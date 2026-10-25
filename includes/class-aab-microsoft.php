<?php
/**
 * Microsoft 365 / Outlook.com calendars as extra conflict calendars (read-only).
 *
 * Busy times come from Microsoft Graph's calendarView, which expands recurring meetings
 * on Microsoft's side. Events marked Busy, Tentative or Out of office block slots;
 * Free and Working elsewhere don't. Only start, end and show-as are read, never titles.
 *
 * @package AndersonAppointmentBooking
 */

defined( 'ABSPATH' ) || exit;

/**
 * Talks to Microsoft Graph.
 */
final class AAB_Microsoft {

	const TOKEN_OPTION  = 'aab_ms';
	const ERROR_OPTION  = 'aab_ms_error';
	const ACCESS_KEY    = 'aab_ms_access';
	const CALENDARS_KEY = 'aab_ms_calendars';
	const AUTHORITY     = 'https://login.microsoftonline.com/common/oauth2/v2.0';
	const GRAPH         = 'https://graph.microsoft.com/v1.0';

	/**
	 * Delegated scopes: read calendars, stay signed in, know who connected.
	 *
	 * @return string
	 */
	public static function scopes() {
		return 'offline_access User.Read Calendars.Read';
	}

	/**
	 * Redirect URI to register in Microsoft Entra (Azure).
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return admin_url( 'admin-post.php?action=aab_ms_oauth' );
	}

	/**
	 * Application (client) ID.
	 *
	 * @return string
	 */
	public static function client_id() {
		return defined( 'AAB_MS_CLIENT_ID' ) ? (string) AAB_MS_CLIENT_ID : (string) AAB_Settings::get( 'ms_client_id' );
	}

	/**
	 * Client secret value.
	 *
	 * @return string
	 */
	public static function client_secret() {
		return defined( 'AAB_MS_CLIENT_SECRET' ) ? (string) AAB_MS_CLIENT_SECRET : AAB_Crypto::decrypt( (string) AAB_Settings::get( 'ms_client_secret' ) );
	}

	/**
	 * Are credentials saved?
	 *
	 * @return bool
	 */
	public static function has_credentials() {
		return '' !== self::client_id() && '' !== self::client_secret();
	}

	/**
	 * Is an account connected?
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$data = get_option( self::TOKEN_OPTION, array() );
		return ! empty( $data['refresh_token'] ) && '' !== AAB_Crypto::decrypt( $data['refresh_token'] );
	}

	/**
	 * Is it connected and set to check at least one calendar?
	 *
	 * @return bool
	 */
	public static function active() {
		return self::is_connected() && (bool) array_filter( (array) AAB_Settings::get( 'ms_calendars' ) );
	}

	/**
	 * Connected account.
	 *
	 * @return string
	 */
	public static function account() {
		$data = get_option( self::TOKEN_OPTION, array() );
		return isset( $data['email'] ) ? (string) $data['email'] : '';
	}

	/**
	 * Sign-in URL.
	 *
	 * @param string $state Anti-CSRF state.
	 * @return string
	 */
	public static function auth_url( $state ) {
		return add_query_arg(
			rawurlencode_deep(
				array(
					'client_id'     => self::client_id(),
					'response_type' => 'code',
					'redirect_uri'  => self::redirect_uri(),
					'response_mode' => 'query',
					'scope'         => self::scopes(),
					'state'         => $state,
					'prompt'        => 'select_account',
				)
			),
			self::AUTHORITY . '/authorize'
		);
	}

