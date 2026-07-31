<?php
/**
 * SMTP settings admin screen.
 *
 * @package CEA_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders and processes the site-level SMTP settings screen.
 */
final class CEA_SMTP_Settings_Page {

	/**
	 * Admin page slug.
	 */
	const PAGE_SLUG = 'cea-plugin';

	/**
	 * User-specific test result transient prefix.
	 */
	const TEST_TRANSIENT_PREFIX = 'cea_smtp_test_result_';

	/**
	 * Mail error captured during a test send.
	 *
	 * @var WP_Error|null
	 */
	private static $mail_error = null;

	/**
	 * Registers admin hooks.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_post_cea_smtp_send_test', array( __CLASS__, 'handle_test_email' ) );
	}

	/**
	 * Registers the SMTP options and fields.
	 *
	 * @return void
	 */
	public static function register_settings() {
		CEA_SMTP_Settings::register();

		add_settings_section(
			'cea_smtp_delivery',
			__( 'Email delivery', 'cea-plugin' ),
			array( __CLASS__, 'render_section_description' ),
			self::PAGE_SLUG
		);

		$fields = array(
			'enabled'        => __( 'Enable SMTP', 'cea-plugin' ),
			'host'           => __( 'SMTP host', 'cea-plugin' ),
			'port'           => __( 'SMTP port', 'cea-plugin' ),
			'encryption'     => __( 'Encryption', 'cea-plugin' ),
			'authentication' => __( 'Authentication', 'cea-plugin' ),
			'username'       => __( 'Username', 'cea-plugin' ),
			'password'       => __( 'Password / API key', 'cea-plugin' ),
			'from_email'     => __( 'From email', 'cea-plugin' ),
			'from_name'      => __( 'From name', 'cea-plugin' ),
			'force_from'     => __( 'Sender override', 'cea-plugin' ),
		);

		foreach ( $fields as $field => $label ) {
			add_settings_field(
				'cea_smtp_' . $field,
				$label,
				array( __CLASS__, 'render_' . $field . '_field' ),
				self::PAGE_SLUG,
				'cea_smtp_delivery',
				array( 'label_for' => 'cea-smtp-' . str_replace( '_', '-', $field ) )
			);
		}
	}

