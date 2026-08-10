<?php
/**
 * Form builder administrator screens.
 *
 * @package CEA_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the CEA menu and form editing interface.
 */
final class CEA_Forms_Admin {

	/**
	 * User-specific validation notice transient prefix.
	 */
	const NOTICE_TRANSIENT_PREFIX = 'cea_form_notice_';

	/**
	 * Prevents recursive saves when an invalid form is returned to draft.
	 *
	 * @var bool
	 */
	private static $saving = false;

	/**
	 * Registers administrator hooks.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'register_root_page' ), 5 );
		add_action( 'admin_menu', array( __CLASS__, 'remove_duplicate_root_submenu' ), 999 );
		add_action( 'add_meta_boxes_' . CEA_Forms::POST_TYPE, array( __CLASS__, 'register_meta_boxes' ) );
		add_action( 'save_post_' . CEA_Forms::POST_TYPE, array( __CLASS__, 'save_form' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notice' ) );
		add_filter( 'manage_' . CEA_Forms::POST_TYPE . '_posts_columns', array( __CLASS__, 'filter_columns' ) );
		add_action( 'manage_' . CEA_Forms::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
	}

	/**
	 * Registers the top-level CEA menu.
	 *
	 * @return void
	 */
	public static function register_root_page() {
		add_menu_page(
			__( 'CEA', 'cea-plugin' ),
			__( 'CEA', 'cea-plugin' ),
			'manage_options',
			'cea-plugin',
			array( __CLASS__, 'redirect_to_forms' ),
			'dashicons-feedback',
			58
		);
	}

	/**
	 * Removes the automatic duplicate top-level submenu item.
	 *
	 * @return void
	 */
	public static function remove_duplicate_root_submenu() {
		remove_submenu_page( 'cea-plugin', 'cea-plugin' );
	}

