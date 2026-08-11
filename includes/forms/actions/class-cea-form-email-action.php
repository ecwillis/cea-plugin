<?php
/**
 * Form email action.
 *
 * @package CEA_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends a form submission through wp_mail().
 */
final class CEA_Form_Email_Action {

	/**
	 * Returns the registry definition.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_definition() {
		return array(
			'label'             => __( 'Email notification', 'cea-plugin' ),
			'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
			'validate_callback' => array( __CLASS__, 'validate_settings' ),
			'render_callback'   => array( __CLASS__, 'render_settings' ),
			'execute_callback'  => array( __CLASS__, 'execute' ),
		);
	}

	/**
	 * Returns default email settings.
	 *
	 * @return array<string, string>
	 */
	public static function get_defaults() {
		return array(
			'to'      => sanitize_email( get_option( 'admin_email', '' ) ),
			'subject' => __( 'New {{form.title}} submission', 'cea-plugin' ),
			'message' => __( "A new submission was received:\n\n{{all_fields}}", 'cea-plugin' ),
		);
	}

	/**
	 * Sanitizes email action settings.
	 *
	 * @param array<string, mixed> $settings Submitted settings.
	 * @param array<string, mixed> $existing Existing settings.
	 * @return array<string, string>
	 */
	public static function sanitize_settings( $settings, $existing = array() ) {
		unset( $existing );

		$defaults = self::get_defaults();

		return array(
			'to'      => isset( $settings['to'] ) ? sanitize_text_field( $settings['to'] ) : $defaults['to'],
			'subject' => isset( $settings['subject'] ) ? sanitize_text_field( $settings['subject'] ) : $defaults['subject'],
			'message' => isset( $settings['message'] ) ? sanitize_textarea_field( $settings['message'] ) : $defaults['message'],
		);
	}

	/**
	 * Validates email action settings.
	 *
	 * @param array<string, mixed> $settings Email settings.
	 * @param array<string, mixed> $context  Optional validation context.
	 * @return true|WP_Error
	 */
	public static function validate_settings( $settings, $context = array() ) {
		unset( $context );

		$recipients = self::parse_recipients( isset( $settings['to'] ) ? $settings['to'] : '' );

		if ( is_wp_error( $recipients ) ) {
			return $recipients;
		}

		if ( empty( $settings['subject'] ) ) {
			return new WP_Error( 'cea_form_email_subject', __( 'Email actions require a subject.', 'cea-plugin' ) );
		}

		if ( empty( $settings['message'] ) ) {
			return new WP_Error( 'cea_form_email_message', __( 'Email actions require a message.', 'cea-plugin' ) );
		}

		return true;
	}

	/**
	 * Renders email action settings.
	 *
	 * @param string               $name     Base field name.
	 * @param array<string, mixed> $settings Email settings.
	 * @param array<string, mixed> $context  Optional rendering context.
	 * @return void
	 */
	public static function render_settings( $name, $settings, $context = array() ) {
		unset( $context );

		$settings = wp_parse_args( $settings, self::get_defaults() );
		?>
		<p>
			<label>
				<strong><?php echo esc_html__( 'Recipients', 'cea-plugin' ); ?></strong>
				<input type="text" class="widefat" name="<?php echo esc_attr( $name ); ?>[to]" value="<?php echo esc_attr( $settings['to'] ); ?>">
			</label>
			<span class="description"><?php echo esc_html__( 'Separate multiple email addresses with commas.', 'cea-plugin' ); ?></span>
		</p>
		<p>
			<label>
				<strong><?php echo esc_html__( 'Subject', 'cea-plugin' ); ?></strong>
				<input type="text" class="widefat" name="<?php echo esc_attr( $name ); ?>[subject]" value="<?php echo esc_attr( $settings['subject'] ); ?>">
			</label>
		</p>
		<p>
			<label>
				<strong><?php echo esc_html__( 'Message', 'cea-plugin' ); ?></strong>
				<textarea class="widefat" rows="7" name="<?php echo esc_attr( $name ); ?>[message]"><?php echo esc_textarea( $settings['message'] ); ?></textarea>
			</label>
			<span class="description"><?php echo esc_html__( 'Tokens: {{all_fields}}, {{field.FIELD_KEY}}, {{form.title}}, {{site.name}}, {{site.url}}, {{submitted_at}}.', 'cea-plugin' ); ?></span>
		</p>
		<?php
	}

	/**
	 * Sends an email action.
	 *
	 * @param array<string, mixed> $submission Submission data.
	 * @param array<string, mixed> $settings   Email settings.
	 * @return true|WP_Error
	 */
	public static function execute( $submission, $settings ) {
		$valid = self::validate_settings( $settings );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$recipients = self::parse_recipients( $settings['to'] );
		$subject    = sanitize_text_field( CEA_Form_Action_Dispatcher::replace_tokens( $settings['subject'], $submission ) );
		$message    = CEA_Form_Action_Dispatcher::replace_tokens( $settings['message'], $submission );
		$sent       = wp_mail( $recipients, $subject, $message );

		return $sent
			? true
			: new WP_Error( 'cea_form_email_failed', __( 'WordPress could not send the form notification email.', 'cea-plugin' ) );
	}

	/**
	 * Parses and validates recipient addresses.
	 *
	 * @param mixed $value Recipient list.
	 * @return array<int, string>|WP_Error
	 */
	private static function parse_recipients( $value ) {
		$parts      = preg_split( '/[\s,;]+/', is_string( $value ) ? trim( $value ) : '' );
		$recipients = array();

		foreach ( is_array( $parts ) ? $parts : array() as $part ) {
			if ( '' === $part ) {
				continue;
			}

			if ( ! is_email( $part ) || false !== strpos( $part, "\n" ) || false !== strpos( $part, "\r" ) ) {
				return new WP_Error( 'cea_form_email_recipient', __( 'Email actions require valid recipient addresses.', 'cea-plugin' ) );
			}

			$recipients[] = sanitize_email( $part );
		}

		if ( empty( $recipients ) ) {
			return new WP_Error( 'cea_form_email_recipient', __( 'Email actions require at least one recipient.', 'cea-plugin' ) );
		}

		return array_values( array_unique( $recipients ) );
	}
}
