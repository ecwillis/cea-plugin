import { __ } from '@wordpress/i18n';
import { useBlockProps } from '@wordpress/block-editor';

/**
 * Editor placeholder. Form selection and a live server-rendered preview
 * (via ServerSideRender) are added once the form picker and render_callback
 * exist — see docs/BLOCKS-PLAN.md, sections 6 and 12.
 */
export default function Edit() {
	return (
		<p { ...useBlockProps() }>
			{ __(
				'CEA Form block — placeholder. Build pipeline scaffold only.',
				'cea-plugin'
			) }
		</p>
	);
}
