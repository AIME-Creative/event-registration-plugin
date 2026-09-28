<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Server-side price computation. Always recomputed at payment time — the
 * browser never decides the price.
 *
 * Order of operations:
 *   1. Main ticket at REGULAR price
 *   2. One reduction line: the active pricing-period saving (e.g. early
 *      bird) OR the membership tier discount (calculated off the regular
 *      price) — whichever is greater. They never stack.
 *      If the event has "early-bird overrides discounts" enabled, an
 *      active pricing period always wins and the membership discount is
 *      ignored for that ticket, even if the member discount is larger.
 *   3. Add-ons at their current period price (no membership discount)
 *   4. Promo code (fixed or %) applied to the subtotal
 */
class EVR_Pricing {

	/**
	 * @param array $opts Optional. 'ignore_availability' => true prices a
	 *                    ticket even if it is sold out or switched off. Used
	 *                    only by manual (admin/API) registrations, never by
	 *                    the public checkout.
	 * @return array|WP_Error Breakdown with line items and total_cents.
	 */
	public static function quote( $event, $ticket_key, $addon_keys, $tier, $promo_code, $opts = array() ) {
		$config    = $event['config'];
		$now       = current_time( 'timestamp' );
		$any_seat  = ! empty( $opts['ignore_availability'] );

		$ticket = EVR_DB::find_ticket( $event, $ticket_key );
		if ( ! $ticket || 'main' !== ( $ticket['type'] ?? 'main' ) || ( empty( $ticket['active'] ) && ! $any_seat ) ) {
			return new WP_Error( 'evr_pricing', 'Please select a valid ticket type.' );
		}

		// Capacity.
		if ( ! $any_seat && ! empty( $ticket['capacity'] ) && EVR_DB::ticket_seats_taken( $event['id'], $ticket_key ) >= (int) $ticket['capacity'] ) {
			return new WP_Error( 'evr_pricing', 'Sorry, that ticket type is sold out.' );
		}

		// Main ticket at regular price; discounts are applied as a single
		// reduction line below.
		$regular = (int) $ticket['price_cents'];
		$lines   = array();
		$lines[] = array(
			'label' => $ticket['label'],
			'cents' => $regular,
		);

		// Time-based pricing period (e.g. an early-bird window). Saving is
		// measured against the regular price.
		$period        = self::active_period( $event, $ticket_key );
		$period_saving = $period ? max( 0, $regular - (int) $period['price_cents'] ) : 0;

		// Membership tier discount — always calculated off the REGULAR
		// price, never off a period price.
		$member_saving = 0;
		$member_label  = '';
		if ( $tier && ! empty( $config['discounts'][ $tier ] ) ) {
			$d      = $config['discounts'][ $tier ];
			$amount = (float) ( $d['amount'] ?? 0 );
			if ( $amount > 0 ) {
				$member_saving = ( 'percent' === ( $d['type'] ?? 'fixed' ) )
					? (int) round( $regular * $amount / 100 )
					: (int) round( $amount * 100 );
				$member_saving = min( $member_saving, $regular );
				$member_label  = ucfirst( $tier ) . ' member discount ' . self::discount_suffix( $d['type'] ?? 'fixed', $amount );
			}
		}

		// When "early-bird overrides discounts" is on and a pricing period is
		// active, the period price wins outright and the membership discount
		// is ignored. Otherwise, whichever saving is greater wins — they never
		// stack. (Promo codes are applied separately below either way.)
		$eb_overrides   = ! empty( $config['early_bird_overrides_discounts'] ) && $period;
		$member_applied = false;

		if ( $eb_overrides ) {
			if ( $period_saving > 0 ) {
				$lines[] = array(
					'label' => ( $period['label'] ?: 'Early bird' ),
					'cents' => -$period_saving,
				);
			}
		} elseif ( $period_saving >= $member_saving && $period_saving > 0 ) {
			$lines[] = array(
				'label' => ( $period['label'] ?: 'Early bird' ),
				'cents' => -$period_saving,
			);
		} elseif ( $member_saving > 0 ) {
			$lines[] = array(
				'label' => $member_label,
				'cents' => -$member_saving,
			);
			$member_applied = true;
		}

		// Add-ons (period prices apply; no membership discount on add-ons).
		foreach ( (array) $addon_keys as $key ) {
			$addon = EVR_DB::find_ticket( $event, $key );
			if ( ! $addon || 'addon' !== ( $addon['type'] ?? '' ) || ( empty( $addon['active'] ) && ! $any_seat ) ) {
				return new WP_Error( 'evr_pricing', 'Invalid add-on selected.' );
			}
			if ( ! $any_seat && ! empty( $addon['capacity'] ) && EVR_DB::ticket_seats_taken( $event['id'], $key ) >= (int) $addon['capacity'] ) {
				return new WP_Error( 'evr_pricing', sprintf( 'Sorry, "%s" is sold out.', $addon['label'] ) );
			}
			$addon_period = self::active_period( $event, $key );
			$addon_price  = $addon_period ? min( (int) $addon_period['price_cents'], (int) $addon['price_cents'] ) : (int) $addon['price_cents'];
			$lines[] = array(
				'label' => $addon['label'] . ( $addon_period && $addon_price < (int) $addon['price_cents'] ? ' (' . ( $addon_period['label'] ?: 'Early bird' ) . ')' : '' ),
				'cents' => $addon_price,
			);
		}

		$subtotal = 0;
		foreach ( $lines as $line ) {
			$subtotal += $line['cents'];
		}

		// Promo code.
		$promo_applied = '';
		if ( $promo_code ) {
			$promo = self::find_promo( $config, $promo_code );
			if ( is_wp_error( $promo ) ) {
				return $promo;
			}
			$amount = (float) ( $promo['amount'] ?? 0 );
			$promo_cents = ( 'percent' === ( $promo['type'] ?? 'fixed' ) )
				? (int) round( $subtotal * $amount / 100 )
				: (int) round( $amount * 100 );
			$promo_cents = min( $promo_cents, $subtotal );
			if ( $promo_cents > 0 ) {
				$lines[] = array(
					'label' => 'Promo code: ' . strtoupper( $promo['code'] ) . ' ' . self::discount_suffix( $promo['type'] ?? 'fixed', $amount ),
					'cents' => -$promo_cents,
				);
				$subtotal -= $promo_cents;
			}
			$promo_applied = strtoupper( $promo['code'] );
		}

		return array(
			'lines'          => $lines,
			'total_cents'    => max( 0, (int) $subtotal ),
			'currency'       => $config['currency'] ?: 'usd',
			'promo_code'     => $promo_applied,
			'member_applied' => $member_applied,
			'tier_label'     => $member_applied ? ucfirst( (string) $tier ) : '',
			// Custom label for the Total row while this pricing period is
			// active; empty once the period ends (the client falls back to "Total").
			'total_label'    => ( $period && ! empty( $period['total_label'] ) ) ? (string) $period['total_label'] : '',
		);
	}