	/**
	 * Redirects the root menu to the forms list.
	 *
	 * @return void
	 */
	public static function redirect_to_forms() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage forms.', 'cea-plugin' ) );
		}

		wp_safe_redirect( admin_url( 'edit.php?post_type=' . CEA_Forms::POST_TYPE ) );
		exit;
	}

	/**
	 * Registers form builder meta boxes.
	 *
	 * @param WP_Post $post Current form.
	 * @return void
	 */
	public static function register_meta_boxes( $post ) {
		add_meta_box(
			'cea-form-fields',
			__( 'Form fields', 'cea-plugin' ),
			array( __CLASS__, 'render_fields_meta_box' ),
			CEA_Forms::POST_TYPE,
			'normal',
			'high'
		);
		add_meta_box(
			'cea-form-actions',
			__( 'Actions', 'cea-plugin' ),
			array( __CLASS__, 'render_actions_meta_box' ),
			CEA_Forms::POST_TYPE,
			'normal',
			'default'
		);
		add_meta_box(
			'cea-form-settings',
			__( 'Confirmation', 'cea-plugin' ),
			array( __CLASS__, 'render_settings_meta_box' ),
			CEA_Forms::POST_TYPE,
			'side',
			'default'
		);
		add_meta_box(
			'cea-form-shortcode',
			__( 'Embed form', 'cea-plugin' ),
			array( __CLASS__, 'render_shortcode_meta_box' ),
			CEA_Forms::POST_TYPE,
			'side',
			'default'
		);

		unset( $post );
	}

	/**
	 * Renders the field builder.
	 *
	 * @param WP_Post $post Current form.
	 * @return void
	 */
	public static function render_fields_meta_box( $post ) {
		wp_nonce_field( 'cea_form_save_' . $post->ID, 'cea_form_nonce' );
		echo '<input type="hidden" name="cea_form_builder_present" value="1">';

		$has_saved_fields = metadata_exists( 'post', $post->ID, CEA_Forms::META_FIELDS );
		$fields           = $has_saved_fields ? CEA_Forms::get_fields( $post->ID ) : array( CEA_Form_Schema::get_default_field() );
		?>
		<p><?php echo esc_html__( 'Add fields, choose their types, and drag them into the order visitors should see. Field keys remain stable when labels change.', 'cea-plugin' ); ?></p>
		<div class="cea-form-sortable" id="cea-form-fields-list" data-cea-list="fields">
			<?php foreach ( $fields as $index => $field ) : ?>
				<?php self::render_field_row( (string) $index, $field ); ?>
			<?php endforeach; ?>
		</div>
		<p>
			<button type="button" class="button" data-cea-add="field"><?php echo esc_html__( 'Add field', 'cea-plugin' ); ?></button>
		</p>
		<script type="text/html" id="tmpl-cea-form-field">
			<?php self::render_field_row( '__INDEX__', CEA_Form_Schema::get_default_field() ); ?>
		</script>
		<?php
	}

	/**
	 * Renders one form field row.
	 *
	 * @param string               $index Field index.
	 * @param array<string, mixed> $field Field configuration.
	 * @return void
	 */
	private static function render_field_row( $index, $field ) {
		$field      = wp_parse_args( $field, CEA_Form_Schema::get_default_field() );
		$name       = 'cea_form_fields[' . $index . ']';
		$field_key  = ! empty( $field['key'] ) ? $field['key'] : '';
		$has_choices = in_array( $field['type'], array( 'select', 'radio' ), true );
		?>
		<div class="cea-form-builder-row" data-cea-row="field">
			<div class="cea-form-builder-row__header">
				<button type="button" class="button-link cea-form-drag-handle" aria-label="<?php echo esc_attr__( 'Reorder field', 'cea-plugin' ); ?>">
					<span class="dashicons dashicons-move" aria-hidden="true"></span>
				</button>
				<strong data-cea-row-title><?php echo esc_html( $field['label'] ); ?></strong>
				<button type="button" class="button-link-delete" data-cea-remove><?php echo esc_html__( 'Remove', 'cea-plugin' ); ?></button>
			</div>
			<div class="cea-form-builder-grid">
				<p>
					<label>
						<strong><?php echo esc_html__( 'Label', 'cea-plugin' ); ?></strong>
						<input type="text" class="widefat" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( $field['label'] ); ?>" data-cea-label>
					</label>
				</p>
				<p>
					<label>
						<strong><?php echo esc_html__( 'Type', 'cea-plugin' ); ?></strong>
						<select class="widefat" name="<?php echo esc_attr( $name ); ?>[type]" data-cea-field-type>
							<?php foreach ( CEA_Form_Schema::get_field_types() as $type => $label ) : ?>
								<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $field['type'], $type ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</p>
				<p>
					<label>
						<strong><?php echo esc_html__( 'Placeholder', 'cea-plugin' ); ?></strong>
						<input type="text" class="widefat" name="<?php echo esc_attr( $name ); ?>[placeholder]" value="<?php echo esc_attr( $field['placeholder'] ); ?>">
					</label>
				</p>
				<p>
					<label>
						<strong><?php echo esc_html__( 'Help text', 'cea-plugin' ); ?></strong>
						<input type="text" class="widefat" name="<?php echo esc_attr( $name ); ?>[description]" value="<?php echo esc_attr( $field['description'] ); ?>">
					</label>
				</p>
				<p>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[required]" value="1" <?php checked( ! empty( $field['required'] ) ); ?>>
						<?php echo esc_html__( 'Required', 'cea-plugin' ); ?>
					</label>
				</p>
				<p class="cea-form-field-key">
					<strong><?php echo esc_html__( 'Field key', 'cea-plugin' ); ?></strong><br>
					<code><?php echo esc_html( '' !== $field_key ? $field_key : __( 'Generated when saved', 'cea-plugin' ) ); ?></code>
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>[key]" value="<?php echo esc_attr( $field_key ); ?>">
				</p>
				<p class="cea-form-builder-grid__full" data-cea-choices <?php echo $has_choices ? '' : 'hidden'; ?>>
					<label>
						<strong><?php echo esc_html__( 'Choices', 'cea-plugin' ); ?></strong>
						<textarea class="widefat" rows="5" name="<?php echo esc_attr( $name ); ?>[choices]"><?php echo esc_textarea( CEA_Form_Schema::choices_to_text( $field['choices'] ) ); ?></textarea>
					</label>
					<span class="description"><?php echo esc_html__( 'Enter one choice per line. Use value|Label for an explicit stable value.', 'cea-plugin' ); ?></span>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the action builder.
	 *
	 * @param WP_Post $post Current form.
	 * @return void
	 */
	public static function render_actions_meta_box( $post ) {
		$has_saved_actions = metadata_exists( 'post', $post->ID, CEA_Forms::META_ACTIONS );
		$actions           = $has_saved_actions ? CEA_Forms::get_actions( $post->ID ) : array( CEA_Form_Schema::get_default_action() );
		$definitions       = CEA_Form_Action_Registry::get_all();
		?>
		<p><?php echo esc_html__( 'Enabled actions run independently after a valid submission. Visitors are not shown delivery or credential errors.', 'cea-plugin' ); ?></p>
		<div class="cea-form-sortable" id="cea-form-actions-list" data-cea-list="actions">
			<?php foreach ( $actions as $index => $action ) : ?>
				<?php self::render_action_row( (string) $index, $action ); ?>
			<?php endforeach; ?>
		</div>
		<p class="cea-form-action-buttons">
			<?php foreach ( $definitions as $type => $definition ) : ?>
				<button type="button" class="button" data-cea-add-action="<?php echo esc_attr( $type ); ?>">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: Form action label. */
							__( 'Add %s', 'cea-plugin' ),
							$definition['label']
						)
					);
					?>
				</button>
			<?php endforeach; ?>
		</p>
		<?php foreach ( $definitions as $type => $definition ) : ?>
			<script type="text/html" id="tmpl-cea-form-action-<?php echo esc_attr( $type ); ?>">
				<?php
				self::render_action_row(
					'__INDEX__',
					array(
						'id'       => '',
						'type'     => $type,
						'enabled'  => true,
						'settings' => array(),
					)
				);
				?>
			</script>
		<?php endforeach; ?>
		<?php
	}

	/**
	 * Renders one action row.
	 *
	 * @param string               $index  Action index.
	 * @param array<string, mixed> $action Action configuration.
	 * @return void
	 */
	private static function render_action_row( $index, $action ) {
		$type       = isset( $action['type'] ) ? sanitize_key( $action['type'] ) : '';
		$definition = CEA_Form_Action_Registry::get( $type );

		if ( null === $definition ) {
			return;
		}

		$name     = 'cea_form_actions[' . $index . ']';
		$settings = isset( $action['settings'] ) && is_array( $action['settings'] ) ? $action['settings'] : array();
		?>
		<div class="cea-form-builder-row" data-cea-row="action">
			<div class="cea-form-builder-row__header">
				<button type="button" class="button-link cea-form-drag-handle" aria-label="<?php echo esc_attr__( 'Reorder action', 'cea-plugin' ); ?>">
					<span class="dashicons dashicons-move" aria-hidden="true"></span>
				</button>
				<strong><?php echo esc_html( $definition['label'] ); ?></strong>
				<button type="button" class="button-link-delete" data-cea-remove><?php echo esc_html__( 'Remove', 'cea-plugin' ); ?></button>
			</div>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[id]" value="<?php echo esc_attr( isset( $action['id'] ) ? $action['id'] : '' ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[type]" value="<?php echo esc_attr( $type ); ?>">
			<p>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( ! empty( $action['enabled'] ) ); ?>>
					<?php echo esc_html__( 'Enabled', 'cea-plugin' ); ?>
				</label>
			</p>
			<?php CEA_Form_Action_Registry::render_settings( $type, $name . '[settings]', $settings ); ?>
		</div>
		<?php
	}

	/**
	 * Renders confirmation settings.
	 *
	 * @param WP_Post $post Current form.
	 * @return void
	 */
	public static function render_settings_meta_box( $post ) {
		$settings = CEA_Forms::get_settings( $post->ID );
		?>
		<p>
			<label for="cea-form-success-message"><strong><?php echo esc_html__( 'Success message', 'cea-plugin' ); ?></strong></label>
			<textarea class="widefat" rows="5" id="cea-form-success-message" name="cea_form_settings[success_message]"><?php echo esc_textarea( $settings['success_message'] ); ?></textarea>
		</p>
		<p>
			<label for="cea-form-redirect-url"><strong><?php echo esc_html__( 'Success redirect', 'cea-plugin' ); ?></strong></label>
			<input type="url" class="widefat" id="cea-form-redirect-url" name="cea_form_settings[redirect_url]" value="<?php echo esc_attr( $settings['redirect_url'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/thank-you/' ) ); ?>">
			<span class="description"><?php echo esc_html__( 'Optional. Must be a URL on this site. When set, it replaces the inline success message.', 'cea-plugin' ); ?></span>
		</p>
		<?php
	}

	/**
	 * Renders shortcode instructions.
	 *
	 * @param WP_Post $post Current form.
	 * @return void
	 */
	public static function render_shortcode_meta_box( $post ) {
		$shortcode = '[cea_form id="' . absint( $post->ID ) . '"]';
		?>
		<p><?php echo esc_html__( 'Add this shortcode to a page or post:', 'cea-plugin' ); ?></p>
		<input type="text" class="widefat code" value="<?php echo esc_attr( $shortcode ); ?>" readonly data-cea-shortcode>
		<p class="description"><?php echo esc_html__( 'Only published forms render publicly.', 'cea-plugin' ); ?></p>
		<?php
	}

	/**
	 * Saves and validates form configuration.
	 *
	 * @param int     $post_id Form ID.
	 * @param WP_Post $post    Form post.
	 * @return void
	 */
	public static function save_form( $post_id, $post ) {
		if ( self::$saving || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if (
			( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			|| wp_is_post_revision( $post_id )
			|| wp_is_post_autosave( $post_id )
			|| empty( $_POST['cea_form_builder_present'] )
		) {
			return;
		}

		$nonce = isset( $_POST['cea_form_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['cea_form_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'cea_form_save_' . $post_id ) ) {
			return;
		}

		$raw_fields   = isset( $_POST['cea_form_fields'] ) && is_array( $_POST['cea_form_fields'] ) ? wp_unslash( $_POST['cea_form_fields'] ) : array();
		$raw_actions  = isset( $_POST['cea_form_actions'] ) && is_array( $_POST['cea_form_actions'] ) ? wp_unslash( $_POST['cea_form_actions'] ) : array();
		$raw_settings = isset( $_POST['cea_form_settings'] ) && is_array( $_POST['cea_form_settings'] ) ? wp_unslash( $_POST['cea_form_settings'] ) : array();
		$existing     = get_post_meta( $post_id, CEA_Forms::META_ACTIONS, true );
		$fields       = CEA_Form_Schema::sanitize_fields( $raw_fields );
		$actions      = CEA_Form_Schema::sanitize_actions( $raw_actions, is_array( $existing ) ? $existing : array() );
		$settings     = CEA_Form_Schema::sanitize_settings( $raw_settings );

		update_post_meta( $post_id, CEA_Forms::META_FIELDS, $fields );
		update_post_meta( $post_id, CEA_Forms::META_ACTIONS, $actions );
		update_post_meta( $post_id, CEA_Forms::META_SETTINGS, $settings );
		update_post_meta( $post_id, CEA_Forms::META_SCHEMA_VERSION, CEA_Forms::SCHEMA_VERSION );

		$errors = CEA_Form_Schema::validate_configuration( $fields, $actions );

		if ( 'publish' === $post->post_status && ! empty( $errors ) ) {
			self::$saving = true;
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'draft',
				)
			);
			self::$saving = false;

			set_transient(
				self::NOTICE_TRANSIENT_PREFIX . get_current_user_id(),
				$errors,
				MINUTE_IN_SECONDS
			);
		}
	}

	/**
	 * Enqueues builder assets on form editing screens.
	 *
	 * @param string $hook_suffix Current admin hook.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		$screen = get_current_screen();

		if ( null === $screen || CEA_Forms::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style( 'cea-forms-admin', CEA_PLUGIN_URL . 'assets/admin/forms.css', array(), CEA_PLUGIN_VERSION );
		wp_enqueue_script(
			'cea-forms-admin',
			CEA_PLUGIN_URL . 'assets/admin/forms.js',
			array( 'jquery', 'jquery-ui-sortable' ),
			CEA_PLUGIN_VERSION,
			true
		);
		wp_localize_script(
			'cea-forms-admin',
			'ceaFormsAdmin',
			array(
				'removeConfirm' => __( 'Remove this item?', 'cea-plugin' ),
				'untitled'      => __( 'Untitled field', 'cea-plugin' ),
			)
		);

		unset( $hook_suffix );
	}

	/**
	 * Displays publish validation errors.
	 *
	 * @return void
	 */
	public static function render_admin_notice() {
		$screen = get_current_screen();

		if ( null === $screen || CEA_Forms::POST_TYPE !== $screen->post_type ) {
			return;
		}

		$key    = self::NOTICE_TRANSIENT_PREFIX . get_current_user_id();
		$errors = get_transient( $key );

		if ( is_array( $errors ) && ! empty( $errors ) ) {
			delete_transient( $key );
			self::render_notice_list(
				__( 'The form was returned to draft because its configuration is incomplete:', 'cea-plugin' ),
				$errors
			);
		}

		$post_id  = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		$failures = 0 < $post_id ? get_transient( CEA_Form_Action_Dispatcher::FAILURE_TRANSIENT_PREFIX . $post_id ) : false;

		if ( is_array( $failures ) && ! empty( $failures ) ) {
			delete_transient( CEA_Form_Action_Dispatcher::FAILURE_TRANSIENT_PREFIX . $post_id );
			$messages = array();

			foreach ( $failures as $failure ) {
				$messages[] = sprintf(
					/* translators: 1: Action type, 2: Failure time, 3: Failure message. */
					__( '%1$s at %2$s: %3$s', 'cea-plugin' ),
					isset( $failure['type'] ) ? $failure['type'] : __( 'action', 'cea-plugin' ),
					isset( $failure['time'] ) ? $failure['time'] : '',
					isset( $failure['message'] ) ? $failure['message'] : __( 'Unknown failure.', 'cea-plugin' )
				);
			}

			self::render_notice_list( __( 'Recent form action failures:', 'cea-plugin' ), $messages );
		}
	}

	/**
	 * Renders an administrator error notice list.
	 *
	 * @param string             $heading  Notice heading.
	 * @param array<int, string> $messages Notice messages.
	 * @return void
	 */
	private static function render_notice_list( $heading, $messages ) {
		?>
		<div class="notice notice-error is-dismissible">
			<p><strong><?php echo esc_html( $heading ); ?></strong></p>
			<ul>
				<?php foreach ( $messages as $message ) : ?>
					<li><?php echo esc_html( $message ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Adds a shortcode column to the forms list.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	public static function filter_columns( $columns ) {
		$result = array();

		foreach ( $columns as $key => $label ) {
			$result[ $key ] = $label;

			if ( 'title' === $key ) {
				$result['cea_shortcode'] = __( 'Shortcode', 'cea-plugin' );
			}
		}

		return $result;
	}

	/**
	 * Renders custom form-list columns.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Form ID.
	 * @return void
	 */
	public static function render_column( $column, $post_id ) {
		if ( 'cea_shortcode' === $column ) {
			echo '<code>[cea_form id=&quot;' . esc_html( absint( $post_id ) ) . '&quot;]</code>';
		}
	}
}
