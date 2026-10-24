<?php
/**
 * Minimal Google Calendar API client (OAuth 2.0 + Calendar v3) built on the WordPress HTTP API.
 *
 * No Composer dependencies: it only needs the four endpoints this plugin uses.
 *
 * @package AndersonAppointmentBooking
 */

defined( 'ABSPATH' ) || exit;

/**
 * Talks to Google.
 */
final class AAB_Google {

	const TOKEN_OPTION   = 'aab_google';
	const ERROR_OPTION   = 'aab_google_error';
	const ACCESS_KEY     = 'aab_access_token';
	const CALENDARS_KEY  = 'aab_calendars';
	const AUTH_ENDPOINT  = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
	const REVOKE_URL     = 'https://oauth2.googleapis.com/revoke';
	const API            = 'https://www.googleapis.com/calendar/v3';

	/**
	 * Narrowest scopes that cover reading busy times, listing calendars and writing booking events.
	 *
	 * @return string[]
	 */
	public static function scopes() {
		return array(
			'https://www.googleapis.com/auth/calendar.events',
			'https://www.googleapis.com/auth/calendar.freebusy',
			'https://www.googleapis.com/auth/calendar.calendarlist.readonly',
		);
	}

	/**
	 * The redirect URI to register in Google Cloud Console.
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return admin_url( 'admin-post.php?action=aab_oauth' );
	}

	/**
	 * Whether a client ID and secret are configured.
	 *
	 * @return bool
	 */
	public static function has_credentials() {
		return '' !== AAB_Settings::client_id() && '' !== AAB_Settings::client_secret();
	}

	/**
	 * Whether a Google account is connected.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$data = get_option( self::TOKEN_OPTION, array() );
		return ! empty( $data['refresh_token'] ) && '' !== AAB_Crypto::decrypt( $data['refresh_token'] );
	}

	/**
	 * Connected account details for display.
	 *
	 * @return array{email:string,connected_at:int}
	 */
	public static function account() {
		$data = get_option( self::TOKEN_OPTION, array() );
		return array(
			'email'        => isset( $data['email'] ) ? (string) $data['email'] : '',
			'connected_at' => isset( $data['connected_at'] ) ? (int) $data['connected_at'] : 0,
		);
	}

	/**
	 * Build the consent screen URL.
	 *
	 * @param string $state Anti-CSRF state value.
	 * @return string
	 */
	public static function auth_url( $state ) {
		return add_query_arg(
			rawurlencode_deep(
				array(
					'client_id'              => AAB_Settings::client_id(),
					'redirect_uri'           => self::redirect_uri(),
					'response_type'          => 'code',
					'scope'                  => implode( ' ', self::scopes() ),
					'access_type'            => 'offline',
					'prompt'                 => 'consent',
					'include_granted_scopes' => 'true',
					'state'                  => $state,
				)
			),
			self::AUTH_ENDPOINT
		);
	}

