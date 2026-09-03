/**
 * E2E tests for managed text-to-speech.
 *
 * Tests the TTS toggle button in the chat header, the TTS settings tab,
 * and the auto-speak behaviour on AI responses.
 *
 * Tests intercept the authenticated capabilities and synthesis routes and use
 * a controlled HTMLAudioElement fake. Browser Web Speech is not the primary
 * path under test.
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
// Helpers
// ---------------------------------------------------------------------------

/**
 * Intercept managed speech routes and inject a controlled Audio fake.
 *
 * @param {import('@playwright/test').Page} page - Playwright page object.
 */
async function injectTtsMock( page ) {
	await page.addInitScript( () => {
		const calls = [];
		window.__ttsMockCalls = calls;
		window.Audio = class {
			constructor( source ) {
				this.source = source;
				this.timer = null;
			}

			play() {
				calls.push( { type: 'play', source: this.source } );
				this.timer = setTimeout( () => this.onended?.(), 5000 );
				return Promise.resolve();
			}

			pause() {
				calls.push( { type: 'pause' } );
				clearTimeout( this.timer );
			}

			removeAttribute() {}
		};

		const revokeObjectUrl = URL.revokeObjectURL.bind( URL );
		URL.revokeObjectURL = ( url ) => {
			calls.push( { type: 'revoke', url } );
			revokeObjectUrl( url );
		};

		Object.defineProperty( window, 'speechSynthesis', {
			value: undefined,
			writable: true,
			configurable: true,
		} );

		// Stub the WP 7.0 abilities API so ensureClientAbilitiesRegistered()
		// (called by the store's streamMessage thunk before POST /run) resolves
		// immediately instead of making REST calls to /wp-abilities/v1/abilities.
		//
		// In WP 7.0-RC2 wp-env, wp.abilities.registerAbilityCategory() and
		// getAbilities() trigger REST fetches that can take 15-30 s, blocking
		// the send-message pipeline and delaying setSending(false) past the
		// test's polling windows.
		//
		// This stub is set unconditionally AND locked with Object.defineProperty
		// (writable: false, configurable: true) so that WordPress's classic
		// scripts cannot override it with the real implementation via simple
		// assignment (window.wp.abilities = realImpl would fail silently).
		// configurable: true is preserved so Playwright can redefine it across
		// tests. This init script runs before any other script on the page, so
		// the stub is always in place when the chat bundle evaluates.
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
		// Use Object.defineProperty so simple assignment can't override the stub.
		Object.defineProperty( window.wp, 'abilities', {
			value: abilitiesStub,
			writable: false,
			configurable: true,
			enumerable: true,
		} );
	} );

	await page.route(
		( url ) =>
			decodeURIComponent( url.toString() ).includes(
				'sd-ai-agent/v1/speech/capabilities'
			),
		async ( route ) => {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					available: true,
					locales: { initial_locale: 'en-US' },
					text_to_speech: {
						max_input_characters: 1200,
						max_response_bytes: 1024,
						output_formats: [ 'mp3' ],
						output_mime_types: [ 'audio/mpeg' ],
						speed: { minimum: 0.5, maximum: 2 },
						voices: [
							{
								id: 'managed-voice',
								name: 'Managed Voice',
								locales: [ 'en-US' ],
							},
						],
					},
					transcription: {
						accepted_input_mime_types: [ 'audio/wav' ],
						max_bytes: 1024,
						max_duration_seconds: 10,
					},
				} ),
			} );
		}
	);
	await page.route(
		( url ) =>
			decodeURIComponent( url.toString() ).includes(
				'sd-ai-agent/v1/speech/synthesis'
			),
		async ( route ) => {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					audio: 'UklGRg==',
					mime_type: 'audio/mpeg',
					request_id: 'e2e-speech',
				} ),
			} );
		}
	);
}

