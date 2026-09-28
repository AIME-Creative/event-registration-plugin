<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Minimal Stripe API client using the WordPress HTTP API (no SDK needed).
 * All calls automatically use the key for the currently selected mode
 * (test/live), so switching environments is a settings toggle only.
 */
class EVR_Stripe {

	const API_BASE = 'https://api.stripe.com/v1/';

	public static function request( $method, $path, $params = array(), $mode = '' ) {
		$mode = $mode ?: EVR_Settings::stripe_mode();
		$sk   = EVR_Settings::stripe_secret_key( $mode );
		if ( ! $sk ) {
			return new WP_Error( 'evr_stripe', 'Stripe secret key is not configured for ' . $mode . ' mode.' );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization'  => 'Bearer ' . $sk,
				'Stripe-Version' => '2024-06-20',
			),
		);

		$url = self::API_BASE . ltrim( $path, '/' );
		if ( 'GET' === $method && $params ) {
			$url = add_query_arg( $params, $url );
		} elseif ( $params ) {
			$args['body'] = $params; // Stripe expects form-encoded bodies.
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			$msg = isset( $body['error']['message'] ) ? $body['error']['message'] : 'Stripe API error (HTTP ' . $code . ').';
			return new WP_Error( 'evr_stripe', $msg, $body );
		}
		return $body;
	}

	/**
	 * Active products with their default price, for the event editor picker.
	 */
	public static function list_products( $mode = '' ) {
		$result = self::request( 'GET', 'products', array(
			'active'   => 'true',
			'limit'    => 100,
			'expand[]' => 'data.default_price',
		), $mode );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$products = array();
		foreach ( (array) ( $result['data'] ?? array() ) as $p ) {
			$price = isset( $p['default_price'] ) && is_array( $p['default_price'] ) ? $p['default_price'] : null;
			$products[] = array(
				'id'          => $p['id'],
				'name'        => $p['name'],
				'price_cents' => $price ? (int) $price['unit_amount'] : 0,
				'currency'    => $price ? $price['currency'] : '',
			);
		}
		return $products;
	}

	/**
	 * Create a PaymentIntent for the on-page (embedded) checkout.
	 *
	 * @param array $opts Extra options:
	 *   - customer            Stripe customer id (attach the payment to a customer)
	 *   - setup_future_usage  e.g. 'off_session' to save the card for later
	 *                         installments charged without the customer present
	 */
	public static function create_payment_intent( $amount_cents, $currency, $metadata = array(), $receipt_email = '', $mode = '', $description = '', $opts = array() ) {
		$params = array(
			'amount'                               => $amount_cents,
			'currency'                             => $currency,
			'automatic_payment_methods[enabled]'   => 'true',
		);
		if ( $description ) {
			$params['description'] = $description; // Shows in the Stripe Dashboard's Description column.
		}
		if ( ! empty( $opts['customer'] ) ) {
			$params['customer'] = $opts['customer'];
		}
		if ( ! empty( $opts['setup_future_usage'] ) ) {
			$params['setup_future_usage'] = $opts['setup_future_usage'];
		}
		foreach ( $metadata as $k => $v ) {
			$params[ 'metadata[' . $k . ']' ] = $v;
		}
		if ( $receipt_email ) {
			$params['receipt_email'] = $receipt_email;
		}
		return self::request( 'POST', 'payment_intents', $params, $mode );
	}

	/**
	 * Find an existing Stripe customer by email, or create one. Used to save
	 * a card against a customer so later installments can be charged
	 * off-session. Returns the customer id or WP_Error.
	 */
	public static function get_or_create_customer( $email, $name = '', $mode = '' ) {
		$email = trim( (string) $email );
		if ( '' === $email ) {
			return new WP_Error( 'evr_stripe', 'An email address is required to create a Stripe customer.' );
		}
		// Reuse the most recent customer with this email if one exists.
		$search = self::request( 'GET', 'customers', array( 'email' => $email, 'limit' => 1 ), $mode );
		if ( ! is_wp_error( $search ) && ! empty( $search['data'][0]['id'] ) ) {
			return $search['data'][0]['id'];
		}
		$created = self::request( 'POST', 'customers', array_filter( array(
			'email' => $email,
			'name'  => $name,
		) ), $mode );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		return $created['id'] ?? new WP_Error( 'evr_stripe', 'Could not create a Stripe customer.' );
	}

	/**
	 * Set a customer's default payment method for invoices. Called after the
	 * first installment succeeds so the saved card auto-charges later invoices;
	 * when the customer later updates their card in the Customer Portal, that
	 * updates this same default, so future invoices follow the new card.
	 */
	public static function set_customer_default_pm( $customer_id, $payment_method_id, $mode = '' ) {
		if ( ! $customer_id || ! $payment_method_id ) {
			return new WP_Error( 'evr_stripe', 'Customer or payment method missing.' );
		}
		return self::request( 'POST', 'customers/' . rawurlencode( $customer_id ), array(
			'invoice_settings[default_payment_method]' => $payment_method_id,
		), $mode );
	}

	/**
	 * Create, finalize, and (via charge_automatically) auto-charge a Stripe
	 * invoice for a single installment. Stripe attempts payment against the
	 * customer's default card on finalization; it also drives retries/dunning
	 * and emails the customer on failure. The invoice is intentionally NOT
	 * pinned to a specific payment method, so a card the customer changes in
	 * the portal (their new default) is used automatically.
	 *
	 * @return array|WP_Error Finalized invoice (status 'paid' if it cleared
	 *                        synchronously, else 'open' while Stripe collects).
	 */
	public static function create_installment_invoice( $customer_id, $amount_cents, $currency, $description, $metadata = array(), $mode = '' ) {
		if ( ! $customer_id ) {
			return new WP_Error( 'evr_stripe', 'A Stripe customer is required to invoice an installment.' );
		}

		$item = self::request( 'POST', 'invoiceitems', array(
			'customer'    => $customer_id,
			'amount'      => $amount_cents,
			'currency'    => $currency,
			'description' => $description,
		), $mode );
		if ( is_wp_error( $item ) ) {
			return $item;
		}

		$params = array(
			'customer'                       => $customer_id,
			'collection_method'              => 'charge_automatically',
			'auto_advance'                   => 'true',
			'pending_invoice_items_behavior' => 'include',
			'description'                    => $description,
		);
		foreach ( $metadata as $k => $v ) {
			$params[ 'metadata[' . $k . ']' ] = $v;
		}
		$invoice = self::request( 'POST', 'invoices', $params, $mode );
		if ( is_wp_error( $invoice ) ) {
			return $invoice;
		}

		return self::request( 'POST', 'invoices/' . rawurlencode( $invoice['id'] ) . '/finalize', array(), $mode );
	}

	/**
	 * Create and send (email) a single Stripe invoice for a plan's remaining
	 * balance, so the customer can pay it off early via the hosted invoice page
	 * with any card. Uses collection_method=send_invoice (no auto-charge); the
	 * `/send` call finalizes the invoice and emails it to the customer.
	 *
	 * @return array|WP_Error The finalized, sent invoice (status 'open').
	 */
	public static function create_payoff_invoice( $customer_id, $amount_cents, $currency, $description, $metadata = array(), $mode = '', $days_until_due = 7 ) {
		if ( ! $customer_id ) {
			return new WP_Error( 'evr_stripe', 'A Stripe customer is required to send an invoice.' );
		}
		$item = self::request( 'POST', 'invoiceitems', array(
			'customer'    => $customer_id,
			'amount'      => $amount_cents,
			'currency'    => $currency,
			'description' => $description,
		), $mode );
		if ( is_wp_error( $item ) ) {
			return $item;
		}

		$params = array(
			'customer'                       => $customer_id,
			'collection_method'              => 'send_invoice',
			'days_until_due'                 => max( 1, (int) $days_until_due ),
			'auto_advance'                   => 'true',
			'pending_invoice_items_behavior' => 'include',
			'description'                    => $description,
		);
		foreach ( $metadata as $k => $v ) {
			$params[ 'metadata[' . $k . ']' ] = $v;
		}
		$invoice = self::request( 'POST', 'invoices', $params, $mode );
		if ( is_wp_error( $invoice ) ) {
			return $invoice;
		}

		// Finalizes and emails the invoice to the customer.
		return self::request( 'POST', 'invoices/' . rawurlencode( $invoice['id'] ) . '/send', array(), $mode );
	}

	/**
	 * Void an open invoice (e.g. cancel an in-flight auto-charge invoice before
	 * rolling it into a payoff invoice, so it can't also settle).
	 */
	public static function void_invoice( $invoice_id, $mode = '' ) {
		if ( ! $invoice_id ) {
			return new WP_Error( 'evr_stripe', 'No invoice id.' );
		}
		return self::request( 'POST', 'invoices/' . rawurlencode( $invoice_id ) . '/void', array(), $mode );
	}

	/**
	 * Create a Stripe Customer Portal session so a customer (or the team, on
	 * their behalf) can update the card used for upcoming installments.
	 * Requires the portal to be activated in the Stripe Dashboard.
	 *
	 * @return array|WP_Error Session ({ url, ... }).
	 */
	public static function create_portal_session( $customer_id, $return_url, $mode = '' ) {
		if ( ! $customer_id ) {
			return new WP_Error( 'evr_stripe', 'No Stripe customer on this registration.' );
		}
		return self::request( 'POST', 'billing_portal/sessions', array_filter( array(
			'customer'   => $customer_id,
			'return_url' => $return_url,
		) ), $mode );
	}

	/**
	 * Charge a saved payment method off-session (customer not present). Retained
	 * for compatibility; installment collection now uses invoices.
	 */
	public static function charge_saved_pm( $customer_id, $payment_method_id, $amount_cents, $currency, $metadata = array(), $mode = '', $description = '' ) {
		$params = array(
			'amount'         => $amount_cents,
			'currency'       => $currency,
			'customer'       => $customer_id,
			'payment_method' => $payment_method_id,
			'off_session'    => 'true',
			'confirm'        => 'true',
		);
		if ( $description ) {
			$params['description'] = $description;
		}
		foreach ( $metadata as $k => $v ) {
			$params[ 'metadata[' . $k . ']' ] = $v;
		}
		return self::request( 'POST', 'payment_intents', $params, $mode );
	}

	public static function get_payment_intent( $id, $mode = '' ) {
		return self::request( 'GET', 'payment_intents/' . rawurlencode( $id ), array(), $mode );
	}

	/**
	 * The Stripe product id a ticket should charge against for the given
	 * mode. Tickets store both catalogs (test + live) with a legacy
	 * single-catalog fallback.
	 */
	public static function ticket_product_id( $ticket, $mode ) {
		if ( ! is_array( $ticket ) ) {
			return '';
		}
		$key = 'live' === $mode ? 'stripe_product_id_live' : 'stripe_product_id_test';
		return (string) ( $ticket[ $key ] ?? '' ) ?: (string) ( $ticket['stripe_product_id'] ?? '' );
	}

	/**
	 * Verify a Stripe webhook signature (Stripe-Signature header).
	 */
	public static function verify_webhook( $payload, $sig_header, $secret, $tolerance = 300 ) {
		if ( ! $secret || ! $sig_header ) {
			return false;
		}
		$timestamp  = null;
		$signatures = array();
		foreach ( explode( ',', $sig_header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 't' === $pair[0] ) {
				$timestamp = (int) $pair[1];
			} elseif ( 'v1' === $pair[0] ) {
				$signatures[] = $pair[1];
			}
		}
		if ( ! $timestamp || empty( $signatures ) ) {
			return false;
		}
		if ( abs( time() - $timestamp ) > $tolerance ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		foreach ( $signatures as $sig ) {
			if ( hash_equals( $expected, $sig ) ) {
				return true;
			}
		}
		return false;
	}
}
