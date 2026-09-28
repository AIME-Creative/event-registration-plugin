<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Stripe webhook endpoint: /wp-json/evr/v1/stripe-webhook
 *
 * - payment_intent.succeeded  -> confirm registration + GHL sync
 * - payment_intent.payment_failed -> mark failed
 * - charge.refunded           -> mark refunded
 */
class EVR_Webhook {

	/**
	 * Canonical Stripe webhook endpoint URL.
	 *
	 * Stripe has one endpoint configured (in the live site) and all events
	 * flow there, so this is pinned to production rather than derived from
	 * rest_url() — that way staging/dev installs show and use the same live
	 * endpoint instead of their own site URL. Override per-site with the
	 * EVR_WEBHOOK_URL constant or the `evr_webhook_endpoint_url` filter.
	 */
	const ENDPOINT_URL = 'https://aimegroup.com/wp-json/evr/v1/stripe-webhook';

	public static function endpoint_url() {
		$url = defined( 'EVR_WEBHOOK_URL' ) && EVR_WEBHOOK_URL ? EVR_WEBHOOK_URL : self::ENDPOINT_URL;
		return apply_filters( 'evr_webhook_endpoint_url', $url );
	}

	public static function init() {
		add_action( 'rest_api_init', function () {
			register_rest_route( 'evr/v1', '/stripe-webhook', array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true', // Verified via Stripe signature.
			) );
		} );
	}

	public static function handle( WP_REST_Request $request ) {
		$payload = $request->get_body();
		$sig     = $request->get_header( 'stripe-signature' );

		// Events can run in test OR live mode simultaneously, so accept
		// webhooks signed with either endpoint's secret.
		$valid = false;
		foreach ( array( 'live', 'test' ) as $mode ) {
			$secret = EVR_Settings::stripe_webhook_secret( $mode );
			if ( $secret && EVR_Stripe::verify_webhook( $payload, $sig, $secret ) ) {
				$valid = true;
				break;
			}
		}
		if ( ! $valid ) {
			return new WP_REST_Response( array( 'error' => 'Invalid signature.' ), 400 );
		}

		$event = json_decode( $payload, true );
		$type  = $event['type'] ?? '';
		$obj   = $event['data']['object'] ?? array();

		$reg_id = absint( $obj['metadata']['evr_registration_id'] ?? 0 );
		$inst   = isset( $obj['metadata']['evr_installment_index'] ) ? (int) $obj['metadata']['evr_installment_index'] : 0;
		$payoff = ! empty( $obj['metadata']['evr_payoff'] );

		switch ( $type ) {
			case 'payment_intent.succeeded':
				if ( $reg_id ) {
					if ( $inst > 0 ) {
						// A later installment charged by the daily sweep — record it
						// (backstop; the sweep also updates inline). Idempotent.
						EVR_Installments::mark_installment_paid( $reg_id, $inst, (string) ( $obj['id'] ?? '' ) );
					} else {
						self::confirm_registration( $reg_id, $obj );
					}
				}
				break;

			case 'payment_intent.payment_failed':
				// Only the first payment can leave a registration "failed"; later
				// installments (inst > 0) are the daily sweep's responsibility for
				// retry/backoff, so they don't touch the registration status here.
				if ( $reg_id && 0 === $inst ) {
					$reg = EVR_DB::get_registration( $reg_id );
					if ( $reg && 'pending' === $reg['status'] ) {
						EVR_DB::update_registration( $reg_id, array( 'status' => 'failed' ) );
					}
				}
				break;

			// Payment-plan installments 2+ are collected as invoices; an early
			// payoff is a single send_invoice invoice tagged evr_payoff.
			case 'invoice.paid':
				if ( $reg_id ) {
					if ( $payoff ) {
						EVR_Installments::apply_payoff_paid( $reg_id );
					} elseif ( $inst > 0 ) {
						EVR_Installments::mark_installment_paid( $reg_id, $inst, (string) ( $obj['id'] ?? '' ) );
					}
				}
				break;

			case 'invoice.payment_failed':
				// Stripe is still retrying (dunning) — just record the reason.
				// A failed payoff attempt leaves the invoice open for the
				// customer to retry, so it needs no state change.
				if ( $reg_id && ! $payoff && $inst > 0 ) {
					$err = $obj['last_finalization_error']['message'] ?? 'The card was declined.';
					EVR_Installments::note_installment_error( $reg_id, $inst, $err );
				}
				break;

			case 'invoice.marked_uncollectible':
				if ( $reg_id ) {
					if ( $payoff ) {
						// Unpaid payoff abandoned — resume the normal schedule.
						EVR_Installments::clear_payoff( $reg_id );
					} elseif ( $inst > 0 ) {
						// Stripe has given up collecting — default the plan.
						EVR_Installments::mark_installment_failed_terminal( $reg_id, $inst, 'Stripe was unable to collect this payment.' );
					}
				}
				break;

			case 'charge.refunded':
				$intent_id = $obj['payment_intent'] ?? '';
				if ( $intent_id ) {
					$reg = EVR_DB::get_registration_by_intent( $intent_id );
					if ( $reg && 'refunded' !== $reg['status'] ) {
						EVR_DB::update_registration( (int) $reg['id'], array( 'status' => 'refunded' ) );
					}
				}
				break;
		}

		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * Mark a registration confirmed (idempotent), record promo usage,
	 * and push it to GoHighLevel.
	 *
	 * @param int   $reg_id
	 * @param array $intent   Stripe PaymentIntent, when one exists.
	 * @param bool  $sync_ghl Pass false to skip the GHL push (manual adds can
	 *                        opt out); the row is then marked 'skipped'.
	 */
	public static function confirm_registration( $reg_id, $intent = null, $sync_ghl = true ) {
		$reg = EVR_DB::get_registration( $reg_id );
		if ( ! $reg || 'confirmed' === $reg['status'] ) {
			return;
		}
		EVR_DB::update_registration( $reg_id, array( 'status' => 'confirmed' ) );

		// Payment-plan registration: record the saved card + activate the plan
		// from the first installment's succeeded PaymentIntent.
		if ( EVR_Installments::get_plan( $reg ) ) {
			if ( ! is_array( $intent ) && $reg['stripe_payment_intent'] ) {
				$fetched = EVR_Stripe::get_payment_intent( $reg['stripe_payment_intent'], $reg['stripe_mode'] );
				$intent  = is_wp_error( $fetched ) ? null : $fetched;
			}
			if ( is_array( $intent ) ) {
				EVR_Installments::finalize_first_installment( $reg_id, $intent );
			}
		}

		EVR_Pricing::record_promo_use( (int) $reg['event_id'], $reg['promo_code'] );
		if ( $sync_ghl ) {
			EVR_GHL::sync_registration( $reg_id );
		} else {
			EVR_DB::update_registration( $reg_id, array( 'ghl_sync_status' => 'skipped' ) );
		}

		/**
		 * Fires after a registration is confirmed. Useful for custom
		 * confirmation emails or other integrations.
		 *
		 * @param int   $reg_id
		 * @param array $reg Registration row (pre-confirmation snapshot).
		 */
		do_action( 'evr_registration_confirmed', $reg_id, $reg );
	}
}