	/**
	 * The pricing period currently in effect for a ticket: among periods
	 * whose end date hasn't passed, the one ending soonest. Periods run
	 * back-to-back — when one expires the next takes over, and when the
	 * last expires the regular price applies.
	 *
	 * @return array|null {ticket_key,label,price_cents,ends}
	 */
	public static function active_period( $event, $ticket_key, $now = null ) {
		// Compare in real UTC seconds. The admin enters the end date in the
		// site's local timezone, so it must be interpreted in that timezone —
		// not as UTC (the old strtotime behaviour), which shifted the cutoff.
		$now      = null !== $now ? (int) $now : time();
		$best     = null;
		$best_end = 0;
		foreach ( (array) ( $event['config']['pricing_periods'] ?? array() ) as $p ) {
			if ( ( $p['ticket_key'] ?? '' ) !== $ticket_key || empty( $p['ends'] ) ) {
				continue;
			}
			$end = self::local_datetime_to_ts( $p['ends'] );
			if ( null === $end || $now >= $end ) {
				continue; // Invalid date, or the period has already ended.
			}
			if ( ! $best || $end < $best_end ) {
				$best     = $p;
				$best_end = $end;
			}
		}
		return $best;
	}

	/**
	 * Convert a datetime-local string (e.g. "2026-07-16T00:00", as entered in
	 * the event editor) into a real Unix timestamp, interpreting it in the
	 * site's configured timezone. Returns null if it can't be parsed.
	 */
	public static function local_datetime_to_ts( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		try {
			$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
			$dt = new DateTimeImmutable( $value, $tz );
			return $dt->getTimestamp();
		} catch ( Exception $e ) {
			$ts = strtotime( $value );
			return false === $ts ? null : $ts;
		}
	}

	/**
	 * The price a ticket sells for right now (active period or regular).
	 */
	public static function current_ticket_price( $event, $ticket ) {
		$period = self::active_period( $event, $ticket['key'] );
		return $period ? min( (int) $period['price_cents'], (int) $ticket['price_cents'] ) : (int) $ticket['price_cents'];
	}

