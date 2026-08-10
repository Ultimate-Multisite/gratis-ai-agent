/**
 * E2E tests for the Superdav AI Agent floating widget.
 *
 * Tests the launcher button and floating panel that appear on all admin pages.
 * Requires a running wp-env environment with the plugin active.
 *
 * The chat widget was redesigned in #1157. Class names changed:
 *   .sdaa-fab         → .sdaa-w-launcher  (WidgetLauncher)
 *   .sdaa-floating-panel → .sdaa-w-panel  (WidgetPanel)
 *   .sdaa-chat-panel  → .sdaa-w-body-wrap (panel body area)
 *   .sdaa-input       → .sdaa-w-input-textarea
 *   .sdaa-send-btn    → .sdaa-cr-send-btn
 *
 * Run: pnpm run test:e2e:playwright
 */

const { test, expect } = require( '@playwright/test' );
const {
	loginToWordPress,
	goToAdminDashboard,
	getFloatingButton,
	getFloatingPanel,
} = require( './utils/wp-admin' );

test.describe( 'Floating Widget', () => {
	test.beforeEach( async ( { page } ) => {
		await loginToWordPress( page );
		await goToAdminDashboard( page );
	} );

	test( 'FAB button is visible on admin pages', async ( { page } ) => {
		const fab = getFloatingButton( page );
		await expect( fab ).toBeVisible();
	} );

	test( 'clicking FAB opens the floating panel', async ( { page } ) => {
		const fab = getFloatingButton( page );
		const panel = getFloatingPanel( page );

		// Panel should not be visible initially (ChatWidget renders launcher OR
		// panel, never both — so the panel element is absent from the DOM).
		await expect( panel ).not.toBeVisible();

		await fab.click();

		await expect( panel ).toBeVisible();
	} );

	test( 'floating panel contains the chat UI', async ( { page } ) => {
		const fab = getFloatingButton( page );
		await fab.click();

		const panel = getFloatingPanel( page );
		// The redesigned widget panel body is .sdaa-w-body-wrap (WidgetPanel,
		// widget-panel.js). It contains WidgetEmpty or WidgetMessageList.
		const bodyWrap = panel.locator( '.sdaa-w-body-wrap' );
		await expect( bodyWrap ).toBeVisible();

		// The input area is always present when the panel is open.
		// .sdaa-w-input-textarea is the textarea inside WidgetInput.
		const input = panel.locator( '.sdaa-w-input-textarea' );
		await expect( input ).toBeVisible();
	} );

	test( 'close button hides the floating panel', async ( { page } ) => {
		const fab = getFloatingButton( page );
		await fab.click();

		const panel = getFloatingPanel( page );
		await expect( panel ).toBeVisible();

		// Click the Close button in the floating panel title bar.
		const closeButton = panel.getByLabel( 'Close' );
		await closeButton.click();

		await expect( panel ).not.toBeVisible();

		// Launcher should reappear after closing.
		await expect( fab ).toBeVisible();
	} );

	test( 'minimize button collapses the panel body', async ( { page } ) => {
		const fab = getFloatingButton( page );
		await fab.click();

		const panel = getFloatingPanel( page );
		await expect( panel ).toBeVisible();

		const minimizeButton = panel.getByLabel( 'Minimize' );
		await minimizeButton.click();

		// Panel element stays in DOM but body is hidden (is-minimized class).
		await expect( panel ).toHaveClass( /is-minimized/ );

		// Body wrap is conditionally rendered: { !isMinimized && <div.sdaa-w-body-wrap> }
		// so when minimized it is removed from the DOM entirely.
		const bodyWrap = panel.locator( '.sdaa-w-body-wrap' );
		await expect( bodyWrap ).not.toBeVisible();
	} );

	test( 'expand button restores minimized panel', async ( { page } ) => {
		const fab = getFloatingButton( page );
		await fab.click();

		const panel = getFloatingPanel( page );

		// Minimize first.
		const minimizeButton = panel.getByLabel( 'Minimize' );
		await minimizeButton.click();

		await expect( panel ).toHaveClass( /is-minimized/ );

		// Expand.
		const expandButton = panel.getByLabel( 'Expand' );
		await expandButton.click();

		await expect( panel ).not.toHaveClass( /is-minimized/ );

		// Body wrap should be visible again after expanding.
		const bodyWrap = panel.locator( '.sdaa-w-body-wrap' );
		await expect( bodyWrap ).toBeVisible();
	} );

	// All tests below scope locators to the floating panel to avoid
	// strict-mode violations when screen-meta also renders a chat panel.

	test( 'message input accepts text', async ( { page } ) => {
		const fab = getFloatingButton( page );
		await fab.click();

		const panel = getFloatingPanel( page );
		// .sdaa-w-input-textarea is the textarea in WidgetInput (widget-input.js).
		const input = panel.locator( '.sdaa-w-input-textarea' );
		await input.fill( 'Hello, AI Agent!' );

		await expect( input ).toHaveValue( 'Hello, AI Agent!' );
	} );

	test( 'send button is enabled when input has text', async ( { page } ) => {
		const fab = getFloatingButton( page );
		await fab.click();

		const panel = getFloatingPanel( page );
		// .sdaa-w-input-textarea is the textarea in WidgetInput.
		const input = panel.locator( '.sdaa-w-input-textarea' );
		// .sdaa-cr-send-btn is the send button in WidgetInput (widget-input.js).
		const sendButton = panel.locator( '.sdaa-cr-send-btn' );

		// Send button should be disabled (or absent) when input is empty.
		await expect( sendButton ).toBeDisabled();

		await input.fill( 'Test message' );

		// Send button should become enabled.
		await expect( sendButton ).toBeEnabled();
	} );

	test( 'clicking send submits without opening the agent picker', async ( {
		page,
	} ) => {
		const fab = getFloatingButton( page );
		await fab.click();

		const panel = getFloatingPanel( page );
		const input = panel.locator( '.sdaa-w-input-textarea' );
		const sendButton = panel.getByLabel( 'Send message' );
		const agentButton = panel.getByRole( 'button', { name: 'General' } );

		await input.fill( 'Say hello only.' );
		await expect( sendButton ).toBeEnabled();

		if ( await agentButton.isVisible() ) {
			const sendBox = await sendButton.boundingBox();
			const agentBox = await agentButton.boundingBox();

			expect( sendBox ).not.toBeNull();
			expect( agentBox ).not.toBeNull();
			expect( agentBox.x + agentBox.width ).toBeLessThanOrEqual(
				sendBox.x
			);
		}

		await sendButton.click();

		await expect( input ).toHaveValue( '' );
		if ( await agentButton.isVisible() ) {
			await expect( agentButton ).not.toHaveAttribute(
				'aria-expanded',
				'true'
			);
		}
	} );

	test( 'pressing Enter submits the message', async ( { page } ) => {
		const fab = getFloatingButton( page );
		await fab.click();

		const panel = getFloatingPanel( page );
		// .sdaa-w-input-textarea is the textarea in WidgetInput.
		const input = panel.locator( '.sdaa-w-input-textarea' );
		await input.fill( 'Test message via Enter' );
		await input.press( 'Enter' );

		// Input should be cleared after submission.
		await expect( input ).toHaveValue( '' );
	} );

	test( 'slash command menu stays inside narrow viewports', async ( {
		page,
	} ) => {
		const viewports = [
			{ width: 390, height: 844 },
			{ width: 320, height: 568 },
		];

		for ( const viewport of viewports ) {
			await page.setViewportSize( viewport );

			const fab = getFloatingButton( page );
			await fab.click();

			const panel = getFloatingPanel( page );
			const input = panel.locator( '.sdaa-w-input-textarea' );
			const slashMenu = panel.getByRole( 'listbox', {
				name: 'Slash commands',
			} );
			const options = slashMenu.getByRole( 'option' );

			await input.fill( '/' );
			await expect( slashMenu ).toBeVisible();
			await expect( options ).toHaveCount( 10 );

			const menuBox = await slashMenu.boundingBox();
			expect( menuBox ).not.toBeNull();
			expect( menuBox.x ).toBeGreaterThanOrEqual( 0 );
			expect( menuBox.y ).toBeGreaterThanOrEqual( 0 );
			expect( menuBox.x + menuBox.width ).toBeLessThanOrEqual(
				viewport.width
			);
			expect( menuBox.y + menuBox.height ).toBeLessThanOrEqual(
				viewport.height
			);

			await options.last().scrollIntoViewIfNeeded();
			await expect( options.last() ).toBeVisible();
			await panel.getByLabel( 'Close' ).click();
		}
	} );
} );
