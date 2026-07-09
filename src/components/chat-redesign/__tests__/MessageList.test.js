/**
 * Unit tests for the redesigned chat MessageList.
 */

import { createElement } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { createRoot } from 'react-dom/client';
import { act } from 'react';

import MessageList from '../MessageList';

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

jest.mock( '../../use-text-to-speech', () => () => ( {
	speak: jest.fn(),
	cancel: jest.fn(),
} ) );

jest.mock( '../message-items', () => ( {
	AssistantMessage: () => null,
	RunningMessage: () => null,
	SystemMessage: () => null,
	UserMessage: () => null,
} ) );

/**
 * Build the selector map needed by MessageList.
 *
 * @param {Object}  root0                 Options.
 * @param {boolean} root0.hasStreamError  Whether the active session has a send error.
 * @param {boolean} [root0.sending=false] Whether a send is currently in progress.
 * @param {number}  [root0.sessionId=123] Current session ID.
 * @return {Object} Selector map.
 */
function buildSelectors( {
	hasStreamError,
	sending = false,
	sessionId = 123,
} ) {
	return {
		getCurrentSessionMessages: () => [],
		isSending: () => sending,
		getCurrentSessionId: () => sessionId,
		getLiveToolCalls: () => [],
		getSessionJobs: () => ( {} ),
		getSettings: () => null,
		isTtsEnabled: () => false,
		getTtsVoiceURI: () => '',
		getTtsRate: () => 1,
		getTtsPitch: () => 1,
		hasStreamError: () => hasStreamError,
	};
}

/**
 * Render MessageList with mocked store hooks.
 *
 * @param {Object} root0             Options.
 * @param {Object} root0.selectors   Mock selector map.
 * @param {Object} root0.dispatchMap Mock dispatch action map.
 * @return {Promise<{container: HTMLElement, root: import('react-dom/client').Root}>}
 *   Rendered container and root.
 */
async function renderMessageList( { selectors, dispatchMap } ) {
	useSelect.mockImplementation( ( fn ) => fn( () => selectors ) );
	useDispatch.mockReturnValue( dispatchMap );

	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );
	await act( async () => {
		root.render( createElement( MessageList ) );
	} );
	return { container, root };
}

describe( 'MessageList stream error banner', () => {
	let container;
	let root;
	let retryLastMessage;

	beforeEach( () => {
		retryLastMessage = jest.fn();
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

	test( 'shows retry banner for the active failed session', async () => {
		( { container, root } = await renderMessageList( {
			selectors: buildSelectors( { hasStreamError: true } ),
			dispatchMap: { sendMessage: jest.fn(), retryLastMessage },
		} ) );

		const banner = container.querySelector( '.sdaa-cr-error-banner' );
		expect( banner ).not.toBeNull();
		expect( banner.textContent ).toContain(
			'Something went wrong while sending your message.'
		);

		await act( async () => {
			banner
				.querySelector( '.sdaa-cr-error-banner__retry' )
				.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
		} );

		expect( retryLastMessage ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'hides retry banner while sending or when no stream error exists', async () => {
		( { container, root } = await renderMessageList( {
			selectors: buildSelectors( {
				hasStreamError: true,
				sending: true,
			} ),
			dispatchMap: { sendMessage: jest.fn(), retryLastMessage },
		} ) );

		expect( container.querySelector( '.sdaa-cr-error-banner' ) ).toBeNull();

		await act( async () => {
			root.unmount();
		} );
		document.body.removeChild( container );
		container = undefined;
		root = undefined;

		( { container, root } = await renderMessageList( {
			selectors: buildSelectors( { hasStreamError: false } ),
			dispatchMap: { sendMessage: jest.fn(), retryLastMessage },
		} ) );

		expect( container.querySelector( '.sdaa-cr-error-banner' ) ).toBeNull();
	} );
} );
