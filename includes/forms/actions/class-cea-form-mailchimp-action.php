<?php
/**
 * Form Mailchimp action.
 *
 * @package CEA_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds consenting form submitters to a Mailchimp audience.
 */
final class CEA_Form_Mailchimp_Action {

	/**
	 * Returns the registry definition.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_definition() {
		return array(
			'label'             => __( 'Mailchimp', 'cea-plugin' ),
			'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
			'validate_callback' => array( __CLASS__, 'validate_settings' ),
			'render_callback'   => array( __CLASS__, 'render_settings' ),
			'execute_callback'  => array( __CLASS__, 'execute' ),
		);
	}

	/**
	 * Returns default Mailchimp action settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_defaults() {
		return array(
			'audience_id'          => '',
			'email_field'          => '',
			'consent_field'        => '',
			'status_if_new'        => 'pending',
			'first_name_field'     => '',
			'first_name_merge_tag' => 'FNAME',
			'last_name_field'      => '',
			'last_name_merge_tag'  => 'LNAME',
			'tags'                 => array(),
		);
	}

	/**
	 * Sanitizes Mailchimp action settings.
	 *
	 * @param array<string, mixed> $settings Submitted settings.
	 * @param array<string, mixed> $existing Existing settings.
	 * @return array<string, mixed>
	 */
	public static function sanitize_settings( $settings, $existing = array() ) {
		unset( $existing );

		$settings = is_array( $settings ) ? $settings : array();
		$defaults = self::get_defaults();
		$status   = isset( $settings['status_if_new'] ) && is_scalar( $settings['status_if_new'] )
			? sanitize_key( (string) $settings['status_if_new'] )
			: $defaults['status_if_new'];

		if ( ! in_array( $status, array( 'pending', 'subscribed' ), true ) ) {
			$status = $defaults['status_if_new'];
		}

		return array(
			'audience_id'          => self::sanitize_identifier( isset( $settings['audience_id'] ) ? $settings['audience_id'] : '' ),
			'email_field'          => self::sanitize_field_key( isset( $settings['email_field'] ) ? $settings['email_field'] : '' ),
			'consent_field'        => self::sanitize_field_key( isset( $settings['consent_field'] ) ? $settings['consent_field'] : '' ),
			'status_if_new'        => $status,
			'first_name_field'     => self::sanitize_field_key( isset( $settings['first_name_field'] ) ? $settings['first_name_field'] : '' ),
			'first_name_merge_tag' => self::sanitize_merge_tag( isset( $settings['first_name_merge_tag'] ) ? $settings['first_name_merge_tag'] : $defaults['first_name_merge_tag'] ),
			'last_name_field'      => self::sanitize_field_key( isset( $settings['last_name_field'] ) ? $settings['last_name_field'] : '' ),
			'last_name_merge_tag'  => self::sanitize_merge_tag( isset( $settings['last_name_merge_tag'] ) ? $settings['last_name_merge_tag'] : $defaults['last_name_merge_tag'] ),
			'tags'                 => self::sanitize_tags( isset( $settings['tags'] ) ? $settings['tags'] : array() ),
		);
	}

