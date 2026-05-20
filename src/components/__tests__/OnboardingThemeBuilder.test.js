/**
 * Unit tests for components/onboarding-theme-builder.js
 *
 * Tests cover:
 * - Calls theme-builder-start endpoint on mount
 * - Selects the Theme Builder agent returned by theme-builder-start
 * - Opens the session returned by theme-builder-start
 * - Sends the kickoff message returned by theme-builder-start
 * - Falls back gracefully when theme-builder-start fails
 * - Uses fallback kickoff message when none returned
 * - Skips agent selection when no agent_id returned
 * - Does not open a session when session_id is missing
 * - Does not call theme-builder-start twice (React 18 strict-mode guard)
 */

import { createElement } from '@wordpress/element';
import { createRoot } from 'react-dom/client';
import { act } from 'react';
import { useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import OnboardingThemeBuilder from '../onboarding-theme-builder';

// Configure React act() environment for jsdom.
global.IS_REACT_ACT_ENVIRONMENT = true;

// ─── Mock @wordpress/data ─────────────────────────────────────────────────────

jest.mock( '@wordpress/data', () => ( {
	useDispatch: jest.fn(),
} ) );

// ─── Mock @wordpress/i18n ─────────────────────────────────────────────────────

jest.mock( '@wordpress/i18n', () => ( {
	__: ( str ) => str,
} ) );

// ─── Mock @wordpress/api-fetch ────────────────────────────────────────────────

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

// ─── Mock store ───────────────────────────────────────────────────────────────

jest.mock( '../../store', () => 'sd-ai-agent' );

// ─── Mock ChatRedesign ────────────────────────────────────────────────────────

jest.mock( '../chat-redesign', () => {
	const React = require( 'react' );
	return () =>
		React.createElement( 'div', { 'data-testid': 'chat-redesign' } );
} );

// ─── Tests ────────────────────────────────────────────────────────────────────

describe( 'OnboardingThemeBuilder', () => {
	let container;
	let root;
	let openSessionMock;
	let sendMessageMock;
	let setSelectedAgentIdMock;

	beforeEach( () => {
		openSessionMock = jest.fn().mockResolvedValue( undefined );
		sendMessageMock = jest.fn().mockResolvedValue( undefined );
		setSelectedAgentIdMock = jest.fn();

		useDispatch.mockReturnValue( {
			openSession: openSessionMock,
			sendMessage: sendMessageMock,
			setSelectedAgentId: setSelectedAgentIdMock,
		} );

		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
	} );

	afterEach( async () => {
		await act( async () => {
			root.unmount();
		} );
		document.body.removeChild( container );
		jest.clearAllMocks();
	} );

	/**
	 * Helper — render OnboardingThemeBuilder inside act().
	 */
	async function renderThemeBuilder() {
		await act( async () => {
			root.render( createElement( OnboardingThemeBuilder, {} ) );
		} );
	}

	test( 'calls theme-builder-start endpoint on mount', async () => {
		apiFetch.mockResolvedValue( {
			session_id: 42,
			agent_id: 7,
			kickoff_message: 'Hi!',
		} );
		await renderThemeBuilder();
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/sd-ai-agent/v1/onboarding/theme-builder-start',
			method: 'POST',
		} );
	} );

	test( 'selects the Theme Builder agent returned by theme-builder-start', async () => {
		apiFetch.mockResolvedValue( {
			session_id: 42,
			agent_id: 7,
			kickoff_message: 'Hi!',
		} );
		await renderThemeBuilder();
		expect( setSelectedAgentIdMock ).toHaveBeenCalledWith( 7 );
	} );

	test( 'opens the session returned by theme-builder-start', async () => {
		apiFetch.mockResolvedValue( {
			session_id: 99,
			agent_id: 7,
			kickoff_message: 'Hi!',
		} );
		await renderThemeBuilder();
		expect( openSessionMock ).toHaveBeenCalledWith( 99 );
	} );

	test( 'sends the kickoff message after session opens (fresh start)', async () => {
		apiFetch.mockResolvedValue( {
			session_id: 42,
			agent_id: 7,
			kickoff_message: 'Hello from theme-builder kickoff',
			is_fresh_start: true,
		} );
		await renderThemeBuilder();
		expect( sendMessageMock ).toHaveBeenCalledWith(
			'Hello from theme-builder kickoff'
		);
	} );

	test( 'skips agent selection when no agent_id returned', async () => {
		apiFetch.mockResolvedValue( {
			session_id: 42,
			kickoff_message: 'Hello',
		} );
		await renderThemeBuilder();
		expect( setSelectedAgentIdMock ).not.toHaveBeenCalled();
		expect( openSessionMock ).toHaveBeenCalledWith( 42 );
	} );

	test( 'uses fallback kickoff message when none returned', async () => {
		apiFetch.mockResolvedValue( {
			session_id: 42,
			agent_id: 7,
			kickoff_message: null,
			is_fresh_start: true,
		} );
		await renderThemeBuilder();
		const [ msg ] = sendMessageMock.mock.calls[ 0 ];
		// Should contain a non-empty fallback string.
		expect( msg ).toBeTruthy();
		expect( typeof msg ).toBe( 'string' );
	} );

	// ── is_fresh_start regression coverage (#1522) ──────────────────────────

	test( 'sends the kickoff when is_fresh_start is true', async () => {
		apiFetch.mockResolvedValue( {
			session_id: 42,
			agent_id: 7,
			kickoff_message: 'Hi from kickoff',
			is_fresh_start: true,
		} );
		await renderThemeBuilder();
		expect( sendMessageMock ).toHaveBeenCalledWith( 'Hi from kickoff' );
	} );

	test( 'does NOT send the kickoff when is_fresh_start is false (resume)', async () => {
		apiFetch.mockResolvedValue( {
			session_id: 42,
			agent_id: 7,
			kickoff_message: 'Hi from kickoff',
			started_at: 1779203410, // truthy: would have fired kickoff under the pre-fix code
			is_fresh_start: false,
		} );
		await renderThemeBuilder();
		// openSession + setSelectedAgentId still fire on resume — only kickoff is suppressed.
		expect( openSessionMock ).toHaveBeenCalledWith( 42 );
		expect( setSelectedAgentIdMock ).toHaveBeenCalledWith( 7 );
		expect( sendMessageMock ).not.toHaveBeenCalled();
	} );

	test( 'does NOT send the kickoff when is_fresh_start is missing (defensive default)', async () => {
		// Defensive: an older server that does not return is_fresh_start
		// must not auto-fire the kickoff because we cannot prove it is fresh.
		apiFetch.mockResolvedValue( {
			session_id: 42,
			agent_id: 7,
			kickoff_message: 'Hi from kickoff',
		} );
		await renderThemeBuilder();
		expect( sendMessageMock ).not.toHaveBeenCalled();
	} );

	test( 'does not throw when theme-builder-start fails', async () => {
		apiFetch.mockRejectedValue( new Error( 'Network error' ) );
		// Should render without throwing.
		await expect( renderThemeBuilder() ).resolves.toBeUndefined();
		// openSession and sendMessage should not be called on error.
		expect( openSessionMock ).not.toHaveBeenCalled();
		expect( sendMessageMock ).not.toHaveBeenCalled();
		expect( setSelectedAgentIdMock ).not.toHaveBeenCalled();
	} );

	test( 'does not open a session when session_id is missing', async () => {
		apiFetch.mockResolvedValue( { success: true } ); // no session_id
		await renderThemeBuilder();
		// openSession should not be called without a session_id.
		expect( openSessionMock ).not.toHaveBeenCalled();
		expect( setSelectedAgentIdMock ).not.toHaveBeenCalled();
	} );

	test( 'renders the ChatRedesign component', async () => {
		apiFetch.mockResolvedValue( {
			session_id: 42,
			agent_id: 7,
			kickoff_message: 'Hi!',
		} );
		await renderThemeBuilder();
		expect(
			container.querySelector( '[data-testid="chat-redesign"]' )
		).not.toBeNull();
	} );

	test( 'does not call theme-builder-start twice on double-mount (strict-mode guard)', async () => {
		apiFetch.mockResolvedValue( {
			session_id: 42,
			agent_id: 7,
			kickoff_message: 'Hi!',
		} );

		// Mount once.
		await act( async () => {
			root.render( createElement( OnboardingThemeBuilder, {} ) );
		} );

		// Re-render with the same element (simulates React 18 strict-mode double-invoke).
		await act( async () => {
			root.render( createElement( OnboardingThemeBuilder, {} ) );
		} );

		// apiFetch should have been called only once despite two renders
		// because the ref guard prevents a second invocation.
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	} );
} );
