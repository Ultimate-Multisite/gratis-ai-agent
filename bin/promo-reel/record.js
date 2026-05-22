#!/usr/bin/env node
/**
 * Superdav AI Agent — promo-reel recorder.
 *
 * Reads bin/promo-reel/prompts.json, opens a Playwright Chromium window at a
 * mobile viewport, logs into the target WordPress instance, and for every
 * beat of kind "prompt" sends the prompt to the agent and records the chat
 * surface to bin/promo-reel/output/clips/<beat-id>.webm.
 *
 * Each beat is recorded in its own browser context so Playwright writes one
 * standalone .webm per clip — no manual seeking needed during assembly.
 *
 * Usage:
 *   node bin/promo-reel/record.js                       # all prompt beats
 *   node bin/promo-reel/record.js --only 1-theme        # one beat
 *   node bin/promo-reel/record.js --base http://wordpress.local:8080 \
 *                                --user admin --pass admin
 *
 * Env vars:
 *   WP_BASE_URL       (default http://localhost:8890 — wp-env dev)
 *   WP_ADMIN_USER     (default admin)
 *   WP_ADMIN_PASSWORD (default password — wp-env default)
 *
 * Exit codes:
 *   0  every requested beat recorded
 *   1  at least one beat failed (timeout, no response, login error)
 *   2  configuration error (missing prompts.json, bad CLI args)
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
// Use @playwright/test rather than standalone playwright — the project
// already depends on @playwright/test for e2e specs, and both packages
// export the same chromium driver. Keeping one Playwright surface avoids
// having to install a second copy in CI / worktrees.
const { chromium } = require( '@playwright/test' );

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------

const REEL_DIR = __dirname;
const PROMPTS_PATH = path.join( REEL_DIR, 'prompts.json' );
const OUTPUT_DIR = path.join( REEL_DIR, 'output' );
const CLIPS_DIR = path.join( OUTPUT_DIR, 'clips' );

if ( ! fs.existsSync( PROMPTS_PATH ) ) {
	console.error( `[promo-reel] missing ${ PROMPTS_PATH }` );
	process.exit( 2 );
}

const config = JSON.parse( fs.readFileSync( PROMPTS_PATH, 'utf8' ) );

const args = parseArgs( process.argv.slice( 2 ) );
const baseUrl = args.base || process.env.WP_BASE_URL || 'http://localhost:8890';
const adminUser = args.user || process.env.WP_ADMIN_USER || 'admin';
const adminPass = args.pass || process.env.WP_ADMIN_PASSWORD || 'password';

// Auth strategy: 'form' (default — submit wp-login.php form) or 'wp-cli'
// (mint an auth cookie via `wp eval` and inject it into the Playwright
// context). The wp-cli path bypasses brute-force / IP-lockout plugins
// like WP Defender, which is essential when targeting a hardened dev
// install.
const authMode = args.auth || process.env.WP_AUTH_MODE || 'form';
if ( authMode !== 'form' && authMode !== 'wp-cli' ) {
	console.error(
		`[promo-reel] invalid --auth mode: ${ authMode } (use 'form' or 'wp-cli')`
	);
	process.exit( 2 );
}
const wpCliPath = args.wpCli || process.env.WP_CLI_PATH || 'wp';

// Optional model pinning. When set, the recorder seeds localStorage so
// the chat boots with this provider/model selected, side-stepping any
// previously-saved per-user preference. Format: "provider-id:model-id".
const pinnedModel = args.model || process.env.WP_AGENT_MODEL || '';

fs.mkdirSync( CLIPS_DIR, { recursive: true } );

// ---------------------------------------------------------------------------
// Selectors (mirror tests/e2e/utils/wp-admin.js — keep in sync)
// ---------------------------------------------------------------------------

const SELECTORS = {
	chatRoot: '.sdaa-cr',
	input: '.sdaa-cr .sdaa-cr-input-textarea',
	send: '.sdaa-cr .sdaa-cr-send-btn:not(.is-stop)',
	stop: '.sdaa-cr .sdaa-cr-send-btn.is-stop',
	messages: '.sdaa-cr .sdaa-cr-messages',
	messageRow: '.sdaa-cr .sdaa-cr-msg-row',
};

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

( async () => {
	const beats = config.beats.filter( ( b ) => b.kind === 'prompt' );
	const target = args.only
		? beats.filter( ( b ) => b.id === args.only )
		: beats;

	if ( target.length === 0 ) {
		console.error(
			`[promo-reel] no matching prompt beats (only=${
				args.only || 'all'
			})`
		);
		process.exit( 2 );
	}

	console.log(
		`[promo-reel] base=${ baseUrl } user=${ adminUser } auth=${ authMode }`
	);
	console.log(
		`[promo-reel] viewport ${ config.viewport.width }×${ config.viewport.height } @ DPR ${ config.viewport.deviceScaleFactor }`
	);
	console.log( `[promo-reel] recording ${ target.length } beat(s)` );

	// In wp-cli auth mode we mint a single auth cookie up front and reuse
	// it across every beat — the recorder never touches wp-login.php.
	let storageState = null;
	if ( authMode === 'wp-cli' ) {
		try {
			storageState = mintAuthStateViaWpCli();
			console.log( '[promo-reel] auth via wp-cli — cookie minted' );
		} catch ( err ) {
			console.error(
				`[promo-reel] wp-cli auth failed: ${ err.message || err }`
			);
			process.exit( 1 );
		}
	}
	let failures = 0;

	for ( const beat of target ) {
		const result = await recordBeat( beat, storageState );
		if ( result.ok ) {
			storageState = result.storageState; // reuse login cookies between beats
			console.log(
				`[promo-reel] ✓ ${ beat.id } → ${ path.relative(
					REEL_DIR,
					result.outputPath
				) } (${ result.elapsedMs } ms)`
			);
		} else {
			failures += 1;
			console.error(
				`[promo-reel] ✗ ${ beat.id } failed: ${ result.error }`
			);
		}
	}

	console.log(
		`[promo-reel] done — ${ target.length - failures }/${
			target.length
		} beats recorded`
	);
	process.exit( failures > 0 ? 1 : 0 );
} )().catch( ( err ) => {
	console.error( '[promo-reel] fatal:', err );
	process.exit( 1 );
} );

// ---------------------------------------------------------------------------
// Beat recorder
// ---------------------------------------------------------------------------

/**
 * Record a single prompt beat.
 *
 * Returns { ok, outputPath, elapsedMs, storageState } on success or
 * { ok:false, error } on failure. Failures are non-fatal at the call site —
 * the main loop continues to the next beat and reports the count.
 *
 * @param {Object}      beat         Beat definition from prompts.json.
 * @param {Object|null} storageState Saved login storage from previous beat.
 * @return {Promise<Object>} Result envelope: `{ ok, outputPath, elapsedMs, storageState }` on success, `{ ok: false, error }` on failure.
 */
