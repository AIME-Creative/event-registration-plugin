<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Staff registration form. Usage: [event_registration_staff] (alias: [evr_staff])
 *
 * A front-end form for the team to add registrants without collecting payment
 * — for people being invoiced separately. It lists every event straight from
 * the database, so events created in future need no setup or mapping: they
 * simply appear in the dropdown.
 *
 * Access is gated two ways, and the form refuses to render if neither is in
 * place (so it can never be published wide open):
 *
 *   - Page password (the default). Put the shortcode on a page set to
 *     "Password protected"; the team shares that one password and needs no
 *     WordPress accounts.
 *   - Signed-in users, with [event_registration_staff require_login="yes"],
 *     optionally narrowed with capability="edit_posts".
 *
 * The AJAX endpoints re-check that gate on every request. The page they belong
 * to is carried in a signed token rather than a plain ID, so the check can't be
 * pointed at some other, unprotected page.
 */
class EVR_Staff {

	const SHORTCODE = 'event_registration_staff';

	/** Submissions allowed per IP per hour. Generous for a team, useless for a bot. */
	const MAX_PER_HOUR = 60;

	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
		add_shortcode( 'evr_staff', array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );

		foreach ( array( 'evr_staff_config', 'evr_staff_register' ) as $action ) {
			add_action( 'wp_ajax_' . $action, array( __CLASS__, substr( $action, 4 ) ) );
			add_action( 'wp_ajax_nopriv_' . $action, array( __CLASS__, substr( $action, 4 ) ) );
		}
	}

	public static function register_assets() {
		wp_register_script( 'evr-staff', EVR_PLUGIN_URL . 'assets/js/staff.js', array(), EVR_VERSION, true );
		wp_register_style( 'evr-staff', EVR_PLUGIN_URL . 'assets/css/staff.css', array(), EVR_VERSION );
	}

	/* ---------------- Access gate ---------------- */

	private static function sign( $payload ) {
		return hash_hmac( 'sha256', $payload, wp_salt( 'nonce' ) );
	}

	/**
	 * Bind the page and the gate it declared into one tamper-proof string, so
	 * the endpoints enforce exactly what the shortcode asked for.
	 */
	private static function make_token( $post_id, $require_login, $capability ) {
		$payload = implode( '|', array( (int) $post_id, $require_login ? '1' : '0', $capability ) );
		return rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' ) . '.' . self::sign( $payload );
	}

	private static function parse_token( $token ) {
		$parts = explode( '.', (string) $token );
		if ( 2 !== count( $parts ) ) {
			return null;
		}
		$payload = base64_decode( strtr( $parts[0], '-_', '+/' ), true );
		if ( ! $payload || ! hash_equals( self::sign( $payload ), $parts[1] ) ) {
			return null;
		}
		$bits = explode( '|', $payload );
		if ( count( $bits ) < 3 ) {
			return null;
		}
		return array(
			'post_id'       => (int) $bits[0],
			'require_login' => '1' === $bits[1],
			'capability'    => $bits[2],
		);
	}

	/**
	 * @return true|string True if allowed; otherwise a reason. The special
	 *                     reason 'unprotected' means the page itself is not
	 *                     gated at all, which is a setup mistake, not a visitor
	 *                     problem.
	 */
	private static function access_check( $post, $require_login, $capability ) {
		if ( ! $post instanceof WP_Post ) {
			return 'This form is not available.';
		}
		if ( $require_login ) {
			if ( ! is_user_logged_in() || ! current_user_can( $capability ) ) {
				return 'You need to be signed in to use this form.';
			}
			return true;
		}
		if ( '' === (string) $post->post_password ) {
			return 'unprotected';
		}
		if ( post_password_required( $post ) ) {
			return 'Enter the page password to use this form.';
		}
		return true;
	}

	/**
	 * Shared front door for both AJAX endpoints: nonce, signed token, gate.
	 * Ends the request on failure.
	 */
	private static function verify_request() {
		if ( ! check_ajax_referer( 'evr_staff', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed. Please reload the page.' ), 403 );
		}
		$token = self::parse_token( wp_unslash( $_POST['token'] ?? '' ) );
		if ( ! $token ) {
			wp_send_json_error( array( 'message' => 'This form is no longer valid. Please reload the page.' ), 403 );
		}
		$check = self::access_check( get_post( $token['post_id'] ), $token['require_login'], $token['capability'] );
		if ( true !== $check ) {
			wp_send_json_error( array( 'message' => 'unprotected' === $check ? 'This form is not available.' : $check ), 403 );
		}
	}

	private static function throttle() {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
		$key = 'evr_staff_rl_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= self::MAX_PER_HOUR ) {
			return false;
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		return true;
	}

	/* ---------------- Render ---------------- */

	public static function render( $atts ) {
		$atts = shortcode_atts( array(
			'require_login' => 'no',
			'capability'    => 'edit_posts',
			'status'        => '',  // Blank = list every event.
			'title'         => 'Add a registration',
		), $atts, self::SHORTCODE );

		$require_login = in_array( strtolower( (string) $atts['require_login'] ), array( 'yes', '1', 'true' ), true );
		$capability    = sanitize_key( $atts['capability'] ) ?: 'edit_posts';
		$post          = get_post();

		$check = self::access_check( $post, $require_login, $capability );

		if ( 'unprotected' === $check ) {
			// Never render an ungated form. Explain the fix to someone who can
			// act on it; show nothing at all to anyone else.
			if ( ! current_user_can( 'manage_options' ) ) {
				return '';
			}
			return '<div class="evr-staff evr-staff-warn"><p><strong>Staff registration form hidden.</strong> '
				. 'This page has no password, so the form would be public. Either set one under '
				. '<em>Page &rarr; Visibility &rarr; Password protected</em>, or restrict the form to signed-in users with '
				. '<code>[event_registration_staff require_login="yes"]</code>.</p></div>';
		}
		if ( true !== $check ) {
			return '<div class="evr-staff"><p>' . esc_html( $check ) . '</p></div>';
		}

		$status = sanitize_key( $atts['status'] );
		$events = array();
		foreach ( EVR_DB::get_events() as $e ) {
			if ( $status && $e['status'] !== $status ) {
				continue;
			}
			$events[] = array(
				'id'     => (int) $e['id'],
				'title'  => $e['title'],
				'status' => $e['status'],
			);
		}

		$js = array(
			'ajax_url'       => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( 'evr_staff' ),
			'token'          => self::make_token( $post->ID, $require_login, $capability ),
			'events'         => $events,
			'payment_states' => EVR_Manual::payment_states(),
		);

		wp_enqueue_script( 'evr-staff' );
		wp_enqueue_style( 'evr-staff' );

		ob_start();
		?>
		<div class="evr-staff" data-evr-staff="<?php echo esc_attr( wp_json_encode( $js ) ); ?>">
			<h3 class="evr-staff-title"><?php echo esc_html( $atts['title'] ); ?></h3>
			<p class="evr-staff-intro">Adds someone to an event without taking payment — for registrants you invoice separately, comp, or who paid another way. They appear on the roster and in the CSV straight away, and sync to GoHighLevel like anyone else.</p>
			<?php if ( ! $events ) : ?>
				<p class="evr-staff-empty">No events have been created yet.</p>
			<?php else : ?>
				<noscript><p>JavaScript is required to use this form.</p></noscript>
				<form class="evr-staff-form" novalidate>
					<label class="evr-staff-field">
						<span>Event</span>
						<select name="event_id" class="evr-staff-event" required>
							<option value="">— choose an event —</option>
							<?php foreach ( $events as $e ) : ?>
								<option value="<?php echo (int) $e['id']; ?>"><?php echo esc_html( $e['title'] . ( 'active' === $e['status'] ? '' : ' (' . $e['status'] . ')' ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>

					<div class="evr-staff-loading" hidden>Loading event…</div>
					<div class="evr-staff-details" hidden></div>

					<fieldset class="evr-staff-group evr-staff-person" hidden>
						<legend>Registrant</legend>
						<div class="evr-staff-cols">
							<label class="evr-staff-field"><span>First name</span><input type="text" name="first_name" required></label>
							<label class="evr-staff-field"><span>Last name</span><input type="text" name="last_name" required></label>
						</div>
						<div class="evr-staff-cols">
							<label class="evr-staff-field"><span>Email</span><input type="email" name="email" required></label>
							<label class="evr-staff-field"><span>Phone</span><input type="text" name="phone"></label>
						</div>
						<p class="evr-staff-hint">Their membership tier is looked up automatically from their email, so a member's discount is priced in.</p>
					</fieldset>

					<div class="evr-staff-custom" hidden></div>

					<fieldset class="evr-staff-group evr-staff-payment" hidden>
						<legend>Payment</legend>
						<?php foreach ( EVR_Manual::payment_states() as $val => $label ) : ?>
							<label class="evr-staff-radio"><input type="radio" name="payment_state" value="<?php echo esc_attr( $val ); ?>" <?php checked( 'invoiced', $val ); ?>> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
						<div class="evr-staff-estimate" hidden></div>
					</fieldset>

					<fieldset class="evr-staff-group evr-staff-meta" hidden>
						<legend>For your records</legend>
						<div class="evr-staff-cols">
							<label class="evr-staff-field"><span>Your name</span><input type="text" name="added_by" required></label>
							<label class="evr-staff-field"><span>Note</span><input type="text" name="note" placeholder="e.g. Invoice #1042"><small>Internal only — never shown to the registrant.</small></label>
						</div>
					</fieldset>

					<div class="evr-staff-actions" hidden>
						<button type="submit" class="evr-staff-submit">Add registration</button>
					</div>
					<div class="evr-staff-message" role="status"></div>
				</form>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ---------------- AJAX ---------------- */

	/**
	 * The chosen event's tickets, add-ons and custom fields, so the form can
	 * build the rest of itself. Prices here are list prices for the on-screen
	 * estimate only — the real amount is computed server-side on submit.
	 */
	public static function staff_config() {
		self::verify_request();

		$event = EVR_DB::get_event( absint( $_POST['event_id'] ?? 0 ) );
		if ( ! $event ) {
			wp_send_json_error( array( 'message' => 'Event not found.' ), 404 );
		}
		$config = $event['config'];

		$tickets = array();
		foreach ( (array) $config['tickets'] as $t ) {
			$sold_out = ! empty( $t['capacity'] ) && EVR_DB::ticket_seats_taken( $event['id'], $t['key'] ) >= (int) $t['capacity'];
			$tickets[] = array(
				'key'                 => $t['key'],
				'label'               => $t['label'],
				'type'                => ( 'addon' === ( $t['type'] ?? 'main' ) ) ? 'addon' : 'main',
				'current_price_cents' => (int) EVR_Pricing::current_ticket_price( $event, $t ),
				'sold_out'            => $sold_out,
				'inactive'            => empty( $t['active'] ),
			);
		}

		$fields = array();
		foreach ( (array) $config['fields'] as $f ) {
			$fields[] = array(
				'key'     => $f['key'],
				'label'   => $f['label'],
				'type'    => $f['type'],
				'options' => array_values( array_filter( array_map( 'trim', explode( ',', (string) $f['options'] ) ) ) ),
			);
		}

		wp_send_json_success( array(
			'title'     => $event['title'],
			'status'    => $event['status'],
			'currency'  => strtoupper( $config['currency'] ?: 'usd' ),
			'tickets'   => $tickets,
			'fields'    => $fields,
			'test_mode' => 'live' !== EVR_Settings::event_mode( $event ),
		) );
	}

	/**
	 * Create the registration. All validation and pricing happens in
	 * EVR_Manual::create() — the same code the admin screen and the REST
	 * endpoint use. The form chooses only the event, the registrant and how
	 * the money is being handled; everything else follows the event's own
	 * rules, exactly as a public registration would.
	 */
	public static function staff_register() {
		self::verify_request();

		if ( ! self::throttle() ) {
			wp_send_json_error( array( 'message' => 'Too many registrations added from here in the last hour. Please try again shortly.' ), 429 );
		}

		$fields = array();
		foreach ( (array) ( $_POST['fields'] ?? array() ) as $key => $value ) {
			$fields[ sanitize_key( $key ) ] = wp_unslash( $value );
		}

		$result = EVR_Manual::create( array(
			'event_id'        => absint( $_POST['event_id'] ?? 0 ),
			'email'           => wp_unslash( $_POST['email'] ?? '' ),
			'first_name'      => wp_unslash( $_POST['first_name'] ?? '' ),
			'last_name'       => wp_unslash( $_POST['last_name'] ?? '' ),
			'phone'           => wp_unslash( $_POST['phone'] ?? '' ),
			'ticket_key'      => wp_unslash( $_POST['ticket_key'] ?? '' ),
			'addon_keys'      => (array) ( $_POST['addon_keys'] ?? array() ),
			'payment_state'   => wp_unslash( $_POST['payment_state'] ?? 'invoiced' ),
			'note'            => wp_unslash( $_POST['note'] ?? '' ),
			'added_by'        => wp_unslash( $_POST['added_by'] ?? '' ),
			'fields'          => $fields,
			// Deliberately not settable from this form: it follows the same
			// flow as a public registration — priced from the ticket, always
			// synced to GHL, duplicates governed by the event's own setting.
			'amount_cents'    => null,
			'sync_ghl'        => true,
			'force_duplicate' => false,
			'source'          => 'staff',
		) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$reg   = EVR_DB::get_registration( $result );
		$event = EVR_DB::get_event( (int) $reg['event_id'] );

		wp_send_json_success( array(
			'registration_id' => (int) $result,
			'name'            => trim( $reg['first_name'] . ' ' . $reg['last_name'] ),
			'event'           => $event ? $event['title'] : '',
			'amount'          => strtoupper( $reg['currency'] ) . ' ' . number_format( (int) $reg['amount_cents'] / 100, 2 ),
			'payment_state'   => EVR_Manual::payment_state_label( $reg['payment_state'] ),
			'tier'            => $reg['tier'] ? ucfirst( $reg['tier'] ) : '',
			// Surfaced so a GHL failure is visible to whoever added the person,
			// rather than only in the admin list.
			'ghl'             => $reg['ghl_sync_status'],
			'ghl_error'       => (string) $reg['ghl_sync_error'],
		) );
	}
}
