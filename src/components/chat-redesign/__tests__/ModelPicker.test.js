/**
 * Unit tests for the shared chat model picker.
 */

import { createElement } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { act } from 'react';
import { createRoot } from 'react-dom/client';

import ModelPicker from '../ModelPicker';

global.IS_REACT_ACT_ENVIRONMENT = true;

jest.mock( '@wordpress/data', () => ( {
	useDispatch: jest.fn(),
	useSelect: jest.fn(),
} ) );

jest.mock( '@wordpress/i18n', () => ( {
	__: ( str ) => str,
} ) );

jest.mock( 'react-dom', () => ( {
	...jest.requireActual( 'react-dom' ),
	createPortal: ( children ) => children,
} ) );

jest.mock( '../../../store', () => 'sd-ai-agent' );

/**
 * Render the picker with a retryable model discovery failure.
 *
 * @param {Object} dispatchMap Bound store actions.
 * @return {Promise<{container: HTMLElement, root: import('react-dom/client').Root}>} Rendered picker.
 */
async function renderUnavailablePicker( dispatchMap ) {
	const selectors = {
		getProviders: () => [
			{
				id: 'sd-ai-agent-cloud',
				name: 'Superdav AI',
				models: [],
				model_discovery: {
					state: 'retryable_unavailable',
					retryable: true,
				},
			},
		],
		getSelectedProviderId: () => 'sd-ai-agent-cloud',
		getSelectedModelId: () => 'superdav-chat-pro',
	};

	useSelect.mockImplementation( ( callback ) => callback( () => selectors ) );
	useDispatch.mockReturnValue( dispatchMap );

	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );
	await act( async () => {
		root.render( createElement( ModelPicker ) );
	} );

	return { container, root };
}

describe( 'ModelPicker model discovery recovery', () => {
	let container;
	let root;

	afterEach( async () => {
		if ( root ) {
			await act( async () => {
				root.unmount();
			} );
			document.body.removeChild( container );
		}
		container = undefined;
		root = undefined;
		jest.clearAllMocks();
	} );

	test( 'shows an unavailable state and retries provider discovery', async () => {
		const fetchProviders = jest.fn();
		( { container, root } = await renderUnavailablePicker( {
			setSelectedProvider: jest.fn(),
			setSelectedModel: jest.fn(),
			fetchProviders,
		} ) );

		expect( container.textContent ).toContain(
			'(models unavailable; retry)'
		);

		await act( async () => {
			container
				.querySelector( '.sdaa-cr-model-chip' )
				.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
		} );

		expect( fetchProviders ).toHaveBeenCalledTimes( 1 );
	} );
} );
