<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Database schema and event/registration data access.
 *
 * Events store their full configuration (tickets, add-ons, discounts,
 * promo codes, GHL settings, custom field mappings) as JSON in `config`.
 * Registrations live in their own table for querying and CSV export.
 */
class EVR_DB {

	const SCHEMA_VERSION = '1.4.0';

	public static function events_table() {
		global $wpdb;
		return $wpdb->prefix . 'evr_events';
	}

	public static function registrations_table() {
		global $wpdb;
		return $wpdb->prefix . 'evr_registrations';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$events = self::events_table();
		$regs   = self::registrations_table();

		dbDelta( "CREATE TABLE {$events} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(255) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			config LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$regs} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			email VARCHAR(190) NOT NULL DEFAULT '',
			first_name VARCHAR(190) NOT NULL DEFAULT '',
			last_name VARCHAR(190) NOT NULL DEFAULT '',
			phone VARCHAR(64) NOT NULL DEFAULT '',
			tier VARCHAR(32) NOT NULL DEFAULT '',
			ticket_key VARCHAR(64) NOT NULL DEFAULT '',
			ticket_label VARCHAR(255) NOT NULL DEFAULT '',
			addons LONGTEXT NULL,
			custom_fields LONGTEXT NULL,
			utm LONGTEXT NULL,
			amount_cents BIGINT NOT NULL DEFAULT 0,
			currency VARCHAR(8) NOT NULL DEFAULT 'usd',
			promo_code VARCHAR(64) NOT NULL DEFAULT '',
			stripe_mode VARCHAR(8) NOT NULL DEFAULT 'test',
			stripe_payment_intent VARCHAR(190) NOT NULL DEFAULT '',
			stripe_customer_id VARCHAR(64) NOT NULL DEFAULT '',
			payment_method_id VARCHAR(190) NOT NULL DEFAULT '',
			plan_status VARCHAR(20) NOT NULL DEFAULT 'none',
			amount_paid_cents BIGINT NOT NULL DEFAULT 0,
			payment_plan LONGTEXT NULL,
			ghl_contact_id VARCHAR(64) NOT NULL DEFAULT '',
			ghl_opportunity_id VARCHAR(64) NOT NULL DEFAULT '',
			ghl_sync_status VARCHAR(20) NOT NULL DEFAULT 'none',
			ghl_sync_error TEXT NULL,
			source VARCHAR(20) NOT NULL DEFAULT 'checkout',
			payment_state VARCHAR(20) NOT NULL DEFAULT '',
			added_by VARCHAR(190) NOT NULL DEFAULT '',
			admin_note TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY event_id (event_id),
			KEY email (email),
			KEY stripe_payment_intent (stripe_payment_intent)
		) {$charset};" );

		update_option( 'evr_schema_version', self::SCHEMA_VERSION );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'evr_schema_version' ) !== self::SCHEMA_VERSION ) {
			self::install();
		}
	}

	/* ---------------- Events ---------------- */

	public static function default_config() {
		return array(
			'stripe_mode'      => '', // '' = use global setting | 'test' | 'live'
			'reg_open'         => '',
			'reg_close'        => '',
			'early_bird_end'   => '',
			'early_bird_overrides_discounts' => false, // Active pricing period ignores membership discounts.
			'currency'         => EVR_Settings::get( 'currency', 'usd' ),
			'allow_duplicates' => false,
			'collect_phone'    => true,
			'success_message'  => 'You are registered! A receipt has been sent to your email.',
			'consent_text'     => 'By checking this box, I consent to receive marketing and promotional messages, including special offers, discounts, new product updates among others. Message frequency may vary. Message & Data rates may apply. Reply HELP for help or STOP to opt-out.',
			'email_note'       => 'Members: Please use the same email that you use as your AMP login.',
			'member_signup_url' => 'https://app.brokersarebest.com/sign-up', // Shown to non-members under the email field.
			'placeholders'     => array( // Placeholder text for the built-in fields.
				'first_name' => 'First Name',
				'last_name'  => 'Last Name',
				'phone'      => 'Phone',
				'email'      => 'Email',
			),
			'tickets'          => array(), // {key,label,type:main|addon,stripe_product_id,price_cents,early_bird_cents,capacity,active}
			'pricing_periods'  => array(), // {ticket_key,label,price_cents,ends} — time-based prices, e.g. early-bird windows
			'discounts'        => array(
				'premium' => array( 'type' => 'fixed', 'amount' => 0 ),
				'elite'   => array( 'type' => 'fixed', 'amount' => 0 ),
				'vip'     => array( 'type' => 'fixed', 'amount' => 0 ),
			),
			'promo_codes'      => array(), // {code,type,amount,max_uses,used,expires}
			'ghl'              => array(
				'location_id'         => '',
				'pipeline_id'         => '',
				'stage_id'            => '',
				'abandoned_stage_id'  => '', // Optional: unpaid carts (24h+) land here, same pipeline.
				'outstanding_stage_id' => '', // Optional: payment-plan registrants land here until fully paid.
				'fully_paid_stage_id' => '', // Optional: plan moves here once the final installment is collected.
				'token_override'      => '',
				'tags'                => '',
			),
			'fields'           => array(), // {key,label,type,required,options,ghl_field_id}
			'utm_mappings'     => array(  // UTM param -> GHL field mapping (same format as field mappings)
				'utm_source'   => '',
				'utm_medium'   => '',
				'utm_campaign' => '',
				'utm_term'     => '',
				'utm_content'  => '',
			),
			'waitlist'         => array(
				'manual'      => false, // Force the waitlist on right now (manual toggle).
				'before_open' => false, // Show waitlist before reg_open.
				'on_capacity' => false, // Switch to waitlist when all main tickets sell out.
				'pipeline_id' => '',    // Blank = same pipeline as the event.
				'stage_id'    => '',
				'message'     => "You're on the waitlist! We'll be in touch as soon as a spot opens up.",
			),
			'appearance'       => array( // Per-event overrides of global appearance; blank = inherit.
				'font'        => '',
				'button_bg'   => '',
				'button_text' => '',
				'text'        => '',
				'border'      => '',
				'accent'      => '',
			),
			'payment_plan'     => array( // Optional installment plan for this event.
				'enabled'         => false,
				'count'           => 2,       // Total installments, including the one paid today.
				'interval_unit'   => 'month', // 'month' | 'day'
				'interval_count'  => 1,       // Every N units between installments.
				'min_total_cents' => 0,       // Only offer when the total is at least this (0 = always).
				'final_due'       => '',      // datetime-local; plan is hidden if the schedule can't finish by then.
				'label'           => '',      // Optional customer-facing label, e.g. "Split into payments".
			),
		);
	}

	/**
	 * Does this email already have a waitlist entry for the event?
	 */
	public static function has_waitlist_entry( $event_id, $email ) {
		global $wpdb;
		$count = $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . self::registrations_table() . " WHERE event_id = %d AND email = %s AND status = 'waitlist'",
			$event_id, $email
		) );
		return $count > 0;
	}

	public static function get_event( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::events_table() . ' WHERE id = %d', $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$config        = json_decode( $row['config'], true );
		$row['config'] = is_array( $config ) ? array_merge( self::default_config(), $config ) : self::default_config();

		// Migrate legacy single early-bird config (pre-1.2) into a pricing period.
		if ( empty( $row['config']['pricing_periods'] ) && ! empty( $row['config']['early_bird_end'] ) ) {
			foreach ( (array) $row['config']['tickets'] as $t ) {
				if ( ! empty( $t['early_bird_cents'] ) ) {
					$row['config']['pricing_periods'][] = array(
						'ticket_key'  => $t['key'],
						'label'       => 'Early bird',
						'price_cents' => (int) $t['early_bird_cents'],
						'ends'        => $row['config']['early_bird_end'],
					);
				}
			}
		}
		return $row;
	}

	public static function get_events() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT id, title, status, created_at FROM ' . self::events_table() . ' ORDER BY id DESC', ARRAY_A );
	}

	public static function save_event( $id, $title, $status, $config ) {
		global $wpdb;
		$data = array(
			'title'      => $title,
			'status'     => $status,
			'config'     => wp_json_encode( $config ),
			'updated_at' => current_time( 'mysql' ),
		);
		if ( $id ) {
			$wpdb->update( self::events_table(), $data, array( 'id' => $id ) );
			return (int) $id;
		}
		$data['created_at'] = current_time( 'mysql' );
		$wpdb->insert( self::events_table(), $data );
		return (int) $wpdb->insert_id;
	}

	public static function delete_event( $id ) {
		global $wpdb;
		$wpdb->delete( self::events_table(), array( 'id' => $id ) );
	}

	public static function update_event_config( $id, $config ) {
		global $wpdb;
		$wpdb->update(
			self::events_table(),
			array( 'config' => wp_json_encode( $config ), 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $id )
		);
	}

	public static function find_ticket( $event, $key ) {
		foreach ( (array) $event['config']['tickets'] as $t ) {
			if ( $t['key'] === $key ) {
				return $t;
			}
		}
		return null;
	}

	/**
	 * Resolve which custom fields are currently visible, given a map of
	 * answers (field key => value). Conditional fields show only when their
	 * rules (combined by AND/OR) pass. A field that depends on a hidden
	 * controller sees that controller as empty, so chains/cascades resolve
	 * by repeating until the result stops changing.
	 *
	 * @param array $fields  Event config 'fields'.
	 * @param array $answers field key => string value.
	 * @return array field key => bool (visible).
	 */
	public static function evaluate_field_visibility( $fields, $answers ) {
		$fields  = (array) $fields;
		$visible = array();
		foreach ( $fields as $f ) {
			$visible[ $f['key'] ] = true;
		}
		$passes = count( $fields ) + 1;
		for ( $i = 0; $i < $passes; $i++ ) {
			$changed = false;
			foreach ( $fields as $f ) {
				$v = self::field_conditions_pass( $f, $answers, $visible );
				if ( $v !== $visible[ $f['key'] ] ) {
					$visible[ $f['key'] ] = $v;
					$changed = true;
				}
			}
			if ( ! $changed ) {
				break;
			}
		}
		return $visible;
	}

	private static function field_conditions_pass( $field, $answers, $visible ) {
		$conds = (array) ( $field['conditions'] ?? array() );
		if ( empty( $conds ) ) {
			return true;
		}
		$or      = 'or' === ( $field['cond_logic'] ?? 'and' );
		$results = array();
		foreach ( $conds as $c ) {
			$ck = $c['field'] ?? '';
			// A hidden controller contributes an empty value.
			$ctrl_visible = ! isset( $visible[ $ck ] ) || $visible[ $ck ];
			$val          = ( $ctrl_visible && isset( $answers[ $ck ] ) ) ? $answers[ $ck ] : '';
			if ( is_array( $val ) ) {
				$val = implode( ', ', $val );
			}
			$results[] = self::condition_test( $c, (string) $val );
		}
		return $or ? in_array( true, $results, true ) : ! in_array( false, $results, true );
	}

	private static function condition_test( $c, $val ) {
		$cv = (string) ( $c['value'] ?? '' );
		switch ( $c['operator'] ?? 'equals' ) {
			case 'not_equals':
				return $val !== $cv;
			case 'one_of':
				$list = array_filter( array_map( 'trim', explode( ',', $cv ) ), 'strlen' );
				return in_array( $val, $list, true );
			case 'not_empty':
				return '' !== trim( $val );
			case 'equals':
			default:
				return $val === $cv;
		}
	}

	/* ---------------- Registrations ---------------- */

	/**
	 * Still-pending registrations created before $cutoff (a 'Y-m-d H:i:s'
	 * string in site-local time, matching created_at) that haven't yet been
	 * pushed to GHL as an opportunity. Used by the abandoned-cart sweep.
	 */
	public static function get_abandoned_candidates( $cutoff, $limit = 50 ) {
		global $wpdb;
		$table = self::registrations_table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE status = 'pending' AND created_at < %s AND ( ghl_opportunity_id IS NULL OR ghl_opportunity_id = '' ) ORDER BY created_at ASC LIMIT %d",
				$cutoff,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Confirmed registrations with an in-progress payment plan. The
	 * installment sweep filters these down to the ones with an installment
	 * actually due, reading the schedule from the payment_plan JSON.
	 */
	public static function get_active_plan_registrations( $limit = 100 ) {
		global $wpdb;
		$table = self::registrations_table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE status = 'confirmed' AND plan_status = 'active' ORDER BY id ASC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
	}

	public static function insert_registration( $data ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$data = array_merge( array( 'created_at' => $now, 'updated_at' => $now ), $data );
		$wpdb->insert( self::registrations_table(), $data );
		return (int) $wpdb->insert_id;
	}

	public static function update_registration( $id, $data ) {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql' );
		$wpdb->update( self::registrations_table(), $data, array( 'id' => $id ) );
	}

	public static function delete_registration( $id ) {
		global $wpdb;
		$wpdb->delete( self::registrations_table(), array( 'id' => $id ) );
	}

	public static function get_registration( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::registrations_table() . ' WHERE id = %d', $id ), ARRAY_A );
	}

	public static function get_registration_by_intent( $intent_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::registrations_table() . ' WHERE stripe_payment_intent = %s ORDER BY id DESC LIMIT 1', $intent_id ), ARRAY_A );
	}

	public static function get_registrations( $event_id, $status = '' ) {
		global $wpdb;
		$sql    = 'SELECT * FROM ' . self::registrations_table() . ' WHERE event_id = %d';
		$params = array( $event_id );
		if ( $status ) {
			$sql     .= ' AND status = %s';
			$params[] = $status;
		}
		$sql .= ' ORDER BY id DESC';
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
	}

	/**
	 * Has this email already got a confirmed registration for the event?
	 */
	public static function has_confirmed_registration( $event_id, $email ) {
		global $wpdb;
		$count = $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . self::registrations_table() . " WHERE event_id = %d AND email = %s AND status = 'confirmed'",
			$event_id, $email
		) );
		return $count > 0;
	}

	/**
	 * The existing confirmed registration for this email on this event, if
	 * any. Used by manual adds to point the admin at the duplicate rather
	 * than just refusing.
	 */
	public static function find_confirmed_registration( $event_id, $email ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::registrations_table() . " WHERE event_id = %d AND email = %s AND status = 'confirmed' ORDER BY id DESC LIMIT 1",
			$event_id, $email
		), ARRAY_A );
	}

	/**
	 * Seats taken for a ticket key: confirmed, plus pending created in the
	 * last hour (in-flight checkouts hold a seat briefly).
	 */
	public static function ticket_seats_taken( $event_id, $ticket_key ) {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - HOUR_IN_SECONDS );
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . self::registrations_table() .
			" WHERE event_id = %d AND ticket_key = %s AND ( status = 'confirmed' OR ( status = 'pending' AND created_at > %s ) )",
			$event_id, $ticket_key, $cutoff
		) );
	}
}