	/**
	 * POST to the token endpoint.
	 *
	 * @param array $params Grant params.
	 * @return array|WP_Error
	 */
	private static function token_request( array $params ) {
		$params['client_id']     = self::client_id();
		$params['client_secret'] = self::client_secret();
		$params['scope']         = self::scopes();
		$response                = wp_remote_post(
			self::AUTHORITY . '/token',
			array(
				'timeout' => 15,
				'body'    => $params,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! empty( $body['error'] ) || empty( $body['access_token'] ) ) {
			$error = is_array( $body ) && ! empty( $body['error'] ) ? (string) $body['error'] : 'http_' . wp_remote_retrieve_response_code( $response );
			$desc  = is_array( $body ) && ! empty( $body['error_description'] ) ? strtok( (string) $body['error_description'], "\r\n" ) : $error;
			return new WP_Error( 'aab_ms_token', $desc, array( 'oauth_error' => $error ) );
		}
		return $body;
	}

	/**
	 * Store tokens from a token response. Microsoft rotates refresh tokens, so keep the newest.
	 *
	 * @param array $body Token response.
	 */
	private static function store_tokens( array $body ) {
		$data = get_option( self::TOKEN_OPTION, array() );
		$data = is_array( $data ) ? $data : array();
		if ( ! empty( $body['refresh_token'] ) ) {
			$data['refresh_token'] = AAB_Crypto::encrypt( (string) $body['refresh_token'] );
		}
		update_option( self::TOKEN_OPTION, $data, false );
		$ttl = isset( $body['expires_in'] ) ? max( 60, (int) $body['expires_in'] - 120 ) : 3000;
		set_transient( self::ACCESS_KEY, AAB_Crypto::encrypt( (string) $body['access_token'] ), $ttl );
	}

	/**
	 * Finish sign-in.
	 *
	 * @param string $code Authorization code.
	 * @return true|WP_Error
	 */
	public static function exchange_code( $code ) {
		$body = self::token_request(
			array(
				'grant_type'   => 'authorization_code',
				'code'         => $code,
				'redirect_uri' => self::redirect_uri(),
			)
		);
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		if ( empty( $body['refresh_token'] ) ) {
			return new WP_Error( 'aab_ms_no_refresh', __( 'Microsoft didn’t allow staying signed in. Make sure the app has the offline_access permission, then connect again.', 'anderson-appointment-booking' ) );
		}
		update_option( self::TOKEN_OPTION, array( 'connected_at' => time() ), false );
		self::store_tokens( $body );
		delete_option( self::ERROR_OPTION );
		delete_transient( self::CALENDARS_KEY );

		$me = self::request( '/me?$select=mail,userPrincipalName' );
		if ( ! is_wp_error( $me ) ) {
			$data          = get_option( self::TOKEN_OPTION, array() );
			$data['email'] = sanitize_text_field( ! empty( $me['mail'] ) ? $me['mail'] : ( isset( $me['userPrincipalName'] ) ? $me['userPrincipalName'] : '' ) );
			update_option( self::TOKEN_OPTION, $data, false );
		}
		return true;
	}

	/**
	 * Forget the connection (Microsoft has no token revocation endpoint for this flow).
	 */
	public static function disconnect() {
		delete_option( self::TOKEN_OPTION );
		delete_option( self::ERROR_OPTION );
		delete_transient( self::ACCESS_KEY );
		delete_transient( self::CALENDARS_KEY );
	}

	/**
	 * A valid access token.
	 *
	 * @return string|WP_Error
	 */
	private static function access_token() {
		$cached = AAB_Crypto::decrypt( (string) get_transient( self::ACCESS_KEY ) );
		if ( '' !== $cached ) {
			return $cached;
		}
		$data    = get_option( self::TOKEN_OPTION, array() );
		$refresh = ! empty( $data['refresh_token'] ) ? AAB_Crypto::decrypt( $data['refresh_token'] ) : '';
		if ( '' === $refresh ) {
			return new WP_Error( 'aab_ms_not_connected', __( 'Outlook is not connected.', 'anderson-appointment-booking' ) );
		}
		$body = self::token_request(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh,
			)
		);
		if ( is_wp_error( $body ) ) {
			$err = $body->get_error_data();
			if ( is_array( $err ) && isset( $err['oauth_error'] ) && in_array( $err['oauth_error'], array( 'invalid_grant', 'interaction_required' ), true ) ) {
				update_option(
					self::ERROR_OPTION,
					array(
						'message' => $body->get_error_message(),
						'time'    => time(),
					),
					false
				);
			}
			AAB_Google::log( 'Outlook token', $body->get_error_message() );
			return $body;
		}
		self::store_tokens( $body );
		delete_option( self::ERROR_OPTION );
		return (string) $body['access_token'];
	}

