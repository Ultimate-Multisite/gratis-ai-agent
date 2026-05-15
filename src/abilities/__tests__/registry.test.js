/**
 * Unit tests for src/abilities/registry.js
 *
 * Tests cover the sd-ai-86a regression: the local clientCallbacks map must
 * be populated even when the WP 7.0 `@wordpress/abilities` script module has
 * not loaded on the page, so that jobSlice's executeClientAbility() can still
 * invoke screenshot-url, navigate-to, capture-screenshot, and insert-block
 * when the chat job returns pending_client_tool_calls.
 *
 * Bug history:
 *   - registerClientAbility() previously returned early (registry.js:189-192)
 *     when abilitiesApiAvailable() was false, before storing the callback in
 *     clientCallbacks. executeClientAbility() then threw
 *     'Client ability "X" is not registered on this page' even though the
 *     callback existed in the bundle.
 *   - Fix: store the local callback first, then gate only the
 *     wp.abilities.registerAbility() call on API availability.
 */

/**
 * Each test loads a fresh registry module via jest.isolateModules so the
 * module-scoped `registeredAbilityNames` and `clientCallbacks` Maps start
 * empty. This avoids cross-test bleed while still exercising the real
 * registry code (not a mock).
 */
function loadRegistry() {
	let mod;
	jest.isolateModules( () => {
		// eslint-disable-next-line global-require
		mod = require( '../registry' );
	} );
	return mod;
}

describe( 'registry — sd-ai-86a regression', () => {
	let originalWp;

	beforeEach( () => {
		originalWp = global.wp;
	} );

	afterEach( () => {
		global.wp = originalWp;
	} );

	test( 'registerClientAbility stores callback locally even when wp.abilities is undefined', async () => {
		// Simulate a page where @wordpress/abilities never loaded.
		delete global.wp;
		const { registerClientAbility, executeClientAbility } = loadRegistry();

		const callback = jest.fn().mockResolvedValue( { ok: true } );

		await registerClientAbility( {
			name: 'sd-ai-agent-js/test-no-api',
			label: 'Test No API',
			description: 'Test that callbacks register without the WP API',
			inputSchema: { type: 'object' },
			outputSchema: { type: 'object' },
			annotations: { readonly: true },
			callback,
		} );

		const result = await executeClientAbility(
			'sd-ai-agent-js/test-no-api',
			{ foo: 'bar' }
		);
		expect( callback ).toHaveBeenCalledWith( { foo: 'bar' } );
		expect( result ).toEqual( { ok: true } );
	} );

	test( 'executeClientAbility throws for truly unknown abilities', async () => {
		delete global.wp;
		const { executeClientAbility } = loadRegistry();

		await expect(
			executeClientAbility( 'sd-ai-agent-js/never-registered', {} )
		).rejects.toThrow( /is not registered on this page/ );
	} );

	test( 'executeClientAbility falls back to wp.abilities.executeAbility when the local map misses but WP API is present', async () => {
		// Local map does NOT contain this ability (different module
		// instance scenario), but wp.abilities.executeAbility is wired up.
		global.wp = {
			abilities: {
				executeAbility: jest
					.fn()
					.mockResolvedValue( { fromWpApi: true } ),
				registerAbility: jest.fn().mockResolvedValue( undefined ),
				registerAbilityCategory: jest
					.fn()
					.mockResolvedValue( undefined ),
				getAbilities: jest.fn().mockReturnValue( [] ),
			},
		};
		const { executeClientAbility } = loadRegistry();

		const result = await executeClientAbility(
			'sd-ai-agent-js/only-in-wp-store',
			{ key: 'value' }
		);

		expect( global.wp.abilities.executeAbility ).toHaveBeenCalledWith(
			'sd-ai-agent-js/only-in-wp-store',
			{ key: 'value' }
		);
		expect( result ).toEqual( { fromWpApi: true } );
	} );

	test( 'registerClientAbility writes to wp.abilities when API is available', async () => {
		const registerAbility = jest.fn().mockResolvedValue( undefined );
		global.wp = {
			abilities: {
				executeAbility: jest.fn(),
				registerAbility,
				registerAbilityCategory: jest
					.fn()
					.mockResolvedValue( undefined ),
				getAbilities: jest.fn().mockReturnValue( [] ),
			},
		};
		const { registerClientAbility, executeClientAbility } = loadRegistry();

		const callback = jest.fn().mockResolvedValue( { wired: true } );
		await registerClientAbility( {
			name: 'sd-ai-agent-js/with-api',
			label: 'With API',
			description: 'Should also reach wp.abilities.registerAbility',
			inputSchema: { type: 'object' },
			outputSchema: { type: 'object' },
			annotations: { readonly: true },
			callback,
		} );

		expect( registerAbility ).toHaveBeenCalledTimes( 1 );
		expect( registerAbility.mock.calls[ 0 ][ 0 ] ).toMatchObject( {
			name: 'sd-ai-agent-js/with-api',
			category: 'sd-ai-agent-js',
			callback,
		} );

		// Local execution path still works alongside the WP store entry.
		await executeClientAbility( 'sd-ai-agent-js/with-api', { x: 1 } );
		expect( callback ).toHaveBeenCalledWith( { x: 1 } );
	} );
} );
