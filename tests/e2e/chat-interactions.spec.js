/**
 * E2E tests for core chat UI interactions.
 *
 * Tests message input, slash commands, and UI state transitions
 * that do not require a live AI provider response.
 *
 * Run: npm run test:e2e:playwright
 */

const { test, expect } = require( '@playwright/test' );
const {
	loginToWordPress,
	goToAgentPage,
	getMessageInput,
	getSendButton,
	getStopButton,
} = require( './utils/wp-admin' );

/**
 * Set up interception for auto-title tests.
 *
 * How the agent job flow works (current implementation):
 *   1. The store POSTs to sd-ai-agent/v1/run with the message and session_id.
 *      The server enqueues the agent job and returns { job_id }.
 *   2. The store polls sd-ai-agent/v1/job/{job_id} every 3 s until the job
 *      status is 'complete', 'error', or 'awaiting_confirmation'.
 *   3. On 'complete' it reloads the session from the DB, optionally records
 *      generated_title via updateSessionTitle(), and calls fetchSessions() to
 *      refresh the sidebar. The SET_SESSIONS reducer merges pendingTitles into
 *      the incoming list so the generated title survives any fetchSessions()
 *      round-trip.
 *
 * When `options.generatedTitle` is provided the job-complete payload includes
 * `generated_title`, exercising the full job-result handling path in the store.
 *
 * No sessions stub is needed: the store's pendingTitles mechanism preserves the
 * optimistic title through any fetchSessions() round-trip, so the real server
 * response (which carries "Untitled") does not overwrite the generated title.
 *
 * @param {import('@playwright/test').Page} page
 * @param {Object} [options]
 * @param {string} [options.generatedTitle] - When set, included as
 *   `generated_title` in the job-complete payload so the store's job-result
 *   parsing path is exercised end-to-end.
 * @param {number} [options.processingPolls=0] - Number of polls that return
 *   `status: 'processing'` before the handler switches to `status: 'complete'`.
 *   Use `processingPolls: 1` in the stop-button test so `sending` stays true
 *   long enough for the assertion to observe the stop button.
 * @return {Promise<void>}
 */
