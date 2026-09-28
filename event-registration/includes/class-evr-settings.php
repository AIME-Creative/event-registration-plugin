<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Global plugin settings: Stripe keys (test + live, switchable without code
 * changes), Supabase membership lookup, GoHighLevel token, defaults.
 */
class EVR_Settings {

	const OPTION = 'evr_settings';

	public static function init() {
		// Settings are saved from the admin page in EVR_Admin.
	}

	public static function defaults() {
		return array(
			'stripe_mode'         => 'test', // 'test' | 'live'
			'stripe_test_pk'      => '',
			'stripe_test_sk'      => '',
			'stripe_test_whsec'   => '',
			'stripe_live_pk'      => '',
			'stripe_live_sk'      => '',
			'stripe_live_whsec'   => '',
			'supabase_url'        => '',
			'supabase_key'        => '',
			'supabase_table'      => 'memberships',
			'supabase_email_col'  => 'email',
			'supabase_tier_col'   => 'membership_level',
			'ghl_token'           => '',
			'currency'            => 'usd',

			// Shared key for the manual-registration REST endpoint (used by a
			// GoHighLevel form/workflow). Blank switches the endpoint off.
			'manual_api_key'      => '',

			// Appearance of the public registration form.
			'appearance_font'        => '',          // preset key from font_stacks(); '' = theme default
			'appearance_font_custom' => '',          // raw font-family, used when appearance_font = 'custom'
			'appearance_button_bg'   => '#ffffff',   // Register/submit button background
			'appearance_button_text' => '#16204e',   // button text
			'appearance_text'        => '',          // body/label text; '' = inherit theme
			'appearance_border'      => '#cccccc',   // input/select/textarea border
			'appearance_accent'      => '#16204e',   // input focus + highlights
		);
	}

	/**
	 * Selectable font presets for the form. '' and 'custom' are special;
	 * every other entry is array{label,stack}.
	 */
	public static function font_stacks() {
		return array(
			''          => 'Theme default',
			'system'    => array( 'label' => 'System sans-serif', 'stack' => "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif" ),
			'helvetica' => array( 'label' => 'Helvetica / Arial', 'stack' => 'Helvetica, Arial, sans-serif' ),
			'georgia'   => array( 'label' => 'Georgia (serif)', 'stack' => "Georgia, 'Times New Roman', serif" ),
			'times'     => array( 'label' => 'Times (serif)', 'stack' => "'Times New Roman', Times, serif" ),
			'mono'      => array( 'label' => 'Monospace', 'stack' => "'SFMono-Regular', Menlo, Consolas, monospace" ),
			'custom'    => array( 'label' => 'Custom (enter below)', 'stack' => '' ),
		);
	}

	/**
	 * Resolve a font preset key (+ optional custom value) to a CSS
	 * font-family string. Returns '' for "theme default".
	 */
	public static function resolve_font( $key, $custom = '' ) {
		if ( 'custom' === $key ) {
			return trim( (string) $custom );
		}
		$stacks = self::font_stacks();
		return ( isset( $stacks[ $key ] ) && is_array( $stacks[ $key ] ) ) ? $stacks[ $key ]['stack'] : '';
	}

	/**
	 * Resolved CSS custom properties for a form, merging an event's
	 * optional per-event overrides over the global appearance settings.
	 * Empty values mean "inherit" and are skipped by the caller.
	 *
	 * @param array|null $event Event row (uses $event['config']['appearance']).
	 * @return array<string,string> CSS variable name => value.
	 */
	public static function appearance_for_event( $event = null ) {
		$g  = self::all();
		$ov = ( is_array( $event ) && is_array( $event['config']['appearance'] ?? null ) ) ? $event['config']['appearance'] : array();

		$pick = function ( $ov_key, $global_value ) use ( $ov ) {
			$v = isset( $ov[ $ov_key ] ) ? trim( (string) $ov[ $ov_key ] ) : '';
			return '' !== $v ? $v : (string) $global_value;
		};

		// Font: event preset overrides global; resolve to a stack.
		$ov_font = isset( $ov['font'] ) ? trim( (string) $ov['font'] ) : '';
		$font    = '' !== $ov_font
			? self::resolve_font( $ov_font )
			: self::resolve_font( $g['appearance_font'], $g['appearance_font_custom'] );
		// Strip anything that could break out of the CSS declaration.
		$font = preg_replace( '/[<>{};\\\\]/', '', (string) $font );

		return array(
			'--evr-font'        => $font,
			'--evr-text'        => $pick( 'text', $g['appearance_text'] ),
			'--evr-button-bg'   => $pick( 'button_bg', $g['appearance_button_bg'] ),
			'--evr-button-text' => $pick( 'button_text', $g['appearance_button_text'] ),
			'--evr-border'      => $pick( 'border', $g['appearance_border'] ),
			'--evr-accent'      => $pick( 'accent', $g['appearance_accent'] ),
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	public static function get( $key, $default = '' ) {
		$all = self::all();
		return isset( $all[ $key ] ) && '' !== $all[ $key ] ? $all[ $key ] : $default;
	}

	public static function save( $values ) {
		$all = self::all();
		foreach ( self::defaults() as $key => $unused ) {
			if ( isset( $values[ $key ] ) ) {
				$all[ $key ] = is_string( $values[ $key ] ) ? trim( wp_unslash( $values[ $key ] ) ) : $values[ $key ];
			}
		}
		$all['stripe_mode'] = in_array( $all['stripe_mode'], array( 'test', 'live' ), true ) ? $all['stripe_mode'] : 'test';

		// Appearance: validate font preset, sanitize colors as hex.
		$all['appearance_font'] = array_key_exists( (string) $all['appearance_font'], self::font_stacks() ) ? $all['appearance_font'] : '';
		foreach ( array( 'appearance_button_bg', 'appearance_button_text', 'appearance_text', 'appearance_border', 'appearance_accent' ) as $color_key ) {
			$all[ $color_key ] = sanitize_hex_color( $all[ $color_key ] );
			if ( null === $all[ $color_key ] ) {
				$all[ $color_key ] = '';
			}
		}

		update_option( self::OPTION, $all );
	}

	public static function stripe_mode() {
		return self::get( 'stripe_mode', 'test' );
	}

	public static function is_live() {
		return 'live' === self::stripe_mode();
	}

	/**
	 * The Stripe mode for a specific event: its own override, or the
	 * global default.
	 */
	public static function event_mode( $event ) {
		$mode = $event['config']['stripe_mode'] ?? '';
		return in_array( $mode, array( 'test', 'live' ), true ) ? $mode : self::stripe_mode();
	}

	public static function stripe_secret_key( $mode = '' ) {
		$mode = $mode ?: self::stripe_mode();
		return 'live' === $mode ? self::get( 'stripe_live_sk' ) : self::get( 'stripe_test_sk' );
	}

	public static function stripe_publishable_key( $mode = '' ) {
		$mode = $mode ?: self::stripe_mode();
		return 'live' === $mode ? self::get( 'stripe_live_pk' ) : self::get( 'stripe_test_pk' );
	}

	public static function stripe_webhook_secret( $mode = '' ) {
		$mode = $mode ?: self::stripe_mode();
		return 'live' === $mode ? self::get( 'stripe_live_whsec' ) : self::get( 'stripe_test_whsec' );
	}
}
