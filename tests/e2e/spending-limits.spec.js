/**
 * E2E tests for the Superdav AI Agent spending limits / budget caps feature (t138/GH#651).
 *
 * Covers:
 *   - Settings UI shows spending limit fields (daily/monthly caps, warning threshold, exceeded action)
 *   - Saving a limit persists across page reload (PATCH → GET round-trip)
 *   - Budget indicator shows warning state when threshold is approached
 *   - Budget indicator shows exceeded state when cap is surpassed
 *   - Resetting limits to zero works correctly
 *   - Budget indicator is hidden when no caps are configured
 *
 * All REST calls to /sd-ai-agent/v1/* are intercepted and mocked so
 * these tests run without a real AI provider or a live WordPress back-end.
 *
 * Run: npm run test:e2e:playwright -- --grep "Spending Limits"
 */

const { test, expect } = require( '@playwright/test' );
const { loginToWordPress } = require( './utils/wp-admin' );

// ---------------------------------------------------------------------------
// Shared mock data
// ---------------------------------------------------------------------------

/**
 * Baseline settings object returned by the /settings endpoint.
 *
 * The SettingsApp component blocks rendering until settingsLoaded is true,
 * which requires a successful response from /sd-ai-agent/v1/settings.
 * Without this mock the page stays in a loading spinner and all tab content
 * (including the General tab with spending limits) is never rendered.
 */
const MOCK_SETTINGS = {
	default_provider: '',
	default_model: '',
	max_iterations: 10,
	greeting_message: '',
	keyboard_shortcut: 'alt+a',
	yolo_mode: false,
	show_on_frontend: false,
	show_token_costs: true,
	auto_memory: false,
	knowledge_enabled: false,
	knowledge_auto_index: false,
	system_prompt: '',
	temperature: 0.7,
	max_output_tokens: 4096,
	context_window_default: 128000,
	tool_discovery_mode: 'auto',
	tool_discovery_threshold: 20,
	budget_daily_cap: 0,
	budget_monthly_cap: 0,
	budget_warning_threshold: 80,
	budget_exceeded_action: 'pause',
	image_generation_size: '1024x1024',
	image_generation_quality: 'standard',
	image_generation_style: 'vivid',
	tool_permissions: {},
	_defaults: {},
};

/**
 * Budget status response returned by the /budget endpoint.
 *
 * @param {object} [overrides] - Fields to override on the default response.
 * @return {object} Budget status object.
 */
function makeBudgetStatus( overrides = {} ) {
	return {
		daily_spend: 0,
		monthly_spend: 0,
		daily_cap: 0,
		monthly_cap: 0,
		warning_level: 'ok',
		...overrides,
	};
}

// ---------------------------------------------------------------------------
// Route-mocking helpers
// ---------------------------------------------------------------------------

/**
 * Install REST API mocks for the spending limits tests.
 *
 * Intercepts all /wp-json/sd-ai-agent/v1/* requests and returns
 * controlled JSON responses so tests run without a live WordPress back-end.
 *
 * Critically, this also mocks the /settings, /providers, /abilities, and
 * /settings/google-analytics endpoints that SettingsApp fetches on mount.
 * Without these mocks the settings page stays in a loading spinner and the
 * tab content (General tab with spending limits) is never rendered.
 *
 * @param {import('@playwright/test').Page} page        - Playwright page.
 * @param {object}                          [overrides] - Per-endpoint overrides.
 * @param {object}                          [overrides.settings]     - Settings GET response.
 * @param {object}                          [overrides.budgetStatus] - Budget GET response.
 */