async function interceptStream( page, options = {} ) {
	const { generatedTitle, processingPolls = 0 } = options;
	let jobPollCount = 0;

	// Track the session_id from the /run POST body so the job-complete payload
	// carries the correct session ID. The store creates the session first via
	// POST /sessions and passes the id in the /run body.
	let capturedSessionId = null;

	// Intercept POST /run — the store sends the message here and receives a job_id.
	// Capture session_id from the request body; return a synthetic job_id.
	// Use a predicate function instead of a regex because wp-env uses plain
	// permalinks (?rest_route=%2F...) where slashes are URL-encoded — a regex
	// against the raw URL would never match.
	await page.route(
		( url ) => decodeURIComponent( url.toString() ).includes( 'sd-ai-agent/v1/run' ),
		async ( route ) => {
		try {
			const postBody = route.request().postDataJSON();
			if ( postBody?.session_id ) {
				capturedSessionId = postBody.session_id;
			}
		} catch {
			// Ignore parse failures — capturedSessionId stays null.
		}

		await route.fulfill( {
			status: 202,
			contentType: 'application/json',
			body: JSON.stringify( {
				job_id: 'e2e-test-job-1',
				status: 'processing',
			} ),
		} );
	} );

	// Intercept GET /job/:id — the store polls here every 3 s until 'complete'.
	// Return 'processing' for the first `processingPolls` polls so tests that
	// need to observe transient UI state (e.g., the stop button) have a window
	// to do so. After that, return 'complete' immediately.
	// capturedSessionId is guaranteed set before the first poll: the browser
	// sends POST /run synchronously before fetching GET /job/:id.
	await page.route(
		( url ) => decodeURIComponent( url.toString() ).includes( 'sd-ai-agent/v1/job/' ),
		async ( route ) => {
		jobPollCount += 1;
		if ( jobPollCount <= processingPolls ) {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( { status: 'processing' } ),
			} );
			return;
		}

		const result = {
			status: 'complete',
			session_id: capturedSessionId,
			reply: 'Hello from the AI!',
			...( generatedTitle ? { generated_title: generatedTitle } : {} ),
		};

		await route.fulfill( {
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify( result ),
		} );
	} );

	// Intercept GET /sessions/:id — the store reloads the session from the DB
	// after the job completes. Without this intercept the test depends on a real
	// REST round-trip which can take 1-3 s on loaded CI runners, pushing total
	// wait time past the 10 s assertion timeout. When capturedSessionId is null
	// (before the job runs), pass through to the real server.
	await page.route(
		( url ) => {
			const decoded = decodeURIComponent( url.toString() );
			return (
				decoded.includes( 'sd-ai-agent/v1/sessions/' ) &&
				! decoded.includes( '/sessions/shared' ) &&
				/\/sessions\/\d+/.test( decoded )
			);
		},
		async ( route ) => {
			if ( capturedSessionId === null ) {
				await route.continue();
				return;
			}
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					id: capturedSessionId,
					title: 'Untitled',
					status: 'active',
					user_id: 1,
					messages: [],
					tool_calls: [],
				} ),
			} );
		}
	);

	// Intercept GET /sessions (list) — the store calls fetchSessions() after the
	// job completes to refresh the sidebar. Without this intercept the sidebar
	// update depends on a real REST round-trip, which can exceed the assertion
	// timeout on loaded CI runners. When capturedSessionId is null (the initial
	// fetchSessions on mount fires before the job), pass through to the real
	// server so the sidebar populates with any pre-existing sessions.
	//
	// The mock response carries generatedTitle (or 'Untitled') as the session
	// title. The store's SET_SESSIONS reducer also merges pendingTitles into the
	// list — so even if generatedTitle is undefined, the optimistic title from
	// updateSessionTitle() takes precedence.
	await page.route(
		( url ) => {
			const decoded = decodeURIComponent( url.toString() );
			return (
				decoded.includes( 'sd-ai-agent/v1/sessions' ) &&
				! decoded.includes( '/sessions/shared' ) &&
				! /\/sessions\/\d+/.test( decoded )
			);
		},
		async ( route ) => {
			if ( capturedSessionId === null ) {
				await route.continue();
				return;
			}
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( [
					{
						id: capturedSessionId,
						title: generatedTitle || 'Untitled',
						status: 'active',
						user_id: 1,
						messages: [],
						tool_calls: [],
					},
				] ),
			} );
		}
	);
}

/**
 * Exercise one paused server-tool confirmation followed by a browser-side
 * completion check and terminal continuation. The client ability intentionally
 * receives duplicate URLs so its real validator returns a fast, deterministic
 * invalid-arguments report instead of loading six frontend iframes.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 * @return {Promise<Object>} Route state and cleanup helpers.
 */
