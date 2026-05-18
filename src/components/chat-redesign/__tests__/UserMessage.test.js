/**
 * Unit tests for the UserMessage component in chat-redesign/message-items.js.
 *
 * Regression tests for GH#1495: "Resend uses old model instead of currently
 * selected dropdown value". The root cause was that handleSubmit early-returned
 * (closing the editor without dispatching) when the user clicked Send in
 * edit-and-resend mode without modifying the text. That silent no-op left the
 * original error visible and made it look as if the resend had ignored the
 * dropdown change.
 *
 * Tests cover:
 * - Send with unchanged draft → editAndResend dispatched with the original text
 *   so streamMessage re-reads getSelectedProviderId/getSelectedModelId from
 *   the store at dispatch time and the new dropdown selection takes effect.
 * - Send with edited draft → editAndResend dispatched with the trimmed new
 *   text.
 * - Cancel → editor closes without dispatching editAndResend.
 *
 * The Send button is disabled by the UI whenever `draft.trim()` is empty, so
 * the whitespace-only-input cases are not reachable through the rendered
 * control surface and are intentionally not exercised here.
 */

import { createElement } from '@wordpress/element';
import { createRoot } from 'react-dom/client';
import { act } from 'react';
import { useDispatch, useSelect } from '@wordpress/data';

import { UserMessage } from '../message-items';

// Configure React act() environment for jsdom.
global.IS_REACT_ACT_ENVIRONMENT = true;

// ─── Mock @wordpress/data ─────────────────────────────────────────────────────

jest.mock( '@wordpress/data', () => ( {
	useDispatch: jest.fn(),
	useSelect: jest.fn(),
} ) );

// ─── Mock @wordpress/i18n ─────────────────────────────────────────────────────

jest.mock( '@wordpress/i18n', () => ( {
	__: ( str ) => str,
} ) );

// ─── Mock @wordpress/icons ────────────────────────────────────────────────────

jest.mock( '@wordpress/icons', () => ( {
	Icon: () => null,
	copy: 'copy-icon',
	check: 'check-icon',
	pencil: 'pencil-icon',
	thumbsDown: 'thumbs-down-icon',
} ) );

// ─── Mock store ───────────────────────────────────────────────────────────────

jest.mock( '../../../store', () => 'sd-ai-agent' );

// ─── Mock sibling modules referenced by message-items.js ─────────────────────

jest.mock( '../../markdown-message', () => () => null );
jest.mock( '../icons', () => ( {
	AiIcon: () => null,
} ) );
jest.mock( '../ToolCard', () => () => null );
jest.mock( '../../../utils/linkify', () => ( {
	linkifyText: ( s ) => s,
} ) );

// ─── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Build a minimal selector mock for a UserMessage in edit mode.
 *
 * @param {Object}  root0                 Options.
 * @param {number}  root0.index           Message index under edit.
 * @param {boolean} [root0.editing=true]  Whether this message is in edit mode.
 * @param {boolean} [root0.sending=false] Whether the store is currently sending.
 * @return {Object} Mock store selector map.
 */
function buildStoreSelectors( { index, editing = true, sending = false } ) {
	return {
		isSending: () => sending,
		getMessageTokens: () => [],
		getProviders: () => [],
		getSelectedProviderId: () => 'anthropic-max',
		getSelectedModelId: () => 'claude-sonnet-4-6',
		getEditingMessageIndex: () => ( editing ? index : null ),
	};
}

/**
 * Wire up the useSelect / useDispatch mocks.
 *
 * @param {Object} root0             Options.
 * @param {Object} root0.selectors   Mock selector map.
 * @param {Object} root0.dispatchMap Mock dispatch action map.
 */
function setupMocks( { selectors, dispatchMap } ) {
	useSelect.mockImplementation( ( fn ) => fn( () => selectors ) );
	useDispatch.mockReturnValue( dispatchMap );
}

/**
 * Render the UserMessage component into a container with React 18 createRoot.
 *
 * @param {Object} root0       Options.
 * @param {Object} root0.msg   Message object.
 * @param {number} root0.index Message index.
 * @return {{container: HTMLElement, root: import('react-dom/client').Root}}
 *   The mounted container + React root for assertion and unmounting.
 */
async function renderUserMessage( { msg, index } ) {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );
	await act( async () => {
		root.render( createElement( UserMessage, { msg, index } ) );
	} );
	return { container, root };
}

/**
 * Look up an action button inside the editor by visible label.
 *
 * @param {HTMLElement} container Root container holding the rendered UI.
 * @param {string}      label     Trimmed button text to match.
 * @return {HTMLButtonElement|null} The first matching button, or null.
 */
function findButton( container, label ) {
	const buttons = container.querySelectorAll( 'button' );
	for ( const b of buttons ) {
		if ( b.textContent?.trim() === label ) {
			return b;
		}
	}
	return null;
}

