<?php
/**
 * Public form submission handler.
 *
 * @package CEA_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Validates public submissions and dispatches configured actions.
 */
final class CEA_Form_Submission_Handler {

	/**
	 * One-time result transient prefix.
	 */
	const RESULT_TRANSIENT_PREFIX = 'cea_form_result_';

	/**
	 * Processed submission transient prefix.
	 */
	const PROCESSED_TRANSIENT_PREFIX = 'cea_form_processed_';

	/**
	 * Rate-limit transient prefix.
	 */
	const RATE_TRANSIENT_PREFIX = 'cea_form_rate_';

	/**
	 * Handles a public or authenticated form submission.
	 *
	 * @return void
	 */
	public static function handle() {
		$form_id = isset( $_POST['cea_form_id'] ) ? absint( $_POST['cea_form_id'] ) : 0;
		$form    = CEA_Forms::get_published_form( $form_id );

		if ( null === $form ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		$settings = CEA_Forms::get_settings( $form_id );

		if ( ! empty( $_POST['cea_form_website'] ) ) {
			self::redirect_success( $form_id, $settings );
		}

		$nonce = isset( $_POST['cea_form_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['cea_form_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'cea_form_submit_' . $form_id ) ) {
			self::redirect_error(
				$form_id,
				__( 'This form has expired. Refresh the page and try again.', 'cea-plugin' )
			);
		}

		$started_at = isset( $_POST['cea_form_started_at'] ) ? absint( $_POST['cea_form_started_at'] ) : 0;

		if ( 0 === $started_at || $started_at > time() || 2 > ( time() - $started_at ) ) {
			self::redirect_error(
				$form_id,
				__( 'Please wait a moment before submitting the form.', 'cea-plugin' )
			);
		}

		if ( self::is_rate_limited( $form_id ) ) {
			self::redirect_error(
				$form_id,
				__( 'Too many submissions were received. Wait a minute and try again.', 'cea-plugin' )
			);
		}

		$fields     = CEA_Forms::get_fields( $form_id );
		$raw_values = isset( $_POST['cea_form_fields'] ) && is_array( $_POST['cea_form_fields'] )
			? wp_unslash( $_POST['cea_form_fields'] )
			: array();
		$validation = self::validate_fields( $fields, $raw_values );

		if ( ! empty( $validation['errors'] ) ) {
			self::redirect_error(
				$form_id,
				__( 'Check the highlighted fields and try again.', 'cea-plugin' ),
				$validation['errors'],
				$validation['values']
			);
		}

		$actions = CEA_Forms::get_actions( $form_id );

		if ( ! empty( CEA_Form_Schema::validate_configuration( $fields, $actions ) ) ) {
			self::redirect_error(
				$form_id,
				__( 'This form is temporarily unavailable. Please try again later.', 'cea-plugin' )
			);
		}

		$token = isset( $_POST['cea_form_submission_token'] )
			? sanitize_text_field( wp_unslash( $_POST['cea_form_submission_token'] ) )
			: '';

		if ( self::was_processed( $token ) ) {
			self::redirect_success( $form_id, $settings );
		}

		$submission = self::build_submission( $form, $fields, $validation['values'] );

		/**
		 * Filters a validated submission before actions execute.
		 *
		 * @param array<string, mixed> $submission Normalized submission.
		 * @param WP_Post              $form       Form post.
		 */
		$submission = apply_filters( 'cea_form_validated_submission', $submission, $form );

		if ( ! is_array( $submission ) ) {
			self::redirect_error(
				$form_id,
				__( 'This form could not be processed. Please try again.', 'cea-plugin' ),
				array(),
				$validation['values']
			);
		}

		self::mark_processed( $token );
		$action_errors = CEA_Form_Action_Dispatcher::dispatch( $actions, $submission );
		$enabled_count = count(
			array_filter(
				$actions,
				static function ( $action ) {
					return ! empty( $action['enabled'] );
				}
			)
		);

		if ( 0 < $enabled_count && count( $action_errors ) >= $enabled_count ) {
			self::unmark_processed( $token );
			self::redirect_error(
				$form_id,
				__( 'Your submission could not be delivered. Please try again.', 'cea-plugin' ),
				array(),
				$validation['values']
			);
		}

		self::redirect_success( $form_id, $settings );
	}

	/**
	 * Validates submitted values against the saved field schema.
	 *
	 * @param array<int, array<string, mixed>> $fields     Field schema.
	 * @param array<string, mixed>             $raw_values Submitted values.
	 * @return array{values: array<string, string>, errors: array<string, string>}
	 */
	public static function validate_fields( $fields, $raw_values ) {
		$values = array();
		$errors = array();

		foreach ( $fields as $field ) {
			$key        = sanitize_key( $field['key'] );
			$raw        = array_key_exists( $key, $raw_values ) ? $raw_values[ $key ] : '';
			$raw_string = is_scalar( $raw ) ? (string) $raw : '';
			$value      = '';
			$max        = 'textarea' === $field['type'] ? 10000 : 500;
			$too_long   = strlen( $raw_string ) > $max;

			switch ( $field['type'] ) {
				case 'checkbox':
					$value = '1' === $raw_string ? '1' : '';
					break;

				case 'email':
					$value = sanitize_email( $raw_string );

					if ( '' !== $raw_string && ! is_email( $value ) ) {
						$errors[ $key ] = sprintf(
							/* translators: %s: Field label. */
							__( 'Enter a valid value for %s.', 'cea-plugin' ),
							$field['label']
						);
					}
					break;

				case 'textarea':
					$value = sanitize_textarea_field( substr( $raw_string, 0, $max ) );
					break;

				case 'select':
				case 'radio':
					$value   = sanitize_key( $raw_string );
					$allowed = wp_list_pluck( $field['choices'], 'value' );

					if ( '' !== $value && ! in_array( $value, $allowed, true ) ) {
						$value          = '';
						$errors[ $key ] = sprintf(
							/* translators: %s: Field label. */
							__( 'Select a valid option for %s.', 'cea-plugin' ),
							$field['label']
						);
					}
					break;

				case 'tel':
				case 'text':
				default:
					$value = sanitize_text_field( substr( $raw_string, 0, $max ) );
					break;
			}

			if ( $too_long ) {
				$errors[ $key ] = sprintf(
					/* translators: %s: Field label. */
					__( '%s is too long.', 'cea-plugin' ),
					$field['label']
				);
			}

			if ( ! empty( $field['required'] ) && '' === $value ) {
				$errors[ $key ] = sprintf(
					/* translators: %s: Field label. */
					__( '%s is required.', 'cea-plugin' ),
					$field['label']
				);
			}

			/**
			 * Filters one sanitized field value.
			 *
			 * @param string               $value Sanitized value.
			 * @param array<string, mixed> $field Field schema.
			 * @param mixed                $raw   Raw submitted value.
			 */
			$value = apply_filters( 'cea_form_sanitized_field_value', $value, $field, $raw );

			$values[ $key ] = is_scalar( $value ) ? (string) $value : '';
		}

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	/**
	 * Builds action-ready submission data.
	 *
	 * @param WP_Post                         $form   Form post.
	 * @param array<int, array<string, mixed>> $fields Field schema.
	 * @param array<string, string>            $values Valid values.
	 * @return array<string, mixed>
	 */
	private static function build_submission( $form, $fields, $values ) {
		$submitted_fields = array();

		foreach ( $fields as $field ) {
			$key                      = sanitize_key( $field['key'] );
			$submitted_fields[ $key ] = array(
				'label' => $field['label'],
				'type'  => $field['type'],
				'value' => isset( $values[ $key ] ) ? $values[ $key ] : '',
			);
		}

		return array(
			'form_id'      => absint( $form->ID ),
			'form_title'   => wp_strip_all_tags( get_the_title( $form ) ),
			'submitted_at' => current_time( 'mysql' ),
			'fields'       => $submitted_fields,
		);
	}

	/**
	 * Returns whether the current client exceeded the submission rate.
	 *
	 * @param int $form_id Form ID.
	 * @return bool
	 */
	private static function is_rate_limited( $form_id ) {
		$remote_address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key            = self::RATE_TRANSIENT_PREFIX . md5( $form_id . '|' . $remote_address . '|' . wp_salt( 'nonce' ) );
		$count          = absint( get_transient( $key ) );
		$limit          = max( 1, (int) apply_filters( 'cea_form_rate_limit', 5, $form_id ) );

		if ( $count >= $limit ) {
			return true;
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

		return false;
	}

	/**
	 * Returns whether a browser-generated idempotency token was processed.
	 *
	 * @param string $token Submission token.
	 * @return bool
	 */
	private static function was_processed( $token ) {
		if ( ! is_string( $token ) || 1 !== preg_match( '/^[a-zA-Z0-9-]{16,80}$/', $token ) ) {
			return false;
		}

		return false !== get_transient( self::PROCESSED_TRANSIENT_PREFIX . md5( $token ) );
	}

	/**
	 * Marks a browser-generated idempotency token as processed.
	 *
	 * @param string $token Submission token.
	 * @return void
	 */
	private static function mark_processed( $token ) {
		if ( is_string( $token ) && 1 === preg_match( '/^[a-zA-Z0-9-]{16,80}$/', $token ) ) {
			set_transient( self::PROCESSED_TRANSIENT_PREFIX . md5( $token ), 1, 10 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Clears a processed token when every configured action failed.
	 *
	 * @param string $token Submission token.
	 * @return void
	 */
	private static function unmark_processed( $token ) {
		if ( is_string( $token ) && 1 === preg_match( '/^[a-zA-Z0-9-]{16,80}$/', $token ) ) {
			delete_transient( self::PROCESSED_TRANSIENT_PREFIX . md5( $token ) );
		}
	}

	/**
	 * Redirects to a successful result or configured same-site page.
	 *
	 * @param int                   $form_id  Form ID.
	 * @param array<string, string> $settings Form settings.
	 * @return void
	 */
	private static function redirect_success( $form_id, $settings ) {
		if ( ! empty( $settings['redirect_url'] ) ) {
			$redirect = CEA_Form_Schema::normalize_same_site_url( $settings['redirect_url'] );

			if ( '' !== $redirect ) {
				wp_safe_redirect( $redirect );
				exit;
			}
		}

		self::redirect_with_result(
			$form_id,
			'success',
			isset( $settings['success_message'] ) ? $settings['success_message'] : CEA_Form_Schema::get_default_settings()['success_message']
		);
	}

	/**
	 * Redirects to an error result.
	 *
	 * @param int                   $form_id Form ID.
	 * @param string                $message Error message.
	 * @param array<string, string> $errors  Field errors.
	 * @param array<string, string> $values  Sanitized values.
	 * @return void
	 */
	private static function redirect_error( $form_id, $message, $errors = array(), $values = array() ) {
		self::redirect_with_result( $form_id, 'error', $message, $errors, $values );
	}

	/**
	 * Stores a one-time result and redirects back to the same-site referrer.
	 *
	 * @param int                   $form_id Form ID.
	 * @param string                $status  Result status.
	 * @param string                $message Result message.
	 * @param array<string, string> $errors  Field errors.
	 * @param array<string, string> $values  Sanitized values.
	 * @return void
	 */
	private static function redirect_with_result( $form_id, $status, $message, $errors = array(), $values = array() ) {
		$token    = wp_generate_password( 32, false, false );
		$key      = self::RESULT_TRANSIENT_PREFIX . md5( $token );
		$referrer = wp_get_referer();
		$redirect = CEA_Form_Schema::normalize_same_site_url( is_string( $referrer ) ? $referrer : '' );

		if ( '' === $redirect ) {
			$redirect = home_url( '/' );
		}

		$redirect = remove_query_arg( 'cea_form_result', $redirect );
		$redirect = add_query_arg( 'cea_form_result', rawurlencode( $token ), $redirect );

		set_transient(
			$key,
			array(
				'form_id' => absint( $form_id ),
				'status'  => 'success' === $status ? 'success' : 'error',
				'message' => sanitize_text_field( $message ),
				'errors'  => $errors,
				'values'  => $values,
			),
			5 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect( $redirect );
		exit;
	}
}
