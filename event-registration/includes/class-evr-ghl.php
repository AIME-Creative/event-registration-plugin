<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * GoHighLevel API v2 client. Each event carries its own location ID,
 * pipeline ID, stage ID, and (optionally) its own access token, so events
 * can sync to entirely different GHL sub-accounts.
 */
class EVR_GHL {

	const API_BASE    = 'https://services.leadconnectorhq.com';
	const API_VERSION = '2021-07-28';

	public static function request( $method, $path, $body = null, $token = '' ) {
		$token = $token ?: EVR_Settings::get( 'ghl_token' );
		if ( ! $token ) {
			return new WP_Error( 'evr_ghl', 'GoHighLevel token is not configured.' );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Version'       => self::API_VERSION,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::API_BASE . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			$msg = isset( $data['message'] ) ? ( is_array( $data['message'] ) ? implode( '; ', $data['message'] ) : $data['message'] ) : 'GHL API error (HTTP ' . $code . ').';
			return new WP_Error( 'evr_ghl', $msg, $data );
		}
		return $data;
	}

	/**
	 * All custom fields for a location — contact AND opportunity models.
	 */
	public static function get_custom_fields( $location_id, $token = '' ) {
		$result = self::request( 'GET', '/locations/' . rawurlencode( $location_id ) . '/customFields?model=all', null, $token );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$fields = array();
		foreach ( (array) ( $result['customFields'] ?? array() ) as $f ) {
			// Option lists for dropdown/radio/multi-select fields.
			$options = array();
			foreach ( (array) ( $f['picklistOptions'] ?? array() ) as $opt ) {
				$options[] = is_array( $opt ) ? (string) ( $opt['label'] ?? ( $opt['value'] ?? '' ) ) : (string) $opt;
			}
			$fields[] = array(
				'id'        => $f['id'],
				'name'      => $f['name'] ?? ( $f['fieldKey'] ?? $f['id'] ),
				'model'     => ( $f['model'] ?? 'contact' ) === 'opportunity' ? 'opportunity' : 'contact',
				'data_type' => $f['dataType'] ?? '',
				'options'   => array_values( array_filter( $options ) ),
			);
		}
		return $fields;
	}

	/**
	 * Standard contact-record fields that form answers can map onto
	 * (mapping value "contact.<key>").
	 */
	public static function standard_contact_fields() {
		return array(
			'companyName' => 'Company name',
			'address1'    => 'Street address',
			'city'        => 'City',
			'state'       => 'State',
			'postalCode'  => 'Postal code',
			'country'     => 'Country',
			'website'     => 'Website',
			'dateOfBirth' => 'Date of birth',
			'gender'      => 'Gender',
			'timezone'    => 'Timezone',
			'source'      => 'Contact source',
		);
	}

	public static function get_pipelines( $location_id, $token = '' ) {
		$result = self::request( 'GET', '/opportunities/pipelines?locationId=' . rawurlencode( $location_id ), null, $token );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$pipelines = array();
		foreach ( (array) ( $result['pipelines'] ?? array() ) as $p ) {
			$stages = array();
			foreach ( (array) ( $p['stages'] ?? array() ) as $s ) {
				$stages[] = array( 'id' => $s['id'], 'name' => $s['name'] );
			}
			$pipelines[] = array( 'id' => $p['id'], 'name' => $p['name'], 'stages' => $stages );
		}
		return $pipelines;
	}

