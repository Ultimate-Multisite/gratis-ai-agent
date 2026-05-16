/**
 * Unit tests for chat-redesign/message-helpers.js
 *
 * Tests cover:
 * - pairToolCalls() pairs calls with responses by id and ignores preamble entries.
 * - pairToolCalls() falls back to one item per entry when no call types exist,
 *   while still skipping preamble entries.
 * - buildRunningItems() preserves original emission order across preamble and
 *   call entries, suppresses whitespace-only preambles, and assigns stable keys.
 * - getRunningToolName() ignores preamble entries when deciding what is in
 *   flight.
 * - extractText() concatenates only text parts.
 */

import {
	buildRunningItems,
	extractText,
	getRunningToolName,
	pairToolCalls,
	parseSuggestions,
} from '../message-helpers';

describe( 'extractText', () => {
	test( 'concatenates text parts in order', () => {
		const msg = {
			parts: [ { text: 'Hello ' }, { text: 'world' } ],
		};
		expect( extractText( msg ) ).toBe( 'Hello world' );
	} );

	test( 'ignores function-call parts', () => {
		const msg = {
			parts: [
				{ text: 'Hi' },
				{ functionCall: { id: 'a', name: 'x', args: {} } },
				{ text: ' there' },
			],
		};
		expect( extractText( msg ) ).toBe( 'Hi there' );
	} );

	test( 'returns empty string for missing/empty parts', () => {
		expect( extractText( {} ) ).toBe( '' );
		expect( extractText( { parts: [] } ) ).toBe( '' );
	} );
} );

describe( 'pairToolCalls', () => {
	test( 'returns empty array for empty or missing input', () => {
		expect( pairToolCalls( null ) ).toEqual( [] );
		expect( pairToolCalls( [] ) ).toEqual( [] );
	} );

	test( 'pairs calls to responses by id', () => {
		const log = [
			{ type: 'call', id: 'a', name: 'tool_a', args: {} },
			{ type: 'response', id: 'a', response: { ok: true } },
		];
		const pairs = pairToolCalls( log );
		expect( pairs ).toHaveLength( 1 );
		expect( pairs[ 0 ].call.id ).toBe( 'a' );
		expect( pairs[ 0 ].response.response ).toEqual( { ok: true } );
	} );

	test( 'ignores preamble entries entirely', () => {
		const log = [
			{ type: 'preamble', text: 'Looking that up...' },
			{ type: 'call', id: 'a', name: 'tool_a', args: {} },
		];
		const pairs = pairToolCalls( log );
		expect( pairs ).toHaveLength( 1 );
		expect( pairs[ 0 ].call.id ).toBe( 'a' );
	} );

	test( 'fallback path still skips preamble entries', () => {
		// No type='call' entries → fallback shows non-preamble entries as
		// orphan cards. Preamble entries must not appear.
		const log = [
			{ type: 'preamble', text: 'Thinking…' },
			{ type: 'misc', id: 'x', name: 'unknown' },
		];
		const pairs = pairToolCalls( log );
		expect( pairs ).toHaveLength( 1 );
		expect( pairs[ 0 ].call.type ).toBe( 'misc' );
	} );
} );

describe( 'buildRunningItems', () => {
	test( 'returns empty array for empty or missing input', () => {
		expect( buildRunningItems( null ) ).toEqual( [] );
		expect( buildRunningItems( [] ) ).toEqual( [] );
	} );

	test( 'preserves emission order across preamble and call entries', () => {
		const log = [
			{ type: 'preamble', text: 'First, let me look that up.' },
			{ type: 'call', id: 'a', name: 'tool_a', args: {} },
			{ type: 'response', id: 'a', response: { ok: true } },
			{ type: 'preamble', text: 'Now updating the page.' },
			{ type: 'call', id: 'b', name: 'tool_b', args: {} },
		];
		const items = buildRunningItems( log );
		expect( items.map( ( i ) => i.kind ) ).toEqual( [
			'preamble',
			'pair',
			'preamble',
			'pair',
		] );
		expect( items[ 0 ].text ).toBe( 'First, let me look that up.' );
		expect( items[ 1 ].call.id ).toBe( 'a' );
		expect( items[ 1 ].response.response ).toEqual( { ok: true } );
		expect( items[ 2 ].text ).toBe( 'Now updating the page.' );
		expect( items[ 3 ].call.id ).toBe( 'b' );
		expect( items[ 3 ].response ).toBeNull();
	} );

	test( 'suppresses whitespace-only preambles', () => {
		const log = [
			{ type: 'preamble', text: '  \n  ' },
			{ type: 'preamble', text: '' },
			{ type: 'call', id: 'a', name: 'tool_a', args: {} },
		];
		const items = buildRunningItems( log );
		expect( items ).toHaveLength( 1 );
		expect( items[ 0 ].kind ).toBe( 'pair' );
	} );

	test( 'assigns stable keys derived from call ids', () => {
		const log = [
			{ type: 'preamble', text: 'A' },
			{ type: 'call', id: 'call-xyz', name: 'tool_a', args: {} },
		];
		const items = buildRunningItems( log );
		expect( items[ 0 ].key ).toBe( 'preamble-0' );
		expect( items[ 1 ].key ).toBe( 'call-xyz' );
	} );

	test( 'still works when responses arrive out of order', () => {
		const log = [
			{ type: 'call', id: 'a', name: 'tool_a', args: {} },
			{ type: 'call', id: 'b', name: 'tool_b', args: {} },
			{ type: 'response', id: 'b', response: { ok: 'b' } },
			{ type: 'response', id: 'a', response: { ok: 'a' } },
		];
		const items = buildRunningItems( log );
		expect( items ).toHaveLength( 2 );
		expect( items[ 0 ].response.response ).toEqual( { ok: 'a' } );
		expect( items[ 1 ].response.response ).toEqual( { ok: 'b' } );
	} );
} );

describe( 'getRunningToolName', () => {
	test( 'returns null when no calls are present', () => {
		expect( getRunningToolName( [] ) ).toBeNull();
		expect(
			getRunningToolName( [ { type: 'preamble', text: 'thinking…' } ] )
		).toBeNull();
	} );

	test( 'returns the running tool name when calls outnumber responses', () => {
		const log = [
			{ type: 'preamble', text: 'one sec' },
			{ type: 'call', id: 'a', name: 'wpab__sd-ai-agent__memory-list' },
		];
		expect( getRunningToolName( log ) ).toBe( 'sd-ai-agent/memory-list' );
	} );

	test( 'returns null when every call has a response', () => {
		const log = [
			{ type: 'call', id: 'a', name: 'wpab__sd-ai-agent__memory-list' },
			{ type: 'response', id: 'a', response: { ok: true } },
		];
		expect( getRunningToolName( log ) ).toBeNull();
	} );
} );

describe( 'parseSuggestions', () => {
	test( 'strips trailing [suggestion] lines and returns them separately', () => {
		const { cleanText, suggestions } = parseSuggestions(
			'Hello world.\n\n[suggestion] Try this\n[suggestion] Or that'
		);
		expect( cleanText ).toBe( 'Hello world.' );
		expect( suggestions ).toEqual( [ 'Try this', 'Or that' ] );
	} );

	test( 'leaves text alone when no suggestions present', () => {
		const { cleanText, suggestions } = parseSuggestions( 'Just text.' );
		expect( cleanText ).toBe( 'Just text.' );
		expect( suggestions ).toEqual( [] );
	} );
} );
