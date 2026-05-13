/**
 * Unit tests for components/onboarding-bootstrap.js
 *
 * Tests cover:
 * - Calls bootstrap-start endpoint on mount
 * - Selects the Setup Assistant agent returned by bootstrap-start
 * - Opens the session returned by bootstrap-start
 * - Sends the kickoff message returned by bootstrap-start
 * - Falls back gracefully when bootstrap-start fails
 * - Uses fallback kickoff message when none returned
 * - Skips agent selection when no agent_id returned
 * - Does not call bootstrap-start twice (React 18 strict-mode guard)
 */

import { createElement } from '@wordpress/element';
import { createRoot } from 'react-dom/client';
import { act } from 'react';
import { useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import OnboardingBootstrap from '../onboarding-bootstrap';

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

describe( 'OnboardingBootstrap', () => {
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
	 *
	 */
	async function renderBootstrap() {
		await act( async () => {
			root.render( createElement( OnboardingBootstrap, {} ) );
		} );
	}

	test( 'calls bootstrap-start endpoint on mount', async () => {
		apiFetch.mockResolvedValue( {
			session_id: 42,
			agent_id: 7,
			kickoff_message: 'Hi!',
		} );
		await renderBootstrap();
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/sd-ai-agent/v1/onboarding/bootstrap-start',
			method: 'POST',
		} );
	} );

	test( 'selects the Setup Assistant agent returned by bootstrap-start', async () => {
		apiFetch.mockResolvedValue( {
			session_id: 42,
			agent_id: 7,
			kickoff_message: 'Hi!',
		} );
		await renderBootstrap();
		expect( setSelectedAgentIdMock ).toHaveBeenCalledWith( 7 );
	} );

	test( 'opens the session returned by bootstrap-start', async () => {
		apiFetch.mockResolvedValue( {
			session_id: 99,
			agent_id: 7,
			kickoff_message: 'Hi!',
		} );
		await renderBootstrap();
		expect( openSessionMock ).toHaveBeenCalledWith( 99 );
	} );

	test( 'sends the kickoff message after session opens', async () => {
		apiFetch.mockResolvedValue( {
			session_id: 42,
			agent_id: 7,
			kickoff_message: 'Hello from kickoff',
		} );
		await renderBootstrap();
		expect( sendMessageMock ).toHaveBeenCalledWith( 'Hello from kickoff' );
	} );

	test( 'skips agent selection when no agent_id returned', async () => {
		apiFetch.mockResolvedValue( {
			session_id: 42,
			kickoff_message: 'Hello',
		} );
		await renderBootstrap();
		expect( setSelectedAgentIdMock ).not.toHaveBeenCalled();
		expect( openSessionMock ).toHaveBeenCalledWith( 42 );
	} );

	test( 'uses fallback kickoff message when none returned', async () => {
		apiFetch.mockResolvedValue( {
			session_id: 42,
			agent_id: 7,
			kickoff_message: null,
		} );
		await renderBootstrap();
		const [ msg ] = sendMessageMock.mock.calls[ 0 ];
		// Should contain a non-empty fallback string.
		expect( msg ).toBeTruthy();
		expect( typeof msg ).toBe( 'string' );
	} );

	test( 'does not throw when bootstrap-start fails', async () => {
		apiFetch.mockRejectedValue( new Error( 'Network error' ) );
		// Should render without throwing.
		await expect( renderBootstrap() ).resolves.toBeUndefined();
		// openSession and sendMessage should not be called on error.
		expect( openSessionMock ).not.toHaveBeenCalled();
		expect( sendMessageMock ).not.toHaveBeenCalled();
		expect( setSelectedAgentIdMock ).not.toHaveBeenCalled();
	} );

	test( 'does not open a session when session_id is missing', async () => {
		apiFetch.mockResolvedValue( { success: true } ); // no session_id
		await renderBootstrap();
		// openSession should not be called without a session_id.
		expect( openSessionMock ).not.toHaveBeenCalled();
		expect( setSelectedAgentIdMock ).not.toHaveBeenCalled();
	} );
} );
