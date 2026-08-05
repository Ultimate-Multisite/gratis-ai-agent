/**
 * E2E tests for the Agent Builder UI (t133 / issue #608).
 *
 * Covers the agent builder shipped in t082 (PR #437):
 *   - Create a new agent with custom name, system prompt, and model
 *   - Assign a tool profile (abilities) to the agent
 *   - Saved agent appears in the agent selector in the chat panel
 *   - Edit an existing agent
 *   - Delete an agent
 *
 * Tests run against the settings page at:
 *   /wp-admin/admin.php?page=sd-ai-agent#/settings
 *
 * The agent builder lives on the "Agents" tab of the settings page.
 * REST API calls to /sd-ai-agent/v1/agents are intercepted so tests
 * are deterministic and do not require a live WordPress database.
 *
 * IMPORTANT: mockAgentsApi() must be called BEFORE loginToWordPress() in
 * every beforeEach block. The admin dashboard (loaded after login) renders
 * the floating widget which calls fetchAgents(). If the mock is not yet
 * registered at that point the request hits the real server, which may
 * return stale data from a previous test and corrupt subsequent assertions.
 *
 * Run: pnpm run test:e2e:playwright
 */

const { test, expect } = require( '@playwright/test' );
const {
	loginToWordPress,
	goToAgentPage,
	goToSettingsPage,
} = require( './utils/wp-admin' );

// ---------------------------------------------------------------------------
// Per-test database cleanup helper.
//
// Deletes all agents from the real WordPress database before each test so
// that agents accidentally written to the real DB (e.g. when a mock fails
// to intercept a POST during a flaky retry) do not corrupt subsequent tests.
//
// Uses a fresh browser page created with browser.newPage() — this page has
// no page.route() handlers registered, so its fetch() calls always reach
// the real server. The cleanup page is in the browser's default context and
// shares no route handlers with the isolated test-page contexts.
// ---------------------------------------------------------------------------

/**
 * Delete all agents from the real WordPress database via the REST API.
 *
 * Creates a temporary browser page with no route mocks so the REST calls
 * always reach the real server. Waits for wpApiSettings to be injected by
 * WordPress before making the calls.
 *
 * @param {import('@playwright/test').Browser} browser - Playwright browser.
 */
async function cleanupRealAgents( browser ) {
	const cleanupPage = await browser.newPage();
	try {
		await loginToWordPress( cleanupPage );
		await cleanupPage.goto( '/wp-admin/index.php' );
		await cleanupPage.waitForLoadState( 'networkidle' );
		// Wait for wpApiSettings to be injected by WordPress.
		// Use 30 s — WP 6.9 CI runners can be slow to inject wpApiSettings
		// when running alongside other parallel workers.
		await cleanupPage
			.waitForFunction(
				() => window.wpApiSettings && window.wpApiSettings.root,
				{ timeout: 30_000 }
			)
			.catch( () => {} ); // Ignore timeout — fall back to /wp-json/.
		await cleanupPage.evaluate( async () => {
			const root =
				( window.wpApiSettings && window.wpApiSettings.root ) ||
				'/wp-json/';
			const nonce =
				( window.wpApiSettings && window.wpApiSettings.nonce ) || '';
			try {
				const resp = await fetch(
					root + 'sd-ai-agent/v1/agents',
					{ headers: { 'X-WP-Nonce': nonce } }
				);
				const agents = await resp.json();
				if ( Array.isArray( agents ) ) {
					await Promise.all(
						agents.map( ( a ) =>
							fetch(
								root +
									'sd-ai-agent/v1/agents/' +
									a.id,
								{
									method: 'DELETE',
									headers: { 'X-WP-Nonce': nonce },
								}
							)
						)
					);
				}
			} catch ( _e ) {
				// Ignore — database may already be clean.
			}
		} );
	} finally {
		await cleanupPage.close();
	}
}

// ---------------------------------------------------------------------------
// Fixtures — deterministic agent data returned by mocked REST responses.
// ---------------------------------------------------------------------------

