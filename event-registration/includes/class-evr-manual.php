<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Manual registrations — people the team adds to an event instead of having
 * them pay through the checkout (invoiced separately, comped, or paid some
 * other way).
 *
 * Three entry points, one code path:
 *   - the "Add a registrant manually" panel on the admin Registrations screen
 *   - the staff form shortcode (EVR_Staff), for a password-protected page the
 *     team uses without needing WordPress accounts
 *   - POST /wp-json/evr/v1/manual-registration, authenticated with the shared
 *     key in Settings, so a GoHighLevel form/workflow can push one in
 *
 * Nothing here touches Stripe: no PaymentIntent, no customer, no invoice. The
 * registration is written straight to 'confirmed' so the person counts on the
 * roster, in the CSV, and against ticket capacity, and it syncs to GHL like
 * any other registration.
 */
class EVR_Manual {

	const REST_NAMESPACE = 'evr/v1';
	const REST_ROUTE     = '/manual-registration';

	/**
	 * How a manually added registrant is being handled for money. Stored in
	 * the registration's `payment_state` column; the empty string means the
	 * row came through the normal paid checkout.
	 */
	public static function payment_states() {
		return array(
			'invoiced'      => 'Invoiced separately — not yet paid',
			'paid_external' => 'Already paid (outside the site)',
			'comp'          => 'Comped — no charge',
		);
	}

	public static function payment_state_label( $state ) {
		$states = self::payment_states();
		return $states[ $state ] ?? '';
	}

	/**
	 * How a registration got here, for the admin list and the CSV.
	 */
	public static function source_label( $source ) {
		$labels = array(
			'manual' => 'Added in admin',
			'staff'  => 'Staff form',
			'api'    => 'API',
		);
		return $labels[ $source ] ?? 'Checkout';
	}