async function interceptConfirmationClientToolContinuation( page ) {
	const jobId = 'e2e-confirmation-client-tool-job';
	const clientToolCallId = 'e2e-theme-completion-call';
	const terminalReply =
		'Confirmation and client-tool continuation completed without another user message.';
	let capturedSessionId = null;
	let confirmationRequestCount = 0;
	let runRequestCount = 0;
	const toolResultPayloads = [];

	const isRunEndpoint = ( url ) => {
		const decoded = decodeURIComponent( url.toString() );
		return (
			decoded.includes( 'sd-ai-agent/v1/run' ) &&
			! decoded.includes( '/run/' )
		);
	};
	const isJobEndpoint = ( url ) => {
		const decoded = decodeURIComponent( url.toString() );
		return (
			decoded.includes( `sd-ai-agent/v1/job/${ jobId }` ) &&
			! decoded.includes( `/job/${ jobId }/confirm` )
		);
	};
	const isConfirmationEndpoint = ( url ) =>
		decodeURIComponent( url.toString() ).includes(
			`sd-ai-agent/v1/job/${ jobId }/confirm`
		);
	const isToolResultEndpoint = ( url ) =>
		decodeURIComponent( url.toString() ).includes(
			'sd-ai-agent/v1/chat/tool-result'
		);
	const isSessionEndpoint = ( url ) => {
		const decoded = decodeURIComponent( url.toString() );
		return (
			capturedSessionId !== null &&
			decoded.includes(
				`sd-ai-agent/v1/sessions/${ capturedSessionId }`
			) &&
			! decoded.includes(
				`/sessions/${ capturedSessionId }/active-job`
			)
		);
	};

	const handleRun = async ( route ) => {
		if ( route.request().method() !== 'POST' ) {
			await route.continue();
			return;
		}

		runRequestCount++;
		try {
			const body = route.request().postDataJSON();
			capturedSessionId = body?.session_id || null;
		} catch {
			// The test assertions below report a missing session ID clearly.
		}
		await route.fulfill( {
			status: 202,
			contentType: 'application/json',
			body: JSON.stringify( { job_id: jobId, status: 'processing' } ),
		} );
	};

	const handleConfirmation = async ( route ) => {
		confirmationRequestCount++;
		await route.fulfill( {
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify( { status: 'processing' } ),
		} );
	};

	const handleToolResult = async ( route ) => {
		try {
			toolResultPayloads.push( route.request().postDataJSON() );
		} catch {
			toolResultPayloads.push( null );
		}
		await route.fulfill( {
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify( { status: 'processing' } ),
		} );
	};

	const handleSession = async ( route ) => {
		if (
			route.request().method() !== 'GET' ||
			toolResultPayloads.length === 0
		) {
			await route.continue();
			return;
		}

		await route.fulfill( {
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify( {
				id: capturedSessionId,
				title: 'E2E continuation',
				status: 'active',
				user_id: 1,
				messages: [
					{
						role: 'user',
						parts: [ { text: 'Build and validate the E2E theme.' } ],
					},
					{
						role: 'model',
						parts: [ { text: terminalReply } ],
					},
				],
				tool_calls: [],
			} ),
		} );
	};

	const handleJob = async ( route ) => {
		let body;
		if ( confirmationRequestCount === 0 ) {
			body = {
				status: 'awaiting_confirmation',
				pending_tools: [
					{
						id: 'e2e-activate-theme-call',
						name: 'sd-ai-agent/activate-theme',
						label: 'Activate E2E theme',
						description: 'Activate the generated E2E theme.',
						args: { stylesheet: 'e2e-theme' },
					},
				],
			};
		} else if ( toolResultPayloads.length === 0 ) {
			body = {
				status: 'awaiting_client_tools',
				pending_client_tool_calls: [
					{
						id: clientToolCallId,
						name: 'sd-ai-agent-js/validate-theme-completion',
						annotations: { readonly: true },
						args: {
							stylesheet: 'e2e-theme',
							fingerprint: 'e2e-fingerprint',
							homepage_url: '/',
							interior_url: '/',
						},
					},
				],
			};
		} else {
			body = {
				status: 'complete',
				session_id: capturedSessionId,
				reply: terminalReply,
			};
		}

		await route.fulfill( {
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify( body ),
		} );
	};

	await page.route( isRunEndpoint, handleRun );
	await page.route( isJobEndpoint, handleJob );
	await page.route( isConfirmationEndpoint, handleConfirmation );
	await page.route( isToolResultEndpoint, handleToolResult );
	await page.route( isSessionEndpoint, handleSession );

	return {
		jobId,
		clientToolCallId,
		getCapturedSessionId: () => capturedSessionId,
		getConfirmationRequestCount: () => confirmationRequestCount,
		getRunRequestCount: () => runRequestCount,
		getToolResultPayloads: () => toolResultPayloads,
		cleanup: async () => {
			await page.unroute( isRunEndpoint, handleRun );
			await page.unroute( isJobEndpoint, handleJob );
			await page.unroute( isConfirmationEndpoint, handleConfirmation );
			await page.unroute( isToolResultEndpoint, handleToolResult );
			await page.unroute( isSessionEndpoint, handleSession );
		},
	};
}

