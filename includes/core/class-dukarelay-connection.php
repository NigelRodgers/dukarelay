<?php
/**
 * Connection: stores the WhatsApp Cloud API credentials and validates that the
 * connection actually works. Everything that sends, checks health, or runs the
 * setup wizard goes through this class first. Pure Core — no WooCommerce/order
 * knowledge.
 *
 * Security posture (see docs/dev/connection.md):
 * - Sensitive fields (access token, app secret) are encrypted at rest with a key
 *   derived from wp-config.php salts, so a database-only leak exposes nothing
 *   usable. Tokens cannot be one-way hashed — we must send Meta the original —
 *   so this is reversible encryption, not a password hash.
 * - Credentials never leave the site (Model A) and are never logged or displayed
 *   in cleartext (see get_masked()).
 *
 * @package DukaRelay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Credential storage + connection validation.
 */
class DukaRelay_Connection {

	/**
	 * Option key holding the (partly encrypted) credentials array.
	 */
	const OPTION_KEY = 'dukarelay_credentials';

	/**
	 * Graph API version. Filterable so we can move it without a release.
	 */
	const API_VERSION = 'v21.0';

	/**
	 * Credential fields that are encrypted at rest.
	 *
	 * @var string[]
	 */
	private $secret_fields = array( 'access_token', 'app_secret' );

	/**
	 * All recognised credential fields.
	 *
	 * @var string[]
	 */
	private $fields = array( 'access_token', 'phone_number_id', 'waba_id', 'app_secret', 'verify_token' );

	/**
	 * Get all credentials, decrypted, ready for use.
	 *
	 * @return array<string,string> Field => value; missing fields are empty strings.
	 */
	public function get_credentials() {
		$stored = get_option( self::OPTION_KEY, array() );
		$creds  = array();

		foreach ( $this->fields as $field ) {
			$value = isset( $stored[ $field ] ) ? $stored[ $field ] : '';
			if ( '' !== $value && in_array( $field, $this->secret_fields, true ) ) {
				$value = $this->decrypt( $value );
			}
			$creds[ $field ] = is_string( $value ) ? $value : '';
		}

		return $creds;
	}

	/**
	 * Save credentials. Secret fields are encrypted before storage; all values
	 * are sanitized. Only recognised fields are persisted.
	 *
	 * @param array<string,string> $input Raw field => value.
	 * @return void
	 */
	public function save_credentials( array $input ) {
		$to_store = array();

		foreach ( $this->fields as $field ) {
			if ( ! isset( $input[ $field ] ) ) {
				continue;
			}
			$value = sanitize_text_field( wp_unslash( $input[ $field ] ) );
			if ( '' === $value ) {
				continue;
			}
			if ( in_array( $field, $this->secret_fields, true ) ) {
				$value = $this->encrypt( $value );
			}
			$to_store[ $field ] = $value;
		}

		update_option( self::OPTION_KEY, $to_store, false );
	}

	/**
	 * Whether the minimum credentials to send are present. (Webhook-only fields
	 * such as verify_token/app_secret are not required just to send.)
	 *
	 * @return bool
	 */
	public function is_configured() {
		$creds = $this->get_credentials();
		return '' !== $creds['access_token'] && '' !== $creds['phone_number_id'];
	}

	/**
	 * A single credential value, decrypted.
	 *
	 * @param string $field Field name.
	 * @return string Empty string if unknown/unset.
	 */
	public function get( $field ) {
		$creds = $this->get_credentials();
		return isset( $creds[ $field ] ) ? $creds[ $field ] : '';
	}

	/**
	 * A masked version of a field, safe to show in the admin UI. Reveals only
	 * the last four characters, never the whole secret.
	 *
	 * @param string $field Field name.
	 * @return string e.g. "••••••1234", or '' if unset.
	 */
	public function get_masked( $field ) {
		$value = $this->get( $field );
		if ( '' === $value ) {
			return '';
		}
		$last = substr( $value, -4 );
		return str_repeat( '•', 6 ) . $last;
	}

