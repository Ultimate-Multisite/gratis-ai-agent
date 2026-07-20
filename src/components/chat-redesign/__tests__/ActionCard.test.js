/**
 * Regression tests for retry action-card composition in the chat redesign.
 */

import { createElement } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { createRoot } from 'react-dom/client';
import { act } from 'react';

import ChatRedesign from '../index';

global.IS_REACT_ACT_ENVIRONMENT = true;

jest.mock( '@wordpress/data', () => ( {
	useDispatch: jest.fn(),
	useSelect: jest.fn(),
} ) );

jest.mock( '@wordpress/i18n', () => ( {
	__: ( string ) => string,
} ) );

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '../../../store', () => 'sd-ai-agent' );
jest.mock( '../../chat-banners', () => () => null );
jest.mock(
	'../../error-boundary',
	() =>
		( { children } ) =>
			children
);
jest.mock( '../../tool-confirmation-dialog', () => () => null );
jest.mock( '../Sidebar', () => () => null );
jest.mock( '../ConvoHeader', () => () => null );
jest.mock( '../ChangesDrawer', () => () => null );
jest.mock( '../MessageList', () => () => null );
jest.mock( '../InputArea', () => () => null );

/**
 * Build the store selector map required by ChatRedesign.
 *
 * @return {Object} Selector map.
 */
function buildSelectors() {
	return {
		getCurrentSessionId: () => null,
		getPendingConfirmation: () => null,
		getPendingActionCard: () => ( {
			type: 'retry_client_tools',
			toolNames: [ 'sd-ai-agent-js/validate-theme-completion' ],
		} ),
		isYoloMode: () => false,
		isSending: () => false,
	};
}

describe( 'ChatRedesign retry action card', () => {
	let container;
	let root;
	let retryClientToolSubmission;

	beforeEach( () => {
		retryClientToolSubmission = jest.fn();
		useSelect.mockImplementation( ( select ) =>
			select( () => buildSelectors() )
		);
		useDispatch.mockReturnValue( {
			confirmToolCall: jest.fn(),
			rejectToolCall: jest.fn(),
			retryClientToolSubmission,
			setPendingActionCard: jest.fn(),
		} );
	} );

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

	test( 'renders Retry and dispatches retryClientToolSubmission for preserved client results', async () => {
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );

		await act( async () => {
			root.render( createElement( ChatRedesign, { uiMode: 'admin' } ) );
		} );

		const retryButton = container.querySelector(
			'.sdaa-action-card-btn-confirm'
		);
		expect( retryButton ).not.toBeNull();
		expect( container.textContent ).toContain(
			'sd-ai-agent-js/validate-theme-completion'
		);

		await act( async () => {
			retryButton.dispatchEvent(
				new MouseEvent( 'click', { bubbles: true } )
			);
		} );

		expect( retryClientToolSubmission ).toHaveBeenCalledTimes( 1 );
	} );
} );