/**
 * Intercept the agent job endpoints so the store completes the message and
 * TTS can fire.
 *
 * The store uses POST /run (returns a job_id) + GET /job/:id polling.
 * We intercept all four endpoints involved in the flow:
 *   1. POST /run — capture session_id, return synthetic job_id.
 *   2. GET /job/:id — return 'processing' for processingPolls rounds, then
 *      'complete' with session_id so the store reloads the session.
 *   3. GET /sessions/:id — return a synthetic session containing the AI reply
 *      (the real DB has no reply since /run was mocked).
 *   4. GET /sessions (list) — intercept the fetchSessions() call that fires
 *      after job completion. Without this, the real REST round-trip can take
 *      1-3 s on loaded CI runners, delaying state transitions and causing
 *      TTS effect timing races.
 *
 * Pattern mirrors chat-interactions.spec.js interceptStream() exactly.
 *
 * @param {import('@playwright/test').Page} page    - Playwright page object.
 * @param {Object}                          options - Configuration.
 * @param {number} [options.processingPolls=0] - Number of polls returning
 *   'processing' before switching to 'complete'. Use 1+ if tests need to
 *   observe transient sending/stop-button state.
 */
async function interceptStream( page, options = {} ) {
	const { processingPolls = 0 } = options;
	let jobPollCount = 0;

	// Track the session_id created by the store before POST /run fires.
	let capturedSessionId = null;

	// Intercept POST /run — capture session_id from the request body and
	// return a synthetic job_id. Use 202 to match the real server response
	// code (job enqueued, not yet complete).
	// Use a predicate function instead of a regex because wp-env uses plain
	// permalinks (?rest_route=%2F...) where slashes are URL-encoded.
	await page.route(
		( url ) => decodeURIComponent( url.toString() ).includes( 'sd-ai-agent/v1/run' ),
		async ( route ) => {
		try {
			const postBody = route.request().postDataJSON();
			if ( postBody?.session_id ) {
				capturedSessionId = postBody.session_id;
			}
		} catch {
			// Ignore parse failures — capturedSessionId stays null, triggering
			// the local-append fallback path in pollJob.
		}
		await route.fulfill( {
			status: 202,
			contentType: 'application/json',
			body: JSON.stringify( {
				job_id: 'e2e-tts-job-1',
				status: 'processing',
			} ),
		} );
	} );

	// Intercept GET /job/:id — return 'processing' for the first
	// `processingPolls` polls, then 'complete' with session_id so the store
	// reloads the session (intercepted below to include the AI reply).
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
		await route.fulfill( {
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify( {
				status: 'complete',
				session_id: capturedSessionId,
				reply: 'Hello from the AI!',
			} ),
		} );
	} );

	// Intercept GET /sessions/:id — return a synthetic session that already
	// contains both the user message and the AI reply. Without this, the store
	// would load the real DB session (which has no AI reply since /run was
	// mocked) and overwrite messages, leaving the last message as 'user' so
	// the TTS effect's role check (`lastMsg.role !== 'model'`) returns early.
	await page.route(
		( url ) => {
			const decoded = decodeURIComponent( url.toString() );
			// Match /sessions/:id but not the list endpoint or sub-paths.
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
					messages: [
						{ role: 'user', parts: [ { text: 'Hello' } ] },
						{
							role: 'model',
							parts: [ { text: 'Hello from the AI!' } ],
						},
					],
					tool_calls: [],
				} ),
			} );
		}
	);

	// Intercept GET /sessions (list) — the store calls fetchSessions() after
	// the job completes to refresh the sidebar. Without this intercept the
	// sidebar update depends on a real REST round-trip which can exceed
	// assertion timeouts on loaded CI runners and delay the setSending(false)
	// dispatch that the TTS effect depends on.
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
						title: 'Untitled',
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

// ---------------------------------------------------------------------------
// Tests: TTS toggle button in the chat header
// ---------------------------------------------------------------------------

