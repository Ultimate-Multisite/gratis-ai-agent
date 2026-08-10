/**
 * E2E tests for the white-label branding settings (t140 / issue #653).
 *
 * Covers the branding panel shipped in t075:
 *   - Branding settings fields are present (agent name, primary color, logo URL)
 *   - Saving a custom agent name reflects in the live preview title bar
 *   - Saving a custom primary color updates the live preview FAB/title-bar style
 *   - Entering a logo URL shows the logo image in the live preview
 *   - Resetting fields to empty restores the default preview values
 *
 * Tests run against the settings page at:
 *   /wp-admin/admin.php?page=sd-ai-agent#/settings
 *
 * The branding panel lives on the "Branding" tab of the settings page.
 * REST API calls to /sd-ai-agent/v1/settings are intercepted so tests
 * are deterministic and do not require a live WordPress database.
 *
 * IMPORTANT: mockSettingsApi() must be called BEFORE loginToWordPress() in
 * every beforeEach block. The admin dashboard (loaded after login) renders
 * the floating widget which calls fetchSettings(). If the mock is not yet
 * registered at that point the request hits the real server, which may
 * return stale data from a previous test and corrupt subsequent assertions.
 *
 * Run: pnpm run test:e2e:playwright
 */

const { test, expect } = require( '@playwright/test' );
const { loginToWordPress, goToSettingsPage } = require( './utils/wp-admin' );

// ---------------------------------------------------------------------------
// URL decode helper (same pattern as agent-builder.spec.js)
// ---------------------------------------------------------------------------

/**
 * Decode a Playwright URL object to its full decoded string.
 *
 * wp-env uses the index.php?rest_route= format (pretty permalinks disabled),
 * so REST API paths appear URL-encoded in the URL string.
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
// Fixtures — deterministic settings data returned by mocked REST responses.
// ---------------------------------------------------------------------------

const DEFAULT_SETTINGS = {
	agent_name: '',
	brand_primary_color: '',
	brand_text_color: '',
	brand_logo_url: '',
	greeting_message: '',
};

const BRANDED_SETTINGS = {
	agent_name: 'Acme Support Bot',
	brand_primary_color: '#e63946',
	brand_text_color: '#ffffff',
	brand_logo_url: 'https://example.com/logo.png',
	greeting_message: 'Hello! How can I help you today?',
};

// ---------------------------------------------------------------------------
// REST mock helpers
// ---------------------------------------------------------------------------

/**
 * Intercept all /sd-ai-agent/v1/settings REST calls and return controlled
 * fixture data. This makes tests deterministic without a live WP database.
 *
 * MUST be called before any page.goto() / loginToWordPress() call so that
 * requests made during the admin dashboard load are also intercepted.
 *
 * @param {import('@playwright/test').Page} page
 * @param {Object}                          [initialSettings=DEFAULT_SETTINGS] - Settings returned by GET.
 */
async function mockSettingsApi( page, initialSettings = DEFAULT_SETTINGS ) {
	// Mutable copy so POST mutations are reflected in subsequent GETs.
	let settings = { ...initialSettings };

	await page.route(
		( url ) => decodeUrl( url ).includes( 'sd-ai-agent/v1/settings' ),
		async ( route ) => {
			const rawMethod = route.request().method();
			const overrideHeader =
				route.request().headers()[ 'x-http-method-override' ] || '';
			const method = overrideHeader.toUpperCase() || rawMethod;

			// POST /settings (save)
			if ( method === 'POST' || method === 'PUT' || method === 'PATCH' ) {
				try {
					const body = JSON.parse( route.request().postData() || '{}' );
					settings = { ...settings, ...body };
				} catch {
					// Ignore parse errors — return current settings.
				}
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( settings ),
				} );
				return;
			}

			// GET /settings
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( settings ),
			} );
		}
	);

	// Stub agents endpoint (used by the floating widget on every admin page).
	await page.route(
		( url ) => decodeUrl( url ).includes( 'sd-ai-agent/v1/agents' ),
		async ( route ) => {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( [] ),
			} );
		}
	);
}