	/**
	 * GET from Graph (absolute nextLink URLs are accepted too).
	 *
	 * @param string $path    Path under /v1.0, or a full graph.microsoft.com URL.
	 * @param bool   $retried Internal.
	 * @return array|WP_Error
	 */
	public static function request( $path, $retried = false ) {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$url = 0 === strpos( $path, 'https://graph.microsoft.com/' ) ? $path : self::GRAPH . $path;
		for ( $attempt = 1; $attempt <= 2; $attempt++ ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout' => 10,
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'Accept'        => 'application/json',
						// Ask for UTC times so no timezone mapping is needed.
						'Prefer'        => 'outlook.timezone="UTC"',
					),
				)
			);
			$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			if ( ( is_wp_error( $response ) || 429 === $code || $code >= 500 ) && 1 === $attempt ) {
				usleep( 500000 );
				continue;
			}
			break;
		}
		if ( 401 === $code && ! $retried ) {
			delete_transient( self::ACCESS_KEY );
			return self::request( $path, true );
		}
		if ( is_wp_error( $response ) ) {
			AAB_Google::log( 'Outlook GET', $response->get_error_message() );
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 || ! is_array( $body ) ) {
			$message = is_array( $body ) && isset( $body['error']['message'] ) ? (string) $body['error']['message'] : 'HTTP ' . $code;
			AAB_Google::log( 'Outlook GET', $code . ' ' . $message );
			return new WP_Error( 'aab_ms_http', $message, array( 'status' => $code ) );
		}
		return $body;
	}

	/**
	 * Calendars in the connected account.
	 *
	 * @param bool $refresh Skip cache.
	 * @return array<int,array{id:string,name:string,default:bool}>|WP_Error
	 */
	public static function calendars( $refresh = false ) {
		$cached = get_transient( self::CALENDARS_KEY );
		if ( ! $refresh && is_array( $cached ) ) {
			return $cached;
		}
		$out  = array();
		$next = '/me/calendars?$select=id,name,isDefaultCalendar&$top=100';
		for ( $page = 0; $next && $page < 5; $page++ ) {
			$body = self::request( $next );
			if ( is_wp_error( $body ) ) {
				return $body;
			}
			foreach ( isset( $body['value'] ) ? $body['value'] : array() as $cal ) {
				$out[] = array(
					'id'      => (string) $cal['id'],
					'name'    => (string) ( isset( $cal['name'] ) ? $cal['name'] : $cal['id'] ),
					'default' => ! empty( $cal['isDefaultCalendar'] ),
				);
			}
			$next = isset( $body['@odata.nextLink'] ) ? (string) $body['@odata.nextLink'] : '';
		}
		set_transient( self::CALENDARS_KEY, $out, 10 * MINUTE_IN_SECONDS );
		return $out;
	}

	/**
	 * Busy intervals from the selected Outlook calendars. Fails closed like Google.
	 *
	 * @param int $from Unix start.
	 * @param int $to   Unix end.
	 * @return array<int,array{0:int,1:int}>|WP_Error
	 */
	public static function busy( $from, $to ) {
		$busy = array();
		foreach ( array_filter( (array) AAB_Settings::get( 'ms_calendars' ) ) as $cal_id ) {
			$next = '/me/calendars/' . rawurlencode( $cal_id ) . '/calendarView?' . http_build_query(
				array(
					'startDateTime' => gmdate( 'Y-m-d\TH:i:s\Z', $from ),
					'endDateTime'   => gmdate( 'Y-m-d\TH:i:s\Z', $to ),
					'$select'       => 'start,end,showAs,isCancelled',
					'$top'          => 250,
				),
				'',
				'&',
				PHP_QUERY_RFC3986
			);
			for ( $page = 0; $next && $page < 10; $page++ ) {
				$body = self::request( $next );
				if ( is_wp_error( $body ) ) {
					/* translators: %s: error */
					return new WP_Error( 'aab_ms_busy', sprintf( __( 'Could not read Outlook availability: %s', 'anderson-appointment-booking' ), $body->get_error_message() ) );
				}
				foreach ( isset( $body['value'] ) ? $body['value'] : array() as $ev ) {
					$show = isset( $ev['showAs'] ) ? strtolower( (string) $ev['showAs'] ) : 'busy';
					if ( ! empty( $ev['isCancelled'] ) || ! in_array( $show, array( 'busy', 'tentative', 'oof', 'unknown' ), true ) ) {
						continue;
					}
					$start = self::utc( isset( $ev['start']['dateTime'] ) ? $ev['start']['dateTime'] : '' );
					$end   = self::utc( isset( $ev['end']['dateTime'] ) ? $ev['end']['dateTime'] : '' );
					if ( $start && $end && $end > $start ) {
						$busy[] = array( $start, $end );
					}
				}
				$next = isset( $body['@odata.nextLink'] ) ? (string) $body['@odata.nextLink'] : '';
			}
		}
		return $busy;
	}

	/**
	 * Parse Graph's "2026-10-27T13:00:00.0000000" (UTC, no zone suffix).
	 *
	 * @param string $value Date-time.
	 * @return int|false
	 */
	private static function utc( $value ) {
		if ( '' === (string) $value ) {
			return false;
		}
		$value = preg_replace( '/\.\d+$/', '', (string) $value );
		return strtotime( preg_match( '/(Z|[+-]\d{2}:\d{2})$/', $value ) ? $value : $value . 'Z' );
	}
}