test.describe( 'TTS Toggle Button', () => {
	test.beforeEach( async ( { page } ) => {
		await injectTtsMock( page );
		await loginToWordPress( page );
		await goToAgentPage( page );
	} );

	test( 'TTS toggle button is visible in the chat header', async ( {
		page,
	} ) => {
		// The button is only rendered when isTTSSupported is true.
		// Our mock defines window.speechSynthesis, so the button should appear.
		// The admin page now uses ChatRedesign (.sdaa-cr); the TTS button is in
		// ConvoHeader with class sdaa-tts-btn. Scoping to .sdaa-cr
		// avoids matching the floating widget's button.
		// Use 15 s timeout — the chat panel can be slow to render on CI runners
		// under load, especially on WP trunk where the SPA mount is heavier.
		const ttsBtn = page
			.locator( '.sdaa-cr .sdaa-tts-btn' )
			.first();
		await expect( ttsBtn ).toBeVisible( { timeout: 15_000 } );
	} );

	test( 'clicking TTS toggle button enables TTS and adds is-active class', async ( {
		page,
	} ) => {
		// Scope to the ChatRedesign root (.sdaa-cr) — admin page chat panel.
		const ttsBtn = page
			.locator( '.sdaa-cr .sdaa-tts-btn' )
			.first();
		await expect( ttsBtn ).toBeVisible( { timeout: 15_000 } );

		// Ensure TTS starts disabled (default state).
		// If it is already active, click once to disable first.
		const isActive = await ttsBtn.evaluate( ( el ) =>
			el.classList.contains( 'is-active' )
		);
		if ( isActive ) {
			await ttsBtn.click();
		}

		// Now enable TTS.
		await ttsBtn.click();
		await expect( ttsBtn ).toHaveClass( /is-active/ );
	} );

	test( 'clicking TTS toggle button a second time disables TTS', async ( {
		page,
	} ) => {
		// Scope to the ChatRedesign root (.sdaa-cr) — admin page chat panel.
		const ttsBtn = page
			.locator( '.sdaa-cr .sdaa-tts-btn' )
			.first();
		await expect( ttsBtn ).toBeVisible( { timeout: 15_000 } );

		// Enable TTS.
		const isActive = await ttsBtn.evaluate( ( el ) =>
			el.classList.contains( 'is-active' )
		);
		if ( ! isActive ) {
			await ttsBtn.click();
		}
		await expect( ttsBtn ).toHaveClass( /is-active/ );

		// Disable TTS.
		await ttsBtn.click();
		await expect( ttsBtn ).not.toHaveClass( /is-active/ );
	} );
} );

// ---------------------------------------------------------------------------
// Tests: TTS settings tab
// ---------------------------------------------------------------------------

test.describe( 'TTS Settings Tab', () => {
	test.beforeEach( async ( { page } ) => {
		await injectTtsMock( page );
		await loginToWordPress( page );
		// TTS settings live inside the General tab of the Settings page.
		// There is no separate "Text-to-Speech" tab — the section is rendered
		// as part of the General tab content under a "Text-to-Speech" heading.
		await goToSettingsPage( page, 'general' );
		// Wait for settings to finish loading so the TTS section is rendered.
		await page
			.locator( '.sdaa-settings-loading' )
			.waitFor( { state: 'hidden', timeout: 15_000 } );
	} );

	test( 'Managed voice settings are present in the General tab', async ( {
		page,
	} ) => {
		const ttsHeading = page.getByRole( 'heading', {
			name: /managed voice/i,
		} );
		await expect( ttsHeading ).toBeVisible();
	} );

	test( 'TTS enable toggle is visible in the Text-to-Speech settings section', async ( {
		page,
	} ) => {
		// The ToggleControl for TTS auto-speak should be visible.
		// WordPress ToggleControl renders a <label> containing the label text.
		// The actual label is "Read AI responses aloud automatically".
		const ttsToggleLabel = page.getByText(
			'Read AI responses aloud automatically',
			{ exact: false }
		);
		await expect( ttsToggleLabel ).toBeVisible();
	} );

	test( 'enabling TTS in settings persists the toggle state', async ( {
		page,
	} ) => {
		// Find the toggle input for the TTS auto-speak setting.
		// WordPress ToggleControl renders a checkbox input inside a label.
		// The actual label is "Read AI responses aloud automatically".
		const ttsToggle = page
			.locator( '.components-toggle-control' )
			.filter( { hasText: 'Read AI responses aloud automatically' } )
			.locator( 'input[type="checkbox"]' );

		const wasChecked = await ttsToggle.isChecked();

		// Toggle to the opposite state.
		await ttsToggle.click();
		const nowChecked = await ttsToggle.isChecked();
		expect( nowChecked ).toBe( ! wasChecked );

		// Toggle back to original state to avoid polluting other tests.
		await ttsToggle.click();
		const restoredChecked = await ttsToggle.isChecked();
		expect( restoredChecked ).toBe( wasChecked );
	} );
} );

