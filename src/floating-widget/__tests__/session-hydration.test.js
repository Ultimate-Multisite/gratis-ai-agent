/**
 * Tests for race-safe floating-widget session hydration.
 */

import { openHydrated } from '../frontend-onboarding';

/**
 * Create a manually resolved import promise.
 *
 * @return {{promise: Promise, resolve: Function}} Deferred import.
 */
function deferred() {
	let resolve;
	const promise = new Promise( ( resolver ) => {
		resolve = resolver;
	} );
	return { promise, resolve };
}

describe( 'hydrateSession', () => {
	test( 'does not reopen a session after a pending hydration is invalidated', async () => {
		const pendingImport = deferred();
		const openSession = jest.fn();
		let current = true;
		const hydration = pendingImport.promise.then( () =>
			openHydrated( [ { id: 12 } ], {}, openSession, () => current )
		);

		// Mirrors CLEAR_CURRENT_SESSION invalidating the effect while import()
		// remains pending.
		current = false;
		pendingImport.resolve();

		await expect( hydration ).resolves.toBeNull();
		expect( openSession ).not.toHaveBeenCalled();
	} );

	test( 'opens the selected session while the hydration run is current', () => {
		const openSession = jest.fn();
		expect(
			openHydrated( [ { id: 12 } ], {}, openSession, () => true )
		).toBe( 12 );
		expect( openSession ).toHaveBeenCalledWith( 12 );
	} );
} );
