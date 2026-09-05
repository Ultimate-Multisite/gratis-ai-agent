/**
 * Tests for automatic post-failure feedback reporting.
 */

import apiFetch from '@wordpress/api-fetch';
import { useDispatch } from '@wordpress/data';
import { createElement, createRoot } from '@wordpress/element';
import { act } from 'react';

import AutomaticFeedbackPrompt from '../automatic-feedback-prompt';
import {
	FEEDBACK_REPORTING_PREFERENCE_KEY,
	setFeedbackReportingPreference,
	toolCallsContainFailure,
} from '../../utils/feedback-reporting';

global.IS_REACT_ACT_ENVIRONMENT = true;

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@wordpress/data', () => ( {
	useDispatch: jest.fn(),
} ) );
jest.mock( '@wordpress/i18n', () => ( {
	__: ( text ) => text,
} ) );
jest.mock( '../../store', () => 'sd-ai-agent' );
jest.mock( '@wordpress/components', () => ( {
	Button: ( { children, ...props } ) => {
		const { createElement: createWpElement } =
			jest.requireActual( '@wordpress/element' );
		return createWpElement( 'button', props, children );
	},
} ) );

const failure = { reason: 'tool_call_error', eventId: 'job-17' };

/**
 * Render the automatic feedback prompt.
 *
 * @return {Promise<{container: HTMLElement, root: import('@wordpress/element').Root}>}
 *   Rendered prompt and root.
 */
async function renderPrompt() {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );
	await act( async () => {
		root.render(
			createElement( AutomaticFeedbackPrompt, {
				sessionId: 17,
				failure,
			} )
		);
	} );
	return { container, root };
}

/**
 * Find a rendered button by its visible label.
 *
 * @param {HTMLElement} container Rendered test container.
 * @param {string}      label     Button label.
 * @return {HTMLButtonElement|undefined} Matching button.
 */
function button( container, label ) {
	return [ ...container.querySelectorAll( 'button' ) ].find(
		( item ) => item.textContent === label
	);
}

describe( 'AutomaticFeedbackPrompt', () => {
	let setFeedbackBanner;

	beforeEach( () => {
		delete global.sdAiAgentData;
		localStorage.clear();
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( { success: true } );
		setFeedbackBanner = jest.fn();
		useDispatch.mockReturnValue( { setFeedbackBanner } );
	} );

	afterEach( () => {
		document.body.innerHTML = '';
		jest.clearAllMocks();
	} );

	test( 'offers all four reporting choices after a detected failure', async () => {
		const { container, root } = await renderPrompt();

		expect( container.textContent ).toContain(
			'It looks like part of this job failed.'
		);
		expect( button( container, 'No' ) ).toBeDefined();
		expect( button( container, 'No, never' ) ).toBeDefined();
		expect( button( container, 'Yes' ) ).toBeDefined();
		expect( button( container, 'Yes, always' ) ).toBeDefined();

		await act( async () => root.unmount() );
	} );

	test( 'No dismisses once and No, never persists the opt-out', async () => {
		let rendered = await renderPrompt();
		await act( async () => {
			button( rendered.container, 'No' ).click();
		} );
		expect( setFeedbackBanner ).toHaveBeenCalledWith( null );
		expect(
			localStorage.getItem( FEEDBACK_REPORTING_PREFERENCE_KEY )
		).toBeNull();
		await act( async () => rendered.root.unmount() );

		setFeedbackBanner.mockClear();
		rendered = await renderPrompt();
		await act( async () => {
			button( rendered.container, 'No, never' ).click();
		} );
		expect(
			localStorage.getItem( FEEDBACK_REPORTING_PREFERENCE_KEY )
		).toBe( 'never' );
		expect( setFeedbackBanner ).toHaveBeenCalledWith( null );
		await act( async () => rendered.root.unmount() );
	} );

	test( 'Yes sends this report without changing the preference', async () => {
		const { container, root } = await renderPrompt();
		await act( async () => {
			button( container, 'Yes' ).click();
			await Promise.resolve();
		} );

		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/sd-ai-agent/v1/feedback/send',
				method: 'POST',
				data: expect.objectContaining( {
					report_type: 'self_reported',
					session_id: 17,
				} ),
			} )
		);
		expect(
			localStorage.getItem( FEEDBACK_REPORTING_PREFERENCE_KEY )
		).toBeNull();
		expect( setFeedbackBanner ).toHaveBeenCalledWith( null );

		await act( async () => root.unmount() );
	} );

	test( 'Yes, always sends now and automatically sends later failures', async () => {
		let rendered = await renderPrompt();
		await act( async () => {
			button( rendered.container, 'Yes, always' ).click();
			await Promise.resolve();
		} );
		expect(
			localStorage.getItem( FEEDBACK_REPORTING_PREFERENCE_KEY )
		).toBe( 'always' );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		await act( async () => rendered.root.unmount() );

		apiFetch.mockClear();
		setFeedbackBanner.mockClear();
		rendered = await renderPrompt();
		await act( async () => Promise.resolve() );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( rendered.container.querySelector( 'button' ) ).toBeNull();
		expect( setFeedbackBanner ).toHaveBeenCalledWith( null );
		await act( async () => rendered.root.unmount() );
	} );
} );

describe( 'feedback reporting helpers', () => {
	test( 'scopes persistent consent to the current WordPress user', () => {
		localStorage.clear();
		global.sdAiAgentData = { currentUserId: 42 };
		setFeedbackReportingPreference( 'always' );

		expect(
			localStorage.getItem( `${ FEEDBACK_REPORTING_PREFERENCE_KEY }:42` )
		).toBe( 'always' );
		expect(
			localStorage.getItem( FEEDBACK_REPORTING_PREFERENCE_KEY )
		).toBeNull();
	} );

	test( 'detects explicit tool response failures only', () => {
		expect(
			toolCallsContainFailure( [
				{ type: 'call', id: '1' },
				{
					type: 'response',
					id: '1',
					response: { error: 'failed' },
				},
			] )
		).toBe( true );
		expect(
			toolCallsContainFailure( [
				{
					type: 'response',
					id: '2',
					response: { success: true },
				},
			] )
		).toBe( false );
		expect(
			toolCallsContainFailure( [
				{
					type: 'response',
					response: {
						success: true,
						result: { success: false, error: 'Validation failed.' },
					},
				},
			] )
		).toBe( true );
	} );
} );
