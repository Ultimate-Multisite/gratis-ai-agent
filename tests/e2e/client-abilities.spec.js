/**
 * E2E tests for client-side abilities (sd-ai-agent-js namespace).
 *
 * Exercises the real browser pipeline for the two client-side abilities:
 *   - sd-ai-agent-js/navigate-to
 *   - sd-ai-agent-js/insert-block
 *
 * These tests exist because the entire #806 → #815 → #821 → #822 chain
 * shipped, failed at runtime for three separate reasons, and each round of
 * fixes required a manual browser session to confirm. CI never caught any
 * of the failures because PHPUnit synthetically injects `client_abilities`
 * into `AgentLoop` options, bypassing the whole browser pipeline.
 *
 * Test coverage:
 *   1. registers on dashboard — category registered with correct label/description
 *   2. navigate-to and insert-block appear in getAbilities()
 *   3. executeAbility navigate-to queues the validated same-origin navigation
 *   4. executeAbility insert-block inserts on editor screen
 *   5. insert-block no-ops on non-editor screen
 *   6. snapshotDescriptors includes the expected descriptors
 *   7. no relevant console errors on any screen
 *
 * Run: pnpm run test:e2e:playwright -- --grep client-abilities
 *
 * Verification: temporarily comment out `await registerCategory()` in
 * src/abilities/index.js — test 1 must go red (category not found).
 * Temporarily comment out `await` on `registerAbilityCategory` in
 * registry.js — test 7 must go red (console error about non-existent category).
 */

const { test, expect } = require( '@playwright/test' );
const { loginToWordPress } = require( './utils/wp-admin' );

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Navigate to the WP admin dashboard and wait for the page to be ready.
 * The floating widget mounts here, which triggers ability registration.
 *
 * Uses a FAB element wait rather than networkidle. On loaded CI runners,
 * waitForLoadState('networkidle') consumed 60-70 s because the floating
 * widget makes several async API calls (providers, sessions, settings,
 * alerts) that keep network connections open. This exhausted most of the
 * 90 s test budget before waitForAbilitiesRegistered could run, causing
 * the outer test timeout to fire instead of the 15 s waitForFunction
 * timeout. Waiting for the FAB element is faster (~2-5 s) and more
 * meaningful: it guarantees the floating-widget React app has mounted
 * and ensureRegistered() has been called.
 *
 * @param {import('@playwright/test').Page} page
 */
async function goToDashboard( page ) {
	await page.goto( '/wp-admin/index.php' );
	await page.waitForLoadState( 'domcontentloaded' );
	// Wait for the launcher button — it renders once React has mounted and the
	// floating-widget bundle has executed (triggering ensureRegistered()).
	// The redesign (#1157) renamed .sdaa-fab to .sdaa-w-launcher.
	await page
		.locator( '.sdaa-w-launcher' )
		.waitFor( { state: 'visible', timeout: 30_000 } );
}

/**
 * Check whether the WP 7.0 abilities API is available on the current page.
 *
 * Returns true if wp.abilities exists and exposes the functions required
 * by the client-abilities tests. This check runs after the page has fully
 * loaded and the FAB is visible (ensureRegistered has been called), so a
 * false result reliably means the API is not available in this environment
 * — not that it hasn't loaded yet.
 *
 * Required coverage uses WordPress 7.0, which loads @wordpress/core-abilities.
 * Explicitly optional compatibility environments may opt into graceful skips.
 *
 * @param {import('@playwright/test').Page} page
 * @return {Promise<boolean>} True when all required wp.abilities methods exist.
 */
async function isAbilitiesApiAvailable( page ) {
	return page.evaluate( () => {
		return (
			typeof wp !== 'undefined' &&
			!! wp.abilities &&
			typeof wp.abilities.getAbilities === 'function' &&
			typeof wp.abilities.registerAbility === 'function' &&
			typeof wp.abilities.registerAbilityCategory === 'function'
		);
	} );
}

/**
 * Require the abilities API for the current test.
 *
 * Call this at the top of any test body that depends on wp.abilities. The
 * default E2E project is a required WordPress 7.0 coverage environment, so an
 * unavailable API must fail with an actionable error instead of silently
 * skipping the test. Compatibility jobs may opt into a graceful skip by
 * setting PLAYWRIGHT_ALLOW_MISSING_ABILITIES_API=1 explicitly.
 * It must run AFTER goToDashboard() or equivalent page navigation so the
 * scripts have loaded.
 *
 * @param {import('@playwright/test').Page} page
 */
