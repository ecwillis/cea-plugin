<?php
/**
 * Form webhook action.
 *
 * @package CEA_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Delivers a signed JSON form payload to a safe remote URL.
 */
final class CEA_Form_Webhook_Action {

	/**
	 * Returns the registry definition.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_definition() {
		return array(
			'label'             => __( 'Webhook', 'cea-plugin' ),
			'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
			'validate_callback' => array( __CLASS__, 'validate_settings' ),
			'render_callback'   => array( __CLASS__, 'render_settings' ),
			'execute_callback'  => array( __CLASS__, 'execute' ),
		);
	}

	/**
	 * Sanitizes webhook action settings without redisplaying secrets.
	 *
	 * @param array<string, mixed> $settings Submitted settings.
	 * @param array<string, mixed> $existing Existing settings.
	 * @return array<string, string>
	 */
	public static function sanitize_settings( $settings, $existing = array() ) {
		$existing_secret = isset( $existing['secret'] ) && is_string( $existing['secret'] ) ? $existing['secret'] : '';
		$submitted       = isset( $settings['secret'] ) && is_string( $settings['secret'] ) ? $settings['secret'] : '';
		$clear_secret    = ! empty( $settings['clear_secret'] );
		$secret          = $existing_secret;

		if ( $clear_secret ) {
			$secret = '';
		} elseif ( '' !== $submitted && 1024 >= strlen( $submitted ) && false === strpos( $submitted, "\0" ) ) {
			$secret = $submitted;
		}

		$url = isset( $settings['url'] ) && is_string( $settings['url'] ) ? trim( $settings['url'] ) : '';

		return array(
			'url'    => esc_url_raw( $url ),
			'secret' => $secret,
		);
	}

	/**
	 * Validates webhook action settings.
	 *
	 * @param array<string, mixed> $settings Webhook settings.
	 * @return true|WP_Error
	 */
	public static function validate_settings( $settings ) {
		$url = isset( $settings['url'] ) ? $settings['url'] : '';

		if ( empty( $url ) || ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'cea_form_webhook_url', __( 'Webhook actions require a valid public URL.', 'cea-plugin' ) );
		}

		$scheme         = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$allow_insecure = (bool) apply_filters( 'cea_form_allow_insecure_webhook', false, $url );

		if ( 'https' !== $scheme && ! $allow_insecure ) {
			return new WP_Error( 'cea_form_webhook_https', __( 'Webhook URLs must use HTTPS.', 'cea-plugin' ) );
		}

		return true;
	}

	/**
	 * Renders webhook action settings.
	 *
	 * @param string               $name     Base field name.
	 * @param array<string, mixed> $settings Webhook settings.
	 * @return void
	 */
	public static function render_settings( $name, $settings ) {
		$url        = isset( $settings['url'] ) ? $settings['url'] : '';
		$has_secret = ! empty( $settings['secret'] );
		?>
		<p>
			<label>
				<strong><?php echo esc_html__( 'Webhook URL', 'cea-plugin' ); ?></strong>
				<input type="url" class="widefat" name="<?php echo esc_attr( $name ); ?>[url]" value="<?php echo esc_attr( $url ); ?>" placeholder="https://example.com/webhook">
			</label>
			<span class="description"><?php echo esc_html__( 'The submission is sent as JSON. HTTPS is required.', 'cea-plugin' ); ?></span>
		</p>
		<p>
			<label>
				<strong><?php echo esc_html__( 'Signing secret', 'cea-plugin' ); ?></strong>
				<input type="password" class="widefat" name="<?php echo esc_attr( $name ); ?>[secret]" value="" autocomplete="new-password">
			</label>
			<span class="description">
				<?php
				echo esc_html(
					$has_secret
						? __( 'A secret is saved. Leave this blank to keep it. Requests include an X-CEA-Signature header.', 'cea-plugin' )
						: __( 'Optional. Requests include an X-CEA-Signature header when a secret is supplied.', 'cea-plugin' )
				);
				?>
			</span>
		</p>
		<?php if ( $has_secret ) : ?>
			<p>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[clear_secret]" value="1">
					<?php echo esc_html__( 'Clear the saved signing secret', 'cea-plugin' ); ?>
				</label>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Sends a webhook action.
	 *
	 * @param array<string, mixed> $submission Submission data.
	 * @param array<string, mixed> $settings   Webhook settings.
	 * @return true|WP_Error
	 */
	public static function execute( $submission, $settings ) {
		$valid = self::validate_settings( $settings );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$payload = wp_json_encode(
			array(
				'form'         => array(
					'id'    => isset( $submission['form_id'] ) ? absint( $submission['form_id'] ) : 0,
					'title' => isset( $submission['form_title'] ) ? (string) $submission['form_title'] : '',
				),
				'submitted_at' => isset( $submission['submitted_at'] ) ? (string) $submission['submitted_at'] : '',
				'fields'       => isset( $submission['fields'] ) ? $submission['fields'] : array(),
			)
		);

		if ( ! is_string( $payload ) ) {
			return new WP_Error( 'cea_form_webhook_payload', __( 'The webhook payload could not be encoded.', 'cea-plugin' ) );
		}

		$headers = array( 'Content-Type' => 'application/json; charset=utf-8' );
		$secret  = isset( $settings['secret'] ) && is_string( $settings['secret'] ) ? $settings['secret'] : '';

		if ( '' !== $secret ) {
			$headers['X-CEA-Signature'] = 'sha256=' . hash_hmac( 'sha256', $payload, $secret );
		}

		$response = wp_safe_remote_post(
			$settings['url'],
			array(
				'body'        => $payload,
				'headers'     => $headers,
				'timeout'     => 8,
				'redirection' => 2,
				'data_format' => 'body',
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'cea_form_webhook_failed', __( 'The webhook request failed.', 'cea-plugin' ) );
		}

		$status = wp_remote_retrieve_response_code( $response );

		if ( 200 > $status || 300 <= $status ) {
			return new WP_Error(
				'cea_form_webhook_status',
				sprintf(
					/* translators: %d: HTTP response status. */
					__( 'The webhook returned HTTP status %d.', 'cea-plugin' ),
					$status
				)
			);
		}

		return true;
	}
}
