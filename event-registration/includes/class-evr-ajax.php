<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AJAX endpoints. Public (front-end checkout) + admin (event editor helpers).
 */
class EVR_Ajax {

	public static function init() {
		// Public checkout endpoints.
		foreach ( array( 'evr_check_membership', 'evr_quote', 'evr_prepare_checkout', 'evr_create_intent', 'evr_register_free', 'evr_client_confirm', 'evr_join_waitlist' ) as $action ) {
			add_action( 'wp_ajax_' . $action, array( __CLASS__, substr( $action, 4 ) ) );
			add_action( 'wp_ajax_nopriv_' . $action, array( __CLASS__, substr( $action, 4 ) ) );
		}
		// Admin helpers.
		add_action( 'wp_ajax_evr_stripe_products', array( __CLASS__, 'stripe_products' ) );
		add_action( 'wp_ajax_evr_ghl_pipelines', array( __CLASS__, 'ghl_pipelines' ) );
		add_action( 'wp_ajax_evr_ghl_fields', array( __CLASS__, 'ghl_fields' ) );
		add_action( 'wp_ajax_evr_retry_ghl', array( __CLASS__, 'retry_ghl' ) );
		add_action( 'wp_ajax_evr_portal_link', array( __CLASS__, 'portal_link' ) );
		add_action( 'wp_ajax_evr_send_payoff', array( __CLASS__, 'send_payoff' ) );
		add_action( 'wp_ajax_evr_cancel_installment', array( __CLASS__, 'cancel_installment' ) );
		add_action( 'wp_ajax_evr_cancel_remaining', array( __CLASS__, 'cancel_remaining' ) );
		add_action( 'wp_ajax_evr_mark_manual_paid', array( __CLASS__, 'mark_manual_paid' ) );
		// CSV export.
		add_action( 'admin_post_evr_export_csv', array( __CLASS__, 'export_csv' ) );
	}

	private static function verify_public_nonce() {
		if ( ! check_ajax_referer( 'evr_public', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed. Please refresh the page.' ), 403 );
		}
	}

	private static function get_active_event() {
		$event = EVR_DB::get_event( absint( $_POST['event_id'] ?? 0 ) );
		if ( ! $event ) {
			wp_send_json_error( array( 'message' => 'Event not found.' ), 404 );
		}
		return $event;
	}

	/* ---------------- Public ---------------- */

	/**
	 * Look up membership tier by email (Supabase).
	 */
	public static function check_membership() {
		self::verify_public_nonce();
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$tier  = EVR_Supabase::get_tier( $email );
		wp_send_json_success( array(
			'tier'  => $tier,
			'label' => $tier ? ucfirst( $tier ) : '',
		) );
	}

	/**
	 * Price quote for current selections (display only; recomputed at payment).
	 */
	public static function quote() {
		self::verify_public_nonce();
		$event  = self::get_active_event();
		$window = EVR_Pricing::check_window( $event );
		if ( is_wp_error( $window ) ) {
			wp_send_json_error( array( 'message' => $window->get_error_message() ) );
		}

		$quote = EVR_Pricing::quote(
			$event,
			sanitize_text_field( wp_unslash( $_POST['ticket_key'] ?? '' ) ),
			array_map( 'sanitize_text_field', (array) ( $_POST['addon_keys'] ?? array() ) ),
			EVR_Supabase::normalize_tier( wp_unslash( $_POST['tier'] ?? '' ) ),
			sanitize_text_field( wp_unslash( $_POST['promo_code'] ?? '' ) )
		);
		if ( is_wp_error( $quote ) ) {
			wp_send_json_error( array( 'message' => $quote->get_error_message() ) );
		}
		wp_send_json_success( $quote );
	}

	/**
	 * Validate name/email/custom fields (shared by registration + waitlist).
	 */
	private static function collect_contact( $event ) {
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$first = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
		$last  = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
		$phone = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
		if ( ! is_email( $email ) || ! $first || ! $last ) {
			wp_send_json_error( array( 'message' => 'Please complete your name and a valid email address.' ) );
		}
		if ( ! empty( $event['config']['collect_phone'] ) && '' === $phone ) {
			wp_send_json_error( array( 'message' => 'Please enter your phone number.' ) );
		}
		if ( ! empty( $event['config']['consent_text'] ) && empty( $_POST['consent'] ) ) {
			wp_send_json_error( array( 'message' => 'Please check the consent box to continue.' ) );
		}

		// Custom fields: collect answers first, then resolve conditional
		// visibility so we only enforce "required" on fields actually shown.
		$raw_fields = (array) ( $_POST['fields'] ?? array() );
		$answers    = array();
		foreach ( (array) $event['config']['fields'] as $field ) {
			$value = isset( $raw_fields[ $field['key'] ] ) ? wp_unslash( $raw_fields[ $field['key'] ] ) : '';
			$value = is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : sanitize_textarea_field( $value );
			$answers[ $field['key'] ] = $value;
		}

		$visible = EVR_DB::evaluate_field_visibility( $event['config']['fields'], $answers );
		foreach ( (array) $event['config']['fields'] as $field ) {
			if ( empty( $visible[ $field['key'] ] ) ) {
				// Hidden by its conditions — don't require it, don't store a stale value.
				$answers[ $field['key'] ] = '';
				continue;
			}
			$val = $answers[ $field['key'] ];
			if ( ! empty( $field['required'] ) && '' === ( is_array( $val ) ? implode( '', $val ) : trim( $val ) ) ) {
				wp_send_json_error( array( 'message' => sprintf( '"%s" is required.', $field['label'] ) ) );
			}
		}

		// UTM tags captured by the form (for affiliate/source tracking).
		$utm     = array();
		$raw_utm = (array) ( $_POST['utm'] ?? array() );
		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ) as $k ) {
			if ( ! empty( $raw_utm[ $k ] ) ) {
				$utm[ $k ] = sanitize_text_field( wp_unslash( $raw_utm[ $k ] ) );
			}
		}

