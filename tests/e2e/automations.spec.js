/**
 * E2E tests for the Superdav AI Agent automations system (t080/t081).
 *
 * Covers:
 *   - Scheduled automations: create, list, enable/disable toggle
 *   - Event-driven automations: create, list, enable/disable toggle
 *   - Proactive alert badge on the FAB
 *
 * All REST calls to /sd-ai-agent/v1/* are intercepted and mocked so
 * these tests run without a real AI provider or a live WordPress back-end.
 *
 * Run: npm run test:e2e:playwright -- --grep automations
 */

const { test, expect } = require( '@playwright/test' );
const { loginToWordPress, goToAdminDashboard } = require( './utils/wp-admin' );

// ---------------------------------------------------------------------------
// Shared mock data
// ---------------------------------------------------------------------------

/** A minimal scheduled automation returned by the REST API. */
const MOCK_AUTOMATION = {
	id: 1,
	// Use a name that does NOT match any built-in template name so that
	// filter({ hasText }) always resolves to the automation card, never to
	// a template card (templates also use .sdaa-skill-card).
	name: 'Test Scheduled Automation',
	description: 'Run a test site health check.',
	prompt: 'Check site health and report issues.',
	schedule: 'daily',
	tool_profile: '',
	max_iterations: 10,
	enabled: true,
	notification_channels: [],
	run_count: 3,
	last_run_at: '2026-03-18 08:00:00',
	next_run_at: null,
	created_at: '2026-01-01 00:00:00',
	updated_at: '2026-03-18 08:00:00',
};

/** A minimal event automation returned by the REST API. */
const MOCK_EVENT = {
	id: 1,
	// Use a name that does NOT match any built-in template name.
	name: 'Test Event Automation',
	description: 'Tag posts automatically when published.',
	hook_name: 'transition_post_status',
	prompt_template: 'Tag the post {{post_title}} with relevant categories.',
	conditions: { post_type: 'post', new_status: 'publish' },
	tool_profile: '',
	max_iterations: 10,
	enabled: true,
	run_count: 5,
	last_run_at: '2026-03-17 12:00:00',
	created_at: '2026-01-01 00:00:00',
	updated_at: '2026-03-17 12:00:00',
};

/** A minimal event trigger definition. */
const MOCK_TRIGGER = {
	hook_name: 'transition_post_status',
	label: 'Post status changed',
	// Description must match the real EventTriggerRegistry value so the test
	// passes whether the mock intercepts or the real server responds.
	description: 'Fires when a post status transitions (e.g. draft to publish).',
	category: 'wordpress',
	// The real EventTriggerRegistry returns placeholders as a key→label object,
	// not an array of {key, description} objects.  The EventsManager component
	// checks `selectedTrigger.placeholders?.length > 0` — an object has no
	// `.length`, so the section would never render with the old array format.
	// Use the same key-value shape the real API returns.
	placeholders: {
		'post.title': 'Post title',
		'post.ID': 'Post ID',
		new_status: 'New post status',
	},
	conditions: {
		post_type: 'Post type equals',
		new_status: 'New status equals',
	},
};

/** Automation templates returned by the REST API. */
const MOCK_TEMPLATES = [
	{
		name: 'Daily Site Health Report',
		description: 'Run a comprehensive automated site health check.',
		prompt: 'Check site health.',
		schedule: 'daily',
		tool_profile: 'site-health',
	},
];

// ---------------------------------------------------------------------------
// Route-mocking helpers
// ---------------------------------------------------------------------------

/**
 * Minimal settings object returned by the /settings endpoint.
 *
 * The SettingsApp component blocks rendering until settingsLoaded is true,
 * which requires a successful response from /sd-ai-agent/v1/settings.
 * Without this mock the page stays in a loading spinner and all tab content
 * (including the automations and events managers) is never rendered.
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
 * Install REST API mocks for the automations endpoints.
 *
 * Intercepts all /wp-json/sd-ai-agent/v1/* requests and returns
 * controlled JSON responses so tests run without a live WordPress back-end.
 *
 * Critically, this also mocks the /settings, /providers, /abilities, and
 * /settings/google-analytics endpoints that SettingsApp fetches on mount.
 * Without these mocks the settings page stays in a loading spinner and the
 * tab content (AutomationsManager, EventsManager) is never rendered.
 *
 * @param {import('@playwright/test').Page} page         - Playwright page.
 * @param {object}                          [overrides]  - Per-endpoint overrides.
 * @param {Array}                           [overrides.automations]          - List response.
 * @param {Array}                           [overrides.eventAutomations]     - List response.
 * @param {Array}                           [overrides.triggers]             - Triggers list.
 * @param {Array}                           [overrides.templates]            - Templates list.
 * @param {object}                          [overrides.createdAutomation]    - POST response.
 * @param {object}                          [overrides.createdEvent]         - POST response.
 * @param {object}                          [overrides.alerts]               - Alerts response.
 */