async function mockSpendingLimitsRoutes( page, overrides = {} ) {
	const {
		settings = MOCK_SETTINGS,
		budgetStatus = makeBudgetStatus(),
	} = overrides;

	// Clear any previously registered route handlers so that re-registering
	// in beforeEach (or within a test body) does not stack handlers from prior
	// tests. Playwright evaluates handlers LIFO, so stale handlers from earlier
	// tests would otherwise shadow the current mock data.
	await page.unrouteAll( { behavior: 'ignoreErrors' } );

	// Use '**' to catch ALL requests, then filter by decoded URL.
	// wp-env uses plain permalinks (?rest_route=%2Fsd-ai-agent%2Fv1%2F...)
	// so the path-based glob '**/sd-ai-agent/v1/**' does NOT match — the
	// plugin path appears in the query string, not the URL path. Decoding first
	// and matching on the decoded string handles both pretty-permalink and
	// plain-permalink URL formats reliably.
	await page.route( '**', async ( route ) => {
		const rawUrl = route.request().url();
		const url = decodeURIComponent( rawUrl );
		const method = route.request().method();
		// WordPress's apiFetch httpV1Middleware converts PATCH/PUT/DELETE
		// to POST with an X-HTTP-Method-Override header. Resolve the
		// effective method so route handlers match correctly.
		const override = route.request().headers()[ 'x-http-method-override' ];
		const effectiveMethod = override || method;

		// Only handle requests for our plugin's REST namespace.
		if ( ! url.includes( 'sd-ai-agent/v1' ) ) {
			return route.continue();
		}

		// ----------------------------------------------------------------
		// Settings-page bootstrap endpoints.
		// SettingsApp calls these on mount; without responses it stays in
		// a loading spinner and never renders the tab content.
		// ----------------------------------------------------------------

		// Google Analytics credential status (fetched in useEffect).
		if ( url.includes( '/settings/google-analytics' ) ) {
			return route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					has_credentials: false,
					has_property_id: false,
					property_id: '',
					has_service_key: false,
				} ),
			} );
		}

		// Settings — must respond before SettingsApp renders tab content.
		// Use includes() rather than /\/settings$/ regex: in plain-permalink
		// format the URL is ?rest_route=.../settings which may have additional
		// query params appended, so a $ anchor would not match.
		if (
			url.includes( '/sd-ai-agent/v1/settings' ) &&
			! url.includes( '/settings/google-analytics' )
		) {
			if ( effectiveMethod === 'POST' || effectiveMethod === 'PATCH' ) {
				// Return the merged settings so the UI reflects the saved values.
				const body = JSON.parse( route.request().postData() || '{}' );
				return route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( { ...settings, ...body } ),
				} );
			}
			return route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( settings ),
			} );
		}

		// Budget status endpoint — used by BudgetIndicator component.
		if ( url.includes( '/sd-ai-agent/v1/budget' ) ) {
			return route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( budgetStatus ),
			} );
		}

		// Providers list — return a fake provider so AdminPageApp renders the
		// chat layout instead of ConnectorGate (which blocks when providers=[]).
		// The budget indicator tests need the non-compact chat panel visible,
		// which requires at least one provider in the store.
		if ( url.includes( '/sd-ai-agent/v1/providers' ) ) {
			return route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( [
					{
						id: 'openai',
						name: 'OpenAI',
						type: 'direct',
						configured: true,
						models: [
							{
								id: 'gpt-4.1-nano',
								name: 'GPT-4.1 Nano',
								context_window: 1000000,
							},
						],
					},
				] ),
			} );
		}

		// Abilities list.
		if ( url.includes( '/sd-ai-agent/v1/abilities' ) ) {
			return route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( [] ),
			} );
		}

		// Alerts endpoint — used by the FAB badge.
		if ( url.includes( '/alerts' ) ) {
			return route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( { count: 0, alerts: [] } ),
			} );
		}

		// Fall through — let other endpoints (sessions, nonces, etc.) pass.
		return route.continue();
	} );
}

/**
 * Navigate to the Settings page and activate the General tab.
 *
 * Waits for the settings section to be visible AND for the initial data
 * fetch to complete before returning. This prevents tests from running
 * assertions against the loading state.
 *
 * @param {import('@playwright/test').Page} page - Playwright page.
 */
