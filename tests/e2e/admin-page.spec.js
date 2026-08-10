/**
 * E2E tests for the Superdav AI Agent unified admin page.
 *
 * The UnifiedAdminMenu consolidates all admin pages into a single React SPA
 * at admin.php?page=sd-ai-agent with hash-based routing:
 *   - Chat:      admin.php?page=sd-ai-agent (or #/chat)
 *   - Abilities: admin.php?page=sd-ai-agent#/abilities
 *   - Changes:   admin.php?page=sd-ai-agent#/changes
 *   - Settings:  admin.php?page=sd-ai-agent#/settings
 *
 * Run: pnpm run test:e2e:playwright
 */

const { test, expect } = require( '@playwright/test' );
const {
	loginToWordPress,
	goToAgentPage,
	goToAbilitiesPage,
	getMessageInput,
	getSendButton,
	getStopButton,
	getChatPanel,
	getMessageList,
	waitForMessageSubmitted,
} = require( './utils/wp-admin' );

test.describe( 'Admin Page - Chat UI', () => {
	test.beforeEach( async ( { page } ) => {
		await loginToWordPress( page );
		await goToAgentPage( page );
	} );

	test( 'admin page loads with correct layout', async ( { page } ) => {
		// The unified admin SPA renders .sdaa-unified-admin as the outer
		// wrapper. The AdminPageApp mounts ChatRedesign inside
		// #sdaa-chat-container. ChatRedesign uses sdaa-cr-* classes:
		//   .sdaa-cr-shell   — two-column shell  (was .sdaa-layout)
		//   .sdaa-cr-sidebar — sidebar panel     (was .sdaa-sidebar)
		//   .sdaa-cr-convo   — conversation area (was .sdaa-main)
		const chatContainer = page.locator( '#sdaa-chat-container' );
		await expect( chatContainer.locator( '.sdaa-cr-shell' ) ).toBeVisible();
		await expect( chatContainer.locator( '.sdaa-cr-sidebar' ) ).toBeVisible();
		await expect( chatContainer.locator( '.sdaa-cr-convo' ) ).toBeVisible();
	} );

	test( 'chat panel is visible on admin page', async ( { page } ) => {
		const chatPanel = getChatPanel( page );
		await expect( chatPanel ).toBeVisible();
	} );

	test( 'message input is present and focusable', async ( { page } ) => {
		const input = getMessageInput( page );
		await expect( input ).toBeVisible();
		await input.focus();
		await expect( input ).toBeFocused();
	} );

	test( 'message list container is present', async ( { page } ) => {
		const messageList = getMessageList( page );
		await expect( messageList ).toBeVisible();
	} );

	test( 'empty state is shown when no messages', async ( { page } ) => {
		// The ChatRedesign MessageList renders .sdaa-cr-empty when there are no
		// messages. Scoped to .sdaa-cr (the admin chat root) so it doesn't match
		// any other empty-state element that may be present on the page.
		const emptyState = page.locator( '.sdaa-cr .sdaa-cr-empty' );
		await expect( emptyState ).toBeVisible();
	} );

	test( 'send button is disabled when input is empty', async ( { page } ) => {
		const sendButton = getSendButton( page );
		await expect( sendButton ).toBeDisabled();
	} );

	test( 'send button enables when input has text', async ( { page } ) => {
		const input = getMessageInput( page );
		const sendButton = getSendButton( page );

		await input.fill( 'Hello' );
		await expect( sendButton ).toBeEnabled();
	} );

	test( 'clearing input disables send button', async ( { page } ) => {
		const input = getMessageInput( page );
		const sendButton = getSendButton( page );

		await input.fill( 'Hello' );
		await expect( sendButton ).toBeEnabled();

		await input.fill( '' );
		await expect( sendButton ).toBeDisabled();
	} );

	test( 'message input placeholder text is correct', async ( { page } ) => {
		// The ChatRedesign InputArea placeholder was updated when the input was
		// redesigned. Old: "Type a message or / for commands…"
		const input = getMessageInput( page );
		await expect( input ).toHaveAttribute(
			'placeholder',
			'Ask the agent to do something, or type / for commands…'
		);
	} );

	test( 'sidebar has new chat button', async ( { page } ) => {
		// The ChatRedesign Sidebar uses .sdaa-cr-new-chat (was .sdaa-new-chat-btn).
		const newChatButton = page.locator( '.sdaa-cr-new-chat' );
		await expect( newChatButton ).toBeVisible();
	} );

	test( 'sidebar has session search input', async ( { page } ) => {
		// The ChatRedesign Sidebar uses .sdaa-cr-sidebar-search (was .sdaa-sidebar-search).
		const searchInput = page.locator( '.sdaa-cr-sidebar-search' );
		await expect( searchInput ).toBeVisible();
	} );
} );

