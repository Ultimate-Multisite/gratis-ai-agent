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
 * Each test loads a fresh registry module via jest.isolateModules. The
 * page-global registry is cleared in beforeEach so tests avoid cross-test
 * bleed while still exercising the real registry code (not a mock).
 */
function loadRegistry() {
	let mod;
	jest.isolateModules( () => {
		// eslint-disable-next-line global-require
		mod = require( '../registry' );
	} );
	return mod;
}

const WIN_REGISTRY_KEY = '__sdAiAgentClientAbilityRegistry';

/**
 * Load refresh-page and its matching registry module instance.
 *
 * @return {{ refreshPage: Object, registry: Object }} Isolated modules.
 */
function loadRefreshPageAndRegistry() {
	let refreshPage;
	let registry;
	jest.isolateModules( () => {
		// eslint-disable-next-line global-require
		refreshPage = require( '../refresh-page' );
		// eslint-disable-next-line global-require
		registry = require( '../registry' );
	} );
	return { refreshPage, registry };
}

describe( 'registry — sd-ai-86a regression', () => {
	let originalWp;

	beforeEach( () => {
		originalWp = global.wp;
		delete window[ WIN_REGISTRY_KEY ];
	} );

	afterEach( () => {
		global.wp = originalWp;
		delete window[ WIN_REGISTRY_KEY ];
	} );

	test( 'shares callbacks and descriptors between webpack module instances without the WP API', async () => {
		delete global.wp;
		const firstBundle = loadRegistry();
		const secondBundle = loadRegistry();
		const callback = jest.fn().mockResolvedValue( { shared: true } );

		await firstBundle.registerClientAbility( {
			name: 'sd-ai-agent-js/cross-bundle',
			label: 'Cross Bundle',
			description: 'Registered by another bundle',
			inputSchema: { type: 'object' },
			outputSchema: { type: 'object' },
			annotations: { readonly: true },
			callback,
		} );

		await expect(
			secondBundle.executeClientAbility( 'sd-ai-agent-js/cross-bundle', {
				from: 'second-bundle',
			} )
		).resolves.toEqual( { shared: true } );
		expect( callback ).toHaveBeenCalledWith( { from: 'second-bundle' } );
		await expect( secondBundle.snapshotDescriptors() ).resolves.toEqual( [
			expect.objectContaining( {
				name: 'sd-ai-agent-js/cross-bundle',
				annotations: { readonly: true },
			} ),
		] );
	} );

	test( 'deduplicates WP ability registration between webpack module instances', async () => {
		const registerAbility = jest.fn().mockResolvedValue( undefined );
		global.wp = {
			abilities: {
				registerAbility,
				registerAbilityCategory: jest
					.fn()
					.mockResolvedValue( undefined ),
			},
		};
		const firstBundle = loadRegistry();
		const secondBundle = loadRegistry();
		const definition = {
			name: 'sd-ai-agent-js/deduplicated',
			label: 'Deduplicated',
			description: 'Shared registration state',
			inputSchema: { type: 'object' },
			outputSchema: { type: 'object' },
			annotations: { readonly: true },
			callback: jest.fn(),
		};

		await firstBundle.registerClientAbility( definition );
		await secondBundle.registerClientAbility( definition );

		expect( registerAbility ).toHaveBeenCalledTimes( 1 );
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

	test( 'snapshotDescriptors falls back to locally registered descriptors when wp.abilities is unavailable', async () => {
		delete global.wp;
		const { registerClientAbility, snapshotDescriptors } = loadRegistry();

		await registerClientAbility( {
			name: 'sd-ai-agent-js/local-only',
			label: 'Local Only',
			description: 'Available via local fallback',
			inputSchema: { type: 'object' },
			outputSchema: { type: 'object' },
			annotations: { readonly: true },
			callback: jest.fn(),
		} );

		await expect( snapshotDescriptors() ).resolves.toEqual( [
			expect.objectContaining( {
				name: 'sd-ai-agent-js/local-only',
				label: 'Local Only',
				annotations: { readonly: true },
			} ),
		] );
	} );

	test( 'snapshotDescriptors falls back to local descriptors when wp store returns no client abilities', async () => {
		global.wp = {
			abilities: {
				executeAbility: jest.fn(),
				registerAbility: jest.fn().mockResolvedValue( undefined ),
				registerAbilityCategory: jest
					.fn()
					.mockResolvedValue( undefined ),
				getAbilities: jest.fn().mockReturnValue( [] ),
			},
		};
		const { registerClientAbility, snapshotDescriptors } = loadRegistry();

		await registerClientAbility( {
			name: 'sd-ai-agent-js/local-fallback',
			label: 'Local Fallback',
			description: 'Store was empty',
			inputSchema: { type: 'object' },
			outputSchema: { type: 'object' },
			annotations: { readonly: true },
			callback: jest.fn(),
		} );

		const descriptors = await snapshotDescriptors();
		expect( descriptors ).toEqual( [
			expect.objectContaining( {
				name: 'sd-ai-agent-js/local-fallback',
			} ),
		] );
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

	test( 'registers refresh-page without an empty required array', async () => {
		delete global.wp;
		const { refreshPage, registry } = loadRefreshPageAndRegistry();
		await refreshPage.registerRefreshPageAbility();
		const descriptor = ( await registry.snapshotDescriptors() ).find(
			( candidate ) => candidate.name === 'sd-ai-agent-js/refresh-page'
		);

		expect( descriptor.input_schema ).toMatchObject( {
			type: 'object',
			properties: {},
		} );
		expect( descriptor.input_schema ).not.toHaveProperty( 'required' );
	} );
} );
