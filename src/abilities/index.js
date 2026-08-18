/**
 * Client-side abilities entry point.
 *
 * Registers the sd-ai-agent-js category and all client-side abilities
 * into the WP 7.0 `core/abilities` store. Import this module at the top of
 * each plugin entry point so registration happens before the chat UI mounts.
 *
 * This module is idempotent — safe to import multiple times.
 *
 * Order matters here: the category MUST be registered (and its async
 * `registerAbilityCategory` Promise MUST resolve) before any ability that
 * lives in it. Previously navigation.js and editor.js self-registered at
 * module-eval time and the registry helpers called the WP 7.0 API
 * synchronously without awaiting the returned Promises — leaving abilities
 * trying to register into a not-yet-resolved category and the
 * `@wordpress/abilities` library throwing
 * "Ability references non-existent category" errors. (Fix landed in t166.)
 *
 * Cross-bundle deduplication (GH#990):
 * Each webpack entry-point bundle has its own module scope and therefore its
 * own `registrationPromise`. When multiple bundles (e.g. floating-widget.js
 * and admin-page.js) are enqueued on the same admin page, each bundle
 * previously ran the full registration pipeline independently. This caused
 * `wp.abilities.registerAbilityCategory()` to be called once per bundle,
 * which in WP 7.0-RC2 triggers a REST fetch to `/wp-json/wp-abilities/v1/abilities`
 * for each call — resulting in duplicate simultaneous requests, one of which
 * was aborted and logged a console error.
 *
 * The fix: `ensureRegistered()` checks a page-level window global
 * (`window.__sdAiAgentAbilitiesRegistering`) before creating a new
 * Promise. If another bundle on the same page has already started or
 * completed the pipeline, the second bundle awaits the same Promise instead
 * of launching a duplicate registration.
 */

import { registerCategory } from './registry';
import { registerNavigationAbility } from './navigation';
import { registerRefreshPageAbility } from './refresh-page';
import { registerEditorAbility } from './editor';
import { registerEditorCapabilitiesAbility } from './editor-capabilities';
import {
	registerCaptureScreenshotAbility,
	registerScreenshotUrlAbility,
} from './screenshot';

/**
 * Window-global key used to share the registration Promise across all
 * webpack bundles on the same page. The first bundle to run
 * `ensureRegistered()` creates and stores the Promise; every subsequent
 * bundle (in any other webpack scope) reads and awaits it instead of
 * starting a new registration pipeline.
 *
 * @type {string}
 */
const WIN_REGISTRATION_KEY = '__sdAiAgentAbilitiesRegistering';

/**
 * Single in-flight registration Promise for this module instance, so
 * concurrent callers (e.g. multiple components in the same bundle that
 * each call ensureRegistered()) await the same pipeline rather than
 * racing.
 *
 * @type {Promise<void>|null}
 */
let registrationPromise = null;

/**
 * Ensure all client-side abilities are registered.
 *
 * Idempotent — calling this multiple times within a single bundle returns
 * the same in-flight Promise. Cross-bundle dedup is provided by the
 * page-level `window.__sdAiAgentAbilitiesRegistering` key: if another
 * bundle on the same page has already started or completed registration,
 * this call returns the existing Promise without re-running the pipeline.
 *
 * If the abilities API was not available when the attempt ran (e.g. the
 * `@wordpress/core-abilities` script module hadn't loaded yet),
 * registerCategory() silently no-ops and the promise resolves without
 * registering anything. In that case both the module-level promise and the
 * window global are reset to null AFTER the attempt completes so a future
 * call can retry. Concurrent callers during the in-flight attempt all
 * receive the same promise — the reset only happens once the promise settles.
 *
 * @return {Promise<void>}
 */
export function ensureRegistered() {
	// Cross-bundle dedup: another webpack bundle on this page may have
	// already started or completed the registration pipeline. Reuse its
	// Promise so we don't call wp.abilities.registerAbilityCategory() a
	// second time (each call can trigger a REST fetch in WP 7.0-RC2).
	if ( window[ WIN_REGISTRATION_KEY ] ) {
		registrationPromise = window[ WIN_REGISTRATION_KEY ];
		return registrationPromise;
	}

	// Same-bundle dedup: a concurrent caller within this bundle.
	if ( registrationPromise ) {
		return registrationPromise;
	}

	// Set both caches before any await so concurrent callers from this
	// bundle and from other bundles that load immediately after see the
	// in-flight Promise rather than starting a new one.
	registrationPromise = window[ WIN_REGISTRATION_KEY ] = ( async () => {
		// Category MUST come first AND its Promise MUST resolve before
		// abilities can register into it.
		await registerCategory();
		// Now safe to register abilities — the category exists in the store.
		await registerNavigationAbility();
		await registerRefreshPageAbility();
		await registerEditorAbility();
		await registerEditorCapabilitiesAbility();
		await (
			await import( './block-examples' )
		).registerCanonicalBlockExamplesAbility();
		await registerCaptureScreenshotAbility();
		await registerScreenshotUrlAbility();
		try {
			await ( await import( './page-quality-validator' ) ).default();
		} catch {
			// Keep the already-registered core browser abilities available.
		}

		// If the abilities API was not available (e.g. script module not
		// yet loaded), the registration calls above silently no-oped.
		// Reset both caches so a future call can retry after the API loads.
		// This MUST happen after the await chain settles — resetting during
		// the in-flight attempt would break concurrent callers' dedup.
		const apiAvailable =
			typeof wp !== 'undefined' &&
			!! wp.abilities &&
			typeof wp.abilities.getAbilities === 'function';
		if ( ! apiAvailable ) {
			registrationPromise = null;
			window[ WIN_REGISTRATION_KEY ] = null;
		}
	} )();

	return registrationPromise;
}

// Auto-register on import so plugin entry points only need a side-effect
// import (`import '../abilities';`) without remembering to call
// ensureRegistered. Callers that need to wait for registration to finish
// (e.g. the chat send-message thunk before snapshotting descriptors) can
// import { ensureRegistered } and `await` it.
ensureRegistered();
