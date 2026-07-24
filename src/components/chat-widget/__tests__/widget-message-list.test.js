/**
 * Unit tests for the floating widget message list.
 */

import { createElement, createRoot } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { act } from 'react';

import WidgetMessageList from '../widget-message-list';

global.IS_REACT_ACT_ENVIRONMENT = true;

jest.mock( '@wordpress/data', () => ( {
	useDispatch: jest.fn(),
	useSelect: jest.fn(),
} ) );

jest.mock( '@wordpress/i18n', () => ( {
	__: ( str ) => str,
} ) );

jest.mock( '../../../store', () => 'sd-ai-agent' );
jest.mock( '../../feedback-consent-modal', () => () => null );
jest.mock( '../../chat-redesign/message-items', () => ( {
	AssistantMessage: () => null,
	RunningMessage: () => null,
	SystemMessage: () => null,
	UserMessage: () => null,
} ) );
jest.mock( '../../chat-redesign/message-helpers', () => ( {
	extractText: ( message ) => message.parts?.[ 0 ]?.text || '',
	getRunningToolName: () => '',
} ) );

/**
 * Render the floating widget message list with a credit-exhaustion notice.
 *
 * @return {Promise<{container: HTMLElement, root: import('@wordpress/element').Root}>}
 *   Rendered container and root.
 */
async function renderCreditExhaustionMessage() {
	const selectors = {
		getCurrentSessionMessages: () => [
			{
				role: 'system',
				parts: [
					{
						text: 'Client error (402): Superdav credit balance is insufficient for this request.',
					},
				],
			},
		],
		isSending: () => false,
		getCurrentSessionId: () => 123,
		getLiveToolCalls: () => [],
		getSessionJobs: () => ( {} ),
		hasStreamError: () => false,
		getProviders: () => [
			{
				id: 'sd-ai-agent-cloud',
				status: {
					account_connect_url: 'https://account.example.test/login',
				},
			},
		],
	};
	useSelect.mockImplementation( ( fn ) => fn( () => selectors ) );
	useDispatch.mockReturnValue( {
		sendMessage: jest.fn(),
		retryLastMessage: jest.fn(),
	} );

	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );
	await act( async () => {
		root.render( createElement( WidgetMessageList ) );
	} );

	return { container, root };
}

describe( 'WidgetMessageList credit exhaustion notice', () => {
	afterEach( () => {
		document.body.innerHTML = '';
		jest.clearAllMocks();
	} );

	test( 'replaces the provider error with an account-settings credit CTA', async () => {
		const { container, root } = await renderCreditExhaustionMessage();
		const notice = container.querySelector(
			'.sd-ai-agent-cr-msg-system--account-action'
		);

		expect( notice ).not.toBeNull();
		expect( notice.textContent ).toContain(
			'Purchase more credits in your account settings'
		);
		expect( notice.textContent ).not.toMatch( /\b(error|insufficient)\b/i );

		const action = notice.querySelector(
			'.sd-ai-agent-cr-msg-system-action'
		);
		expect( action.textContent ).toBe( 'Purchase credits' );
		expect( action.getAttribute( 'href' ) ).toBe(
			'https://account.example.test/login'
		);

		await act( async () => {
			root.unmount();
		} );
	} );
} );

describe( 'WidgetMessageList recoverable-job action card', () => {
	afterEach( () => {
		document.body.innerHTML = '';
		jest.clearAllMocks();
	} );

	test( 'renders and dispatches the retry action for the active session', async () => {
		const retryLastMessage = jest.fn();
		const selectors = {
			getCurrentSessionMessages: () => [],
			isSending: () => false,
			getCurrentSessionId: () => 123,
			getLiveToolCalls: () => [],
			getSessionJobs: () => ( {} ),
			hasStreamError: () => true,
			getProviders: () => [],
		};
		useSelect.mockImplementation( ( fn ) => fn( () => selectors ) );
		useDispatch.mockReturnValue( {
			sendMessage: jest.fn(),
			retryLastMessage,
		} );

		const container = document.createElement( 'div' );
		document.body.appendChild( container );
		const root = createRoot( container );
		await act( async () => {
			root.render( createElement( WidgetMessageList ) );
		} );

		const retryButton = container.querySelector(
			'.sdaa-w-retry-failed-step'
		);
		expect( retryButton.textContent ).toBe( 'Retry failed step' );
		await act( async () => {
			retryButton.dispatchEvent(
				new MouseEvent( 'click', { bubbles: true } )
			);
		} );
		expect( retryLastMessage ).toHaveBeenCalledTimes( 1 );

		await act( async () => {
			root.unmount();
		} );
	} );
} );