async function requireAbilitiesApi( page ) {
	const available = await isAbilitiesApiAvailable( page );
	if (
		! available &&
		process.env.PLAYWRIGHT_ALLOW_MISSING_ABILITIES_API === '1'
	) {
		test.skip(
			true,
			'wp.abilities API not available in this explicitly optional compatibility environment'
		);
	}

	expect(
		available,
		'wp.abilities API is required for client-ability E2E coverage. Use a WordPress 7.0 runtime that loads @wordpress/core-abilities, or set PLAYWRIGHT_ALLOW_MISSING_ABILITIES_API=1 only for an explicitly optional compatibility environment.'
	).toBe( true );
}

/**
 * Wait for the sd-ai-agent-js abilities to be registered.
 *
 * Polls wp.abilities.getAbilities() until both abilities appear or the
 * timeout is reached. This is necessary because registration is async —
 * the category Promise must resolve before abilities can register.
 *
 * On slow CI runners, the @wordpress/core-abilities script module may load
 * well after the plugin's floating-widget bundle has called
 * ensureRegistered(). The source-side fix (registry.js waitForAbilitiesApi
 * increased to 30 s, index.js retry logic) handles the root cause. This
 * test-side timeout is set to 45 s (matching other long waits in this file)
 * to accommodate the full registration chain: 30 s API poll + category
 * registration + 2 ability registrations + React render time.
 *
 * The previous 15 s timeout was consistently too short on CI runners under
 * load — the abilities API loaded at ~12-18 s but registration added
 * another 2-5 s, pushing total time past the 15 s budget.
 *
 * Note: page.waitForFunction(fn, arg?, options?) — the second argument is
 * `arg` (data passed to the function), not the options object. Passing
 * `{ timeout }` as the second argument treats it as `arg` and uses the
 * default test timeout (90 s) instead. The correct call passes `null` for
 * `arg` and `{ timeout }` as the third argument.
 *
 * @param {import('@playwright/test').Page} page
 * @param {number}                          [timeout=45000] Max wait in ms.
 */
async function waitForAbilitiesRegistered( page, timeout = 45_000 ) {
	await page.waitForFunction(
		() => {
			if (
				typeof wp === 'undefined' ||
				! wp.abilities ||
				typeof wp.abilities.getAbilities !== 'function'
			) {
				return false;
			}
			// getAbilities() may return a Promise in WP 7.0 — handle both sync
			// and async shapes defensively. The polling loop will retry until
			// the Promise resolves with the expected abilities.
			try {
				const result = wp.abilities.getAbilities();
				if ( result && typeof result.then === 'function' ) {
					// Async path: can't await inside waitForFunction, so we
					// attach a side-effect that sets a flag when resolved.
					if ( ! window.__sdAbilitiesResolved ) {
						result.then( ( abilities ) => {
							window.__sdAbilitiesResolved = Array.isArray(
								abilities
							)
								? abilities.filter( ( a ) =>
										a?.name?.startsWith( 'sd-ai-agent-js/' )
								  ).length >= 2
								: false;
						} );
					}
					return !! window.__sdAbilitiesResolved;
				}
				// Sync path.
				const abilities = Array.isArray( result ) ? result : [];
				return (
					abilities.filter( ( a ) =>
						a?.name?.startsWith( 'sd-ai-agent-js/' )
					).length >= 2
				);
			} catch ( _e ) {
				return false;
			}
		},
		null,
		{ timeout }
	);
}

/**
 * Collect console errors and page errors during a test.
 *
 * @param {import('@playwright/test').Page} page
 * @return {{ consoleErrors: string[], pageErrors: string[] }} Captured errors.
 */
function collectErrors( page ) {
	const consoleErrors = [];
	const pageErrors = [];

	page.on( 'console', ( msg ) => {
		if ( msg.type() === 'error' ) {
			consoleErrors.push( msg.text() );
		}
	} );

	page.on( 'pageerror', ( err ) => {
		pageErrors.push( err.message );
	} );

	return { consoleErrors, pageErrors };
}

/**
 * Create a draft through the authenticated REST client and open it in Gutenberg.
 *
 * @param {import('@playwright/test').Page} page Browser page.
 * @return {Promise<number>} Draft post ID.
 */
async function createDraftAndOpenEditor( page ) {
	await page.goto( '/wp-admin/index.php' );
	await page.waitForLoadState( 'domcontentloaded' );
	const postId = await page.evaluate( async () => {
		const post = await wp.apiFetch( {
			path: '/wp/v2/posts',
			method: 'POST',
			data: {
				status: 'draft',
				title: 'SD AI Agent reflector test',
				content:
					'<!-- wp:paragraph -->\n<p>Initial Playwright paragraph.</p>\n<!-- /wp:paragraph -->',
			},
		} );
		return post.id;
	} );

	await page.goto( `/wp-admin/post.php?post=${ postId }&action=edit` );
	await page.waitForLoadState( 'domcontentloaded' );
	// The editor canvas can remain visually hidden while Gutenberg finishes
	// loading on shared CI runners. The reflection tests need its data stores,
	// not a visible canvas, so wait for those real runtime dependencies instead.
	await page.waitForFunction(
		() => {
			const editor = wp.data?.select?.( 'core/editor' );
			const blockEditor = wp.data?.select?.( 'core/block-editor' );
			return (
				typeof editor?.getCurrentPostId === 'function' &&
				typeof editor?.isEditedPostDirty === 'function' &&
				typeof blockEditor?.getBlocks === 'function' &&
				typeof window.sdAiAgentReflection?.emit === 'function'
			);
		},
		null,
		{ timeout: 90_000 }
	);

	return postId;
}

