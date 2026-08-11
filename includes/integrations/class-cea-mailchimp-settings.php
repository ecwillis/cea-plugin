<?php
/**
 * Mailchimp Marketing API settings storage.
 *
 * @package CEA_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages the site-level Mailchimp Marketing API connection.
 */
final class CEA_Mailchimp_Settings {

	/** Settings API group. */
	const GROUP = 'cea_mailchimp';

	/** General Mailchimp settings option. */
	const OPTION_NAME = 'cea_mailchimp_settings';

	/** Mailchimp API key option. */
	const API_KEY_OPTION_NAME = 'cea_mailchimp_api_key';

	/**
	 * Returns default settings.
	 *
	 * @return array<string, string>
	 */
	public static function get_defaults() {
		return array(
			'server_prefix' => '',
		);
	}

	/**
	 * Creates non-autoloaded options on activation.
	 *
	 * @return void
	 */
	public static function activate() {
		add_option( self::OPTION_NAME, self::get_defaults(), '', false );
		add_option( self::API_KEY_OPTION_NAME, '', '', false );
	}

	/**
	 * Ensures options exist for already-active installations.
	 *
	 * @return void
	 */
	public static function ensure_options() {
		if ( false === get_option( self::OPTION_NAME, false ) ) {
			add_option( self::OPTION_NAME, self::get_defaults(), '', false );
		}

		if ( false === get_option( self::API_KEY_OPTION_NAME, false ) ) {
			add_option( self::API_KEY_OPTION_NAME, '', '', false );
		}
	}

	/**
	 * Registers options with the WordPress Settings API.
	 *
	 * @return void
	 */
	public static function register() {
		self::ensure_options();

		register_setting(
			self::GROUP,
			self::API_KEY_OPTION_NAME,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_api_key' ),
				'default'           => '',
			)
		);

