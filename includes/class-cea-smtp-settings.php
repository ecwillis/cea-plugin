<?php
/**
 * SMTP settings storage and validation.
 *
 * @package CEA_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages site-level SMTP settings.
 */
final class CEA_SMTP_Settings {

	/**
	 * Settings API group.
	 */
	const GROUP = 'cea_smtp';

	/**
	 * General SMTP settings option.
	 */
	const OPTION_NAME = 'cea_smtp_settings';

	/**
	 * SMTP password option.
	 */
	const PASSWORD_OPTION_NAME = 'cea_smtp_password';

	/**
	 * Returns the default SMTP settings.
	 *
	 * @return array<string, bool|int|string>
	 */
	public static function get_defaults() {
		return array(
			'enabled'        => false,
			'host'           => '',
			'port'           => 587,
			'encryption'     => 'tls',
			'authentication' => true,
			'username'       => '',
			'from_email'     => '',
			'from_name'      => '',
			'force_from'     => false,
		);
	}

	/**
	 * Creates non-autoloaded options when the plugin is activated.
	 *
	 * @return void
	 */
	public static function activate() {
		add_option( self::OPTION_NAME, self::get_defaults(), '', false );
		add_option( self::PASSWORD_OPTION_NAME, '', '', false );
	}

	/**
	 * Ensures options exist for installations updated while already active.
	 *
	 * @return void
	 */
	public static function ensure_options() {
		if ( false === get_option( self::OPTION_NAME, false ) ) {
			add_option( self::OPTION_NAME, self::get_defaults(), '', false );
		}

		if ( false === get_option( self::PASSWORD_OPTION_NAME, false ) ) {
			add_option( self::PASSWORD_OPTION_NAME, '', '', false );
		}
	}

