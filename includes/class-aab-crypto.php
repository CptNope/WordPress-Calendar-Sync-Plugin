<?php
/**
 * Encrypts secrets (Google client secret and refresh token) at rest.
 *
 * Uses AES-256-GCM keyed from the site's auth salt, so a leaked database dump
 * alone does not expose your Google credentials. If the salts in wp-config.php
 * change, stored secrets can no longer be read and you will need to reconnect.
 *
 * @package AndersonAppointmentBooking
 */

defined( 'ABSPATH' ) || exit;

/**
 * Small symmetric-encryption helper.
 */
final class AAB_Crypto {

	const PREFIX = 'aab1:';

	/**
	 * Whether real encryption is available on this server.
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'openssl_encrypt' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true );
	}

	/**
	 * Derive the encryption key.
	 *
	 * @return string Raw 32-byte key.
	 */
	private static function key() {
		$salt = defined( 'AAB_ENCRYPTION_KEY' ) ? AAB_ENCRYPTION_KEY : wp_salt( 'auth' );
		return hash( 'sha256', 'anderson-appointment-booking|' . $salt, true );
	}

	/**
	 * Encrypt a string.
	 *
	 * @param string $plain Plain text.
	 * @return string Encoded cipher text, or '' for empty input.
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}
		if ( ! self::available() ) {
			// Fallback keeps the plugin usable on hosts without OpenSSL; the admin screen warns about it.
			return 'plain:' . base64_encode( $plain ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $cipher ) {
			return '';
		}
		return self::PREFIX . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a string produced by encrypt().
	 *
	 * @param string $encoded Encoded cipher text.
	 * @return string Plain text, or '' if it cannot be decrypted.
	 */
	public static function decrypt( $encoded ) {
		$encoded = (string) $encoded;
		if ( '' === $encoded ) {
			return '';
		}
		if ( 0 === strpos( $encoded, 'plain:' ) ) {
			return (string) base64_decode( substr( $encoded, 6 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		}
		if ( 0 !== strpos( $encoded, self::PREFIX ) || ! self::available() ) {
			return '';
		}
		$raw = base64_decode( substr( $encoded, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return '';
		}
		$iv     = substr( $raw, 0, 12 );
		$tag    = substr( $raw, 12, 16 );
		$cipher = substr( $raw, 28 );
		$plain  = openssl_decrypt( $cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		return false === $plain ? '' : $plain;
	}
}
