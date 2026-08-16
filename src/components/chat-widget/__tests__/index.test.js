import { createElement, createRoot } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { act } from 'react';

import ChatWidget from '..';

global.IS_REACT_ACT_ENVIRONMENT = true;

jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn(),
} ) );
jest.mock( '@wordpress/i18n', () => ( {
	__: ( text ) => text,
} ) );
jest.mock( '../../../store', () => 'sd-ai-agent' );
jest.mock( '../../../utils/chat-ui-mode', () => ( {
	getChatUiMode: () => 'admin',
} ) );
jest.mock( '../widget-launcher', () => ( { label, onActivate } ) => (
	<button type="button" onClick={ onActivate }>
		{ label || 'Open AI Agent' }
	</button>
) );
jest.mock( '../widget-panel', () => {
	throw new Error( 'chunk unavailable' );
} );

describe( 'ChatWidget deferred panel', () => {
	test( 'keeps a retry launcher available when the panel chunk rejects', async () => {
		useSelect.mockImplementation( ( callback ) =>
			callback( () => ( { isFloatingOpen: () => true } ) )
		);
		const container = document.createElement( 'div' );
		const root = createRoot( container );

		await act( async () => {
			root.render( createElement( ChatWidget ) );
			await Promise.resolve();
		} );

		const retry = container.querySelector( 'button' );
		expect( retry ).not.toBeNull();
		expect( retry.textContent ).toBe( 'Retry opening AI Agent' );

		await act( async () => {
			retry.click();
			await Promise.resolve();
		} );
		expect( container.querySelector( 'button' ) ).not.toBeNull();

		await act( async () => root.unmount() );
	} );
} );