	/**
	 * Registers options with the WordPress Settings API.
	 *
	 * The password is registered first so a password submitted in the same form
	 * is available when the general settings are validated.
	 *
	 * @return void
	 */
	public static function register() {
		self::ensure_options();

		register_setting(
			self::GROUP,
			self::PASSWORD_OPTION_NAME,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_password' ),
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
	 * Returns normalized SMTP settings.
	 *
	 * @return array<string, bool|int|string>
	 */
	public static function get_settings() {
		$saved = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$settings                   = wp_parse_args( $saved, self::get_defaults() );
		$settings['enabled']        = ! empty( $settings['enabled'] );
		$settings['host']           = is_string( $settings['host'] ) ? $settings['host'] : '';
		$settings['port']           = absint( $settings['port'] );
		$settings['encryption']     = is_string( $settings['encryption'] ) ? $settings['encryption'] : 'tls';
		$settings['authentication'] = ! empty( $settings['authentication'] );
		$settings['username']       = is_string( $settings['username'] ) ? $settings['username'] : '';
		$settings['from_email']     = is_string( $settings['from_email'] ) ? $settings['from_email'] : '';
		$settings['from_name']      = is_string( $settings['from_name'] ) ? $settings['from_name'] : '';
		$settings['force_from']     = ! empty( $settings['force_from'] );

		return $settings;
	}

	/**
	 * Returns whether SMTP delivery is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = self::get_settings();

		return $settings['enabled'];
	}

	/**
	 * Returns whether the password is supplied outside WordPress.
	 *
	 * @return bool
	 */
	public static function has_external_password() {
		return defined( 'CEA_SMTP_PASSWORD' );
	}

	/**
	 * Returns the effective SMTP password.
	 *
	 * @return string
	 */
	public static function get_password() {
		if ( self::has_external_password() ) {
			$password = constant( 'CEA_SMTP_PASSWORD' );

			return is_scalar( $password ) ? (string) $password : '';
		}

		$password = get_option( self::PASSWORD_OPTION_NAME, '' );

		return is_string( $password ) ? $password : '';
	}

	/**
	 * Sanitizes a submitted SMTP password without redisplaying it.
	 *
	 * @param mixed $value Submitted password.
	 * @return string
	 */
	public static function sanitize_password( $value ) {
		$existing = get_option( self::PASSWORD_OPTION_NAME, '' );
		$existing = is_string( $existing ) ? $existing : '';

		if ( self::has_external_password() ) {
			return $existing;
		}

		$submission     = is_array( $value ) ? $value : array( 'value' => $value );
		$clear_password = ! empty( $submission['clear'] );
		$value          = isset( $submission['value'] ) ? $submission['value'] : '';

		if ( $clear_password ) {
			return '';
		}

		if ( ! is_string( $value ) || '' === $value ) {
			return $existing;
		}

		if ( 1024 < strlen( $value ) || false !== strpos( $value, "\0" ) ) {
			add_settings_error(
				self::OPTION_NAME,
				'cea_smtp_password_invalid',
				__( 'The SMTP password was not saved because it is invalid.', 'cea-plugin' ),
				'error'
			);

			return $existing;
		}

		return $value;
	}

	/**
	 * Sanitizes the general SMTP settings.
	 *
	 * @param mixed $input Submitted settings.
	 * @return array<string, bool|int|string>
	 */
	public static function sanitize_settings( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$current  = self::get_settings();
		$settings = self::get_defaults();

		$host = isset( $input['host'] ) ? strtolower( trim( sanitize_text_field( $input['host'] ) ) ) : '';

		if ( '' === $host || self::is_valid_host( $host ) ) {
			$settings['host'] = $host;
		} else {
			$settings['host'] = $current['host'];
			add_settings_error(
				self::OPTION_NAME,
				'cea_smtp_host_invalid',
				__( 'Enter an SMTP host name without a protocol or path.', 'cea-plugin' ),
				'error'
			);
		}

		$port = isset( $input['port'] ) ? absint( $input['port'] ) : 0;

		if ( 1 <= $port && 65535 >= $port ) {
			$settings['port'] = $port;
		} else {
			$settings['port'] = $current['port'];
			add_settings_error(
				self::OPTION_NAME,
				'cea_smtp_port_invalid',
				__( 'Enter an SMTP port between 1 and 65535.', 'cea-plugin' ),
				'error'
			);
		}

		$allowed_encryption = array( 'tls', 'ssl', 'none' );
		$encryption         = isset( $input['encryption'] ) ? sanitize_key( $input['encryption'] ) : '';

		if ( in_array( $encryption, $allowed_encryption, true ) ) {
			$settings['encryption'] = $encryption;
		} else {
			$settings['encryption'] = $current['encryption'];
			add_settings_error(
				self::OPTION_NAME,
				'cea_smtp_encryption_invalid',
				__( 'Select a supported SMTP encryption method.', 'cea-plugin' ),
				'error'
			);
		}

		$settings['authentication'] = ! empty( $input['authentication'] );
		$settings['username']       = isset( $input['username'] ) ? sanitize_text_field( $input['username'] ) : '';

		$from_email = isset( $input['from_email'] ) ? trim( sanitize_text_field( $input['from_email'] ) ) : '';

		if ( '' === $from_email || is_email( $from_email ) ) {
			$settings['from_email'] = sanitize_email( $from_email );
		} else {
			$settings['from_email'] = $current['from_email'];
			add_settings_error(
				self::OPTION_NAME,
				'cea_smtp_from_email_invalid',
				__( 'Enter a valid From email address.', 'cea-plugin' ),
				'error'
			);
		}

		$settings['from_name']  = isset( $input['from_name'] ) ? sanitize_text_field( $input['from_name'] ) : '';
		$settings['force_from'] = ! empty( $input['force_from'] );
		$settings['enabled']    = ! empty( $input['enabled'] );

		$configuration_errors = self::get_configuration_errors( $settings, self::get_password() );

		if ( $settings['enabled'] && ! empty( $configuration_errors ) ) {
			$settings['enabled'] = false;
			add_settings_error(
				self::OPTION_NAME,
				'cea_smtp_configuration_incomplete',
				__( 'SMTP was not enabled because its configuration is incomplete. Review the status below.', 'cea-plugin' ),
				'error'
			);
		}

		if ( $settings['enabled'] && 'none' === $settings['encryption'] ) {
			add_settings_error(
				self::OPTION_NAME,
				'cea_smtp_encryption_warning',
				__( 'SMTP is enabled without transport encryption. Use this only with a trusted internal server.', 'cea-plugin' ),
				'warning'
			);
		}

		return $settings;
	}

	/**
	 * Returns errors that make the current configuration unsafe to use.
	 *
	 * @param array<string, bool|int|string>|null $settings SMTP settings.
	 * @param string|null                        $password SMTP password.
	 * @return array<string, string>
	 */
	public static function get_configuration_errors( $settings = null, $password = null ) {
		$settings = is_array( $settings ) ? wp_parse_args( $settings, self::get_defaults() ) : self::get_settings();
		$password = is_string( $password ) ? $password : self::get_password();
		$errors   = array();

		if ( empty( $settings['host'] ) || ! self::is_valid_host( $settings['host'] ) ) {
			$errors['host'] = __( 'An SMTP host is required.', 'cea-plugin' );
		}

		if ( empty( $settings['port'] ) || 1 > $settings['port'] || 65535 < $settings['port'] ) {
			$errors['port'] = __( 'A valid SMTP port is required.', 'cea-plugin' );
		}

		if ( ! in_array( $settings['encryption'], array( 'tls', 'ssl', 'none' ), true ) ) {
			$errors['encryption'] = __( 'A valid encryption method is required.', 'cea-plugin' );
		}

		if ( ! empty( $settings['authentication'] ) ) {
			if ( empty( $settings['username'] ) ) {
				$errors['username'] = __( 'An SMTP username is required when authentication is enabled.', 'cea-plugin' );
			}

			if ( '' === $password ) {
				$errors['password'] = __( 'An SMTP password or API key is required when authentication is enabled.', 'cea-plugin' );
			}
		}

		if ( empty( $settings['from_email'] ) || ! is_email( $settings['from_email'] ) ) {
			$errors['from_email'] = __( 'A valid From email address is required for SMTP delivery.', 'cea-plugin' );
		}

		return $errors;
	}

	/**
	 * Removes the effective password from an error message.
	 *
	 * @param string $message Message that may contain a credential.
	 * @return string
	 */
	public static function redact_password( $message ) {
		$password = self::get_password();

		if ( '' !== $password ) {
			$message = str_replace( $password, '[redacted]', $message );
		}

		return $message;
	}

	/**
	 * Validates an SMTP host name or IP address.
	 *
	 * @param string $host SMTP host.
	 * @return bool
	 */
	private static function is_valid_host( $host ) {
		if ( 2 < strlen( $host ) && '[' === $host[0] && ']' === substr( $host, -1 ) ) {
			return false !== filter_var(
				substr( $host, 1, -1 ),
				FILTER_VALIDATE_IP,
				FILTER_FLAG_IPV6
			);
		}

		if ( is_numeric( str_replace( '.', '', $host ) ) ) {
			return false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 );
		}

		return 1 === preg_match(
			'/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i',
			$host
		);
	}
}
