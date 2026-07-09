<?php
/**
 * Settings: the store for the owner's non-secret preferences (Primary Number,
 * forward/auto-reply toggles, enabled modules). Secrets live in Connection, not
 * here. One option row, typed lookups, defaults for everything. See
 * docs/dev/settings.md.
 *
 * @package DukaRelay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Preference storage with defaults and per-key sanitising.
 */
class DukaRelay_Settings {

	const OPTION_KEY = 'dukarelay_settings';

	/**
	 * Default values for every known setting. Callers always get one of these
	 * when a value is unset, so no setting is ever "undefined".
	 *
	 * @var array<string,mixed>
	 */
	private $defaults = array(
		'primary_number'     => '',
		'forward_enabled'    => true,
		'auto_reply_enabled' => true,
		'auto_reply_text'    => '',
		'enabled_modules'    => array( 'woocommerce' => true ),
	);

	/**
	 * Get a setting, falling back to its default when unset.
	 *
	 * @param string $key Setting key.
	 * @return mixed Null if the key is unknown and has no default.
	 */
	public function get( $key ) {
		$all = get_option( self::OPTION_KEY, array() );
		if ( is_array( $all ) && array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}
		return array_key_exists( $key, $this->defaults ) ? $this->defaults[ $key ] : null;
	}

	/**
	 * All settings, defaults merged under any stored values.
	 *
	 * @return array<string,mixed>
	 */
	public function get_all() {
		$all = get_option( self::OPTION_KEY, array() );
		return array_merge( $this->defaults, is_array( $all ) ? $all : array() );
	}

	/**
	 * Update one or more settings. Unknown keys are ignored; each value is
	 * sanitised for its type before storage.
	 *
	 * @param array<string,mixed> $values Key => value.
	 * @return void
	 */
	public function update( array $values ) {
		$all = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}

		foreach ( $values as $key => $value ) {
			if ( ! array_key_exists( $key, $this->defaults ) ) {
				continue;
			}
			$all[ $key ] = $this->sanitize( $key, $value );
		}

		update_option( self::OPTION_KEY, $all, false );
	}

	/**
	 * The Primary Number (E.164), where inbound messages are forwarded.
	 *
	 * @return string
	 */
	public function get_primary_number() {
		return (string) $this->get( 'primary_number' );
	}

	/**
	 * Whether a module is enabled.
	 *
	 * @param string $module Module slug, e.g. 'woocommerce'.
	 * @return bool
	 */
	public function is_module_enabled( $module ) {
		$modules = $this->get( 'enabled_modules' );
		return is_array( $modules ) && ! empty( $modules[ $module ] );
	}

	/**
	 * Sanitise a value according to its setting key.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Raw value.
	 * @return mixed
	 */
	private function sanitize( $key, $value ) {
		switch ( $key ) {
			case 'primary_number':
				return $this->normalise_number( (string) $value );

			case 'forward_enabled':
			case 'auto_reply_enabled':
				return (bool) $value;

			case 'auto_reply_text':
				return sanitize_textarea_field( (string) $value );

			case 'enabled_modules':
				$out = array();
				if ( is_array( $value ) ) {
					foreach ( $value as $module => $on ) {
						$out[ sanitize_key( $module ) ] = (bool) $on;
					}
				}
				return $out;

			default:
				return $value;
		}
	}

	/**
	 * Normalise a phone number toward E.164 (keep a leading +, strip the rest).
	 *
	 * @param string $number Raw number.
	 * @return string
	 */
	private function normalise_number( $number ) {
		$number = trim( $number );
		if ( '' === $number ) {
			return '';
		}
		$has_plus = ( 0 === strpos( $number, '+' ) );
		$digits   = preg_replace( '/\D+/', '', $number );
		return $has_plus ? '+' . $digits : $digits;
	}
}