test.describe( 'Admin Page - Session Management', () => {
	test.beforeEach( async ( { page } ) => {
		await loginToWordPress( page );
		await goToAgentPage( page );
	} );

	test( 'new chat button clears the current session', async ( { page } ) => {
		// Type a message to create a session context.
		const input = getMessageInput( page );
		await input.fill( 'Test message' );
		await input.press( 'Enter' );

		// Wait for the user message row to appear — this confirms the message
		// was submitted and appended to the chat. The message row is added
		// synchronously before any async REST calls, so it is a stable signal
		// on all WP versions (including trunk where the stop button may
		// disappear quickly if the backend returns an error fast).
		await waitForMessageSubmitted( page );

		// Click new chat — ChatRedesign Sidebar uses .sdaa-cr-new-chat.
		const newChatButton = page.locator( '.sdaa-cr-new-chat' );
		await newChatButton.click();

		// Empty state should reappear — ChatRedesign MessageList renders .sdaa-cr-empty.
		const emptyState = page.locator( '.sdaa-cr .sdaa-cr-empty' );
		await expect( emptyState ).toBeVisible( { timeout: 5_000 } );
	} );

	test( 'session list shows sessions after a message is sent', async ( {
		page,
	} ) => {
		const input = getMessageInput( page );
		await input.fill( 'Create a session' );
		await input.press( 'Enter' );

		// Wait for the user message row to appear — this confirms the message
		// was submitted. The session is created via POST /sessions before the
		// background job is spawned, and the session list is refreshed after
		// session creation. Using the message row (appended synchronously) is
		// more reliable than the stop button, which may disappear quickly on
		// WP trunk if the backend returns an error response fast.
		await waitForMessageSubmitted( page );

		// At least one session row should appear in the sidebar.
		// The ChatRedesign Sidebar uses .sdaa-cr-session-row (was .sdaa-session-item).
		// Use toBeVisible() on the first item rather than toHaveCount(1) because
		// prior tests in the same run may have created sessions that persist in
		// the wp-env database across tests.
		const sessionItems = page.locator( '.sdaa-cr-session-row' );
		await expect( sessionItems.first() ).toBeVisible( { timeout: 10_000 } );
	} );
} );

test.describe( 'Admin Page - Keyboard Shortcuts', () => {
	test.beforeEach( async ( { page } ) => {
		await loginToWordPress( page );
		await goToAgentPage( page );
	} );

	test( 'Ctrl+N / Cmd+N starts a new chat', async ( { page } ) => {
		const input = getMessageInput( page );
		await input.fill( 'Some text' );
		await input.press( 'Enter' );

		// Wait for the user message row to appear — this confirms the message
		// was submitted. The message row is appended synchronously before any
		// async REST calls, making it a stable signal on all WP versions. The
		// stop button is transient and may disappear quickly on WP trunk if the
		// backend returns an error response fast (no AI provider in CI).
		await waitForMessageSubmitted( page );

		// Trigger new chat shortcut.
		await page.keyboard.press( 'ControlOrMeta+n' );

		// ChatRedesign MessageList renders .sdaa-cr-empty when there are no messages.
		const emptyState = page.locator( '.sdaa-cr .sdaa-cr-empty' );
		await expect( emptyState ).toBeVisible( { timeout: 5_000 } );
	} );

	test( 'Ctrl+K / Cmd+K focuses the sidebar search', async ( { page } ) => {
		await page.keyboard.press( 'ControlOrMeta+k' );

		// ChatRedesign Sidebar: the container is .sdaa-cr-sidebar-search (div) but
		// the focusable element is .sdaa-cr-search-input (input) inside it.
		// The AdminPageApp shortcut handler focuses .sdaa-cr-search-input directly.
		const searchInput = page.locator( '.sdaa-cr-search-input' );
		await expect( searchInput ).toBeFocused();
	} );
} );