	public static function init() {
		add_action( 'rest_api_init', function () {
			register_rest_route( self::REST_NAMESPACE, self::REST_ROUTE, array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_rest' ),
				'permission_callback' => array( __CLASS__, 'verify_key' ),
			) );
		} );
	}

	public static function endpoint_url() {
		return rest_url( trim( self::REST_NAMESPACE, '/' ) . self::REST_ROUTE );
	}

	/* ---------------- Core ---------------- */

	/**
	 * Create a confirmed registration without collecting payment.
	 *
	 * @param array $args {
	 *     @type int    $event_id        Required.
	 *     @type string $email           Required.
	 *     @type string $first_name      Required.
	 *     @type string $last_name       Required.
	 *     @type string $phone
	 *     @type string $ticket_key      '' = no ticket; the amount then comes from $amount_cents.
	 *     @type array  $addon_keys
	 *     @type string $promo_code
	 *     @type string $payment_state   comp | invoiced | paid_external.
	 *     @type int    $amount_cents    Explicit override in cents; null = price it as the checkout would.
	 *     @type string $note            Internal note, e.g. an invoice number.
	 *     @type string $added_by        Who on the team entered it.
	 *     @type array  $fields          Custom field key => value.
	 *     @type bool   $sync_ghl        Default true.
	 *     @type bool   $force_duplicate Add even if this email is already registered.
	 *     @type string $source          'manual' (admin screen) | 'staff' (staff page) | 'api' (REST).
	 * }
	 * @return int|WP_Error Registration ID.
	 */
	public static function create( $args ) {
		$args = array_merge( array(
			'event_id'        => 0,
			'email'           => '',
			'first_name'      => '',
			'last_name'       => '',
			'phone'           => '',
			'ticket_key'      => '',
			'addon_keys'      => array(),
			'promo_code'      => '',
			'payment_state'   => 'invoiced',
			'amount_cents'    => null,
			'note'            => '',
			'added_by'        => '',
			'fields'          => array(),
			'sync_ghl'        => true,
			'force_duplicate' => false,
			'source'          => 'manual',
		), (array) $args );

		$event = EVR_DB::get_event( absint( $args['event_id'] ) );
		if ( ! $event ) {
			return new WP_Error( 'evr_manual_event', 'Event not found.', array( 'status' => 404 ) );
		}

		$email = sanitize_email( (string) $args['email'] );
		$first = sanitize_text_field( (string) $args['first_name'] );
		$last  = sanitize_text_field( (string) $args['last_name'] );
		if ( ! is_email( $email ) || '' === $first || '' === $last ) {
			return new WP_Error( 'evr_manual_contact', 'A first name, last name and valid email address are required.', array( 'status' => 400 ) );
		}

		$state = array_key_exists( $args['payment_state'], self::payment_states() ) ? $args['payment_state'] : 'invoiced';

		// Duplicate guard: same rule as the public form (the event's
		// "allow duplicates" setting), overridable per add.
		if ( empty( $args['force_duplicate'] ) && empty( $event['config']['allow_duplicates'] ) ) {
			$existing = EVR_DB::find_confirmed_registration( (int) $event['id'], $email );
			if ( $existing ) {
				return new WP_Error(
					'evr_manual_duplicate',
					sprintf( '%s is already registered for this event (registration #%d).', $email, (int) $existing['id'] ),
					array( 'status' => 409, 'registration_id' => (int) $existing['id'] )
				);
			}
		}

		// Membership tier still comes from Supabase, so a member added by
		// hand gets their tier recorded and their discount priced in.
		$tier = EVR_Supabase::get_tier( $email );

		$ticket_key = sanitize_text_field( (string) $args['ticket_key'] );
		$addon_keys = array_values( array_filter( array_map( 'sanitize_text_field', (array) $args['addon_keys'] ) ) );
		$promo      = sanitize_text_field( (string) $args['promo_code'] );

		$currency      = $event['config']['currency'] ?: 'usd';
		$ticket_label  = '';
		$promo_applied = '';
		$priced_cents  = null;

		if ( '' !== $ticket_key ) {
			// Manual adds deliberately ignore sold-out/inactive tickets and
			// the registration window — that is the point of adding by hand.
			$quote = EVR_Pricing::quote( $event, $ticket_key, $addon_keys, $tier, $promo, array( 'ignore_availability' => true ) );
			if ( is_wp_error( $quote ) ) {
				return new WP_Error( 'evr_manual_pricing', $quote->get_error_message(), array( 'status' => 400 ) );
			}
			$priced_cents  = (int) $quote['total_cents'];
			$currency      = $quote['currency'];
			$promo_applied = $quote['promo_code'];
			$ticket        = EVR_DB::find_ticket( $event, $ticket_key );
			$ticket_label  = $ticket ? $ticket['label'] : '';
		} elseif ( $addon_keys ) {
			return new WP_Error( 'evr_manual_pricing', 'Select a ticket before adding add-ons.', array( 'status' => 400 ) );
		}

		// An explicit amount always wins over the computed price; a comp is
		// always zero whatever was selected.
		$amount_cents = null !== $args['amount_cents'] ? max( 0, (int) $args['amount_cents'] ) : (int) $priced_cents;
		if ( 'comp' === $state ) {
			$amount_cents = 0;
		}

		// Custom field answers. Required-ness is NOT enforced here: whoever is
		// adding this person by hand may not have every answer, and the whole
		// point of this path is to bypass the public form's gates.
		$answers = array();
		foreach ( (array) $event['config']['fields'] as $field ) {
			$value = $args['fields'][ $field['key'] ] ?? '';
			$answers[ $field['key'] ] = is_array( $value )
				? array_map( 'sanitize_text_field', $value )
				: sanitize_textarea_field( (string) $value );
		}

		$reg_id = EVR_DB::insert_registration( array(
			'event_id'          => (int) $event['id'],
			'status'            => 'pending', // Flipped to confirmed below, through the normal path.
			'email'             => $email,
			'first_name'        => $first,
			'last_name'         => $last,
			'phone'             => sanitize_text_field( (string) $args['phone'] ),
			'tier'              => $tier,
			'ticket_key'        => $ticket_key,
			'ticket_label'      => $ticket_label,
			'addons'            => wp_json_encode( $addon_keys ),
			'custom_fields'     => wp_json_encode( $answers ),
			'utm'               => wp_json_encode( array() ),
			'amount_cents'      => $amount_cents,
			'amount_paid_cents' => 'paid_external' === $state ? $amount_cents : 0,
			'currency'          => $currency,
			'promo_code'        => $promo_applied,
			'stripe_mode'       => EVR_Settings::event_mode( $event ),
			'source'            => in_array( $args['source'], array( 'manual', 'staff', 'api' ), true ) ? $args['source'] : 'manual',
			'payment_state'     => $state,
			'added_by'          => sanitize_text_field( (string) $args['added_by'] ),
			'admin_note'        => sanitize_textarea_field( (string) $args['note'] ),
		) );

		if ( ! $reg_id ) {
			return new WP_Error( 'evr_manual_insert', 'Could not save the registration.', array( 'status' => 500 ) );
		}

		// Same confirmation path as a paid registration: records promo usage,
		// syncs GHL, and fires evr_registration_confirmed.
		EVR_Webhook::confirm_registration( $reg_id, null, ! empty( $args['sync_ghl'] ) );

		/**
		 * Fires after a registrant is added by hand (admin screen or API).
		 *
		 * @param int    $reg_id
		 * @param string $state  comp | invoiced | paid_external.
		 * @param string $source manual | api.
		 */
		do_action( 'evr_manual_registration_added', $reg_id, $state, $args['source'] );

		return $reg_id;
	}

	/**
	 * Mark an invoiced manual registration as paid: records the money as
	 * collected and re-syncs GHL so the opportunity leaves the
	 * Outstanding-payment stage.
	 *
	 * @return true|WP_Error
	 */
	public static function mark_paid( $registration_id ) {
		$reg = EVR_DB::get_registration( absint( $registration_id ) );
		if ( ! $reg ) {
			return new WP_Error( 'evr_manual', 'Registration not found.' );
		}
		if ( 'invoiced' !== ( $reg['payment_state'] ?? '' ) ) {
			return new WP_Error( 'evr_manual', 'This registration is not awaiting a separate invoice.' );
		}
		EVR_DB::update_registration( (int) $reg['id'], array(
			'payment_state'     => 'paid_external',
			'amount_paid_cents' => (int) $reg['amount_cents'],
		) );
		EVR_GHL::sync_registration( (int) $reg['id'] );
		return true;
	}

	/* ---------------- REST endpoint (GHL form / workflow) ---------------- */

	/**
	 * Shared-key auth. The key lives in Settings; blank means the endpoint is
	 * switched off entirely.
	 */
	public static function verify_key( $request ) {
		$key = (string) EVR_Settings::get( 'manual_api_key' );
		if ( '' === $key ) {
			return new WP_Error( 'evr_manual_disabled', 'The manual registration endpoint is not enabled.', array( 'status' => 403 ) );
		}
		$given = (string) $request->get_header( 'x-evr-key' );
		if ( '' === $given ) {
			$given = (string) $request->get_param( 'key' );
		}
		if ( ! hash_equals( $key, $given ) ) {
			return new WP_Error( 'evr_manual_auth', 'Invalid or missing key.', array( 'status' => 403 ) );
		}
		return true;
	}

	public static function handle_rest( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_params();
		}

		// Amount may arrive as dollars (`amount`) or cents (`amount_cents`);
		// blank/absent means "price it from the ticket".
		$amount_cents = null;
		if ( isset( $body['amount_cents'] ) && '' !== $body['amount_cents'] ) {
			$amount_cents = (int) $body['amount_cents'];
		} elseif ( isset( $body['amount'] ) && '' !== $body['amount'] ) {
			$amount_cents = (int) round( (float) $body['amount'] * 100 );
		}

		$addons = $body['addon_keys'] ?? ( $body['addons'] ?? array() );
		if ( is_string( $addons ) ) {
			$addons = array_filter( array_map( 'trim', explode( ',', $addons ) ) );
		}

		$result = self::create( array(
			'event_id'        => $body['event_id'] ?? 0,
			'email'           => $body['email'] ?? '',
			'first_name'      => $body['first_name'] ?? '',
			'last_name'       => $body['last_name'] ?? '',
			'phone'           => $body['phone'] ?? '',
			'ticket_key'      => $body['ticket_key'] ?? '',
			'addon_keys'      => $addons,
			'promo_code'      => $body['promo_code'] ?? '',
			'payment_state'   => $body['payment_state'] ?? 'invoiced',
			'amount_cents'    => $amount_cents,
			'note'            => $body['note'] ?? '',
			'fields'          => (array) ( $body['fields'] ?? array() ),
			'sync_ghl'        => ! isset( $body['sync_ghl'] ) || (bool) $body['sync_ghl'],
			'force_duplicate' => ! empty( $body['force_duplicate'] ),
			'source'          => 'api',
		) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$reg = EVR_DB::get_registration( $result );
		return new WP_REST_Response( array(
			'registration_id' => (int) $result,
			'status'          => $reg['status'],
			'payment_state'   => $reg['payment_state'],
			'amount'          => number_format( (int) $reg['amount_cents'] / 100, 2, '.', '' ),
			'currency'        => strtoupper( $reg['currency'] ),
			'tier'            => $reg['tier'],
			'ghl_sync'        => $reg['ghl_sync_status'],
			'ghl_sync_error'  => $reg['ghl_sync_error'],
		), 201 );
	}
}
