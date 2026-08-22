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
// but if it is, the widget and its category should actually register.
// See docs/BLOCKS-PLAN.md, section 7.
if ( did_action( 'elementor/loaded' ) ) {
	cea_blocks_smoke_assert(
		class_exists( 'CEA_Elementor_Integration' ),
		'Elementor is active but the CEA Elementor integration did not load.'
	);

	// get_widget_types() is what actually triggers Elementor's own
	// elementor/widgets/register action (Elementor lazily builds its
	// widget list on first access, not on a fixed WordPress hook).
	$widget_types = \Elementor\Plugin::$instance->widgets_manager->get_widget_types();
	cea_blocks_smoke_assert( isset( $widget_types['cea_form'] ), 'The cea_form Elementor widget did not register.' );
	cea_blocks_smoke_assert( 'CEA_Form_Elementor_Widget' === get_class( $widget_types['cea_form'] ), 'The cea_form Elementor widget registered the wrong class.' );

	$categories = \Elementor\Plugin::$instance->elements_manager->get_categories();
	cea_blocks_smoke_assert( isset( $categories['cea'] ), 'The "cea" Elementor widget category is not registered.' );
}

// --- CEA_Block_Registry mechanics ---------------------------------------
//
// No production code registers through CEA_Block_Registry yet (see
// docs/BLOCKS-PLAN.md, section 12, step 4), so this exercises it with a
// throwaway stub block type rather than the real cea/form block.

cea_blocks_smoke_assert( class_exists( 'CEA_Block_Base' ), 'Block base contract did not load.' );
cea_blocks_smoke_assert( class_exists( 'CEA_Block_Registry' ), 'Block registry did not load.' );

if ( ! class_exists( 'CEA_Blocks_Smoke_Stub_Block', false ) ) {
	/**
	 * Minimal stand-in block type used only to exercise CEA_Block_Registry.
	 * Its build_path() intentionally does not exist, so init_gutenberg()
	 * must skip it rather than fatal.
	 */
	class CEA_Blocks_Smoke_Stub_Block extends CEA_Block_Base {

		/**
		 * @return string
		 */
		public function slug() {
			return 'cea-smoke-test/stub';
		}

		/**
		 * @return string
		 */
		public function build_path() {
			return CEA_PLUGIN_DIR . 'build/blocks/does-not-exist';
		}

		/**
		 * @param array $attributes Normalized attributes.
		 * @return string
		 */
		public function render( array $attributes ) {
			return '';
		}
	}
}

$stub = new CEA_Blocks_Smoke_Stub_Block();

cea_blocks_smoke_assert( null === $stub->elementor_widget_class(), 'Default elementor_widget_class() should be null.' );
cea_blocks_smoke_assert(
	array( 'a' => 1 ) === $stub->normalize_attributes( array( 'a' => 1 ) ),
	'Default normalize_attributes() should pass attributes through unchanged.'
);

try {
	CEA_Block_Registry::register( $stub );
	cea_blocks_smoke_assert(
		$stub === CEA_Block_Registry::get( 'cea-smoke-test/stub' ),
		'Registry::get() did not return the registered block type.'
	);
	cea_blocks_smoke_assert(
		array_key_exists( 'cea-smoke-test/stub', CEA_Block_Registry::all() ),
		'Registry::all() is missing a registered block type.'
	);

	// register_block_type() itself no-ops safely on a missing block.json
	// (verified directly against this WordPress version — see
	// class-cea-block-registry.php); this just confirms that guarantee
	// still holds end to end through init_gutenberg(), not that
	// CEA_Block_Registry adds a guard of its own.
	CEA_Block_Registry::init_gutenberg();
	cea_blocks_smoke_assert(
		! WP_Block_Type_Registry::get_instance()->is_registered( 'cea-smoke-test/stub' ),
		'The registry should not register a block type whose build/ directory is missing.'
	);
} finally {
	CEA_Block_Registry::reset();
}
cea_blocks_smoke_assert( array() === CEA_Block_Registry::all(), 'Registry::reset() did not clear registered block types.' );

WP_CLI::success( 'CEA theme blocks smoke tests passed.' );