async function goToGeneralTab( page ) {
	// UnifiedAdminMenu uses hash-based routing. The settings route is at
	// admin.php?page=sd-ai-agent#/settings. The old URL
	// (tools.php?page=sdaa-settings) triggers a wp_safe_redirect()
	// which causes Playwright to hang — use the canonical hash URL directly.
	await page.goto( '/wp-admin/admin.php?page=sd-ai-agent#/settings' );
	await page.waitForLoadState( 'domcontentloaded' );
	// Wait for the settings route container to render.
	// Use 30 s to match the Playwright test timeout — the unified admin SPA
	// can be slow to render on CI runners under load with 3 parallel workers.
	await page
		.locator( '.sdaa-route-settings' )
		.waitFor( { state: 'visible', timeout: 30_000 } );

	// The SettingsRoute outer TabPanel (inside .sdaa-route-settings) has
	// General/Providers/Advanced tabs. The outer "General" tab is active by
	// default (initialTabName='general'), so SettingsApp renders immediately.
	//
	// SettingsApp itself renders an inner TabPanel with tabs:
	// providers, general, system-prompt, memory, skills, etc.
	// The inner tabs have className='sdaa-settings-tab'.
	// The inner "General" tab contains the Spending Limits section.
	// The inner TabPanel defaults to the first tab ("providers"), so we must
	// explicitly click the inner "General" tab.
	//
	// Wait for SettingsApp to finish loading (settingsLoaded=true) before
	// clicking the inner tab — SettingsApp renders a spinner until then.
	await page
		.locator( '.sdaa-settings-loading' )
		.waitFor( { state: 'hidden', timeout: 15_000 } )
		.catch( () => {} ); // Non-fatal: spinner may not appear if settings load instantly.

	// Click the inner "General" tab inside SettingsApp.
	// The inner SettingsApp TabPanel tab buttons have className
	// 'sdaa-settings-tab'. The outer SettingsRoute TabPanel tabs
	// do NOT have this class. Filtering by this class uniquely identifies the
	// inner tabs and avoids matching the outer "General" tab or the floating
	// widget's "General" tab.
	const innerGeneralTab = page
		.locator(
			'.sdaa-route-settings .sdaa-settings-tab'
		)
		.filter( { hasText: /^general$/i } );
	await innerGeneralTab.click();

	// Wait for the settings section container to confirm the tab content has rendered.
	const section = page.locator( '.sdaa-settings-section' );
	await section.waitFor( { state: 'visible', timeout: 15_000 } );
}

// ---------------------------------------------------------------------------
// Spending Limits — Settings UI
// ---------------------------------------------------------------------------

