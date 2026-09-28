<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Admin UI: events list, event editor, registrations list, settings.
 */
class EVR_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_evr_save_event', array( __CLASS__, 'save_event' ) );
		add_action( 'admin_post_evr_delete_event', array( __CLASS__, 'delete_event' ) );
		add_action( 'admin_post_evr_delete_registration', array( __CLASS__, 'delete_registration' ) );
		add_action( 'admin_post_evr_add_registration', array( __CLASS__, 'add_registration' ) );
		add_action( 'admin_post_evr_save_settings', array( __CLASS__, 'save_settings' ) );
	}

	public static function menu() {
		add_menu_page( 'Event Registration', 'Event Registration', 'manage_options', 'evr-events', array( __CLASS__, 'render_events' ), 'dashicons-tickets-alt', 26 );
		add_submenu_page( 'evr-events', 'Events', 'Events', 'manage_options', 'evr-events', array( __CLASS__, 'render_events' ) );
		add_submenu_page( 'evr-events', 'Add Event', 'Add Event', 'manage_options', 'evr-event-edit', array( __CLASS__, 'render_event_edit' ) );
		add_submenu_page( 'evr-events', 'Registrations', 'Registrations', 'manage_options', 'evr-registrations', array( __CLASS__, 'render_registrations' ) );
		add_submenu_page( 'evr-events', 'Settings', 'Settings', 'manage_options', 'evr-settings', array( __CLASS__, 'render_settings' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( $hook, 'evr-' ) ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style( 'evr-admin', EVR_PLUGIN_URL . 'assets/css/admin.css', array(), EVR_VERSION );
		wp_enqueue_script( 'evr-admin', EVR_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery', 'wp-color-picker', 'jquery-ui-sortable' ), EVR_VERSION, true );
		wp_localize_script( 'evr-admin', 'EVR_ADMIN', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'evr_admin' ),
			'mode'     => EVR_Settings::stripe_mode(),
		) );
	}

	private static function mode_badge() {
		$live = EVR_Settings::is_live();
		printf(
			'<span class="evr-mode-badge %s">Stripe: %s mode</span>',
			$live ? 'evr-live' : 'evr-test',
			$live ? 'LIVE' : 'TEST'
		);
	}

	/* ---------------- Events list ---------------- */

	public static function render_events() {
		$events = EVR_DB::get_events();
		?>
		<div class="wrap evr-wrap">
			<h1>Events <?php self::mode_badge(); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=evr-event-edit' ) ); ?>" class="page-title-action">Add New</a>
			</h1>
			<table class="widefat striped">
				<thead><tr><th>ID</th><th>Title</th><th>Status</th><th>Shortcode</th><th>Registrations</th><th></th></tr></thead>
				<tbody>
				<?php if ( ! $events ) : ?>
					<tr><td colspan="6">No events yet. Click "Add New" to create one.</td></tr>
				<?php endif; ?>
				<?php foreach ( $events as $e ) :
					$confirmed = count( EVR_DB::get_registrations( $e['id'], 'confirmed' ) );
					$delete_url = wp_nonce_url( admin_url( 'admin-post.php?action=evr_delete_event&event_id=' . $e['id'] ), 'evr_admin' );
					?>
					<tr>
						<td><?php echo (int) $e['id']; ?></td>
						<td><strong><a href="<?php echo esc_url( admin_url( 'admin.php?page=evr-event-edit&event_id=' . $e['id'] ) ); ?>"><?php echo esc_html( $e['title'] ); ?></a></strong></td>
						<td><?php echo esc_html( ucfirst( $e['status'] ) ); ?></td>
						<td><code>[event_registration id="<?php echo (int) $e['id']; ?>"]</code></td>
						<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=evr-registrations&event_id=' . $e['id'] ) ); ?>"><?php echo (int) $confirmed; ?> confirmed</a></td>
						<td><a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('Delete this event? Registrations are kept but orphaned.');">Delete</a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* ---------------- Event editor ---------------- */

	public static function render_event_edit() {
		$event_id = absint( $_GET['event_id'] ?? 0 );
		$event    = $event_id ? EVR_DB::get_event( $event_id ) : null;
		$title    = $event ? $event['title'] : '';
		$status   = $event ? $event['status'] : 'draft';
		$config   = $event ? $event['config'] : EVR_DB::default_config();
		?>
		<div class="wrap evr-wrap">
			<h1><?php echo $event ? 'Edit Event' : 'Add Event'; ?> <?php self::mode_badge(); ?></h1>
			<?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success"><p>Event saved.</p></div><?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="evr-event-form">
				<?php wp_nonce_field( 'evr_admin' ); ?>
				<input type="hidden" name="action" value="evr_save_event">
				<input type="hidden" name="event_id" value="<?php echo (int) $event_id; ?>">
				<input type="hidden" name="config_json" id="evr-config-json">
				<script>window.EVR_EVENT_CONFIG = <?php echo wp_json_encode( $config ); ?>;</script>

				<table class="form-table">
					<tr><th><label for="evr-title">Event Title</label></th>
						<td><input type="text" class="regular-text" id="evr-title" name="title" value="<?php echo esc_attr( $title ); ?>" required></td></tr>
					<tr><th>Status</th>
						<td><select name="status">
							<?php foreach ( array( 'draft' => 'Draft', 'active' => 'Active (accepting registrations)', 'closed' => 'Closed' ) as $val => $label ) : ?>
								<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $status, $val ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select></td></tr>
					<tr><th>Stripe account</th>
						<td><select id="evr-stripe-mode">
							<option value="">Use global setting (currently <?php echo esc_html( strtoupper( EVR_Settings::stripe_mode() ) ); ?>)</option>
							<option value="test" <?php selected( $config['stripe_mode'] ?? '', 'test' ); ?>>Test (sandbox)</option>
							<option value="live" <?php selected( $config['stripe_mode'] ?? '', 'live' ); ?>>Live</option>
						</select>
						<p class="description">This event's checkout and payments use this mode — other events are unaffected. Tickets store both their test and live products, so switching needs no re-picking.</p></td></tr>
					<tr><th>Registration window</th>
						<td>
							Opens <input type="datetime-local" id="evr-reg-open" value="<?php echo esc_attr( $config['reg_open'] ); ?>">
							&nbsp;Closes <input type="datetime-local" id="evr-reg-close" value="<?php echo esc_attr( $config['reg_close'] ); ?>">
							<p class="description">Leave blank for always open while the event is Active.</p>
						</td></tr>
					<tr><th>Options</th>
						<td>
							<label><input type="checkbox" id="evr-allow-duplicates" <?php checked( ! empty( $config['allow_duplicates'] ) ); ?>> Allow the same email to register more than once</label><br>
							<label><input type="checkbox" id="evr-collect-phone" <?php checked( ! empty( $config['collect_phone'] ) ); ?>> Collect phone number</label>
						</td></tr>
					<tr><th><label for="evr-success-message">Success message</label></th>
						<td><textarea id="evr-success-message" class="large-text" rows="2"><?php echo esc_textarea( $config['success_message'] ); ?></textarea></td></tr>
					<tr><th><label for="evr-email-note">Email field note</label></th>
						<td><input type="text" class="large-text" id="evr-email-note" value="<?php echo esc_attr( $config['email_note'] ?? '' ); ?>">
							<p class="description">Shown in bold red under the email field. Leave blank to hide.</p></td></tr>
					<tr><th><label for="evr-member-url">Membership sign-up link</label></th>
						<td><input type="url" class="large-text" id="evr-member-url" value="<?php echo esc_attr( $config['member_signup_url'] ?? '' ); ?>" placeholder="https://app.brokersarebest.com/sign-up">
							<p class="description">Shown under the email field when the registrant is <strong>not</strong> a member, inviting them to join. Leave blank to hide the invite.</p></td></tr>
					<tr><th><label for="evr-consent-text">Consent / disclaimer text</label></th>
						<td><textarea id="evr-consent-text" class="large-text" rows="3"><?php echo esc_textarea( $config['consent_text'] ?? '' ); ?></textarea>
							<p class="description">Shown as a required checkbox at the bottom of the form. Leave blank to hide the checkbox entirely.</p></td></tr>
				</table>

				<?php $ph = is_array( $config['placeholders'] ?? null ) ? $config['placeholders'] : array(); ?>
				<h2>Built-in Field Placeholders</h2>
				<p class="description">Placeholder text shown (greyed out) inside the standard fields. Leave a box blank for no placeholder.</p>
				<table class="form-table">
					<tr><th><label for="evr-ph-first">First name</label></th><td><input type="text" class="regular-text" id="evr-ph-first" value="<?php echo esc_attr( $ph['first_name'] ?? '' ); ?>"></td></tr>
					<tr><th><label for="evr-ph-last">Last name</label></th><td><input type="text" class="regular-text" id="evr-ph-last" value="<?php echo esc_attr( $ph['last_name'] ?? '' ); ?>"></td></tr>
					<tr><th><label for="evr-ph-phone">Phone</label></th><td><input type="text" class="regular-text" id="evr-ph-phone" value="<?php echo esc_attr( $ph['phone'] ?? '' ); ?>"></td></tr>
					<tr><th><label for="evr-ph-email">Email</label></th><td><input type="text" class="regular-text" id="evr-ph-email" value="<?php echo esc_attr( $ph['email'] ?? '' ); ?>"></td></tr>
				</table>

				<h2>Tickets &amp; Add-ons</h2>
				<p class="description">Each ticket stores its product from BOTH Stripe catalogs, so switching this event between test and live needs no re-picking — the matching product is used automatically. A simple registration fee = one Main ticket; ticket choices and add-ons only appear on the form when you add them here. Prices are in dollars.</p>
			<p><button type="button" class="button" id="evr-load-products">Load Stripe products (test + live)</button> <span id="evr-products-status"></span></p>
				<table class="widefat" id="evr-tickets-table">
					<thead><tr><th>Type</th><th>Label</th><th>Stripe product (test)</th><th>Stripe product (live)</th><th>Regular price</th><th>Capacity</th><th>Active</th><th></th></tr></thead>
					<tbody></tbody>
				</table>
				<p><button type="button" class="button" data-evr-add="ticket">+ Main ticket</button>
					<button type="button" class="button" data-evr-add="addon">+ Add-on</button></p>

				<h2>Pricing Periods</h2>
				<p class="description">Optional time-based prices, e.g. two early-bird windows: Early Bird 1 applies until its end date, Early Bird 2 takes over until its end date, then the regular price kicks in. Membership discounts are always calculated off the <strong>regular</strong> price, and the registrant gets whichever saving is larger — period price or member discount — never both.</p>
				<table class="widefat" id="evr-periods-table">
					<thead><tr><th>Ticket</th><th>Period label</th><th>Price</th><th>Ends</th><th>Total label <small>(optional)</small></th><th></th></tr></thead>
					<tbody></tbody>
				</table>
				<p class="description">"Total label" replaces the word <strong>Total</strong> in the order summary while this period is active (e.g. "Total Early-Bird Price"). It reverts to "Total" automatically once the period ends. Leave blank to always show "Total".</p>
				<p><button type="button" class="button" data-evr-add="period">+ Pricing period</button></p>
				<p><label><input type="checkbox" id="evr-eb-override"> <strong>While an early-bird pricing period is active, ignore membership tier discounts</strong> — the early-bird price wins, even if a member's discount would be larger. Promo codes still apply. (Default off: whichever saving is greater wins.)</label></p>

				<?php $pp = is_array( $config['payment_plan'] ?? null ) ? $config['payment_plan'] : array(); ?>
				<h2>Payment Plan</h2>
				<p class="description">Let registrants split the total into a fixed number of automatic payments. The first payment is taken on the page today and the card is saved; the remaining payments are charged automatically on their due dates. The total is split evenly (any rounding goes onto the first payment). Add-ons and discounts are included in the total that gets split.</p>
				<table class="form-table">
					<tr><th>Enable</th>
						<td><label><input type="checkbox" id="evr-pp-enabled" <?php checked( ! empty( $pp['enabled'] ) ); ?>> Offer a payment plan on this event's form</label>
							<p class="description">When enabled, registrants can choose "pay in full" or the installment plan. If the plan can't finish by the "final payment due by" date below (e.g. the event is too close), the option is hidden automatically and full payment is required.</p></td></tr>
					<tr><th><label for="evr-pp-count">Number of payments</label></th>
						<td><input type="number" min="2" step="1" class="small-text" id="evr-pp-count" value="<?php echo esc_attr( $pp['count'] ?? 2 ); ?>">
							<span class="description">Total payments, including the one taken today (minimum 2).</span></td></tr>
					<tr><th>Payment interval</th>
						<td>Every
							<input type="number" min="1" step="1" class="small-text" id="evr-pp-interval-count" value="<?php echo esc_attr( $pp['interval_count'] ?? 1 ); ?>">
							<select id="evr-pp-interval-unit">
								<option value="month" <?php selected( ( $pp['interval_unit'] ?? 'month' ), 'month' ); ?>>month(s)</option>
								<option value="day" <?php selected( ( $pp['interval_unit'] ?? 'month' ), 'day' ); ?>>day(s)</option>
							</select>
							<p class="description">e.g. "Every 1 month" with 2 payments = one today, one next month.</p></td></tr>
					<tr><th><label for="evr-pp-min-total">Minimum total to offer</label></th>
						<td>$<input type="number" min="0" step="0.01" class="small-text" id="evr-pp-min-total" value="<?php echo esc_attr( isset( $pp['min_total_cents'] ) ? number_format( (int) $pp['min_total_cents'] / 100, 2, '.', '' ) : '' ); ?>">
							<span class="description">Only show the plan when the order total is at least this much. Leave blank/0 to always offer it.</span></td></tr>
					<tr><th><label for="evr-pp-final-due">Final payment due by</label></th>
						<td><input type="datetime-local" id="evr-pp-final-due" value="<?php echo esc_attr( $pp['final_due'] ?? '' ); ?>">
							<p class="description">The last installment must fall on or before this date. If the schedule wouldn't finish in time, the plan option disappears from the form and the registrant must pay in full. Leave blank for no cut-off.</p></td></tr>
					<tr><th><label for="evr-pp-label">Option label</label></th>
						<td><input type="text" class="regular-text" id="evr-pp-label" value="<?php echo esc_attr( $pp['label'] ?? '' ); ?>" placeholder="Split into payments">
							<p class="description">Optional wording shown on the form's plan choice. Leave blank for the default.</p></td></tr>
				</table>

				<h2>Membership Tier Discounts</h2>
				<p class="description">Applied to the main ticket when the registrant's email matches a membership in Supabase. "Processor" variants get the same discount as their base tier.</p>
				<table class="widefat" style="max-width:600px">
					<thead><tr><th>Tier</th><th>Discount type</th><th>Amount</th></tr></thead>
					<tbody id="evr-discounts"></tbody>
				</table>

				<h2>Promo Codes</h2>
				<table class="widefat" id="evr-promos-table">
					<thead><tr><th>Code</th><th>Type</th><th>Amount</th><th>Max uses</th><th>Used</th><th>Expires</th><th></th></tr></thead>
					<tbody></tbody>
				</table>
				<p><button type="button" class="button" data-evr-add="promo">+ Promo code</button></p>

				<h2>GoHighLevel</h2>
				<table class="form-table">
					<tr><th>Location ID</th><td><input type="text" class="regular-text" id="evr-ghl-location"> </td></tr>
					<tr><th>Token override</th><td><input type="password" class="regular-text" id="evr-ghl-token" autocomplete="off">
						<p class="description">Optional. Leave blank to use the global token from Settings. Use a location-level Private Integration token when this event belongs to a different sub-account.</p></td></tr>
					<tr><th>Pipeline / Stage</th>
						<td><button type="button" class="button" id="evr-load-pipelines">Load pipelines</button>
							<select id="evr-ghl-pipeline" style="display:none"></select>
							<select id="evr-ghl-stage" style="display:none"></select>
							<span id="evr-ghl-pipeline-manual">
								Pipeline ID <input type="text" id="evr-ghl-pipeline-id">
								Stage ID <input type="text" id="evr-ghl-stage-id">
							</span>
							<span id="evr-pipelines-status"></span></td></tr>
					<tr><th>Abandoned cart stage</th>
						<td><select id="evr-ghl-abandoned-stage" style="display:none"></select>
							<span id="evr-ghl-abandoned-manual">Stage ID <input type="text" id="evr-ghl-abandoned-stage-id" placeholder="blank = disabled"></span>
							<p class="description">Optional. A registration left unpaid for 24 hours is added to this stage (same pipeline as above) so you can follow up on abandoned carts. When the person completes payment, the opportunity automatically moves to the Pipeline/Stage above. Leave blank to disable.</p></td></tr>
					<tr><th>Payment plan: Outstanding stage</th>
						<td><select id="evr-ghl-outstanding-stage" style="display:none"></select>
							<span id="evr-ghl-outstanding-manual">Stage ID <input type="text" id="evr-ghl-outstanding-stage-id" placeholder="blank = use registered stage"></span>
							<p class="description">Optional. Registrants who choose the payment plan are placed in this stage (same pipeline) while installments are still outstanding, instead of the registered stage above. Leave blank to use the registered stage.</p></td></tr>
					<tr><th>Payment plan: Fully paid stage</th>
						<td><select id="evr-ghl-fullypaid-stage" style="display:none"></select>
							<span id="evr-ghl-fullypaid-manual">Stage ID <input type="text" id="evr-ghl-fullypaid-stage-id" placeholder="blank = disabled"></span>
							<p class="description">Optional. When the final installment is collected, the opportunity moves automatically from the Outstanding stage to this one. Leave blank to leave it in the Outstanding stage.</p></td></tr>
					<tr><th>Contact tags</th><td><input type="text" class="regular-text" id="evr-ghl-tags" placeholder="event-2026, attendee">
						<p class="description">Comma-separated tags added to the GHL contact. Existing tags on the contact are kept.</p></td></tr>
				</table>

				<h2>Waitlist</h2>
				<p class="description">When active, the same form collects waitlist signups (no payment) instead of registrations.</p>
				<table class="form-table">
					<tr><th>Enable waitlist</th>
						<td>
							<label><input type="checkbox" id="evr-wl-manual"> <strong>Waitlist on now</strong> (manual toggle — overrides dates and capacity until unchecked)</label><br>
							<label><input type="checkbox" id="evr-wl-before"> Automatically before registration opens (requires a registration "Opens" date above)</label><br>
							<label><input type="checkbox" id="evr-wl-capacity"> Automatically when all main tickets reach capacity</label>
						</td></tr>
					<tr><th>Waitlist pipeline / stage</th>
						<td>
							<select id="evr-wl-pipeline" style="display:none"></select>
							<select id="evr-wl-stage" style="display:none"></select>
							<span id="evr-wl-manual">
								Pipeline ID <input type="text" id="evr-wl-pipeline-id" placeholder="blank = same pipeline">
								Stage ID <input type="text" id="evr-wl-stage-id">
							</span>
							<p class="description">Waitlist signups are created as opportunities in this stage — usually a different stage of the same pipeline. Leave pipeline blank to use the event's main pipeline, or pick another. "Load pipelines" above fills these dropdowns too.</p>
						</td></tr>
					<tr><th>Waitlist message</th>
						<td><textarea id="evr-wl-message" class="large-text" rows="2"></textarea>
							<p class="description">Shown after someone joins the waitlist.</p></td></tr>
				</table>

				<h2>Custom Form Fields</h2>
				<p class="description">Extra questions on the registration form, mapped to GHL fields. Standard contact fields (company name, address, etc.) and opportunity source are always available in the picker. Click "Load GHL fields" (after entering the Location ID above) to also pull this location's contact <em>and</em> opportunity custom fields, or choose "Enter field ID manually" (prefix opportunity custom field IDs with <code>cf_opportunity:</code>).</p>
				<p><button type="button" class="button" id="evr-load-ghl-fields">Load GHL fields</button> <span id="evr-ghl-fields-status"></span></p>
				<p class="description">Drag the <span class="dashicons dashicons-menu" style="vertical-align:middle"></span> handle to reorder fields — the order here is the order they appear on the registration form.</p>
				<p class="description"><strong>Show when</strong> lets a field appear only when other fields have certain values — e.g. show "Which session?" only when "Attending? = Yes". Controlling fields must be a <em>Dropdown</em> or <em>Checkbox</em> (for a checkbox use the value <code>Yes</code>, or the "is not empty / checked" operator). Hidden fields are never required and aren't submitted.</p>
				<table class="widefat" id="evr-fields-table">
					<thead><tr><th style="width:24px"></th><th>Label</th><th>Type</th><th>Placeholder</th><th>Options <small>(comma-sep, for select)</small></th><th>Required</th><th>GHL field</th><th>Show when</th><th></th></tr></thead>
					<tbody></tbody>
				</table>
				<p><button type="button" class="button" data-evr-add="field">+ Form field</button></p>

				<h2>UTM Tracking</h2>
				<p class="description">UTM parameters on the visitor's link (e.g. <code>?utm_source=jane</code>) are captured automatically at signup and saved with the registration. Map each parameter to a GHL field to see whose link drove the signup. Uses the same picker as above — "Load GHL fields" fills the custom field lists.</p>
				<table class="widefat" id="evr-utm-table" style="max-width:700px">
					<thead><tr><th>URL parameter</th><th>GHL field</th></tr></thead>
					<tbody></tbody>
				</table>

				<?php $ap = is_array( $config['appearance'] ?? null ) ? $config['appearance'] : array(); ?>
				<h2>Appearance (this event)</h2>
				<p class="description">Optional. Override the global form styling (<strong>Settings → Appearance</strong>) for this event only. Leave a field blank to inherit the global value.</p>
				<table class="form-table">
					<tr><th><label for="evr-ap-font">Font</label></th>
						<td><select id="evr-ap-font">
							<option value="">— use global —</option>
							<?php foreach ( EVR_Settings::font_stacks() as $val => $f ) :
								if ( '' === $val || 'custom' === $val ) { continue; } ?>
								<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $ap['font'] ?? '', $val ); ?>><?php echo esc_html( $f['label'] ); ?></option>
							<?php endforeach; ?>
						</select></td></tr>
					<tr><th>Button color</th><td><input type="text" class="evr-color" id="evr-ap-button-bg" value="<?php echo esc_attr( $ap['button_bg'] ?? '' ); ?>"></td></tr>
					<tr><th>Button text color</th><td><input type="text" class="evr-color" id="evr-ap-button-text" value="<?php echo esc_attr( $ap['button_text'] ?? '' ); ?>"></td></tr>
					<tr><th>Body text color</th><td><input type="text" class="evr-color" id="evr-ap-text" value="<?php echo esc_attr( $ap['text'] ?? '' ); ?>"></td></tr>
					<tr><th>Input border color</th><td><input type="text" class="evr-color" id="evr-ap-border" value="<?php echo esc_attr( $ap['border'] ?? '' ); ?>"></td></tr>
					<tr><th>Accent color</th><td><input type="text" class="evr-color" id="evr-ap-accent" value="<?php echo esc_attr( $ap['accent'] ?? '' ); ?>"></td></tr>
				</table>

				<?php submit_button( 'Save Event' ); ?>
			</form>
		</div>
		<?php
	}

	public static function save_event() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'evr_admin' ) ) {
			wp_die( 'Not allowed.' );
		}
		$event_id = absint( $_POST['event_id'] ?? 0 );
		$title    = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		$status   = in_array( $_POST['status'] ?? '', array( 'draft', 'active', 'closed' ), true ) ? $_POST['status'] : 'draft';
		$raw      = json_decode( wp_unslash( $_POST['config_json'] ?? '' ), true );
		$config   = self::sanitize_config( is_array( $raw ) ? $raw : array() );

		// Preserve promo usage counters from the stored event.
		if ( $event_id ) {
			$existing = EVR_DB::get_event( $event_id );
			if ( $existing ) {
				$used = array();
				foreach ( (array) $existing['config']['promo_codes'] as $p ) {
					$used[ strtoupper( $p['code'] ) ] = (int) ( $p['used'] ?? 0 );
				}
				foreach ( $config['promo_codes'] as &$p ) {
					$p['used'] = $used[ strtoupper( $p['code'] ) ] ?? 0;
				}
				unset( $p );
			}
		}

		$event_id = EVR_DB::save_event( $event_id, $title, $status, $config );
		wp_safe_redirect( admin_url( 'admin.php?page=evr-event-edit&event_id=' . $event_id . '&saved=1' ) );
		exit;
	}

	private static function sanitize_config( $raw ) {
		$config = EVR_DB::default_config();

		foreach ( array( 'reg_open', 'reg_close', 'early_bird_end', 'success_message', 'currency' ) as $key ) {
			if ( isset( $raw[ $key ] ) ) {
				$config[ $key ] = sanitize_text_field( $raw[ $key ] );
			}
		}
		// May legitimately be blank (hides the consent checkbox / email note).
		$config['consent_text'] = isset( $raw['consent_text'] ) ? sanitize_textarea_field( $raw['consent_text'] ) : '';
		$config['email_note']   = isset( $raw['email_note'] ) ? sanitize_text_field( $raw['email_note'] ) : '';
		$config['member_signup_url'] = isset( $raw['member_signup_url'] ) ? esc_url_raw( trim( $raw['member_signup_url'] ) ) : '';

		// Built-in field placeholders (may be blank for no placeholder).
		$raw_ph = (array) ( $raw['placeholders'] ?? array() );
		$config['placeholders'] = array();
		foreach ( array( 'first_name', 'last_name', 'phone', 'email' ) as $ph_key ) {
			$config['placeholders'][ $ph_key ] = isset( $raw_ph[ $ph_key ] ) ? sanitize_text_field( $raw_ph[ $ph_key ] ) : EVR_DB::default_config()['placeholders'][ $ph_key ];
		}
		$config['stripe_mode']  = in_array( $raw['stripe_mode'] ?? '', array( 'test', 'live' ), true ) ? $raw['stripe_mode'] : '';
		$config['allow_duplicates'] = ! empty( $raw['allow_duplicates'] );
		$config['collect_phone']    = ! empty( $raw['collect_phone'] );
		$config['early_bird_overrides_discounts'] = ! empty( $raw['early_bird_overrides_discounts'] );

		$config['tickets'] = array();
		foreach ( (array) ( $raw['tickets'] ?? array() ) as $t ) {
			if ( empty( $t['label'] ) ) {
				continue;
			}
			$config['tickets'][] = array(
				'key'               => sanitize_key( $t['key'] ?: uniqid( 'tk_' ) ),
				'label'             => sanitize_text_field( $t['label'] ),
				'type'              => 'addon' === ( $t['type'] ?? '' ) ? 'addon' : 'main',
				'stripe_product_id'      => sanitize_text_field( $t['stripe_product_id'] ?? '' ), // Legacy single-catalog field.
				'stripe_product_id_test' => sanitize_text_field( $t['stripe_product_id_test'] ?? '' ),
				'stripe_product_id_live' => sanitize_text_field( $t['stripe_product_id_live'] ?? '' ),
				'price_cents'       => absint( $t['price_cents'] ?? 0 ),
				'early_bird_cents'  => absint( $t['early_bird_cents'] ?? 0 ),
				'capacity'          => absint( $t['capacity'] ?? 0 ),
				'active'            => ! empty( $t['active'] ),
			);
		}

		$config['pricing_periods'] = array();
		foreach ( (array) ( $raw['pricing_periods'] ?? array() ) as $p ) {
			if ( empty( $p['ticket_key'] ) || empty( $p['ends'] ) ) {
				continue;
			}
			$config['pricing_periods'][] = array(
				'ticket_key'  => sanitize_key( $p['ticket_key'] ),
				'label'       => sanitize_text_field( $p['label'] ?? '' ) ?: 'Early bird',
				'price_cents' => absint( $p['price_cents'] ?? 0 ),
				'ends'        => sanitize_text_field( $p['ends'] ),
				'total_label' => sanitize_text_field( $p['total_label'] ?? '' ),
			);
		}

		foreach ( array( 'premium', 'elite', 'vip' ) as $tier ) {
			$d = $raw['discounts'][ $tier ] ?? array();
			$config['discounts'][ $tier ] = array(
				'type'   => 'percent' === ( $d['type'] ?? '' ) ? 'percent' : 'fixed',
				'amount' => max( 0, (float) ( $d['amount'] ?? 0 ) ),
			);
		}

		$config['promo_codes'] = array();
		foreach ( (array) ( $raw['promo_codes'] ?? array() ) as $p ) {
			if ( empty( $p['code'] ) ) {
				continue;
			}
			$config['promo_codes'][] = array(
				'code'     => strtoupper( sanitize_text_field( $p['code'] ) ),
				'type'     => 'percent' === ( $p['type'] ?? '' ) ? 'percent' : 'fixed',
				'amount'   => max( 0, (float) ( $p['amount'] ?? 0 ) ),
				'max_uses' => absint( $p['max_uses'] ?? 0 ),
				'used'     => absint( $p['used'] ?? 0 ),
				'expires'  => sanitize_text_field( $p['expires'] ?? '' ),
			);
		}

		$ghl = (array) ( $raw['ghl'] ?? array() );
		$config['ghl'] = array(
			'location_id'          => sanitize_text_field( $ghl['location_id'] ?? '' ),
			'pipeline_id'          => sanitize_text_field( $ghl['pipeline_id'] ?? '' ),
			'stage_id'             => sanitize_text_field( $ghl['stage_id'] ?? '' ),
			'abandoned_stage_id'   => sanitize_text_field( $ghl['abandoned_stage_id'] ?? '' ),
			'outstanding_stage_id' => sanitize_text_field( $ghl['outstanding_stage_id'] ?? '' ),
			'fully_paid_stage_id'  => sanitize_text_field( $ghl['fully_paid_stage_id'] ?? '' ),
			'token_override'       => sanitize_text_field( $ghl['token_override'] ?? '' ),
			'tags'                 => sanitize_text_field( $ghl['tags'] ?? '' ),
		);

		$wl = (array) ( $raw['waitlist'] ?? array() );
		$config['waitlist'] = array(
			'manual'      => ! empty( $wl['manual'] ),
			'before_open' => ! empty( $wl['before_open'] ),
			'on_capacity' => ! empty( $wl['on_capacity'] ),
			'pipeline_id' => sanitize_text_field( $wl['pipeline_id'] ?? '' ),
			'stage_id'    => sanitize_text_field( $wl['stage_id'] ?? '' ),
			'message'     => sanitize_textarea_field( $wl['message'] ?? '' ) ?: EVR_DB::default_config()['waitlist']['message'],
		);

		$ap = (array) ( $raw['appearance'] ?? array() );
		$config['appearance'] = array(
			'font'        => array_key_exists( $ap['font'] ?? '', EVR_Settings::font_stacks() ) ? $ap['font'] : '',
			'button_bg'   => sanitize_hex_color( $ap['button_bg'] ?? '' ) ?: '',
			'button_text' => sanitize_hex_color( $ap['button_text'] ?? '' ) ?: '',
			'text'        => sanitize_hex_color( $ap['text'] ?? '' ) ?: '',
			'border'      => sanitize_hex_color( $ap['border'] ?? '' ) ?: '',
			'accent'      => sanitize_hex_color( $ap['accent'] ?? '' ) ?: '',
		);

		$config['utm_mappings'] = array();
		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ) as $param ) {
			$config['utm_mappings'][ $param ] = sanitize_text_field( $raw['utm_mappings'][ $param ] ?? '' );
		}

		$pp = (array) ( $raw['payment_plan'] ?? array() );
		$config['payment_plan'] = array(
			'enabled'         => ! empty( $pp['enabled'] ),
			'count'           => max( 2, absint( $pp['count'] ?? 2 ) ),
			'interval_unit'   => 'day' === ( $pp['interval_unit'] ?? 'month' ) ? 'day' : 'month',
			'interval_count'  => max( 1, absint( $pp['interval_count'] ?? 1 ) ),
			'min_total_cents' => absint( $pp['min_total_cents'] ?? 0 ),
			'final_due'       => sanitize_text_field( $pp['final_due'] ?? '' ),
			'label'           => sanitize_text_field( $pp['label'] ?? '' ),
		);

		$config['fields'] = array();
		foreach ( (array) ( $raw['fields'] ?? array() ) as $f ) {
			if ( empty( $f['label'] ) ) {
				continue;
			}

			// Conditional visibility: combine rules with AND/OR. Empty rules
			// list = always shown.
			$cond_logic = ( 'or' === ( $f['cond_logic'] ?? '' ) ) ? 'or' : 'and';
			$conditions = array();
			foreach ( (array) ( $f['conditions'] ?? array() ) as $c ) {
				$cfield = sanitize_key( $c['field'] ?? '' );
				if ( '' === $cfield ) {
					continue;
				}
				$op = in_array( $c['operator'] ?? '', array( 'equals', 'not_equals', 'one_of', 'not_empty' ), true ) ? $c['operator'] : 'equals';
				$conditions[] = array(
					'field'    => $cfield,
					'operator' => $op,
					'value'    => sanitize_text_field( $c['value'] ?? '' ),
				);
			}

			$config['fields'][] = array(
				'key'          => sanitize_key( $f['key'] ?: sanitize_title( $f['label'] ) ),
				'label'        => sanitize_text_field( $f['label'] ),
				'type'         => in_array( $f['type'] ?? '', array( 'text', 'textarea', 'select', 'checkbox', 'date' ), true ) ? $f['type'] : 'text',
				'placeholder'  => sanitize_text_field( $f['placeholder'] ?? '' ),
				'options'      => sanitize_text_field( $f['options'] ?? '' ),
				'required'     => ! empty( $f['required'] ),
				'ghl_field_id' => sanitize_text_field( $f['ghl_field_id'] ?? '' ),
				'cond_logic'   => $cond_logic,
				'conditions'   => $conditions,
			);
		}

		return $config;
	}

	public static function delete_event() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'evr_admin' ) ) {
			wp_die( 'Not allowed.' );
		}
		EVR_DB::delete_event( absint( $_GET['event_id'] ?? 0 ) );
		wp_safe_redirect( admin_url( 'admin.php?page=evr-events' ) );
		exit;
	}

	public static function delete_registration() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'evr_admin' ) ) {
			wp_die( 'Not allowed.' );
		}
		$reg = EVR_DB::get_registration( absint( $_GET['registration_id'] ?? 0 ) );
		if ( $reg ) {
			EVR_DB::delete_registration( (int) $reg['id'] );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=evr-registrations&event_id=' . ( $reg ? (int) $reg['event_id'] : 0 ) . '&deleted=1' ) );
		exit;
	}

	/**
	 * Add a registrant by hand (no payment collected). Everything is validated
	 * and priced in EVR_Manual::create(); this only unpacks the form.
	 */
	public static function add_registration() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'evr_admin' ) ) {
			wp_die( 'Not allowed.' );
		}
		$event_id = absint( $_POST['event_id'] ?? 0 );

		// Blank amount = price it from the ticket selection; "0" is a real
		// override, so only an empty string falls back.
		$raw_amount   = trim( (string) wp_unslash( $_POST['amount'] ?? '' ) );
		$amount_cents = '' === $raw_amount ? null : (int) round( (float) $raw_amount * 100 );

		$fields = array();
		foreach ( (array) ( $_POST['fields'] ?? array() ) as $key => $value ) {
			$fields[ sanitize_key( $key ) ] = wp_unslash( $value );
		}

		$result = EVR_Manual::create( array(
			'event_id'        => $event_id,
			'email'           => wp_unslash( $_POST['email'] ?? '' ),
			'first_name'      => wp_unslash( $_POST['first_name'] ?? '' ),
			'last_name'       => wp_unslash( $_POST['last_name'] ?? '' ),
			'phone'           => wp_unslash( $_POST['phone'] ?? '' ),
			'ticket_key'      => wp_unslash( $_POST['ticket_key'] ?? '' ),
			'addon_keys'      => (array) ( $_POST['addon_keys'] ?? array() ),
			'promo_code'      => wp_unslash( $_POST['promo_code'] ?? '' ),
			'payment_state'   => wp_unslash( $_POST['payment_state'] ?? 'invoiced' ),
			'amount_cents'    => $amount_cents,
			'note'            => wp_unslash( $_POST['note'] ?? '' ),
			'fields'          => $fields,
			'sync_ghl'        => ! empty( $_POST['sync_ghl'] ),
			'force_duplicate' => ! empty( $_POST['force_duplicate'] ),
			'source'          => 'manual',
		) );

		$args = array( 'page' => 'evr-registrations', 'event_id' => $event_id );
		if ( is_wp_error( $result ) ) {
			// Carry the message in a short-lived per-user transient rather than
			// the URL, so nothing arbitrary can be reflected into the notice.
			set_transient( 'evr_add_error_' . get_current_user_id(), $result->get_error_message(), MINUTE_IN_SECONDS );
			$args['evr_add'] = 'error'; // Re-open the panel so the entry isn't lost from view.
		} else {
			$args['added'] = (int) $result;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * "Add a registrant manually" panel — for people who are invoiced
	 * separately, comped, or paid some other way. Scoped to the event
	 * currently selected above; nothing here touches Stripe.
	 */
	private static function render_add_registrant( $event ) {
		$config  = $event['config'];
		$tickets = array();
		$addons  = array();
		foreach ( (array) $config['tickets'] as $t ) {
			if ( 'addon' === ( $t['type'] ?? 'main' ) ) {
				$addons[] = $t;
			} else {
				$tickets[] = $t;
			}
		}
		$open = isset( $_GET['evr_add'] );
		?>
		<details class="evr-add-reg" <?php echo $open ? 'open' : ''; ?>>
			<summary>+ Add a registrant manually (no payment collected)</summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="evr-add-reg-form">
				<?php wp_nonce_field( 'evr_admin' ); ?>
				<input type="hidden" name="action" value="evr_add_registration">
				<input type="hidden" name="event_id" value="<?php echo (int) $event['id']; ?>">
				<p class="description">Adds the person to <strong><?php echo esc_html( $event['title'] ); ?></strong> as a confirmed registration, skipping the checkout entirely. They count on the roster, in the CSV and against ticket capacity, and they sync to GoHighLevel like anyone else. Sold-out tickets and a closed registration window are ignored here.</p>

				<table class="form-table">
					<tr><th><label for="evr-add-first">Name</label></th>
						<td><input type="text" id="evr-add-first" name="first_name" placeholder="First name" required>
							<input type="text" name="last_name" placeholder="Last name" required></td></tr>
					<tr><th><label for="evr-add-email">Email</label></th>
						<td><input type="email" class="regular-text" id="evr-add-email" name="email" required>
							<p class="description">Their membership tier is looked up automatically, so a member gets their tier recorded and their discount priced in.</p></td></tr>
					<tr><th><label for="evr-add-phone">Phone</label></th>
						<td><input type="text" class="regular-text" id="evr-add-phone" name="phone"></td></tr>

					<?php if ( $tickets ) : ?>
						<tr><th><label for="evr-add-ticket">Ticket</label></th>
							<td><select id="evr-add-ticket" name="ticket_key">
									<option value="">— none (set the amount below) —</option>
									<?php foreach ( $tickets as $t ) : ?>
										<option value="<?php echo esc_attr( $t['key'] ); ?>" data-cents="<?php echo (int) $t['price_cents']; ?>">
											<?php echo esc_html( $t['label'] . ' — $' . number_format( (int) $t['price_cents'] / 100, 2 ) . ( empty( $t['active'] ) ? ' (inactive)' : '' ) ); ?>
										</option>
									<?php endforeach; ?>
								</select></td></tr>
					<?php endif; ?>

					<?php if ( $addons ) : ?>
						<tr><th>Add-ons</th>
							<td><?php foreach ( $addons as $a ) : ?>
									<label class="evr-add-addon"><input type="checkbox" name="addon_keys[]" value="<?php echo esc_attr( $a['key'] ); ?>" data-cents="<?php echo (int) $a['price_cents']; ?>">
										<?php echo esc_html( $a['label'] . ' — $' . number_format( (int) $a['price_cents'] / 100, 2 ) ); ?></label><br>
								<?php endforeach; ?></td></tr>
					<?php endif; ?>

					<tr><th>Payment handling</th>
						<td>
							<?php foreach ( EVR_Manual::payment_states() as $val => $label ) : ?>
								<label class="evr-add-state"><input type="radio" name="payment_state" value="<?php echo esc_attr( $val ); ?>" <?php checked( 'invoiced', $val ); ?>> <?php echo esc_html( $label ); ?></label><br>
							<?php endforeach; ?>
							<p class="description"><strong>Invoiced separately</strong> records the amount as owed and (if the event has an Outstanding-payment stage) puts the GHL opportunity there until you mark it paid. <strong>Comped</strong> forces the amount to $0.</p>
						</td></tr>
					<tr><th><label for="evr-add-amount">Amount</label></th>
						<td>$<input type="number" min="0" step="0.01" class="small-text" id="evr-add-amount" name="amount" placeholder="auto">
							<span class="description">Leave blank to use the ticket price (with their member discount and any promo code). Enter a figure to override it — e.g. the amount you actually invoiced.</span></td></tr>
					<tr><th><label for="evr-add-promo">Promo code</label></th>
						<td><input type="text" id="evr-add-promo" name="promo_code" placeholder="optional">
							<span class="description">Applied to the computed price, and counted against the code's usage limit.</span></td></tr>
					<tr><th><label for="evr-add-note">Internal note</label></th>
						<td><input type="text" class="regular-text" id="evr-add-note" name="note" placeholder="e.g. Invoice #1042 — sent 3 Oct">
							<span class="description">Shown on this screen and in the CSV. Never shown to the registrant.</span></td></tr>

					<?php if ( ! empty( $config['fields'] ) ) : ?>
						<tr><th>Form fields</th>
							<td>
								<?php foreach ( (array) $config['fields'] as $f ) :
									$name = 'fields[' . $f['key'] . ']';
									$opts = array_filter( array_map( 'trim', explode( ',', (string) ( $f['options'] ?? '' ) ) ), 'strlen' );
									?>
									<p class="evr-add-field"><label><?php echo esc_html( $f['label'] ); ?><br>
										<?php if ( 'select' === $f['type'] && $opts ) : ?>
											<select name="<?php echo esc_attr( $name ); ?>">
												<option value="">—</option>
												<?php foreach ( $opts as $o ) : ?><option value="<?php echo esc_attr( $o ); ?>"><?php echo esc_html( $o ); ?></option><?php endforeach; ?>
											</select>
										<?php elseif ( 'textarea' === $f['type'] ) : ?>
											<textarea class="large-text" rows="2" name="<?php echo esc_attr( $name ); ?>"></textarea>
										<?php elseif ( 'checkbox' === $f['type'] ) : ?>
											<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="Yes">
										<?php else : ?>
											<input type="<?php echo 'date' === $f['type'] ? 'date' : 'text'; ?>" class="regular-text" name="<?php echo esc_attr( $name ); ?>">
										<?php endif; ?>
									</label></p>
								<?php endforeach; ?>
								<p class="description">These feed the event's GoHighLevel field mappings. None are required here, even if they are on the public form.</p>
							</td></tr>
					<?php endif; ?>

					<tr><th>Options</th>
						<td>
							<label><input type="checkbox" name="sync_ghl" value="1" checked> Sync to GoHighLevel (contact, tags and opportunity)</label><br>
							<label><input type="checkbox" name="force_duplicate" value="1"> Add even if this email is already registered for this event</label>
						</td></tr>
				</table>
				<?php submit_button( 'Add registrant', 'primary', 'submit', false ); ?>
			</form>
		</details>
		<?php
	}

	/* ---------------- Registrations ---------------- */

	public static function render_registrations() {
		$events   = EVR_DB::get_events();
		$event_id = absint( $_GET['event_id'] ?? ( $events[0]['id'] ?? 0 ) );
		$event    = $event_id ? EVR_DB::get_event( $event_id ) : null;
		$regs     = $event ? EVR_DB::get_registrations( $event_id ) : array();
		$csv_url  = $event ? admin_url( 'admin-post.php?action=evr_export_csv&event_id=' . $event_id . '&nonce=' . wp_create_nonce( 'evr_admin' ) ) : '';
		?>
		<div class="wrap evr-wrap">
			<h1>Registrations <?php self::mode_badge(); ?></h1>
			<?php if ( isset( $_GET['deleted'] ) ) : ?><div class="notice notice-success"><p>Registration deleted.</p></div><?php endif; ?>
			<?php if ( isset( $_GET['added'] ) ) : ?><div class="notice notice-success"><p>Registrant added manually (registration #<?php echo absint( $_GET['added'] ); ?>). No payment was collected.</p></div><?php endif; ?>
			<?php
			$add_error = get_transient( 'evr_add_error_' . get_current_user_id() );
			if ( $add_error ) {
				delete_transient( 'evr_add_error_' . get_current_user_id() );
				echo '<div class="notice notice-error"><p>' . esc_html( $add_error ) . '</p></div>';
			}
			?>
			<form method="get"><input type="hidden" name="page" value="evr-registrations">
				<select name="event_id" onchange="this.form.submit()">
					<?php foreach ( $events as $e ) : ?>
						<option value="<?php echo (int) $e['id']; ?>" <?php selected( $event_id, $e['id'] ); ?>><?php echo esc_html( $e['title'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( $csv_url ) : ?><a class="button" href="<?php echo esc_url( $csv_url ); ?>">Download CSV</a><?php endif; ?>
			</form>
			<?php if ( $event ) : self::render_add_registrant( $event ); endif; ?>
			<table class="widefat striped" style="margin-top:12px">
				<thead><tr><th>Date</th><th>Name</th><th>Email</th><th>Tier</th><th>Ticket</th><th>Amount</th><th>Status</th><th>Mode</th><th>GHL</th><th></th></tr></thead>
				<tbody>
				<?php if ( ! $regs ) : ?><tr><td colspan="10">No registrations yet.</td></tr><?php endif; ?>
				<?php foreach ( $regs as $r ) : ?>
					<tr>
						<td><?php echo esc_html( $r['created_at'] ); ?></td>
						<td><?php echo esc_html( $r['first_name'] . ' ' . $r['last_name'] ); ?></td>
						<td><?php echo esc_html( $r['email'] ); ?></td>
						<td><?php echo esc_html( ucfirst( $r['tier'] ) ); ?></td>
						<td><?php echo esc_html( $r['ticket_label'] ); ?></td>
						<td><?php echo esc_html( strtoupper( $r['currency'] ) . ' ' . number_format( $r['amount_cents'] / 100, 2 ) ); ?>
							<?php $state = (string) ( $r['payment_state'] ?? '' ); ?>
							<?php if ( $state ) : ?>
								<div class="evr-manual-detail">
									<span class="evr-pay-state evr-pay-<?php echo esc_attr( $state ); ?>"><?php echo esc_html( EVR_Manual::payment_state_label( $state ) ); ?></span>
									<?php if ( ! empty( $r['admin_note'] ) ) : ?>
										<div class="evr-manual-note"><?php echo esc_html( $r['admin_note'] ); ?></div>
									<?php endif; ?>
									<?php if ( 'invoiced' === $state ) : ?>
										<button type="button" class="button button-small evr-mark-paid" data-reg="<?php echo (int) $r['id']; ?>">Mark paid</button>
										<span class="evr-mark-paid-result"></span>
									<?php endif; ?>
								</div>
							<?php endif; ?>
							<?php
							$plan = EVR_Installments::get_plan( $r );
							if ( $plan ) :
								$paid_count     = 0;
								$cancelable     = 0; // Payments not yet collected and not already called off.
								$canceled_cents = 0; // What was called off — i.e. never collected here.
								foreach ( $plan['installments'] as $inst ) {
									if ( 'paid' === $inst['status'] ) {
										$paid_count++;
									} elseif ( 'canceled' === $inst['status'] ) {
										$canceled_cents += (int) $inst['amount_cents'];
									} else {
										$cancelable++;
									}
								}
								$dash_url = 'https://dashboard.stripe.com/' . ( 'live' === $r['stripe_mode'] ? '' : 'test/' ) . 'customers/' . rawurlencode( $r['stripe_customer_id'] );
								?>
								<div class="evr-plan-detail">
									<div class="evr-plan-head">Payment plan: <?php echo (int) $paid_count . '/' . count( $plan['installments'] ); ?> paid
										<?php if ( $canceled_cents ) : ?>
											<span class="evr-plan-canceled-sum">· <?php echo esc_html( strtoupper( $r['currency'] ) . ' ' . number_format( $canceled_cents / 100, 2 ) ); ?> canceled, not collected</span>
										<?php endif; ?>
										<span class="evr-plan-tag evr-plan-<?php echo esc_attr( $r['plan_status'] ); ?>"><?php echo esc_html( $r['plan_status'] ); ?></span>
									</div>
									<table class="evr-inst-table">
										<?php foreach ( $plan['installments'] as $inst ) :
											$ts  = EVR_Pricing::local_datetime_to_ts( $inst['due_date'] );
											$err = (string) ( $inst['last_error'] ?? '' );
											?>
											<tr>
												<td>Payment <?php echo (int) $inst['index'] + 1; ?></td>
												<td><?php echo esc_html( number_format( (int) $inst['amount_cents'] / 100, 2 ) ); ?></td>
												<td><span class="evr-inst evr-inst-<?php echo esc_attr( $inst['status'] ); ?>"<?php echo $err ? ' title="' . esc_attr( $err ) . '"' : ''; ?>><?php echo esc_html( $inst['status'] ); ?></span></td>
												<td><?php echo $ts ? esc_html( date_i18n( 'M j, Y', $ts ) ) : ''; ?></td>
												<td><?php if ( ! empty( $inst['invoice_url'] ) ) : ?><a href="<?php echo esc_url( $inst['invoice_url'] ); ?>" target="_blank" rel="noopener">invoice</a><?php endif; ?></td>
												<td><?php if ( ! in_array( $inst['status'], array( 'paid', 'canceled' ), true ) ) : ?>
													<button type="button" class="button-link evr-cancel-inst" data-reg="<?php echo (int) $r['id']; ?>" data-index="<?php echo (int) $inst['index']; ?>">Cancel</button>
												<?php endif; ?></td>
											</tr>
										<?php endforeach; ?>
									</table>
									<?php if ( ! empty( $r['stripe_customer_id'] ) ) : ?>
										<button type="button" class="button button-small evr-portal-link" data-reg="<?php echo (int) $r['id']; ?>">Manage card</button>
										<a class="button button-small" href="<?php echo esc_url( $dash_url ); ?>" target="_blank" rel="noopener">View in Stripe</a>
										<?php if ( in_array( $r['plan_status'], array( 'active', 'defaulted' ), true ) ) : ?>
											<?php if ( ! empty( $plan['payoff_invoice_id'] ) ) : ?>
												<span class="evr-payoff-sent">Payoff invoice sent<?php if ( ! empty( $plan['payoff_invoice_url'] ) ) : ?> — <a href="<?php echo esc_url( $plan['payoff_invoice_url'] ); ?>" target="_blank" rel="noopener">view</a><?php endif; ?></span>
											<?php else : ?>
												<button type="button" class="button button-small evr-send-payoff" data-reg="<?php echo (int) $r['id']; ?>">Send invoice for remaining balance</button>
											<?php endif; ?>
										<?php endif; ?>
										<?php if ( $cancelable ) : ?>
											<button type="button" class="button button-small evr-cancel-remaining" data-reg="<?php echo (int) $r['id']; ?>">Cancel remaining payments</button>
										<?php endif; ?>
										<span class="evr-portal-result"></span>
										<span class="evr-payoff-result"></span>
										<span class="evr-cancel-result"></span>
									<?php endif; ?>
								</div>
							<?php endif; ?>
						</td>
						<td><span class="evr-status evr-status-<?php echo esc_attr( $r['status'] ); ?>"><?php echo esc_html( $r['status'] ); ?></span>
							<?php $src = (string) ( $r['source'] ?? '' ); ?>
							<?php if ( in_array( $src, array( 'manual', 'staff', 'api' ), true ) ) : ?>
								<br><span class="evr-source-tag" title="Added without going through the checkout"><?php echo esc_html( EVR_Manual::source_label( $src ) ); ?><?php echo ! empty( $r['added_by'] ) ? esc_html( ' · ' . $r['added_by'] ) : ''; ?></span>
							<?php endif; ?></td>
						<td><?php echo esc_html( $r['stripe_mode'] ); ?></td>
						<td>
							<?php echo esc_html( $r['ghl_sync_status'] ); ?>
							<?php if ( 'failed' === $r['ghl_sync_status'] ) : ?>
								<br><small><?php echo esc_html( $r['ghl_sync_error'] ); ?></small>
								<br><button type="button" class="button button-small evr-retry-ghl" data-reg="<?php echo (int) $r['id']; ?>">Retry</button>
							<?php endif; ?>
						</td>
						<td>
							<?php $del_url = wp_nonce_url( admin_url( 'admin-post.php?action=evr_delete_registration&registration_id=' . (int) $r['id'] ), 'evr_admin' ); ?>
							<a href="<?php echo esc_url( $del_url ); ?>" class="evr-delete-link" onclick="return confirm('Delete this registration? This only removes it here — it does not refund the payment in Stripe or remove the contact/opportunity in GHL.');">Delete</a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* ---------------- Settings ---------------- */

	public static function render_settings() {
		$s = EVR_Settings::all();
		$webhook_url = EVR_Webhook::endpoint_url();
		?>
		<div class="wrap evr-wrap">
			<h1>Event Registration Settings <?php self::mode_badge(); ?></h1>
			<?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success"><p>Settings saved.</p></div><?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'evr_admin' ); ?>
				<input type="hidden" name="action" value="evr_save_settings">

				<h2>Stripe</h2>
				<table class="form-table">
					<tr><th>Mode</th><td>
						<label><input type="radio" name="stripe_mode" value="test" <?php checked( $s['stripe_mode'], 'test' ); ?>> Test (sandbox)</label> &nbsp;
						<label><input type="radio" name="stripe_mode" value="live" <?php checked( $s['stripe_mode'], 'live' ); ?>> <strong>Live</strong></label>
						<p class="description">Switching mode changes which keys and which Stripe product catalog the whole plugin uses. No code changes needed.</p>
					</td></tr>
					<tr><th>Test publishable key</th><td><input type="text" class="regular-text" name="stripe_test_pk" value="<?php echo esc_attr( $s['stripe_test_pk'] ); ?>"></td></tr>
					<tr><th>Test secret key</th><td><input type="password" class="regular-text" name="stripe_test_sk" value="<?php echo esc_attr( $s['stripe_test_sk'] ); ?>" autocomplete="off"></td></tr>
					<tr><th>Test webhook signing secret</th><td><input type="password" class="regular-text" name="stripe_test_whsec" value="<?php echo esc_attr( $s['stripe_test_whsec'] ); ?>" autocomplete="off"></td></tr>
					<tr><th>Live publishable key</th><td><input type="text" class="regular-text" name="stripe_live_pk" value="<?php echo esc_attr( $s['stripe_live_pk'] ); ?>"></td></tr>
					<tr><th>Live secret key</th><td><input type="password" class="regular-text" name="stripe_live_sk" value="<?php echo esc_attr( $s['stripe_live_sk'] ); ?>" autocomplete="off"></td></tr>
					<tr><th>Live webhook signing secret</th><td><input type="password" class="regular-text" name="stripe_live_whsec" value="<?php echo esc_attr( $s['stripe_live_whsec'] ); ?>" autocomplete="off"></td></tr>
					<tr><th>Webhook endpoint URL</th><td><code><?php echo esc_html( $webhook_url ); ?></code>
						<p class="description">Add this endpoint in the Stripe Dashboard (both test and live) with events: <code>payment_intent.succeeded</code>, <code>payment_intent.payment_failed</code>, <code>charge.refunded</code>. If you use <strong>payment plans</strong>, also add <code>invoice.paid</code>, <code>invoice.payment_failed</code>, <code>invoice.marked_uncollectible</code> (and activate the Stripe <strong>Customer Portal</strong> so customers can update their card). This is pinned to the live site, so it stays the same on staging/dev installs — don't repoint Stripe at another host.</p></td></tr>
					<tr><th>Currency</th><td><input type="text" name="currency" value="<?php echo esc_attr( $s['currency'] ); ?>" size="4"> <span class="description">3-letter code, e.g. usd</span></td></tr>
				</table>

				<h2>Supabase (membership lookup)</h2>
				<table class="form-table">
					<tr><th>Project URL</th><td><input type="url" class="regular-text" name="supabase_url" value="<?php echo esc_attr( $s['supabase_url'] ); ?>" placeholder="https://xxxx.supabase.co"></td></tr>
					<tr><th>Service role key</th><td><input type="password" class="regular-text" name="supabase_key" value="<?php echo esc_attr( $s['supabase_key'] ); ?>" autocomplete="off">
						<p class="description">Used server-side only; never exposed to the browser.</p></td></tr>
					<tr><th>Table name</th><td><input type="text" name="supabase_table" value="<?php echo esc_attr( $s['supabase_table'] ); ?>"></td></tr>
					<tr><th>Email column</th><td><input type="text" name="supabase_email_col" value="<?php echo esc_attr( $s['supabase_email_col'] ); ?>"></td></tr>
					<tr><th>Tier column</th><td><input type="text" name="supabase_tier_col" value="<?php echo esc_attr( $s['supabase_tier_col'] ); ?>">
						<p class="description">Values like "Premium", "Premium Processor", "Elite", "VIP Processor" are recognized automatically.</p></td></tr>
				</table>

				<h2>GoHighLevel</h2>
				<table class="form-table">
					<tr><th>Private Integration token</th><td><input type="password" class="regular-text" name="ghl_token" value="<?php echo esc_attr( $s['ghl_token'] ); ?>" autocomplete="off">
						<p class="description">Default token. Events in other sub-accounts can set a per-event token override.</p></td></tr>
				</table>

				<h2>Manual registrations</h2>
				<p class="description">Three ways to add someone without collecting payment. The first two need no setup.</p>
				<table class="form-table">
					<tr><th>1. In here</th><td>The <strong>"+ Add a registrant manually"</strong> panel at the top of the <a href="<?php echo esc_url( admin_url( 'admin.php?page=evr-registrations' ) ); ?>">Registrations</a> screen. For anyone with a WordPress admin login.</td></tr>
					<tr><th>2. Staff page</th><td>
						<p style="margin-top:0">A form your team can use without WordPress accounts. Create a page, set it to <strong>Password protected</strong> (Page &rarr; Visibility), share that password with the team, and put this shortcode on it:</p>
						<p><code>[event_registration_staff]</code></p>
						<p class="description">It lists <strong>every event automatically</strong>, so events you create later need no setup — they just appear in the dropdown. The form refuses to render on a page with no password, so it can't be published wide open by accident. To gate it on WordPress logins instead, use <code>[event_registration_staff require_login="yes"]</code> (add <code>capability="edit_posts"</code> to narrow which roles). Optional: <code>title="…"</code> changes the heading, and <code>status="active"</code> limits the dropdown to active events.</p>
					</td></tr>
					<tr><th>3. API</th><td>The endpoint below, for a <strong>GoHighLevel form</strong> (or any other tool) to create registrations directly from its workflow. Note that a GHL workflow has to be told which <code>event_id</code> to use, so it needs updating for each new event — the staff page above avoids that.</td></tr>
				</table>
				<table class="form-table">
					<tr><th>Endpoint URL</th><td><code><?php echo esc_html( EVR_Manual::endpoint_url() ); ?></code>
						<p class="description">In GoHighLevel: add a <strong>Custom Webhook</strong> action to the form's workflow, method POST, with the key below in an <code>X-EVR-Key</code> header. See the payload below.</p></td></tr>
					<tr><th>Shared key</th><td>
						<input type="text" class="regular-text code" name="manual_api_key" id="evr-manual-key" value="<?php echo esc_attr( $s['manual_api_key'] ); ?>" autocomplete="off">
						<button type="button" class="button" id="evr-gen-manual-key">Generate</button>
						<p class="description"><strong>Leave blank to switch the endpoint off entirely.</strong> Anyone holding this key can add registrations, so treat it like a password and regenerate it if it leaks. Registrations created this way are marked "added via API" in the list.</p></td></tr>
					<tr><th>JSON body</th><td>
<pre class="evr-code-block">{
  "event_id": 12,
  "email": "someone@example.com",
  "first_name": "Jane",
  "last_name": "Doe",
  "phone": "555-0100",
  "ticket_key": "tk_abc123",          // optional; omit and set "amount" instead
  "addon_keys": ["tk_def456"],        // optional
  "payment_state": "invoiced",        // invoiced | paid_external | comp
  "amount": 499.00,                   // optional; omit to use the ticket price
  "promo_code": "",                   // optional
  "note": "Invoice #1042",            // optional
  "fields": { "company": "Acme" },    // optional, keyed by your form field keys
  "sync_ghl": true                    // optional, defaults to true
}</pre>
						<p class="description">Ticket keys are shown on the event editor's Tickets rows. A success returns HTTP 201 with the new <code>registration_id</code>; a duplicate email returns 409 (add <code>"force_duplicate": true</code> to allow it anyway).</p></td></tr>
				</table>

				<h2>Appearance</h2>
				<p class="description">Controls the look of the public registration form. These are the global defaults; each event can override them under its own <strong>Appearance (this event)</strong> section. Leave a color blank to inherit your theme.</p>
				<table class="form-table">
					<tr><th><label for="evr-appearance-font">Font</label></th>
						<td>
							<select name="appearance_font" id="evr-appearance-font">
								<?php foreach ( EVR_Settings::font_stacks() as $val => $f ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $s['appearance_font'], $val ); ?>><?php echo esc_html( is_array( $f ) ? $f['label'] : $f ); ?></option>
								<?php endforeach; ?>
							</select>
							<input type="text" class="regular-text" name="appearance_font_custom" value="<?php echo esc_attr( $s['appearance_font_custom'] ); ?>" placeholder="e.g. 'Poppins', sans-serif" style="margin-left:8px">
							<p class="description">Pick a preset, or choose "Custom" and enter a font-family. A custom/Google font must already be loaded by your theme.</p>
						</td></tr>
					<tr><th>Button color</th><td><input type="text" class="evr-color" name="appearance_button_bg" value="<?php echo esc_attr( $s['appearance_button_bg'] ); ?>" data-default-color="#ffffff"></td></tr>
					<tr><th>Button text color</th><td><input type="text" class="evr-color" name="appearance_button_text" value="<?php echo esc_attr( $s['appearance_button_text'] ); ?>" data-default-color="#16204e"></td></tr>
					<tr><th>Body text color</th><td><input type="text" class="evr-color" name="appearance_text" value="<?php echo esc_attr( $s['appearance_text'] ); ?>">
						<p class="description">Applies to labels and form text. Leave blank to inherit your theme (useful on dark page backgrounds).</p></td></tr>
					<tr><th>Input border color</th><td><input type="text" class="evr-color" name="appearance_border" value="<?php echo esc_attr( $s['appearance_border'] ); ?>" data-default-color="#cccccc"></td></tr>
					<tr><th>Accent color</th><td><input type="text" class="evr-color" name="appearance_accent" value="<?php echo esc_attr( $s['appearance_accent'] ); ?>" data-default-color="#16204e">
						<p class="description">Used for input focus highlights.</p></td></tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'evr_admin' ) ) {
			wp_die( 'Not allowed.' );
		}
		EVR_Settings::save( $_POST );
		wp_safe_redirect( admin_url( 'admin.php?page=evr-settings&saved=1' ) );
		exit;
	}
}
