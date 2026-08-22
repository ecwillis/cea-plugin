<?php
/**
 * The cea/form block type.
 *
 * @package CEA_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders a published CEA form inside a Gutenberg block.
 *
 * Deliberately does not re-implement "is this form displayable" (missing
 * ID, unpublished form, empty fields, invalid action configuration) —
 * CEA_Form_Renderer::render_shortcode() already owns that decision and
 * already returns '' for every one of those cases, exactly like the
 * [cea_form] shortcode. This class only adds an editor-only diagnostic
 * message on top when that happens, so an admin sees *why* an inserted
 * block looks empty in the editor - see docs/BLOCKS-PLAN.md, section 6.
 */
final class CEA_Form_Block extends CEA_Block_Base {

	/**
	 * @return string
	 */
	public function slug() {
		return 'cea/form';
	}

	/**
	 * @return string
	 */
	public function build_path() {
		return CEA_PLUGIN_DIR . 'build/blocks/form';
	}

	/**
	 * Coerces raw block/widget attributes into the shape render() expects.
	 *
	 * @param array $attributes Raw attributes from the Gutenberg block or Elementor widget.
	 * @return array{formId: int}
	 */
	public function normalize_attributes( array $attributes ) {
		return array(
			'formId' => isset( $attributes['formId'] ) ? absint( $attributes['formId'] ) : 0,
		);
	}

	/**
	 * Returns the Elementor widget class for this block type, lazily
	 * loading its file first.
	 *
	 * Only ever called from CEA_Block_Registry::init_elementor(), itself
	 * only ever invoked from Elementor's own `elementor/widgets/register`
	 * hook — so \Elementor\Widget_Base (the widget class's parent) is
	 * guaranteed to already exist by the time this require runs. This
	 * lazy require, rather than an unconditional one in cea-plugin.php,
	 * is what keeps Elementor a soft dependency — see
	 * docs/BLOCKS-PLAN.md, section 7.
	 *
	 * @return string
	 */
	public function elementor_widget_class() {
		require_once __DIR__ . '/class-cea-form-elementor-widget.php';

		return CEA_Form_Elementor_Widget::class;
	}

	/**
	 * Renders the block's front-end markup.
	 *
	 * Normalizes its own input, so it's safe to call directly (as the
	 * future Elementor widget will) without the caller having normalized
	 * first — see CEA_Block_Base::normalize_attributes().
	 *
	 * @param array $attributes Raw or normalized attributes; either is accepted.
	 * @return string
	 */
	public function render( array $attributes ) {
		$form_id = $this->normalize_attributes( $attributes )['formId'];

		if ( ! $form_id ) {
			return $this->placeholder( __( 'Select a form to display it here.', 'cea-plugin' ) );
		}

		$markup = CEA_Form_Renderer::render_shortcode( array( 'id' => $form_id ) );

		if ( '' !== $markup ) {
			return $markup;
		}

		$message = CEA_Forms::get_published_form( $form_id )
			? __( 'This form has no fields to display yet.', 'cea-plugin' )
			: __( 'This form is not published.', 'cea-plugin' );

		return $this->placeholder( $message );
	}

	/**
	 * Renders a diagnostic placeholder for users who can act on it, and
	 * nothing at all for everyone else. A public visitor should never see
	 * "select a form" text left over from an editor's unfinished page —
	 * matching the "who can place this block" capability decision in
	 * docs/BLOCKS-PLAN.md ("anyone who can edit posts/pages").
	 *
	 * @param string $message Placeholder message.
	 * @return string
	 */
	private function placeholder( $message ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}

		return '<p class="cea-form-block-placeholder">' . esc_html( $message ) . '</p>';
	}
}
