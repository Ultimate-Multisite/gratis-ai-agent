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
jest.mock( '../../automatic-feedback-prompt', () => () => null );
jest.mock( '../../chat-redesign/message-items', () => ( {
	AssistantMessage: () => null,
	RunningMessage: () => null,
	SystemMessage: () => null,
	UserMessage: () => null,
} ) );
jest.mock( '../../chat-redesign/message-helpers', () => ( {
	extractText: ( message ) => message.parts?.[ 0 ]?.text || '',
	getFriendlyToolLabel: () => 'Working',
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
		getSettings: () => ( {} ),
		hasStreamError: () => false,
		getPendingActionCard: () => null,
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
		compactConversation: jest.fn(),
		setPendingActionCard: jest.fn(),
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
			getSettings: () => ( {} ),
			hasStreamError: () => true,
			getPendingActionCard: () => null,
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

	test( 'renders preserved client-result Retry without the generic error button', async () => {
		const retryClientToolSubmission = jest.fn();
		const selectors = {
			getCurrentSessionMessages: () => [],
			isSending: () => false,
			getCurrentSessionId: () => 123,
			getLiveToolCalls: () => [],
			getSessionJobs: () => ( {} ),
			getSettings: () => ( {} ),
			hasStreamError: () => true,
			getPendingActionCard: () => ( {
				type: 'retry_client_tools',
				toolNames: [ 'sd-ai-agent-js/screenshot-url' ],
			} ),
			getPendingToolResultRetry: () => ( { sessionId: 123 } ),
			getProviders: () => [],
		};
		useSelect.mockImplementation( ( fn ) => fn( () => selectors ) );
		useDispatch.mockReturnValue( {
			sendMessage: jest.fn(),
			retryLastMessage: jest.fn(),
			retryClientToolSubmission,
			resumeRecoverableJob: jest.fn(),
			compactConversation: jest.fn(),
			setPendingActionCard: jest.fn(),
		} );

		const container = document.createElement( 'div' );
		document.body.appendChild( container );
		const root = createRoot( container );
		await act( async () => {
			root.render( createElement( WidgetMessageList ) );
		} );

		expect(
			container.querySelector( '.sdaa-w-retry-failed-step' )
		).toBeNull();
		const retryButton = container.querySelector(
			'.sdaa-action-card--retry .sdaa-action-card-btn-confirm'
		);
		expect( retryButton ).not.toBeNull();
		expect( retryButton.textContent ).toBe( 'Retry' );

		await act( async () => {
			retryButton.dispatchEvent(
				new MouseEvent( 'click', { bubbles: true } )
			);
		} );
		expect( retryClientToolSubmission ).toHaveBeenCalledTimes( 1 );

		await act( async () => {
			root.unmount();
		} );
	} );

	test( 'renders durable Resume and dispatches resumeRecoverableJob', async () => {
		const resumeRecoverableJob = jest.fn();
		const selectors = {
			getCurrentSessionMessages: () => [],
			isSending: () => false,
			getCurrentSessionId: () => 123,
			getLiveToolCalls: () => [],
			getSessionJobs: () => ( {} ),
			getSettings: () => ( {} ),
			hasStreamError: () => false,
			getPendingActionCard: () => ( {
				type: 'resume_recoverable_job',
				sessionId: 123,
				diagnostic: {
					next_action: 'retry',
					last_safe_phase: 'client_tool_resume',
					correlation_id: 'job-abcdef123456',
				},
			} ),
			getProviders: () => [],
		};
		useSelect.mockImplementation( ( fn ) => fn( () => selectors ) );
		useDispatch.mockReturnValue( {
			sendMessage: jest.fn(),
			retryLastMessage: jest.fn(),
			retryClientToolSubmission: jest.fn(),
			resumeRecoverableJob,
			compactConversation: jest.fn(),
			setPendingActionCard: jest.fn(),
		} );

		const container = document.createElement( 'div' );
		document.body.appendChild( container );
		const root = createRoot( container );
		await act( async () => {
			root.render( createElement( WidgetMessageList ) );
		} );

		const resumeButton = container.querySelector(
			'.sdaa-action-card--resume .sdaa-action-card-btn-confirm'
		);
		expect( resumeButton ).not.toBeNull();
		expect( resumeButton.textContent ).toBe( 'Retry failed step' );

		await act( async () => {
			resumeButton.dispatchEvent(
				new MouseEvent( 'click', { bubbles: true } )
			);
		} );
		expect( resumeRecoverableJob ).toHaveBeenCalledTimes( 1 );

		await act( async () => {
			root.unmount();
		} );
	} );
} );

describe( 'WidgetMessageList compact conversation action card', () => {
	afterEach( () => {
		document.body.innerHTML = '';
		jest.clearAllMocks();
	} );

	test( 'offers and completes compact-and-continue for the active session', async () => {
		const compactConversation = jest.fn().mockResolvedValue( true );
		const setPendingActionCard = jest.fn();
		const selectors = {
			getCurrentSessionMessages: () => [],
			isSending: () => false,
			getCurrentSessionId: () => 123,
			getLiveToolCalls: () => [],
			getSessionJobs: () => ( {} ),
			getSettings: () => ( {} ),
			hasStreamError: () => true,
			getPendingActionCard: () => ( {
				type: 'compact_session',
				sessionId: 123,
			} ),
			getProviders: () => [],
		};
		useSelect.mockImplementation( ( fn ) => fn( () => selectors ) );
		useDispatch.mockReturnValue( {
			sendMessage: jest.fn(),
			retryLastMessage: jest.fn(),
			compactConversation,
			setPendingActionCard,
		} );

		const container = document.createElement( 'div' );
		document.body.appendChild( container );
		const root = createRoot( container );
		await act( async () => {
			root.render( createElement( WidgetMessageList ) );
		} );

		expect(
			container.querySelector( '.sdaa-w-retry-failed-step' )
		).toBeNull();
		const compactButton = container.querySelector(
			'.sd-ai-agent-compact-conversation-action-card__confirm'
		);
		expect( compactButton ).not.toBeNull();
		expect( compactButton.textContent ).toBe( 'Compact and continue' );

		await act( async () => {
			compactButton.dispatchEvent(
				new MouseEvent( 'click', { bubbles: true } )
			);
			await Promise.resolve();
		} );

		expect( compactConversation ).toHaveBeenCalledTimes( 1 );
		expect( setPendingActionCard ).toHaveBeenCalledWith( null );

		await act( async () => {
			root.unmount();
		} );
	} );

	test( 'keeps the compact card and shows a compaction failure', async () => {
		const compactConversation = jest.fn().mockResolvedValue( {
			error: 'Compaction service unavailable',
		} );
		const setPendingActionCard = jest.fn();
		const selectors = {
			getCurrentSessionMessages: () => [],
			isSending: () => false,
			getCurrentSessionId: () => 123,
			getLiveToolCalls: () => [],
			getSessionJobs: () => ( {} ),
			getSettings: () => ( {} ),
			hasStreamError: () => true,
			getPendingActionCard: () => ( {
				type: 'compact_session',
				sessionId: 123,
			} ),
			getProviders: () => [],
		};
		useSelect.mockImplementation( ( fn ) => fn( () => selectors ) );
		useDispatch.mockReturnValue( {
			sendMessage: jest.fn(),
			retryLastMessage: jest.fn(),
			compactConversation,
			setPendingActionCard,
		} );

		const container = document.createElement( 'div' );
		document.body.appendChild( container );
		const root = createRoot( container );
		await act( async () => {
			root.render( createElement( WidgetMessageList ) );
		} );

		const compactButton = container.querySelector(
			'.sd-ai-agent-compact-conversation-action-card__confirm'
		);
		expect( compactButton ).not.toBeNull();

		await act( async () => {
			compactButton.dispatchEvent(
				new MouseEvent( 'click', { bubbles: true } )
			);
		} );

		expect( container.textContent ).toContain(
			'Compaction service unavailable'
		);
		expect( setPendingActionCard ).not.toHaveBeenCalledWith( null );

		await act( async () => {
			root.unmount();
		} );
	} );
} );
