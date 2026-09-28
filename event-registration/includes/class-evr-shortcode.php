<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Front-end registration form. Usage: [event_registration id="123"] (alias: [evr_event])
 *
 * Renders a minimal shell; checkout.js builds the interactive parts
 * (membership lookup, ticket/add-on selection, price summary, embedded
 * Stripe Payment Element) from the localized event config.
 */
class EVR_Shortcode {

	public static function init() {
		add_shortcode( 'event_registration', array( __CLASS__, 'render' ) );
		add_shortcode( 'evr_event', array( __CLASS__, 'render' ) ); // Back-compat alias.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	public static function register_assets() {
		wp_register_script( 'stripe-js', 'https://js.stripe.com/v3/', array(), null, true );
		wp_register_script( 'evr-checkout', EVR_PLUGIN_URL . 'assets/js/checkout.js', array( 'stripe-js' ), EVR_VERSION, true );
		wp_register_style( 'evr-checkout', EVR_PLUGIN_URL . 'assets/css/checkout.css', array(), EVR_VERSION );
	}

	public static function render( $atts ) {
		$atts  = shortcode_atts( array( 'id' => 0 ), $atts );
		$event = EVR_DB::get_event( absint( $atts['id'] ) );
		if ( ! $event ) {
			return '<p>Event not found.</p>';
		}

		// Waitlist mode takes precedence over the closed message when enabled.
		$waitlist_state = EVR_Pricing::waitlist_state( $event );
		if ( ! $waitlist_state ) {
			$window = EVR_Pricing::check_window( $event );
			if ( is_wp_error( $window ) ) {
				return '<div class="evr-form evr-closed"><p>' . esc_html( $window->get_error_message() ) . '</p></div>';
			}
		}

		$config = $event['config'];
		$mode   = EVR_Settings::event_mode( $event );
		$now    = current_time( 'timestamp' );

		// Is an early-bird (pricing period) currently running for any main
		// ticket? Used to tailor the "become a member" prompt — members aren't
		// discounted further during early-bird, so we only mention savings after.
		$early_bird_active = false;
		foreach ( (array) $config['tickets'] as $t ) {
			if ( 'main' !== ( $t['type'] ?? 'main' ) || empty( $t['active'] ) ) {
				continue;
			}
			if ( EVR_Pricing::active_period( $event, $t['key'] ) ) {
				$early_bird_active = true;
				break;
			}
		}

		// Public-safe subset of the config for the browser.
		$tickets = array();
		foreach ( (array) $config['tickets'] as $t ) {
			if ( empty( $t['active'] ) ) {
				continue;
			}
			$sold_out = ! empty( $t['capacity'] ) && EVR_DB::ticket_seats_taken( $event['id'], $t['key'] ) >= (int) $t['capacity'];
			$tickets[] = array(
				'key'                 => $t['key'],
				'label'               => $t['label'],
				'type'                => $t['type'],
				'price_cents'         => (int) $t['price_cents'],
				'current_price_cents' => EVR_Pricing::current_ticket_price( $event, $t ),
				'sold_out'            => $sold_out,
			);
		}

		$fields = array();
		foreach ( (array) $config['fields'] as $f ) {
			$fields[] = array(
				'key'         => $f['key'],
				'label'       => $f['label'],
				'type'        => $f['type'],
				'placeholder' => (string) ( $f['placeholder'] ?? '' ),
				'options'     => array_filter( array_map( 'trim', explode( ',', (string) $f['options'] ) ) ),
				'required'    => ! empty( $f['required'] ),
				'cond_logic'  => ( 'or' === ( $f['cond_logic'] ?? '' ) ) ? 'or' : 'and',
				'conditions'  => array_values( (array) ( $f['conditions'] ?? array() ) ),
			);
		}

		$js_config = array(
			'ajax_url'        => admin_url( 'admin-ajax.php' ),
			'nonce'           => wp_create_nonce( 'evr_public' ),
			'event_id'        => (int) $event['id'],
			'publishable_key' => EVR_Settings::stripe_publishable_key( $mode ),
			'currency'        => $config['currency'] ?: 'usd',
			'tickets'         => $tickets,
			'fields'          => $fields,
			'placeholders'    => is_array( $config['placeholders'] ?? null ) ? $config['placeholders'] : array(),
			'early_bird_active' => $early_bird_active,
			'member_signup_url' => (string) ( $config['member_signup_url'] ?? '' ),
			'collect_phone'   => ! empty( $config['collect_phone'] ),
			'consent_text'    => (string) ( $config['consent_text'] ?? '' ),
			'email_note'      => (string) ( $config['email_note'] ?? '' ),
			'has_promos'      => ! empty( $config['promo_codes'] ),
			'payment_plan'    => ( ! $waitlist_state && ! empty( $config['payment_plan']['enabled'] ) ) ? array(
				'enabled'         => true,
				'count'           => max( 2, (int) $config['payment_plan']['count'] ),
				'interval_unit'   => 'day' === ( $config['payment_plan']['interval_unit'] ?? 'month' ) ? 'day' : 'month',
				'interval_count'  => max( 1, (int) ( $config['payment_plan']['interval_count'] ?? 1 ) ),
				'min_total_cents' => (int) ( $config['payment_plan']['min_total_cents'] ?? 0 ),
				'final_due'       => (string) ( $config['payment_plan']['final_due'] ?? '' ),
				'label'           => (string) ( $config['payment_plan']['label'] ?? '' ),
			) : array( 'enabled' => false ),
			'success_message' => $config['success_message'],
			'test_mode'       => 'live' !== $mode,
			'waitlist'        => $waitlist_state ? array(
				'active'  => true,
				'notice'  => 'capacity' === $waitlist_state
					? 'This event is currently full — join the waitlist and we\'ll let you know if a spot opens up.'
					: 'Registration isn\'t open yet — join the waitlist and we\'ll save your place in line.',
				'message' => $config['waitlist']['message'],
			) : false,
		);

		wp_enqueue_script( 'evr-checkout' );
		wp_enqueue_style( 'evr-checkout' );

		// Resolve appearance (per-event override over global) into scoped CSS variables.
		$appearance_css = '';
		foreach ( EVR_Settings::appearance_for_event( $event ) as $prop => $val ) {
			if ( '' !== $val ) {
				$appearance_css .= $prop . ':' . $val . ';';
			}
		}

		ob_start();
		?>
		<?php if ( '' !== $appearance_css ) : ?>
			<style id="evr-appearance-<?php echo (int) $event['id']; ?>">#evr-form-<?php echo (int) $event['id']; ?>{<?php echo $appearance_css; // phpcs:ignore WordPress.Security.EscapeOutput — values sanitized in EVR_Settings ?>}</style>
		<?php endif; ?>
		<div class="evr-form" id="evr-form-<?php echo (int) $event['id']; ?>" data-evr-config="<?php echo esc_attr( wp_json_encode( $js_config ) ); ?>">
			<?php if ( 'live' !== $mode ) : ?>
				<div class="evr-test-banner">Test mode — no real charges will be made.</div>
			<?php endif; ?>
			<noscript><p>JavaScript is required to register for this event.</p></noscript>
			<div class="evr-loading">Loading registration form…</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