	/**
	 * Registers the page under the WordPress Settings menu.
	 *
	 * @return void
	 */
	public static function register_page() {
		add_options_page(
			__( 'CEA Plugin', 'cea-plugin' ),
			__( 'CEA Plugin', 'cea-plugin' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage these settings.', 'cea-plugin' ) );
		}

		$test_result = get_transient( self::TEST_TRANSIENT_PREFIX . get_current_user_id() );

		if ( false !== $test_result ) {
			delete_transient( self::TEST_TRANSIENT_PREFIX . get_current_user_id() );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'CEA Plugin', 'cea-plugin' ); ?></h1>

			<?php settings_errors(); ?>

			<?php if ( is_array( $test_result ) && ! empty( $test_result['message'] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( 'success' === $test_result['type'] ? 'success' : 'error' ); ?> is-dismissible">
					<p><?php echo esc_html( $test_result['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<?php self::render_status(); ?>

			<form action="options.php" method="post">
				<?php
				settings_fields( CEA_SMTP_Settings::GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>

			<hr>

			<h2><?php echo esc_html__( 'Send a test email', 'cea-plugin' ); ?></h2>
			<p><?php echo esc_html__( 'The test uses wp_mail() and the same SMTP path as other WordPress email. A successful result confirms that the SMTP server accepted the message, not that it reached the inbox.', 'cea-plugin' ); ?></p>

			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="cea_smtp_send_test">
				<?php wp_nonce_field( 'cea_smtp_send_test' ); ?>
				<label for="cea-smtp-test-recipient"><strong><?php echo esc_html__( 'Recipient', 'cea-plugin' ); ?></strong></label>
				<input
					type="email"
					class="regular-text"
					id="cea-smtp-test-recipient"
					name="recipient"
					value="<?php echo esc_attr( self::get_default_test_recipient() ); ?>"
					required
				>
				<?php submit_button( __( 'Send test email', 'cea-plugin' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php echo esc_html__( 'Mailchimp Transactional (Mandrill) example', 'cea-plugin' ); ?></h2>
			<p><?php echo esc_html__( 'Mandrill uses generic SMTP credentials, so it does not require provider-specific code:', 'cea-plugin' ); ?></p>
			<ul>
				<li><?php echo wp_kses_post( __( '<strong>Host:</strong> <code>smtp.mandrillapp.com</code>', 'cea-plugin' ) ); ?></li>
				<li><?php echo wp_kses_post( __( '<strong>Port:</strong> <code>587</code>', 'cea-plugin' ) ); ?></li>
				<li><?php echo wp_kses_post( __( '<strong>Encryption:</strong> STARTTLS', 'cea-plugin' ) ); ?></li>
				<li><?php echo wp_kses_post( __( '<strong>Username:</strong> Mailchimp primary contact email', 'cea-plugin' ) ); ?></li>
				<li><?php echo wp_kses_post( __( '<strong>Password:</strong> Mailchimp Transactional API key', 'cea-plugin' ) ); ?></li>
			</ul>
			<p>
				<a href="<?php echo esc_url( 'https://mailchimp.com/developer/transactional/docs/smtp-integration/' ); ?>" target="_blank" rel="noopener noreferrer">
					<?php echo esc_html__( 'Review Mandrill SMTP documentation', 'cea-plugin' ); ?>
					<span class="screen-reader-text"><?php echo esc_html__( ' (opens in a new tab)', 'cea-plugin' ); ?></span>
				</a>
			</p>
			<p><?php echo esc_html__( 'Use only one SMTP-routing plugin at a time. The sending domain must also satisfy the selected provider’s authentication requirements.', 'cea-plugin' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Renders the settings section introduction.
	 *
	 * @return void
	 */
	public static function render_section_description() {
		echo '<p>' . esc_html__( 'Route all WordPress email through a provider-neutral SMTP server. When enabled, an SMTP failure will not fall back to PHP mail.', 'cea-plugin' ) . '</p>';
	}

	/**
	 * Renders current configuration status.
	 *
	 * @return void
	 */
	private static function render_status() {
		$enabled = CEA_SMTP_Settings::is_enabled();
		$errors  = CEA_SMTP_Settings::get_configuration_errors();

		if ( ! $enabled ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'SMTP delivery is currently disabled.', 'cea-plugin' ) . '</p></div>';
			return;
		}

		if ( empty( $errors ) ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'SMTP delivery is enabled and its required settings are present.', 'cea-plugin' ) . '</p></div>';
			return;
		}

		echo '<div class="notice notice-error inline"><p>' . esc_html__( 'SMTP delivery is enabled but invalid. Email will be blocked until these items are corrected:', 'cea-plugin' ) . '</p><ul>';

		foreach ( $errors as $error ) {
			echo '<li>' . esc_html( $error ) . '</li>';
		}

		echo '</ul></div>';
	}

	/**
	 * Renders the enabled field.
	 *
	 * @return void
	 */
	public static function render_enabled_field() {
		$settings = CEA_SMTP_Settings::get_settings();
		?>
		<label for="cea-smtp-enabled">
			<input type="checkbox" id="cea-smtp-enabled" name="<?php echo esc_attr( CEA_SMTP_Settings::OPTION_NAME ); ?>[enabled]" value="1" <?php checked( $settings['enabled'] ); ?>>
			<?php echo esc_html__( 'Send all WordPress email through the configured SMTP server', 'cea-plugin' ); ?>
		</label>
		<?php
	}

	/**
	 * Renders the SMTP host field.
	 *
	 * @return void
	 */
	public static function render_host_field() {
		$settings = CEA_SMTP_Settings::get_settings();
		?>
		<input type="text" class="regular-text" id="cea-smtp-host" name="<?php echo esc_attr( CEA_SMTP_Settings::OPTION_NAME ); ?>[host]" value="<?php echo esc_attr( $settings['host'] ); ?>" placeholder="smtp.example.com">
		<p class="description"><?php echo esc_html__( 'Enter a host name or IP address without a protocol or path. IPv6 addresses must use square brackets.', 'cea-plugin' ); ?></p>
		<?php
	}

	/**
	 * Renders the SMTP port field.
	 *
	 * @return void
	 */
	public static function render_port_field() {
		$settings = CEA_SMTP_Settings::get_settings();
		?>
		<input type="number" class="small-text" id="cea-smtp-port" name="<?php echo esc_attr( CEA_SMTP_Settings::OPTION_NAME ); ?>[port]" value="<?php echo esc_attr( $settings['port'] ); ?>" min="1" max="65535" required>
		<p class="description"><?php echo esc_html__( 'Port 587 with STARTTLS is recommended when the provider supports it.', 'cea-plugin' ); ?></p>
		<?php
	}

	/**
	 * Renders the encryption field.
	 *
	 * @return void
	 */
	public static function render_encryption_field() {
		$settings = CEA_SMTP_Settings::get_settings();
		?>
		<select id="cea-smtp-encryption" name="<?php echo esc_attr( CEA_SMTP_Settings::OPTION_NAME ); ?>[encryption]">
			<option value="tls" <?php selected( $settings['encryption'], 'tls' ); ?>><?php echo esc_html__( 'STARTTLS (recommended)', 'cea-plugin' ); ?></option>
			<option value="ssl" <?php selected( $settings['encryption'], 'ssl' ); ?>><?php echo esc_html__( 'Implicit TLS', 'cea-plugin' ); ?></option>
			<option value="none" <?php selected( $settings['encryption'], 'none' ); ?>><?php echo esc_html__( 'None (not recommended)', 'cea-plugin' ); ?></option>
		</select>
		<p class="description"><?php echo esc_html__( 'Certificate verification remains enabled for encrypted connections.', 'cea-plugin' ); ?></p>
		<?php
	}

	/**
	 * Renders the authentication field.
	 *
	 * @return void
	 */
	public static function render_authentication_field() {
		$settings = CEA_SMTP_Settings::get_settings();
		?>
		<label for="cea-smtp-authentication">
			<input type="checkbox" id="cea-smtp-authentication" name="<?php echo esc_attr( CEA_SMTP_Settings::OPTION_NAME ); ?>[authentication]" value="1" <?php checked( $settings['authentication'] ); ?>>
			<?php echo esc_html__( 'Authenticate with a username and password', 'cea-plugin' ); ?>
		</label>
		<?php
	}

	/**
	 * Renders the SMTP username field.
	 *
	 * @return void
	 */
	public static function render_username_field() {
		$settings = CEA_SMTP_Settings::get_settings();
		?>
		<input type="text" class="regular-text" id="cea-smtp-username" name="<?php echo esc_attr( CEA_SMTP_Settings::OPTION_NAME ); ?>[username]" value="<?php echo esc_attr( $settings['username'] ); ?>" autocomplete="username">
		<?php
	}

	/**
	 * Renders the SMTP password field without exposing a saved value.
	 *
	 * @return void
	 */
	public static function render_password_field() {
		if ( CEA_SMTP_Settings::has_external_password() ) {
			?>
			<input type="hidden" name="<?php echo esc_attr( CEA_SMTP_Settings::PASSWORD_OPTION_NAME ); ?>[value]" value="">
			<input type="password" class="regular-text" id="cea-smtp-password" value="" autocomplete="new-password" disabled>
			<p><strong><?php echo esc_html__( 'Configured externally with CEA_SMTP_PASSWORD.', 'cea-plugin' ); ?></strong></p>
			<p class="description"><?php echo esc_html__( 'The constant takes precedence over any password stored in WordPress.', 'cea-plugin' ); ?></p>
			<?php
			return;
		}

		$has_saved_password = '' !== CEA_SMTP_Settings::get_password();
		?>
		<input type="password" class="regular-text" id="cea-smtp-password" name="<?php echo esc_attr( CEA_SMTP_Settings::PASSWORD_OPTION_NAME ); ?>[value]" value="" autocomplete="new-password">
		<p class="description">
			<?php
			echo $has_saved_password
				? esc_html__( 'A password is saved. Leave this field blank to keep it.', 'cea-plugin' )
				: esc_html__( 'Enter the SMTP password or provider-issued API key.', 'cea-plugin' );
			?>
		</p>
		<?php if ( $has_saved_password ) : ?>
			<label for="cea-smtp-clear-password">
				<input type="checkbox" id="cea-smtp-clear-password" name="<?php echo esc_attr( CEA_SMTP_Settings::PASSWORD_OPTION_NAME ); ?>[clear]" value="1">
				<?php echo esc_html__( 'Clear the saved password when settings are saved', 'cea-plugin' ); ?>
			</label>
		<?php endif; ?>
		<?php
	}

	/**
	 * Renders the From email field.
	 *
	 * @return void
	 */
	public static function render_from_email_field() {
		$settings = CEA_SMTP_Settings::get_settings();
		?>
		<input type="email" class="regular-text" id="cea-smtp-from-email" name="<?php echo esc_attr( CEA_SMTP_Settings::OPTION_NAME ); ?>[from_email]" value="<?php echo esc_attr( $settings['from_email'] ); ?>">
		<p class="description"><?php echo esc_html__( 'Required. Use an address permitted by the SMTP provider and its authenticated sending domain.', 'cea-plugin' ); ?></p>
		<?php
	}

	/**
	 * Renders the From name field.
	 *
	 * @return void
	 */
	public static function render_from_name_field() {
		$settings = CEA_SMTP_Settings::get_settings();
		?>
		<input type="text" class="regular-text" id="cea-smtp-from-name" name="<?php echo esc_attr( CEA_SMTP_Settings::OPTION_NAME ); ?>[from_name]" value="<?php echo esc_attr( $settings['from_name'] ); ?>">
		<?php
	}

	/**
	 * Renders the sender override field.
	 *
	 * @return void
	 */
	public static function render_force_from_field() {
		$settings = CEA_SMTP_Settings::get_settings();
		?>
		<label for="cea-smtp-force-from">
			<input type="checkbox" id="cea-smtp-force-from" name="<?php echo esc_attr( CEA_SMTP_Settings::OPTION_NAME ); ?>[force_from]" value="1" <?php checked( $settings['force_from'] ); ?>>
			<?php echo esc_html__( 'Override valid custom sender details supplied by themes and other plugins', 'cea-plugin' ); ?>
		</label>
		<p class="description"><?php echo esc_html__( 'The configured sender always replaces WordPress’s generated default address. Enable this option to replace custom From headers too.', 'cea-plugin' ); ?></p>
		<?php
	}

	/**
	 * Sends a test email through wp_mail().
	 *
	 * @return void
	 */
	public static function handle_test_email() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to send an SMTP test.', 'cea-plugin' ) );
		}

		check_admin_referer( 'cea_smtp_send_test' );

		$recipient = isset( $_POST['recipient'] ) ? sanitize_email( wp_unslash( $_POST['recipient'] ) ) : '';

		if ( ! is_email( $recipient ) ) {
			self::store_test_result( 'error', __( 'Enter a valid test recipient email address.', 'cea-plugin' ) );
			self::redirect_to_page();
		}

		if ( ! CEA_SMTP_Settings::is_enabled() ) {
			self::store_test_result( 'error', __( 'Enable SMTP and save a complete configuration before sending a test.', 'cea-plugin' ) );
			self::redirect_to_page();
		}

		$configuration_errors = CEA_SMTP_Settings::get_configuration_errors();

		if ( ! empty( $configuration_errors ) ) {
			self::store_test_result( 'error', __( 'Correct the SMTP configuration errors before sending a test.', 'cea-plugin' ) );
			self::redirect_to_page();
		}

		self::$mail_error = null;
		add_action( 'wp_mail_failed', array( __CLASS__, 'capture_mail_error' ) );

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject   = sprintf(
			/* translators: %s: Site name. */
			__( '[%s] SMTP test email', 'cea-plugin' ),
			$site_name
		);
		$message   = sprintf(
			/* translators: 1: Site URL, 2: Local date and time. */
			__( "This is an SMTP test email from %1\$s.\n\nSent at %2\$s.", 'cea-plugin' ),
			home_url( '/' ),
			current_time( 'mysql' )
		);
		$sent      = wp_mail( $recipient, $subject, $message );

		remove_action( 'wp_mail_failed', array( __CLASS__, 'capture_mail_error' ) );

		if ( $sent ) {
			self::store_test_result(
				'success',
				__( 'The SMTP server accepted the test email. Check the recipient inbox and the provider’s delivery activity.', 'cea-plugin' )
			);
		} else {
			$message = self::$mail_error instanceof WP_Error
				? self::$mail_error->get_error_message()
				: __( 'The SMTP test failed without a detailed mailer error.', 'cea-plugin' );
			$message = CEA_SMTP_Settings::redact_password( sanitize_text_field( $message ) );

			self::store_test_result(
				'error',
				sprintf(
					/* translators: %s: Sanitized mailer error. */
					__( 'The SMTP test failed: %s', 'cea-plugin' ),
					$message
				)
			);
		}

		self::redirect_to_page();
	}

	/**
	 * Captures a WordPress mail failure during the test request.
	 *
	 * @param WP_Error $error Mail error.
	 * @return void
	 */
	public static function capture_mail_error( $error ) {
		if ( $error instanceof WP_Error ) {
			self::$mail_error = $error;
		}
	}

	/**
	 * Returns the current user's email or the site admin email.
	 *
	 * @return string
	 */
	private static function get_default_test_recipient() {
		$current_user = wp_get_current_user();

		if ( ! empty( $current_user->user_email ) && is_email( $current_user->user_email ) ) {
			return $current_user->user_email;
		}

		return sanitize_email( get_option( 'admin_email', '' ) );
	}

	/**
	 * Stores a short-lived, user-specific test result.
	 *
	 * @param string $type    Result type.
	 * @param string $message Result message.
	 * @return void
	 */
	private static function store_test_result( $type, $message ) {
		set_transient(
			self::TEST_TRANSIENT_PREFIX . get_current_user_id(),
			array(
				'type'    => 'success' === $type ? 'success' : 'error',
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Redirects back to the settings page and ends the request.
	 *
	 * @return void
	 */
	private static function redirect_to_page() {
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) );
		exit;
	}
}
