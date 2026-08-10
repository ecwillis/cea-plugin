<?php
/**
 * Public form renderer.
 *
 * @package CEA_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders published forms and submission results.
 */
final class CEA_Form_Renderer {

	/**
	 * Rendered form instance counter.
	 *
	 * @var int
	 */
	private static $instance = 0;

	/**
	 * Renders the cea_form shortcode.
	 *
	 * @param array<string, mixed> $attributes Shortcode attributes.
	 * @return string
	 */
	public static function render_shortcode( $attributes ) {
		$attributes = shortcode_atts( array( 'id' => 0 ), is_array( $attributes ) ? $attributes : array(), 'cea_form' );
		$form       = CEA_Forms::get_published_form( absint( $attributes['id'] ) );

		if ( null === $form ) {
			return '';
		}

		$fields  = CEA_Forms::get_fields( $form->ID );
		$actions = CEA_Forms::get_actions( $form->ID );

		if ( empty( $fields ) || ! empty( CEA_Form_Schema::validate_configuration( $fields, $actions ) ) ) {
			return '';
		}

		CEA_Forms::enqueue_public_assets();
		++self::$instance;

		$instance = 'cea-form-' . $form->ID . '-' . self::$instance;
		$result   = self::consume_result( $form->ID );
		$values   = ! empty( $result['values'] ) && is_array( $result['values'] ) ? $result['values'] : array();
		$errors   = ! empty( $result['errors'] ) && is_array( $result['errors'] ) ? $result['errors'] : array();

		ob_start();

		/**
		 * Fires before a public CEA form renders.
		 *
		 * @param WP_Post $form Form post.
		 * @param string  $instance Unique rendered instance ID.
		 */
		do_action( 'cea_form_before', $form, $instance );
		?>
		<div class="cea-form" id="<?php echo esc_attr( $instance ); ?>">
			<?php self::render_result( $result, $instance ); ?>

			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="cea-form__form" novalidate>
				<input type="hidden" name="action" value="cea_form_submit">
				<input type="hidden" name="cea_form_id" value="<?php echo esc_attr( $form->ID ); ?>">
				<input type="hidden" name="cea_form_started_at" value="<?php echo esc_attr( time() ); ?>" data-cea-started-at>
				<input type="hidden" name="cea_form_submission_token" value="" data-cea-submission-token>
				<?php wp_nonce_field( 'cea_form_submit_' . $form->ID, 'cea_form_nonce' ); ?>

				<div class="cea-form__honeypot" aria-hidden="true">
					<label for="<?php echo esc_attr( $instance . '-website' ); ?>"><?php echo esc_html__( 'Leave this field empty', 'cea-plugin' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $instance . '-website' ); ?>" name="cea_form_website" value="" tabindex="-1" autocomplete="off">
				</div>

				<?php foreach ( $fields as $field ) : ?>
					<?php
					echo self::render_field(
						$field,
						$instance,
						array_key_exists( $field['key'], $values ) ? $values[ $field['key'] ] : '',
						isset( $errors[ $field['key'] ] ) ? $errors[ $field['key'] ] : ''
					); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by render_field().
					?>
				<?php endforeach; ?>

				<p class="cea-form__submit">
					<button type="submit"><?php echo esc_html__( 'Submit', 'cea-plugin' ); ?></button>
				</p>
			</form>
		</div>
		<?php
		/**
		 * Fires after a public CEA form renders.
		 *
		 * @param WP_Post $form Form post.
		 * @param string  $instance Unique rendered instance ID.
		 */
		do_action( 'cea_form_after', $form, $instance );

		return (string) ob_get_clean();
	}