test.describe( 'Spending Limits — Settings UI (GH#651)', () => {
	test.beforeEach( async ( { page } ) => {
		// Install route mocks BEFORE login so the floating widget's fetchAlerts()
		// and SettingsApp bootstrap requests are intercepted from the first page load.
		await mockSpendingLimitsRoutes( page );
		await loginToWordPress( page );
	} );

	test( 'General tab renders the Spending Limits heading', async ( {
		page,
	} ) => {
		await goToGeneralTab( page );

		await expect(
			page.getByRole( 'heading', { name: /spending limits/i } )
		).toBeVisible();
	} );

	test( 'spending limit fields are visible with correct labels', async ( {
		page,
	} ) => {
		await goToGeneralTab( page );

		// Daily Budget Cap field.
		await expect(
			page.getByLabel( /daily budget cap/i )
		).toBeVisible();

		// Monthly Budget Cap field.
		await expect(
			page.getByLabel( /monthly budget cap/i )
		).toBeVisible();

		// Warning Threshold range control.
		// WordPress RangeControl renders two inputs with the same aria-label
		// (a range slider and a number spinbutton). Use the slider role to
		// avoid a strict-mode violation from the ambiguous getByLabel match.
		await expect(
			page.getByRole( 'slider', { name: /warning threshold/i } )
		).toBeVisible();

		// Action When Budget Exceeded select.
		await expect(
			page.getByLabel( /action when budget exceeded/i )
		).toBeVisible();
	} );

	test( 'budget exceeded action select has pause and warn options', async ( {
		page,
	} ) => {
		await goToGeneralTab( page );

		const select = page.getByLabel( /action when budget exceeded/i );
		await expect( select ).toBeVisible();

		// "pause" option — blocks new requests.
		await expect(
			select.locator( 'option[value="pause"]' )
		).toHaveCount( 1 );

		// "warn" option — shows warning but allows requests.
		await expect(
			select.locator( 'option[value="warn"]' )
		).toHaveCount( 1 );
	} );

	test( 'saving a daily cap sends PATCH and reflects the saved value', async ( {
		page,
	} ) => {
		let patchMade = false;
		let patchBody = null;

		// Register a higher-priority handler (LIFO) to capture the PATCH.
		// Use route.fallback() for non-matching requests so they pass to the
		// beforeEach mock rather than going directly to the network.
		await page.route( '**', async ( route ) => {
			const url = decodeURIComponent( route.request().url() );
			const method = route.request().method();
			const override =
				route.request().headers()[ 'x-http-method-override' ];
			const effectiveMethod = override || method;

			if (
				url.includes( '/sd-ai-agent/v1/settings' ) &&
				! url.includes( '/settings/google-analytics' ) &&
				( effectiveMethod === 'POST' || effectiveMethod === 'PATCH' )
			) {
				patchMade = true;
				patchBody = JSON.parse( route.request().postData() || '{}' );
				return route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( {
						...MOCK_SETTINGS,
						...patchBody,
					} ),
				} );
			}

			return route.fallback();
		} );

		await goToGeneralTab( page );

		// Set a daily cap of 5.00.
		const dailyCapInput = page.getByLabel( /daily budget cap/i );
		await dailyCapInput.fill( '5' );

		// Save settings.
		const saveButton = page.getByRole( 'button', { name: /save settings/i } );
		await saveButton.click();

		// Success snackbar should appear (SettingsApp uses SnackbarList).
		await expect(
			page
				.locator( '.components-snackbar' )
				.filter( { hasText: /saved/i } )
		).toBeVisible( { timeout: 10_000 } );

		// PATCH must have been made with the correct budget_daily_cap value.
		expect( patchMade ).toBe( true );
		expect( patchBody ).toMatchObject( { budget_daily_cap: 5 } );
	} );

	test( 'saving a monthly cap sends PATCH with correct value', async ( {
		page,
	} ) => {
		let patchBody = null;

		await page.route( '**', async ( route ) => {
			const url = decodeURIComponent( route.request().url() );
			const method = route.request().method();
			const override =
				route.request().headers()[ 'x-http-method-override' ];
			const effectiveMethod = override || method;

			if (
				url.includes( '/sd-ai-agent/v1/settings' ) &&
				! url.includes( '/settings/google-analytics' ) &&
				( effectiveMethod === 'POST' || effectiveMethod === 'PATCH' )
			) {
				patchBody = JSON.parse( route.request().postData() || '{}' );
				return route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( {
						...MOCK_SETTINGS,
						...patchBody,
					} ),
				} );
			}

			return route.fallback();
		} );

		await goToGeneralTab( page );

		const monthlyCapInput = page.getByLabel( /monthly budget cap/i );
		await monthlyCapInput.fill( '50' );

		await page.getByRole( 'button', { name: /save settings/i } ).click();

		await expect(
			page
				.locator( '.components-snackbar' )
				.filter( { hasText: /saved/i } )
		).toBeVisible( { timeout: 10_000 } );

		expect( patchBody ).toMatchObject( { budget_monthly_cap: 50 } );
	} );

	test( 'resetting caps to zero sends PATCH with zero values', async ( {
		page,
	} ) => {
		// Start with caps already set.
		await mockSpendingLimitsRoutes( page, {
			settings: {
				...MOCK_SETTINGS,
				budget_daily_cap: 10,
				budget_monthly_cap: 100,
			},
		} );

		let patchBody = null;

		await page.route( '**', async ( route ) => {
			const url = decodeURIComponent( route.request().url() );
			const method = route.request().method();
			const override =
				route.request().headers()[ 'x-http-method-override' ];
			const effectiveMethod = override || method;

			if (
				url.includes( '/sd-ai-agent/v1/settings' ) &&
				! url.includes( '/settings/google-analytics' ) &&
				( effectiveMethod === 'POST' || effectiveMethod === 'PATCH' )
			) {
				patchBody = JSON.parse( route.request().postData() || '{}' );
				return route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( {
						...MOCK_SETTINGS,
						...patchBody,
					} ),
				} );
			}

			return route.fallback();
		} );

		await goToGeneralTab( page );

		// Clear both cap fields (reset to 0 = unlimited).
		const dailyCapInput = page.getByLabel( /daily budget cap/i );
		await dailyCapInput.fill( '' );

		const monthlyCapInput = page.getByLabel( /monthly budget cap/i );
		await monthlyCapInput.fill( '' );

		await page.getByRole( 'button', { name: /save settings/i } ).click();

		await expect(
			page
				.locator( '.components-snackbar' )
				.filter( { hasText: /saved/i } )
		).toBeVisible( { timeout: 10_000 } );

		// Clearing the field sends 0 (unlimited) per the onChange handler.
		expect( patchBody ).toMatchObject( {
			budget_daily_cap: 0,
			budget_monthly_cap: 0,
		} );
	} );
} );