async function mockAutomationRoutes( page, overrides = {} ) {
	const {
		automations = [ MOCK_AUTOMATION ],
		eventAutomations = [ MOCK_EVENT ],
		triggers = [ MOCK_TRIGGER ],
		templates = MOCK_TEMPLATES,
		createdAutomation = { ...MOCK_AUTOMATION, id: 99 },
		createdEvent = { ...MOCK_EVENT, id: 99 },
		alerts = { count: 0, alerts: [] },
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
			return route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( MOCK_SETTINGS ),
			} );
		}

		// Providers list.
		if ( url.includes( '/sd-ai-agent/v1/providers' ) ) {
			return route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( [] ),
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

		// ----------------------------------------------------------------
		// Automations-specific endpoints.
		// ----------------------------------------------------------------

		// Alerts endpoint — used by the FAB badge.
		if ( url.includes( '/alerts' ) ) {
			return route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( alerts ),
			} );
		}

		// Tool profiles — used by both managers.
		if ( url.includes( '/tool-profiles' ) ) {
			return route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( [] ),
			} );
		}

		// Automation templates.
		if ( url.includes( '/automation-templates' ) ) {
			return route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( templates ),
			} );
		}

		// Event triggers registry.
		if ( url.includes( '/event-triggers' ) ) {
			return route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( triggers ),
			} );
		}

		// Event automations CRUD.
		if ( url.includes( '/event-automations' ) ) {
			if (
				effectiveMethod === 'PATCH' ||
				effectiveMethod === 'DELETE'
			) {
				return route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( { success: true } ),
				} );
			}
			if ( effectiveMethod === 'POST' ) {
				return route.fulfill( {
					status: 201,
					contentType: 'application/json',
					body: JSON.stringify( createdEvent ),
				} );
			}
			// GET /event-automations or /event-automations/:id
			return route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify(
					url.match( /\/event-automations\/\d+/ )
						? eventAutomations[ 0 ] || MOCK_EVENT
						: eventAutomations
				),
			} );
		}

		// Automation logs — matches both /automation-logs and /automations/{id}/logs.
		if (
			url.includes( '/automation-logs' ) ||
			/\/automations\/\d+\/logs/.test( url )
		) {
			return route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( [] ),
			} );
		}

		// Scheduled automations CRUD and run.
		if ( url.includes( '/automations' ) ) {
			if (
				effectiveMethod === 'PATCH' ||
				effectiveMethod === 'DELETE'
			) {
				return route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( { success: true } ),
				} );
			}
			if ( effectiveMethod === 'POST' ) {
				// /automations/:id/run
				if ( url.match( /\/automations\/\d+\/run/ ) ) {
					return route.fulfill( {
						status: 200,
						contentType: 'application/json',
						body: JSON.stringify( {
							success: true,
							message: 'Automation ran successfully.',
						} ),
					} );
				}
				return route.fulfill( {
					status: 201,
					contentType: 'application/json',
					body: JSON.stringify( createdAutomation ),
				} );
			}
			// GET /automations or /automations/:id
			return route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify(
					url.match( /\/automations\/\d+/ )
						? automations[ 0 ] || MOCK_AUTOMATION
						: automations
				),
			} );
		}

		// Fall through — let other endpoints (sessions, nonces, etc.) pass.
		return route.continue();
	} );
}

/**
 * Navigate to the Settings page and activate the Automations tab.
 *
 * Waits for the AutomationsManager container to be visible AND for the
 * initial data fetch to complete (loading spinner gone) before returning.
 * This prevents tests from running assertions against the loading state
 * where automations = [] and template cards may be visible.
 *
 * @param {import('@playwright/test').Page} page - Playwright page.
 */
async function goToAutomationsTab( page ) {
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
	const tab = page.getByRole( 'tab', { name: /automations/i } );
	await tab.click();
	// Wait for the manager container to confirm the tab content has rendered.
	const manager = page.locator( '.sdaa-automations-manager' );
	await manager.waitFor( { state: 'visible', timeout: 15_000 } );
	// Wait for the async fetchAll() to complete: the "Loading…" text is shown
	// while loaded=false and disappears once fetchAll resolves or rejects.
	// This ensures assertions run against the final data state, not the
	// intermediate loading state where automations=[] and templates may show.
	await manager
		.getByText( 'Loading…' )
		.waitFor( { state: 'hidden', timeout: 10_000 } )
		.catch( () => {} ); // Already gone — that's fine.
}

/**
 * Navigate to the Settings page and activate the Automations tab to reach the
 * EventsManager.
 *
 * The EventsManager is rendered inside the Automations tab (together with
 * AutomationsManager) — there is no separate "Events" tab in the settings
 * page. Waiting for .sdaa-events-manager ensures the events
 * section has rendered and the initial data fetch has completed.
 *
 * @param {import('@playwright/test').Page} page - Playwright page.
 */
