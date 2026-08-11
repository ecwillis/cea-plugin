<?php
/**
 * Form action dispatcher.
 *
 * @package CEA_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Executes configured actions and resolves submission template tokens.
 */
final class CEA_Form_Action_Dispatcher {

	/**
	 * Administrator-facing action failure transient prefix.
	 */
	const FAILURE_TRANSIENT_PREFIX = 'cea_form_action_failure_';

	/**
	 * Executes all enabled actions.
	 *
	 * @param array<int, array<string, mixed>> $actions    Form actions.
	 * @param array<string, mixed>             $submission Normalized submission.
	 * @return array<string, WP_Error>
	 */
	public static function dispatch( $actions, $submission ) {
		$errors = array();

		/**
		 * Fires immediately before form actions execute.
		 *
		 * @param array<string, mixed>             $submission Normalized submission.
		 * @param array<int, array<string, mixed>> $actions    Form actions.
		 */
		do_action( 'cea_form_before_actions', $submission, $actions );

		foreach ( $actions as $action ) {
			if ( empty( $action['enabled'] ) || empty( $action['type'] ) ) {
				continue;
			}

			$definition = CEA_Form_Action_Registry::get( $action['type'] );
			$action_id  = ! empty( $action['id'] ) ? sanitize_key( $action['id'] ) : sanitize_key( $action['type'] );
			$settings   = ! empty( $action['settings'] ) && is_array( $action['settings'] ) ? $action['settings'] : array();
			$valid      = CEA_Form_Action_Registry::validate_settings( $action['type'], $settings );

			if ( is_wp_error( $valid ) ) {
				$errors[ $action_id ] = $valid;
				self::announce_failure( $submission, $action, $valid );
				continue;
			}

			if ( null === $definition || ! is_callable( $definition['execute_callback'] ) ) {
				$error                = new WP_Error( 'cea_form_action_unavailable', __( 'A configured form action is unavailable.', 'cea-plugin' ) );
				$errors[ $action_id ] = $error;
				self::announce_failure( $submission, $action, $error );
				continue;
			}

			$result = call_user_func( $definition['execute_callback'], $submission, $settings );

			if ( true !== $result && ! is_wp_error( $result ) ) {
				$result = new WP_Error( 'cea_form_action_failed', __( 'The form action did not complete successfully.', 'cea-plugin' ) );
			}

			if ( is_wp_error( $result ) ) {
				$errors[ $action_id ] = $result;
				self::announce_failure( $submission, $action, $result );
			}

			/**
			 * Fires after an individual form action executes.
			 *
			 * @param true|WP_Error           $result     Action result.
			 * @param array<string, mixed>    $action     Action configuration.
			 * @param array<string, mixed>    $submission Normalized submission.
			 */
			do_action( 'cea_form_action_executed', $result, $action, $submission );
		}

		/**
		 * Fires after all form actions execute.
		 *
		 * @param array<string, WP_Error> $errors     Action errors keyed by action ID.
		 * @param array<string, mixed>    $submission Normalized submission.
		 */
		do_action( 'cea_form_after_actions', $errors, $submission );

		return $errors;
	}

	/**
	 * Replaces allowlisted tokens in an action template.
	 *
	 * @param string               $template   Template content.
	 * @param array<string, mixed> $submission Normalized submission.
	 * @return string
	 */
	public static function replace_tokens( $template, $submission ) {
		$fields = ! empty( $submission['fields'] ) && is_array( $submission['fields'] ) ? $submission['fields'] : array();
		$tokens = array(
			'form.id'     => isset( $submission['form_id'] ) ? (string) absint( $submission['form_id'] ) : '',
			'form.title'  => isset( $submission['form_title'] ) ? (string) $submission['form_title'] : '',
			'site.name'   => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'site.url'    => home_url( '/' ),
			'submitted_at' => isset( $submission['submitted_at'] ) ? (string) $submission['submitted_at'] : '',
			'all_fields'  => self::format_all_fields( $fields ),
		);

		foreach ( $fields as $key => $field ) {
			$tokens[ 'field.' . sanitize_key( $key ) ] = isset( $field['value'] ) ? self::format_value( $field['value'] ) : '';
		}

		$result = preg_replace_callback(
			'/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/',
			static function ( $matches ) use ( $tokens ) {
				return isset( $tokens[ $matches[1] ] ) ? $tokens[ $matches[1] ] : '';
			},
			(string) $template
		);

		return is_string( $result ) ? $result : '';
	}

	/**
	 * Formats a submitted field value.
	 *
	 * @param mixed $value Field value.
	 * @return string
	 */
	public static function format_value( $value ) {
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( 'strval', $value ) );
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Formats all submitted fields for text templates.
	 *
	 * @param array<string, array<string, mixed>> $fields Submitted fields.
	 * @return string
	 */
	private static function format_all_fields( $fields ) {
		$lines = array();

		foreach ( $fields as $field ) {
			$label = ! empty( $field['label'] ) ? (string) $field['label'] : __( 'Field', 'cea-plugin' );
			$value = isset( $field['value'] ) ? self::format_value( $field['value'] ) : '';
			$lines[] = $label . ': ' . $value;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Announces an action failure without exposing submission values.
	 *
	 * @param array<string, mixed> $submission Submission data.
	 * @param array<string, mixed> $action     Action configuration.
	 * @param WP_Error             $error      Action error.
	 * @return void
	 */
	private static function announce_failure( $submission, $action, $error ) {
		/**
		 * Fires when a form action fails.
		 *
		 * Submitted field values are intentionally not passed as separate arguments.
		 *
		 * @param int      $form_id Form ID.
		 * @param string   $type    Action type.
		 * @param WP_Error $error   Action error.
		 */
		do_action(
			'cea_form_action_failed',
			isset( $submission['form_id'] ) ? absint( $submission['form_id'] ) : 0,
			isset( $action['type'] ) ? sanitize_key( $action['type'] ) : '',
			$error
		);

		$form_id = isset( $submission['form_id'] ) ? absint( $submission['form_id'] ) : 0;

		if ( 0 < $form_id ) {
			$key      = self::FAILURE_TRANSIENT_PREFIX . $form_id;
			$failures = get_transient( $key );
			$failures = is_array( $failures ) ? $failures : array();
			$failures[] = array(
				'time'    => current_time( 'mysql' ),
				'type'    => isset( $action['type'] ) ? sanitize_key( $action['type'] ) : '',
				'message' => sanitize_text_field( $error->get_error_message() ),
			);
			$failures = array_slice( $failures, -5 );

			set_transient( $key, $failures, DAY_IN_SECONDS );
		}
	}
}