async function recordBeat( beat, storageState ) {
	const start = Date.now();
	const browser = await chromium.launch( { headless: true } );

	const contextOptions = {
		viewport: {
			width: config.viewport.width,
			height: config.viewport.height,
		},
		deviceScaleFactor: config.viewport.deviceScaleFactor || 1,
		isMobile: true,
		hasTouch: true,
		recordVideo: {
			dir: CLIPS_DIR,
			size: {
				width:
					config.viewport.width *
					( config.viewport.deviceScaleFactor || 1 ),
				height:
					config.viewport.height *
					( config.viewport.deviceScaleFactor || 1 ),
			},
		},
	};
	if ( storageState ) {
		contextOptions.storageState = storageState;
	}

	const context = await browser.newContext( contextOptions );
	context.setDefaultTimeout( 60_000 );

	// Pre-seed localStorage so the chat boots in a known good state:
	//   - sdAiAgentChatSidebarCollapsed=1 hides the session sidebar so
	//     the chat surface fills the viewport (also makes the mobile
	//     breakpoint show the chat instead of the sidebar).
	//   - sdAiAgentProvider / sdAiAgentModel pin the model the chat will
	//     send the prompt to, side-stepping any stale per-user choice.
	const pinProviderId = pinnedModel.split( ':' )[ 0 ] || '';
	const pinModelId = pinnedModel.split( ':' )[ 1 ] || '';
	await context.addInitScript(
		( { providerId, modelId } ) => {
			try {
				window.localStorage.setItem(
					'sdAiAgentChatSidebarCollapsed',
					'1'
				);
				if ( providerId ) {
					window.localStorage.setItem(
						'sdAiAgentProvider',
						providerId
					);
				}
				if ( modelId ) {
					window.localStorage.setItem( 'sdAiAgentModel', modelId );
				}
			} catch ( _e ) {
				// Storage unavailable — fall back to in-page UI flows.
			}

			// Hide WP admin chrome (top bar, left menu) so the chat
			// surface owns the full viewport — gives the reel a clean
			// product-focused look instead of a "screenshot of a CMS".
			// Injected once DOM is ready so the <head> exists.
			const inject = () => {
				const style = document.createElement( 'style' );
				style.dataset.promoReel = '1';
				style.textContent = [
					'html.wp-toolbar { padding-top: 0 !important; }',
					'#wpadminbar { display: none !important; }',
					'#adminmenumain, #adminmenuback, #adminmenuwrap { display: none !important; }',
					'#wpcontent, #wpfooter { margin-left: 0 !important; padding-left: 0 !important; }',
					'#wpbody-content { padding: 0 !important; }',
					'.notice, .updated, .error, #screen-meta-links { display: none !important; }',
				].join( '\n' );
				document.head.appendChild( style );
			};
			if ( document.readyState === 'loading' ) {
				document.addEventListener( 'DOMContentLoaded', inject );
			} else {
				inject();
			}
		},
		{ providerId: pinProviderId, modelId: pinModelId }
	);

	const page = await context.newPage();
	let videoPath = null;

	try {
		// 1. Ensure we're logged in.
		//    - wp-cli mode: storageState was minted up front, no form submit.
		//    - form mode: only the first beat hits wp-login.php; the
		//      returned context.storageState() is reused on subsequent beats.
		if ( ! storageState ) {
			await login( page );
		}

		// 2. Navigate to the agent admin page (chat route).
		await page.goto( `${ baseUrl }/wp-admin/admin.php?page=sd-ai-agent` );
		await page.waitForLoadState( 'domcontentloaded' );
		await page
			.locator( SELECTORS.chatRoot )
			.waitFor( { state: 'visible', timeout: 45_000 } );

		// 3. Start a fresh session via the /new slash command so each beat
		//    records on an empty chat surface. Falls back silently if the
		//    slash menu is missing on this build.
		await startFreshSession( page );

		// 4. Type the prompt.
		const input = page.locator( SELECTORS.input ).first();
		await input.click();
		await input.fill( beat.prompt );
		await page.waitForTimeout( config.wait.post_send_settle_ms );

		// 5. Send.
		await page.locator( SELECTORS.send ).first().click();

		// 6. Wait for the agent loop to finish: the stop button appears
		//    while the job is in flight and disappears (replaced by send)
		//    when status=complete | error | awaiting_confirmation.
		await waitForAgentComplete( page, config.wait.response_timeout_ms );

		// 7. Hold a beat so the final assistant message lands on screen
		//    before we close the recording.
		await page.waitForTimeout( config.wait.post_complete_settle_ms );

		// 8. Save login state for reuse by subsequent beats.
		const nextStorage = await context.storageState();

		// 9. Close cleanly so Playwright finalises the .webm.
		videoPath = await page.video()?.path();
		await context.close();
		await browser.close();

		// 10. Rename the auto-generated video filename → <beat-id>.webm.
		const finalPath = path.join( CLIPS_DIR, `${ beat.id }.webm` );
		if ( videoPath && fs.existsSync( videoPath ) ) {
			fs.renameSync( videoPath, finalPath );
		}

		return {
			ok: true,
			outputPath: finalPath,
			elapsedMs: Date.now() - start,
			storageState: nextStorage,
		};
	} catch ( err ) {
		// Save a failure screenshot to help debugging without a video.
		try {
			const shotPath = path.join( CLIPS_DIR, `${ beat.id }.error.png` );
			await page.screenshot( { path: shotPath, fullPage: false } );
		} catch ( _e ) {
			// Best-effort only.
		}
		try {
			await context.close();
			await browser.close();
		} catch ( _e ) {
			// Already closed.
		}
		return { ok: false, error: err.message || String( err ) };
	}
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Log in to WordPress at $baseUrl with the configured admin credentials.
 *
 * @param {import('playwright').Page} page
 * @return {Promise<void>}
 */
async function login( page ) {
	await page.goto( `${ baseUrl }/wp-login.php` );
	await page.fill( '#user_login', adminUser );
	await page.fill( '#user_pass', adminPass );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/, { timeout: 60_000 } );
	return undefined;
}