	/**
	 * Renders one schema field.
	 *
	 * @param array<string, mixed> $field    Field configuration.
	 * @param string               $instance Form instance ID.
	 * @param mixed                $value    Submitted value.
	 * @param string               $error    Validation error.
	 * @return string
	 */
	private static function render_field( $field, $instance, $value, $error ) {
		$key            = sanitize_key( $field['key'] );
		$id             = $instance . '-' . $key;
		$name           = 'cea_form_fields[' . $key . ']';
		$description_id = $id . '-description';
		$error_id       = $id . '-error';
		$described_by   = array();

		if ( ! empty( $field['description'] ) ) {
			$described_by[] = $description_id;
		}

		if ( '' !== $error ) {
			$described_by[] = $error_id;
		}

		$common = array(
			'id'               => $id,
			'name'             => $name,
			'aria-describedby' => implode( ' ', $described_by ),
			'aria-invalid'     => '' !== $error ? 'true' : '',
		);

		ob_start();
		?>
		<div class="cea-form__field cea-form__field--<?php echo esc_attr( $field['type'] ); ?><?php echo '' !== $error ? ' cea-form__field--error' : ''; ?>">
			<?php if ( 'radio' === $field['type'] ) : ?>
				<fieldset<?php echo '' !== $error ? ' aria-invalid="true"' : ''; ?><?php echo ! empty( $described_by ) ? ' aria-describedby="' . esc_attr( implode( ' ', $described_by ) ) . '"' : ''; ?>>
					<legend>
						<?php echo esc_html( $field['label'] ); ?>
						<?php self::render_required_marker( $field ); ?>
					</legend>
					<?php foreach ( $field['choices'] as $choice_index => $choice ) : ?>
						<?php $choice_id = $id . '-' . absint( $choice_index ); ?>
						<label class="cea-form__choice" for="<?php echo esc_attr( $choice_id ); ?>">
							<input type="radio" id="<?php echo esc_attr( $choice_id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $choice['value'] ); ?>" <?php checked( (string) $value, $choice['value'] ); ?> <?php echo ! empty( $field['required'] ) ? 'required' : ''; ?>>
							<?php echo esc_html( $choice['label'] ); ?>
						</label>
					<?php endforeach; ?>
			<?php elseif ( 'checkbox' === $field['type'] ) : ?>
				<label class="cea-form__checkbox" for="<?php echo esc_attr( $id ); ?>">
					<input
						type="checkbox"
						id="<?php echo esc_attr( $id ); ?>"
						name="<?php echo esc_attr( $name ); ?>"
						value="1"
						<?php checked( (string) $value, '1' ); ?>
						<?php echo ! empty( $field['required'] ) ? 'required' : ''; ?>
						<?php echo '' !== $error ? 'aria-invalid="true"' : ''; ?>
						<?php echo ! empty( $described_by ) ? 'aria-describedby="' . esc_attr( implode( ' ', $described_by ) ) . '"' : ''; ?>
					>
					<?php echo esc_html( $field['label'] ); ?>
					<?php self::render_required_marker( $field ); ?>
				</label>
			<?php else : ?>
				<label for="<?php echo esc_attr( $id ); ?>">
					<?php echo esc_html( $field['label'] ); ?>
					<?php self::render_required_marker( $field ); ?>
				</label>

				<?php if ( 'textarea' === $field['type'] ) : ?>
					<textarea
						id="<?php echo esc_attr( $common['id'] ); ?>"
						name="<?php echo esc_attr( $common['name'] ); ?>"
						<?php echo ! empty( $field['placeholder'] ) ? 'placeholder="' . esc_attr( $field['placeholder'] ) . '"' : ''; ?>
						<?php echo ! empty( $field['required'] ) ? 'required' : ''; ?>
						<?php echo '' !== $common['aria-describedby'] ? 'aria-describedby="' . esc_attr( $common['aria-describedby'] ) . '"' : ''; ?>
						<?php echo '' !== $common['aria-invalid'] ? 'aria-invalid="true"' : ''; ?>
					><?php echo esc_textarea( is_scalar( $value ) ? (string) $value : '' ); ?></textarea>
				<?php elseif ( 'select' === $field['type'] ) : ?>
					<select
						id="<?php echo esc_attr( $common['id'] ); ?>"
						name="<?php echo esc_attr( $common['name'] ); ?>"
						<?php echo ! empty( $field['required'] ) ? 'required' : ''; ?>
						<?php echo '' !== $common['aria-describedby'] ? 'aria-describedby="' . esc_attr( $common['aria-describedby'] ) . '"' : ''; ?>
						<?php echo '' !== $common['aria-invalid'] ? 'aria-invalid="true"' : ''; ?>
					>
						<option value=""><?php echo esc_html( ! empty( $field['placeholder'] ) ? $field['placeholder'] : __( 'Select an option', 'cea-plugin' ) ); ?></option>
						<?php foreach ( $field['choices'] as $choice ) : ?>
							<option value="<?php echo esc_attr( $choice['value'] ); ?>" <?php selected( (string) $value, $choice['value'] ); ?>><?php echo esc_html( $choice['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php else : ?>
					<input
						type="<?php echo esc_attr( in_array( $field['type'], array( 'email', 'tel' ), true ) ? $field['type'] : 'text' ); ?>"
						id="<?php echo esc_attr( $common['id'] ); ?>"
						name="<?php echo esc_attr( $common['name'] ); ?>"
						value="<?php echo esc_attr( is_scalar( $value ) ? (string) $value : '' ); ?>"
						<?php echo ! empty( $field['placeholder'] ) ? 'placeholder="' . esc_attr( $field['placeholder'] ) . '"' : ''; ?>
						<?php echo ! empty( $field['required'] ) ? 'required' : ''; ?>
						<?php echo '' !== $common['aria-describedby'] ? 'aria-describedby="' . esc_attr( $common['aria-describedby'] ) . '"' : ''; ?>
						<?php echo '' !== $common['aria-invalid'] ? 'aria-invalid="true"' : ''; ?>
					>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( ! empty( $field['description'] ) ) : ?>
				<p class="cea-form__description" id="<?php echo esc_attr( $description_id ); ?>"><?php echo esc_html( $field['description'] ); ?></p>
			<?php endif; ?>

			<?php if ( '' !== $error ) : ?>
				<p class="cea-form__error" id="<?php echo esc_attr( $error_id ); ?>"><?php echo esc_html( $error ); ?></p>
			<?php endif; ?>

			<?php if ( 'radio' === $field['type'] ) : ?>
				</fieldset>
			<?php endif; ?>
		</div>
		<?php
		$html = (string) ob_get_clean();

		/**
		 * Filters one rendered form field.
		 *
		 * @param string               $html     Escaped field HTML.
		 * @param array<string, mixed> $field    Field configuration.
		 * @param mixed                $value    Current value.
		 * @param string               $error    Validation error.
		 * @param string               $instance Form instance ID.
		 */
		return (string) apply_filters( 'cea_form_field_html', $html, $field, $value, $error, $instance );
	}

	/**
	 * Renders a required marker.
	 *
	 * @param array<string, mixed> $field Field configuration.
	 * @return void
	 */
	private static function render_required_marker( $field ) {
		if ( ! empty( $field['required'] ) ) {
			echo ' <span class="cea-form__required" aria-hidden="true">*</span>';
			echo '<span class="screen-reader-text"> ' . esc_html__( '(required)', 'cea-plugin' ) . '</span>';
		}
	}

	/**
	 * Renders a stored result.
	 *
	 * @param array<string, mixed> $result   Submission result.
	 * @param string               $instance Form instance ID.
	 * @return void
	 */
	private static function render_result( $result, $instance ) {
		if ( empty( $result['status'] ) || empty( $result['message'] ) ) {
			return;
		}

		$is_success = 'success' === $result['status'];
		?>
		<div class="cea-form__notice cea-form__notice--<?php echo esc_attr( $is_success ? 'success' : 'error' ); ?>" role="<?php echo esc_attr( $is_success ? 'status' : 'alert' ); ?>" tabindex="-1" data-cea-result>
			<p><?php echo esc_html( $result['message'] ); ?></p>
			<?php if ( ! $is_success && ! empty( $result['errors'] ) && is_array( $result['errors'] ) ) : ?>
				<ul aria-label="<?php echo esc_attr__( 'Fields with errors', 'cea-plugin' ); ?>">
					<?php foreach ( $result['errors'] as $field_key => $message ) : ?>
						<li><a href="#<?php echo esc_attr( $instance . '-' . sanitize_key( $field_key ) ); ?>"><?php echo esc_html( $message ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Consumes a one-time result for this form.
	 *
	 * @param int $form_id Form ID.
	 * @return array<string, mixed>
	 */
	private static function consume_result( $form_id ) {
		$token = isset( $_GET['cea_form_result'] ) ? sanitize_text_field( wp_unslash( $_GET['cea_form_result'] ) ) : '';

		if ( 1 !== preg_match( '/^[a-zA-Z0-9]{32}$/', $token ) ) {
			return array();
		}

		$key    = CEA_Form_Submission_Handler::RESULT_TRANSIENT_PREFIX . md5( $token );
		$result = get_transient( $key );

		if ( ! is_array( $result ) || absint( isset( $result['form_id'] ) ? $result['form_id'] : 0 ) !== absint( $form_id ) ) {
			return array();
		}

		delete_transient( $key );

		return $result;
	}
}