/**
 * Set the editor textarea value through a native input event so React's
 * synthetic onChange fires and the component's draft state updates.
 *
 * @param {HTMLElement} container Root container holding the editor.
 * @param {string}      value     Text to enter.
 */
async function typeIntoEditor( container, value ) {
	const textarea = container.querySelector( 'textarea' );
	await act( async () => {
		const setter = Object.getOwnPropertyDescriptor(
			window.HTMLTextAreaElement.prototype,
			'value'
		).set;
		setter.call( textarea, value );
		textarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
}

// ─── Tests ────────────────────────────────────────────────────────────────────

describe( 'UserMessage handleSubmit (GH#1495)', () => {
	let editAndResend;
	let setEditingMessageIndex;
	let container;
	let root;

	beforeEach( () => {
		editAndResend = jest.fn();
		setEditingMessageIndex = jest.fn();
	} );

	afterEach( async () => {
		if ( root ) {
			await act( async () => {
				root.unmount();
			} );
			document.body.removeChild( container );
			root = undefined;
			container = undefined;
		}
		jest.clearAllMocks();
	} );

	test( 'Send with unchanged draft still dispatches editAndResend with the original text', async () => {
		// Production flow: user clicks the pencil → setDraft(text) seeds the
		// editor with the original message. We replay that by typing the same
		// text into the textarea so the Send button is enabled.
		const originalText = 'Build me a restaurant website.';
		const msg = { role: 'user', parts: [ { text: originalText } ] };
		setupMocks( {
			selectors: buildStoreSelectors( { index: 3 } ),
			dispatchMap: { editAndResend, setEditingMessageIndex },
		} );

		const rendered = await renderUserMessage( { msg, index: 3 } );
		container = rendered.container;
		root = rendered.root;

		await typeIntoEditor( container, originalText );

		const sendBtn = findButton( container, 'Send' );
		expect( sendBtn ).not.toBeNull();
		expect( sendBtn.disabled ).toBe( false );
		await act( async () => {
			sendBtn.click();
		} );

		// The bug before GH#1495: handleSubmit early-returned because
		// draft === text, so editAndResend was never dispatched and the new
		// model in the store was never picked up. The fix is to always
		// resend when Send is clicked.
		expect( editAndResend ).toHaveBeenCalledTimes( 1 );
		expect( editAndResend ).toHaveBeenCalledWith( 3, originalText );
		expect( setEditingMessageIndex ).not.toHaveBeenCalled();
	} );

	test( 'Send with edited draft dispatches editAndResend with the trimmed new text', async () => {
		const msg = { role: 'user', parts: [ { text: 'Original text.' } ] };
		setupMocks( {
			selectors: buildStoreSelectors( { index: 0 } ),
			dispatchMap: { editAndResend, setEditingMessageIndex },
		} );

		const rendered = await renderUserMessage( { msg, index: 0 } );
		container = rendered.container;
		root = rendered.root;

		await typeIntoEditor( container, '  Edited text.  ' );
		const sendBtn = findButton( container, 'Send' );
		await act( async () => {
			sendBtn.click();
		} );

		expect( editAndResend ).toHaveBeenCalledTimes( 1 );
		expect( editAndResend ).toHaveBeenCalledWith( 0, 'Edited text.' );
	} );

	test( 'Send button is disabled when draft is empty (UI guard)', async () => {
		const msg = { role: 'user', parts: [ { text: '' } ] };
		setupMocks( {
			selectors: buildStoreSelectors( { index: 2 } ),
			dispatchMap: { editAndResend, setEditingMessageIndex },
		} );

		const rendered = await renderUserMessage( { msg, index: 2 } );
		container = rendered.container;
		root = rendered.root;

		const sendBtn = findButton( container, 'Send' );
		expect( sendBtn ).not.toBeNull();
		expect( sendBtn.disabled ).toBe( true );
	} );

	test( 'Cancel closes the editor without dispatching editAndResend', async () => {
		const msg = { role: 'user', parts: [ { text: 'Some text.' } ] };
		setupMocks( {
			selectors: buildStoreSelectors( { index: 4 } ),
			dispatchMap: { editAndResend, setEditingMessageIndex },
		} );

		const rendered = await renderUserMessage( { msg, index: 4 } );
		container = rendered.container;
		root = rendered.root;

		await typeIntoEditor( container, 'Some text.' );

		const cancelBtn = findButton( container, 'Cancel' );
		expect( cancelBtn ).not.toBeNull();
		await act( async () => {
			cancelBtn.click();
		} );

		expect( editAndResend ).not.toHaveBeenCalled();
		expect( setEditingMessageIndex ).toHaveBeenCalledWith( null );
	} );
} );