/**
 * Mint a WordPress auth cookie via wp-cli and return a Playwright
 * storageState object that the browser context can adopt.
 *
 * This bypasses wp-login.php entirely — useful when the target site has a
 * brute-force / IP-lockout plugin (WP Defender, Limit Login Attempts,
 * Wordfence). The minted cookie is identical to what WordPress sets when
 * a user submits the login form, so every protected route the recorder
 * touches behaves exactly the same as a real session.
 *
 * Requires wp-cli on PATH and a working `wp-cli.yml` in the target repo
 * pointing at the right WordPress install. Run the recorder from the
 * superdav-ai-agent repo root so wp-cli resolves the right WP_HOME.
 *
 * @return {{cookies: Array, origins: Array}} Playwright storageState.
 */
function mintAuthStateViaWpCli() {
	// Look up cookie name, domain, path, value via wp eval. The output is
	// machine-parseable (KEY=VALUE per line) so we can strip WP-CLI's
	// Notice/Deprecated chatter on the way through.
	const phpScript = [
		'$user = get_user_by("login", "' +
			adminUser.replace( /"/g, '\\"' ) +
			'");',
		'if ( ! $user ) { echo "ERROR=user_not_found\\n"; exit(1); }',
		'$expiration = time() + 86400;',
		'$manager = WP_Session_Tokens::get_instance( $user->ID );',
		'$token = $manager->create( $expiration );',
		'$cookie = wp_generate_auth_cookie( $user->ID, $expiration, "logged_in", $token );',
		'echo "NAME=" . LOGGED_IN_COOKIE . "\\n";',
		'echo "VALUE=" . $cookie . "\\n";',
		'echo "EXP=" . $expiration . "\\n";',
		'echo "DOMAIN=" . ( defined( "COOKIE_DOMAIN" ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : "" ) . "\\n";',
		'echo "PATH=" . ( defined( "COOKIEPATH" ) ? COOKIEPATH : "/" ) . "\\n";',
	].join( ' ' );

	// Use execFileSync (no shell) so the PHP script is passed verbatim
	// as a single argv element — otherwise shell variable expansion
	// turns `$user` into an empty string and PHP rejects the result.
	let raw;
	try {
		raw = execFileSync( wpCliPath, [ 'eval', phpScript ], {
			encoding: 'utf8',
			stdio: [ 'ignore', 'pipe', 'pipe' ],
		} );
	} catch ( err ) {
		const tail = String( err.stderr || err.stdout || '' )
			.split( '\n' )
			.slice( -20 )
			.join( '\n' );
		throw new Error( `wp eval exited ${ err.status ?? '?' }\n${ tail }` );
	}

	const fields = {};
	for ( const line of raw.split( '\n' ) ) {
		const m = line.match( /^([A-Z_]+)=(.*)$/ );
		if ( m ) {
			fields[ m[ 1 ] ] = m[ 2 ];
		}
	}
	if ( fields.ERROR ) {
		throw new Error( `wp-cli: ${ fields.ERROR }` );
	}
	if ( ! fields.NAME || ! fields.VALUE ) {
		throw new Error(
			`wp-cli returned no cookie (raw=${ raw.slice( 0, 200 ) })`
		);
	}

	// Playwright wants the host without scheme/port, leading dot optional.
	// Derive it from baseUrl so the cookie is scoped correctly even when
	// COOKIE_DOMAIN is empty (single-site installs).
	const url = new URL( baseUrl );
	const cookieDomain = fields.DOMAIN || url.hostname;
	const cookiePath = fields.PATH || '/';

	return {
		cookies: [
			{
				name: fields.NAME,
				value: fields.VALUE,
				domain: cookieDomain,
				path: cookiePath,
				expires: Number( fields.EXP ) || -1,
				httpOnly: true,
				secure: url.protocol === 'https:',
				sameSite: 'Lax',
			},
		],
		origins: [],
	};
}

/**
 * Start a fresh chat session by sending /new via the slash menu.
 *
 * This is best-effort — older or stripped builds may not expose the slash
 * menu. We don't fail the beat if /new doesn't work; the recording just
 * starts on the existing session.
 *
 * @param {import('playwright').Page} page
 * @return {Promise<void>}
 */
async function startFreshSession( page ) {
	// Click the persistent "+ New chat" button in the sidebar. This works
	// at all viewport widths — including the mobile layout where the
	// sidebar takes the full screen and the chat surface (with the
	// textarea we want) is hidden until a session is opened.
	//
	// Falls back to the /new slash command if the button isn't found
	// (older builds, customised UIs). If both fail we proceed on whatever
	// session is active — non-fatal so the recorder still produces a
	// clip showing whatever state the chat is in.
	try {
		const newChatBtn = page.locator( '.sdaa-cr-new-chat' ).first();
		await newChatBtn.waitFor( { state: 'visible', timeout: 5_000 } );
		await newChatBtn.click();
		// On mobile the textarea only renders after the chat surface
		// is mounted — wait for it before returning so the caller can
		// fill it without racing.
		await page
			.locator( SELECTORS.input )
			.first()
			.waitFor( { state: 'visible', timeout: 10_000 } );
		return undefined;
	} catch ( _e ) {
		// Fall through to slash-command fallback.
	}

	try {
		const input = page.locator( SELECTORS.input ).first();
		await input.click();
		await input.fill( '/new' );
		const newItem = page
			.locator( '.sdaa-slash-item' )
			.filter( { hasText: '/new' } )
			.first();
		await newItem.waitFor( { state: 'visible', timeout: 3_000 } );
		await newItem.click();
		await page
			.locator( '.sdaa-cr-empty, .sdaa-cr-msg-row' )
			.first()
			.waitFor( { state: 'visible', timeout: 5_000 } );
	} catch ( _e ) {
		// Both paths unavailable — proceed on the current session.
	}
	return undefined;
}

/**
 * Block until the agent loop is no longer in-flight.
 *
 * The store toggles the send button to .is-stop while a job is running and
 * back to a plain .sdaa-cr-send-btn when status ∈ {complete, error,
 * awaiting_confirmation}. We watch for the .is-stop class to appear, then
 * disappear. If .is-stop never appears (race or very fast job), we fall back
 * to a fixed grace window.
 *
 * @param {import('playwright').Page} page
 * @param {number}                    timeoutMs
 * @return {Promise<void>}
 */
async function waitForAgentComplete( page, timeoutMs ) {
	const stop = page.locator( SELECTORS.stop ).first();

	// Phase 1: stop button becomes visible (job started).
	try {
		await stop.waitFor( { state: 'visible', timeout: 10_000 } );
	} catch ( _e ) {
		// Job may have finished before we observed the transition.
		// Give it a small grace window and return.
		await page.waitForTimeout( 1_500 );
		return undefined;
	}

	// Phase 2: stop button disappears (job done).
	await stop.waitFor( { state: 'hidden', timeout: timeoutMs } );
	return undefined;
}

/**
 * Parse the recorder's CLI arguments.
 *
 * Supports: --only <beat-id>, --base <url>, --user <login>, --pass <pwd>.
 * Unknown flags are ignored to keep the recorder lenient.
 *
 * @param {string[]} argv
 * @return {Object} Parsed flag map keyed by the long-form flag name (without the leading `--`).
 */
function parseArgs( argv ) {
	const out = {};
	for ( let i = 0; i < argv.length; i += 1 ) {
		const key = argv[ i ];
		const value = argv[ i + 1 ];
		if ( key === '--only' ) {
			out.only = value;
			i += 1;
		} else if ( key === '--base' ) {
			out.base = value;
			i += 1;
		} else if ( key === '--user' ) {
			out.user = value;
			i += 1;
		} else if ( key === '--pass' ) {
			out.pass = value;
			i += 1;
		} else if ( key === '--auth' ) {
			out.auth = value;
			i += 1;
		} else if ( key === '--wp-cli' ) {
			out.wpCli = value;
			i += 1;
		} else if ( key === '--model' ) {
			out.model = value;
			i += 1;
		}
	}
	return out;
}
