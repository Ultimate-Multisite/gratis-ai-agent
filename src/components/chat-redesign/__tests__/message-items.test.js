/**
 * Unit tests for shared chat-redesign message item rendering.
 */

import { createElement } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { createRoot } from 'react-dom/client';
import { act } from 'react';

import { AssistantMessage } from '../message-items';

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
	default: () => null,
	ToolResultHighlights: () => null,
} ) );

/**
 * Render an assistant message with mocked store selectors.
 *
 * @param {Object}  root0                Options.
 * @param {boolean} root0.hasStreamError Whether the active session has a send error.
 * @param {string}  root0.text           Assistant message text.
 * @return {Promise<{container: HTMLElement, root: import('react-dom/client').Root}>}
 *   Rendered container and root.
 */
async function renderAssistantMessage( { hasStreamError, text = '' } ) {
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
					toolCalls: [
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
} );