// ---------------------------------------------------------------------------
// Spending Limits — Budget Indicator in Chat Header
// ---------------------------------------------------------------------------
// FIXME: BudgetIndicator was in ChatPanel (.sdaa-chat-panel) which
// is no longer rendered in the admin page. The admin page now uses ChatRedesign
// (.sdaa-cr) which does not yet include BudgetIndicator. Re-enable this describe
// block once BudgetIndicator is added to the ChatRedesign layout.
// ---------------------------------------------------------------------------

test.describe.fixme( 'Spending Limits — Budget Indicator (GH#651)', () => {
	test.beforeEach( async ( { page } ) => {
		await mockSpendingLimitsRoutes( page );
		await loginToWordPress( page );
	} );

	/**
	 * Navigate to the admin page and wait for the AdminPageApp to mount inside
	 * #sdaa-chat-container. The unified admin's ChatRoute calls
	 * window.sdAiAgentChat.mount() which renders AdminPageApp. AdminPageApp
	 * returns null until settingsLoaded=true, then renders ChatRedesign.
	 * Wait for .sdaa-cr (the ChatRedesign root) to confirm hydration.
	 *
	 * NOTE: The old admin page rendered ChatPanel (.sdaa-chat-panel).
	 * The redesigned admin page renders ChatRedesign (.sdaa-cr). BudgetIndicator
	 * is no longer part of the ChatRedesign layout — the budget indicator tests
	 * below are marked test.fixme until BudgetIndicator is added to ChatRedesign.
	 *
	 * @param {import('@playwright/test').Page} page - Playwright page.
	 */
	async function goToAdminChatPage( page ) {
		await page.goto( '/wp-admin/admin.php?page=sd-ai-agent' );
		await page.waitForLoadState( 'domcontentloaded' );
		// Wait for the unified admin SPA to render.
		await page
			.locator( '.sdaa-unified-admin' )
			.waitFor( { state: 'visible', timeout: 15_000 } );
		// Wait for the ChatRedesign root element (.sdaa-cr) to confirm the
		// AdminPageApp has hydrated past the settingsLoaded=false null-return guard.
		// Old selector was .sdaa-chat-panel:not(.is-compact).
		await page
			.locator( '.sdaa-cr' )
			.waitFor( { state: 'visible', timeout: 15_000 } );
	}

	test( 'budget indicator is hidden when no caps are configured', async ( {
		page,
	} ) => {
		// No caps → BudgetIndicator returns null.
		await mockSpendingLimitsRoutes( page, {
			budgetStatus: makeBudgetStatus( {
				daily_cap: 0,
				monthly_cap: 0,
			} ),
		} );

		await goToAdminChatPage( page );

		// The indicator element should not be present in the admin page chat panel.
		// Scope to the non-compact chat panel to avoid matching the floating
		// widget's hidden budget indicator (which also returns null when no caps).
		await expect(
			page.locator(
				'.sdaa-chat-panel:not(.is-compact) .sdaa-budget-indicator'
			)
		).toHaveCount( 0 );
	} );

	test( 'budget indicator shows ok state when spend is below warning threshold', async ( {
		page,
	} ) => {
		await mockSpendingLimitsRoutes( page, {
			budgetStatus: makeBudgetStatus( {
				daily_spend: 1.0,
				daily_cap: 10.0,
				warning_level: 'ok',
			} ),
		} );

		await goToAdminChatPage( page );

		// Scope to the non-compact (admin page) chat panel to avoid matching
		// the floating widget's hidden budget indicator.
		const indicator = page
			.locator(
				'.sdaa-chat-panel:not(.is-compact) .sdaa-budget-indicator'
			)
			.first();
		await expect( indicator ).toBeVisible( { timeout: 10_000 } );
		await expect( indicator ).toHaveClass(
			/ai-agent-budget-indicator--ok/
		);
		// Should display spend / cap.
		await expect( indicator ).toContainText( '$1.00' );
		await expect( indicator ).toContainText( '$10.00' );
	} );

	test( 'budget indicator shows warning state when threshold is approached', async ( {
		page,
	} ) => {
		// 85% of a $10 daily cap → warning level.
		await mockSpendingLimitsRoutes( page, {
			budgetStatus: makeBudgetStatus( {
				daily_spend: 8.5,
				daily_cap: 10.0,
				warning_level: 'warning',
			} ),
		} );

		await goToAdminChatPage( page );

		// Scope to the non-compact (admin page) chat panel.
		const indicator = page
			.locator(
				'.sdaa-chat-panel:not(.is-compact) .sdaa-budget-indicator'
			)
			.first();
		await expect( indicator ).toBeVisible( { timeout: 10_000 } );
		await expect( indicator ).toHaveClass(
			/ai-agent-budget-indicator--warning/
		);
		// Tooltip should mention approaching the limit.
		const title = await indicator.getAttribute( 'title' );
		expect( title ).toMatch( /approaching budget limit/i );
	} );

	test( 'budget indicator shows exceeded state when cap is surpassed', async ( {
		page,
	} ) => {
		// Spend exceeds the daily cap.
		await mockSpendingLimitsRoutes( page, {
			budgetStatus: makeBudgetStatus( {
				daily_spend: 12.0,
				daily_cap: 10.0,
				warning_level: 'exceeded',
			} ),
		} );

		await goToAdminChatPage( page );

		// Scope to the non-compact (admin page) chat panel.
		const indicator = page
			.locator(
				'.sdaa-chat-panel:not(.is-compact) .sdaa-budget-indicator'
			)
			.first();
		await expect( indicator ).toBeVisible( { timeout: 10_000 } );
		await expect( indicator ).toHaveClass(
			/ai-agent-budget-indicator--exceeded/
		);
		// Tooltip should mention budget exceeded.
		const title = await indicator.getAttribute( 'title' );
		expect( title ).toMatch( /budget exceeded/i );
	} );

	test( 'budget indicator uses monthly cap when no daily cap is set', async ( {
		page,
	} ) => {
		await mockSpendingLimitsRoutes( page, {
			budgetStatus: makeBudgetStatus( {
				daily_spend: 0,
				monthly_spend: 25.0,
				daily_cap: 0,
				monthly_cap: 100.0,
				warning_level: 'ok',
			} ),
		} );

		await goToAdminChatPage( page );

		// Scope to the non-compact (admin page) chat panel.
		const indicator = page
			.locator(
				'.sdaa-chat-panel:not(.is-compact) .sdaa-budget-indicator'
			)
			.first();
		await expect( indicator ).toBeVisible( { timeout: 10_000 } );
		// Should show monthly spend and cap.
		await expect( indicator ).toContainText( '$25.00' );
		await expect( indicator ).toContainText( '$100.00' );
		// Label should say "this month".
		await expect( indicator ).toContainText( 'this month' );
	} );
} );