	/**
	 * Exchange an authorization code for tokens and store the refresh token.
	 *
	 * @param string $code Authorization code.
	 * @return true|WP_Error
	 */
	public static function exchange_code( $code ) {
		$response = self::token_request(
			array(
				'grant_type'   => 'authorization_code',
				'code'         => $code,
				'redirect_uri' => self::redirect_uri(),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( empty( $response['refresh_token'] ) ) {
			return new WP_Error( 'aab_no_refresh', __( 'Google did not return a refresh token. Remove this app from your Google account’s third-party access page, then connect again.', 'anderson-appointment-booking' ) );
		}

		$granted = isset( $response['scope'] ) ? explode( ' ', $response['scope'] ) : self::scopes();
		$missing = array_diff( self::scopes(), $granted );
		if ( $missing ) {
			return new WP_Error( 'aab_scopes', __( 'Some calendar permissions were not granted. Connect again and leave every permission checked.', 'anderson-appointment-booking' ) );
		}

		update_option(
			self::TOKEN_OPTION,
			array(
				'refresh_token' => AAB_Crypto::encrypt( $response['refresh_token'] ),
				'email'         => '',
				'connected_at'  => time(),
			),
			false
		);
		self::cache_access_token( $response );
		delete_option( self::ERROR_OPTION );
		delete_transient( self::CALENDARS_KEY );

		// Record which account was connected (the primary calendar's ID is its email address).
		$primary = self::request( 'GET', '/calendars/primary' );
		if ( ! is_wp_error( $primary ) && ! empty( $primary['id'] ) ) {
			$data          = get_option( self::TOKEN_OPTION, array() );
			$data['email'] = sanitize_text_field( $primary['id'] );
			update_option( self::TOKEN_OPTION, $data, false );
		}
		return true;
	}

	/**
	 * Revoke and forget the connection.
	 */
	public static function disconnect() {
		$data  = get_option( self::TOKEN_OPTION, array() );
		$token = ! empty( $data['refresh_token'] ) ? AAB_Crypto::decrypt( $data['refresh_token'] ) : '';
		if ( '' !== $token ) {
			wp_remote_post(
				self::REVOKE_URL,
				array(
					'timeout' => 10,
					'body'    => array( 'token' => $token ),
				)
			);
		}
		delete_option( self::TOKEN_OPTION );
		delete_option( self::ERROR_OPTION );
		delete_transient( self::ACCESS_KEY );
		delete_transient( self::CALENDARS_KEY );
	}

	/**
	 * POST to the token endpoint.
	 *
	 * @param array $params Grant parameters.
	 * @return array|WP_Error Decoded response.
	 */
	private static function token_request( array $params ) {
		$params['client_id']     = AAB_Settings::client_id();
		$params['client_secret'] = AAB_Settings::client_secret();

		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'timeout' => 15,
				'body'    => $params,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 || ! is_array( $body ) || ! empty( $body['error'] ) ) {
			$error = is_array( $body ) && ! empty( $body['error'] ) ? (string) $body['error'] : 'http_' . $code;
			$desc  = is_array( $body ) && ! empty( $body['error_description'] ) ? (string) $body['error_description'] : $error;
			return new WP_Error( 'aab_token_' . sanitize_key( $error ), $desc, array( 'oauth_error' => $error ) );
		}
		return $body;
	}

	/**
	 * Store an access token until shortly before it expires.
	 *
	 * @param array $response Token response.
	 */
	private static function cache_access_token( array $response ) {
		if ( empty( $response['access_token'] ) ) {
			return;
		}
		$ttl = isset( $response['expires_in'] ) ? max( 60, (int) $response['expires_in'] - 120 ) : 3000;
		set_transient( self::ACCESS_KEY, AAB_Crypto::encrypt( $response['access_token'] ), $ttl );
	}

	/**
	 * A valid access token, refreshing when needed.
	 *
	 * @param bool $force Skip the cache.
	 * @return string|WP_Error
	 */
	public static function access_token( $force = false ) {
		if ( ! $force ) {
			$cached = AAB_Crypto::decrypt( (string) get_transient( self::ACCESS_KEY ) );
			if ( '' !== $cached ) {
				return $cached;
			}
		}
		$data    = get_option( self::TOKEN_OPTION, array() );
		$refresh = ! empty( $data['refresh_token'] ) ? AAB_Crypto::decrypt( $data['refresh_token'] ) : '';
		if ( '' === $refresh ) {
			return new WP_Error( 'aab_not_connected', __( 'Google Calendar is not connected.', 'anderson-appointment-booking' ) );
		}

		$response = self::token_request(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh,
			)
		);
		if ( is_wp_error( $response ) ) {
			$data = $response->get_error_data();
			if ( is_array( $data ) && isset( $data['oauth_error'] ) && 'invalid_grant' === $data['oauth_error'] ) {
				// The grant was revoked or expired: record it so the admin sees a reconnect notice.
				update_option( self::ERROR_OPTION, array( 'message' => $response->get_error_message(), 'time' => time() ), false );
			}
			return $response;
		}
		self::cache_access_token( $response );
		delete_option( self::ERROR_OPTION );
		return (string) $response['access_token'];
	}

