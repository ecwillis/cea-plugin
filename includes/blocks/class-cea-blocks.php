<?php
/**
 * Block feature bootstrap.
 *
 * @package CEA_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers block hooks and the plugin's block types.
 */
final class CEA_Blocks {

	/**
	 * Registers block-related hooks.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'init', array( __CLASS__, 'register_blocks' ) );
		add_filter( 'block_categories_all', array( __CLASS__, 'register_block_category' ) );
		CEA_Rest_Form_Picker_Controller::register_hooks();
		CEA_Elementor_Integration::register_hooks();
	}

	/**
	 * Registers the plugin's block types through CEA_Block_Registry.
	 *
	 * register_block_type_from_metadata() already file_exists()-checks
	 * block.json internally and returns false without side effects when
	 * it's missing, so calling this on an unbuilt checkout (e.g. before
	 * `npm run build` has been run) doesn't fatal — no extra guard needed
	 * here. Confirmed directly against this WordPress version rather than
	 * assumed — see docs/BLOCKS-PLAN.md, section 12, step 3.
	 *
	 * @return void
	 */
	public static function register_blocks() {
		CEA_Block_Registry::register( new CEA_Form_Block() );
		CEA_Block_Registry::init_gutenberg();
	}

	/**
	 * Adds the shared "CEA Plugin" block category used by every block type
	 * this plugin ships, so they group together in the block inserter
	 * instead of scattering across core categories.
	 *
	 * @param array $categories Existing block categories.
	 * @return array
	 */
	public static function register_block_category( $categories ) {
		return array_merge(
			array(
				array(
					'slug'  => 'cea',
					'title' => __( 'CEA Plugin', 'cea-plugin' ),
					'icon'  => null,
				),
			),
			$categories
		);
	}
}
