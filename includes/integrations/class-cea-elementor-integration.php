<?php
/**
 * Elementor integration bootstrap.
 *
 * Elementor is a soft dependency: every hook this class adds is one only
 * Elementor itself ever fires (`elementor/widgets/register`,
 * `elementor/elements/categories_registered`), and neither method touches
 * an \Elementor\* class directly — so this file is safe to load
 * unconditionally on a site without Elementor active; it's simply inert
 * there. See docs/BLOCKS-PLAN.md, section 7.
 *
 * @package CEA_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers CEA Plugin's Elementor widgets and their shared category.
 */
final class CEA_Elementor_Integration {

	/**
	 * Registers Elementor hooks.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'elementor/widgets/register', array( __CLASS__, 'register_widgets' ) );
		add_action( 'elementor/elements/categories_registered', array( __CLASS__, 'register_category' ) );
	}

	/**
	 * Registers every block type's Elementor widget, for block types that
	 * have one. Fired by Elementor itself once its own widget classes
	 * (e.g. \Elementor\Widget_Base) are already loaded, so it's safe for
	 * CEA_Block_Registry::init_elementor() to lazily require widget class
	 * files at this point.
	 *
	 * @param object $widgets_manager Elementor's \Elementor\Widgets_Manager instance.
	 * @return void
	 */
	public static function register_widgets( $widgets_manager ) {
		CEA_Block_Registry::init_elementor( $widgets_manager );
	}

	/**
	 * Adds the shared "CEA Plugin" Elementor widget category, mirroring
	 * the Gutenberg block category registered in CEA_Blocks.
	 *
	 * @param object $elements_manager Elementor's \Elementor\Elements_Manager instance.
	 * @return void
	 */
	public static function register_category( $elements_manager ) {
		$elements_manager->add_category(
			'cea',
			array(
				'title' => __( 'CEA Plugin', 'cea-plugin' ),
				'icon'  => 'eicon-plug',
			)
		);
	}
}
