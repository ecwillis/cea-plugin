/**
 * Dynamic block: all markup comes from PHP (CEA_Form_Block::render(), via
 * the registry's render_callback), never from serialized post content.
 * This is what keeps front-end markup identical between Gutenberg and
 * (once it exists) the Elementor widget — see docs/BLOCKS-PLAN.md,
 * sections 1 and 6.
 */
export default function save() {
	return null;
}