async function goToEventsTab( page ) {
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
	// EventsManager lives inside the Automations tab, not a separate Events tab.
	const tab = page.getByRole( 'tab', { name: /automations/i } );
	await tab.click();
	// Wait for the manager container to confirm the tab content has rendered.
	const manager = page.locator( '.sdaa-events-manager' );
	await manager.waitFor( { state: 'visible', timeout: 15_000 } );
	// Wait for the async fetchAll() to complete.
	await manager
		.getByText( 'Loading…' )
		.waitFor( { state: 'hidden', timeout: 10_000 } )
		.catch( () => {} ); // Already gone — that's fine.
}

// ---------------------------------------------------------------------------
// Scheduled Automations
// ---------------------------------------------------------------------------

test.describe( 'Scheduled Automations (t080)', () => {
	test.beforeEach( async ( { page } ) => {
		// Install route mocks BEFORE login so the floating widget's fetchAlerts()
		// and SettingsApp bootstrap requests are intercepted from the first page load.
		await mockAutomationRoutes( page );
		await loginToWordPress( page );
	} );

	test( 'automations tab renders the manager heading', async ( { page } ) => {
		await goToAutomationsTab( page );

		const manager = page.locator( '.sdaa-automations-manager' );
		await expect( manager ).toBeVisible();

		// Heading text.
		await expect(
			page.getByRole( 'heading', { name: /scheduled automations/i } )
		).toBeVisible();
	} );

	test( 'automation list displays existing automations', async ( { page } ) => {
		await goToAutomationsTab( page );

		// The mock returns one automation — its name should appear in a card.
		const card = page
			.locator( '.sdaa-skill-card' )
			.filter( { hasText: MOCK_AUTOMATION.name } );
		await expect( card ).toBeVisible();
	} );

	test( 'automation card shows schedule badge', async ( { page } ) => {
		await goToAutomationsTab( page );

		const card = page
			.locator( '.sdaa-skill-card' )
			.filter( { hasText: MOCK_AUTOMATION.name } );

		// Wait for the card to be in the DOM before accessing sub-elements.
		// The automations list is populated asynchronously after the tab renders.
		await expect( card ).toBeVisible( { timeout: 10_000 } );

		// Schedule badge (e.g. "daily").
		const badge = card.locator( '.sdaa-skill-badge' ).first();
		await expect( badge ).toContainText( MOCK_AUTOMATION.schedule );
	} );

	test( 'automation card shows run count', async ( { page } ) => {
		await goToAutomationsTab( page );

		const card = page
			.locator( '.sdaa-skill-card' )
			.filter( { hasText: MOCK_AUTOMATION.name } );

		await expect( card ).toContainText(
			`${ MOCK_AUTOMATION.run_count } runs`
		);
	} );

	test( 'Add Automation button opens the creation form', async ( { page } ) => {
		await goToAutomationsTab( page );

		const addButton = page.getByRole( 'button', {
			name: /add automation/i,
		} );
		await expect( addButton ).toBeVisible();
		await addButton.click();

		// Form fields should appear.
		const form = page.locator( '.sdaa-skill-form' );
		await expect( form ).toBeVisible();

		// Name and Prompt fields are required.
		await expect( form.getByLabel( /^name/i ) ).toBeVisible();
		await expect( form.getByLabel( /prompt/i ) ).toBeVisible();
	} );

	test( 'Create button is disabled when Name or Prompt is empty', async ( {
		page,
	} ) => {
		await goToAutomationsTab( page );

		await page
			.getByRole( 'button', { name: /add automation/i } )
			.click();

		const form = page.locator( '.sdaa-skill-form' );
		const createButton = form.getByRole( 'button', { name: /^create$/i } );

		// Both fields empty → disabled.
		await expect( createButton ).toBeDisabled();

		// Fill only Name → still disabled (Prompt missing).
		await form.getByLabel( /^name/i ).fill( 'My Automation' );
		await expect( createButton ).toBeDisabled();

		// Fill Prompt → enabled.
		await form.getByLabel( /prompt/i ).fill( 'Do something useful.' );
		await expect( createButton ).toBeEnabled();
	} );

	test( 'creating a scheduled automation submits POST and shows success notice', async ( {
		page,
	} ) => {
		// After creation the list re-fetches; return the new item.
		const newAutomation = {
			...MOCK_AUTOMATION,
			id: 99,
			name: 'My New Automation',
			prompt: 'Do something useful.',
		};

		// Track whether the POST was made and capture the request body.
		let postMade = false;
		let postBody = null;
		// Use '**' + decoded URL check to match both pretty-permalink and
		// plain-permalink (?rest_route=...) URL formats. Registered after the
		// beforeEach mock so it takes precedence (LIFO).
		await page.route( '**', async ( route ) => {
			const decodedUrl = decodeURIComponent( route.request().url() );
			// Only handle the /automations list endpoint (not /automations/ID
			// or /automation-templates).
			if (
				! decodedUrl.includes( 'sd-ai-agent/v1/automations' ) ||
				decodedUrl.includes( '/automation-templates' ) ||
				/sd-ai-agent\/v1\/automations\/\d/.test( decodedUrl )
			) {
				return route.continue();
			}
			if ( route.request().method() === 'POST' ) {
				postMade = true;
				postBody = JSON.parse(
					route.request().postData() || '{}'
				);
				return route.fulfill( {
					status: 201,
					contentType: 'application/json',
					body: JSON.stringify( newAutomation ),
				} );
			}
			// GET after creation returns the new item.
			return route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( [ newAutomation ] ),
			} );
		} );

		await goToAutomationsTab( page );

		await page
			.getByRole( 'button', { name: /add automation/i } )
			.click();

		const form = page.locator( '.sdaa-skill-form' );
		await form.getByLabel( /^name/i ).fill( 'My New Automation' );
		await form.getByLabel( /prompt/i ).fill( 'Do something useful.' );

		await form.getByRole( 'button', { name: /^create$/i } ).click();

		// Success notice should appear.
		await expect(
			page.locator( '.components-notice' ).filter( { hasText: /saved/i } )
		).toBeVisible( { timeout: 10_000 } );

		// POST must have been made with the correct body fields.
		expect( postMade ).toBe( true );
		expect( postBody ).toMatchObject( {
			name: 'My New Automation',
			prompt: 'Do something useful.',
		} );

		// The new automation should appear in the list after the GET refresh.
		await expect(
			page
				.locator( '.sdaa-skill-cards' )
				.filter( { hasText: 'My New Automation' } )
		).toBeVisible( { timeout: 10_000 } );
	} );

	test( 'enable/disable toggle calls PATCH and updates card state', async ( {
		page,
	} ) => {
		// Start with an enabled automation.
		let patchCalled = false;
		let patchBody = null;

		// After the PATCH the component calls fetchAll(); the GET must return
		// the updated state so the card re-renders with enabled: false.
		// Use '**' + decoded URL check to match both pretty-permalink and
		// plain-permalink (?rest_route=...) URL formats.
		// IMPORTANT: use route.fallback() (not route.continue()) for non-matching
		// requests so they pass to the next handler (the beforeEach mock) rather
		// than going directly to the network and bypassing the mock entirely.
		//
		// NOTE: WordPress's apiFetch httpV1Middleware converts PATCH/PUT/DELETE
		// to POST with an X-HTTP-Method-Override header. We must check for
		// both the raw method AND the override header.
		await page.route( '**', async ( route ) => {
			const decodedUrl = decodeURIComponent( route.request().url() );
			const method = route.request().method();
			const override = route.request().headers()[
				'x-http-method-override'
			];
			const effectiveMethod = override || method;

			// Match /automations/1 for PATCH and individual GET.
			if ( /sd-ai-agent\/v1\/automations\/1\b/.test( decodedUrl ) ) {
				if ( effectiveMethod === 'PATCH' ) {
					patchCalled = true;
					patchBody = JSON.parse(
						route.request().postData() || '{}'
					);
					return route.fulfill( {
						status: 200,
						contentType: 'application/json',
						body: JSON.stringify( { success: true } ),
					} );
				}
				// GET /automations/1 — return disabled state after PATCH.
				return route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify(
						patchCalled
							? { ...MOCK_AUTOMATION, enabled: false }
							: MOCK_AUTOMATION
					),
				} );
			}

			// Match /automations list endpoint (fetchAll after PATCH).
			// Only intercept after PATCH so the initial load uses the
			// beforeEach mock's default data.
			if (
				patchCalled &&
				/sd-ai-agent\/v1\/automations\b/.test( decodedUrl ) &&
				! /\/automations\/\d/.test( decodedUrl ) &&
				effectiveMethod === 'GET'
			) {
				return route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( [
						{ ...MOCK_AUTOMATION, enabled: false },
					] ),
				} );
			}

			return route.fallback();
		} );

		await goToAutomationsTab( page );

		const card = page
			.locator( '.sdaa-skill-card' )
			.filter( { hasText: MOCK_AUTOMATION.name } );

		// Wait for the card to be in the DOM before accessing sub-elements.
		await expect( card ).toBeVisible( { timeout: 10_000 } );

		// WordPress ToggleControl renders an opacity:0 <input> that covers
		// the entire .components-form-toggle area (position:absolute, z-index:1,
		// width/height 100%). Use evaluate() to trigger the native click in the
		// page's JS context — this properly fires React's synthetic onChange
		// handler, unlike Playwright's click({ force: true }) which bypasses
		// the browser's native event dispatch for opacity:0 elements.
		const checkbox = card
			.locator( 'input.components-form-toggle__input' )
			.first();

		// Verify the toggle starts in the checked/enabled state.
		await expect( checkbox ).toBeChecked();

		// Trigger the click via evaluate() so React's onChange fires correctly.
		await checkbox.evaluate( ( el ) => el.click() );

		// PATCH should have been called with enabled: false.
		await expect
			.poll( () => patchCalled, { timeout: 5_000 } )
			.toBe( true );
		expect( patchBody ).toMatchObject( { enabled: false } );

		// After fetchAll() the toggle should reflect the disabled state.
		await expect( checkbox ).not.toBeChecked( { timeout: 5_000 } );
	} );

	test( 'disabled automation card has disabled CSS class', async ( {
		page,
	} ) => {
		const disabledAutomation = { ...MOCK_AUTOMATION, enabled: false };
		await mockAutomationRoutes( page, {
			automations: [ disabledAutomation ],
		} );

		await goToAutomationsTab( page );

		const card = page
			.locator( '.sdaa-skill-card' )
			.filter( { hasText: disabledAutomation.name } );

		await expect( card ).toHaveClass( /sdaa-skill-card--disabled/ );
	} );

	test( 'Cancel button hides the creation form', async ( { page } ) => {
		await goToAutomationsTab( page );

		await page
			.getByRole( 'button', { name: /add automation/i } )
			.click();

		const form = page.locator( '.sdaa-skill-form' );
		await expect( form ).toBeVisible();

		await form.getByRole( 'button', { name: /cancel/i } ).click();

		await expect( form ).not.toBeVisible();
	} );

	test( 'Schedule select has expected options', async ( { page } ) => {
		await goToAutomationsTab( page );

		await page
			.getByRole( 'button', { name: /add automation/i } )
			.click();

		const form = page.locator( '.sdaa-skill-form' );
		const scheduleSelect = form.getByLabel( /schedule/i );

		// Verify the four standard WP cron schedules are present.
		// Use value-attribute selectors rather than :has-text() to avoid
		// substring matches (e.g. "Daily" would match "Twice Daily").
		for ( const [ label, value ] of [
			[ 'Hourly', 'hourly' ],
			[ 'Twice Daily', 'twicedaily' ],
			[ 'Daily', 'daily' ],
			[ 'Weekly', 'weekly' ],
		] ) {
			await expect(
				scheduleSelect.locator( `option[value="${ value }"]` )
			).toHaveCount( 1, { message: `Expected option "${ label }" to exist` } );
		}
	} );

	test( 'empty state shows when no automations exist', async ( { page } ) => {
		await mockAutomationRoutes( page, { automations: [], templates: [] } );

		await goToAutomationsTab( page );

		// No automation cards should be rendered (templates also use
		// .sdaa-skill-card, so we check the automations list container
		// directly — it is only rendered when automations.length > 0).
		await expect(
			page.locator( '.sdaa-automations-manager .sdaa-skill-cards' )
		).toHaveCount( 0 );
	} );

	test( 'Use Template button pre-fills the form', async ( { page } ) => {
		await mockAutomationRoutes( page, { automations: [] } );

		await goToAutomationsTab( page );

		const useTemplateButton = page
			.getByRole( 'button', { name: /use template/i } )
			.first();
		await expect( useTemplateButton ).toBeVisible();
		await useTemplateButton.click();

		// Form should open pre-filled with the template name.
		const form = page.locator( '.sdaa-skill-form' );
		await expect( form ).toBeVisible();

		const nameInput = form.getByLabel( /^name/i );
		await expect( nameInput ).toHaveValue( MOCK_TEMPLATES[ 0 ].name );
	} );
} );

