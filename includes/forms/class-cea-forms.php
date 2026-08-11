<?php
/**
 * Form feature controller and storage access.
 *
 * @package CEA_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers form hooks and provides normalized form configuration.
 */
final class CEA_Forms {

	/** Form post type. */
	const POST_TYPE = 'cea_form';

	/** Form field metadata key. */
	const META_FIELDS = '_cea_form_fields';

	/** Form action metadata key. */
	const META_ACTIONS = '_cea_form_actions';

	/** Form settings metadata key. */
	const META_SETTINGS = '_cea_form_settings';

	/** Form schema metadata key. */
	const META_SCHEMA_VERSION = '_cea_form_schema_version';

	/** Current form schema version. */
	const SCHEMA_VERSION = 1;

	/**
	 * Registers public form hooks.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_shortcode( 'cea_form', array( 'CEA_Form_Renderer', 'render_shortcode' ) );
		add_action( 'admin_post_cea_form_submit', array( 'CEA_Form_Submission_Handler', 'handle' ) );
		add_action( 'admin_post_nopriv_cea_form_submit', array( 'CEA_Form_Submission_Handler', 'handle' ) );
	}

	/**
	 * Registers the private form post type.
	 *
	 * @return void
	 */
	public static function register_post_type() {
		$capabilities = array(
			'edit_post'              => 'manage_options',
			'read_post'              => 'manage_options',
			'delete_post'            => 'manage_options',
			'edit_posts'             => 'manage_options',
			'edit_others_posts'      => 'manage_options',
			'publish_posts'          => 'manage_options',
			'read_private_posts'     => 'manage_options',
			'delete_posts'           => 'manage_options',
			'delete_private_posts'   => 'manage_options',
			'delete_published_posts' => 'manage_options',
			'delete_others_posts'    => 'manage_options',
			'edit_private_posts'     => 'manage_options',
			'edit_published_posts'   => 'manage_options',
			'create_posts'           => 'manage_options',
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'                   => __( 'Forms', 'cea-plugin' ),
					'singular_name'          => __( 'Form', 'cea-plugin' ),
					'add_new'                => __( 'Add Form', 'cea-plugin' ),
					'add_new_item'           => __( 'Add New Form', 'cea-plugin' ),
					'edit_item'              => __( 'Edit Form', 'cea-plugin' ),
					'new_item'               => __( 'New Form', 'cea-plugin' ),
					'search_items'           => __( 'Search Forms', 'cea-plugin' ),
					'not_found'              => __( 'No forms found.', 'cea-plugin' ),
					'not_found_in_trash'     => __( 'No forms found in Trash.', 'cea-plugin' ),
					'all_items'              => __( 'Forms', 'cea-plugin' ),
					'item_published'         => __( 'Form published.', 'cea-plugin' ),
					'item_updated'           => __( 'Form updated.', 'cea-plugin' ),
					'item_reverted_to_draft' => __( 'Form reverted to draft.', 'cea-plugin' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => true,
				'show_in_menu'        => 'cea-plugin',
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'supports'            => array( 'title' ),
				'capabilities'        => $capabilities,
				'map_meta_cap'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
			)
		);
	}

	/**
	 * Returns a published form or null.
	 *
	 * @param int $form_id Form ID.
	 * @return WP_Post|null
	 */
	public static function get_published_form( $form_id ) {
		$form = get_post( absint( $form_id ) );

		if ( ! $form instanceof WP_Post || self::POST_TYPE !== $form->post_type || 'publish' !== $form->post_status ) {
			return null;
		}

		return $form;
	}

	/**
	 * Returns normalized fields for a form.
	 *
	 * @param int $form_id Form ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_fields( $form_id ) {
		return CEA_Form_Schema::sanitize_fields( get_post_meta( $form_id, self::META_FIELDS, true ) );
	}

	/**
	 * Returns normalized actions for a form.
	 *
	 * @param int $form_id Form ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_actions( $form_id ) {
		$actions = get_post_meta( $form_id, self::META_ACTIONS, true );

		return CEA_Form_Schema::sanitize_actions( $actions, is_array( $actions ) ? $actions : array() );
	}

	/**
	 * Returns normalized settings for a form.
	 *
	 * @param int $form_id Form ID.
	 * @return array<string, string>
	 */
	public static function get_settings( $form_id ) {
		return CEA_Form_Schema::sanitize_settings( get_post_meta( $form_id, self::META_SETTINGS, true ) );
	}

	/**
	 * Enqueues form assets only when a form is rendered.
	 *
	 * @return void
	 */
	public static function enqueue_public_assets() {
		wp_enqueue_style( 'cea-forms', CEA_PLUGIN_URL . 'assets/public/forms.css', array(), CEA_PLUGIN_VERSION );
		wp_enqueue_script( 'cea-forms', CEA_PLUGIN_URL . 'assets/public/forms.js', array(), CEA_PLUGIN_VERSION, true );
	}
}
