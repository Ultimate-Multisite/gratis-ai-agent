/**
 * Unit tests for shared chat-redesign message item rendering.
 */

import { createElement } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { createRoot } from 'react-dom/client';
import { act } from 'react';

import { AssistantMessage, RunningMessage } from '../message-items';

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
	copy: 'copy',
	check: 'check',
	pencil: 'pencil',
	thumbsDown: 'thumbsDown',
} ) );

jest.mock( '../../../store', () => 'sd-ai-agent' );

jest.mock(
	'../../markdown-message',
	() =>
		( { content } ) =>
			content
);

jest.mock( '../icons', () => ( {
	AiIcon: () => null,
} ) );

jest.mock( '../ToolCard', () => ( {
	__esModule: true,
	default: ( { call } ) => `Tool card: ${ call.name }`,
	ToolResultHighlights: () => null,
} ) );

/**
 * Render an assistant message with mocked store selectors.
 *
 * @param {Object}  root0                Options.
 * @param {boolean} root0.hasStreamError Whether the active session has a send error.
 * @param {string}  root0.text           Assistant message text.
 * @param {Array}   root0.toolCalls      Logged tool calls for the message.
 * @return {Promise<{container: HTMLElement, root: import('react-dom/client').Root}>}
 *   Rendered container and root.
 */
async function renderAssistantMessage( {
	hasStreamError,
	text = '',
	toolCalls,
} ) {
	const selectors = {
		getMessageTokens: () => [],
		getSettings: () => ( { show_tool_call_details: false } ),
		hasStreamError: () => hasStreamError,
	};
	useSelect.mockImplementation( ( fn ) => fn( () => selectors ) );

	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );
	await act( async () => {
		root.render(
			createElement( AssistantMessage, {
				msg: {
					role: 'model',
					parts: [ { text } ],
					toolCalls: toolCalls || [
						{
							type: 'call',
							id: 'theme',
							name: 'sd-ai-agent/get-theme-json',
						},
						{
							type: 'response',
							id: 'theme',
							response: { success: true },
						},
					],
				},
				index: 0,
				onSuggestionSelect: jest.fn(),
				onThumbsDown: jest.fn(),
				isLastModel: true,
			} )
		);
	} );

	return { container, root };
}

describe( 'AssistantMessage progress summary', () => {
	afterEach( () => {
		document.body.innerHTML = '';
		jest.clearAllMocks();
	} );

	test( 'shows an interrupted progress state when the final response failed', async () => {
		const { container, root } = await renderAssistantMessage( {
			hasStreamError: true,
			text: 'Error: The AI service is unavailable.',
		} );

		const summary = container.querySelector( '.sdaa-cr-progress-summary' );
		expect( summary ).not.toBeNull();
		expect( summary.classList.contains( 'is-error' ) ).toBe( true );
		expect( summary.textContent ).toContain( 'Work paused' );
		expect( summary.textContent ).toContain(
			'The agent completed several steps, then stopped before finishing the reply.'
		);
		expect( summary.textContent ).not.toContain( 'Work completed' );

		await act( async () => {
			root.unmount();
		} );
	} );

	test( 'does not call a tool-only final message complete when no reply was written', async () => {
		const { container, root } = await renderAssistantMessage( {
			hasStreamError: false,
		} );

		const summary = container.querySelector( '.sdaa-cr-progress-summary' );
		expect( summary ).not.toBeNull();
		expect( summary.classList.contains( 'is-error' ) ).toBe( true );
		expect( summary.textContent ).toContain( 'Work paused' );
		expect( summary.textContent ).not.toContain( 'Work completed' );

		await act( async () => {
			root.unmount();
		} );
	} );

	test( 'keeps recovered failures inspectable without showing an attention warning', async () => {
		const { container, root } = await renderAssistantMessage( {
			hasStreamError: false,
			text: 'I found the page and completed the update.',
			toolCalls: [
				{ type: 'call', id: 'first', name: 'sd-ai-agent/get-post' },
				{
					type: 'response',
					id: 'first',
					response: { error: 'The first request timed out.' },
				},
				{ type: 'call', id: 'retry', name: 'sd-ai-agent/get-post' },
				{
					type: 'response',
					id: 'retry',
					response: { success: true },
				},
			],
		} );

		expect( container.textContent ).toContain( 'Work completed' );
		expect( container.textContent ).toContain( '1 recovered' );
		expect( container.textContent ).not.toContain( 'need attention' );

		await act( async () => {
			container
				.querySelector( '.sdaa-cr-progress-stat.is-recovered' )
				.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
		} );

		expect(
			container.querySelector( '.sdaa-cr-progress-details' )
		).not.toBeNull();
		expect( container.textContent ).toContain(
			'Tool card: sd-ai-agent/get-post'
		);

		await act( async () => {
			root.unmount();
		} );
	} );
} );

describe( 'RunningMessage progress summary', () => {
	test( 'shows the exact nested ability instead of its dispatcher', async () => {
		const container = document.createElement( 'div' );
		document.body.appendChild( container );
		const root = createRoot( container );

		await act( async () => {
			root.render(
				createElement( RunningMessage, {
					step: 'Checking options…',
					liveToolCalls: [
						{
							type: 'call',
							id: 'options',
							name: 'wpab__sd-ai-agent__ability-call',
							args: {
								ability: 'sd-ai-agent/list-options',
							},
						},
					],
				} )
			);
		} );

		expect( container.textContent ).toContain( 'Checking options' );
		expect( container.textContent ).toContain( 'sd-ai-agent/list-options' );
		expect( container.textContent ).not.toContain(
			'sd-ai-agent/ability-call'
		);

		await act( async () => {
			root.unmount();
		} );
		document.body.removeChild( container );
	} );
} );