	/**
	 * Human label for a discount, e.g. "(10% off)" or "($115 off)".
	 */
	private static function discount_suffix( $type, $amount ) {
		$num = rtrim( rtrim( number_format( (float) $amount, 2, '.', ',' ), '0' ), '.' );
		return 'percent' === $type ? '(' . $num . '% off)' : '($' . $num . ' off)';
	}

	private static function find_promo( $config, $code ) {
		$code = strtoupper( trim( $code ) );
		$now  = time();
		foreach ( (array) $config['promo_codes'] as $promo ) {
			if ( strtoupper( trim( $promo['code'] ?? '' ) ) !== $code ) {
				continue;
			}
			$expires = self::local_datetime_to_ts( $promo['expires'] ?? '' );
			if ( null !== $expires && $now >= $expires ) {
				return new WP_Error( 'evr_pricing', 'That promo code has expired.' );
			}
			if ( ! empty( $promo['max_uses'] ) && (int) ( $promo['used'] ?? 0 ) >= (int) $promo['max_uses'] ) {
				return new WP_Error( 'evr_pricing', 'That promo code has reached its usage limit.' );
			}
			return $promo;
		}
		return new WP_Error( 'evr_pricing', 'Invalid promo code.' );
	}

	/**
	 * Increment a promo code's usage counter after a confirmed registration.
	 */
	public static function record_promo_use( $event_id, $code ) {
		if ( ! $code ) {
			return;
		}
		$event = EVR_DB::get_event( $event_id );
		if ( ! $event ) {
			return;
		}
		$config  = $event['config'];
		$changed = false;
		foreach ( $config['promo_codes'] as &$promo ) {
			if ( strtoupper( trim( $promo['code'] ?? '' ) ) === strtoupper( trim( $code ) ) ) {
				$promo['used'] = (int) ( $promo['used'] ?? 0 ) + 1;
				$changed = true;
				break;
			}
		}
		unset( $promo );
		if ( $changed ) {
			EVR_DB::update_event_config( $event_id, $config );
		}
	}

	/**
	 * Is registration currently open for this event?
	 *
	 * @return true|WP_Error
	 */
	public static function check_window( $event ) {
		if ( 'active' !== $event['status'] ) {
			return new WP_Error( 'evr_window', 'Registration is not open for this event.' );
		}
		$config = $event['config'];
		$now    = time();
		$open   = self::local_datetime_to_ts( $config['reg_open'] ?? '' );
		$close  = self::local_datetime_to_ts( $config['reg_close'] ?? '' );
		if ( null !== $open && $now < $open ) {
			return new WP_Error( 'evr_window', 'Registration has not opened yet.' );
		}
		if ( null !== $close && $now >= $close ) {
			return new WP_Error( 'evr_window', 'Registration for this event has closed.' );
		}
		return true;
	}

	/**
	 * Should the form currently show the waitlist instead of registration?
	 *
	 * @return string '' (no) | 'manual' | 'pre_open' | 'capacity'
	 */
	public static function waitlist_state( $event ) {
		if ( 'active' !== $event['status'] ) {
			return '';
		}
		$config = $event['config'];
		$w      = $config['waitlist'] ?? array();
		$now    = time();
		$open   = self::local_datetime_to_ts( $config['reg_open'] ?? '' );
		$close  = self::local_datetime_to_ts( $config['reg_close'] ?? '' );

		// Manual toggle wins over everything, including dates.
		if ( ! empty( $w['manual'] ) ) {
			return 'manual';
		}

		// After the close date there is no waitlist either.
		if ( null !== $close && $now >= $close ) {
			return '';
		}
		if ( ! empty( $w['before_open'] ) && null !== $open && $now < $open ) {
			return 'pre_open';
		}
		if ( ! empty( $w['on_capacity'] ) && self::all_mains_sold_out( $event ) ) {
			return 'capacity';
		}
		return '';
	}

	/**
	 * True when every active main ticket has a capacity and has reached it.
	 */
	private static function all_mains_sold_out( $event ) {
		$has_main = false;
		foreach ( (array) $event['config']['tickets'] as $t ) {
			if ( 'main' !== ( $t['type'] ?? 'main' ) || empty( $t['active'] ) ) {
				continue;
			}
			$has_main = true;
			if ( empty( $t['capacity'] ) ) {
				return false; // Unlimited ticket — never "full".
			}
			if ( EVR_DB::ticket_seats_taken( $event['id'], $t['key'] ) < (int) $t['capacity'] ) {
				return false;
			}
		}
		return $has_main;
	}
}