		return array(
			'email'   => $email,
			'first'   => $first,
			'last'    => $last,
			'phone'   => $phone,
			'answers' => $answers,
			'utm'     => $utm,
		);
	}

	/**
	 * Gather + validate registration input shared by paid and free paths.
	 */
	private static function collect_registration( $event ) {
		$window = EVR_Pricing::check_window( $event );
		if ( is_wp_error( $window ) ) {
			wp_send_json_error( array( 'message' => $window->get_error_message() ) );
		}

		$contact = self::collect_contact( $event );
		$email   = $contact['email'];

		if ( empty( $event['config']['allow_duplicates'] ) && EVR_DB::has_confirmed_registration( $event['id'], $email ) ) {
			wp_send_json_error( array( 'message' => 'This email address is already registered for this event.' ) );
		}

		// Server-side tier lookup — never trust the browser's claimed tier.
		$tier = EVR_Supabase::get_tier( $email );

		$answers = $contact['answers'];

		$ticket_key = sanitize_text_field( wp_unslash( $_POST['ticket_key'] ?? '' ) );
		$addon_keys = array_map( 'sanitize_text_field', (array) ( $_POST['addon_keys'] ?? array() ) );
		$promo      = sanitize_text_field( wp_unslash( $_POST['promo_code'] ?? '' ) );

		$quote = EVR_Pricing::quote( $event, $ticket_key, $addon_keys, $tier, $promo );
		if ( is_wp_error( $quote ) ) {
			wp_send_json_error( array( 'message' => $quote->get_error_message() ) );
		}

		$ticket = EVR_DB::find_ticket( $event, $ticket_key );

		return array(
			'row'   => array(
				'event_id'      => $event['id'],
				'email'         => $email,
				'first_name'    => $contact['first'],
				'last_name'     => $contact['last'],
				'phone'         => $contact['phone'],
				'tier'          => $tier,
				'ticket_key'    => $ticket_key,
				'ticket_label'  => $ticket ? $ticket['label'] : '',
				'addons'        => wp_json_encode( $addon_keys ),
				'custom_fields' => wp_json_encode( $answers ),
				'utm'           => wp_json_encode( $contact['utm'] ),
				'amount_cents'  => $quote['total_cents'],
				'currency'      => $quote['currency'],
				'promo_code'    => $quote['promo_code'],
				'stripe_mode'   => EVR_Settings::event_mode( $event ),
			),
			'quote' => $quote,
		);
	}

	/**
	 * Create the pending registration and return the amount/currency so the
	 * client can mount Stripe's Payment Element in deferred mode. Deliberately
	 * does NOT touch Stripe: the PaymentIntent is created later by
	 * create_intent(), only when the visitor actually submits payment — so an
	 * abandoned form-fill leaves no Incomplete transaction in Stripe. The
	 * pending row is still written here so the abandoned-cart sweep can follow
	 * up on unpaid registrations as before. If the computed total is 0, tells
	 * the client to use the free path instead.
	 */
	public static function prepare_checkout() {
		self::verify_public_nonce();
		$event = self::get_active_event();
		$data  = self::collect_registration( $event );

		if ( 0 === $data['row']['amount_cents'] ) {
			wp_send_json_success( array( 'free' => true ) );
		}

		// Payment plan (installments): only when the visitor asked for it AND
		// it's actually allowed for this event/total right now. Availability is
		// recomputed here — the browser's choice is never trusted.
		$want_plan = ! empty( $_POST['pay_plan'] );
		$plan_ok   = $want_plan && EVR_Installments::plan_available( $event, $data['row']['amount_cents'] );
		if ( $want_plan && ! $plan_ok ) {
			wp_send_json_error( array( 'message' => 'The payment plan is no longer available for this event. Please refresh the page and pay in full.' ) );
		}

		$data['row']['status'] = 'pending';
		$reg_id = EVR_DB::insert_registration( $data['row'] );

		if ( $plan_ok ) {
			$plan     = EVR_Installments::plan_config( $event );
			$schedule = EVR_Installments::attach_schedule( $reg_id, $data['row']['amount_cents'], $plan );
			wp_send_json_success( array(
				'registration_id'   => $reg_id,
				'amount_cents'      => (int) $schedule[0]['amount_cents'], // Charged today.
				'first_amount_cents' => (int) $schedule[0]['amount_cents'],
				'total_cents'       => $data['row']['amount_cents'],
				'currency'          => $data['row']['currency'],
				'pay_plan'          => true,
				'installments'      => $schedule,
				'quote'             => $data['quote'],
			) );
		}

		wp_send_json_success( array(
			'registration_id' => $reg_id,
			'amount_cents'    => $data['row']['amount_cents'],
			'currency'        => $data['row']['currency'],
			'quote'           => $data['quote'],
		) );
	}

	/**
	 * Create (or reuse) the Stripe PaymentIntent for a pending registration.
	 * Called only when the visitor submits the payment form (deferred flow),
	 * so a Stripe transaction exists only for genuine payment attempts. The
	 * amount/currency come from the server-stored registration row, never the
	 * browser. Reuses an existing intent so a declined card that's retried
	 * doesn't spawn a second Incomplete PaymentIntent.
	 */
	public static function create_intent() {
		self::verify_public_nonce();
		$reg_id = absint( $_POST['registration_id'] ?? 0 );
		$reg    = EVR_DB::get_registration( $reg_id );
		if ( ! $reg ) {
			wp_send_json_error( array( 'message' => 'Registration not found.' ), 404 );
		}
		// Resolve the event from the registration itself — the pay step only
		// posts registration_id, so we must not depend on a POSTed event_id here.
		$event = EVR_DB::get_event( (int) $reg['event_id'] );
		if ( ! $event ) {
			wp_send_json_error( array( 'message' => 'Event not found.' ), 404 );
		}
		if ( 'pending' !== $reg['status'] ) {
			wp_send_json_error( array( 'message' => 'This registration can no longer be paid for.' ) );
		}
		if ( (int) $reg['amount_cents'] <= 0 ) {
			wp_send_json_error( array( 'message' => 'Payment is not required for this selection.' ) );
		}

		// Reuse the intent already attached to this registration (e.g. the
		// visitor's card was declined and they're retrying) instead of
		// minting a fresh one each attempt.
		if ( $reg['stripe_payment_intent'] ) {
			$existing = EVR_Stripe::get_payment_intent( $reg['stripe_payment_intent'], $reg['stripe_mode'] );
			if ( ! is_wp_error( $existing ) && ! empty( $existing['client_secret'] ) ) {
				wp_send_json_success( array(
					'registration_id' => $reg_id,
					'client_secret'   => $existing['client_secret'],
				) );
			}
		}

		// A payment plan charges only the first installment today and saves
		// the card (off-session) for the rest; otherwise charge the full total.
		$is_plan = (bool) EVR_Installments::get_plan( $reg );
		$amount  = EVR_Installments::first_amount_cents( $reg );
		$ticket  = EVR_DB::find_ticket( $event, $reg['ticket_key'] );

		// Connect the charge to its Stripe product for dashboard/reporting.
		$metadata = array(
			'evr_registration_id'   => $reg_id,
			'evr_event_id'          => $event['id'],
			'evr_event_title'       => $event['title'],
			'evr_stripe_product_id' => EVR_Stripe::ticket_product_id( $ticket, $reg['stripe_mode'] ),
		);
		$description = trim( (string) $event['title'] . ( $reg['ticket_label'] ? ' — ' . $reg['ticket_label'] : '' ) );
		$opts        = array();

		if ( $is_plan ) {
			$metadata['evr_installment_index'] = 0;
			$opts['setup_future_usage']        = 'off_session';
			$customer = EVR_Stripe::get_or_create_customer(
				$reg['email'],
				trim( $reg['first_name'] . ' ' . $reg['last_name'] ),
				$reg['stripe_mode']
			);
			if ( is_wp_error( $customer ) ) {
				wp_send_json_error( array( 'message' => $customer->get_error_message() ) );
			}
			$opts['customer'] = $customer;
			EVR_DB::update_registration( $reg_id, array( 'stripe_customer_id' => $customer ) );
		}

		$intent = EVR_Stripe::create_payment_intent(
			(int) $amount,
			$reg['currency'],
			$metadata,
			$reg['email'],
			$reg['stripe_mode'],
			$description,
			$opts
		);
		if ( is_wp_error( $intent ) ) {
			wp_send_json_error( array( 'message' => $intent->get_error_message() ) );
		}

		EVR_DB::update_registration( $reg_id, array( 'stripe_payment_intent' => $intent['id'] ) );

		wp_send_json_success( array(
			'registration_id' => $reg_id,
			'client_secret'   => $intent['client_secret'],
		) );
	}

	/**
	 * Zero-total registration (fully discounted or free event) — no Stripe.
	 */
	public static function register_free() {
		self::verify_public_nonce();
		$event = self::get_active_event();
		$data  = self::collect_registration( $event );

		if ( $data['row']['amount_cents'] > 0 ) {
			wp_send_json_error( array( 'message' => 'Payment is required for this selection.' ) );
		}

		$data['row']['status'] = 'pending';
		$reg_id = EVR_DB::insert_registration( $data['row'] );
		EVR_Webhook::confirm_registration( $reg_id );

		wp_send_json_success( array( 'message' => $event['config']['success_message'] ) );
	}

	/**
	 * Join the waitlist — no payment. Available before registration opens
	 * (if enabled) or when all main tickets are sold out (if enabled).
	 */
	public static function join_waitlist() {
		self::verify_public_nonce();
		$event = self::get_active_event();

		if ( ! EVR_Pricing::waitlist_state( $event ) ) {
			wp_send_json_error( array( 'message' => 'The waitlist is not open for this event.' ) );
		}

		$contact = self::collect_contact( $event );

		if ( EVR_DB::has_waitlist_entry( $event['id'], $contact['email'] ) ) {
			wp_send_json_error( array( 'message' => "You're already on the waitlist for this event." ) );
		}
		if ( empty( $event['config']['allow_duplicates'] ) && EVR_DB::has_confirmed_registration( $event['id'], $contact['email'] ) ) {
			wp_send_json_error( array( 'message' => 'This email address is already registered for this event.' ) );
		}

		$reg_id = EVR_DB::insert_registration( array(
			'event_id'      => $event['id'],
			'status'        => 'waitlist',
			'email'         => $contact['email'],
			'first_name'    => $contact['first'],
			'last_name'     => $contact['last'],
			'phone'         => $contact['phone'],
			'tier'          => EVR_Supabase::get_tier( $contact['email'] ),
			'custom_fields' => wp_json_encode( $contact['answers'] ),
			'utm'           => wp_json_encode( $contact['utm'] ),
			'amount_cents'  => 0,
			'currency'      => $event['config']['currency'] ?: 'usd',
			'stripe_mode'   => EVR_Settings::event_mode( $event ),
		) );

		EVR_GHL::sync_registration( $reg_id );

		/**
		 * Fires after someone joins the waitlist.
		 *
		 * @param int $reg_id
		 */
		do_action( 'evr_waitlist_joined', $reg_id );

		wp_send_json_success( array( 'message' => $event['config']['waitlist']['message'] ) );
	}

	/**
	 * Client-side fallback after Stripe confirms payment in the browser:
	 * verify the PaymentIntent directly with Stripe and confirm. The
	 * webhook remains the authoritative path; this covers webhook delays
	 * or misconfiguration. Idempotent.
	 */
	public static function client_confirm() {
		self::verify_public_nonce();
		$reg_id    = absint( $_POST['registration_id'] ?? 0 );
		$intent_id = sanitize_text_field( wp_unslash( $_POST['payment_intent'] ?? '' ) );
		$reg       = EVR_DB::get_registration( $reg_id );

		if ( ! $reg || $reg['stripe_payment_intent'] !== $intent_id ) {
			wp_send_json_error( array( 'message' => 'Registration not found.' ), 404 );
		}
		if ( 'confirmed' === $reg['status'] ) {
			wp_send_json_success( array( 'status' => 'confirmed' ) );
		}

		$intent = EVR_Stripe::get_payment_intent( $intent_id, $reg['stripe_mode'] );
		if ( is_wp_error( $intent ) || 'succeeded' !== ( $intent['status'] ?? '' ) ) {
			wp_send_json_error( array( 'message' => 'Payment has not completed yet.' ) );
		}

		EVR_Webhook::confirm_registration( $reg_id, $intent );
		wp_send_json_success( array( 'status' => 'confirmed' ) );
	}

	/* ---------------- Admin ---------------- */

	private static function verify_admin() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'evr_admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
		}
	}

	public static function stripe_products() {
		self::verify_admin();
		$mode = sanitize_text_field( wp_unslash( $_POST['mode'] ?? '' ) );
		$mode = in_array( $mode, array( 'test', 'live' ), true ) ? $mode : EVR_Settings::stripe_mode();
		$products = EVR_Stripe::list_products( $mode );
		if ( is_wp_error( $products ) ) {
			wp_send_json_error( array( 'message' => $products->get_error_message() ) );
		}
		wp_send_json_success( array( 'mode' => $mode, 'products' => $products ) );
	}

	public static function ghl_pipelines() {
		self::verify_admin();
		$location = sanitize_text_field( wp_unslash( $_POST['location_id'] ?? '' ) );
		$token    = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		$result   = EVR_GHL::get_pipelines( $location, $token );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'pipelines' => $result ) );
	}

	public static function ghl_fields() {
		self::verify_admin();
		$location = sanitize_text_field( wp_unslash( $_POST['location_id'] ?? '' ) );
		$token    = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		$result   = EVR_GHL::get_custom_fields( $location, $token );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'fields' => $result ) );
	}

	public static function retry_ghl() {
		self::verify_admin();
		$result = EVR_GHL::sync_registration( absint( $_POST['registration_id'] ?? 0 ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success();
	}

	/**
	 * Generate a Stripe Customer Portal link for a payment-plan registration so
	 * the team (or the customer, on a call) can update the card that future
	 * installment invoices will charge. Links are single-use and short-lived.
	 */
	public static function portal_link() {
		self::verify_admin();
		$reg = EVR_DB::get_registration( absint( $_POST['registration_id'] ?? 0 ) );
		if ( ! $reg || empty( $reg['stripe_customer_id'] ) ) {
			wp_send_json_error( array( 'message' => 'No Stripe customer on this registration yet.' ) );
		}
		$session = EVR_Stripe::create_portal_session(
			$reg['stripe_customer_id'],
			admin_url( 'admin.php?page=evr-registrations&event_id=' . (int) $reg['event_id'] ),
			$reg['stripe_mode']
		);
		if ( is_wp_error( $session ) ) {
			wp_send_json_error( array( 'message' => $session->get_error_message() ) );
		}
		wp_send_json_success( array( 'url' => $session['url'] ?? '' ) );
	}

	/**
	 * Email the customer an invoice for the plan's full remaining balance so
	 * they can pay it off early. Pauses auto-charges until it's paid/voided.
	 */
	public static function send_payoff() {
		self::verify_admin();
		$result = EVR_Installments::send_payoff_invoice( absint( $_POST['registration_id'] ?? 0 ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'url' => $result['hosted_invoice_url'] ?? '' ) );
	}

	/**
	 * Call off one scheduled payment (voiding its Stripe invoice if one is
	 * already out), leaving any later payment on the plan intact.
	 */
	public static function cancel_installment() {
		self::verify_admin();
		$result = EVR_Installments::cancel_installment(
			absint( $_POST['registration_id'] ?? 0 ),
			absint( $_POST['index'] ?? 0 )
		);
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success();
	}

	/**
	 * Call off every payment on the plan that hasn't been collected yet.
	 */
	public static function cancel_remaining() {
		self::verify_admin();
		$result = EVR_Installments::cancel_remaining( absint( $_POST['registration_id'] ?? 0 ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'canceled' => (int) $result ) );
	}

	/**
	 * Mark a manually added, separately invoiced registration as paid. Records
	 * the money as collected and re-syncs GHL so the opportunity moves out of
	 * the Outstanding-payment stage.
	 */
	public static function mark_manual_paid() {
		self::verify_admin();
		$result = EVR_Manual::mark_paid( absint( $_POST['registration_id'] ?? 0 ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success();
	}

	/**
	 * Download an event's registrations as CSV (custom fields flattened
	 * into their own columns).
	 */
	public static function export_csv() {
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_GET['nonce'] ?? '', 'evr_admin' ) ) {
			wp_die( 'Not allowed.' );
		}
		$event_id = absint( $_GET['event_id'] ?? 0 );
		$event    = EVR_DB::get_event( $event_id );
		if ( ! $event ) {
			wp_die( 'Event not found.' );
		}

		$regs = EVR_DB::get_registrations( $event_id );

		// Union of custom field keys, in configured order.
		$field_defs = array();
		foreach ( (array) $event['config']['fields'] as $f ) {
			$field_defs[ $f['key'] ] = $f['label'];
		}

		$filename = sanitize_file_name( $event['title'] ?: 'event' ) . '-registrations-' . gmdate( 'Ymd' ) . '.csv';
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out  = fopen( 'php://output', 'w' );
		$head = array( 'ID', 'Date', 'Status', 'Added Via', 'Added By', 'Payment Handling', 'Admin Note', 'Email', 'First Name', 'Last Name', 'Phone', 'Membership Tier', 'Ticket', 'Add-ons', 'Amount', 'Currency', 'Promo Code', 'Stripe Mode', 'Payment Intent', 'GHL Sync', 'Plan Status', 'Paid', 'Installments', 'UTM Source', 'UTM Medium', 'UTM Campaign', 'UTM Term', 'UTM Content' );
		foreach ( $field_defs as $label ) {
			$head[] = $label;
		}
		fputcsv( $out, $head );

		foreach ( $regs as $reg ) {
			$addon_keys = json_decode( $reg['addons'], true ) ?: array();
			$addon_labels = array();
			foreach ( $addon_keys as $key ) {
				$t = EVR_DB::find_ticket( $event, $key );
				$addon_labels[] = $t ? $t['label'] : $key;
			}
			$answers = json_decode( $reg['custom_fields'], true ) ?: array();
			$row = array(
				$reg['id'],
				$reg['created_at'],
				$reg['status'],
				EVR_Manual::source_label( (string) ( $reg['source'] ?? '' ) ),
				(string) ( $reg['added_by'] ?? '' ),
				EVR_Manual::payment_state_label( (string) ( $reg['payment_state'] ?? '' ) ),
				(string) ( $reg['admin_note'] ?? '' ),
				$reg['email'],
				$reg['first_name'],
				$reg['last_name'],
				$reg['phone'],
				$reg['tier'],
				$reg['ticket_label'],
				implode( '; ', $addon_labels ),
				number_format( $reg['amount_cents'] / 100, 2, '.', '' ),
				strtoupper( $reg['currency'] ),
				$reg['promo_code'],
				$reg['stripe_mode'],
				$reg['stripe_payment_intent'],
				$reg['ghl_sync_status'],
			);

			// Payment plan columns: status, amount collected so far, and a
			// "paid/total" installment count.
			$plan = EVR_Installments::get_plan( $reg );
			if ( $plan ) {
				$paid_count = 0;
				foreach ( $plan['installments'] as $inst ) {
					if ( 'paid' === $inst['status'] ) {
						$paid_count++;
					}
				}
				$row[] = $reg['plan_status'];
				$row[] = number_format( ( (int) $reg['amount_paid_cents'] ) / 100, 2, '.', '' );
				$row[] = $paid_count . '/' . count( $plan['installments'] );
			} else {
				$row[] = '';
				$row[] = '';
				$row[] = '';
			}

			$utm = json_decode( $reg['utm'] ?? '', true ) ?: array();
			foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ) as $param ) {
				$row[] = $utm[ $param ] ?? '';
			}
			foreach ( array_keys( $field_defs ) as $key ) {
				$value = $answers[ $key ] ?? '';
				$row[] = is_array( $value ) ? implode( ', ', $value ) : $value;
			}
			fputcsv( $out, $row );
		}
		fclose( $out );
		exit;
	}
}