// ---------------------------------------------------------------------------
// Event-Driven Automations
// ---------------------------------------------------------------------------

test.describe( 'Event-Driven Automations (t081)', () => {
	test.beforeEach( async ( { page } ) => {
		// Install route mocks BEFORE login so the floating widget's fetchAlerts()
		// and SettingsApp bootstrap requests are intercepted from the first page load.
		await mockAutomationRoutes( page );
		await loginToWordPress( page );
	} );

	test( 'events tab renders the manager heading', async ( { page } ) => {
		await goToEventsTab( page );

		const manager = page.locator( '.sdaa-events-manager' );
		await expect( manager ).toBeVisible();

		await expect(
			page.getByRole( 'heading', { name: /event-driven automations/i } )
		).toBeVisible();
	} );

	test( 'event list displays existing event automations', async ( {
		page,
	} ) => {
		await goToEventsTab( page );

		const card = page
			.locator( '.sdaa-skill-card' )
			.filter( { hasText: MOCK_EVENT.name } );
		await expect( card ).toBeVisible();
	} );

	test( 'event card shows hook name badge', async ( { page } ) => {
		await goToEventsTab( page );

		const card = page
			.locator( '.sdaa-skill-card' )
			.filter( { hasText: MOCK_EVENT.name } );

		// Wait for the card to be in the DOM before accessing sub-elements.
		await expect( card ).toBeVisible( { timeout: 10_000 } );

		const badge = card.locator( '.sdaa-skill-badge' ).first();
		await expect( badge ).toContainText( MOCK_EVENT.hook_name );
	} );

	test( 'event card shows run count', async ( { page } ) => {
		await goToEventsTab( page );

		const card = page
			.locator( '.sdaa-skill-card' )
			.filter( { hasText: MOCK_EVENT.name } );

		await expect( card ).toContainText( `${ MOCK_EVENT.run_count } runs` );
	} );

	test( 'Add Event button opens the creation form', async ( { page } ) => {
		await goToEventsTab( page );

		const addButton = page.getByRole( 'button', { name: /add event/i } );
		await expect( addButton ).toBeVisible();
		await addButton.click();

		const form = page.locator( '.sdaa-skill-form' );
		await expect( form ).toBeVisible();

		// Required fields.
		await expect( form.getByLabel( /^name/i ) ).toBeVisible();
		await expect( form.getByLabel( /trigger hook/i ) ).toBeVisible();
		await expect( form.getByLabel( /prompt template/i ) ).toBeVisible();
	} );

	test( 'Create button is disabled when required fields are empty', async ( {
		page,
	} ) => {
		await goToEventsTab( page );

		await page.getByRole( 'button', { name: /add event/i } ).click();

		const form = page.locator( '.sdaa-skill-form' );
		const createButton = form.getByRole( 'button', { name: /^create$/i } );

		// All empty → disabled.
		await expect( createButton ).toBeDisabled();

		// Name only → still disabled.
		await form.getByLabel( /^name/i ).fill( 'My Event' );
		await expect( createButton ).toBeDisabled();

		// Name + hook → still disabled (prompt missing).
		const hookSelect = form.getByLabel( /trigger hook/i );
		await hookSelect.selectOption( MOCK_TRIGGER.hook_name );
		await expect( createButton ).toBeDisabled();

		// All three filled → enabled.
		await form
			.getByLabel( /prompt template/i )
			.fill( 'Handle {{post_title}}.' );
		await expect( createButton ).toBeEnabled();
	} );

	test( 'creating an event automation submits POST and shows success notice', async ( {
		page,
	} ) => {
		const newEvent = {
			...MOCK_EVENT,
			id: 99,
			name: 'My New Event',
			prompt_template: 'Handle {{post_title}}.',
		};

		let postMade = false;
		let postBody = null;
		// Use '**' + decoded URL check to match both pretty-permalink and
		// plain-permalink (?rest_route=...) URL formats.
		await page.route( '**', async ( route ) => {
			const decodedUrl = decodeURIComponent( route.request().url() );
			// Only handle the /event-automations list endpoint (not /event-automations/ID).
			if (
				! decodedUrl.includes( 'sd-ai-agent/v1/event-automations' ) ||
				/sd-ai-agent\/v1\/event-automations\/\d/.test( decodedUrl )
			) {
				return route.continue();
			}
			if ( route.request().method() === 'POST' ) {
				postMade = true;
				postBody = JSON.parse(
					route.request().postData() || '{}'
				);
				return route.fulfill( {
					status: 201,
					contentType: 'application/json',
					body: JSON.stringify( newEvent ),
				} );
			}
			return route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( [ newEvent ] ),
			} );
		} );

		await goToEventsTab( page );

		await page.getByRole( 'button', { name: /add event/i } ).click();

		const form = page.locator( '.sdaa-skill-form' );
		await form.getByLabel( /^name/i ).fill( 'My New Event' );
		await form
			.getByLabel( /trigger hook/i )
			.selectOption( MOCK_TRIGGER.hook_name );
		await form
			.getByLabel( /prompt template/i )
			.fill( 'Handle {{post_title}}.' );

		await form.getByRole( 'button', { name: /^create$/i } ).click();

		await expect(
			page
				.locator( '.components-notice' )
				.filter( { hasText: /saved/i } )
		).toBeVisible( { timeout: 10_000 } );

		// POST must have been made with the correct body fields.
		expect( postMade ).toBe( true );
		expect( postBody ).toMatchObject( {
			name: 'My New Event',
			hook_name: MOCK_TRIGGER.hook_name,
			prompt_template: 'Handle {{post_title}}.',
		} );

		// The new event should appear in the list after the GET refresh.
		await expect(
			page
				.locator( '.sdaa-skill-cards' )
				.filter( { hasText: 'My New Event' } )
		).toBeVisible( { timeout: 10_000 } );
	} );

	test( 'trigger hook select is populated from the event-triggers endpoint', async ( {
		page,
	} ) => {
		await goToEventsTab( page );

		await page.getByRole( 'button', { name: /add event/i } ).click();

		const form = page.locator( '.sdaa-skill-form' );
		const hookSelect = form.getByLabel( /trigger hook/i );

		// The mock trigger should appear as an option.
		const option = hookSelect.locator(
			`option[value="${ MOCK_TRIGGER.hook_name }"]`
		);
		await expect( option ).toHaveCount( 1 );
	} );

	test( 'selecting a trigger shows its description and placeholders', async ( {
		page,
	} ) => {
		await goToEventsTab( page );

		await page.getByRole( 'button', { name: /add event/i } ).click();

		const form = page.locator( '.sdaa-skill-form' );
		await form
			.getByLabel( /trigger hook/i )
			.selectOption( MOCK_TRIGGER.hook_name );

		// Trigger info block should appear.
		const triggerInfo = form.locator( '.sdaa-trigger-info' );
		await expect( triggerInfo ).toBeVisible();
		await expect( triggerInfo ).toContainText(
			MOCK_TRIGGER.description
		);

		// Placeholder keys should be listed.
		// MOCK_TRIGGER.placeholders is a key→label object (matching the real
		// EventTriggerRegistry API format); iterate over its keys.
		for ( const key of Object.keys( MOCK_TRIGGER.placeholders ) ) {
			await expect( triggerInfo ).toContainText( `{{${ key }}}` );
		}
	} );

	test( 'enable/disable toggle calls PATCH for event automation', async ( {
		page,
	} ) => {
		let patchCalled = false;
		let patchBody = null;

		// After the PATCH the component calls fetchAll(); the GET must return
		// the updated state so the card re-renders with enabled: false.
		// Use '**' + decoded URL check to match both pretty-permalink and
		// plain-permalink (?rest_route=...) URL formats.
		// IMPORTANT: use route.fallback() (not route.continue()) for non-matching
		// requests so they pass to the next handler (the beforeEach mock) rather
		// than going directly to the network and bypassing the mock entirely.
		//
		// NOTE: WordPress's apiFetch httpV1Middleware converts PATCH/PUT/DELETE
		// to POST with an X-HTTP-Method-Override header. We must check for
		// both the raw method AND the override header.
		await page.route( '**', async ( route ) => {
			const decodedUrl = decodeURIComponent( route.request().url() );
			const method = route.request().method();
			const override = route.request().headers()[
				'x-http-method-override'
			];
			const effectiveMethod = override || method;

			// Match /event-automations/1 for PATCH and individual GET.
			if (
				/sd-ai-agent\/v1\/event-automations\/1\b/.test(
					decodedUrl
				)
			) {
				if ( effectiveMethod === 'PATCH' ) {
					patchCalled = true;
					patchBody = JSON.parse(
						route.request().postData() || '{}'
					);
					return route.fulfill( {
						status: 200,
						contentType: 'application/json',
						body: JSON.stringify( { success: true } ),
					} );
				}
				// GET /event-automations/1 — return disabled after PATCH.
				return route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify(
						patchCalled
							? { ...MOCK_EVENT, enabled: false }
							: MOCK_EVENT
					),
				} );
			}

			// Match /event-automations list endpoint (fetchAll after PATCH).
			if (
				patchCalled &&
				/sd-ai-agent\/v1\/event-automations\b/.test(
					decodedUrl
				) &&
				! /\/event-automations\/\d/.test( decodedUrl ) &&
				effectiveMethod === 'GET'
			) {
				return route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( [
						{ ...MOCK_EVENT, enabled: false },
					] ),
				} );
			}

			return route.fallback();
		} );

		await goToEventsTab( page );

		const card = page
			.locator( '.sdaa-skill-card' )
			.filter( { hasText: MOCK_EVENT.name } );

		// Wait for the card to be in the DOM before accessing sub-elements.
		await expect( card ).toBeVisible( { timeout: 10_000 } );

		// WordPress ToggleControl renders an opacity:0 <input> that covers
		// the entire .components-form-toggle area. Use evaluate() to trigger
		// the native click in the page's JS context — this properly fires
		// React's synthetic onChange handler, unlike click({ force: true })
		// which bypasses the browser's native event dispatch for opacity:0
		// elements.
		const checkbox = card
			.locator( 'input.components-form-toggle__input' )
			.first();

		// Verify the toggle starts in the checked/enabled state.
		await expect( checkbox ).toBeChecked();

		// Trigger the click via evaluate() so React's onChange fires correctly.
		await checkbox.evaluate( ( el ) => el.click() );

		// PATCH should have been called with enabled: false.
		await expect
			.poll( () => patchCalled, { timeout: 5_000 } )
			.toBe( true );
		expect( patchBody ).toMatchObject( { enabled: false } );

		// After fetchAll() the toggle should reflect the disabled state.
		await expect( checkbox ).not.toBeChecked( { timeout: 5_000 } );
	} );

	test( 'disabled event card has disabled CSS class', async ( { page } ) => {
		const disabledEvent = { ...MOCK_EVENT, enabled: false };
		await mockAutomationRoutes( page, {
			eventAutomations: [ disabledEvent ],
		} );

		await goToEventsTab( page );

		const card = page
			.locator( '.sdaa-skill-card' )
			.filter( { hasText: disabledEvent.name } );

		await expect( card ).toHaveClass( /sdaa-skill-card--disabled/ );
	} );

	test( 'empty state shows when no event automations exist', async ( {
		page,
	} ) => {
		await mockAutomationRoutes( page, { eventAutomations: [] } );

		await goToEventsTab( page );

		await expect(
			page.getByText( 'No event automations configured yet.' )
		).toBeVisible();
	} );

	test( 'Cancel button hides the event creation form', async ( { page } ) => {
		await goToEventsTab( page );

		await page.getByRole( 'button', { name: /add event/i } ).click();

		const form = page.locator( '.sdaa-skill-form' );
		await expect( form ).toBeVisible();

		await form.getByRole( 'button', { name: /cancel/i } ).click();

		await expect( form ).not.toBeVisible();
	} );
} );