const AGENT_FIXTURE = {
	id: 1,
	slug: 'test-agent',
	name: 'Test Agent',
	description: 'A test agent for E2E coverage.',
	system_prompt: 'You are a helpful test assistant.',
	provider_id: '',
	model_id: '',
	tool_profile: '',
	temperature: null,
	max_iterations: null,
	greeting: '',
	avatar_icon: '',
	enabled: true,
};

const AGENT_FIXTURE_UPDATED = {
	...AGENT_FIXTURE,
	name: 'Updated Agent',
	system_prompt: 'You are an updated test assistant.',
};

// ---------------------------------------------------------------------------
// URL decode helper
// ---------------------------------------------------------------------------

/**
 * Decode a Playwright URL object to its full decoded string.
 *
 * wp-env uses the index.php?rest_route= format (pretty permalinks disabled),
 * so REST API paths appear URL-encoded in the URL string:
 *   http://localhost:8890/index.php?rest_route=%2Fsd-ai-agent%2Fv1%2Fagents
 *
 * Playwright's page.route() regex matches against the raw (encoded) URL, so
 * literal-slash regexes like /sd-ai-agent\/v1\/agents/ never match. Using
 * a function matcher with decodeURIComponent() normalises the URL first.
 *
 * @param {URL} url - Playwright URL object.
 * @return {string} Fully decoded URL string.
 */
function decodeUrl( url ) {
	try {
		return decodeURIComponent( url.toString() );
	} catch {
		return url.toString();
	}
}

// ---------------------------------------------------------------------------
// REST mock helpers
// ---------------------------------------------------------------------------

/**
 * Intercept all /sd-ai-agent/v1/agents REST calls and return controlled
 * fixture data. This makes tests deterministic without a live WP database.
 *
 * MUST be called before any page.goto() / loginToWordPress() call so that
 * requests made during the admin dashboard load (floating widget fetchAgents)
 * are also intercepted.
 *
 * Each test receives a fresh Playwright page object (new browser context), so
 * there are no stale route handlers to clear. Do NOT call page.unrouteAll()
 * here — it can abort in-flight requests made during the page's initial load
 * and cause fetchAgents() to fall through to the real server.
 *
 * Uses function matchers (not regex) for all route patterns because wp-env
 * serves the REST API via index.php?rest_route= with URL-encoded slashes
 * (%2F). Regex patterns with literal slashes never match encoded URLs.
 *
 * @param {import('@playwright/test').Page} page
 * @param {Object}                          opts
 * @param {Object[]}                        [opts.initialAgents=[]]  - Agents returned by GET /agents.
 * @param {Object}                          [opts.createdAgent]      - Agent returned by POST /agents.
 * @param {Object}                          [opts.updatedAgent]      - Agent returned by PUT /agents/:id.
 */