/**
 * Wait for the block editor data store rather than a canvas DOM selector.
 *
 * WordPress 7.0 renders the canvas in an iframe, and its first-run guide can
 * cover the canvas while the editor is fully initialized. The abilities under
 * test operate on the data store, so that store is the durable readiness signal.
 *
 * @param {import('@playwright/test').Page} page Browser page.
 */
async function waitForBlockEditorReady( page ) {
	await page.waitForFunction(
		() => {
			const blockEditor = wp.data?.select?.( 'core/block-editor' );
			return (
				typeof blockEditor?.getBlocks === 'function' &&
				typeof wp.blocks?.createBlock === 'function'
			);
		},
		null,
		{ timeout: 90_000 }
	);
}

// ---------------------------------------------------------------------------
// Test suite 1: Category registration
// ---------------------------------------------------------------------------

test.describe( 'client-abilities — category registration', () => {
	test.beforeEach( async ( { page } ) => {
		await loginToWordPress( page );
		await goToDashboard( page );
		await requireAbilitiesApi( page );
	} );

	test( 'registers on dashboard — category has expected label and description', async ( {
		page,
	} ) => {
		// Wait for the abilities to be registered before asserting.
		await waitForAbilitiesRegistered( page );

		const category = await page.evaluate( async () => {
			if (
				typeof wp === 'undefined' ||
				! wp.abilities ||
				typeof wp.abilities.getAbilityCategory !== 'function'
			) {
				return null;
			}
			try {
				return await wp.abilities.getAbilityCategory(
					'sd-ai-agent-js'
				);
			} catch ( _e ) {
				return null;
			}
		} );

		expect( category ).not.toBeNull();
		expect( category ).toMatchObject( {
			label: expect.stringContaining( 'SD AI Agent' ),
			description: expect.stringContaining( 'browser' ),
		} );
	} );
} );

// ---------------------------------------------------------------------------
// Test suite 2: Ability registration
// ---------------------------------------------------------------------------

test.describe( 'client-abilities — ability registration', () => {
	test.beforeEach( async ( { page } ) => {
		await loginToWordPress( page );
		await goToDashboard( page );
		await requireAbilitiesApi( page );
	} );

	test( 'required client abilities expose valid schemas and readonly annotations', async ( {
		page,
	} ) => {
		await waitForAbilitiesRegistered( page );

		const abilities = await page.evaluate( async () => {
			if (
				typeof wp === 'undefined' ||
				! wp.abilities ||
				typeof wp.abilities.getAbilities !== 'function'
			) {
				return [];
			}
			try {
				const all = await wp.abilities.getAbilities();
				return ( Array.isArray( all ) ? all : [] ).filter( ( a ) =>
					a?.name?.startsWith( 'sd-ai-agent-js/' )
				);
			} catch ( _e ) {
				return [];
			}
		} );

		const byName = new Map(
			abilities.map( ( ability ) => [ ability.name, ability ] )
		);
		for ( const name of [
			'sd-ai-agent-js/navigate-to',
			'sd-ai-agent-js/refresh-page',
			'sd-ai-agent-js/get-editor-selection',
			'sd-ai-agent-js/insert-block',
			'sd-ai-agent-js/get-editor-capabilities',
			'sd-ai-agent-js/capture-screenshot',
			'sd-ai-agent-js/screenshot-url',
		] ) {
			expect( byName.has( name ) ).toBe( true );
		}

		// Verify expected schema shape for navigate-to.
		const navigateTo = byName.get( 'sd-ai-agent-js/navigate-to' );
		expect( navigateTo ).toBeDefined();
		expect( navigateTo ).toMatchObject( {
			name: 'sd-ai-agent-js/navigate-to',
			label: expect.any( String ),
			description: expect.any( String ),
		} );
		// input_schema must have a `path` property.
		expect( navigateTo.input_schema ).toMatchObject( {
			type: 'object',
			properties: expect.objectContaining( {
				path: expect.objectContaining( { type: 'string' } ),
			} ),
		} );

		// Verify expected schema shape for insert-block.
		const insertBlock = byName.get( 'sd-ai-agent-js/insert-block' );
		expect( insertBlock ).toBeDefined();
		expect( insertBlock ).toMatchObject( {
			name: 'sd-ai-agent-js/insert-block',
			label: expect.any( String ),
			description: expect.any( String ),
		} );
		// input_schema must have a `blockName` property.
		expect( insertBlock.input_schema ).toMatchObject( {
			type: 'object',
			properties: expect.objectContaining( {
				blockName: expect.objectContaining( { type: 'string' } ),
			} ),
		} );
		expect( insertBlock.input_schema.required ).toEqual( [ 'blockName' ] );
		expect( insertBlock.meta.annotations ).toMatchObject( {
			readonly: false,
		} );

		for ( const name of [
			'sd-ai-agent-js/refresh-page',
			'sd-ai-agent-js/get-editor-selection',
			'sd-ai-agent-js/get-editor-capabilities',
			'sd-ai-agent-js/capture-screenshot',
		] ) {
			expect( byName.get( name ).input_schema ).not.toHaveProperty(
				'required'
			);
			expect( byName.get( name ).meta.annotations ).toMatchObject( {
				readonly: true,
			} );
		}
		expect(
			byName.get( 'sd-ai-agent-js/screenshot-url' ).input_schema.required
		).toEqual( [ 'url' ] );
	} );
} );

