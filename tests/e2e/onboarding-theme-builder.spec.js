/**
 * E2E spec: theme-builder onboarding flow (Onboarding v2)
 *
 * Drives the AI-driven onboarding "Design a custom theme" path end-to-end:
 *
 *  1. With a provider configured, onboarding_complete=false, and the site
 *     reporting an empty published-content set, AdminPageApp mounts
 *     OnboardingThemeBuilder directly (no wizard, no mode-picker).
 *  2. The theme-builder agent opens with at least one chat message visible
 *     (the auto-sent kickoff).
 *  3. A deterministic build instruction receives a "DONE" reply.
 *  4. Theme files exist on disk and the theme is the active stylesheet.
 *
 * Uses test.describe.serial because all steps share browser state (the chat
 * session opened in step 1 is reused in steps 2–4).
 *
 * Stable mocks used:
 *  - GET /wp/v2/posts (empty-content heuristic probe) → [] so the gate routes
 *    to OnboardingThemeBuilder regardless of any CI posts already present.
 *
 * The agent's job flow (POST /run → job polling → DONE reply, scaffold-block-theme
 * and activate-theme abilities) uses the real backend with generous timeouts.
 *
 * Replaces the legacy wizard-driven flow that was removed in the Onboarding v2
 * cleanup (see todo/PLANS.md "Onboarding v2: Gate + AI-Driven Discovery").
 *
 * Closes #1385.   Ref #1373.
 *
 * Run: npm run test:e2e:playwright -- tests/e2e/onboarding-theme-builder.spec.js
 */

'use strict';

const { test, expect } = require( '@playwright/test' );
const path = require( 'path' );
const fs = require( 'fs' );
const { execSync } = require( 'child_process' );
const {
	loginToWordPress,
	getMessageInput,
	getSendButton,
	getChatPanel,
} = require( './utils/wp-admin' );

// ---------------------------------------------------------------------------
// WP-CLI helper
// ---------------------------------------------------------------------------

/**
 * Run a WP-CLI command inside the wp-env container.
 *
 * Selects `cli` (dev, port 8890) or `tests-cli` (test, port 8893) based on
 * WP_BASE_URL so the call targets the same WordPress instance that Playwright
 * is driving.
 *
 * WP_ENV_HOME detection order:
 *   1. WP_ENV_HOME env var (set explicitly by the caller or by the shell).
 *   2. /tmp/wp-env — the path used by the GitHub Actions E2E workflow when
 *      `npm run wp-env:start` is executed with `WP_ENV_HOME: /tmp/wp-env`.
 *      The directory is present for the lifetime of the CI job.
 *   3. Undefined (falls back to @wordpress/env's default ~/.wp-env).
 *
 * Always returns a string. Errors are swallowed so cleanup failures are
 * non-fatal — the spec uses overwrite=true in the build instruction to handle
 * any leftover test-theme directory from a previous run.
 *
 * @param {string} command - WP-CLI command WITHOUT the `wp` prefix.
 * @return {string} Trimmed stdout, or '' on error.
 */
function wpCli( command ) {
	const baseUrl = process.env.WP_BASE_URL || 'http://localhost:8890';
	const service = baseUrl.includes( ':8893' ) ? 'tests-cli' : 'cli';
	const cwd = path.resolve( __dirname, '../..' );

	// Determine WP_ENV_HOME: explicit env var → CI fallback → undefined.
	const wpEnvHome =
		process.env.WP_ENV_HOME ||
		( fs.existsSync( '/tmp/wp-env' ) ? '/tmp/wp-env' : undefined );

	const env = { ...process.env };
	if ( wpEnvHome ) {
		env.WP_ENV_HOME = wpEnvHome;
	}

	try {
		return execSync( `npx wp-env run ${ service } wp ${ command }`, {
			cwd,
			encoding: 'utf8',
			env,
			stdio: [ 'pipe', 'pipe', 'pipe' ],
		} ).trim();
	} catch {
		// Non-fatal: log nothing to avoid noise in CI logs.
		return '';
	}
}

/**
 * Recursively delete a directory inside wp-content via WP-CLI eval.
 *
 * @param {string} wpContentRelPath - Path relative to WP_CONTENT_DIR,
 *                                  e.g. '/themes/e2e-test-theme'.
 */
