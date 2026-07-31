/**
 * Unit tests for SystemMessage account-action rendering.
 */

import { createElement } from '@wordpress/element';
import { createRoot } from 'react-dom/client';
import { act } from 'react';

import AccountActionMessage from '../../chat-messages/account-action-message';

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
async function renderAccountActionMessage( props ) {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );
	await act( async () => {
		root.render( createElement( AccountActionMessage, props ) );
	} );
	return { container, root };
}

describe( 'AccountActionMessage', () => {
	afterEach( () => {
		document.body.innerHTML = '';
	} );

	test( 'renders managed-credit notices as account actions', async () => {
		const { container, root } = await renderAccountActionMessage( {
			notice: {
				type: 'account_action',
				reason: 'credit_exhausted',
				action: 'purchase_credits',
				actionUrl: 'https://account.example.test/login',
			},
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