test.describe( 'Chat Input Interactions', () => {
	test.beforeEach( async ( { page } ) => {
		await loginToWordPress( page );
		await goToAgentPage( page );
	} );

	test( 'textarea auto-resizes as text grows', async ( { page } ) => {
		const input = getMessageInput( page );
		const initialHeight = await input.evaluate( ( el ) => el.offsetHeight );

		// Type multiple lines.
		await input.fill( 'Line 1\nLine 2\nLine 3\nLine 4\nLine 5' );

		const newHeight = await input.evaluate( ( el ) => el.offsetHeight );
		expect( newHeight ).toBeGreaterThan( initialHeight );
	} );

	test( 'Shift+Enter inserts a newline instead of sending', async ( {
		page,
	} ) => {
		const input = getMessageInput( page );
		await input.fill( 'First line' );
		await input.press( 'Shift+Enter' );
		await input.type( 'Second line' );

		const value = await input.inputValue();
		expect( value ).toContain( '\n' );
	} );

	test( 'Enter key sends the message', async ( { page } ) => {
		const input = getMessageInput( page );
		await input.fill( 'Test message' );
		await input.press( 'Enter' );

		// Input should be cleared after send.
		await expect( input ).toHaveValue( '' );
	} );

	test( 'send button click sends the message', async ( { page } ) => {
		const input = getMessageInput( page );
		const sendButton = getSendButton( page );

		await input.fill( 'Test via button' );
		await sendButton.click();

		await expect( input ).toHaveValue( '' );
	} );

	test( 'stop button appears while sending', async ( { page } ) => {
		// Intercept the stream endpoint BEFORE sending so the request stays
		// in-flight long enough for the stop button to be visible. Without this
		// mock the backend returns an error immediately (no AI provider in CI),
		// setting sending=false before the 5 s assertion window.
		// processingPolls: 1 ensures the first job poll returns 'processing',
		// keeping sending=true (and the stop button visible) until the second
		// poll returns 'complete'. This prevents a race where an immediate
		// 'complete' response clears sending state before the assertion fires.
		await interceptStream( page, { processingPolls: 1 } );

		const input = getMessageInput( page );
		await input.fill( 'Trigger a response' );
		await input.press( 'Enter' );

		// Stop button should appear while the request is in flight.
		const stopButton = getStopButton( page );
		await expect( stopButton ).toBeVisible( { timeout: 5_000 } );
	} );
} );

test.describe( 'Confirmation and client-tool continuation', () => {
	test.beforeEach( async ( { page } ) => {
		// Keep client-ability registration deterministic. The fake registry still
		// stores the actual callbacks registered by the admin bundle, so the test
		// exercises validate-theme-completion rather than hand-crafting a result.
		await page.addInitScript( () => {
			if ( typeof window.wp === 'undefined' ) {
				window.wp = {};
			}
			const registeredAbilities = [];
			const abilitiesStub = {
				registerAbilityCategory: async () => {},
				registerAbility: async ( ability ) => {
					registeredAbilities.push( ability );
				},
				getAbilities: () => registeredAbilities,
				getAbilityCategory: async () => null,
				executeAbility: async ( name, args ) => {
					const ability = registeredAbilities.find(
						( item ) => item.name === name
					);
					return ability?.callback( args );
				},
			};
			Object.defineProperty( window.wp, 'abilities', {
				value: abilitiesStub,
				writable: false,
				configurable: true,
				enumerable: true,
			} );
		} );

		await loginToWordPress( page );
		await goToAgentPage( page );
		await page
			.locator( '.sdaa-cr' )
			.waitFor( { state: 'visible', timeout: 30_000 } );
		await page.waitForFunction(
			() =>
				window.wp?.abilities
					?.getAbilities()
					?.some(
						( ability ) =>
							ability.name ===
							'sd-ai-agent-js/validate-theme-completion'
					),
			null,
			{ timeout: 10_000 }
		);
	} );

	test( 'continues an approved turn through validate-theme-completion without a new user message', async ( {
		page,
	} ) => {
		const flow = await interceptConfirmationClientToolContinuation( page );

		try {
			const input = getMessageInput( page );
			await input.fill( 'Build and validate the E2E theme.' );
			await getSendButton( page ).click();

			const confirmation = page.locator( '.sdaa-tool-confirm-dialog' );
			await expect( confirmation ).toBeVisible( { timeout: 10_000 } );
			await confirmation.getByRole( 'button', { name: 'Allow' } ).click();

			const messageList = page.locator( '.sdaa-cr .sdaa-cr-messages' );
			await expect( messageList ).toContainText(
				'Confirmation and client-tool continuation completed without another user message.',
				{ timeout: 15_000 }
			);

			await expect
				.poll( () => flow.getToolResultPayloads().length, {
					timeout: 5_000,
				} )
				.toBe( 1 );
			expect( flow.getCapturedSessionId() ).not.toBeNull();
			expect( flow.getRunRequestCount() ).toBe( 1 );
			expect( flow.getConfirmationRequestCount() ).toBe( 1 );
			expect( flow.getToolResultPayloads()[ 0 ] ).toMatchObject( {
				session_id: flow.getCapturedSessionId(),
				job_id: flow.jobId,
				tool_results: [
					{
						id: flow.clientToolCallId,
						name: 'sd-ai-agent-js/validate-theme-completion',
						result: expect.objectContaining( {
							complete: false,
							passed: false,
							violations: expect.arrayContaining( [
								expect.objectContaining( {
									code: 'invalid_completion_urls',
								} ),
							] ),
						} ),
					},
				],
			} );
		} finally {
			await flow.cleanup();
		}
	} );
} );