// ---------------------------------------------------------------------------
// Tests: TTS auto-speak on AI responses
// ---------------------------------------------------------------------------

test.describe( 'TTS Auto-Speak on AI Responses', () => {
	test.beforeEach( async ( { page } ) => {
		await injectTtsMock( page );
		await loginToWordPress( page );
		await goToAgentPage( page );
	} );

	test( 'managed audio plays when read-aloud is enabled and AI responds', async ( {
		page,
	} ) => {
		// Enable TTS via the header toggle. The admin page uses ChatRedesign
		// (.sdaa-cr); scope to that root to avoid matching the floating widget.
		const ttsBtn = page
			.locator( '.sdaa-cr .sdaa-tts-btn' )
			.first();
		await expect( ttsBtn ).toBeVisible( { timeout: 15_000 } );

		const isActive = await ttsBtn.evaluate( ( el ) =>
			el.classList.contains( 'is-active' )
		);
		if ( ! isActive ) {
			await ttsBtn.click();
		}
		await expect( ttsBtn ).toHaveClass( /is-active/ );

		// Intercept the stream so the AI response completes quickly.
		// Use processingPolls: 1 so the store's job polling settles its
		// internal state (currentJobId, sessionJob) before transitioning
		// to complete — this matches real-world timing more closely.
		await interceptStream( page, { processingPolls: 1 } );

		// Count existing assistant bubbles BEFORE sending so we can detect
		// the NEW bubble that appears from THIS response. If a previous test
		// (or a prior session reload) already showed an assistant bubble,
		// waiting for `.first()` would resolve immediately and TTS polling
		// would start before the new response is processed — causing a race
		// where speak() is called after the 10 s window expires.
		// ChatRedesign renders model messages as .sdaa-cr-msg-assistant rows.
		const assistantBubbleLocator = page.locator( '.sdaa-cr-msg-assistant' );
		const initialBubbleCount = await assistantBubbleLocator.count();

		// Send a message. Scope to the ChatRedesign root (.sdaa-cr).
		const input = page
			.locator( '.sdaa-cr .sdaa-cr-input-textarea' )
			.first();
		await input.fill( 'Hello' );
		await input.press( 'Enter' );

		// Wait for a NEW assistant bubble to appear (count must exceed the
		// initial count). This avoids the false-positive from pre-existing
		// assistant bubbles and ensures TTS polling starts only after the
		// response from THIS message is in the store.
		await page.waitForFunction(
			( count ) =>
				document.querySelectorAll( '.sdaa-cr-msg-assistant' ).length >
				count,
			initialBubbleCount,
			{ timeout: 30_000 }
		);

		// Wait for the store to finish sending (sending=false). The TTS
		// effect only fires when sending is false, so we need to ensure the
		// full job-completion state transition (setCurrentSession →
		// setSending(false)) has been processed by React before polling for
		// speak calls. The message input being enabled is a reliable proxy.
		await expect(
			page
				.locator( '.sdaa-cr .sdaa-cr-input-textarea' )
				.first()
		).toBeEnabled( { timeout: 15_000 } );

		// Verify that speak() was called on the mock.
		// TTS fires asynchronously after the stream completes (the useEffect
		// in MessageList waits for sending=false + last message role=model),
		// so poll until the speak call appears rather than checking synchronously.
		// The wp.abilities stub injected by injectTtsMock() ensures that
		// ensureClientAbilitiesRegistered() resolves immediately, so the full
		// send-message pipeline (POST /run → pollJob → session fetch →
		// setSending(false)) completes in ~5 s with processingPolls: 1.
		await expect
			.poll(
				async () => {
					const calls = await page.evaluate(
						() => window.__ttsMockCalls
					);
					return calls.filter( ( c ) => c.type === 'play' ).length;
				},
				{ timeout: 20_000 }
			)
			.toBeGreaterThanOrEqual( 1 );
	} );

	test( 'managed audio does not play when read-aloud is disabled', async ( {
		page,
	} ) => {
		// Ensure TTS is disabled. Scope to the ChatRedesign root (.sdaa-cr).
		const ttsBtn = page
			.locator( '.sdaa-cr .sdaa-tts-btn' )
			.first();
		await expect( ttsBtn ).toBeVisible( { timeout: 15_000 } );

		const isActive = await ttsBtn.evaluate( ( el ) =>
			el.classList.contains( 'is-active' )
		);
		if ( isActive ) {
			await ttsBtn.click();
		}
		await expect( ttsBtn ).not.toHaveClass( /is-active/ );

		// Intercept the stream.
		await interceptStream( page );

		// Capture the current assistant-bubble count so we can wait for a
		// genuinely NEW response (avoids matching a pre-existing bubble).
		// ChatRedesign renders model messages as .sdaa-cr-msg-assistant rows.
		const assistantBubbleLocator = page.locator( '.sdaa-cr-msg-assistant' );
		const initialBubbleCount = await assistantBubbleLocator.count();

		// Send a message. Scope to the ChatRedesign root (.sdaa-cr).
		const input = page
			.locator( '.sdaa-cr .sdaa-cr-input-textarea' )
			.first();
		await input.fill( 'Hello' );
		await input.press( 'Enter' );

		// Wait for a NEW assistant bubble (count must exceed the initial).
		await page.waitForFunction(
			( count ) =>
				document.querySelectorAll( '.sdaa-cr-msg-assistant' ).length >
				count,
			initialBubbleCount,
			{ timeout: 30_000 }
		);

		// Wait a short period for any async TTS effects to fire —
		// we want to confirm speak was NOT called even after settling.
		// eslint-disable-next-line playwright/no-wait-for-timeout
		await page.waitForTimeout( 1_000 );

		// Verify that speak() was NOT called.
		const calls = await page.evaluate( () => window.__ttsMockCalls );
		const speakCalls = calls.filter( ( c ) => c.type === 'play' );
		expect( speakCalls.length ).toBe( 0 );
	} );

	test( 'the active read-aloud control stops audio and revokes its URL', async ( {
		page,
	} ) => {
		const ttsBtn = page
			.locator( '.sdaa-cr .sdaa-tts-btn' )
			.first();
		await expect( ttsBtn ).toBeVisible( { timeout: 15_000 } );

		const isActive = await ttsBtn.evaluate( ( el ) =>
			el.classList.contains( 'is-active' )
		);
		if ( ! isActive ) {
			await ttsBtn.click();
		}
		await expect( ttsBtn ).toHaveClass( /is-active/ );

		await interceptStream( page );
		const input = page
			.locator( '.sdaa-cr .sdaa-cr-input-textarea' )
			.first();
		await input.fill( 'Read this response' );
		await input.press( 'Enter' );
		await expect( ttsBtn ).toHaveAttribute(
			'aria-label',
			'Stop reading aloud',
			{ timeout: 30_000 }
		);
		await ttsBtn.click();

		const calls = await page.evaluate( () => window.__ttsMockCalls );
		expect( calls.some( ( call ) => call.type === 'pause' ) ).toBe( true );
		expect( calls.some( ( call ) => call.type === 'revoke' ) ).toBe( true );
	} );
} );