	/**
	 * Push a registration to GHL: upsert contact (with mapped custom
	 * fields + tags), then create an opportunity in the event's pipeline.
	 * Safe to re-run (retry) — it records errors on the registration row.
	 */
	public static function sync_registration( $registration_id ) {
		$reg = EVR_DB::get_registration( $registration_id );
		if ( ! $reg ) {
			return new WP_Error( 'evr_ghl', 'Registration not found.' );
		}
		$event = EVR_DB::get_event( $reg['event_id'] );
		if ( ! $event ) {
			return new WP_Error( 'evr_ghl', 'Event not found.' );
		}

		$ghl = $event['config']['ghl'];
		if ( empty( $ghl['location_id'] ) ) {
			EVR_DB::update_registration( $registration_id, array( 'ghl_sync_status' => 'skipped' ) );
			return true; // GHL not configured for this event — nothing to do.
		}
		$token = ! empty( $ghl['token_override'] ) ? $ghl['token_override'] : '';

		// Route each mapped answer to its GHL destination. Mapping formats:
		//   contact.<key>        standard contact field (e.g. contact.companyName)
		//   cf_contact:<id>      contact custom field
		//   cf_opportunity:<id>  opportunity custom field
		//   opportunity.source   opportunity source
		//   <bare id>            legacy: contact custom field
		$answers          = json_decode( $reg['custom_fields'], true ) ?: array();
		$standard_keys    = self::standard_contact_fields();
		$contact_standard = array();
		$custom_fields    = array(); // Contact custom fields.
		$opp_custom       = array();
		$opp_source       = '';

		$route = function ( $map, $value ) use ( $standard_keys, &$contact_standard, &$custom_fields, &$opp_custom, &$opp_source ) {
			$value = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
			if ( ! $map || '' === $value ) {
				return;
			}
			if ( 0 === strpos( $map, 'contact.' ) ) {
				$key = substr( $map, 8 );
				if ( isset( $standard_keys[ $key ] ) ) {
					$contact_standard[ $key ] = $value;
				}
			} elseif ( 'opportunity.source' === $map ) {
				$opp_source = $value;
			} elseif ( 0 === strpos( $map, 'cf_opportunity:' ) ) {
				$opp_custom[] = array( 'id' => substr( $map, 15 ), 'value' => $value );
			} elseif ( 0 === strpos( $map, 'cf_contact:' ) ) {
				$custom_fields[] = array( 'id' => substr( $map, 11 ), 'value' => $value );
			} else {
				$custom_fields[] = array( 'id' => $map, 'value' => $value );
			}
		};

		foreach ( (array) $event['config']['fields'] as $field ) {
			if ( empty( $field['ghl_field_id'] ) || ! isset( $answers[ $field['key'] ] ) ) {
				continue;
			}
			$route( $field['ghl_field_id'], $answers[ $field['key'] ] );
		}

		// UTM tags captured at signup, mapped per event.
		$utm = json_decode( $reg['utm'] ?? '', true ) ?: array();
		foreach ( (array) ( $event['config']['utm_mappings'] ?? array() ) as $param => $map ) {
			if ( $map && ! empty( $utm[ $param ] ) ) {
				$route( $map, $utm[ $param ] );
			}
		}

		$tags = array_filter( array_map( 'trim', explode( ',', (string) $ghl['tags'] ) ) );

		$contact_body = array(
			'locationId' => $ghl['location_id'],
			'email'      => $reg['email'],
			'firstName'  => $reg['first_name'],
			'lastName'   => $reg['last_name'],
		);
		if ( $reg['phone'] ) {
			$contact_body['phone'] = $reg['phone'];
		}
		foreach ( $contact_standard as $key => $value ) {
			$contact_body[ $key ] = $value;
		}
		if ( $custom_fields ) {
			$contact_body['customFields'] = $custom_fields;
		}

		// NB: tags are deliberately NOT sent in the upsert body — the contact
		// upsert/update endpoint REPLACES the contact's tag array with whatever
		// it is given, so doing that here would wipe tags added by GHL
		// workflows, other events, or by hand. They are added additively below.
		$contact = self::request( 'POST', '/contacts/upsert', $contact_body, $token );
		if ( is_wp_error( $contact ) ) {
			EVR_DB::update_registration( $registration_id, array(
				'ghl_sync_status' => 'failed',
				'ghl_sync_error'  => 'Contact upsert: ' . $contact->get_error_message(),
			) );
			return $contact;
		}

		$contact_id = $contact['contact']['id'] ?? '';
		$update     = array( 'ghl_contact_id' => $contact_id );

		// Tags, added one-way via the dedicated endpoint so existing tags on the
		// contact survive. Idempotent — re-adding a tag the contact already has
		// is a no-op, which matters because this sync re-runs (payment webhook,
		// abandoned-cart sweep, plan completion, manual retry).
		$tag_error = '';
		if ( $contact_id && $tags ) {
			$tag_result = self::request(
				'POST',
				'/contacts/' . rawurlencode( $contact_id ) . '/tags',
				array( 'tags' => array_values( $tags ) ),
				$token
			);
			if ( is_wp_error( $tag_result ) ) {
				// Non-fatal: the opportunity still matters more than the tags,
				// so carry on and report it at the end.
				$tag_error = 'Tags: ' . $tag_result->get_error_message();
			}
		}

		// Target pipeline + stage depends on the registration's state:
		//   waitlist  -> waitlist stage (optionally its own pipeline)
		//   pending   -> abandoned-cart stage (same pipeline) — set by the cron sweep
		//   confirmed -> the event's normal "registered" stage, UNLESS it's on a
		//                payment plan: then the "Outstanding payment" stage while
		//                the plan is still running, moving to "Fully paid" once
		//                the final installment is collected.
		// A pending cart that later completes keeps the SAME opportunity and is
		// just moved to the next stage (no duplicate).
		$waitlist = $event['config']['waitlist'] ?? array();
		$suffix   = '';
		if ( 'waitlist' === $reg['status'] ) {
			$pipeline_id = ( $waitlist['pipeline_id'] ?? '' ) ?: $ghl['pipeline_id'];
			$stage_id    = $waitlist['stage_id'] ?? '';
			$suffix      = ' (Waitlist)';
		} elseif ( 'pending' === $reg['status'] ) {
			$pipeline_id = $ghl['pipeline_id'];
			$stage_id    = $ghl['abandoned_stage_id'] ?? '';
			$suffix      = ' (Abandoned cart)';
		} else {
			$pipeline_id = $ghl['pipeline_id'];
			$stage_id    = $ghl['stage_id'];
			// 'canceled' counts as outstanding too: the scheduled payments were
			// called off, but the balance itself is still to be collected.
			if ( in_array( $reg['plan_status'], array( 'active', 'defaulted', 'canceled' ), true ) && ! empty( $ghl['outstanding_stage_id'] ) ) {
				$stage_id = $ghl['outstanding_stage_id'];
				$suffix   = ' (Outstanding payment)';
			} elseif ( 'completed' === $reg['plan_status'] && ! empty( $ghl['fully_paid_stage_id'] ) ) {
				$stage_id = $ghl['fully_paid_stage_id'];
				$suffix   = ' (Fully paid)';
			} elseif ( 'invoiced' === ( $reg['payment_state'] ?? '' ) && ! empty( $ghl['outstanding_stage_id'] ) ) {
				// Added by hand and being invoiced separately — the balance is
				// owed, it's just collected outside the plugin.
				$stage_id = $ghl['outstanding_stage_id'];
				$suffix   = ' (Outstanding payment)';
			}
		}

		// Opportunity: create one if none exists yet, otherwise update the
		// existing one (this is what moves an abandoned cart into the
		// registered stage once payment completes).
		if ( $contact_id && $pipeline_id && $stage_id ) {
			$opp_body = array(
				'pipelineId'      => $pipeline_id,
				'pipelineStageId' => $stage_id,
				'name'            => trim( $reg['first_name'] . ' ' . $reg['last_name'] ) . ' — ' . $event['title'] . $suffix,
				'status'          => 'open',
				'monetaryValue'   => round( $reg['amount_cents'] / 100, 2 ),
			);
			if ( $opp_source ) {
				$opp_body['source'] = $opp_source;
			}
			if ( $opp_custom ) {
				$opp_body['customFields'] = $opp_custom;
			}

			if ( empty( $reg['ghl_opportunity_id'] ) ) {
				$opp_body['locationId'] = $ghl['location_id'];
				$opp_body['contactId']  = $contact_id;
				$opp = self::request( 'POST', '/opportunities/', $opp_body, $token );
			} else {
				$opp = self::request( 'PUT', '/opportunities/' . rawurlencode( $reg['ghl_opportunity_id'] ), $opp_body, $token );
			}

			if ( is_wp_error( $opp ) ) {
				$update['ghl_sync_status'] = 'failed';
				$update['ghl_sync_error']  = 'Opportunity: ' . $opp->get_error_message();
				EVR_DB::update_registration( $registration_id, $update );
				return $opp;
			}
			if ( ! empty( $opp['opportunity']['id'] ) ) {
				$update['ghl_opportunity_id'] = $opp['opportunity']['id'];
			}
		}

		if ( $tag_error ) {
			$update['ghl_sync_status'] = 'failed';
			$update['ghl_sync_error']  = $tag_error;
			EVR_DB::update_registration( $registration_id, $update );
			return new WP_Error( 'evr_ghl', $tag_error );
		}

		$update['ghl_sync_status'] = 'synced';
		$update['ghl_sync_error']  = '';
		EVR_DB::update_registration( $registration_id, $update );
		return true;
	}

	/**
	 * Cron sweep: push genuinely abandoned carts (still pending after the
	 * delay, default 24h) into their event's abandoned-cart stage. Runs hourly.
	 * Each is only pushed once — sync_registration stamps the opportunity ID,
	 * which excludes it from the next sweep. If/when the person completes
	 * payment, confirm_registration moves that same opportunity to the
	 * registered stage.
	 */
	public static function sweep_abandoned_carts() {
		$delay  = (int) apply_filters( 'evr_abandoned_cart_delay', DAY_IN_SECONDS );
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $delay );
		$rows   = EVR_DB::get_abandoned_candidates( $cutoff, 50 );
		foreach ( $rows as $reg ) {
			$event = EVR_DB::get_event( $reg['event_id'] );
			if ( ! $event ) {
				continue;
			}
			// Only events that opted in by setting an abandoned-cart stage.
			if ( empty( $event['config']['ghl']['abandoned_stage_id'] ) || empty( $event['config']['ghl']['location_id'] ) ) {
				continue;
			}
			self::sync_registration( (int) $reg['id'] );
		}
	}
}
