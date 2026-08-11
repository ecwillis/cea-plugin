<?php
/**
 * Mailchimp settings administrator screen.
 *
 * @package CEA_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders and processes the Mailchimp Marketing connection screen.
 */
final class CEA_Mailchimp_Settings_Page {

	/** CEA submenu slug. */
	const SUBMENU_SLUG = 'cea-mailchimp-settings';

	/** User-specific test-result transient prefix. */
	const TEST_TRANSIENT_PREFIX = 'cea_mailchimp_test_result_';

	/**
	 * Registers administrator hooks.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 20 );
		add_action( 'admin_post_cea_mailchimp_test_connection', array( __CLASS__, 'handle_test_connection' ) );
	}

	/**
	 * Registers Mailchimp settings and fields.
	 *
	 * @return void
	 */
	public static function register_settings() {
		CEA_Mailchimp_Settings::register();

		add_settings_section(
			'cea_mailchimp_connection',
			__( 'Marketing API connection', 'cea-plugin' ),
			array( __CLASS__, 'render_section_description' ),
			self::SUBMENU_SLUG
		);

		add_settings_field(
			'cea_mailchimp_api_key',
			__( 'Marketing API key', 'cea-plugin' ),
			array( __CLASS__, 'render_api_key_field' ),
			self::SUBMENU_SLUG,
			'cea_mailchimp_connection',
			array( 'label_for' => 'cea-mailchimp-api-key' )
		);

		add_settings_field(
			'cea_mailchimp_server_prefix',
			__( 'Server prefix', 'cea-plugin' ),
			array( __CLASS__, 'render_server_prefix_field' ),
			self::SUBMENU_SLUG,
			'cea_mailchimp_connection',
			array( 'label_for' => 'cea-mailchimp-server-prefix' )
		);
	}

