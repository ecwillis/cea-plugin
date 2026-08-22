<?php
/**
 * Non-mutating WordPress CLI smoke tests for CEA Plugin theme blocks.
 *
 * Run with:
 * wp eval-file wp-content/plugins/cea-plugin/tests/blocks-smoke.php --path=/path/to/wordpress
 *
 * @package CEA_Plugin
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'Run this file with wp eval-file.' );
}

/**
 * Fails the smoke test when a condition is false.
 *
 * @param bool   $condition Condition.
 * @param string $message   Failure message.
 * @return void
 */
function cea_blocks_smoke_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

cea_blocks_smoke_assert( class_exists( 'CEA_Blocks' ), 'Block bootstrap did not load.' );
cea_blocks_smoke_assert( class_exists( 'CEA_Form_Picker' ), 'Form picker did not load.' );
cea_blocks_smoke_assert( class_exists( 'CEA_Rest_Form_Picker_Controller' ), 'Form picker REST controller did not load.' );

cea_blocks_smoke_assert(
	WP_Block_Type_Registry::get_instance()->is_registered( 'cea/form' ),
	'cea/form block is not registered. Run `npm run build` in the plugin directory first.'
);

// rest_get_server() fires the rest_api_init action the first time it's
// called, which is what actually registers our route.
$routes = rest_get_server()->get_routes();
cea_blocks_smoke_assert( isset( $routes['/cea/v1/forms'] ), 'cea/v1/forms REST route is not registered.' );

$previous_user       = get_current_user_id();
$anonymous_response  = null;
try {
	wp_set_current_user( 0 );
	$anonymous_response = rest_do_request( new WP_REST_Request( 'GET', '/cea/v1/forms' ) );
} finally {
	wp_set_current_user( $previous_user );
}
cea_blocks_smoke_assert(
	in_array( $anonymous_response->get_status(), array( 401, 403 ), true ),
	'The form picker route did not require authentication.'
);

// Elementor is a soft dependency: nothing here should assume it's active,
// but if it is, its widget should register once that phase exists.
// See docs/BLOCKS-PLAN.md, section 7.
if ( did_action( 'elementor/loaded' ) ) {
	cea_blocks_smoke_assert(
		class_exists( 'CEA_Elementor_Integration' ),
		'Elementor is active but the CEA Elementor integration did not load.'
	);
}

WP_CLI::success( 'CEA theme blocks smoke tests passed.' );
