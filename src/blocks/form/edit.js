import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, SelectControl, Spinner } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import apiFetch from '@wordpress/api-fetch';

/**
 * The canvas preview is the real PHP render (via ServerSideRender), not a
 * second JS implementation of "what a CEA form looks like" — see
 * docs/BLOCKS-PLAN.md, section 6. This keeps the editor honest: what an
 * admin sees here is what visitors will see.
 *
 * @param {Object}   props               Block edit props.
 * @param {Object}   props.attributes    Block attributes ({ formId: number }).
 * @param {Function} props.setAttributes Updates block attributes.
 */
export default function Edit( { attributes, setAttributes } ) {
	const { formId } = attributes;
	const blockProps = useBlockProps();

	// null while the picker list is loading; [] once loaded (or on error).
	const [ forms, setForms ] = useState( null );
	const [ fetchError, setFetchError ] = useState( false );

	useEffect( () => {
		let isCurrent = true;

		apiFetch( { path: '/cea/v1/forms' } )
			.then( ( result ) => {
				if ( isCurrent ) {
					setForms( result );
				}
			} )
			.catch( () => {
				if ( isCurrent ) {
					setFetchError( true );
					setForms( [] );
				}
			} );

		return () => {
			isCurrent = false;
		};
	}, [] );

	const options = [
		{ value: 0, label: __( 'Select a form…', 'cea-plugin' ) },
		...( forms || [] ).map( ( form ) => ( {
			value: form.id,
			label: form.title,
		} ) ),
	];

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Form', 'cea-plugin' ) }>
					{ forms === null && ! fetchError && <Spinner /> }
					{ fetchError && (
						<p>
							{ __( 'Forms could not be loaded.', 'cea-plugin' ) }
						</p>
					) }
					{ forms !== null && (
						<SelectControl
							label={ __( 'Form', 'cea-plugin' ) }
							value={ formId }
							options={ options }
							onChange={ ( value ) =>
								setAttributes( { formId: Number( value ) } )
							}
						/>
					) }
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<ServerSideRender block="cea/form" attributes={ attributes } />
			</div>
		</>
	);
}