	/**
	 * Registers the page under the CEA menu.
	 *
	 * @return void
	 */
	public static function register_page() {
		add_submenu_page(
			'cea-plugin',
			__( 'Mailchimp Settings', 'cea-plugin' ),
			__( 'Mailchimp', 'cea-plugin' ),
			'manage_options',
			self::SUBMENU_SLUG,
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
			wp_die( esc_html__( 'You do not have permission to manage Mailchimp settings.', 'cea-plugin' ) );
		}

		$result = get_transient( self::TEST_TRANSIENT_PREFIX . get_current_user_id() );

		if ( false !== $result ) {
			delete_transient( self::TEST_TRANSIENT_PREFIX . get_current_user_id() );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'CEA Mailchimp Settings', 'cea-plugin' ); ?></h1>

			<?php settings_errors(); ?>

			<?php if ( is_array( $result ) && ! empty( $result['message'] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( 'success' === $result['type'] ? 'success' : 'error' ); ?> is-dismissible">
					<p><?php echo esc_html( $result['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<?php self::render_status(); ?>

			<form action="options.php" method="post">
				<?php
				settings_fields( CEA_Mailchimp_Settings::GROUP );
				do_settings_sections( self::SUBMENU_SLUG );
				submit_button();
				?>
			</form>

			<hr>

			<h2><?php echo esc_html__( 'Test connection and refresh audiences', 'cea-plugin' ); ?></h2>
			<p><?php echo esc_html__( 'This verifies the credentials and refreshes the audience choices available in form actions.', 'cea-plugin' ); ?></p>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="cea_mailchimp_test_connection">
				<?php wp_nonce_field( 'cea_mailchimp_test_connection' ); ?>
				<?php submit_button( __( 'Test connection', 'cea-plugin' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php echo esc_html__( 'Privacy and consent', 'cea-plugin' ); ?></h2>
			<p><?php echo esc_html__( 'Mailchimp actions require a mapped consent checkbox. Double opt-in is the default because Mailchimp signup-form preferences do not automatically apply to API integrations.', 'cea-plugin' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Renders the settings-section description.
	 *
	 * @return void
	 */
	public static function render_section_description() {
		echo '<p>' . esc_html__( 'Connect this site to the Mailchimp Marketing API. This is separate from Mailchimp Transactional (Mandrill) SMTP.', 'cea-plugin' ) . '</p>';
	}

	/**
	 * Renders current configuration status.
	 *
	 * @return void
	 */
	private static function render_status() {
		$errors = CEA_Mailchimp_Settings::get_configuration_errors();

		if ( empty( $errors ) ) {
			$audiences = CEA_Mailchimp_Client::get_cached_audiences();
			$message   = empty( $audiences )
				? __( 'Mailchimp credentials are present. Test the connection to load audience choices.', 'cea-plugin' )
				: sprintf(
					/* translators: %d: Number of cached Mailchimp audiences. */
					_n( '%d Mailchimp audience is available to form actions.', '%d Mailchimp audiences are available to form actions.', count( $audiences ), 'cea-plugin' ),
					count( $audiences )
				);

			echo '<div class="notice notice-success inline"><p>' . esc_html( $message ) . '</p></div>';
			return;
		}

		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Complete the Mailchimp connection before enabling a Mailchimp form action.', 'cea-plugin' ) . '</p><ul>';

		foreach ( $errors as $error ) {
			echo '<li>' . esc_html( $error ) . '</li>';
		}

		echo '</ul></div>';
	}

	/**
	 * Renders the API-key field.
	 *
	 * @return void
	 */
	public static function render_api_key_field() {
		$external = CEA_Mailchimp_Settings::has_external_api_key();
		$has_key  = '' !== CEA_Mailchimp_Settings::get_api_key();
		?>
		<input
			type="password"
			class="regular-text"
			id="cea-mailchimp-api-key"
			name="<?php echo esc_attr( CEA_Mailchimp_Settings::API_KEY_OPTION_NAME ); ?>[value]"
			value=""
			autocomplete="new-password"
			<?php disabled( $external ); ?>
		>
		<p class="description">
			<?php
			echo esc_html(
				$external
					? __( 'The API key is supplied by CEA_MAILCHIMP_MARKETING_API_KEY and cannot be changed here.', 'cea-plugin' )
					: ( $has_key
						? __( 'An API key is saved. Leave this blank to keep it.', 'cea-plugin' )
						: __( 'Create a Mailchimp Marketing API key and store it here or in wp-config.php.', 'cea-plugin' ) )
			);
			?>
		</p>
		<?php if ( $has_key && ! $external ) : ?>
			<label>
				<input type="checkbox" name="<?php echo esc_attr( CEA_Mailchimp_Settings::API_KEY_OPTION_NAME ); ?>[clear]" value="1">
				<?php echo esc_html__( 'Clear the saved API key', 'cea-plugin' ); ?>
			</label>
		<?php endif; ?>
		<?php
	}

	/**
	 * Renders the server-prefix field.
	 *
	 * @return void
	 */
	public static function render_server_prefix_field() {
		$settings = CEA_Mailchimp_Settings::get_settings();
		$external = CEA_Mailchimp_Settings::has_external_server_prefix();
		?>
		<input
			type="text"
			class="regular-text"
			id="cea-mailchimp-server-prefix"
			name="<?php echo esc_attr( CEA_Mailchimp_Settings::OPTION_NAME ); ?>[server_prefix]"
			value="<?php echo esc_attr( $settings['server_prefix'] ); ?>"
			placeholder="<?php echo esc_attr( CEA_Mailchimp_Settings::derive_server_prefix( CEA_Mailchimp_Settings::get_api_key() ) ); ?>"
			<?php disabled( $external ); ?>
		>
		<p class="description">
			<?php
			echo esc_html(
				$external
					? __( 'The server prefix is supplied by CEA_MAILCHIMP_SERVER_PREFIX.', 'cea-plugin' )
					: __( 'Usually derived from the API-key suffix. Enter a value such as us21 only when an override is needed.', 'cea-plugin' )
			);
			?>
		</p>
		<?php
	}

	/**
	 * Tests the connection and refreshes cached audiences.
	 *
	 * @return void
	 */
	public static function handle_test_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to test the Mailchimp connection.', 'cea-plugin' ) );
		}

		check_admin_referer( 'cea_mailchimp_test_connection' );

		$errors = CEA_Mailchimp_Settings::get_configuration_errors();

		if ( ! empty( $errors ) ) {
			self::store_test_result( 'error', reset( $errors ) );
			self::redirect_to_page();
		}

		$result = ( new CEA_Mailchimp_Client() )->test_connection();

		if ( is_wp_error( $result ) ) {
			$message = CEA_Mailchimp_Settings::redact_api_key( sanitize_text_field( $result->get_error_message() ) );
			self::store_test_result( 'error', $message );
		} else {
			self::store_test_result(
				'success',
				sprintf(
					/* translators: %d: Number of Mailchimp audiences. */
					_n( 'Connection successful. %d audience was loaded.', 'Connection successful. %d audiences were loaded.', count( $result ), 'cea-plugin' ),
					count( $result )
				)
			);
		}

		self::redirect_to_page();
	}

	/**
	 * Stores a short-lived, user-specific result.
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
				'message' => sanitize_text_field( $message ),
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Redirects back to the settings page.
	 *
	 * @return void
	 */
	private static function redirect_to_page() {
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SUBMENU_SLUG ) );
		exit;
	}
}