	/**
	 * Validate the live connection by asking Meta for the Store Number's details.
	 * Distinguishes "credentials are bad" from "couldn't reach Meta" so we never
	 * cry wolf about the token when the real problem is the network.
	 *
	 * @return array{ok:bool,reason:string,detail:string,phone:string}
	 */
	public function validate() {
		$creds = $this->get_credentials();

		if ( '' === $creds['access_token'] || '' === $creds['phone_number_id'] ) {
			return $this->result( false, __( 'Not connected yet — missing credentials.', 'dukarelay' ) );
		}

		$version = apply_filters( 'dukarelay_graph_api_version', self::API_VERSION );
		$url     = sprintf(
			'https://graph.facebook.com/%s/%s?fields=display_phone_number,verified_name',
			rawurlencode( $version ),
			rawurlencode( $creds['phone_number_id'] )
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $creds['access_token'],
				),
			)
		);

		// Network / transport failure — not a credential problem.
		if ( is_wp_error( $response ) ) {
			return $this->result(
				false,
				__( 'Could not reach WhatsApp (network error). Your credentials may still be fine.', 'dukarelay' ),
				$response->get_error_message()
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code ) {
			$phone       = isset( $body['display_phone_number'] ) ? (string) $body['display_phone_number'] : '';
			$ok          = $this->result( true, __( 'Connected.', 'dukarelay' ) );
			$ok['phone'] = $phone;
			return $ok;
		}

		// Meta answered with an error — surface its message, mapped to plain words.
		$meta_message = isset( $body['error']['message'] ) ? (string) $body['error']['message'] : '';

		if ( 401 === $code || 403 === $code ) {
			return $this->result(
				false,
				__( 'Authentication failed — the access token is invalid or expired. Re-connect to fix it.', 'dukarelay' ),
				$meta_message
			);
		}

		if ( 404 === $code ) {
			return $this->result(
				false,
				__( 'The Phone Number ID was not found. Check you entered the Store Number, not another number.', 'dukarelay' ),
				$meta_message
			);
		}

		return $this->result(
			false,
			/* translators: %d: HTTP status code from Meta. */
			sprintf( __( 'WhatsApp returned an unexpected error (HTTP %d).', 'dukarelay' ), $code ),
			$meta_message
		);
	}

	/**
	 * Shape a validation result consistently.
	 *
	 * @param bool   $ok     Whether the connection is good.
	 * @param string $reason Plain-English, user-facing reason.
	 * @param string $detail Optional technical detail (e.g. Meta's message).
	 * @return array{ok:bool,reason:string,detail:string,phone:string}
	 */
	private function result( $ok, $reason, $detail = '' ) {
		return array(
			'ok'     => (bool) $ok,
			'reason' => $reason,
			'detail' => $detail,
			'phone'  => '',
		);
	}

	/**
	 * Whether at-rest encryption is available on this server (OpenSSL present).
	 * If false, secrets are stored unencrypted — the setup wizard / health check
	 * surfaces this as a warning rather than downgrading silently.
	 *
	 * @return bool
	 */
	public function is_encryption_available() {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' );
	}

	/**
	 * Encrypt a value for at-rest storage. AES-256-CBC with a random IV, keyed
	 * off wp-config salts. The IV is stored alongside the ciphertext (that is
	 * safe and required for decryption).
	 *
	 * @param string $plaintext Value to encrypt.
	 * @return string Base64 "iv:ciphertext", or the plaintext unchanged if
	 *                OpenSSL is unavailable (rare; still never logged/displayed).
	 */
	private function encrypt( $plaintext ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return $plaintext;
		}
		$iv_length  = openssl_cipher_iv_length( 'aes-256-cbc' );
		$iv         = openssl_random_pseudo_bytes( $iv_length );
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-cbc', $this->encryption_key(), OPENSSL_RAW_DATA, $iv );
		if ( false === $ciphertext ) {
			return $plaintext;
		}
		// base64 encodes the raw binary IV+ciphertext for text storage — not code obfuscation.
		return base64_encode( $iv . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Reverse encrypt().
	 *
	 * @param string $stored Base64 "iv:ciphertext".
	 * @return string Decrypted value, or the input unchanged if it wasn't
	 *                encrypted / OpenSSL is unavailable.
	 */
	private function decrypt( $stored ) {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return $stored;
		}
		// Decodes our own stored binary IV+ciphertext — not code obfuscation.
		$raw = base64_decode( $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw ) {
			return $stored;
		}
		$iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );
		if ( strlen( $raw ) <= $iv_length ) {
			return $stored;
		}
		$iv         = substr( $raw, 0, $iv_length );
		$ciphertext = substr( $raw, $iv_length );
		$plaintext  = openssl_decrypt( $ciphertext, 'aes-256-cbc', $this->encryption_key(), OPENSSL_RAW_DATA, $iv );
		return ( false === $plaintext ) ? $stored : $plaintext;
	}

	/**
	 * The encryption key: a dedicated DUKARELAY_ENCRYPTION_KEY constant if the
	 * site defines one, otherwise derived from WordPress's own salts (which live
	 * in wp-config.php, i.e. on the filesystem, not in the database).
	 *
	 * @return string 32-byte key for AES-256.
	 */
	private function encryption_key() {
		if ( defined( 'DUKARELAY_ENCRYPTION_KEY' ) && DUKARELAY_ENCRYPTION_KEY ) {
			return hash( 'sha256', DUKARELAY_ENCRYPTION_KEY, true );
		}
		return hash( 'sha256', wp_salt( 'auth' ), true );
	}
}
