/**
 * Unit tests for the reflection event emitter
 * (src/store/reflection-emitter.js).
 *
 * Covers:
 * - emits one event per new response entry with `affected`
 * - skips entries before the cursor (no double-emit on subsequent polls)
 * - skips non-response entries and responses without `affected`
 * - pairs response with matching call entry by id for args
 * - returns entries.length as the new cursor
 * - tolerates malformed input gracefully
 */

import { emitReflectionEvents } from '../reflection-emitter';
import sharedBus, { __resetReflectionBusForTests } from '../reflection-bus';

describe( 'reflection-emitter', () => {
	let received;
	let unsubscribe;

	beforeEach( () => {
		__resetReflectionBusForTests();
		received = [];
		unsubscribe = sharedBus.on( ( evt ) => received.push( evt ) );
	} );

	afterEach( () => {
		unsubscribe();
		__resetReflectionBusForTests();
	} );

	test( 'emits one event per new response entry with `affected`', () => {
		const entries = [
			{
				type: 'call',
				id: 'c1',
				name: 'sd-ai-agent/update-post',
				args: { post_id: 42, title: 'New' },
			},
			{
				type: 'response',
				id: 'c1',
				name: 'sd-ai-agent/update-post',
				response: {
					post_id: 42,
					affected: {
						kind: 'post',
						post_id: 42,
						url: 'https://example.com/about/',
						fields: [ 'post_title' ],
					},
				},
			},
		];

		const next = emitReflectionEvents( entries, 0, {
			sessionId: 7,
			jobId: 'job-abc',
		} );

		expect( next ).toBe( 2 );
		expect( received ).toHaveLength( 1 );
		expect( received[ 0 ] ).toEqual( {
			type: 'tool-applied',
			tool: 'sd-ai-agent/update-post',
			sessionId: 7,
			jobId: 'job-abc',
			args: { post_id: 42, title: 'New' },
			result: entries[ 1 ].response,
			affected: entries[ 1 ].response.affected,
		} );
	} );

	test( 'skips entries before the cursor on subsequent calls', () => {
		const entries = [
			{
				type: 'call',
				id: 'c1',
				name: 'sd-ai-agent/update-post',
				args: {},
			},
			{
				type: 'response',
				id: 'c1',
				name: 'sd-ai-agent/update-post',
				response: { affected: { kind: 'post', post_id: 1 } },
			},
		];

		const first = emitReflectionEvents( entries, 0, {
			sessionId: 1,
			jobId: 'j',
		} );
		// Second call with the same entries should not re-emit.
		const second = emitReflectionEvents( entries, first, {
			sessionId: 1,
			jobId: 'j',
		} );

		expect( first ).toBe( 2 );
		expect( second ).toBe( 2 );
		expect( received ).toHaveLength( 1 );
	} );

	test( 'skips non-response entries and responses without affected', () => {
		const entries = [
			{ type: 'preamble', text: 'Looking up your post…' },
			{
				type: 'call',
				id: 'c1',
				name: 'sd-ai-agent/get-post',
				args: { id: 42 },
			},
			{
				type: 'response',
				id: 'c1',
				name: 'sd-ai-agent/get-post',
				response: { post_id: 42, title: 'Hello' }, // No `affected` — readonly tool.
			},
			{
				type: 'provider_retry',
				message: 'Retrying…',
			},
		];

		emitReflectionEvents( entries, 0, { sessionId: 1, jobId: 'j' } );

		expect( received ).toHaveLength( 0 );
	} );

	test( 'pairs response with matching call entry by id for args', () => {
		const entries = [
			{ type: 'call', id: 'a', name: 'foo', args: { x: 1 } },
			{ type: 'call', id: 'b', name: 'bar', args: { y: 2 } },
			{
				type: 'response',
				id: 'b',
				name: 'bar',
				response: { affected: { kind: 'post', post_id: 9 } },
			},
		];

		emitReflectionEvents( entries, 0, { sessionId: 1, jobId: 'j' } );

		expect( received ).toHaveLength( 1 );
		expect( received[ 0 ].args ).toEqual( { y: 2 } );
	} );

	test( 'tolerates malformed input gracefully', () => {
		expect(
			emitReflectionEvents( /** @type {*} */ ( null ), 0, {
				sessionId: 1,
				jobId: 'j',
			} )
		).toBe( 0 );

		expect(
			emitReflectionEvents(
				[ null, undefined, { type: 'response' } ],
				0,
				{
					sessionId: 1,
					jobId: 'j',
				}
			)
		).toBe( 3 );

		expect( received ).toHaveLength( 0 );
	} );

	test( 'cursor beyond array length is clamped, not crashing', () => {
		const entries = [
			{
				type: 'response',
				id: 'x',
				name: 'foo',
				response: { affected: { kind: 'post' } },
			},
		];

		const next = emitReflectionEvents( entries, 9999, {
			sessionId: 1,
			jobId: 'j',
		} );

		expect( next ).toBe( 1 );
		expect( received ).toHaveLength( 0 );
	} );
} );