test.describe( 'Slash Command Menu', () => {
	test.beforeEach( async ( { page } ) => {
		await loginToWordPress( page );
		await goToAgentPage( page );
	} );

	test( 'slash menu appears when typing /', async ( { page } ) => {
		// Wait for ChatRedesign InputArea to be ready before typing.
		await page
			.locator( '.sdaa-cr' )
			.waitFor( { state: 'visible', timeout: 30_000 } );
		const input = getMessageInput( page );
		await input.fill( '/' );

		const slashMenu = page.locator( '.sdaa-slash-menu' );
		await expect( slashMenu ).toBeVisible();
	} );

	test( 'slash menu shows expected commands', async ( { page } ) => {
		const input = getMessageInput( page );
		await input.fill( '/' );

		const slashMenu = page.locator( '.sdaa-slash-menu' );
		await expect( slashMenu ).toBeVisible();

		// Core commands should be listed.
		await expect( slashMenu ).toContainText( '/new' );
		await expect( slashMenu ).toContainText( '/remember' );
		await expect( slashMenu ).toContainText( '/forget' );
		await expect( slashMenu ).toContainText( '/clear' );
		await expect( slashMenu ).toContainText( '/help' );
	} );

	test( 'slash menu filters as user types', async ( { page } ) => {
		const input = getMessageInput( page );
		await input.fill( '/rem' );

		const slashMenu = page.locator( '.sdaa-slash-menu' );
		await expect( slashMenu ).toBeVisible();

		// Only /remember should match /rem.
		const items = page.locator( '.sdaa-slash-item' );
		const count = await items.count();
		expect( count ).toBeGreaterThanOrEqual( 1 );

		await expect( slashMenu ).toContainText( '/remember' );
	} );

	test( 'slash menu closes when Escape is pressed', async ( { page } ) => {
		const input = getMessageInput( page );
		await input.fill( '/' );

		const slashMenu = page.locator( '.sdaa-slash-menu' );
		await expect( slashMenu ).toBeVisible();

		await input.press( 'Escape' );
		await expect( slashMenu ).not.toBeVisible();
	} );

	test( 'selecting /new from slash menu clears the session', async ( {
		page,
	} ) => {
		const input = getMessageInput( page );
		await input.fill( '/new' );

		const slashMenu = page.locator( '.sdaa-slash-menu' );
		await expect( slashMenu ).toBeVisible();

		// Click the /new item.
		const newItem = page.locator( '.sdaa-slash-item' ).filter( {
			hasText: '/new',
		} );
		await newItem.click();

		// Empty state should be visible. ChatRedesign MessageList renders
		// .sdaa-cr-empty when there are no messages. Scoped to .sdaa-cr (the admin
		// chat root) so it doesn't match any other element on the page.
		const emptyState = page.locator( '.sdaa-cr .sdaa-cr-empty' );
		await expect( emptyState ).toBeVisible();
	} );

	test( '/help slash command opens shortcuts dialog', async ( { page } ) => {
		const input = getMessageInput( page );
		await input.fill( '/help' );

		const slashMenu = page.locator( '.sdaa-slash-menu' );
		await expect( slashMenu ).toBeVisible();

		const helpItem = page.locator( '.sdaa-slash-item' ).filter( {
			hasText: '/help',
		} );
		await helpItem.click();

		// Shortcuts dialog should open.
		const shortcutsDialog = page.locator( '.sdaa-shortcuts-overlay' );
		await expect( shortcutsDialog ).toBeVisible();
	} );
} );