// ---------------------------------------------------------------------------
// Test suite 3: public schema validation
// ---------------------------------------------------------------------------

test.describe( 'client-abilities — public schema validation', () => {
	test.beforeEach( async ( { page } ) => {
		await loginToWordPress( page );
		await goToDashboard( page );
		await requireAbilitiesApi( page );
	} );

	test( 'executeAbility reaches get-editor-selection with empty input', async ( {
		page,
	} ) => {
		await waitForAbilitiesRegistered( page );

		const result = await page.evaluate( async () => {
			try {
				return await wp.abilities.executeAbility(
					'sd-ai-agent-js/get-editor-selection',
					{}
				);
			} catch ( err ) {
				return { error: err.message };
			}
		} );

		expect( result.error ).toBeUndefined();
		expect( result ).toMatchObject( {
			available: expect.any( Boolean ),
			selected: expect.any( Boolean ),
			count: expect.any( Number ),
		} );
	} );
} );

// ---------------------------------------------------------------------------
// Test suite 4: navigate-to execution
// ---------------------------------------------------------------------------

test.describe( 'client-abilities — navigate-to execution', () => {
	test.beforeEach( async ( { page } ) => {
		await loginToWordPress( page );
		await goToDashboard( page );
		await requireAbilitiesApi( page );
	} );

	test( 'executeAbility navigate-to queues plugins.php navigation', async ( {
		page,
	} ) => {
		await waitForAbilitiesRegistered( page );

		// Navigation is intentionally deferred until jobSlice has posted the tool
		// result, otherwise unloading the page can strand the server-side job.
		const result = await page.evaluate( async () => {
			if (
				typeof wp === 'undefined' ||
				! wp.abilities ||
				typeof wp.abilities.executeAbility !== 'function'
			) {
				return null;
			}
			try {
				const ret = await wp.abilities.executeAbility(
					'sd-ai-agent-js/navigate-to',
					{ path: 'plugins.php' }
				);
				const pendingNavigation = window._sdAiAgentPendingNavigation;
				delete window._sdAiAgentPendingNavigation;

				return { result: ret, pendingNavigation };
			} catch ( err ) {
				return { error: err.message };
			}
		} );

		expect( result ).not.toBeNull();
		expect( result.error ).toBeUndefined();
		expect( result.result ).toMatchObject( {
			navigated: true,
			path: 'plugins.php',
		} );
		// The queued URL must be the validated same-origin admin destination.
		expect( result.pendingNavigation ).toMatch(
			/\/wp-admin\/plugins\.php$/
		);
	} );
} );

// ---------------------------------------------------------------------------
// Test suite 4: insert-block execution on editor screen
// ---------------------------------------------------------------------------