	/**
	 * Validates Mailchimp action settings.
	 *
	 * @param array<string, mixed> $settings Mailchimp settings.
	 * @param array<string, mixed> $context  Optional form context.
	 * @return true|WP_Error
	 */
	public static function validate_settings( $settings, $context = array() ) {
		$settings = wp_parse_args( is_array( $settings ) ? $settings : array(), self::get_defaults() );

		if ( ! CEA_Mailchimp_Settings::is_configured() ) {
			return new WP_Error( 'cea_form_mailchimp_connection', __( 'Configure the Mailchimp Marketing API connection before enabling this action.', 'cea-plugin' ) );
		}

		if ( '' === $settings['audience_id'] || $settings['audience_id'] !== self::sanitize_identifier( $settings['audience_id'] ) ) {
			return new WP_Error( 'cea_form_mailchimp_audience', __( 'Mailchimp actions require a valid audience ID.', 'cea-plugin' ) );
		}

		if ( '' === $settings['email_field'] ) {
			return new WP_Error( 'cea_form_mailchimp_email_field', __( 'Mailchimp actions require an email field mapping.', 'cea-plugin' ) );
		}

		if ( '' === $settings['consent_field'] ) {
			return new WP_Error( 'cea_form_mailchimp_consent_field', __( 'Mailchimp actions require a consent checkbox mapping.', 'cea-plugin' ) );
		}

		if ( ! in_array( $settings['status_if_new'], array( 'pending', 'subscribed' ), true ) ) {
			return new WP_Error( 'cea_form_mailchimp_status', __( 'Select a valid Mailchimp opt-in mode.', 'cea-plugin' ) );
		}

		if ( '' !== $settings['first_name_field'] && '' === $settings['first_name_merge_tag'] ) {
			return new WP_Error( 'cea_form_mailchimp_first_name_tag', __( 'Enter the Mailchimp merge tag for the mapped first-name field.', 'cea-plugin' ) );
		}

		if ( '' !== $settings['last_name_field'] && '' === $settings['last_name_merge_tag'] ) {
			return new WP_Error( 'cea_form_mailchimp_last_name_tag', __( 'Enter the Mailchimp merge tag for the mapped last-name field.', 'cea-plugin' ) );
		}

		if (
			'' !== $settings['first_name_field']
			&& '' !== $settings['last_name_field']
			&& $settings['first_name_merge_tag'] === $settings['last_name_merge_tag']
		) {
			return new WP_Error( 'cea_form_mailchimp_duplicate_merge_tag', __( 'First and last name must use different Mailchimp merge tags.', 'cea-plugin' ) );
		}

		$fields = isset( $context['fields'] ) && is_array( $context['fields'] ) ? $context['fields'] : array();

		if ( ! empty( $fields ) ) {
			$fields_by_key = array();

			foreach ( $fields as $field ) {
				if ( is_array( $field ) && ! empty( $field['key'] ) ) {
					$fields_by_key[ sanitize_key( $field['key'] ) ] = $field;
				}
			}

			if ( ! isset( $fields_by_key[ $settings['email_field'] ] ) || 'email' !== $fields_by_key[ $settings['email_field'] ]['type'] ) {
				return new WP_Error( 'cea_form_mailchimp_email_field', __( 'The mapped Mailchimp email field is missing or is not an email field.', 'cea-plugin' ) );
			}

			if ( ! isset( $fields_by_key[ $settings['consent_field'] ] ) || 'checkbox' !== $fields_by_key[ $settings['consent_field'] ]['type'] ) {
				return new WP_Error( 'cea_form_mailchimp_consent_field', __( 'The mapped Mailchimp consent field is missing or is not a checkbox.', 'cea-plugin' ) );
			}

			foreach ( array( 'first_name_field', 'last_name_field' ) as $mapping ) {
				if ( '' !== $settings[ $mapping ] && ! isset( $fields_by_key[ $settings[ $mapping ] ] ) ) {
					return new WP_Error( 'cea_form_mailchimp_name_field', __( 'A mapped Mailchimp name field no longer exists.', 'cea-plugin' ) );
				}
			}
		}

		return true;
	}