	/**
	 * Call the Calendar API.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path under /calendar/v3.
	 * @param array|null $body   JSON body.
	 * @param array      $query  Query arguments.
	 * @param bool       $retry  Internal: retry once on 401.
	 * @return array|WP_Error Decoded JSON (empty array for 204).
	 */
	public static function request( $method, $path, $body = null, array $query = array(), $retry = true ) {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$url  = add_query_arg( rawurlencode_deep( $query ), self::API . $path );
		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $code && $retry ) {
			delete_transient( self::ACCESS_KEY );
			return self::request( $method, $path, $body, $query, false );
		}
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 ) {
			$message = is_array( $decoded ) && isset( $decoded['error']['message'] ) ? (string) $decoded['error']['message'] : 'HTTP ' . $code;
			return new WP_Error( 'aab_google_http', $message, array( 'status' => $code ) );
		}
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Calendars in the connected account.
	 *
	 * @param bool $refresh Ignore the cache.
	 * @return array<int,array{id:string,summary:string,primary:bool,writable:bool}>|WP_Error
	 */
	public static function calendars( $refresh = false ) {
		$cached = get_transient( self::CALENDARS_KEY );
		if ( ! $refresh && is_array( $cached ) ) {
			return $cached;
		}
		$out   = array();
		$page  = null;
		$guard = 0;
		do {
			$query = array( 'maxResults' => 250 );
			if ( $page ) {
				$query['pageToken'] = $page;
			}
			$result = self::request( 'GET', '/users/me/calendarList', null, $query );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			foreach ( isset( $result['items'] ) ? $result['items'] : array() as $item ) {
				$out[] = array(
					'id'       => (string) $item['id'],
					'summary'  => isset( $item['summaryOverride'] ) ? (string) $item['summaryOverride'] : (string) ( isset( $item['summary'] ) ? $item['summary'] : $item['id'] ),
					'primary'  => ! empty( $item['primary'] ),
					'writable' => isset( $item['accessRole'] ) && in_array( $item['accessRole'], array( 'owner', 'writer' ), true ),
				);
			}
			$page = isset( $result['nextPageToken'] ) ? $result['nextPageToken'] : null;
		} while ( $page && ++$guard < 10 );

		set_transient( self::CALENDARS_KEY, $out, 10 * MINUTE_IN_SECONDS );
		return $out;
	}

	/**
	 * Busy intervals across calendars.
	 *
	 * Fails closed: if any calendar can't be read, an error is returned so no slot is offered on stale data.
	 *
	 * @param int      $from         Unix start.
	 * @param int      $to           Unix end.
	 * @param string[] $calendar_ids Calendar IDs.
	 * @return array<int,array{0:int,1:int}>|WP_Error
	 */
	public static function busy( $from, $to, array $calendar_ids ) {
		$items = array();
		foreach ( array_unique( $calendar_ids ) as $id ) {
			$items[] = array( 'id' => $id );
		}
		$result = self::request(
			'POST',
			'/freeBusy',
			array(
				'timeMin' => gmdate( 'Y-m-d\TH:i:s\Z', $from ),
				'timeMax' => gmdate( 'Y-m-d\TH:i:s\Z', $to ),
				'items'   => $items,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$busy = array();
		foreach ( isset( $result['calendars'] ) ? $result['calendars'] : array() as $id => $calendar ) {
			if ( ! empty( $calendar['errors'] ) ) {
				/* translators: %s: calendar ID */
				return new WP_Error( 'aab_freebusy', sprintf( __( 'Could not read availability for calendar %s.', 'anderson-appointment-booking' ), $id ) );
			}
			foreach ( isset( $calendar['busy'] ) ? $calendar['busy'] : array() as $range ) {
				$start = strtotime( $range['start'] );
				$end   = strtotime( $range['end'] );
				if ( $start && $end && $end > $start ) {
					$busy[] = array( $start, $end );
				}
			}
		}
		return $busy;
	}

	/**
	 * Create an event.
	 *
	 * @param string $calendar_id  Calendar ID.
	 * @param array  $event        Event resource.
	 * @param bool   $send_updates Email guests.
	 * @return array|WP_Error Created event.
	 */
	public static function insert_event( $calendar_id, array $event, $send_updates ) {
		$query = array( 'sendUpdates' => $send_updates ? 'all' : 'none' );
		if ( isset( $event['conferenceData'] ) ) {
			$query['conferenceDataVersion'] = 1;
		}
		return self::request( 'POST', '/calendars/' . rawurlencode( $calendar_id ) . '/events', $event, $query );
	}

	/**
	 * Delete an event.
	 *
	 * @param string $calendar_id  Calendar ID.
	 * @param string $event_id     Event ID.
	 * @param bool   $send_updates Email guests about the cancellation.
	 * @return true|WP_Error
	 */
	public static function delete_event( $calendar_id, $event_id, $send_updates ) {
		$result = self::request(
			'DELETE',
			'/calendars/' . rawurlencode( $calendar_id ) . '/events/' . rawurlencode( $event_id ),
			null,
			array( 'sendUpdates' => $send_updates ? 'all' : 'none' )
		);
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			// Already gone on Google's side counts as success.
			if ( is_array( $data ) && isset( $data['status'] ) && in_array( (int) $data['status'], array( 404, 410 ), true ) ) {
				return true;
			}
			return $result;
		}
		return true;
	}
}