// ---------------------------------------------------------------------------
// Proactive Alert Badge on FAB
// ---------------------------------------------------------------------------

test.describe( 'Proactive Alert Badge on FAB', () => {
	test.beforeEach( async ( { page } ) => {
		// Install a default mock BEFORE login so the floating widget's initial
		// fetchAlerts() call (triggered when /wp-admin/ loads after login) is
		// intercepted rather than hitting the real backend.
		await mockAutomationRoutes( page );
		await loginToWordPress( page );
	} );

	test( 'FAB badge is hidden when alert count is zero', async ( { page } ) => {
		// Override with test-specific alerts mock (registered after beforeEach mock;
		// Playwright evaluates route handlers LIFO so this takes precedence).
		await mockAutomationRoutes( page, {
			alerts: { count: 0, alerts: [] },
		} );

		await goToAdminDashboard( page );

		const fab = page.locator( '.sdaa-w-launcher' );
		await expect( fab ).toBeVisible();

		// Badge should not be present when count is 0.
		const badge = fab.locator( '.sdaa-w-launcher-badge' );
		await expect( badge ).toHaveCount( 0 );
	} );

	test( 'FAB badge shows alert count when alerts exist', async ( { page } ) => {
		await mockAutomationRoutes( page, {
			alerts: {
				count: 3,
				alerts: [
					{ type: 'warning', message: 'Plugin updates available.' },
					{ type: 'warning', message: 'Debug mode is enabled.' },
					{ type: 'info', message: 'Disk usage above 80%.' },
				],
			},
		} );

		await goToAdminDashboard( page );

		const fab = page.locator( '.sdaa-w-launcher' );
		await expect( fab ).toBeVisible();

		// Badge should appear with the count.
		const badge = fab.locator( '.sdaa-w-launcher-badge' );
		await expect( badge ).toBeVisible( { timeout: 10_000 } );
		await expect( badge ).toContainText( '3' );
	} );

	test( 'FAB badge shows "9+" when alert count exceeds 9', async ( {
		page,
	} ) => {
		await mockAutomationRoutes( page, {
			alerts: {
				count: 12,
				alerts: Array.from( { length: 12 }, ( _, i ) => ( {
					type: 'warning',
					message: `Alert ${ i + 1 }`,
				} ) ),
			},
		} );

		await goToAdminDashboard( page );

		// Use 20 s — the badge appears after fetchAlerts() resolves, which can
		// take longer than 10 s on CI runners under load with 3 parallel workers.
		const badge = page.locator( '.sdaa-w-launcher-badge' );
		await expect( badge ).toBeVisible( { timeout: 20_000 } );
		await expect( badge ).toContainText( '9+' );
	} );

	test( 'FAB badge has accessible aria-label with alert count', async ( {
		page,
	} ) => {
		await mockAutomationRoutes( page, {
			alerts: { count: 2, alerts: [] },
		} );

		await goToAdminDashboard( page );

		const badge = page.locator( '.sdaa-w-launcher-badge' );
		await expect( badge ).toBeVisible( { timeout: 10_000 } );

		// aria-label should include the count for screen readers.
		const ariaLabel = await badge.getAttribute( 'aria-label' );
		expect( ariaLabel ).toMatch( /2\s*alert/i );
	} );
} );