		register_setting(
			self::GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => self::get_defaults(),
			)
		);
	}

	/**
	 * Returns normalized Mailchimp settings.
	 *
	 * @return array<string, string>
	 */
	public static function get_settings() {
		$saved = get_option( self::OPTION_NAME, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$saved = wp_parse_args( $saved, self::get_defaults() );

		return array(
			'server_prefix' => isset( $saved['server_prefix'] ) && is_string( $saved['server_prefix'] )
				? strtolower( trim( $saved['server_prefix'] ) )
				: '',
		);
	}

	/**
	 * Returns whether the API key is supplied outside WordPress.
	 *
	 * @return bool
	 */
	public static function has_external_api_key() {
		return defined( 'CEA_MAILCHIMP_MARKETING_API_KEY' );
	}

	/**
	 * Returns whether the server prefix is supplied outside WordPress.
	 *
	 * @return bool
	 */
	public static function has_external_server_prefix() {
		return defined( 'CEA_MAILCHIMP_SERVER_PREFIX' );
	}

	/**
	 * Returns the effective Mailchimp API key.
	 *
	 * @return string
	 */
	public static function get_api_key() {
		if ( self::has_external_api_key() ) {
			$key = constant( 'CEA_MAILCHIMP_MARKETING_API_KEY' );

			return is_scalar( $key ) ? trim( (string) $key ) : '';
		}

		$key = get_option( self::API_KEY_OPTION_NAME, '' );

		return is_string( $key ) ? trim( $key ) : '';
	}

	/**
	 * Returns the effective Mailchimp server prefix.
	 *
	 * @return string
	 */
	public static function get_server_prefix() {
		if ( self::has_external_server_prefix() ) {
			$prefix = constant( 'CEA_MAILCHIMP_SERVER_PREFIX' );

			return is_scalar( $prefix ) ? strtolower( trim( (string) $prefix ) ) : '';
		}

		$settings = self::get_settings();

		if ( '' !== $settings['server_prefix'] ) {
			return $settings['server_prefix'];
		}

		return self::derive_server_prefix( self::get_api_key() );
	}

	/**
	 * Derives the data-center prefix from an API-key suffix.
	 *
	 * @param string $api_key Mailchimp API key.
	 * @return string
	 */
	public static function derive_server_prefix( $api_key ) {
		if ( ! is_string( $api_key ) || 1 !== preg_match( '/-([a-z]{2,4}[0-9]+)$/i', trim( $api_key ), $matches ) ) {
			return '';
		}

		return strtolower( $matches[1] );
	}

	/**
	 * Validates a Mailchimp data-center prefix.
	 *
	 * @param string $prefix Server prefix.
	 * @return bool
	 */
	public static function is_valid_server_prefix( $prefix ) {
		return is_string( $prefix ) && 1 === preg_match( '/^[a-z]{2,4}[0-9]+$/', $prefix );
	}

	/**
	 * Sanitizes a submitted API key without redisplaying it.
	 *
	 * @param mixed $value Submitted API-key structure.
	 * @return string
	 */
	public static function sanitize_api_key( $value ) {
		$existing = get_option( self::API_KEY_OPTION_NAME, '' );
		$existing = is_string( $existing ) ? $existing : '';

		if ( self::has_external_api_key() ) {
			return $existing;
		}

		$submission = is_array( $value ) ? $value : array( 'value' => $value );
		$clear      = ! empty( $submission['clear'] );
		$key        = isset( $submission['value'] ) ? $submission['value'] : '';

		if ( $clear ) {
			self::clear_cache();
			return '';
		}

		if ( ! is_string( $key ) || '' === trim( $key ) ) {
			return $existing;
		}

		$key = trim( $key );

		if ( 1024 < strlen( $key ) || false !== strpos( $key, "\0" ) || preg_match( '/\s/', $key ) ) {
			add_settings_error(
				self::OPTION_NAME,
				'cea_mailchimp_api_key_invalid',
				__( 'The Mailchimp API key was not saved because it is invalid.', 'cea-plugin' ),
				'error'
			);

			return $existing;
		}

		self::clear_cache();

		return $key;
	}

	/**
	 * Sanitizes general Mailchimp settings.
	 *
	 * @param mixed $input Submitted settings.
	 * @return array<string, string>
	 */
	public static function sanitize_settings( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$current = self::get_settings();

		if ( self::has_external_server_prefix() ) {
			return $current;
		}

		$prefix  = isset( $input['server_prefix'] ) && is_scalar( $input['server_prefix'] )
			? strtolower( trim( sanitize_text_field( (string) $input['server_prefix'] ) ) )
			: '';

		if ( '' !== $prefix && ! self::is_valid_server_prefix( $prefix ) ) {
			add_settings_error(
				self::OPTION_NAME,
				'cea_mailchimp_server_prefix_invalid',
				__( 'Enter a valid Mailchimp server prefix, such as us21.', 'cea-plugin' ),
				'error'
			);

			$prefix = $current['server_prefix'];
		}

		if ( $prefix !== $current['server_prefix'] ) {
			self::clear_cache();
		}

		return array( 'server_prefix' => $prefix );
	}

	/**
	 * Returns connection errors for the effective configuration.
	 *
	 * @return array<string, string>
	 */
	public static function get_configuration_errors() {
		$errors = array();

		if ( ! self::is_valid_api_key( self::get_api_key() ) ) {
			$errors['api_key'] = __( 'A valid Mailchimp Marketing API key is required.', 'cea-plugin' );
		}

		if ( ! self::is_valid_server_prefix( self::get_server_prefix() ) ) {
			$errors['server_prefix'] = __( 'A valid Mailchimp server prefix is required.', 'cea-plugin' );
		}

		return $errors;
	}

	/**
	 * Returns whether the effective connection has all required values.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return empty( self::get_configuration_errors() );
	}

	/**
	 * Redacts the effective API key from a diagnostic message.
	 *
	 * @param string $message Diagnostic message.
	 * @return string
	 */
	public static function redact_api_key( $message ) {
		$key = self::get_api_key();

		return '' !== $key ? str_replace( $key, '[redacted]', $message ) : $message;
	}

	/**
	 * Validates an effective API key before using it in an HTTP header.
	 *
	 * @param string $key API key.
	 * @return bool
	 */
	private static function is_valid_api_key( $key ) {
		return is_string( $key )
			&& '' !== $key
			&& 1024 >= strlen( $key )
			&& false === strpos( $key, "\0" )
			&& 0 === preg_match( '/\s/', $key );
	}

	/**
	 * Clears cached Mailchimp account data when connection details change.
	 *
	 * @return void
	 */
	private static function clear_cache() {
		if ( class_exists( 'CEA_Mailchimp_Client' ) ) {
			CEA_Mailchimp_Client::clear_audience_cache();
		}
	}
}
