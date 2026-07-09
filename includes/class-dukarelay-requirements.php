<?php
/**
 * Requirements: the server-compatibility gate. Checks the hard prerequisites and
 * is used at activation to refuse install on servers we cannot serve safely
 * (e.g. no OpenSSL = we cannot store credentials encrypted). "We can't serve
 * such users" — decided 2026-07-09.
 *
 * @package DukaRelay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hard prerequisite checks.
 */
class DukaRelay_Requirements {

	const MIN_PHP = '7.4';
	const MIN_WP  = '6.4';

	/**
	 * Return the list of unmet requirements (human-readable). Empty = all good.
	 *
	 * @return string[]
	 */
	public static function unmet() {
		$unmet = array();

		if ( version_compare( PHP_VERSION, self::MIN_PHP, '<' ) ) {
			/* translators: %s: required PHP version. */
			$unmet[] = sprintf( __( 'PHP %s or newer is required.', 'dukarelay' ), self::MIN_PHP );
		}

		global $wp_version;
		if ( isset( $wp_version ) && version_compare( $wp_version, self::MIN_WP, '<' ) ) {
			/* translators: %s: required WordPress version. */
			$unmet[] = sprintf( __( 'WordPress %s or newer is required.', 'dukarelay' ), self::MIN_WP );
		}

		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) ) {
			$unmet[] = __( 'The OpenSSL PHP extension is required to store your WhatsApp credentials securely.', 'dukarelay' );
		}

		if ( ! function_exists( 'hash_hmac' ) ) {
			$unmet[] = __( 'The PHP hash extension is required to verify incoming webhooks.', 'dukarelay' );
		}

		return $unmet;
	}

	/**
	 * Whether the server meets all requirements.
	 *
	 * @return bool
	 */
	public static function met() {
		return array() === self::unmet();
	}
}
