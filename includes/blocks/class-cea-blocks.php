<?php
/**
 * Block feature bootstrap.
 *
 * Scaffold phase only: registers the built `cea/form` block directly.
 * This will delegate to a CEA_Block_Registry once more than one block
 * type exists — see docs/BLOCKS-PLAN.md, sections 5 and 12.
 *
 * @package CEA_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers block hooks.
 */
final class CEA_Blocks {

	/**
	 * Registers block-related hooks.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'init', array( __CLASS__, 'register_blocks' ) );
	}

	/**
	 * Registers built block types.
	 *
	 * Silently no-ops if the block hasn't been built yet (e.g. `npm run
	 * build` hasn't been run), so an unbuilt checkout doesn't fatal.
	 *
	 * @return void
	 */
	public static function register_blocks() {
		$form_block_path = CEA_PLUGIN_DIR . 'build/blocks/form';

		if ( file_exists( $form_block_path . '/block.json' ) ) {
			register_block_type( $form_block_path );
		}
	}
}