test.describe( 'client-abilities — insert-block on editor screen', () => {
	test.beforeEach( async ( { page } ) => {
		await loginToWordPress( page );
	} );

	test( 'executeAbility insert-block inserts a paragraph on post-new.php', async ( {
		page,
	} ) => {
		// Navigate to the block editor.
		await page.goto( '/wp-admin/post-new.php' );
		await page.waitForLoadState( 'domcontentloaded' );

		// Check abilities API BEFORE the slow editor wait. On CI the block
		// editor can take 45-60 s to initialise — skipping early avoids a
		// 60 s timeout when the abilities API isn't available at all.
		await requireAbilitiesApi( page );

		await waitForBlockEditorReady( page );

		// Wait for abilities to register (the admin-page bundle also loads here).
		await waitForAbilitiesRegistered( page );

		const result = await page.evaluate( async () => {
			if (
				typeof wp === 'undefined' ||
				! wp.abilities ||
				typeof wp.abilities.executeAbility !== 'function'
			) {
				return null;
			}
			try {
				return await wp.abilities.executeAbility(
					'sd-ai-agent-js/insert-block',
					{
						blockName: 'core/paragraph',
						attributes: { content: 'hello from playwright' },
					}
				);
			} catch ( err ) {
				return { error: err.message };
			}
		} );

		expect( result ).not.toBeNull();
		expect( result.error ).toBeUndefined();
		expect( result ).toMatchObject( {
			inserted: true,
			blockName: 'core/paragraph',
		} );
		expect( typeof result.clientId ).toBe( 'string' );
		expect( result.clientId.length ).toBeGreaterThan( 0 );

		// Assert the block exists in the editor state. WP 7.0 renders the canvas
		// inside an iframe, while this ability deliberately targets the data store.
		const insertedBlock = await page.evaluate( ( clientId ) => {
			const block = wp.data
				.select( 'core/block-editor' )
				.getBlock( clientId );
			return block
				? { name: block.name, content: block.attributes?.content }
				: null;
		}, result.clientId );
		expect( insertedBlock ).toEqual( {
			name: 'core/paragraph',
			content: 'hello from playwright',
		} );
	} );
} );

test.describe( 'client-abilities — nested block insertion', () => {
	test.beforeEach( async ( { page } ) => {
		await loginToWordPress( page );
	} );

	test( 'nested block insertion preserves the Group parent and index after reload', async ( {
		page,
	} ) => {
		await createDraftAndOpenEditor( page );
		await requireAbilitiesApi( page );
		await waitForAbilitiesRegistered( page );

		const inserted = await page.evaluate( async () => {
			const blockEditor = wp.data.select( 'core/block-editor' );
			const blockDispatcher = wp.data.dispatch( 'core/block-editor' );
			const group = wp.blocks.createBlock( 'core/group', {}, [
				wp.blocks.createBlock( 'core/paragraph', {
					content: 'Existing Group child.',
				} ),
			] );
			const list = wp.blocks.createBlock( 'core/list', {}, [
				wp.blocks.createBlock( 'core/list-item', {
					content: 'Nested first item.',
				} ),
				wp.blocks.createBlock( 'core/list-item', {
					content: 'Nested second item.',
				} ),
			] );

			blockDispatcher.resetBlocks( [ group ] );
			const result = await wp.abilities.executeAbility(
				'sd-ai-agent-js/insert-block-markup',
				{
					markup: wp.blocks.serialize( [ list ] ),
					rootClientId: group.clientId,
					index: 1,
				}
			);
			const insertedGroup = blockEditor.getBlock( group.clientId );

			return {
				result,
				children: insertedGroup.innerBlocks.map( ( block ) => ( {
					name: block.name,
					children: block.innerBlocks.map( ( child ) => child.name ),
				} ) ),
				markup: wp.blocks.serialize( blockEditor.getBlocks() ),
			};
		} );

		expect( inserted.result ).toMatchObject( { applied: true } );
		expect( inserted.children ).toEqual( [
			{ name: 'core/paragraph', children: [] },
			{
				name: 'core/list',
				children: [ 'core/list-item', 'core/list-item' ],
			},
		] );
		expect( inserted.markup ).toContain( 'Nested first item.' );

		await page.evaluate( async () => {
			await wp.data.dispatch( 'core/editor' ).savePost();
		} );
		await page.reload();
		await page.waitForLoadState( 'domcontentloaded' );
		await page.waitForFunction(
			() =>
				typeof wp?.data?.select?.( 'core/block-editor' )?.getBlocks ===
				'function',
			null,
			{ timeout: 60_000 }
		);

		const reloaded = await page.evaluate( () => {
			const group = wp
				.data.select( 'core/block-editor' )
				.getBlocks()
				.find( ( block ) => block.name === 'core/group' );
			return {
				children: group.innerBlocks.map( ( block ) => ( {
					name: block.name,
					children: block.innerBlocks.map( ( child ) => child.name ),
				} ) ),
				markup: wp.blocks.serialize(
					wp.data.select( 'core/block-editor' ).getBlocks()
				),
			};
		} );

		expect( reloaded.children ).toEqual( inserted.children );
		expect( reloaded.markup ).toContain( 'Nested second item.' );
	} );
} );

// ---------------------------------------------------------------------------
// Test suite 5: editor history execution
// ---------------------------------------------------------------------------