// ---------------------------------------------------------------------------
// Navigation helper
// ---------------------------------------------------------------------------

/**
 * Navigate to the Branding tab on the settings page and wait for the
 * BrandingManager component to be visible.
 *
 * @param {import('@playwright/test').Page} page
 */
async function goToBrandingTab( page ) {
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
	// Click the Branding tab (present in the unified settings route).
	const tab = page.getByRole( 'tab', { name: /branding/i } );
	await tab.click();
	await page
		.locator( '.sdaa-branding-manager' )
		.waitFor( { state: 'visible', timeout: 15_000 } );
}

// ---------------------------------------------------------------------------
// Locator helpers
// ---------------------------------------------------------------------------

/**
 * Get the branding manager container.
 *
 * @param {import('@playwright/test').Page} page
 * @return {import('@playwright/test').Locator}
 */
function getBrandingManager( page ) {
	return page.locator( '.sdaa-branding-manager' );
}

/**
 * Get the live preview container.
 *
 * Scoped to the branding manager to avoid matching branding preview elements
 * rendered by the floating widget on the same page. The floating widget
 * renders its own BrandingPreview instance (hidden in the compact panel),
 * which causes strict-mode violations when the locator matches multiple elements.
 *
 * @param {import('@playwright/test').Page} page
 * @return {import('@playwright/test').Locator}
 */
function getBrandingPreview( page ) {
	return getBrandingManager( page ).locator(
		'.sdaa-branding-preview'
	);
}

/**
 * Get the preview title bar element.
 *
 * Scoped to the branding manager to avoid matching floating widget instances.
 *
 * @param {import('@playwright/test').Page} page
 * @return {import('@playwright/test').Locator}
 */
function getPreviewTitleBar( page ) {
	return getBrandingManager( page ).locator(
		'.sdaa-branding-preview__titlebar'
	);
}

/**
 * Get the preview FAB element.
 *
 * Scoped to the branding manager to avoid matching floating widget instances.
 *
 * @param {import('@playwright/test').Page} page
 * @return {import('@playwright/test').Locator}
 */
function getPreviewFab( page ) {
	return getBrandingManager( page ).locator(
		'.sdaa-branding-preview__fab'
	);
}

/**
 * Get the "Save Settings" button.
 *
 * @param {import('@playwright/test').Page} page
 * @return {import('@playwright/test').Locator}
 */
function getSaveButton( page ) {
	return page.getByRole( 'button', { name: /Save Settings/i } );
}

// ---------------------------------------------------------------------------
// Test suites
// ---------------------------------------------------------------------------

test.describe( 'White-Label Branding - Settings Fields', () => {
	test.beforeEach( async ( { page } ) => {
		// Register mocks BEFORE login so the floating widget's fetchSettings()
		// call (made during the admin dashboard load) is also intercepted.
		await mockSettingsApi( page, DEFAULT_SETTINGS );
		await loginToWordPress( page );
		await goToBrandingTab( page );
	} );

	test( 'branding manager section is visible on the Branding tab', async ( {
		page,
	} ) => {
		await expect( getBrandingManager( page ) ).toBeVisible();
	} );

	test( 'agent display name field is present and accepts input', async ( {
		page,
	} ) => {
		const manager = getBrandingManager( page );
		const nameField = manager.getByLabel( /Agent Display Name/i );
		await expect( nameField ).toBeVisible();
		await nameField.fill( 'My Custom Agent' );
		await expect( nameField ).toHaveValue( 'My Custom Agent' );
	} );

	test( 'primary brand color field is present and accepts a hex value', async ( {
		page,
	} ) => {
		const manager = getBrandingManager( page );
		// The primary color TextControl has id="sdaa-brand-primary-color".
		const colorField = manager.locator(
			'#sdaa-brand-primary-color'
		);
		await expect( colorField ).toBeVisible();
		await colorField.fill( '#e63946' );
		await expect( colorField ).toHaveValue( '#e63946' );
	} );

	test( 'logo / avatar URL field is present and accepts a URL', async ( {
		page,
	} ) => {
		const manager = getBrandingManager( page );
		const logoField = manager.getByLabel( /Logo \/ Avatar URL/i );
		await expect( logoField ).toBeVisible();
		await logoField.fill( 'https://example.com/logo.png' );
		await expect( logoField ).toHaveValue( 'https://example.com/logo.png' );
	} );

	test( 'greeting message field is present and accepts text', async ( {
		page,
	} ) => {
		const manager = getBrandingManager( page );
		const greetingField = manager.getByLabel( /Greeting Message/i );
		await expect( greetingField ).toBeVisible();
		await greetingField.fill( 'Hello! How can I help?' );
		await expect( greetingField ).toHaveValue( 'Hello! How can I help?' );
	} );

	test( '"Save Settings" button is visible on the Branding tab', async ( {
		page,
	} ) => {
		await expect( getSaveButton( page ) ).toBeVisible();
	} );
} );