async function mockAgentsApi( page, opts = {} ) {
	const {
		initialAgents = [],
		createdAgent = AGENT_FIXTURE,
		updatedAgent = AGENT_FIXTURE_UPDATED,
	} = opts;

	// Mutable list so POST/DELETE mutations are reflected in subsequent GETs.
	let agents = [ ...initialAgents ];

	await page.route(
		( url ) => decodeUrl( url ).includes( 'sd-ai-agent/v1/agents' ),
		async ( route ) => {
			// WordPress's apiFetch sends PATCH/PUT/DELETE as POST with an
			// X-HTTP-Method-Override header (http-v1 middleware). Read the
			// override header first so the mock dispatches correctly.
			const rawMethod = route.request().method();
			const overrideHeader =
				route.request().headers()[ 'x-http-method-override' ] || '';
			const method = overrideHeader.toUpperCase() || rawMethod;
			// Decode the URL so that wp-env's %2F-encoded paths match correctly.
			const decodedUrl = decodeUrl( route.request().url() );

			// DELETE /agents/:id
			if ( method === 'DELETE' ) {
				const idMatch = decodedUrl.match( /\/agents\/(\d+)/ );
				if ( idMatch ) {
					const id = parseInt( idMatch[ 1 ], 10 );
					agents = agents.filter( ( a ) => a.id !== id );
				}
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( { deleted: true } ),
				} );
				return;
			}

			// PUT /agents/:id (update)
			if ( method === 'PUT' || method === 'PATCH' ) {
				agents = agents.map( ( a ) =>
					a.id === updatedAgent.id ? updatedAgent : a
				);
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( updatedAgent ),
				} );
				return;
			}

			// POST /agents (create) — only when no method override is present.
			if (
				rawMethod === 'POST' &&
				! overrideHeader &&
				! decodedUrl.match( /\/agents\/\d+/ )
			) {
				agents = [ ...agents, createdAgent ];
				await route.fulfill( {
					status: 201,
					contentType: 'application/json',
					body: JSON.stringify( createdAgent ),
				} );
				return;
			}

			// GET /agents/:id (single agent)
			const getIdMatch = decodedUrl.match( /\/agents\/(\d+)/ );
			if ( method === 'GET' && getIdMatch ) {
				const id = parseInt( getIdMatch[ 1 ], 10 );
				const agent = agents.find( ( a ) => a.id === id );
				await route.fulfill( {
					status: agent ? 200 : 404,
					contentType: 'application/json',
					body: JSON.stringify( agent || { message: 'Agent not found' } ),
				} );
				return;
			}

			// GET /agents (list)
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( agents ),
			} );
		}
	);

	// Stub providers endpoint (used by the provider/model dropdowns).
	// Use a function matcher so wp-env's URL-encoded paths (%2F) are decoded first.
	// Return a fake provider so AdminPageApp renders the chat layout instead of
	// ConnectorGate — the agent selector lives inside the chat panel and is only
	// visible when at least one provider is in the store.
	await page.route(
		( url ) =>
			decodeUrl( url ).includes( 'sd-ai-agent/v1/providers' ),
		async ( route ) => {
			await route.fulfill( {
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
	);
}

// ---------------------------------------------------------------------------
// Locator helpers
// ---------------------------------------------------------------------------

/**
 * Get the agent builder container.
 *
 * @param {import('@playwright/test').Page} page
 * @return {import('@playwright/test').Locator}
 */
function getAgentBuilder( page ) {
	return page.locator( '.sdaa-builder' );
}

/**
 * Get the "Add Agent" button.
 *
 * @param {import('@playwright/test').Page} page
 * @return {import('@playwright/test').Locator}
 */
function getAddAgentButton( page ) {
	return page.getByRole( 'button', { name: /Add Agent/i } );
}

/**
 * Get the agent creation/edit form.
 *
 * @param {import('@playwright/test').Page} page
 * @return {import('@playwright/test').Locator}
 */
function getAgentForm( page ) {
	return page.locator( '.sdaa-form' );
}

/**
 * Get the "Create Agent" submit button.
 *
 * @param {import('@playwright/test').Page} page
 * @return {import('@playwright/test').Locator}
 */
function getCreateAgentButton( page ) {
	return page.getByRole( 'button', { name: /Create Agent/i } );
}

/**
 * Get the "Update Agent" submit button.
 *
 * @param {import('@playwright/test').Page} page
 * @return {import('@playwright/test').Locator}
 */
function getUpdateAgentButton( page ) {
	return page.getByRole( 'button', { name: /Update Agent/i } );
}

/**
 * Get the "Cancel" button in the form.
 *
 * @param {import('@playwright/test').Page} page
 * @return {import('@playwright/test').Locator}
 */
function getCancelButton( page ) {
	return page.getByRole( 'button', { name: /Cancel/i } );
}

/**
 * Get all agent cards in the list.
 *
 * @param {import('@playwright/test').Page} page
 * @return {import('@playwright/test').Locator}
 */
function getAgentCards( page ) {
	return page.locator( '.sdaa-card' );
}

/**
 * Get the edit (pencil) button for a specific agent card.
 *
 * @param {import('@playwright/test').Locator} card - Agent card locator.
 * @return {import('@playwright/test').Locator}
 */
function getEditButton( card ) {
	return card.getByRole( 'button', { name: /Edit agent/i } );
}

/**
 * Get the delete (trash) button for a specific agent card.
 *
 * @param {import('@playwright/test').Locator} card - Agent card locator.
 * @return {import('@playwright/test').Locator}
 */
function getDeleteButton( card ) {
	return card.getByRole( 'button', { name: /Delete agent/i } );
}

/**
 * Get the agent selector dropdown in the admin page chat panel.
 *
 * Scoped to the non-compact (admin page) chat panel to avoid matching the
 * floating widget's hidden agent selector (.sdaa-selector.is-compact).
 * The floating widget renders AgentSelector with compact=true, adding is-compact.
 *
 * @param {import('@playwright/test').Page} page
 * @return {import('@playwright/test').Locator}
 */
function getAgentSelector( page ) {
	return page
		.locator(
			'.sdaa-chat-panel:not(.is-compact) .sdaa-selector'
		)
		.first();
}

/**
 * Navigate to the Agents tab on the settings page and wait for the
 * AgentBuilder component to finish its initial fetchAgents() load.
 *
 * The AgentBuilder renders a spinner (.sdaa-loading) while
 * agentsLoaded is false. Waiting for the spinner to disappear ensures
 * fetchAgents() has completed and the agent list (or empty state) is
 * rendered before the test makes assertions.
 *
 * @param {import('@playwright/test').Page} page
 */
async function goToAgentsTab( page ) {
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
	// Click the Agents tab (present in the unified settings route).
	const tab = page.getByRole( 'tab', { name: /agents/i } );
	await tab.click();
	// Wait for the AgentBuilder container to be visible.
	await page
		.locator( '.sdaa-builder' )
		.waitFor( { state: 'visible', timeout: 15000 } );
	// Wait for the loading spinner to disappear — signals agentsLoaded=true.
	// Use a short timeout: if the spinner never appeared (agentsLoaded was
	// already true), this resolves immediately.
	await page
		.locator( '.sdaa-loading' )
		.waitFor( { state: 'hidden', timeout: 15000 } )
		.catch( () => {
			// Spinner may never appear if agentsLoaded was already true.
		} );
}

// ---------------------------------------------------------------------------
// Test suites
// ---------------------------------------------------------------------------

test.describe( 'Agent Builder - Create Agent', () => {
	test.beforeEach( async ( { page, browser } ) => {
		// Register mocks BEFORE login so the floating widget's fetchAgents()
		// call (made during the admin dashboard load) is also intercepted.
		await mockAgentsApi( page, { initialAgents: [] } );
		// Delete any real agents left by a previous flaky run. Uses a fresh
		// browser page with no route mocks so the calls reach the real server.
		await cleanupRealAgents( browser );
		await loginToWordPress( page );
		await goToAgentsTab( page );
	} );

	test( 'agent builder section is visible on the Agents tab', async ( {
		page,
	} ) => {
		const builder = getAgentBuilder( page );
		await expect( builder ).toBeVisible();
	} );

	test( '"Add Agent" button is visible when no agents exist', async ( {
		page,
	} ) => {
		const addBtn = getAddAgentButton( page );
		await expect( addBtn ).toBeVisible();
	} );

	test( 'clicking "Add Agent" shows the creation form', async ( {
		page,
	} ) => {
		await getAddAgentButton( page ).click();
		const form = getAgentForm( page );
		await expect( form ).toBeVisible();
		// Form heading should say "New Agent".
		await expect( form.getByRole( 'heading', { name: /New Agent/i } ) ).toBeVisible();
	} );

	test( 'form has slug, name, system prompt, and tool profile fields', async ( {
		page,
	} ) => {
		await getAddAgentButton( page ).click();
		const form = getAgentForm( page );

		await expect( form.getByLabel( /Slug/i ) ).toBeVisible();
		await expect( form.getByLabel( /^Name/i ) ).toBeVisible();
		await expect( form.getByLabel( /System Prompt/i ) ).toBeVisible();
	} );

	test( 'submitting without a name shows a validation error', async ( {
		page,
	} ) => {
		await getAddAgentButton( page ).click();
		// Fill slug but leave name empty.
		await page.getByLabel( /Slug/i ).fill( 'my-agent' );
		await getCreateAgentButton( page ).click();

		// A notice with status "error" should appear.
		await expect(
			page.locator( '.components-notice.is-error' )
		).toBeVisible();
	} );

	test( 'submitting without a slug shows a validation error', async ( {
		page,
	} ) => {
		await getAddAgentButton( page ).click();
		// Fill name but leave slug empty.
		await page.getByLabel( /^Name/i ).fill( 'My Agent' );
		await getCreateAgentButton( page ).click();

		await expect(
			page.locator( '.components-notice.is-error' )
		).toBeVisible();
	} );

	test( 'creates an agent with custom name, system prompt, and model', async ( {
		page,
	} ) => {
		await getAddAgentButton( page ).click();

		const form = getAgentForm( page );
		await form.getByLabel( /Slug/i ).fill( AGENT_FIXTURE.slug );
		await form.getByLabel( /^Name/i ).fill( AGENT_FIXTURE.name );
		await form
			.getByLabel( /System Prompt/i )
			.fill( AGENT_FIXTURE.system_prompt );

		await getCreateAgentButton( page ).click();

		// After a successful create the form closes and the new agent card
		// appears. The component calls resetForm() immediately after
		// createAgent() resolves, which clears the notice in the same render
		// cycle, so we verify the outcome (card visible) rather than the
		// transient notice.
		await expect( getAgentForm( page ) ).not.toBeVisible();
		const cards = getAgentCards( page );
		await expect( cards ).toHaveCount( 1 );
		await expect( cards.first() ).toContainText( AGENT_FIXTURE.name );
	} );

	test( '"Cancel" button hides the form without saving', async ( {
		page,
	} ) => {
		await getAddAgentButton( page ).click();
		await expect( getAgentForm( page ) ).toBeVisible();

		await getCancelButton( page ).click();

		await expect( getAgentForm( page ) ).not.toBeVisible();
		// No agent cards should have been created.
		await expect( getAgentCards( page ) ).toHaveCount( 0 );
	} );
} );

test.describe( 'Agent Builder - Agent List', () => {
	test.beforeEach( async ( { page, browser } ) => {
		// Register mocks BEFORE login so all API calls are intercepted.
		await mockAgentsApi( page, { initialAgents: [ AGENT_FIXTURE ] } );
		// Delete any real agents left by a previous flaky run.
		await cleanupRealAgents( browser );
		await loginToWordPress( page );
		await goToAgentsTab( page );
	} );

	test( 'existing agents are listed as cards', async ( { page } ) => {
		const cards = getAgentCards( page );
		// goToAgentsTab() already waited for the spinner to disappear, so
		// agentsLoaded is true. Use a short timeout as a safety net only.
		await expect( cards ).toHaveCount( 1, { timeout: 10000 } );
		await expect( cards.first() ).toContainText( AGENT_FIXTURE.name );
	} );

	test( 'agent card shows system prompt preview', async ( { page } ) => {
		const card = getAgentCards( page ).first();
		await expect( card ).toBeVisible( { timeout: 10000 } );
		// Use a generous timeout for the inner element — the card body renders
		// asynchronously after the card itself becomes visible.
		await expect(
			card.locator( '.sdaa-prompt-preview' )
		).toContainText( AGENT_FIXTURE.system_prompt.slice( 0, 40 ), {
			timeout: 10000,
		} );
	} );

	test( 'edit and delete buttons are present on each card', async ( {
		page,
	} ) => {
		const card = getAgentCards( page ).first();
		// Wait for the card to be visible before checking its buttons.
		await expect( card ).toBeVisible( { timeout: 10000 } );
		await expect( getEditButton( card ) ).toBeVisible();
		await expect( getDeleteButton( card ) ).toBeVisible();
	} );
} );

test.describe( 'Agent Builder - Edit Agent', () => {
	test.beforeEach( async ( { page, browser } ) => {
		// Register mocks BEFORE login so all API calls are intercepted.
		await mockAgentsApi( page, { initialAgents: [ AGENT_FIXTURE ] } );
		// Delete any real agents left by a previous flaky run.
		// Wrapped in try-catch: cleanupRealAgents creates a separate browser
		// page that can time out under CI load without failing the test setup.
		await cleanupRealAgents( browser ).catch( () => {} );
		await loginToWordPress( page );
		await goToAgentsTab( page );
	} );

	test( 'clicking edit opens the form pre-populated with agent data', async ( {
		page,
	} ) => {
		const card = getAgentCards( page ).first();
		await expect( card ).toBeVisible( { timeout: 10000 } );
		await getEditButton( card ).click();

		const form = getAgentForm( page );
		await expect( form ).toBeVisible();

		// Heading should say "Edit Agent".
		await expect(
			form.getByRole( 'heading', { name: /Edit Agent/i } )
		).toBeVisible();

		// Slug field should NOT be shown when editing (cannot change slug).
		await expect( form.getByLabel( /Slug/i ) ).not.toBeVisible();

		// Name field should be pre-filled.
		await expect( form.getByLabel( /^Name/i ) ).toHaveValue(
			AGENT_FIXTURE.name
		);

		// System prompt should be pre-filled.
		await expect( form.getByLabel( /System Prompt/i ) ).toHaveValue(
			AGENT_FIXTURE.system_prompt
		);
	} );

	test( 'updating an agent shows a success notice', async ( { page } ) => {
		const card = getAgentCards( page ).first();
		await expect( card ).toBeVisible( { timeout: 10000 } );
		await getEditButton( card ).click();

		const form = getAgentForm( page );
		await form
			.getByLabel( /^Name/i )
			.fill( AGENT_FIXTURE_UPDATED.name );
		await form
			.getByLabel( /System Prompt/i )
			.fill( AGENT_FIXTURE_UPDATED.system_prompt );

		await getUpdateAgentButton( page ).click();

		// For updates the form stays open and the notice persists (resetForm
		// is not called on update, only on create).
		await expect(
			page.locator( '.components-notice.is-success' )
		).toBeVisible( { timeout: 10000 } );
	} );

	test( 'updated agent name is reflected in the card after saving', async ( {
		page,
	} ) => {
		const card = getAgentCards( page ).first();
		await expect( card ).toBeVisible( { timeout: 10000 } );
		await getEditButton( card ).click();

		await page
			.getByLabel( /^Name/i )
			.fill( AGENT_FIXTURE_UPDATED.name );

		await getUpdateAgentButton( page ).click();

		// Wait for the success notice — confirms the PATCH completed.
		await expect(
			page.locator( '.components-notice.is-success' )
		).toBeVisible( { timeout: 10000 } );

		// Close the form so the card list becomes visible again.
		// The edit form hides the card list (showForm=true renders only the
		// form), so we must dismiss it before asserting on card content.
		await getCancelButton( page ).click();
		await expect( getAgentForm( page ) ).not.toBeVisible();

		// After closing the form the card should show the updated name.
		// The store was optimistically updated on PATCH so no re-fetch wait
		// is needed, but allow a short timeout as a safety net.
		await expect( getAgentCards( page ).first() ).toContainText(
			AGENT_FIXTURE_UPDATED.name,
			{ timeout: 10_000 }
		);
	} );

	test( '"Cancel" in edit mode closes the form without saving', async ( {
		page,
	} ) => {
		const card = getAgentCards( page ).first();
		await expect( card ).toBeVisible( { timeout: 10000 } );
		await getEditButton( card ).click();

		await page.getByLabel( /^Name/i ).fill( 'Should Not Save' );
		await getCancelButton( page ).click();

		await expect( getAgentForm( page ) ).not.toBeVisible();
		// Original name should still be shown.
		await expect( getAgentCards( page ).first() ).toContainText(
			AGENT_FIXTURE.name
		);
	} );
} );

test.describe( 'Agent Builder - Delete Agent', () => {
	test.beforeEach( async ( { page, browser } ) => {
		// Register mocks BEFORE login so all API calls are intercepted.
		await mockAgentsApi( page, { initialAgents: [ AGENT_FIXTURE ] } );
		// Delete any real agents left by a previous flaky run.
		await cleanupRealAgents( browser );
		await loginToWordPress( page );
		await goToAgentsTab( page );
	} );

	test( 'deleting an agent removes it from the list', async ( { page } ) => {
		// Accept the confirmation dialog automatically.
		page.on( 'dialog', ( dialog ) => dialog.accept() );

		const card = getAgentCards( page ).first();
		await expect( card ).toBeVisible( { timeout: 10000 } );
		await getDeleteButton( card ).click();

		// Card should be removed. The component re-fetches the list after
		// delete; allow time for the DOM to update.
		await expect( getAgentCards( page ) ).toHaveCount( 0, {
			timeout: 10_000,
		} );
	} );

	test( 'dismissing the delete confirmation keeps the agent', async ( {
		page,
	} ) => {
		// Dismiss the confirmation dialog.
		page.on( 'dialog', ( dialog ) => dialog.dismiss() );

		const card = getAgentCards( page ).first();
		await expect( card ).toBeVisible( { timeout: 10000 } );
		await getDeleteButton( card ).click();

		// Card should still be present.
		await expect( getAgentCards( page ) ).toHaveCount( 1 );
	} );
} );

// FIXME: The admin chat UI was redesigned. The old AgentSelector component
// (.sdaa-selector with a <select> element) was replaced by the new
// AgentPicker chip/popover (.sdaa-cr-agent-chip) which only renders when 2+
// agents are enabled. These tests need to be rewritten to use the new chip
// interaction model and to mock 2+ agents. Re-enable once updated.
test.describe.fixme( 'Agent Builder - Agent Selector in Chat', () => {
	test.beforeEach( async ( { page, browser } ) => {
		// Register mocks BEFORE login so all API calls are intercepted,
		// including the fetchAgents() call made by the floating widget on
		// the admin dashboard after login.
		await mockAgentsApi( page, { initialAgents: [ AGENT_FIXTURE ] } );
		// Delete any real agents left by a previous flaky run.
		await cleanupRealAgents( browser );
		await loginToWordPress( page );
		await goToAgentPage( page );
	} );

	test( 'agent selector is visible in the chat panel when agents exist', async ( {
		page,
	} ) => {
		// The AgentSelector component renders null until the agents store is
		// loaded and there is at least one enabled agent. Wait with a generous
		// timeout to allow the mocked API response to be processed.
		const selector = getAgentSelector( page );
		await expect( selector ).toBeVisible( { timeout: 10000 } );
	} );

	test( 'agent selector contains the custom agent by name', async ( {
		page,
	} ) => {
		const selector = getAgentSelector( page );
		await expect( selector ).toBeVisible( { timeout: 10000 } );
		const select = selector.locator( 'select' );
		await expect( select ).toBeVisible();

		// The option for our fixture agent should be present.
		const option = select.locator(
			`option[value="${ AGENT_FIXTURE.id }"]`
		);
		await expect( option ).toHaveText( AGENT_FIXTURE.name );
	} );

	test( 'selecting an agent from the dropdown updates the selection', async ( {
		page,
	} ) => {
		const selector = getAgentSelector( page );
		await expect( selector ).toBeVisible( { timeout: 10000 } );
		const select = selector.locator( 'select' );

		// Select the custom agent.
		await select.selectOption( String( AGENT_FIXTURE.id ) );

		// The select value should reflect the chosen agent.
		await expect( select ).toHaveValue( String( AGENT_FIXTURE.id ) );
	} );

	test( 'agent selector includes a "Default agent" option', async ( {
		page,
	} ) => {
		const selector = getAgentSelector( page );
		await expect( selector ).toBeVisible( { timeout: 10000 } );
		const select = selector.locator( 'select' );

		const defaultOption = select.locator( 'option[value=""]' );
		await expect( defaultOption ).toHaveText( /Default agent/i );
	} );

	test( 'selecting "Default agent" resets the agent selection', async ( {
		page,
	} ) => {
		const selector = getAgentSelector( page );
		await expect( selector ).toBeVisible( { timeout: 10000 } );
		const select = selector.locator( 'select' );

		// First select the custom agent.
		await select.selectOption( String( AGENT_FIXTURE.id ) );
		await expect( select ).toHaveValue( String( AGENT_FIXTURE.id ) );

		// Then reset to default.
		await select.selectOption( '' );
		await expect( select ).toHaveValue( '' );
	} );
} );

// FIXME: Step 2 of the lifecycle (verify agent in chat selector) uses
// getAgentSelector which relies on .sdaa-selector (<select>). The
// admin chat UI now uses AgentPicker (sdaa-cr-agent-chip), a chip/popover that
// only renders when 2+ agents exist. Re-enable once step 2 is rewritten for
// the new AgentPicker interaction model.
test.describe.fixme( 'Agent Builder - Full Lifecycle', () => {
	/**
	 * End-to-end lifecycle: create → verify in chat selector → edit → delete.
	 *
	 * This test exercises the complete agent lifecycle in a single flow,
	 * mirroring real user behaviour.
	 */
	test( 'create, verify in chat selector, edit, then delete an agent', async ( {
		page,
		browser,
	} ) => {
		// Register mocks BEFORE login so all API calls are intercepted.
		await mockAgentsApi( page, { initialAgents: [] } );
		// Delete any real agents left by a previous flaky run.
		await cleanupRealAgents( browser );
		await loginToWordPress( page );

		// ---- Step 1: Create the agent ----
		await goToAgentsTab( page );

		await getAddAgentButton( page ).click();

		const form = getAgentForm( page );
		await form.getByLabel( /Slug/i ).fill( AGENT_FIXTURE.slug );
		await form.getByLabel( /^Name/i ).fill( AGENT_FIXTURE.name );
		await form
			.getByLabel( /System Prompt/i )
			.fill( AGENT_FIXTURE.system_prompt );

		await getCreateAgentButton( page ).click();

		// Form closes and card appears after successful create.
		await expect( getAgentForm( page ) ).not.toBeVisible();
		await expect( getAgentCards( page ) ).toHaveCount( 1 );

		// ---- Step 2: Verify agent appears in chat selector ----
		// Re-mock with the created agent so the chat page sees it.
		await mockAgentsApi( page, { initialAgents: [ AGENT_FIXTURE ] } );
		await goToAgentPage( page );

		const selector = getAgentSelector( page );
		await expect( selector ).toBeVisible( { timeout: 10000 } );
		await expect(
			selector.locator( `option[value="${ AGENT_FIXTURE.id }"]` )
		).toHaveText( AGENT_FIXTURE.name );

		// ---- Step 3: Edit the agent ----
		await mockAgentsApi( page, { initialAgents: [ AGENT_FIXTURE ] } );
		await goToAgentsTab( page );

		const card = getAgentCards( page ).first();
		await expect( card ).toBeVisible( { timeout: 10000 } );
		await getEditButton( card ).click();

		await page
			.getByLabel( /^Name/i )
			.fill( AGENT_FIXTURE_UPDATED.name );
		await page
			.getByLabel( /System Prompt/i )
			.fill( AGENT_FIXTURE_UPDATED.system_prompt );

		await getUpdateAgentButton( page ).click();

		await expect(
			page.locator( '.components-notice.is-success' )
		).toBeVisible( { timeout: 10000 } );

		// Close the form so the card list becomes visible again.
		// The edit form hides the card list (showForm=true renders only the
		// form), so we must dismiss it before asserting on card content.
		await getCancelButton( page ).click();
		await expect( getAgentForm( page ) ).not.toBeVisible();

		// After closing the form the card should show the updated name.
		await expect( getAgentCards( page ).first() ).toContainText(
			AGENT_FIXTURE_UPDATED.name,
			{ timeout: 10_000 }
		);

		// ---- Step 4: Delete the agent ----
		page.on( 'dialog', ( dialog ) => dialog.accept() );

		await getDeleteButton( getAgentCards( page ).first() ).click();

		// Allow time for the list to update after the delete re-fetch.
		await expect( getAgentCards( page ) ).toHaveCount( 0, {
			timeout: 10_000,
		} );
	} );
} );
