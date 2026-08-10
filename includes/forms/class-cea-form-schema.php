<?php
/**
 * Form configuration schema and sanitization.
 *
 * @package CEA_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes form fields, actions, and settings.
 */
final class CEA_Form_Schema {

	/**
	 * Maximum fields per form.
	 */
	const MAX_FIELDS = 50;

	/**
	 * Maximum actions per form.
	 */
	const MAX_ACTIONS = 10;

	/**
	 * Returns supported field types.
	 *
	 * @return array<string, string>
	 */
	public static function get_field_types() {
		$types = array(
			'text'     => __( 'Text', 'cea-plugin' ),
			'email'    => __( 'Email', 'cea-plugin' ),
			'tel'      => __( 'Telephone', 'cea-plugin' ),
			'textarea' => __( 'Textarea', 'cea-plugin' ),
			'select'   => __( 'Select', 'cea-plugin' ),
			'radio'    => __( 'Radio buttons', 'cea-plugin' ),
			'checkbox' => __( 'Checkbox', 'cea-plugin' ),
		);

		/**
		 * Filters supported form field types.
		 *
		 * Custom field types must also use the field-validation filters.
		 *
		 * @param array<string, string> $types Field type labels keyed by type.
		 */
		$types = apply_filters( 'cea_form_field_types', $types );

		return is_array( $types ) ? $types : array();
	}

	/**
	 * Returns a default field definition.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_default_field() {
		return array(
			'key'         => '',
			'type'        => 'text',
			'label'       => __( 'Name', 'cea-plugin' ),
			'required'    => true,
			'placeholder' => '',
			'description' => '',
			'choices'     => array(),
		);
	}

	/**
	 * Returns default form settings.
	 *
	 * @return array<string, string>
	 */
	public static function get_default_settings() {
		return array(
			'success_message' => __( 'Thank you. Your submission has been received.', 'cea-plugin' ),
			'redirect_url'    => '',
		);
	}

	/**
	 * Returns a new default email action.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_default_action() {
		return array(
			'id'       => '',
			'type'     => 'email',
			'enabled'  => true,
			'settings' => CEA_Form_Email_Action::get_defaults(),
		);
	}

	/**
	 * Sanitizes form fields.
	 *
	 * @param mixed $raw_fields Submitted or stored fields.
	 * @return array<int, array<string, mixed>>
	 */
	public static function sanitize_fields( $raw_fields ) {
		if ( ! is_array( $raw_fields ) ) {
			return array();
		}

		$fields    = array();
		$used_keys = array();
		$allowed   = array_keys( self::get_field_types() );
		$limit     = max( 1, absint( apply_filters( 'cea_form_max_fields', self::MAX_FIELDS ) ) );
		$raw_fields = array_slice( $raw_fields, 0, $limit );

		foreach ( $raw_fields as $raw_field ) {
			if ( ! is_array( $raw_field ) ) {
				continue;
			}

			$label = isset( $raw_field['label'] ) && is_scalar( $raw_field['label'] )
				? sanitize_text_field( (string) $raw_field['label'] )
				: '';

			if ( '' === $label ) {
				continue;
			}

			$key = isset( $raw_field['key'] ) && is_scalar( $raw_field['key'] )
				? sanitize_key( (string) $raw_field['key'] )
				: '';

			if ( '' === $key || isset( $used_keys[ $key ] ) ) {
				$key = self::generate_id( 'field_' );
			}

			$used_keys[ $key ] = true;
			$type              = isset( $raw_field['type'] ) && is_scalar( $raw_field['type'] )
				? sanitize_key( (string) $raw_field['type'] )
				: 'text';
			$type              = in_array( $type, $allowed, true ) ? $type : 'text';
			$choices           = in_array( $type, array( 'select', 'radio' ), true )
				? self::sanitize_choices( isset( $raw_field['choices'] ) ? $raw_field['choices'] : array() )
				: array();

			$fields[] = array(
				'key'         => $key,
				'type'        => $type,
				'label'       => $label,
				'required'    => ! empty( $raw_field['required'] ),
				'placeholder' => isset( $raw_field['placeholder'] ) && is_scalar( $raw_field['placeholder'] )
					? sanitize_text_field( (string) $raw_field['placeholder'] )
					: '',
				'description' => isset( $raw_field['description'] ) && is_scalar( $raw_field['description'] )
					? sanitize_text_field( (string) $raw_field['description'] )
					: '',
				'choices'     => $choices,
			);
		}

		return $fields;
	}