test.describe( 'White-Label Branding - Live Preview', () => {
	test.beforeEach( async ( { page } ) => {
		await mockSettingsApi( page, DEFAULT_SETTINGS );
		await loginToWordPress( page );
		await goToBrandingTab( page );
	} );

	test( 'live preview section is visible', async ( { page } ) => {
		await expect( getBrandingPreview( page ) ).toBeVisible();
	} );

	test( 'entering a custom agent name updates the preview title bar', async ( {
		page,
	} ) => {
		const manager = getBrandingManager( page );
		const nameField = manager.getByLabel( /Agent Display Name/i );

		await nameField.fill( 'Acme Support Bot' );

		// The preview title bar should reflect the typed name immediately
		// (controlled component — no save required for live preview).
		const titleBar = getPreviewTitleBar( page );
		await expect( titleBar ).toContainText( 'Acme Support Bot' );
	} );

	test( 'entering a custom primary color updates the preview FAB background', async ( {
		page,
	} ) => {
		const manager = getBrandingManager( page );
		const colorField = manager.locator(
			'#sdaa-brand-primary-color'
		);

		await colorField.fill( '#e63946' );

		// The preview FAB should have the custom background color applied as
		// an inline style. The BrandingPreview component uses the value directly.
		const fab = getPreviewFab( page );
		await expect( fab ).toBeVisible();
		// Verify the inline style contains the custom color.
		// Browsers normalise hex colours to rgb() in computed/inline styles,
		// so check for either the hex value or its rgb() equivalent.
		const fabStyle = await fab.getAttribute( 'style' );
		const hasHex = fabStyle && fabStyle.includes( '#e63946' );
		const hasRgb = fabStyle && fabStyle.includes( 'rgb(230, 57, 70)' );
		expect( hasHex || hasRgb ).toBe( true );
	} );

	test( 'entering a logo URL shows the logo image in the preview', async ( {
		page,
	} ) => {
		const manager = getBrandingManager( page );
		const logoField = manager.getByLabel( /Logo \/ Avatar URL/i );

		await logoField.fill( 'https://example.com/logo.png' );

		// The preview should render an <img> element with the logo URL.
		// The img is inside the __fab preview circle (aria-hidden="true") which
		// is a visual-only element. We verify the img is attached to the DOM
		// with the correct src rather than checking CSS visibility, since the
		// img is inside an aria-hidden container that some environments treat
		// as visually hidden.
		const previewLogo = getBrandingPreview( page ).locator(
			'.sdaa-branding-preview__logo'
		);
		await expect( previewLogo ).toBeAttached();
		await expect( previewLogo ).toHaveAttribute(
			'src',
			'https://example.com/logo.png'
		);
	} );

	test( 'clearing the agent name restores the default "SD AI Agent" label in the preview', async ( {
		page,
	} ) => {
		const manager = getBrandingManager( page );
		const nameField = manager.getByLabel( /Agent Display Name/i );

		// First set a custom name.
		await nameField.fill( 'Custom Name' );
		await expect( getPreviewTitleBar( page ) ).toContainText( 'Custom Name' );

		// Clear the field — preview should fall back to the default "SD AI Agent".
		await nameField.fill( '' );
		await expect( getPreviewTitleBar( page ) ).toContainText( 'SD AI Agent' );
	} );
} );

