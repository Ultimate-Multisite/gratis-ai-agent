/**
 * Unit tests for the fallback reload toast reflector.
 */

import {
	showFallbackToast,
	__resetFallbackToastForTests,
	__setFallbackToastReloadForTests,
} from '../fallback-toast';

describe( 'fallback-toast', () => {
	let reloadMock;

	beforeEach( () => {
		document.body.className = '';
		__resetFallbackToastForTests();

		reloadMock = jest.fn();
		__setFallbackToastReloadForTests( reloadMock );
	} );

	afterEach( () => {
		__resetFallbackToastForTests();
		document.body.className = '';
	} );

	test( 'renders a toast with count 1 on first event', () => {
		showFallbackToast( { affected: { kind: 'term' } } );

		const toast = document.querySelector( '.sd-ai-agent-fallback-toast' );

		expect( toast ).not.toBeNull();
		expect(
			toast.querySelector( '.sd-ai-agent-fallback-toast__msg' )
		).toHaveProperty(
			'textContent',
			'Agent made 1 update. Reload to see changes.'
		);
	} );

	test( 'increments count while keeping a single toast element', () => {
		showFallbackToast( { affected: { kind: 'term' } } );
		showFallbackToast( { affected: { kind: 'media' } } );

		expect(
			document.querySelectorAll( '.sd-ai-agent-fallback-toast' )
		).toHaveLength( 1 );
		expect(
			document.querySelector( '.sd-ai-agent-fallback-toast__msg' )
		).toHaveProperty(
			'textContent',
			'Agent made 2 updates. Reload to see changes.'
		);
	} );

	test( 'dismiss removes the toast and resets the count', () => {
		showFallbackToast( { affected: { kind: 'term' } } );
		showFallbackToast( { affected: { kind: 'media' } } );

		document
			.querySelector( '.sd-ai-agent-fallback-toast__dismiss' )
			.click();

		expect(
			document.querySelector( '.sd-ai-agent-fallback-toast' )
		).toBeNull();

		showFallbackToast( { affected: { kind: 'term' } } );

		expect(
			document.querySelector( '.sd-ai-agent-fallback-toast__msg' )
		).toHaveProperty(
			'textContent',
			'Agent made 1 update. Reload to see changes.'
		);
	} );

	test( 'reload button calls window.location.reload', () => {
		showFallbackToast( { affected: { kind: 'term' } } );

		document.querySelector( '.sd-ai-agent-fallback-toast__reload' ).click();

		expect( reloadMock ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'sets polite status semantics', () => {
		showFallbackToast( { affected: { kind: 'term' } } );

		const toast = document.querySelector( '.sd-ai-agent-fallback-toast' );

		expect( toast.getAttribute( 'role' ) ).toBe( 'status' );
		expect( toast.getAttribute( 'aria-live' ) ).toBe( 'polite' );
	} );

	test( 'does not show on wp-admin screens', () => {
		document.body.classList.add( 'wp-admin' );

		showFallbackToast( { affected: { kind: 'term' } } );

		expect(
			document.querySelector( '.sd-ai-agent-fallback-toast' )
		).toBeNull();
	} );
} );
