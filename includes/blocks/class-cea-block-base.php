<?php
/**
 * Contract each theme block type implements.
 *
 * @package CEA_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Base contract for a theme block registered with CEA_Block_Registry.
 *
 * A "block type" here is one feature exposed to both Gutenberg (as a
 * dynamic block) and, optionally, Elementor (as a widget) through a single
 * shared render path, so front-end markup never drifts between editors —
 * see docs/BLOCKS-PLAN.md, sections 1 and 5.
 */
abstract class CEA_Block_Base {

	/**
	 * Returns the block's registered name, e.g. `cea/form`.
	 *
	 * @return string
	 */
	abstract public function slug();

	/**
	 * Returns the built block.json directory for register_block_type().
	 *
	 * @return string
	 */
	abstract public function build_path();

	/**
	 * Renders the block/widget's front-end markup.
	 *
	 * Called as the Gutenberg render_callback and, once an Elementor
	 * widget for this block type exists, from that widget's render()
	 * method too — the same normalized attributes in, the same markup
	 * out, regardless of which editor produced them.
	 *
	 * @param array $attributes Normalized attributes; see normalize_attributes().
	 * @return string
	 */
	abstract public function render( array $attributes );

	/**
	 * Returns the Elementor widget class for this block type, or null if
	 * it has no Elementor equivalent (yet).
	 *
	 * @return string|null Fully-qualified class name extending \Elementor\Widget_Base.
	 */
	public function elementor_widget_class() {
		return null;
	}

	/**
	 * Normalizes raw attributes from either editor into the shape
	 * render() expects, applying the same validation/fallback rules
	 * regardless of source. Block types that take attributes should
	 * override this.
	 *
	 * @param array $attributes Raw attributes from the Gutenberg block or Elementor widget.
	 * @return array
	 */
	public function normalize_attributes( array $attributes ) {
		return $attributes;
	}
}
