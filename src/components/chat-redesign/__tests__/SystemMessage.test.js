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
				"You've used all of your available SD AI credits. Purchase more credits in your account settings to continue using Standard.",
				'https://account.example.test/login',
				'credit_exhausted',
			],
		} );

		expect(
			container.querySelector(
				'.sd-ai-agent-cr-msg-system--account-action'
			)
		).not.toBeNull();
		expect( container.textContent ).toContain(
			'Purchase more credits in your account settings'
		);
		expect( container.textContent ).not.toMatch(
			/\b(error|rejected|insufficient)\b/i
		);

		const action = container.querySelector(
			'.sd-ai-agent-cr-msg-system-action'
		);
		expect( action ).not.toBeNull();
		expect( action.getAttribute( 'href' ) ).toBe(
			'https://account.example.test/login'
		);
		expect( action.getAttribute( 'target' ) ).toBe( '_blank' );
		expect( action.getAttribute( 'rel' ) ).toBe( 'noopener noreferrer' );
		expect( action.textContent ).toBe( 'Purchase credits' );

		const inlineAction = container.querySelector(
			'.sd-ai-agent-cr-msg-system-inline-action'
		);
		expect( inlineAction.textContent ).toBe( 'account settings' );
		expect( inlineAction.getAttribute( 'href' ) ).toBe(
			'https://account.example.test/login'
		);

		await act( async () => {
			root.unmount();
		} );
	} );
} );
