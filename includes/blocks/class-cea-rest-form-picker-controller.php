<?php
/**
 * REST route backing the Gutenberg form picker.
 *
 * Deliberately a narrow, purpose-built route rather than exposing the
 * `cea_form` post type over REST wholesale: that CPT's own capabilities
 * are all `manage_options` (see CEA_Forms::register_post_type()), and
 * widening those to make core's posts REST controller usable by editors
 * would also expose form fields/actions/settings to non-admins. This route
 * only ever returns { id, title } for published forms, gated on
 * `edit_posts` — see docs/BLOCKS-PLAN.md, section 4.
 *
 * @package CEA_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the cea/v1/forms REST route.
 */
final class CEA_Rest_Form_Picker_Controller {

	/** REST namespace. */
	const ROUTE_NAMESPACE = 'cea/v1';

	/** REST route. */
	const ROUTE = '/forms';

	/**
	 * Registers REST hooks.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers the route.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_items' ),
				'permission_callback' => array( __CLASS__, 'get_items_permissions_check' ),
				'args'                => array(),
			)
		);
	}

	/**
	 * Restricts the route to users who can edit posts/pages.
	 *
	 * Matches the capability required to insert the block itself, not the
	 * (stricter) capability required to manage forms.
	 *
	 * @return true|WP_Error
	 */
	public static function get_items_permissions_check() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'cea_rest_forbidden',
				__( 'Sorry, you are not allowed to list forms.', 'cea-plugin' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Returns the published-forms picker list.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_items() {
		return rest_ensure_response( CEA_Form_Picker::get_choices() );
	}
}
