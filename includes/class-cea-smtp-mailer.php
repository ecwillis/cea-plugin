<?php
/**
 * SMTP mail transport integration.
 *
 * @package CEA_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Routes WordPress email through a configured SMTP server.
 */
final class CEA_SMTP_Mailer {

	/**
	 * Registers mail-related hooks.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_filter( 'pre_wp_mail', array( __CLASS__, 'fail_closed_preflight' ), 1 );
		add_action( 'phpmailer_init', array( __CLASS__, 'configure_phpmailer' ), PHP_INT_MAX );
		add_filter( 'wp_mail_from', array( __CLASS__, 'filter_from_email' ), PHP_INT_MAX );
		add_filter( 'wp_mail_from_name', array( __CLASS__, 'filter_from_name' ), PHP_INT_MAX );
	}

	/**
	 * Blocks sending when SMTP is enabled but cannot be configured safely.
	 *
	 * @param null|bool $return Whether another callback preempted wp_mail().
	 * @return null|bool
	 */
	public static function fail_closed_preflight( $return ) {
		if ( null !== $return || ! CEA_SMTP_Settings::is_enabled() ) {
			return $return;
		}

		$errors = CEA_SMTP_Settings::get_configuration_errors();

		if ( empty( $errors ) ) {
			return null;
		}

		$error = new WP_Error(
			'cea_smtp_invalid_configuration',
			__( 'CEA SMTP is enabled, but its configuration is incomplete. Email was not sent.', 'cea-plugin' ),
			array( 'configuration_errors' => array_values( $errors ) )
		);

		do_action( 'wp_mail_failed', $error );

		return false;
	}

	/**
	 * Applies the configured SMTP transport to WordPress's PHPMailer instance.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance.
	 * @return void
	 */
	public static function configure_phpmailer( $phpmailer ) {
		if ( ! CEA_SMTP_Settings::is_enabled() || ! empty( CEA_SMTP_Settings::get_configuration_errors() ) ) {
			return;
		}

		$settings = CEA_SMTP_Settings::get_settings();

		$phpmailer->isSMTP();
		$phpmailer->Host          = $settings['host'];
		$phpmailer->Port          = $settings['port'];
		$phpmailer->SMTPAuth      = $settings['authentication'];
		$phpmailer->AuthType      = '';
		$phpmailer->SMTPDebug     = 0;
		$phpmailer->SMTPKeepAlive = false;
		$phpmailer->SMTPOptions   = array();
		$phpmailer->Timeout       = 30;

		if ( $settings['authentication'] ) {
			$phpmailer->Username = $settings['username'];
			$phpmailer->Password = CEA_SMTP_Settings::get_password();
		} else {
			$phpmailer->Username = '';
			$phpmailer->Password = '';
		}

		switch ( $settings['encryption'] ) {
			case 'ssl':
				$phpmailer->SMTPSecure  = 'ssl';
				$phpmailer->SMTPAutoTLS = false;
				break;

			case 'none':
				$phpmailer->SMTPSecure  = '';
				$phpmailer->SMTPAutoTLS = false;
				break;

			case 'tls':
			default:
				$phpmailer->SMTPSecure  = 'tls';
				$phpmailer->SMTPAutoTLS = true;
				break;
		}
	}

	/**
	 * Applies the configured From email to WordPress defaults and invalid senders.
	 *
	 * A valid custom sender supplied by other code remains intact unless the
	 * administrator explicitly enables sender overrides.
	 *
	 * @param string $from_email Existing From email.
	 * @return string
	 */
	public static function filter_from_email( $from_email ) {
		$settings = CEA_SMTP_Settings::get_settings();

		if ( ! $settings['enabled'] || empty( $settings['from_email'] ) ) {
			return $from_email;
		}

		if (
			$settings['force_from']
			|| ! is_email( $from_email )
			|| self::is_wordpress_default_from_email( $from_email )
		) {
			return $settings['from_email'];
		}

		return $from_email;
	}

	/**
	 * Applies the configured From name to the WordPress default sender.
	 *
	 * @param string $from_name Existing From name.
	 * @return string
	 */
	public static function filter_from_name( $from_name ) {
		$settings = CEA_SMTP_Settings::get_settings();

		if (
			$settings['enabled']
			&& ! empty( $settings['from_name'] )
			&& ( $settings['force_from'] || 'WordPress' === $from_name )
		) {
			return $settings['from_name'];
		}

		return $from_name;
	}

	/**
	 * Returns whether an address is WordPress's generated default sender.
	 *
	 * @param string $from_email From email address.
	 * @return bool
	 */
	private static function is_wordpress_default_from_email( $from_email ) {
		$site_name = wp_parse_url( network_home_url(), PHP_URL_HOST );
		$default   = 'wordpress@';

		if ( null !== $site_name ) {
			if ( 0 === strpos( $site_name, 'www.' ) ) {
				$site_name = substr( $site_name, 4 );
			}

			$default .= $site_name;
		}

		return strtolower( $default ) === strtolower( $from_email );
	}
}