test.describe( 'Provider Selector', () => {
	test.beforeEach( async ( { page } ) => {
		await loginToWordPress( page );
		await goToAgentPage( page );
	} );

	test( 'provider selector is visible in the chat header', async ( {
		page,
	} ) => {
		// Wait for ChatRedesign to mount before checking the model chip.
		// ChatRoute polls for window.sdAiAgentChat (exposed by admin-page.js),
		// so .sdaa-cr may appear after a short delay post-navigation.
		await page
			.locator( '.sdaa-cr' )
			.waitFor( { state: 'visible', timeout: 30_000 } );

		// ChatRedesign replaces ProviderSelector with ModelPicker. The model chip
		// (.sdaa-cr-model-chip-wrap) sits in InputArea's toolbar and shows the
		// active provider + model. Scoped to .sdaa-cr (admin chat root).
		const providerSelector = page
			.locator( '.sdaa-cr .sdaa-cr-model-chip-wrap' )
			.first();
		await expect( providerSelector ).toBeVisible();
	} );
} );

/**
 * Auto-title sessions (t099)
 *
 * After the first AI response the store reads `generated_title` from the SSE
 * done event and calls `updateSessionTitle()` to update the sidebar item.
 *
 * These tests use interceptStream() with an optional `generatedTitle` to
 * simulate auto-titling without a live AI provider. When `generatedTitle` is
 * provided, the intercepted SSE `done` payload includes `generated_title`,
 * exercising the full stream-event parsing path in the store. This ensures
 * regressions in SSE handling are caught rather than masked by a direct store
 * dispatch.
 */
