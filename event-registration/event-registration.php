<?php
/**
 * Plugin Name: Event Registration
 * Description: Event registration with embedded Stripe checkout, Supabase membership-tier discounts, and GoHighLevel pipeline sync.
 * Version: 1.21.1
 * Author: AIME Group
 * License: GPL-2.0+
 * Text Domain: event-registration
 * Update URI: false
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EVR_VERSION', '1.21.1' );
define( 'EVR_PLUGIN_FILE', __FILE__ );
define( 'EVR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EVR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once EVR_PLUGIN_DIR . 'includes/class-evr-db.php';
require_once EVR_PLUGIN_DIR . 'includes/class-evr-settings.php';
require_once EVR_PLUGIN_DIR . 'includes/class-evr-stripe.php';
require_once EVR_PLUGIN_DIR . 'includes/class-evr-supabase.php';
require_once EVR_PLUGIN_DIR . 'includes/class-evr-ghl.php';
require_once EVR_PLUGIN_DIR . 'includes/class-evr-pricing.php';
require_once EVR_PLUGIN_DIR . 'includes/class-evr-installments.php';
require_once EVR_PLUGIN_DIR . 'includes/class-evr-manual.php';
require_once EVR_PLUGIN_DIR . 'includes/class-evr-ajax.php';
require_once EVR_PLUGIN_DIR . 'includes/class-evr-webhook.php';
require_once EVR_PLUGIN_DIR . 'includes/class-evr-admin.php';
require_once EVR_PLUGIN_DIR . 'includes/class-evr-shortcode.php';
require_once EVR_PLUGIN_DIR . 'includes/class-evr-staff.php';

register_activation_hook( __FILE__, array( 'EVR_DB', 'install' ) );

// Hourly sweep that pushes abandoned carts (unpaid 24h+) to GHL, plus a daily
// sweep that charges due payment-plan installments off-session.
register_activation_hook( __FILE__, function () {
	if ( ! wp_next_scheduled( 'evr_abandoned_cart_sweep' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'evr_abandoned_cart_sweep' );
	}
	if ( ! wp_next_scheduled( 'evr_installment_sweep' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'evr_installment_sweep' );
	}
} );
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'evr_abandoned_cart_sweep' );
	wp_clear_scheduled_hook( 'evr_installment_sweep' );
} );
add_action( 'evr_abandoned_cart_sweep', array( 'EVR_GHL', 'sweep_abandoned_carts' ) );
add_action( 'evr_installment_sweep', array( 'EVR_Installments', 'charge_due_installments' ) );

// When a payment plan is fully paid, re-sync GHL so the opportunity moves from
// the Outstanding-payment stage to the Fully-paid stage.
add_action( 'evr_plan_completed', array( 'EVR_GHL', 'sync_registration' ) );

add_action( 'plugins_loaded', function () {
	EVR_DB::maybe_upgrade();
	EVR_Settings::init();
	EVR_Ajax::init();
	EVR_Webhook::init();
	EVR_Manual::init();
	EVR_Admin::init();
	EVR_Shortcode::init();
	EVR_Staff::init();

	// Self-heal the schedules for sites updated in place (no reactivation).
	if ( ! wp_next_scheduled( 'evr_abandoned_cart_sweep' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'evr_abandoned_cart_sweep' );
	}
	if ( ! wp_next_scheduled( 'evr_installment_sweep' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'evr_installment_sweep' );
	}
} );