/**
 * Abilities search and filter (t098)
 *
 * The UnifiedAdminMenu routes abilities to admin.php?page=sd-ai-agent#/abilities
 * which renders AbilitiesExplorerApp directly (not as a tab inside settings).
 * AbilitiesExplorerApp provides:
 *   - A SearchControl that filters abilities by name/description.
 *   - A SelectControl that filters by category.
 *   - Collapsible category sections with Expand all / Collapse all buttons.
 *   - A result count paragraph that updates as filters change.
 *
 * These tests navigate to the abilities route and verify each UI feature.
 * Assertions are environment-agnostic: the total ability count is read
 * dynamically from the DOM, and filter assertions verify relative changes
 * (filtered < total) rather than hardcoded counts.
 */
test.describe( 'Abilities - Search and Filter (t098)', () => {
	test.beforeEach( async ( { page } ) => {
		await loginToWordPress( page );
		await goToAbilitiesPage( page );
	} );

	/**
	 * Parse the integer total from the count element text.
	 * Handles both "N abilities" (unfiltered) and "Showing X of N abilities"
	 * (filtered) formats.
	 *
	 * @param {import('@playwright/test').Page} page
	 * @return {Promise<number>} The total ability count shown in the UI.
	 */
	async function getTotalAbilityCount( page ) {
		const countEl = page.locator( '.sdaa-abilities-count' );
		await expect( countEl ).toBeVisible();
		const text = await countEl.textContent();
		// "Showing X of N abilities" → capture N
		const ofMatch = text.match( /of\s+(\d+)/ );
		if ( ofMatch ) {
			return parseInt( ofMatch[ 1 ], 10 );
		}
		// "N abilities" → capture N
		const simpleMatch = text.match( /(\d+)/ );
		if ( simpleMatch ) {
			return parseInt( simpleMatch[ 1 ], 10 );
		}
		throw new Error( `Unexpected count text: "${ text }"` );
	}

	/**
	 * Parse the filtered count from "Showing X of N abilities" text.
	 *
	 * @param {import('@playwright/test').Page} page
	 * @return {Promise<number>} The filtered (shown) ability count.
	 */
	async function getFilteredAbilityCount( page ) {
		const countEl = page.locator( '.sdaa-abilities-count' );
		const text = await countEl.textContent();
		const match = text.match( /Showing\s+(\d+)\s+of/ );
		if ( match ) {
			return parseInt( match[ 1 ], 10 );
		}
		throw new Error( `Expected "Showing X of N" format, got: "${ text }"` );
	}

	/**
	 * Get the value of the first non-empty option in the category select.
	 * Returns null if no non-empty options exist.
	 *
	 * @param {import('@playwright/test').Page} page
	 * @return {Promise<string|null>} The first non-empty option value, or null.
	 */
	async function getFirstCategoryOption( page ) {
		const categorySelect = page
			.locator( '.sdaa-abilities-filters' )
			.locator( 'select' );
		const options = await categorySelect.locator( 'option' ).all();
		for ( const option of options ) {
			const value = await option.getAttribute( 'value' );
			if ( value && value.trim() !== '' ) {
				return value;
			}
		}
		return null;
	}

	test( 'abilities manager renders with search and category controls', async ( {
		page,
	} ) => {
		// The AbilitiesManager toolbar contains a SearchControl and a
		// SelectControl for category filtering.
		const manager = page.locator( '.sdaa-abilities-manager' );
		await expect( manager ).toBeVisible();

		// SearchControl renders an <input> inside .sdaa-abilities-search.
		const searchInput = page
			.locator( '.sdaa-abilities-search' )
			.locator( 'input' );
		await expect( searchInput ).toBeVisible();

		// Category SelectControl renders a <select> inside .sdaa-abilities-filters.
		const categorySelect = page
			.locator( '.sdaa-abilities-filters' )
			.locator( 'select' );
		await expect( categorySelect ).toBeVisible();
	} );

	test( 'abilities count shows total when no filter is active', async ( {
		page,
	} ) => {
		// The count paragraph shows "N abilities" when all are visible.
		// Assert the count is a positive number without hardcoding the value.
		const countEl = page.locator( '.sdaa-abilities-count' );
		await expect( countEl ).toBeVisible();
		const total = await getTotalAbilityCount( page );
		expect( total ).toBeGreaterThan( 0 );
	} );

	test( 'search input filters abilities by name', async ( { page } ) => {
		const searchInput = page
			.locator( '.sdaa-abilities-search' )
			.locator( 'input' );

		// Read the total before filtering.
		const total = await getTotalAbilityCount( page );

		// Type "post" — matches abilities whose name or label contains "post".
		await searchInput.fill( 'post' );

		// Count paragraph should update to "Showing X of N abilities" where X < N.
		const countEl = page.locator( '.sdaa-abilities-count' );
		await expect( countEl ).toContainText( 'Showing' );
		const filtered = await getFilteredAbilityCount( page );
		expect( filtered ).toBeGreaterThan( 0 );
		expect( filtered ).toBeLessThan( total );

		// At least one category section should be visible (has matching abilities).
		const visibleSections = page.locator( '.sdaa-abilities-category' );
		await expect( visibleSections.first() ).toBeVisible();
	} );

	test( 'search input filters abilities by description', async ( {
		page,
	} ) => {
		const searchInput = page
			.locator( '.sdaa-abilities-search' )
			.locator( 'input' );

		// Read the total before filtering.
		const total = await getTotalAbilityCount( page );

		// "post" matches abilities whose description mentions posts.
		await searchInput.fill( 'post' );

		// Count paragraph should update to "Showing X of N abilities" where X < N.
		const countEl = page.locator( '.sdaa-abilities-count' );
		await expect( countEl ).toContainText( 'Showing' );
		const filtered = await getFilteredAbilityCount( page );
		expect( filtered ).toBeGreaterThan( 0 );
		expect( filtered ).toBeLessThan( total );

		// At least one category section should be visible.
		const visibleSections = page.locator( '.sdaa-abilities-category' );
		await expect( visibleSections.first() ).toBeVisible();
	} );

	test( 'clearing search restores full list', async ( { page } ) => {
		const searchInput = page
			.locator( '.sdaa-abilities-search' )
			.locator( 'input' );

		// Read the total before filtering.
		const total = await getTotalAbilityCount( page );

		await searchInput.fill( 'post' );
		const countEl = page.locator( '.sdaa-abilities-count' );
		await expect( countEl ).toContainText( 'Showing' );

		// Clear the search — count should return to the original total.
		await searchInput.fill( '' );
		const restored = await getTotalAbilityCount( page );
		expect( restored ).toBe( total );
	} );

	test( 'no-results message appears when search matches nothing', async ( {
		page,
	} ) => {
		const searchInput = page
			.locator( '.sdaa-abilities-search' )
			.locator( 'input' );

		await searchInput.fill( 'xyzzy_nonexistent_ability' );

		// AbilitiesExplorerApp renders a "No abilities match your current filters."
		// paragraph when the filtered list is empty but abilities are registered.
		const noResults = page.locator(
			'text=No abilities match your current filters.'
		);
		await expect( noResults ).toBeVisible();
	} );

	test( 'category dropdown filters to a single category', async ( {
		page,
	} ) => {
		const categorySelect = page
			.locator( '.sdaa-abilities-filters' )
			.locator( 'select' );

		// Read the total before filtering.
		const total = await getTotalAbilityCount( page );

		// Discover the first real category option from the dropdown.
		const firstCategory = await getFirstCategoryOption( page );
		expect( firstCategory ).not.toBeNull();

		// Select the first available category.
		await categorySelect.selectOption( firstCategory );

		// Count should show "Showing X of N" where X < N.
		const countEl = page.locator( '.sdaa-abilities-count' );
		await expect( countEl ).toContainText( 'Showing' );
		const filtered = await getFilteredAbilityCount( page );
		expect( filtered ).toBeGreaterThan( 0 );
		expect( filtered ).toBeLessThan( total );

		// The selected category section should be visible.
		const selectedSection = page
			.locator( '.sdaa-abilities-category' )
			.filter( { hasText: firstCategory } );
		await expect( selectedSection ).toBeVisible();
	} );

	test( 'selecting All Categories restores full list', async ( { page } ) => {
		const categorySelect = page
			.locator( '.sdaa-abilities-filters' )
			.locator( 'select' );

		// Read the total before filtering.
		const total = await getTotalAbilityCount( page );

		// Discover and select the first real category.
		const firstCategory = await getFirstCategoryOption( page );
		expect( firstCategory ).not.toBeNull();
		await categorySelect.selectOption( firstCategory );

		const countEl = page.locator( '.sdaa-abilities-count' );
		await expect( countEl ).toContainText( 'Showing' );

		// Reset to "All Categories" (value is empty string).
		await categorySelect.selectOption( '' );
		const restored = await getTotalAbilityCount( page );
		expect( restored ).toBe( total );
	} );

	test( 'search and category filter combine correctly', async ( {
		page,
	} ) => {
		const searchInput = page
			.locator( '.sdaa-abilities-search' )
			.locator( 'input' );
		const categorySelect = page
			.locator( '.sdaa-abilities-filters' )
			.locator( 'select' );

		// Read the total before filtering.
		const total = await getTotalAbilityCount( page );

		// Discover the first real category.
		const firstCategory = await getFirstCategoryOption( page );
		expect( firstCategory ).not.toBeNull();

		// Filter to the first category, then search for "post".
		// The combined result should be fewer than the total.
		await categorySelect.selectOption( firstCategory );
		await searchInput.fill( 'post' );

		const countEl = page.locator( '.sdaa-abilities-count' );
		// Either "Showing X of N" (some match) or the no-results message.
		// In both cases the filtered count must be less than the total.
		const text = await countEl.textContent();
		if ( text.includes( 'Showing' ) ) {
			const filtered = await getFilteredAbilityCount( page );
			expect( filtered ).toBeLessThan( total );
		} else {
			// No results — count shows 0, which is less than total.
			const noResults = page.locator(
				'text=No abilities match your current filters.'
			);
			await expect( noResults ).toBeVisible();
		}
	} );

	test( 'category sections are collapsible', async ( { page } ) => {
		// Use the first visible category header (environment-agnostic).
		const firstHeader = page
			.locator( '.sdaa-abilities-category-header' )
			.first();
		await expect( firstHeader ).toBeVisible();

		// Initially expanded (defaultOpen=true when allOpen=true).
		// The category body contains the ability rows.
		const firstCategory = page
			.locator( '.sdaa-abilities-category' )
			.first();
		const firstBody = firstCategory.locator(
			'.sdaa-abilities-category-body'
		);
		await expect( firstBody ).toBeVisible();

		// Click the header to collapse.
		await firstHeader.click();
		await expect( firstBody ).not.toBeVisible();

		// Click again to expand.
		await firstHeader.click();
		await expect( firstBody ).toBeVisible();
	} );

	test( 'Collapse all button hides all category bodies', async ( {
		page,
	} ) => {
		const collapseBtn = page.getByRole( 'button', {
			name: /collapse all/i,
		} );
		await expect( collapseBtn ).toBeVisible();

		// Wait for at least one category body to be present before collapsing.
		// Without this, the abilities may not have loaded yet and count() returns 0.
		const categoryBodies = page.locator(
			'.sdaa-abilities-category-body'
		);
		await expect( categoryBodies.first() ).toBeVisible( {
			timeout: 10_000,
		} );

		await collapseBtn.click();

		// All category bodies should be hidden.
		const count = await categoryBodies.count();
		for ( let i = 0; i < count; i++ ) {
			await expect( categoryBodies.nth( i ) ).not.toBeVisible();
		}
	} );

	test( 'Expand all button shows all category bodies', async ( { page } ) => {
		// Wait for at least one category body to be present before collapsing.
		// Without this, the abilities may not have loaded yet and count() returns 0.
		const categoryBodies = page.locator(
			'.sdaa-abilities-category-body'
		);
		await expect( categoryBodies.first() ).toBeVisible( {
			timeout: 10_000,
		} );

		// Collapse first, then expand.
		const collapseBtn = page.getByRole( 'button', {
			name: /collapse all/i,
		} );
		await collapseBtn.click();

		// Verify collapsed before expanding — avoids a race where expand fires
		// before collapse has finished, making the subsequent visibility check
		// unreliable.
		await expect( categoryBodies.first() ).not.toBeVisible( {
			timeout: 5_000,
		} );

		const expandBtn = page.getByRole( 'button', { name: /expand all/i } );
		await expandBtn.click();

		// Wait for React to re-render the category bodies into the DOM.
		// The bodies are conditionally rendered (not just hidden), so count()
		// returns 0 if called before the first element is present.
		await expect( categoryBodies.first() ).toBeVisible( {
			timeout: 10_000,
		} );

		// All category bodies should be visible.
		const count = await categoryBodies.count();
		expect( count ).toBeGreaterThan( 0 );
		for ( let i = 0; i < count; i++ ) {
			await expect( categoryBodies.nth( i ) ).toBeVisible();
		}
	} );

	test( 'category sections auto-expand when search filter is active', async ( {
		page,
	} ) => {
		// Wait for abilities to load before collapsing.
		const categoryBodies = page.locator(
			'.sdaa-abilities-category-body'
		);
		await expect( categoryBodies.first() ).toBeVisible( {
			timeout: 10_000,
		} );

		// Collapse all first.
		const collapseBtn = page.getByRole( 'button', {
			name: /collapse all/i,
		} );
		await collapseBtn.click();

		// Verify collapsed.
		const count = await categoryBodies.count();
		for ( let i = 0; i < count; i++ ) {
			await expect( categoryBodies.nth( i ) ).not.toBeVisible();
		}

		// Activate search — AbilitiesManager passes defaultOpen=true when
		// isFiltering is truthy, which forces sections open.
		const searchInput = page
			.locator( '.sdaa-abilities-search' )
			.locator( 'input' );
		await searchInput.fill( 'post' );

		// At least one category body should now be visible (auto-expanded by filter).
		await expect( categoryBodies.first() ).toBeVisible();
	} );

	test( 'category count badge shows number of abilities per category', async ( {
		page,
	} ) => {
		// Use the first visible category header (environment-agnostic).
		const firstHeader = page
			.locator( '.sdaa-abilities-category-header' )
			.first();
		await expect( firstHeader ).toBeVisible();

		// Each category header shows a count badge — assert it exists and is
		// a positive number without hardcoding the value.
		const countBadge = firstHeader.locator(
			'.sdaa-abilities-category-count'
		);
		await expect( countBadge ).toBeVisible();
		const badgeText = await countBadge.textContent();
		const badgeCount = parseInt( badgeText, 10 );
		expect( badgeCount ).toBeGreaterThan( 0 );
	} );
} );
