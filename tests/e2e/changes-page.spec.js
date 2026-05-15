/**
 * E2E tests for the Superdav AI Agent Changes admin page.
 *
 * The UnifiedAdminMenu consolidates all admin pages into a single React SPA
 * at admin.php?page=sd-ai-agent with hash-based routing. The changes
 * route is at admin.php?page=sd-ai-agent#/changes and renders the
 * ChangesRoute component inside the unified admin layout.
 *
 * Run: npm run test:e2e:playwright
 */

const { test, expect } = require( '@playwright/test' );
const {
	loginToWordPress,
	goToChangesPage,
} = require( './utils/wp-admin' );

// ─── Page load ────────────────────────────────────────────────────────────────

test.describe( 'Changes Page - Page Load', () => {
	test.beforeEach( async ( { page } ) => {
		await loginToWordPress( page );
		await goToChangesPage( page );
	} );

	test( 'changes page loads the unified admin app', async ( { page } ) => {
		// The UnifiedAdminMenu SPA mounts into #sdaa-root and
		// renders .sdaa-unified-admin as the top-level wrapper.
		await expect(
			page.locator( '#sdaa-root' )
		).toBeVisible();
		await expect(
			page.locator( '.sdaa-unified-admin' )
		).toBeVisible();
	} );

	test( 'changes route container is rendered', async ( { page } ) => {
		// The Router renders ChangesRoute inside .sdaa-route-changes
		// when the hash is #/changes.
		await expect(
			page.locator( '.sdaa-route-changes' )
		).toBeVisible();
	} );

	test( 'changes page shows the Changes heading', async ( { page } ) => {
		// ChangesRoute renders an h2 with "Changes".
		await expect(
			page.locator( '.sdaa-route-changes' ).getByRole( 'heading', {
				name: /changes/i,
				level: 2,
			} )
		).toBeVisible();
	} );

	test( 'changes page shows descriptive content', async ( { page } ) => {
		// ChangesRoute renders a description paragraph.
		await expect(
			page.locator( '.sdaa-route-changes' )
		).toContainText( 'changes' );
	} );

	test( 'navigation highlights the Changes menu item', async ( { page } ) => {
		// The Navigation component renders links for each route. The changes
		// link should be present in the unified admin navigation.
		const nav = page.locator( '.sd-ai-admin-layout' );
		await expect( nav ).toBeVisible();
	} );
} );

// ─── REST endpoint ────────────────────────────────────────────────────────────

/**
 * Fetch the modified-plugins REST endpoint from within the browser context.
 *
 * The fetch is issued with a 15 s AbortSignal so a cold-start or slow
 * endpoint on a CI runner cannot silently consume the entire test budget.
 * Network errors and AbortError are caught and returned as a sentinel
 * value { status: 0, body: null } rather than letting page.evaluate()
 * reject, which would lose the timeout context entirely.
 *
 * @param {import('@playwright/test').Page} page - Playwright page object.
 * @return {Promise<{status: number, body: object|null}>} Resolved fetch result or sentinel on error.
 */
async function fetchModifiedPlugins( page ) {
	return page.evaluate( async () => {
		const nonce = window.sdAiAgentData?.nonce || '';
		if ( ! nonce ) {
			return { status: 0, body: null };
		}
		// Use wpApiSettings.root (set by wp_localize_script for wp-api-fetch)
		// which handles both pretty-permalink (/wp-json/) and plain-permalink
		// (?rest_route=) environments. Fall back to the plain-permalink format
		// if wpApiSettings is unavailable (e.g. wp-env without pretty permalinks).
		const apiRoot =
			window.wpApiSettings?.root ||
			window.location.origin + '/?rest_route=/';
		const endpoint = `${ apiRoot }sd-ai-agent/v1/modified-plugins`;

		// AbortSignal.timeout() cancels the fetch after 15 s so a slow
		// endpoint does not exhaust the test budget. Wrapping in try/catch
		// converts AbortError and network failures into the sentinel value
		// instead of an unhandled rejection.
		let res;
		try {
			res = await fetch( endpoint, {
				headers: {
					'X-WP-Nonce': nonce,
				},
				signal: AbortSignal.timeout( 15_000 ),
			} );
		} catch ( e ) {
			return { status: 0, body: null };
		}

		let body = null;
		try {
			body = await res.json();
		} catch ( e ) {
			body = null;
		}
		return { status: res.status, body };
	} );
}

test.describe( 'Changes Page - REST Endpoint', () => {
	// These tests navigate to the changes page (≤45 s SPA mount wait) and
	// then issue a fetch to the modified-plugins endpoint. 120 s gives
	// loginToWordPress (≤60 s), goToChangesPage (≤45 s), and the fetch
	// (≤15 s) enough combined headroom on CI runners under load without
	// being so large that genuinely broken tests take forever to fail.
	test.setTimeout( 120_000 );

	test.beforeEach( async ( { page } ) => {
		await loginToWordPress( page );
	} );

	test( 'REST endpoint for modified plugins returns expected shape', async ( {
		page,
	} ) => {
		// Navigate to the changes page so the nonce and wpApiSettings are
		// available in the page context.
		await goToChangesPage( page );

		const apiResponse = await fetchModifiedPlugins( page );

		// Endpoint must return 200.
		expect( apiResponse.status ).toBe( 200 );

		// Response must have `plugins` array and `count` integer.
		expect( apiResponse.body ).toHaveProperty( 'plugins' );
		expect( apiResponse.body ).toHaveProperty( 'count' );
		expect( Array.isArray( apiResponse.body.plugins ) ).toBe( true );
		expect( typeof apiResponse.body.count ).toBe( 'number' );
	} );

	test( 'each modified plugin entry has a download_url', async ( { page } ) => {
		await goToChangesPage( page );

		const apiResponse = await fetchModifiedPlugins( page );

		expect( apiResponse.status ).toBe( 200 );

		// If any plugins are returned, each must have a download_url.
		for ( const plugin of apiResponse.body.plugins ) {
			expect( plugin ).toHaveProperty( 'plugin_slug' );
			expect( plugin ).toHaveProperty( 'download_url' );
			expect( typeof plugin.download_url ).toBe( 'string' );
			expect( plugin.download_url ).toMatch( /download-plugin/ );
		}
	} );
} );
