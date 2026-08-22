<?php
/**
 * Central registry for theme block types.
 *
 * Adding a new block type is meant to be: implement CEA_Block_Base, then
 * call CEA_Block_Registry::register() with an instance from CEA_Blocks.
 * Nothing else in the bootstrap needs to change — see
 * docs/BLOCKS-PLAN.md, section 5.
 *
 * Not yet wired into CEA_Blocks::register_blocks(); that switch happens
 * once a real CEA_Block_Base implementation (the form block) exists to
 * register through it — see docs/BLOCKS-PLAN.md, section 12, step 4.
 *
 * @package CEA_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Holds registered block types and drives their Gutenberg/Elementor registration.
 */
final class CEA_Block_Registry {

	/**
	 * Registered block types, keyed by slug.
	 *
	 * @var array<string, CEA_Block_Base>
	 */
	private static $blocks = array();

	/**
	 * Registers a block type.
	 *
	 * @param CEA_Block_Base $block Block type instance.
	 * @return void
	 */
	public static function register( CEA_Block_Base $block ) {
		self::$blocks[ $block->slug() ] = $block;
	}

	/**
	 * Returns a registered block type, or null.
	 *
	 * @param string $slug Block slug, e.g. `cea/form`.
	 * @return CEA_Block_Base|null
	 */
	public static function get( $slug ) {
		return isset( self::$blocks[ $slug ] ) ? self::$blocks[ $slug ] : null;
	}

	/**
	 * Returns all registered block types.
	 *
	 * @return array<string, CEA_Block_Base>
	 */
	public static function all() {
		return self::$blocks;
	}

	/**
	 * Registers every block type with Gutenberg.
	 *
	 * No explicit "does build_path() exist" guard is needed here:
	 * register_block_type_from_metadata() already file_exists()-checks
	 * block.json internally and returns false without side effects when
	 * it's missing (e.g. `npm run build` hasn't been run yet), so an
	 * unbuilt checkout doesn't fatal. Confirmed directly against this
	 * WordPress version rather than assumed — see docs/BLOCKS-PLAN.md,
	 * section 12, step 3.
	 *
	 * @return void
	 */
	public static function init_gutenberg() {
		foreach ( self::$blocks as $block ) {
			register_block_type(
				$block->build_path(),
				array(
					'render_callback' => static function ( $attributes ) use ( $block ) {
						return $block->render( $block->normalize_attributes( (array) $attributes ) );
					},
				)
			);
		}
	}

	/**
	 * Registers every block type's Elementor widget, for block types that
	 * have one.
	 *
	 * @param object $widgets_manager Elementor's \Elementor\Widgets_Manager instance.
	 * @return void
	 */
	public static function init_elementor( $widgets_manager ) {
		foreach ( self::$blocks as $block ) {
			$widget_class = $block->elementor_widget_class();

			if ( $widget_class && class_exists( $widget_class ) ) {
				$widgets_manager->register( new $widget_class() );
			}
		}
	}

	/**
	 * Clears all registrations. Test-only: lets tests exercise the
	 * registry with stub block types without leaking state.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$blocks = array();
	}
}