test.describe( 'client-abilities — editor history', () => {
	test.beforeEach( async ( { page } ) => {
		await loginToWordPress( page );
	} );

	test( 'reports settled editor history undo and redo evidence', async ( {
		page,
	} ) => {
		await createDraftAndOpenEditor( page );
		await requireAbilitiesApi( page );
		await waitForAbilitiesRegistered( page );

		const result = await page.evaluate( async () => {
			const blockEditor = wp.data.select( 'core/block-editor' );
			const blockDispatcher = wp.data.dispatch( 'core/block-editor' );
			const initialBlocks = blockEditor.getBlocks();
			const selected = initialBlocks.find(
				( block ) => block.name === 'core/paragraph'
			);
			if ( ! selected ) {
				return { error: 'paragraph_unavailable' };
			}

			const original = wp.blocks.serialize( initialBlocks );
			const replacement = wp.blocks.createBlock( 'core/paragraph', {
				content: 'History replacement from Playwright.',
			} );
			blockDispatcher.selectBlock( selected.clientId );
			blockDispatcher.replaceBlocks( [ selected.clientId ], replacement );
			const replacementMarkup = wp.blocks.serialize(
				blockEditor.getBlocks()
			);
			const undo = await wp.abilities.executeAbility(
				'sd-ai-agent-js/change-editor-history',
				{ direction: 'undo' }
			);
			const afterUndo = wp.blocks.serialize( blockEditor.getBlocks() );
			const redo = await wp.abilities.executeAbility(
				'sd-ai-agent-js/change-editor-history',
				{ direction: 'redo' }
			);
			const afterRedo = wp.blocks.serialize( blockEditor.getBlocks() );

			return {
				original,
				replacementMarkup,
				undo,
				afterUndo,
				redo,
				afterRedo,
			};
		} );

		expect( result.error ).toBeUndefined();
		expect( result.replacementMarkup ).not.toBe( result.original );
		expect( result.undo ).toMatchObject( {
			applied: true,
			direction: 'undo',
		} );
		expect( result.afterUndo ).toBe( result.original );
		expect( result.redo ).toMatchObject( {
			applied: true,
			direction: 'redo',
		} );
		expect( result.afterRedo ).toBe( result.replacementMarkup );
	} );
} );

// ---------------------------------------------------------------------------
// Test suite 6: server-side post reflection in the block editor
// ---------------------------------------------------------------------------

test.describe( 'client-abilities — server post reflection', () => {
	test.beforeEach( async ( { page } ) => {
		await loginToWordPress( page );
	} );

	test( 'synchronizes a server mutation into a clean editor without making it dirty', async ( {
		page,
	} ) => {
		const postId = await createDraftAndOpenEditor( page );

		const markup =
			'<!-- wp:paragraph -->\n<p>Reflected server revision.</p>\n<!-- /wp:paragraph -->';
		await page.evaluate(
			async ( { serverMarkup, currentPostId } ) => {
				await wp.apiFetch( {
					path: `/wp/v2/posts/${ currentPostId }`,
					method: 'POST',
					data: { content: serverMarkup },
				} );
				window.sdAiAgentReflection.emit( {
					type: 'tool-applied',
					tool: 'sd-ai-agent/update-post',
					affected: {
						kind: 'post',
						post_id: currentPostId,
						fields: [ 'post_content' ],
					},
				} );
			},
			{ serverMarkup: markup, currentPostId: postId }
		);

		await page.waitForFunction(
			( serverMarkup ) => {
				const editor = wp.data.select( 'core/editor' );
				const blocks = wp.data
					.select( 'core/block-editor' )
					.getBlocks();
				return (
					wp.blocks.serialize( blocks ) === serverMarkup &&
					editor.isEditedPostDirty() === false
				);
			},
			markup,
			{ timeout: 15_000 }
		);
	} );

	test( 'keeps successive server mutations reflected and the editor clean', async ( {
		page,
	} ) => {
		const postId = await createDraftAndOpenEditor( page );
		const revisions = [
			{
				tool: 'sd-ai-agent/update-post',
				markup:
					'<!-- wp:heading -->\n<h2>Updated heading.</h2>\n<!-- /wp:heading -->',
			},
			{
				tool: 'sd-ai-agent/edit-block-tree',
				markup:
					'<!-- wp:list -->\n<ul class="wp-block-list"><li>Updated nested item.</li></ul>\n<!-- /wp:list -->',
			},
			{
				tool: 'sd-ai-agent/append-post-content',
				markup:
					'<!-- wp:paragraph -->\n<p>Appended server paragraph.</p>\n<!-- /wp:paragraph -->',
			},
		];

		for ( const revision of revisions ) {
			await page.evaluate(
				async ( { currentPostId, serverMarkup, tool } ) => {
					await wp.apiFetch( {
						path: `/wp/v2/posts/${ currentPostId }`,
						method: 'POST',
						data: { content: serverMarkup },
					} );
					window.sdAiAgentReflection.emit( {
						type: 'tool-applied',
						tool,
						affected: {
							kind: 'post',
							post_id: currentPostId,
							fields: [ 'post_content' ],
						},
					} );
				},
				{
					currentPostId: postId,
					serverMarkup: revision.markup,
					tool: revision.tool,
				}
			);

			await page.waitForFunction(
				( serverMarkup ) => {
					const editor = wp.data.select( 'core/editor' );
					const blocks = wp.data
						.select( 'core/block-editor' )
						.getBlocks();
					const normalizedMarkup = wp.blocks.serialize(
						wp.blocks.parse( serverMarkup )
					);
					return (
						wp.blocks.serialize( blocks ) === normalizedMarkup &&
						editor.isEditedPostDirty() === false
					);
				},
				revision.markup,
				{ timeout: 15_000 }
			);
		}
	} );

	test( 'preserves a dirty local editor after a server mutation event', async ( {
		page,
	} ) => {
		const postId = await createDraftAndOpenEditor( page );

		const localMarkup =
			'<!-- wp:paragraph -->\n<p>Local unsaved revision.</p>\n<!-- /wp:paragraph -->';
		const serverMarkup =
			'<!-- wp:paragraph -->\n<p>Server revision.</p>\n<!-- /wp:paragraph -->';
		await page.evaluate(
			async ( { localContent, serverContent, currentPostId } ) => {
				wp.data.dispatch( 'core/editor' ).editPost( {
					content: localContent,
				} );
				await wp.apiFetch( {
					path: `/wp/v2/posts/${ currentPostId }`,
					method: 'POST',
					data: { content: serverContent },
				} );
				window.sdAiAgentReflection.emit( {
					type: 'tool-applied',
					tool: 'sd-ai-agent/edit-block-tree',
					affected: {
						kind: 'post',
						post_id: currentPostId,
						fields: [ 'post_content' ],
					},
				} );
			},
			{
				localContent: localMarkup,
				serverContent: serverMarkup,
				currentPostId: postId,
			}
		);

		await page.waitForTimeout( 500 );
		const state = await page.evaluate( () => ( {
			content: wp.blocks.serialize(
				wp.data.select( 'core/block-editor' ).getBlocks()
			),
			dirty: wp.data.select( 'core/editor' ).isEditedPostDirty(),
		} ) );

		expect( state.content ).toBe( localMarkup );
		expect( state.dirty ).toBe( true );
	} );
} );