function wpCliRmdir( wpContentRelPath ) {
	// Escape the path for safe inclusion in a double-quoted PHP string.
	const escaped = wpContentRelPath.replace( /'/g, "\\'" );
	wpCli(
		`eval "` +
			`$dir = WP_CONTENT_DIR . '${ escaped }'; ` +
			`if ( ! is_dir( $dir ) ) { echo 'not_found'; return; } ` +
			`$it = new RecursiveIteratorIterator( ` +
			`new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), ` +
			`RecursiveIteratorIterator::CHILD_FIRST ); ` +
			`foreach ( $it as $f ) { $f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() ); } ` +
			`rmdir( $dir ); ` +
			`echo 'deleted';` +
			`"`
	);
}

// ---------------------------------------------------------------------------
// Local navigation helpers
// ---------------------------------------------------------------------------

/**
 * Navigate to the admin agent page and wait for the chat UI to render.
 *
 * AdminPageApp probes /wp/v2/posts once, then mounts OnboardingThemeBuilder
 * (empty install) → ChatRedesign. We wait for .sdaa-cr (the redesign root)
 * which is the synchronous render output of OnboardingThemeBuilder.
 *
 * @param {import('@playwright/test').Page} page - Playwright page.
 */
async function goToAgentPageForOnboarding( page ) {
	await page.goto( '/wp-admin/admin.php?page=sd-ai-agent' );
	await page.waitForLoadState( 'domcontentloaded' );
	// Allow up to 45 s — AdminPageApp fetches settings/providers and the
	// SPA bundle itself takes time on CI runners.
	await page
		.locator( '.sdaa-cr' )
		.waitFor( { state: 'visible', timeout: 45_000 } );
}

// ---------------------------------------------------------------------------
// Serial describe block
// ---------------------------------------------------------------------------

test.describe.serial( 'Theme-builder onboarding flow (Onboarding v2)', () => {
	/**
	 * Shared browser page — all tests in this serial suite reuse the same
	 * page so the browser session (cookies, React state) persists across steps.
	 *
	 * @type {import('@playwright/test').Page}
	 */
	let page;

	/**
	 * The active theme that was installed before this spec ran.
	 * Restored in afterAll so other spec files are not affected.
	 */
	let previousTheme = 'twentytwentyfive';

	// ── Setup / teardown ──────────────────────────────────────────────────

	test.beforeAll( async ( { browser } ) => {
		// 1. Record the currently-active theme before touching anything.
		previousTheme =
			wpCli( 'option get stylesheet' ).replace( /\s+/g, '' ) ||
			'twentytwentyfive';

		// 2. Create the shared page. browser.newPage() inherits baseURL from
		//    playwright.config.js so relative paths work in loginToWordPress().
		page = await browser.newPage();

		// 3. Login and get a valid nonce for REST calls.
		await loginToWordPress( page );
		await page.goto( '/wp-admin/index.php' );
		await page.waitForLoadState( 'networkidle' );

		// Wait for wpApiSettings (injected by WordPress into the page head).
		await page
			.waitForFunction(
				() =>
					typeof window.wpApiSettings !== 'undefined' &&
					!! window.wpApiSettings.root,
				{ timeout: 15_000 }
			)
			.catch( () => {} );

		// 4. Reset onboarding state via REST so AdminPageApp routes to the
		//    bootstrapper:
		//    a) POST /onboarding/rescan clears OnboardingManager's WP options
		//       (COMPLETE_OPTION and BOOTSTRAP_SESSION_OPTION).
		//    b) POST /settings sets onboarding_complete=false in the Settings
		//       store so AdminPageApp's gate evaluates to "bootstrapper needed".
		await page.evaluate( async () => {
			const root =
				( window.wpApiSettings && window.wpApiSettings.root ) ||
				'/wp-json/';
			const nonce =
				( window.wpApiSettings && window.wpApiSettings.nonce ) || '';
			await fetch( root + 'sd-ai-agent/v1/onboarding/rescan', {
				method: 'POST',
				headers: { 'X-WP-Nonce': nonce },
			} ).catch( () => {} );
			await fetch( root + 'sd-ai-agent/v1/settings', {
				method: 'POST',
				headers: {
					'X-WP-Nonce': nonce,
					'Content-Type': 'application/json',
				},
				body: JSON.stringify( { onboarding_complete: false } ),
			} ).catch( () => {} );
		} );

		// 5. Clear any stale theme-builder session so theme-builder-start
		//    creates a fresh one (idempotent endpoint reuses the stored ID
		//    otherwise, which may point to a closed session).
		wpCli( 'option delete sd_ai_agent_theme_builder_session_id' );

		// 6. Delete any leftover e2e-test-theme directory from a prior run.
		//    Non-fatal: the build instruction uses overwrite=true as a safety net.
		wpCliRmdir( '/themes/e2e-test-theme' );

		// 7. Mock the empty-content heuristic probe so the gate routes to
		//    OnboardingThemeBuilder regardless of any CI posts already present.
		//    The probe fires once, on first render after settings load.
		await page.route(
			( url ) => {
				const decoded = decodeURIComponent( url.toString() );
				return (
					decoded.includes( '/wp/v2/posts' ) &&
					decoded.includes( 'per_page=1' ) &&
					decoded.includes( 'status=publish' )
				);
			},
			async ( route ) => {
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( [] ),
				} );
			}
		);
	} );

	test.afterAll( async () => {
		// Restore the previously-active theme.
		wpCli( `eval "switch_theme( '${ previousTheme }' );"` );

		// Remove the test theme directory created during the test.
		wpCliRmdir( '/themes/e2e-test-theme' );

		await page?.close();
	} );

	// ── Test 1: ChatRedesign mounts via the empty-install route ───────────

	test( 'fresh install with no published content mounts ChatRedesign directly via OnboardingThemeBuilder', async () => {
		// Navigate to the agent page — AdminPageApp should:
		//   1. Load settings → see onboarding_complete=false.
		//   2. Fire the /wp/v2/posts probe → mocked to [].
		//   3. Mount OnboardingThemeBuilder → renders ChatRedesign (.sdaa-cr).
		await goToAgentPageForOnboarding( page );

		// The chat shell must be visible — no wizard, no mode-picker.
		await expect( getChatPanel( page ) ).toBeVisible();
	} );

	// ── Test 2: greeting visible ──────────────────────────────────────────

	test( 'theme-builder chat session opens and the kickoff message is visible', async () => {
		// OnboardingThemeBuilder auto-sends a kickoff message (sendMessage()).
		// The user message row is appended synchronously before any REST call,
		// so it is a reliable early-readiness signal.
		// Allow 45 s for the session POST + /run POST round-trip on CI runners.
		await page
			.locator( '.sdaa-cr .sdaa-cr-msg-row' )
			.first()
			.waitFor( { state: 'visible', timeout: 45_000 } );
	} );

	// ── Test 2b: reload does not re-send kickoff ──────────────────────────

	test( 'reloading the page during the theme-builder flow does NOT re-send the kickoff message', async () => {
		// Count the current number of message rows before reload.
		const messageRowsBefore = await page
			.locator( '.sdaa-cr .sdaa-cr-msg-row' )
			.count();

		// Reload the page.
		await page.reload();
		await page.waitForLoadState( 'domcontentloaded' );

		// Wait for the chat UI to re-render after reload.
		await page
			.locator( '.sdaa-cr' )
			.waitFor( { state: 'visible', timeout: 45_000 } );

		// Count the message rows after reload.
		// If the kickoff was re-sent, there would be an additional message row.
		// With the fix, the count should remain the same.
		const messageRowsAfter = await page
			.locator( '.sdaa-cr .sdaa-cr-msg-row' )
			.count();

		// Assert that no new kickoff message was added.
		expect( messageRowsAfter ).toBe( messageRowsBefore );
	} );

	// ── Test 3: build instruction → DONE reply → theme on disk ────────────

	test( 'sending the build instruction results in DONE reply and an active theme on disk', async () => {
		// Send the single-call deterministic build instruction.
		// overwrite=true handles any leftover directory from a prior run that
		// the beforeAll cleanup could not remove.
		const input = getMessageInput( page );
		await input.fill(
			'Use sd-ai-agent/scaffold-block-theme with slug=e2e-test-theme, ' +
				'name="E2E Test Theme", and overwrite=true, then call ' +
				'sd-ai-agent/activate-theme with stylesheet=e2e-test-theme. ' +
				'Reply only with: DONE.'
		);
		await getSendButton( page ).click();

		// Wait for the agent reply containing "DONE".
		// Agent loops involve REST job polling (3 s intervals), the scaffold
		// ability, and the activate-theme ability — allow a generous 120 s.
		const messageList = page.locator( '.sdaa-cr .sdaa-cr-messages' );
		await expect( messageList ).toContainText( 'DONE', {
			timeout: 120_000,
		} );

		// ── Server-side assertions ────────────────────────────────────────

		// 1. Active stylesheet via the WP REST Themes API.
		const activeThemes = await page.evaluate( async () => {
			const root =
				( window.wpApiSettings && window.wpApiSettings.root ) ||
				'/wp-json/';
			const nonce =
				( window.wpApiSettings && window.wpApiSettings.nonce ) || '';
			try {
				const resp = await fetch( root + 'wp/v2/themes?status=active', {
					headers: { 'X-WP-Nonce': nonce },
				} );
				return resp.ok ? resp.json() : [];
			} catch {
				return [];
			}
		} );
		expect( activeThemes ).toBeInstanceOf( Array );
		expect( activeThemes.length ).toBeGreaterThan( 0 );
		expect( activeThemes[ 0 ].stylesheet ).toBe( 'e2e-test-theme' );

		// 2. Theme directory exists on disk.
		const themeDirExists = wpCli(
			"eval \"echo is_dir( WP_CONTENT_DIR . '/themes/e2e-test-theme' ) ? 'yes' : 'no';\""
		);
		expect(
			themeDirExists,
			'e2e-test-theme directory must exist'
		).toContain( 'yes' );

		// 3. Required theme files exist.
		// scaffold-block-theme writes theme.json, style.css, functions.php and
		// creates templates/; templates/index.html is written by the agent via
		// the file-write ability.
		const requiredFiles = [
			'theme.json',
			'style.css',
			'functions.php',
			'templates/index.html',
		];
		for ( const file of requiredFiles ) {
			const exists = wpCli(
				`eval "echo file_exists( WP_CONTENT_DIR . '/themes/e2e-test-theme/${ file }' ) ? 'yes' : 'no';"`
			);
			expect(
				exists,
				`required theme file ${ file } must exist`
			).toContain( 'yes' );
		}
	} );
} );
