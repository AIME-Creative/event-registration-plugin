<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Membership tier lookup against a Supabase table via the PostgREST API.
 *
 * "Premium Processor", "Elite Processor", and "VIP Processor" are
 * normalized to their base tier, so one discount per tier covers both.
 */
class EVR_Supabase {

	/**
	 * Look up the membership tier for an email.
	 *
	 * @return string One of 'premium' | 'elite' | 'vip' | '' (no membership).
	 */
	public static function get_tier( $email ) {
		$email = strtolower( trim( $email ) );
		if ( ! is_email( $email ) ) {
			return '';
		}

		$url   = rtrim( EVR_Settings::get( 'supabase_url' ), '/' );
		$key   = EVR_Settings::get( 'supabase_key' );
		$table = EVR_Settings::get( 'supabase_table', 'memberships' );
		$ecol  = EVR_Settings::get( 'supabase_email_col', 'email' );
		$tcol  = EVR_Settings::get( 'supabase_tier_col', 'membership_level' );

		if ( ! $url || ! $key ) {
			return '';
		}

		$endpoint = $url . '/rest/v1/' . rawurlencode( $table )
			. '?select=' . rawurlencode( $tcol )
			. '&' . rawurlencode( $ecol ) . '=ilike.' . rawurlencode( $email )
			. '&limit=1';

		$response = wp_remote_get( $endpoint, array(
			'timeout' => 15,
			'headers' => array(
				'apikey'        => $key,
				'Authorization' => 'Bearer ' . $key,
			),
		) );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 400 ) {
			return '';
		}

		$rows = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $rows[0][ $tcol ] ) ) {
			return '';
		}

		return self::normalize_tier( $rows[0][ $tcol ] );
	}

	/**
	 * Normalize raw tier values, e.g. "Premium Processor" -> "premium".
	 */
	public static function normalize_tier( $raw ) {
		$tier = strtolower( trim( (string) $raw ) );
		$tier = trim( str_replace( 'processor', '', $tier ) );
		return in_array( $tier, array( 'premium', 'elite', 'vip' ), true ) ? $tier : '';
	}
}