	/**
	 * Sanitizes form actions while preserving undisplayed secrets.
	 *
	 * @param mixed                            $raw_actions Submitted or stored actions.
	 * @param array<int, array<string, mixed>> $existing    Existing action configuration.
	 * @return array<int, array<string, mixed>>
	 */
	public static function sanitize_actions( $raw_actions, $existing = array() ) {
		if ( ! is_array( $raw_actions ) ) {
			return array();
		}

		$actions       = array();
		$existing_by_id = array();
		$used_ids      = array();
		$limit         = max( 1, absint( apply_filters( 'cea_form_max_actions', self::MAX_ACTIONS ) ) );
		$raw_actions   = array_slice( $raw_actions, 0, $limit );

		foreach ( $existing as $existing_action ) {
			if ( is_array( $existing_action ) && ! empty( $existing_action['id'] ) && is_scalar( $existing_action['id'] ) ) {
				$existing_by_id[ sanitize_key( (string) $existing_action['id'] ) ] = $existing_action;
			}
		}

		foreach ( $raw_actions as $raw_action ) {
			if ( ! is_array( $raw_action ) ) {
				continue;
			}

			$type = isset( $raw_action['type'] ) && is_scalar( $raw_action['type'] )
				? sanitize_key( (string) $raw_action['type'] )
				: '';

			if ( null === CEA_Form_Action_Registry::get( $type ) ) {
				continue;
			}

			$id = isset( $raw_action['id'] ) && is_scalar( $raw_action['id'] )
				? sanitize_key( (string) $raw_action['id'] )
				: '';

			if ( '' === $id || isset( $used_ids[ $id ] ) ) {
				$id = self::generate_id( 'action_' );
			}

			$used_ids[ $id ] = true;
			$old             = isset( $existing_by_id[ $id ]['settings'] ) && is_array( $existing_by_id[ $id ]['settings'] )
				? $existing_by_id[ $id ]['settings']
				: array();
			$settings        = CEA_Form_Action_Registry::sanitize_settings(
				$type,
				isset( $raw_action['settings'] ) ? $raw_action['settings'] : array(),
				$old
			);

			$actions[] = array(
				'id'       => $id,
				'type'     => $type,
				'enabled'  => ! empty( $raw_action['enabled'] ),
				'settings' => $settings,
			);
		}

		return $actions;
	}

	/**
	 * Sanitizes general form settings.
	 *
	 * @param mixed $raw_settings Submitted or stored settings.
	 * @return array<string, string>
	 */
	public static function sanitize_settings( $raw_settings ) {
		$raw_settings = is_array( $raw_settings ) ? $raw_settings : array();
		$defaults     = self::get_default_settings();
		$message      = isset( $raw_settings['success_message'] ) && is_scalar( $raw_settings['success_message'] )
			? sanitize_textarea_field( (string) $raw_settings['success_message'] )
			: $defaults['success_message'];
		$redirect     = isset( $raw_settings['redirect_url'] ) && is_string( $raw_settings['redirect_url'] )
			? trim( $raw_settings['redirect_url'] )
			: '';

		if ( '' === $message ) {
			$message = $defaults['success_message'];
		}

		$redirect = self::normalize_same_site_url( $redirect );

		return array(
			'success_message' => $message,
			'redirect_url'    => $redirect,
		);
	}