// ---------------------------------------------------------------------------
// Test suite 7: insert-block no-op on non-editor screen
// ---------------------------------------------------------------------------

test.describe( 'client-abilities — insert-block no-op on non-editor screen', () => {
	test.beforeEach( async ( { page } ) => {
		await loginToWordPress( page );
		await goToDashboard( page );
		await requireAbilitiesApi( page );
	} );

	test( 'insert-block returns inserted:false on dashboard without throwing', async ( {
		page,
	} ) => {
		await waitForAbilitiesRegistered( page );

		const result = await page.evaluate( async () => {
			if (
				typeof wp === 'undefined' ||
				! wp.abilities ||
				typeof wp.abilities.executeAbility !== 'function'
			) {
				return null;
			}
			try {
				return await wp.abilities.executeAbility(
					'sd-ai-agent-js/insert-block',
					{ blockName: 'core/paragraph' }
				);
			} catch ( err ) {
				return { error: err.message };
			}
		} );

		expect( result ).not.toBeNull();
		expect( result.error ).toBeUndefined();
		// On a non-editor screen, insert-block must return inserted: false.
		expect( result ).toMatchObject( {
			inserted: false,
			blockName: 'core/paragraph',
		} );
	} );
} );

// ---------------------------------------------------------------------------
// Test suite 8: snapshotDescriptors
// ---------------------------------------------------------------------------

