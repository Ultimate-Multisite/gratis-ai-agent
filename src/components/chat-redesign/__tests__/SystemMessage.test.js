/**
 * Unit tests for SystemMessage account-action rendering.
 */

import { createElement } from '@wordpress/element';
import { createRoot } from 'react-dom/client';
import { act } from 'react';

import AccountActionSystemMessage from '../account-action-system-message';

global.IS_REACT_ACT_ENVIRONMENT = true;

jest.mock( '@wordpress/data', () => ( {
	useDispatch: jest.fn(),
	useSelect: jest.fn(),
} ) );

jest.mock( '@wordpress/i18n', () => ( {
	__: ( str ) => str,
} ) );

jest.mock( '@wordpress/icons', () => ( {
	Icon: () => null,
	copy: 'copy-icon',
	check: 'check-icon',
	pencil: 'pencil-icon',
	thumbsDown: 'thumbs-down-icon',
} ) );

jest.mock( '../../../store', () => 'sd-ai-agent' );
jest.mock( '../../markdown-message', () => () => null );
jest.mock( '../icons', () => ( {
	AiIcon: () => null,
} ) );
jest.mock( '../ToolCard', () => () => null );
jest.mock( '../../../utils/linkify', () => ( {
	linkifyText: ( s ) => s,
} ) );

/**
 * Render an account-action notice for DOM assertions.
 *
 * @param {Object} props Component props.
 * @return {Promise<Object>} Render result.
 */
async function renderAccountActionSystemMessage( props ) {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );
	await act( async () => {
		root.render( createElement( AccountActionSystemMessage, props ) );
	} );
	return { container, root };
}

describe( 'AccountActionSystemMessage', () => {
	afterEach( () => {
		document.body.innerHTML = '';
	} );

	test( 'renders Superdav credit notices as account actions', async () => {
		const { container, root } = await renderAccountActionSystemMessage( {
			notice: [
				"You're almost ready to continue. Add payment information to your Superdav account to keep using Superdav Chat Pro.",
				'https://account.example.test/login',
			],
		} );

		expect(
			container.querySelector( '.sdaa-cr-msg-system--account-action' )
		).not.toBeNull();
		expect( container.textContent ).toContain(
			"You're almost ready to continue"
		);
		expect( container.textContent ).not.toMatch(
			/\b(error|rejected|insufficient)\b/i
		);

		const action = container.querySelector( '.sdaa-cr-msg-system-action' );
		expect( action ).not.toBeNull();
		expect( action.getAttribute( 'href' ) ).toBe(
			'https://account.example.test/login'
		);
		expect( action.textContent ).toBe( 'Add payment information' );

		await act( async () => {
			root.unmount();
		} );
	} );
} );
