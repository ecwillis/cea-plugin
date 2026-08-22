import { useBlockProps } from '@wordpress/block-editor';

/**
 * Static save for the scaffold phase only. The block becomes dynamic
 * (save returns null, PHP render_callback takes over) once it renders
 * a real form — see docs/BLOCKS-PLAN.md, section 6.
 */
export default function save() {
	return <p { ...useBlockProps.save() }>CEA Form block placeholder.</p>;
}