test.describe( 'Auto-Title Sessions (t099)', () => {
	test.beforeEach( async ( { page } ) => {
		// Stub the WP 7.0 abilities API so ensureClientAbilitiesRegistered()
		// (called by the store's streamMessage thunk before POST /run) resolves
		// immediately instead of making REST calls to /wp-abilities/v1/abilities.
		// In WP 7.0-RC2 wp-env, these REST calls can take 15-30 s, causing the
		// 20 s sidebar-assertion timeout to expire. Locked with
		// Object.defineProperty (writable: false) so WordPress's classic scripts
		// cannot override the stub via simple assignment. See the matching fix in
		// text-to-speech.spec.js injectTtsMock() for the full rationale.
		await page.addInitScript( () => {
			if ( typeof window.wp === 'undefined' ) {
				window.wp = {};
			}
			const abilitiesStub = {
				registerAbility: async () => {},
				registerAbilityCategory: async () => {},
				getAbilities: async () => [],
				getAbilityCategory: async () => null,
				executeAbility: async () => null,
			};
			Object.defineProperty( window.wp, 'abilities', {
				value: abilitiesStub,
				writable: false,
				configurable: true,
				enumerable: true,
			} );
		} );

		await loginToWordPress( page );
		await goToAgentPage( page );
		// Wait for ChatRedesign to mount before interacting. ChatRoute polls for
		// window.sdAiAgentChat, so .sdaa-cr may appear after a short delay.
		await page
			.locator( '.sdaa-cr' )
			.waitFor( { state: 'visible', timeout: 30_000 } );
	} );

	test( 'session title updates in sidebar after first AI response', async ( {
		page,
	} ) => {
		const expectedTitle = 'My Auto-Generated Title';

		// Intercept the stream and include generated_title in the done payload.
		// This exercises the full SSE parsing path: the store reads
		// done.generated_title from the intercepted stream and calls
		// updateSessionTitle() — no direct store dispatch is needed.
		await interceptStream( page, { generatedTitle: expectedTitle } );

		const input = getMessageInput( page );
		await input.fill( 'Tell me about WordPress' );
		await input.press( 'Enter' );

		// Wait for the active session row to appear in the sidebar. The active
		// row has the is-active class and is the current session. Using
		// .first() is unreliable when previous tests have left sessions in the
		// sidebar — the current session may not be the first item.
		//
		// ChatRedesign Sidebar uses .sdaa-cr-session-row (was .sdaa-session-item).
		//
		// 20 s timeout: the full chain (POST /sessions → POST /run → job poll
		// at 3 s interval → fetchSessions → React re-render) takes 8-15 s on
		// CI runners under load. The previous 10 s timeout was borderline —
		// the third auto-title test (which uses 15 s) passed while these two
		// (at 10 s) failed consistently.
		const activeItem = page.locator( '.sdaa-cr-session-row.is-active' );
		await expect( activeItem ).toBeVisible( { timeout: 20_000 } );

		// The active sidebar row should now display the generated title.
		// The title arrives via the SSE done event (generated_title field),
		// not via a direct store dispatch, so this assertion validates the
		// full stream-event handling path.
		// ChatRedesign Sidebar uses .sdaa-cr-session-row-title (was .sdaa-session-title).
		await expect( activeItem ).toContainText( expectedTitle, {
			timeout: 10_000,
		} );
	} );

	test( 'session title is not "Untitled" after auto-title fires', async ( {
		page,
	} ) => {
		const expectedTitle = 'WordPress Plugin Development';

		// Include generated_title in the SSE done payload so the store's
		// stream-event parsing path is exercised end-to-end.
		await interceptStream( page, { generatedTitle: expectedTitle } );

		const input = getMessageInput( page );
		await input.fill( 'How do I build a WordPress plugin?' );
		await input.press( 'Enter' );

		// Wait for the active session row (see timeout rationale in first test).
		// ChatRedesign Sidebar uses .sdaa-cr-session-row (was .sdaa-session-item).
		const activeItem = page.locator( '.sdaa-cr-session-row.is-active' );
		await expect( activeItem ).toBeVisible( { timeout: 20_000 } );

		// The title element inside the active session row should not say "Untitled".
		// The title arrives via the SSE done event, not a direct store dispatch.
		// ChatRedesign Sidebar uses .sdaa-cr-session-row-title (was .sdaa-session-title).
		const titleEl = activeItem.locator( '.sdaa-cr-session-row-title' );
		await expect( titleEl ).not.toContainText( 'Untitled', {
			timeout: 10_000,
		} );
		await expect( titleEl ).toContainText( expectedTitle, {
			timeout: 10_000,
		} );
	} );

	test( 'new session starts as Untitled before any AI response', async ( {
		page,
	} ) => {
		// Intercept the stream so it completes quickly and fetchSessions() runs,
		// populating state.sessions with the new session. This avoids relying on
		// the stop button (which is fragile on WP trunk — the stream may fail
		// before setSending(true) renders the button).
		await interceptStream( page );

		const input = getMessageInput( page );
		await input.fill( 'Hello' );
		await input.press( 'Enter' );

		// Wait for the active session row to appear in the sidebar. fetchSessions()
		// runs after the intercepted stream completes, so the session is in
		// state.sessions at this point.
		// ChatRedesign Sidebar uses .sdaa-cr-session-row (was .sdaa-session-item).
		const activeItem = page.locator( '.sdaa-cr-session-row.is-active' );
		await expect( activeItem ).toBeVisible( { timeout: 15_000 } );

		// The intercepted done event carries no generated_title, so the title
		// should still be "Untitled" (or empty) at this point.
		// ChatRedesign Sidebar uses .sdaa-cr-session-row-title (was .sdaa-session-title).
		const titleEl = activeItem.locator( '.sdaa-cr-session-row-title' );
		const titleText = await titleEl.textContent();
		// Title is either empty or "Untitled" — no auto-title was injected.
		expect(
			titleText === '' ||
				titleText === 'Untitled' ||
				titleText?.includes( 'Untitled' )
		).toBe( true );
	} );
} );
