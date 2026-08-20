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
 *   3. executeAbility navigate-to actually navigates
 *   4. executeAbility insert-block inserts on editor screen
 *   5. insert-block no-ops on non-editor screen
 *   6. snapshotDescriptors returns the expected list
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
 * Used by test.skip() to gracefully degrade when the CI environment runs a
 * WordPress version where @wordpress/core-abilities is not loaded (e.g.
 * the WP 7.0-branch build hasn't shipped the abilities script module yet,
 * or the module is registered but not enqueued by core).
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
 * Skip the current test if the abilities API is not available.
 *
 * Call this at the top of any test body that depends on wp.abilities.
 * It must run AFTER goToDashboard() or equivalent page navigation so the
 * scripts have loaded.
 *
 * @param {import('@playwright/test').Page} page
 */
async function skipIfNoAbilitiesApi( page ) {
	const available = await isAbilitiesApiAvailable( page );
	test.skip(
		! available,
		'wp.abilities API not available — @wordpress/core-abilities script module not loaded in this WP build'
	);
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

// ---------------------------------------------------------------------------
// Test suite 1: Category registration
// ---------------------------------------------------------------------------

test.describe( 'client-abilities — category registration', () => {
	test.beforeEach( async ( { page } ) => {
		await loginToWordPress( page );
		await goToDashboard( page );
		await skipIfNoAbilitiesApi( page );
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
			description: expect.stringContaining( 'client' ),
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
		await skipIfNoAbilitiesApi( page );
	} );

	test( 'navigate-to and insert-block appear in getAbilities()', async ( {
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

		const names = abilities.map( ( a ) => a.name );
		expect( names ).toContain( 'sd-ai-agent-js/navigate-to' );
		expect( names ).toContain( 'sd-ai-agent-js/insert-block' );

		// Verify expected schema shape for navigate-to.
		const navigateTo = abilities.find(
			( a ) => a.name === 'sd-ai-agent-js/navigate-to'
		);
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
		const insertBlock = abilities.find(
			( a ) => a.name === 'sd-ai-agent-js/insert-block'
		);
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
	} );
} );

// ---------------------------------------------------------------------------
// Test suite 3: navigate-to execution
// ---------------------------------------------------------------------------

test.describe( 'client-abilities — navigate-to execution', () => {
	test.beforeEach( async ( { page } ) => {
		await loginToWordPress( page );
		await goToDashboard( page );
		await skipIfNoAbilitiesApi( page );
	} );

	test( 'executeAbility navigate-to actually navigates to plugins.php', async ( {
		page,
	} ) => {
		await waitForAbilitiesRegistered( page );

		// Execute the ability and capture the return value before navigation
		// causes the page to unload. We use a Promise race with a short timeout
		// so we can capture the return value synchronously before the redirect.
		const result = await page.evaluate( async () => {
			if (
				typeof wp === 'undefined' ||
				! wp.abilities ||
				typeof wp.abilities.executeAbility !== 'function'
			) {
				return null;
			}
			try {
				// Override window.location.assign to capture the call without
				// actually navigating (which would unload the page and lose the result).
				let assignedUrl = null;
				const originalAssign = window.location.assign.bind(
					window.location
				);
				window.location.assign = ( url ) => {
					assignedUrl = url;
					// Don't call originalAssign — we don't want to navigate away
					// during the test assertion phase.
				};

				const ret = await wp.abilities.executeAbility(
					'sd-ai-agent-js/navigate-to',
					{ path: 'plugins.php' }
				);

				// Restore original assign.
				window.location.assign = originalAssign;

				return { result: ret, assignedUrl };
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
		// The assigned URL must end with /wp-admin/plugins.php.
		expect( result.assignedUrl ).toMatch( /\/wp-admin\/plugins\.php$/ );
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
		await skipIfNoAbilitiesApi( page );

		// Wait for the block editor to mount — the editor canvas is the signal.
		// 60 s accommodates slow CI runners where the block editor can take
		// 45-55 s to initialise (Gutenberg script modules + React hydration).
		await page
			.locator( '.block-editor-writing-flow, .editor-styles-wrapper' )
			.first()
			.waitFor( { state: 'visible', timeout: 60_000 } );

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

		// Assert the block actually appears in the editor DOM.
		// The block editor renders blocks inside .block-editor-block-list__layout.
		// A paragraph block renders a <p> with the content.
		await expect(
			page.locator(
				'.block-editor-block-list__layout [data-type="core/paragraph"]'
			)
		).toBeVisible( { timeout: 10_000 } );
	} );
} );

// ---------------------------------------------------------------------------
// Test suite 5: server-side post reflection in the block editor
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
					return (
						wp.blocks.serialize( blocks ) === serverMarkup &&
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
// Test suite 6: insert-block no-op on non-editor screen
// ---------------------------------------------------------------------------

test.describe( 'client-abilities — insert-block no-op on non-editor screen', () => {
	test.beforeEach( async ( { page } ) => {
		await loginToWordPress( page );
		await goToDashboard( page );
		await skipIfNoAbilitiesApi( page );
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
// Test suite 7: snapshotDescriptors
// ---------------------------------------------------------------------------

test.describe( 'client-abilities — snapshotDescriptors', () => {
	test.beforeEach( async ( { page } ) => {
		await loginToWordPress( page );
		await goToDashboard( page );
		await skipIfNoAbilitiesApi( page );
	} );

	test( 'snapshotDescriptors returns 2 descriptors with expected shape', async ( {
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

		// Must have exactly 2 descriptors.
		expect( descriptors ).toHaveLength( 2 );

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

		// Verify the two expected ability names are present.
		const names = descriptors.map( ( d ) => d.name );
		expect( names ).toContain( 'sd-ai-agent-js/navigate-to' );
		expect( names ).toContain( 'sd-ai-agent-js/insert-block' );
	} );
} );

// ---------------------------------------------------------------------------
// Test suite 8: No relevant console errors
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
		await skipIfNoAbilitiesApi( page );
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
		await skipIfNoAbilitiesApi( page );
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
		await skipIfNoAbilitiesApi( page );

		// The block editor is notoriously slow to initialise on CI runners,
		// especially on WP trunk where Gutenberg loads additional script
		// modules. 60 s accommodates the worst-case load time observed in
		// CI (45-55 s) with headroom. The previous 45 s timeout failed
		// consistently on both WP 6.9 and trunk CI matrices.
		await page
			.locator( '.block-editor-writing-flow, .editor-styles-wrapper' )
			.first()
			.waitFor( { state: 'visible', timeout: 60_000 } );
		await waitForAbilitiesRegistered( page );

		assertNoForbiddenErrors(
			[ ...consoleErrors, ...pageErrors ],
			'post-new.php'
		);
	} );
} );