test.describe( 'White-Label Branding - Save and Persist', () => {
	test.beforeEach( async ( { page } ) => {
		await mockSettingsApi( page, DEFAULT_SETTINGS );
		await loginToWordPress( page );
		await goToBrandingTab( page );
	} );

	test( 'saving branding settings shows a success notice', async ( {
		page,
	} ) => {
		const manager = getBrandingManager( page );

		// Fill in branding fields.
		await manager
			.getByLabel( /Agent Display Name/i )
			.fill( BRANDED_SETTINGS.agent_name );
		await manager
			.locator( '#sdaa-brand-primary-color' )
			.fill( BRANDED_SETTINGS.brand_primary_color );

		await getSaveButton( page ).click();

		// A success snackbar should appear after saving (SettingsApp uses SnackbarList).
		await expect(
			page
				.locator( '.components-snackbar' )
				.filter( { hasText: /saved/i } )
		).toBeVisible( { timeout: 10000 } );
	} );

	test( 'saved agent name persists after navigating away and back', async ( {
		page,
	} ) => {
		const manager = getBrandingManager( page );
		const nameField = manager.getByLabel( /Agent Display Name/i );

		await nameField.fill( BRANDED_SETTINGS.agent_name );
		await getSaveButton( page ).click();

		// Wait for save to complete (SettingsApp uses SnackbarList).
		await expect(
			page
				.locator( '.components-snackbar' )
				.filter( { hasText: /saved/i } )
		).toBeVisible( { timeout: 10000 } );

		// Navigate away and return to the Branding tab.
		// The mock will return the saved value on the next GET /settings.
		await goToBrandingTab( page );

		// The field should be pre-populated with the saved value.
		await expect(
			getBrandingManager( page ).getByLabel( /Agent Display Name/i )
		).toHaveValue( BRANDED_SETTINGS.agent_name );
	} );
} );

test.describe( 'White-Label Branding - Color Picker', () => {
	test.beforeEach( async ( { page } ) => {
		await mockSettingsApi( page, DEFAULT_SETTINGS );
		await loginToWordPress( page );
		await goToBrandingTab( page );
	} );

	test( '"Pick" button toggles the primary color picker open and closed', async ( {
		page,
	} ) => {
		const manager = getBrandingManager( page );

		// Find the "Pick" button for the primary color field.
		// It is the first "Pick" button in the branding manager.
		const pickButtons = manager.getByRole( 'button', { name: /Pick/i } );
		const primaryPickBtn = pickButtons.first();

		// Color picker should not be visible initially.
		await expect(
			manager.locator( '.components-color-picker' ).first()
		).not.toBeVisible();

		// Click "Pick" to open the color picker.
		await primaryPickBtn.click();
		await expect(
			manager.locator( '.components-color-picker' ).first()
		).toBeVisible();

		// Click "Close" to dismiss it.
		const closeBtn = manager.getByRole( 'button', { name: /Close/i } ).first();
		await closeBtn.click();
		await expect(
			manager.locator( '.components-color-picker' ).first()
		).not.toBeVisible();
	} );

	test( 'opening the primary color picker closes the text color picker', async ( {
		page,
	} ) => {
		const manager = getBrandingManager( page );
		const pickButtons = manager.getByRole( 'button', { name: /Pick/i } );

		// Open the text color picker (second "Pick" button).
		await pickButtons.nth( 1 ).click();
		await expect(
			manager.locator( '.components-color-picker' )
		).toHaveCount( 1 );

		// Open the primary color picker (first "Pick" button) — text picker should close.
		await pickButtons.first().click();
		// Only one picker should be open at a time.
		await expect(
			manager.locator( '.components-color-picker' )
		).toHaveCount( 1 );
	} );
} );