test.describe( 'client-abilities — snapshotDescriptors', () => {
	test.beforeEach( async ( { page } ) => {
		await loginToWordPress( page );
		await goToDashboard( page );
		await requireAbilitiesApi( page );
	} );

	test( 'snapshotDescriptors includes required descriptors with expected shape', async ( {
		page,
	} ) => {
		await waitForAbilitiesRegistered( page );

		// Evaluate snapshotDescriptors via the built bundle's exposed global,
		// or inline a mirror of the function using wp.abilities.getAbilities().
		const descriptors = await page.evaluate( async () => {
			if (
				typeof wp === 'undefined' ||
				! wp.abilities ||
				typeof wp.abilities.getAbilities !== 'function'
			) {
				return [];
			}
			try {
				const allAbilities =
					( await wp.abilities.getAbilities() ) || [];
				return allAbilities
					.filter(
						( ability ) =>
							ability &&
							ability.name &&
							ability.name.startsWith( 'sd-ai-agent-js/' )
					)
					.map( ( ability ) => ( {
						name: ability.name,
						label: ability.label || ability.name,
						description: ability.description || '',
						input_schema: ability.input_schema || {},
						output_schema: ability.output_schema || {},
						annotations: ability.meta?.annotations || {},
					} ) );
			} catch ( _e ) {
				return [];
			}
		} );

		// Additional client abilities may be registered without breaking this
		// coverage, but each expected editor ability must remain discoverable.
		const names = descriptors.map( ( descriptor ) => descriptor.name );
		for ( const name of [
			'sd-ai-agent-js/navigate-to',
			'sd-ai-agent-js/refresh-page',
			'sd-ai-agent-js/get-editor-selection',
			'sd-ai-agent-js/insert-block',
			'sd-ai-agent-js/get-editor-capabilities',
			'sd-ai-agent-js/capture-screenshot',
			'sd-ai-agent-js/screenshot-url',
			'sd-ai-agent-js/replace-editor-selection',
			'sd-ai-agent-js/insert-block-markup',
			'sd-ai-agent-js/change-editor-history',
			'sd-ai-agent-js/get-canonical-block-examples',
			'sd-ai-agent-js/validate-page-quality',
		] ) {
			expect( names ).toContain( name );
		}

		// Each descriptor must have the expected shape.
		for ( const descriptor of descriptors ) {
			expect( descriptor ).toMatchObject( {
				name: expect.stringMatching( /^sd-ai-agent-js\// ),
				label: expect.any( String ),
				description: expect.any( String ),
				input_schema: expect.any( Object ),
				output_schema: expect.any( Object ),
				annotations: expect.any( Object ),
			} );
			expect( descriptor.name.length ).toBeGreaterThan( 0 );
			expect( descriptor.label.length ).toBeGreaterThan( 0 );
			expect( descriptor.description.length ).toBeGreaterThan( 0 );
		}
	} );
} );

// ---------------------------------------------------------------------------
// Test suite 9: No relevant console errors
// ---------------------------------------------------------------------------

test.describe( 'client-abilities — no relevant console errors', () => {
	/**
	 * Error strings that indicate a broken abilities registration pipeline.
	 * These are the exact strings from the bug history (#806 → #815 → #821 → t166).
	 */
	const FORBIDDEN_ERROR_PATTERNS = [
		'Ability name is required',
		'must contain a `description` string',
		'references non-existent category',
		'Category not found: sd-ai-agent-js',
		'Failed to resolve module specifier "@wordpress/abilities"',
	];

	/**
	 * Assert that none of the collected errors match the forbidden patterns.
	 *
	 * @param {string[]} errors Array of error message strings.
	 * @param {string}   screen Screen name for error messages.
	 */
	function assertNoForbiddenErrors( errors, screen ) {
		for ( const error of errors ) {
			for ( const pattern of FORBIDDEN_ERROR_PATTERNS ) {
				expect(
					error,
					`Forbidden error on ${ screen }: "${ pattern }"`
				).not.toContain( pattern );
			}
		}
	}

	test( 'no relevant console errors on dashboard', async ( { page } ) => {
		const { consoleErrors, pageErrors } = collectErrors( page );

		await loginToWordPress( page );
		await goToDashboard( page );
		await requireAbilitiesApi( page );
		await waitForAbilitiesRegistered( page );

		assertNoForbiddenErrors(
			[ ...consoleErrors, ...pageErrors ],
			'dashboard'
		);
	} );

	test( 'no relevant console errors on admin page', async ( { page } ) => {
		const { consoleErrors, pageErrors } = collectErrors( page );

		await loginToWordPress( page );
		await page.goto( '/wp-admin/admin.php?page=sd-ai-agent' );
		await page.waitForLoadState( 'domcontentloaded' );
		await page
			.locator( '.sdaa-unified-admin' )
			.waitFor( { state: 'visible', timeout: 45_000 } );
		await requireAbilitiesApi( page );
		await waitForAbilitiesRegistered( page );

		assertNoForbiddenErrors(
			[ ...consoleErrors, ...pageErrors ],
			'admin page'
		);
	} );

	test( 'no relevant console errors on post-new.php', async ( { page } ) => {
		const { consoleErrors, pageErrors } = collectErrors( page );

		await loginToWordPress( page );
		await page.goto( '/wp-admin/post-new.php' );
		await page.waitForLoadState( 'domcontentloaded' );

		// Check abilities API BEFORE the slow editor wait. On CI the block
		// editor can take 45-60 s to initialise — skipping early avoids a
		// 60 s timeout when the abilities API isn't available at all.
		await requireAbilitiesApi( page );

		await waitForBlockEditorReady( page );
		await waitForAbilitiesRegistered( page );

		assertNoForbiddenErrors(
			[ ...consoleErrors, ...pageErrors ],
			'post-new.php'
		);
	} );
} );
