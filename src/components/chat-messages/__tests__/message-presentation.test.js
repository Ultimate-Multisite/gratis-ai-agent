/**
 * Tests for the shared main/widget message presentation contract.
 */

import {
	getRunningStatusMessage,
	getRunningJobPresentation,
	getVisibleMessages,
	RUNNING_STATUS_MESSAGES,
	resolveSystemMessagePresentation,
} from '../message-presentation';

jest.mock( '@wordpress/i18n', () => ( {
	__: ( text ) => text,
} ) );

describe( 'shared chat message presentation', () => {
	test( 'filters non-visible records once for every chat surface', () => {
		const visible = getVisibleMessages( [
			{ role: 'function', parts: [ { text: 'hidden' } ] },
			{ role: 'model', parts: [ { text: '' } ] },
			{ role: 'user', parts: [ { text: '' } ] },
			{ role: 'user', parts: [ { text: 'Visible request' } ] },
			{ role: 'model', toolCalls: [ { type: 'call' } ] },
		] );

		expect( visible.map( ( item ) => item.index ) ).toEqual( [ 3, 4 ] );
	} );

	test( 'prefers structured account actions over message text', () => {
		const notice = {
			type: 'account_action',
			reason: 'credit_exhausted',
			action: 'purchase_credits',
			actionUrl: 'https://account.example.test/credits',
		};

		expect(
			resolveSystemMessagePresentation(
				{
					role: 'system',
					parts: [ { text: 'PRIVATE_PROVIDER_ERROR' } ],
					notice,
				},
				[]
			)
		).toEqual( { type: 'account_action', notice } );
	} );

	test( 'normalizes legacy credit text without exposing provider details', () => {
		const presentation = resolveSystemMessagePresentation(
			{
				role: 'system',
				parts: [
					{
						text: 'Client error 402: Superdav credit balance is insufficient.',
					},
				],
			},
			[
				{
					id: 'sd-ai-agent-cloud',
					status: {
						purchase_credits_url:
							'https://account.example.test/credits',
					},
				},
			]
		);

		expect( presentation ).toEqual( {
			type: 'account_action',
			notice: {
				type: 'account_action',
				reason: 'credit_exhausted',
				action: 'purchase_credits',
				actionUrl: 'https://account.example.test/credits',
			},
		} );
	} );

	test( 'uses the same running-job precedence and starts the fallback rotation', () => {
		expect(
			getRunningJobPresentation( {
				currentSessionId: 7,
				sessionJobs: {
					7: { toolCalls: [ { type: 'preamble', text: 'Working' } ] },
				},
				liveToolCalls: [ { type: 'call', name: 'ignored' } ],
			} )
		).toEqual( {
			toolCalls: [ { type: 'preamble', text: 'Working' } ],
			isFallback: true,
			step: 'Thinking…',
		} );
	} );

	test( 'rotates 100 progressive fallback statuses in order and wraps', () => {
		expect( RUNNING_STATUS_MESSAGES ).toHaveLength( 100 );
		expect( getRunningStatusMessage( 0 ) ).toBe( 'Thinking…' );
		expect( getRunningStatusMessage( 1 ) ).toBe( 'Working…' );
		expect( getRunningStatusMessage( 77 ) ).toBe( 'Transmogrifying…' );
		expect( getRunningStatusMessage( 78 ) ).toBe( 'Discombobulating…' );
		expect( getRunningStatusMessage( 100 ) ).toBe( 'Thinking…' );
	} );
} );