	/**
	 * Validates a complete form configuration.
	 *
	 * @param array<int, array<string, mixed>> $fields  Form fields.
	 * @param array<int, array<string, mixed>> $actions Form actions.
	 * @return array<int, string>
	 */
	public static function validate_configuration( $fields, $actions ) {
		$errors = array();

		if ( empty( $fields ) ) {
			$errors[] = __( 'Add at least one labeled field before publishing the form.', 'cea-plugin' );
		}

		foreach ( $fields as $field ) {
			if ( in_array( $field['type'], array( 'select', 'radio' ), true ) && empty( $field['choices'] ) ) {
				$errors[] = sprintf(
					/* translators: %s: Form field label. */
					__( 'The “%s” field requires at least one choice.', 'cea-plugin' ),
					$field['label']
				);
			}
		}

		$enabled = 0;

		foreach ( $actions as $action ) {
			if ( empty( $action['enabled'] ) ) {
				continue;
			}

			++$enabled;
			$result = CEA_Form_Action_Registry::validate_settings( $action['type'], $action['settings'] );

			if ( is_wp_error( $result ) ) {
				$definition = CEA_Form_Action_Registry::get( $action['type'] );
				$label      = null !== $definition ? $definition['label'] : $action['type'];
				$errors[]   = sprintf(
					/* translators: 1: Action label, 2: Validation message. */
					__( '%1$s action: %2$s', 'cea-plugin' ),
					$label,
					$result->get_error_message()
				);
			}
		}

		if ( 0 === $enabled ) {
			$errors[] = __( 'Enable at least one valid action before publishing the form.', 'cea-plugin' );
		}

		return $errors;
	}

	/**
	 * Converts choice input into stable value/label pairs.
	 *
	 * Lines may use value|Label or a label by itself.
	 *
	 * @param mixed $raw_choices Submitted choices.
	 * @return array<int, array<string, string>>
	 */
	public static function sanitize_choices( $raw_choices ) {
		if ( is_string( $raw_choices ) ) {
			$raw_choices = preg_split( '/\r\n|\r|\n/', $raw_choices );
		}

		if ( ! is_array( $raw_choices ) ) {
			return array();
		}

		$choices = array();
		$used    = array();

		foreach ( array_slice( $raw_choices, 0, 100 ) as $index => $raw_choice ) {
			if ( is_array( $raw_choice ) ) {
				$label = isset( $raw_choice['label'] ) ? sanitize_text_field( $raw_choice['label'] ) : '';
				$value = isset( $raw_choice['value'] ) ? sanitize_key( $raw_choice['value'] ) : '';
			} else {
				$parts = explode( '|', (string) $raw_choice, 2 );
				$value = 2 === count( $parts ) ? sanitize_key( trim( $parts[0] ) ) : '';
				$label = sanitize_text_field( 2 === count( $parts ) ? trim( $parts[1] ) : trim( $parts[0] ) );
			}

			if ( '' === $label ) {
				continue;
			}

			if ( '' === $value ) {
				$value = sanitize_key( $label );
			}

			if ( '' === $value ) {
				$value = 'choice_' . ( absint( $index ) + 1 );
			}

			$base   = $value;
			$suffix = 2;

			while ( isset( $used[ $value ] ) ) {
				$value = $base . '_' . $suffix;
				++$suffix;
			}

			$used[ $value ] = true;
			$choices[]      = array(
				'value' => $value,
				'label' => $label,
			);
		}

		return $choices;
	}

	/**
	 * Converts stored choices to administrator textarea syntax.
	 *
	 * @param array<int, array<string, string>> $choices Choices.
	 * @return string
	 */
	public static function choices_to_text( $choices ) {
		$lines = array();

		foreach ( $choices as $choice ) {
			if ( ! empty( $choice['value'] ) && ! empty( $choice['label'] ) ) {
				$lines[] = $choice['value'] . '|' . $choice['label'];
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Normalizes a redirect URL and requires it to remain on the current site.
	 *
	 * @param mixed $url Redirect URL.
	 * @return string
	 */
	public static function normalize_same_site_url( $url ) {
		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			return '';
		}

		$url = trim( $url );

		if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
			$url = home_url( $url );
		}

		$url       = esc_url_raw( $url );
		$host      = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$scheme    = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );

		if ( '' === $url || '' === $host || $home_host !== $host || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * Generates a stable schema identifier.
	 *
	 * @param string $prefix Identifier prefix.
	 * @return string
	 */
	private static function generate_id( $prefix ) {
		return sanitize_key( $prefix . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 ) );
	}
}
