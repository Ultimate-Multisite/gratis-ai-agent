/**
 * Tests for shared session-change count loading.
 */

import { createElement } from '@wordpress/element';
import { createRoot } from 'react-dom/client';
import { act } from 'react';
import apiFetch from '@wordpress/api-fetch';

import useChangesCount from '../use-changes-count';

global.IS_REACT_ACT_ENVIRONMENT = true;

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

/**
 * Render the current count for hook assertions.
 *
 * @param {Object}  root0
 * @param {number}  root0.sessionId Active session ID.
 * @param {boolean} root0.sending   Whether a turn is active.
 * @return {JSX.Element} Count fixture.
 */
function ChangesCountHarness( { sessionId, sending } ) {
	const { changesCount } = useChangesCount( { sessionId, sending } );
	return <span data-count>{ changesCount }</span>;
}

/**
 * Create a manually resolved promise.
 *
 * @return {{promise: Promise, resolve: Function}} Deferred promise.
 */
function deferred() {
	let resolve;
	const promise = new Promise( ( resolver ) => {
		resolve = resolver;
	} );
	return { promise, resolve };
}

describe( 'useChangesCount', () => {
	let container;
	let root;

	beforeEach( () => {
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
		apiFetch.mockReset();
	} );

	afterEach( async () => {
		await act( async () => root.unmount() );
		container.remove();
	} );

	test( 'fetches once per session and ignores stale responses', async () => {
		const first = deferred();
		const second = deferred();
		apiFetch
			.mockReturnValueOnce( first.promise )
			.mockReturnValueOnce( second.promise );

		await act( async () => {
			root.render(
				createElement( ChangesCountHarness, {
					sessionId: 1,
					sending: false,
				} )
			);
		} );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );

		await act( async () => {
			root.render(
				createElement( ChangesCountHarness, {
					sessionId: 2,
					sending: false,
				} )
			);
		} );
		expect( apiFetch ).toHaveBeenCalledTimes( 2 );

		await act( async () => second.resolve( { total: 2 } ) );
		expect( container.querySelector( '[data-count]' ).textContent ).toBe(
			'2'
		);

		await act( async () => first.resolve( { total: 9 } ) );
		expect( container.querySelector( '[data-count]' ).textContent ).toBe(
			'2'
		);
	} );

	test( 'refreshes once when a turn completes', async () => {
		apiFetch.mockResolvedValue( { total: 1 } );
		await act( async () => {
			root.render(
				createElement( ChangesCountHarness, {
					sessionId: 1,
					sending: true,
				} )
			);
		} );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );

		await act( async () => {
			root.render(
				createElement( ChangesCountHarness, {
					sessionId: 1,
					sending: false,
				} )
			);
		} );
		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
	} );

	test( 'does not count a session switch as a completed turn', async () => {
		apiFetch.mockResolvedValue( { total: 1 } );
		await act( async () => {
			root.render(
				createElement( ChangesCountHarness, {
					sessionId: 1,
					sending: true,
				} )
			);
		} );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );

		await act( async () => {
			root.render(
				createElement( ChangesCountHarness, {
					sessionId: 2,
					sending: false,
				} )
			);
		} );
		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
	} );
} );
