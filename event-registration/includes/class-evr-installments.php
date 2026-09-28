<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Payment plans (installments).
 *
 * A registrant can split the total into a fixed number of automatic payments.
 * The first installment is charged on the page via the embedded Payment
 * Element with the card saved (Stripe customer + setup_future_usage), and the
 * remaining installments are charged off-session on their due dates by the
 * daily `evr_installment_sweep` cron.
 *
 * The schedule lives on the registration row as JSON in `payment_plan`:
 *   {count, interval_unit, interval_count, installments:[
 *     {index, amount_cents, due_date, status, payment_intent, paid_at, attempts, last_error}
 * An installment's status runs scheduled -> invoiced -> paid, with `failed`
 * (dunning exhausted) and `canceled` (an admin called it off) terminal.
 *   ]}
 * Due dates are stored 'Y-m-d H:i:s' in the site's local timezone (matching
 * created_at). Amounts and eligibility are always computed server-side.
 */
class EVR_Installments {

	/**
	 * Off-session retry attempts before an installment is abandoned and the
	 * plan is marked defaulted. Filterable via `evr_plan_max_attempts`.
	 */
	public static function max_attempts() {
		return max( 1, (int) apply_filters( 'evr_plan_max_attempts', 3 ) );
	}

	/**
	 * Build the installment schedule for a total. Equal split, with any
	 * rounding remainder added to the first (today's) payment. Installment 0
	 * is due now; each later one is stepped by the configured interval.
	 *
	 * @return array[] installments (index, amount_cents, due_date, status, ...)
	 */
	public static function build_schedule( $total_cents, $plan, $start_ts = null ) {
		$total_cents = max( 0, (int) $total_cents );
		$count       = max( 2, (int) ( $plan['count'] ?? 2 ) );
		$unit        = 'day' === ( $plan['interval_unit'] ?? 'month' ) ? 'days' : 'months';
		$every       = max( 1, (int) ( $plan['interval_count'] ?? 1 ) );
		$start_ts    = null !== $start_ts ? (int) $start_ts : time();

		$base   = (int) floor( $total_cents / $count );
		$first  = $base + ( $total_cents - $base * $count ); // Remainder on installment 0.

		$tz  = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		try {
			$anchor = ( new DateTimeImmutable( '@' . $start_ts ) )->setTimezone( $tz );
		} catch ( Exception $e ) {
			$anchor = new DateTimeImmutable( 'now', $tz );
		}

		$installments = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$due = $anchor->modify( '+' . ( $every * $i ) . ' ' . $unit );
			$installments[] = array(
				'index'          => $i,
				'amount_cents'   => 0 === $i ? $first : $base,
				'due_date'       => $due->format( 'Y-m-d H:i:s' ),
				'status'         => 'scheduled', // scheduled -> invoiced -> paid; failed/canceled = terminal
				'payment_intent' => '',
				'invoice_id'     => '', // Stripe invoice for installments 2+ (first uses the PaymentIntent).
				'invoice_url'    => '',
				'paid_at'        => '',
				'attempts'       => 0,
				'last_error'     => '',
			);
		}
		return $installments;
	}

	/**
	 * Whether a payment plan may be offered for this event at this total.
	 * Single source of truth (front-end mirrors this, the server enforces it):
	 * enabled, at least 2 payments, a positive total above the minimum, and —
	 * if a "final payment due by" date is set — the last installment must fall
	 * on or before that date, so the option disappears when the event is too
	 * close for the schedule to finish.
	 */
	public static function plan_available( $event, $total_cents, $now = null ) {
		$plan = self::plan_config( $event );
		if ( empty( $plan['enabled'] ) ) {
			return false;
		}
		$total_cents = (int) $total_cents;
		if ( $total_cents <= 0 || (int) $plan['count'] < 2 ) {
			return false;
		}
		if ( $total_cents < (int) $plan['min_total_cents'] ) {
			return false;
		}
		if ( ! empty( $plan['final_due'] ) ) {
			$final_due_ts = EVR_Pricing::local_datetime_to_ts( $plan['final_due'] );
			if ( null !== $final_due_ts ) {
				$schedule = self::build_schedule( $total_cents, $plan, $now );
				$last     = end( $schedule );
				$last_ts  = EVR_Pricing::local_datetime_to_ts( $last['due_date'] );
				if ( null !== $last_ts && $last_ts > $final_due_ts ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * The event's payment-plan config, merged over defaults.
	 */
	public static function plan_config( $event ) {
		$defaults = EVR_DB::default_config()['payment_plan'];
		$plan     = ( is_array( $event['config']['payment_plan'] ?? null ) ) ? $event['config']['payment_plan'] : array();
		return array_merge( $defaults, $plan );
	}

	/**
	 * Persist a freshly built schedule onto a pending registration.
	 */
	public static function attach_schedule( $reg_id, $total_cents, $plan, $start_ts = null ) {
		$schedule = self::build_schedule( $total_cents, $plan, $start_ts );
		EVR_DB::update_registration( $reg_id, array(
			'payment_plan' => wp_json_encode( array(
				'count'          => max( 2, (int) $plan['count'] ),
				'interval_unit'  => 'day' === ( $plan['interval_unit'] ?? 'month' ) ? 'day' : 'month',
				'interval_count' => max( 1, (int) ( $plan['interval_count'] ?? 1 ) ),
				'installments'   => $schedule,
			) ),
		) );
		return $schedule;
	}

	/**
	 * Read the stored schedule for a registration, or null.
	 */
	public static function get_plan( $reg ) {
		if ( empty( $reg['payment_plan'] ) ) {
			return null;
		}
		$plan = json_decode( $reg['payment_plan'], true );
		return ( is_array( $plan ) && ! empty( $plan['installments'] ) ) ? $plan : null;
	}

	/**
	 * First payment amount (what's charged on the page today), or the full
	 * total if there is no schedule.
	 */
	public static function first_amount_cents( $reg ) {
		$plan = self::get_plan( $reg );
		return $plan ? (int) $plan['installments'][0]['amount_cents'] : (int) $reg['amount_cents'];
	}

	/**
	 * Finalize the first installment once its PaymentIntent has succeeded:
	 * record the saved customer + payment method, mark installment 0 paid, and
	 * activate the plan. Idempotent. Called from the confirm path with the
	 * succeeded PaymentIntent object.
	 */
	public static function finalize_first_installment( $reg_id, $intent ) {
		$reg  = EVR_DB::get_registration( $reg_id );
		$plan = $reg ? self::get_plan( $reg ) : null;
		if ( ! $plan ) {
			return; // Not a payment-plan registration.
		}
		if ( 'scheduled' !== ( $plan['installments'][0]['status'] ?? '' ) ) {
			return; // Already finalized.
		}

		$now = current_time( 'mysql' );
		$plan['installments'][0]['status']         = 'paid';
		$plan['installments'][0]['payment_intent'] = (string) ( $intent['id'] ?? $reg['stripe_payment_intent'] );
		$plan['installments'][0]['paid_at']        = $now;

		$customer = (string) ( $intent['customer'] ?? $reg['stripe_customer_id'] );
		$pm       = (string) ( $intent['payment_method'] ?? '' );

		// Make the just-saved card the customer's default so later invoices
		// auto-charge it. A card the customer later changes in the portal
		// updates this same default, so future invoices follow the new card.
		if ( $customer && $pm ) {
			EVR_Stripe::set_customer_default_pm( $customer, $pm, $reg['stripe_mode'] );
		}

		$all_paid = self::all_paid( $plan );
		EVR_DB::update_registration( $reg_id, array(
			'stripe_customer_id' => $customer,
			'payment_method_id'  => $pm,
			'payment_plan'       => wp_json_encode( $plan ),
			'amount_paid_cents'  => self::paid_total( $plan ),
			'plan_status'        => $all_paid ? 'completed' : 'active',
		) );
	}

	/**
	 * Mark a later installment paid (idempotent). Used as a backstop by the
	 * webhook when a sweep-initiated PaymentIntent succeeds.
	 */
	public static function mark_installment_paid( $reg_id, $index, $intent_id = '' ) {
		$reg  = EVR_DB::get_registration( $reg_id );
		$plan = $reg ? self::get_plan( $reg ) : null;
		if ( ! $plan || ! isset( $plan['installments'][ $index ] ) ) {
			return;
		}
		if ( 'paid' === $plan['installments'][ $index ]['status'] ) {
			return; // Already recorded.
		}
		$plan['installments'][ $index ]['status']  = 'paid';
		$plan['installments'][ $index ]['paid_at'] = current_time( 'mysql' );
		if ( $intent_id ) {
			$plan['installments'][ $index ]['payment_intent'] = $intent_id;
		}
		// Derived rather than forced to 'active', so a payment landing late on
		// a plan whose remaining installments were canceled can't restart it.
		$status = self::derive_plan_status( $plan, 'canceled' === $reg['plan_status'] ? 'canceled' : 'active' );
		EVR_DB::update_registration( $reg_id, array(
			'payment_plan'      => wp_json_encode( $plan ),
			'amount_paid_cents' => self::paid_total( $plan ),
			'plan_status'       => $status,
		) );
		if ( 'completed' === $status ) {
			do_action( 'evr_plan_completed', $reg_id );
		}
	}

	/**
	 * Daily sweep: for each due, not-yet-invoiced installment, issue a Stripe
	 * invoice (charge_automatically) that auto-charges the customer's default
	 * card. Once an installment is invoiced, Stripe owns collection —
	 * retries/dunning and the customer email — so the sweep never issues a
	 * second invoice for it; it just waits for the `invoice.paid` webhook (or
	 * the inline "already paid" result). Stops at the first not-yet-due or
	 * still-outstanding installment per registration.
	 */
	public static function charge_due_installments() {
		$now_ts = time();
		foreach ( EVR_DB::get_active_plan_registrations() as $reg ) {
			if ( 'confirmed' !== $reg['status'] ) {
				continue; // Refunded/failed registrations stop future invoices.
			}
			$plan = self::get_plan( $reg );
			if ( ! $plan ) {
				continue;
			}
			// A payoff invoice is out for collection — don't also auto-charge;
			// the invoice.paid / void webhook resolves it.
			if ( ! empty( $plan['payoff_invoice_id'] ) ) {
				continue;
			}
			$customer = $reg['stripe_customer_id'];
			if ( ! $customer ) {
				EVR_DB::update_registration( (int) $reg['id'], array( 'plan_status' => 'defaulted' ) );
				do_action( 'evr_installment_failed', (int) $reg['id'], 0, 'No Stripe customer on file.' );
				continue;
			}

			$event   = EVR_DB::get_event( (int) $reg['event_id'] );
			$ticket  = $event ? EVR_DB::find_ticket( $event, $reg['ticket_key'] ) : null;
			$mode    = $reg['stripe_mode'];
			$changed = false;

			foreach ( $plan['installments'] as $i => $inst ) {
				// Settled, or deliberately canceled by an admin. Skip and carry
				// on: a canceled payment is never invoiced, but it also doesn't
				// hold up any payment still scheduled after it.
				if ( in_array( $inst['status'], array( 'paid', 'canceled' ), true ) ) {
					continue;
				}
				// An installment already invoiced (Stripe still collecting) or
				// terminally failed blocks the ones after it — stop here.
				if ( 'scheduled' !== $inst['status'] ) {
					break;
				}
				$due_ts = EVR_Pricing::local_datetime_to_ts( $inst['due_date'] );
				if ( null === $due_ts || $due_ts > $now_ts ) {
					break; // Not due yet — nothing further to invoice this run.
				}

				$invoice = EVR_Stripe::create_installment_invoice(
					$customer,
					(int) $inst['amount_cents'],
					$reg['currency'],
					self::charge_description( $event, $reg, $i, (int) $plan['count'] ),
					array(
						'evr_registration_id'   => (int) $reg['id'],
						'evr_event_id'          => (int) $reg['event_id'],
						'evr_installment_index' => $i,
						'evr_stripe_product_id' => EVR_Stripe::ticket_product_id( $ticket, $mode ),
					),
					$mode
				);
				$changed = true;

				if ( is_wp_error( $invoice ) ) {
					// Couldn't create/finalize the invoice — leave the installment
					// 'scheduled' so it's retried next run, record why, and stop.
					$plan['installments'][ $i ]['last_error'] = $invoice->get_error_message();
					break;
				}

				$plan['installments'][ $i ]['invoice_id']  = (string) ( $invoice['id'] ?? '' );
				$plan['installments'][ $i ]['invoice_url'] = (string) ( $invoice['hosted_invoice_url'] ?? '' );
				$plan['installments'][ $i ]['last_error']  = '';

				if ( 'paid' === ( $invoice['status'] ?? '' ) ) {
					// Card cleared synchronously on finalize.
					$plan['installments'][ $i ]['status']  = 'paid';
					$plan['installments'][ $i ]['paid_at'] = current_time( 'mysql' );
					continue; // The next installment is due later, so the loop stops there.
				}

				// Invoice open — Stripe is collecting (may auto-retry). Await the
				// invoice.paid webhook; don't invoice the next one meanwhile.
				$plan['installments'][ $i ]['status'] = 'invoiced';
				break;
			}

			if ( ! $changed ) {
				continue;
			}
			$all_paid = self::all_paid( $plan );
			EVR_DB::update_registration( (int) $reg['id'], array(
				'payment_plan'      => wp_json_encode( $plan ),
				'amount_paid_cents' => self::paid_total( $plan ),
				'plan_status'       => $all_paid ? 'completed' : 'active',
			) );
			if ( $all_paid ) {
				do_action( 'evr_plan_completed', (int) $reg['id'] );
			}
		}
	}

	/**
	 * Record a (non-terminal) failed invoice attempt without changing status —
	 * Stripe is still retrying. Used by the invoice.payment_failed webhook.
	 */
	public static function note_installment_error( $reg_id, $index, $error ) {
		$reg  = EVR_DB::get_registration( $reg_id );
		$plan = $reg ? self::get_plan( $reg ) : null;
		if ( ! $plan || ! isset( $plan['installments'][ $index ] ) || 'paid' === $plan['installments'][ $index ]['status'] ) {
			return;
		}
		$plan['installments'][ $index ]['last_error'] = (string) $error;
		EVR_DB::update_registration( $reg_id, array( 'payment_plan' => wp_json_encode( $plan ) ) );
	}

	/**
	 * Terminally fail an installment (Stripe gave up collecting) and default the
	 * plan. Used by the invoice.marked_uncollectible webhook. Idempotent.
	 */
	public static function mark_installment_failed_terminal( $reg_id, $index, $error = '' ) {
		$reg  = EVR_DB::get_registration( $reg_id );
		$plan = $reg ? self::get_plan( $reg ) : null;
		if ( ! $plan || ! isset( $plan['installments'][ $index ] ) || 'paid' === $plan['installments'][ $index ]['status'] ) {
			return;
		}
		$plan['installments'][ $index ]['status'] = 'failed';
		if ( $error ) {
			$plan['installments'][ $index ]['last_error'] = (string) $error;
		}
		EVR_DB::update_registration( $reg_id, array(
			'payment_plan' => wp_json_encode( $plan ),
			'plan_status'  => 'defaulted',
		) );
		do_action( 'evr_installment_failed', $reg_id, $index, $error );
	}

	/* ---------------- Cancelling scheduled payments ---------------- */

	/**
	 * Cancel a single not-yet-paid installment. Nothing further is charged for
	 * it, and if Stripe already has an invoice out collecting it, that invoice
	 * is voided first so it can't still settle. Terminal: the sweep never
	 * re-invoices a canceled payment, though any payment scheduled after it
	 * still charges on its own due date.
	 *
	 * Use when the amount owed changes after the plan was written — a price
	 * drop, a negotiated adjustment — and the balance will be collected some
	 * other way, rather than charging in full and refunding later.
	 *
	 * @return true|WP_Error
	 */
	public static function cancel_installment( $reg_id, $index ) {
		$reg  = EVR_DB::get_registration( $reg_id );
		$plan = $reg ? self::get_plan( $reg ) : null;
		if ( ! $plan || ! isset( $plan['installments'][ $index ] ) ) {
			return new WP_Error( 'evr_plan', 'That payment is not on this registration.' );
		}
		$status = $plan['installments'][ $index ]['status'];
		if ( 'paid' === $status ) {
			return new WP_Error( 'evr_plan', 'That payment has already been collected — refund it in Stripe instead.' );
		}
		if ( 'canceled' === $status ) {
			return true; // Already canceled — idempotent.
		}
		$had_payoff = ! empty( $plan['payoff_invoice_id'] );
		$voided     = self::void_payoff( $plan, $reg['stripe_mode'] );
		if ( is_wp_error( $voided ) ) {
			return $voided;
		}
		$result = self::void_and_cancel( $plan, $index, $reg['stripe_mode'] );
		if ( is_wp_error( $result ) ) {
			// A payoff invoice voided a moment ago is gone in Stripe either
			// way — record that, or the sweep stays paused on an invoice that
			// no longer exists.
			if ( $had_payoff ) {
				self::save_plan( $reg, $plan );
			}
			return $result;
		}
		self::save_plan( $reg, $plan );
		return true;
	}

	/**
	 * Cancel every payment on the plan that hasn't been collected yet. Once
	 * nothing chargeable is left the plan goes to plan_status 'canceled', which
	 * takes the registration out of the sweep's query entirely.
	 *
	 * @return int|WP_Error Number of payments canceled.
	 */
	public static function cancel_remaining( $reg_id ) {
		$reg  = EVR_DB::get_registration( $reg_id );
		$plan = $reg ? self::get_plan( $reg ) : null;
		if ( ! $plan ) {
			return new WP_Error( 'evr_plan', 'This registration has no payment plan.' );
		}
		$had_payoff = ! empty( $plan['payoff_invoice_id'] );
		$voided     = self::void_payoff( $plan, $reg['stripe_mode'] );
		if ( is_wp_error( $voided ) ) {
			return $voided;
		}
		$canceled = 0;
		foreach ( $plan['installments'] as $i => $inst ) {
			if ( in_array( $inst['status'], array( 'paid', 'canceled' ), true ) ) {
				continue;
			}
			$result = self::void_and_cancel( $plan, $i, $reg['stripe_mode'] );
			if ( is_wp_error( $result ) ) {
				// Persist whatever was canceled (and any payoff invoice already
				// voided) before the failure, so nothing is silently lost, then
				// report what blocked us.
				if ( $canceled || $had_payoff ) {
					self::save_plan( $reg, $plan );
				}
				return $result;
			}
			$canceled++;
		}
		if ( ! $canceled ) {
			return new WP_Error( 'evr_plan', 'There are no remaining payments to cancel on this plan.' );
		}
		self::save_plan( $reg, $plan );
		return $canceled;
	}

	/**
	 * Void an installment's in-flight Stripe invoice (if any) and mark it
	 * canceled on the plan array. On a Stripe failure the plan is left
	 * untouched, so the admin never sees "canceled" against an invoice that is
	 * in fact still out collecting.
	 *
	 * @return true|WP_Error
	 */
	private static function void_and_cancel( &$plan, $index, $mode ) {
		$inst = $plan['installments'][ $index ];
		if ( 'invoiced' === $inst['status'] && ! empty( $inst['invoice_id'] ) ) {
			$voided = EVR_Stripe::void_invoice( $inst['invoice_id'], $mode );
			if ( is_wp_error( $voided ) ) {
				return new WP_Error( 'evr_plan', sprintf(
					'Payment %d could not be canceled — Stripe refused to void its invoice: %s',
					(int) $index + 1,
					$voided->get_error_message()
				) );
			}
		}
		$plan['installments'][ $index ]['status']      = 'canceled';
		$plan['installments'][ $index ]['invoice_id']  = '';
		$plan['installments'][ $index ]['invoice_url'] = '';
		$plan['installments'][ $index ]['last_error']  = '';
		return true;
	}

	/**
	 * Void a payoff invoice still out for collection. It bills the very
	 * payments being canceled, so it has to go too or it would collect the old
	 * balance anyway. No-op when there isn't one.
	 *
	 * @return true|WP_Error
	 */
	private static function void_payoff( &$plan, $mode ) {
		if ( empty( $plan['payoff_invoice_id'] ) ) {
			return true;
		}
		$voided = EVR_Stripe::void_invoice( $plan['payoff_invoice_id'], $mode );
		if ( is_wp_error( $voided ) ) {
			return new WP_Error( 'evr_plan', sprintf(
				'Stripe refused to void the outstanding payoff invoice, so nothing was canceled: %s',
				$voided->get_error_message()
			) );
		}
		$plan['payoff_invoice_id']  = '';
		$plan['payoff_invoice_url'] = '';
		return true;
	}

	/**
	 * Persist a mutated plan and re-derive the registration's plan_status.
	 */
	private static function save_plan( $reg, $plan ) {
		$status = self::derive_plan_status( $plan, $reg['plan_status'] );
		EVR_DB::update_registration( (int) $reg['id'], array(
			'payment_plan'      => wp_json_encode( $plan ),
			'amount_paid_cents' => self::paid_total( $plan ),
			'plan_status'       => $status,
		) );
		return $status;
	}

	/**
	 * The plan_status implied by the schedule: 'completed' once every payment
	 * is paid, 'canceled' once nothing chargeable is left but some payments
	 * were called off, and otherwise whatever the row already says (a plan with
	 * a payment still to come stays 'active'/'defaulted'). Only 'active' rows
	 * are picked up by the sweep, so 'canceled' is what actually stops it.
	 */
	private static function derive_plan_status( $plan, $current ) {
		foreach ( $plan['installments'] as $inst ) {
			if ( ! in_array( $inst['status'], array( 'paid', 'canceled' ), true ) ) {
				return $current; // Something is still chargeable.
			}
		}
		return self::all_paid( $plan ) ? 'completed' : 'canceled';
	}

	/* ---------------- Early payoff ---------------- */

	/**
	 * Email the customer a single Stripe invoice for their entire remaining
	 * balance so they can pay the plan off early. Any auto-charge invoice
	 * already out for collection is voided first (rolled into the payoff) so it
	 * can't double-charge, and the daily sweep pauses for this registration
	 * (via the stored payoff_invoice_id) until the payoff is paid or voided.
	 *
	 * @return array|WP_Error The sent invoice.
	 */
	public static function send_payoff_invoice( $reg_id ) {
		$reg  = EVR_DB::get_registration( $reg_id );
		$plan = $reg ? self::get_plan( $reg ) : null;
		if ( ! $plan ) {
			return new WP_Error( 'evr_plan', 'This registration has no payment plan.' );
		}
		if ( 'confirmed' !== $reg['status'] ) {
			return new WP_Error( 'evr_plan', 'Only a confirmed registration can be invoiced.' );
		}
		if ( empty( $reg['stripe_customer_id'] ) ) {
			return new WP_Error( 'evr_plan', 'No Stripe customer on this registration.' );
		}
		if ( ! empty( $plan['payoff_invoice_id'] ) ) {
			return new WP_Error( 'evr_plan', 'A payoff invoice has already been sent for this registration.' );
		}

		$mode      = $reg['stripe_mode'];
		$remaining = 0;
		foreach ( $plan['installments'] as $i => $inst ) {
			// A canceled payment is no longer owed — keep it out of the total.
			if ( in_array( $inst['status'], array( 'paid', 'canceled' ), true ) ) {
				continue;
			}
			$remaining += (int) $inst['amount_cents'];
			// Cancel an in-flight auto-charge invoice so it can't also settle.
			if ( 'invoiced' === $inst['status'] && ! empty( $inst['invoice_id'] ) ) {
				EVR_Stripe::void_invoice( $inst['invoice_id'], $mode );
				$plan['installments'][ $i ]['status']      = 'scheduled';
				$plan['installments'][ $i ]['invoice_id']  = '';
				$plan['installments'][ $i ]['invoice_url'] = '';
			}
		}
		if ( $remaining <= 0 ) {
			return new WP_Error( 'evr_plan', 'This plan has no remaining balance.' );
		}

		$event  = EVR_DB::get_event( (int) $reg['event_id'] );
		$ticket = $event ? EVR_DB::find_ticket( $event, $reg['ticket_key'] ) : null;
		$days   = (int) apply_filters( 'evr_payoff_days_until_due', 7 );

		$invoice = EVR_Stripe::create_payoff_invoice(
			$reg['stripe_customer_id'],
			$remaining,
			$reg['currency'],
			self::payoff_description( $event, $reg ),
			array(
				'evr_registration_id'   => (int) $reg['id'],
				'evr_event_id'          => (int) $reg['event_id'],
				'evr_payoff'            => 1,
				'evr_stripe_product_id' => EVR_Stripe::ticket_product_id( $ticket, $mode ),
			),
			$mode,
			$days
		);
		if ( is_wp_error( $invoice ) ) {
			return $invoice;
		}

		$plan['payoff_invoice_id']  = (string) ( $invoice['id'] ?? '' );
		$plan['payoff_invoice_url'] = (string) ( $invoice['hosted_invoice_url'] ?? '' );
		EVR_DB::update_registration( $reg_id, array( 'payment_plan' => wp_json_encode( $plan ) ) );
		return $invoice;
	}

	/**
	 * The payoff invoice was paid: mark every remaining installment paid,
	 * complete the plan, and fire evr_plan_completed. Idempotent.
	 */
	public static function apply_payoff_paid( $reg_id ) {
		$reg  = EVR_DB::get_registration( $reg_id );
		$plan = $reg ? self::get_plan( $reg ) : null;
		if ( ! $plan || 'completed' === $reg['plan_status'] ) {
			return;
		}
		$now = current_time( 'mysql' );
		foreach ( $plan['installments'] as $i => $inst ) {
			// Canceled payments weren't billed on the payoff invoice, so they
			// stay canceled rather than being recorded as paid.
			if ( ! in_array( $inst['status'], array( 'paid', 'canceled' ), true ) ) {
				$plan['installments'][ $i ]['status']     = 'paid';
				$plan['installments'][ $i ]['paid_at']    = $now;
				$plan['installments'][ $i ]['last_error'] = '';
			}
		}
		$plan['payoff_invoice_id'] = '';
		EVR_DB::update_registration( $reg_id, array(
			'payment_plan'      => wp_json_encode( $plan ),
			'amount_paid_cents' => self::paid_total( $plan ),
			'plan_status'       => 'completed',
		) );
		do_action( 'evr_plan_completed', $reg_id );
	}

	/**
	 * The payoff invoice was voided or went uncollectible without being paid:
	 * clear the marker so the normal auto-charge schedule resumes on the
	 * remaining installments' due dates.
	 */
	public static function clear_payoff( $reg_id ) {
		$reg  = EVR_DB::get_registration( $reg_id );
		$plan = $reg ? self::get_plan( $reg ) : null;
		if ( ! $plan || empty( $plan['payoff_invoice_id'] ) ) {
			return;
		}
		$plan['payoff_invoice_id']  = '';
		$plan['payoff_invoice_url'] = '';
		EVR_DB::update_registration( $reg_id, array( 'payment_plan' => wp_json_encode( $plan ) ) );
	}

	private static function payoff_description( $event, $reg ) {
		$title = $event ? $event['title'] : ( $reg['ticket_label'] ?: 'Registration' );
		$label = $reg['ticket_label'] ? ' — ' . $reg['ticket_label'] : '';
		return sprintf( '%s%s — remaining balance', $title, $label );
	}

	/* ---------------- Internals ---------------- */

	private static function all_paid( $plan ) {
		foreach ( $plan['installments'] as $inst ) {
			if ( 'paid' !== $inst['status'] ) {
				return false;
			}
		}
		return true;
	}

	private static function paid_total( $plan ) {
		$sum = 0;
		foreach ( $plan['installments'] as $inst ) {
			if ( 'paid' === $inst['status'] ) {
				$sum += (int) $inst['amount_cents'];
			}
		}
		return $sum;
	}

	private static function charge_description( $event, $reg, $index, $count ) {
		$title = $event ? $event['title'] : ( $reg['ticket_label'] ?: 'Registration' );
		$label = $reg['ticket_label'] ? ' — ' . $reg['ticket_label'] : '';
		return sprintf( '%s%s (payment %d of %d)', $title, $label, $index + 1, $count );
	}
}