	/**
	 * Renders Mailchimp action settings.
	 *
	 * @param string               $name     Base field name.
	 * @param array<string, mixed> $settings Mailchimp settings.
	 * @param array<string, mixed> $context  Optional form context.
	 * @return void
	 */
	public static function render_settings( $name, $settings, $context = array() ) {
		$settings       = wp_parse_args( $settings, self::get_defaults() );
		$fields         = isset( $context['fields'] ) && is_array( $context['fields'] ) ? $context['fields'] : array();
		$audiences      = CEA_Mailchimp_Client::get_cached_audiences();
		$audience_list  = 'cea-mailchimp-audiences-' . preg_replace( '/[^a-zA-Z0-9_-]/', '-', $name );
		$settings_url   = admin_url( 'admin.php?page=' . CEA_Mailchimp_Settings_Page::SUBMENU_SLUG );
		$email_fields   = self::filter_fields_by_type( $fields, array( 'email' ) );
		$consent_fields = self::filter_fields_by_type( $fields, array( 'checkbox' ) );
		?>
		<?php if ( ! CEA_Mailchimp_Settings::is_configured() ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php echo esc_html__( 'Connect Mailchimp before enabling this action.', 'cea-plugin' ); ?>
					<a href="<?php echo esc_url( $settings_url ); ?>"><?php echo esc_html__( 'Open Mailchimp settings', 'cea-plugin' ); ?></a>
				</p>
			</div>
		<?php endif; ?>
		<div class="cea-form-builder-grid">
			<p>
				<label>
					<strong><?php echo esc_html__( 'Audience ID', 'cea-plugin' ); ?></strong>
					<input type="text" class="widefat" name="<?php echo esc_attr( $name ); ?>[audience_id]" value="<?php echo esc_attr( $settings['audience_id'] ); ?>" list="<?php echo esc_attr( $audience_list ); ?>">
				</label>
				<datalist id="<?php echo esc_attr( $audience_list ); ?>">
					<?php foreach ( $audiences as $audience ) : ?>
						<option value="<?php echo esc_attr( $audience['id'] ); ?>"><?php echo esc_html( $audience['name'] ); ?></option>
					<?php endforeach; ?>
				</datalist>
				<span class="description">
					<?php echo esc_html__( 'Choose a cached audience or enter its ID.', 'cea-plugin' ); ?>
					<a href="<?php echo esc_url( $settings_url ); ?>"><?php echo esc_html__( 'Refresh audiences', 'cea-plugin' ); ?></a>
				</span>
			</p>
			<p>
				<label>
					<strong><?php echo esc_html__( 'Email field', 'cea-plugin' ); ?></strong>
					<?php self::render_field_select( $name . '[email_field]', $settings['email_field'], $email_fields, __( 'Select an email field', 'cea-plugin' ) ); ?>
				</label>
			</p>
			<p>
				<label>
					<strong><?php echo esc_html__( 'Consent checkbox', 'cea-plugin' ); ?></strong>
					<?php self::render_field_select( $name . '[consent_field]', $settings['consent_field'], $consent_fields, __( 'Select a checkbox', 'cea-plugin' ) ); ?>
				</label>
				<span class="description"><?php echo esc_html__( 'Mailchimp is skipped unless this checkbox is checked.', 'cea-plugin' ); ?></span>
			</p>
			<p>
				<label>
					<strong><?php echo esc_html__( 'New contact opt-in', 'cea-plugin' ); ?></strong>
					<select class="widefat" name="<?php echo esc_attr( $name ); ?>[status_if_new]">
						<option value="pending" <?php selected( $settings['status_if_new'], 'pending' ); ?>><?php echo esc_html__( 'Double opt-in (recommended)', 'cea-plugin' ); ?></option>
						<option value="subscribed" <?php selected( $settings['status_if_new'], 'subscribed' ); ?>><?php echo esc_html__( 'Subscribe immediately', 'cea-plugin' ); ?></option>
					</select>
				</label>
				<span class="description"><?php echo esc_html__( 'Immediate subscription should only be used when your consent process permits it.', 'cea-plugin' ); ?></span>
			</p>
			<p>
				<label>
					<strong><?php echo esc_html__( 'First-name field', 'cea-plugin' ); ?></strong>
					<?php self::render_field_select( $name . '[first_name_field]', $settings['first_name_field'], $fields, __( 'Do not map', 'cea-plugin' ) ); ?>
				</label>
			</p>
			<p>
				<label>
					<strong><?php echo esc_html__( 'First-name merge tag', 'cea-plugin' ); ?></strong>
					<input type="text" class="widefat" name="<?php echo esc_attr( $name ); ?>[first_name_merge_tag]" value="<?php echo esc_attr( $settings['first_name_merge_tag'] ); ?>" maxlength="10" placeholder="FNAME">
				</label>
			</p>
			<p>
				<label>
					<strong><?php echo esc_html__( 'Last-name field', 'cea-plugin' ); ?></strong>
					<?php self::render_field_select( $name . '[last_name_field]', $settings['last_name_field'], $fields, __( 'Do not map', 'cea-plugin' ) ); ?>
				</label>
			</p>
			<p>
				<label>
					<strong><?php echo esc_html__( 'Last-name merge tag', 'cea-plugin' ); ?></strong>
					<input type="text" class="widefat" name="<?php echo esc_attr( $name ); ?>[last_name_merge_tag]" value="<?php echo esc_attr( $settings['last_name_merge_tag'] ); ?>" maxlength="10" placeholder="LNAME">
				</label>
			</p>
			<p class="cea-form-builder-grid__full">
				<label>
					<strong><?php echo esc_html__( 'Tags', 'cea-plugin' ); ?></strong>
					<textarea class="widefat" rows="3" name="<?php echo esc_attr( $name ); ?>[tags]"><?php echo esc_textarea( implode( "\n", $settings['tags'] ) ); ?></textarea>
				</label>
				<span class="description"><?php echo esc_html__( 'Optional. Enter one Mailchimp tag per line.', 'cea-plugin' ); ?></span>
			</p>
		</div>
		<?php if ( empty( $email_fields ) || empty( $consent_fields ) ) : ?>
			<p class="description"><?php echo esc_html__( 'Save an Email field and a Checkbox field before configuring their Mailchimp mappings.', 'cea-plugin' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Executes the Mailchimp action.
	 *
	 * @param array<string, mixed> $submission Submission data.
	 * @param array<string, mixed> $settings   Mailchimp settings.
	 * @return true|WP_Error
	 */
	public static function execute( $submission, $settings ) {
		$settings = wp_parse_args( is_array( $settings ) ? $settings : array(), self::get_defaults() );
		$valid    = self::validate_settings( $settings );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$fields  = isset( $submission['fields'] ) && is_array( $submission['fields'] ) ? $submission['fields'] : array();
		$consent = self::get_submission_value( $fields, $settings['consent_field'], 'checkbox' );

		if ( '1' !== $consent ) {
			return true;
		}

		$email = strtolower( trim( self::get_submission_value( $fields, $settings['email_field'], 'email' ) ) );

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'cea_form_mailchimp_email', __( 'The mapped Mailchimp email value is invalid.', 'cea-plugin' ) );
		}

		$merge_fields = array();

		if ( '' !== $settings['first_name_field'] ) {
			$first_name = self::get_submission_value( $fields, $settings['first_name_field'] );

			if ( '' !== $first_name ) {
				$merge_fields[ $settings['first_name_merge_tag'] ] = $first_name;
			}
		}

		if ( '' !== $settings['last_name_field'] ) {
			$last_name = self::get_submission_value( $fields, $settings['last_name_field'] );

			if ( '' !== $last_name ) {
				$merge_fields[ $settings['last_name_merge_tag'] ] = $last_name;
			}
		}

		$client = new CEA_Mailchimp_Client();
		$result = $client->upsert_member(
			$settings['audience_id'],
			$email,
			$settings['status_if_new'],
			$merge_fields
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $client->add_tags( $settings['audience_id'], $email, $settings['tags'] );
	}

	/**
	 * Renders a form-field selector.
	 *
	 * @param string                          $name        Input name.
	 * @param string                          $selected    Selected field key.
	 * @param array<int, array<string, mixed>> $fields      Available fields.
	 * @param string                          $placeholder Empty option label.
	 * @return void
	 */
	private static function render_field_select( $name, $selected, $fields, $placeholder ) {
		?>
		<select class="widefat" name="<?php echo esc_attr( $name ); ?>">
			<option value=""><?php echo esc_html( $placeholder ); ?></option>
			<?php foreach ( $fields as $field ) : ?>
				<?php if ( ! is_array( $field ) || empty( $field['key'] ) || empty( $field['label'] ) ) : ?>
					<?php continue; ?>
				<?php endif; ?>
				<option value="<?php echo esc_attr( $field['key'] ); ?>" <?php selected( $selected, $field['key'] ); ?>>
					<?php echo esc_html( $field['label'] . ' (' . $field['key'] . ')' ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Filters fields by allowed type.
	 *
	 * @param array<int, array<string, mixed>> $fields Available fields.
	 * @param array<int, string>               $types  Allowed field types.
	 * @return array<int, array<string, mixed>>
	 */
	private static function filter_fields_by_type( $fields, $types ) {
		return array_values(
			array_filter(
				$fields,
				static function ( $field ) use ( $types ) {
					return is_array( $field ) && isset( $field['type'] ) && in_array( $field['type'], $types, true );
				}
			)
		);
	}

	/**
	 * Returns one scalar submitted field value with an optional type check.
	 *
	 * @param array<string, array<string, mixed>> $fields        Submission fields.
	 * @param string                              $key           Field key.
	 * @param string                              $required_type Optional required type.
	 * @return string
	 */
	private static function get_submission_value( $fields, $key, $required_type = '' ) {
		if ( ! isset( $fields[ $key ] ) || ! is_array( $fields[ $key ] ) ) {
			return '';
		}

		if ( '' !== $required_type && ( ! isset( $fields[ $key ]['type'] ) || $required_type !== $fields[ $key ]['type'] ) ) {
			return '';
		}

		$value = isset( $fields[ $key ]['value'] ) ? $fields[ $key ]['value'] : '';

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Sanitizes a form-field key.
	 *
	 * @param mixed $value Field key.
	 * @return string
	 */
	private static function sanitize_field_key( $value ) {
		return is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
	}

	/**
	 * Sanitizes a Mailchimp resource identifier.
	 *
	 * @param mixed $value Identifier.
	 * @return string
	 */
	private static function sanitize_identifier( $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		return 1 === preg_match( '/^[a-zA-Z0-9_-]{1,64}$/', $value ) ? $value : '';
	}

	/**
	 * Sanitizes a Mailchimp merge tag.
	 *
	 * @param mixed $value Merge tag.
	 * @return string
	 */
	private static function sanitize_merge_tag( $value ) {
		$value = is_scalar( $value ) ? strtoupper( trim( (string) $value ) ) : '';

		return 1 === preg_match( '/^[A-Z0-9_]{1,10}$/', $value ) ? $value : '';
	}

	/**
	 * Sanitizes a list of Mailchimp tags.
	 *
	 * @param mixed $value Submitted tags.
	 * @return array<int, string>
	 */
	private static function sanitize_tags( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\r\n|\r|\n/', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$tags = array();

		foreach ( array_slice( $value, 0, 20 ) as $tag ) {
			if ( ! is_scalar( $tag ) ) {
				continue;
			}

			$tag = sanitize_text_field( substr( trim( (string) $tag ), 0, 100 ) );

			if ( '' !== $tag ) {
				$tags[] = $tag;
			}
		}

		return array_values( array_unique( $tags ) );
	}
}
