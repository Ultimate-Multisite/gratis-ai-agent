/**
 * Client-side abilities registry for sd-ai-agent-js namespace.
 *
 * Thin wrapper around @wordpress/abilities (exposed by core 7.0 as the
 * `wp.abilities` global, populated when the script module loads) that:
 *
 *   - Registers the sd-ai-agent-js category (idempotent, async).
 *   - Provides registerClientAbility() to register abilities with the
 *     correct shape required by the WP 7.0 API (single options object,
 *     with `name` as a property).
 *   - Provides snapshotDescriptors() to capture current descriptors for
 *     posting to the server in the /chat request body
 *     (used by sessionsSlice sendMessage thunk).
 *
 * Bug history (#806 → #815 → #821 → t166):
 *   - #815 (in #815's PR) shipped this file with three API misuses that
 *     prevented any ability from registering at runtime:
 *       a) registerAbilityCategory() was called without a `description`
 *          field, which is required per the WP 7.0 dev note. Fix: add it.
 *       b) registerAbility() was called with two positional arguments
 *          (def.name, options) instead of the documented single options
 *          object containing `name`. The library read `.name` off the
 *          options bag, found undefined, and threw "Ability name is
 *          required". Fix: pass a single object.
 *       c) Both registerAbilityCategory() and registerAbility() in the
 *          WP 7.0 API are ASYNC and return Promises. The category Promise
 *          had not yet resolved when the abilities tried to register
 *          synchronously, so the abilities looked up a not-yet-existent
 *          category and threw "Ability references non-existent category".
 *          Fix: await every registration call.
 *
 *   - #821 (t165) wired the entry-point imports so this file actually
 *     ran, which surfaced the throws above as visible console errors
 *     during a browser smoke test.
 *
 *   - This task (t166) fixes all three API misuses and makes the
 *     registration pipeline properly async-aware.
 */

const CATEGORY_SLUG = 'sd-ai-agent-js';
const CATEGORY_LABEL = 'Superdav AI Agent (Client)';
const CATEGORY_DESCRIPTION =
	'Client-side abilities provided by the Superdav AI Agent plugin. Execute in the browser without a server round-trip.';

/**
 * Single category-registration Promise shared across all callers in the
 * same module instance, so concurrent ensureRegistered() calls await the
 * same in-flight registration instead of racing.
 *
 * @type {Promise<void>|null}
 */
let categoryRegistrationPromise = null;

/**
 * Per-ability deduplication set so the same ability isn't registered
 * twice from a second bundle on the same page (each entry-point bundle
 * has its own module instance).
 *
 * @type {Set<string>}
 */
const registeredAbilityNames = new Set();

/**
 * Callback map — name → async function(args) → result.
 *
 * Maintained in parallel to the WP 7.0 registry so jobSlice can invoke
 * abilities by name without relying on wp.abilities.executeAbility() (which
 * may not be available or may not surface the raw return value we need).
 *
 * @type {Map<string, Function>}
 */
const clientCallbacks = new Map();

/**
 * Detect whether the WP 7.0 abilities API is available on this page.
 *
 * @return {boolean} True when wp.abilities is loaded and exposes the
 *                   functions we need.
 */
function abilitiesApiAvailable() {
	return (
		typeof wp !== 'undefined' &&
		!! wp.abilities &&
		typeof wp.abilities.registerAbility === 'function' &&
		typeof wp.abilities.registerAbilityCategory === 'function'
	);
}

/**
 * Wait up to maxWaitMs for the WP 7.0 abilities API to become available.
 *
 * Addresses the race condition where floating-widget.js (a regular deferred
 * script) may execute before @wordpress/core-abilities (a script module with
 * implicit defer) has had a chance to populate wp.abilities. Previously the
 * code returned early with `undefined`, leaving abilities unregistered with
 * no retry path. Now we poll every 100 ms until the API appears or the
 * deadline passes.
 *
 * @param {number} maxWaitMs Maximum milliseconds to wait (default 30 000).
 * @return {Promise<void>} Resolves when the API is available or the deadline passes.
 */
async function waitForAbilitiesApi( maxWaitMs = 30_000 ) {
	if ( abilitiesApiAvailable() ) {
		return;
	}
	await new Promise( ( resolve ) => {
		const deadline = Date.now() + maxWaitMs;
		const check = () => {
			if ( abilitiesApiAvailable() || Date.now() >= deadline ) {
				resolve();
			} else {
				setTimeout( check, 100 );
			}
		};
		setTimeout( check, 50 );
	} );
}

/**
 * Register the sd-ai-agent-js category (idempotent, async).
 *
 * Must be awaited before any registerClientAbility() call — the WP 7.0
 * `registerAbilityCategory` API is async, and abilities registered
 * before the category Promise resolves throw
 * "Ability references non-existent category".
 *
 * Multiple concurrent callers receive the same in-flight Promise so we
 * never double-register.
 *
 * @return {Promise<void>}
 */
export async function registerCategory() {
	if ( categoryRegistrationPromise ) {
		return categoryRegistrationPromise;
	}

	// Set the promise immediately — before any awaits — to prevent concurrent
	// callers from racing into this function and launching duplicate registrations.
	// The async body inside will wait for wp.abilities to become available.
	categoryRegistrationPromise = ( async () => {
		// Wait for @wordpress/core-abilities to populate wp.abilities. This
		// handles the race condition where floating-widget.js (regular deferred
		// script) runs before the @wordpress/core-abilities script module has
		// executed. Previously we returned early with `undefined`, which left
		// categoryRegistrationPromise null and silently skipped all ability
		// registration with no retry path.
		await waitForAbilitiesApi();

		if ( ! abilitiesApiAvailable() ) {
			// API never became available (e.g. not a WP 7.0+ site). Skip silently.
			return;
		}

		try {
			await wp.abilities.registerAbilityCategory( CATEGORY_SLUG, {
				label: CATEGORY_LABEL,
				description: CATEGORY_DESCRIPTION,
			} );
		} catch ( _err ) {
			// Already registered by another bundle on the same page —
			// safe to ignore. Both bundles will continue to register
			// their abilities into the same shared category.
		}
	} )();

	return categoryRegistrationPromise;
}

/**
 * Register a single client-side ability (async).
 *
 * Shapes the definition with the correct category and meta.annotations,
 * and guards against double-registration.
 *
 * The WP 7.0 `registerAbility` API takes a SINGLE options object whose
 * `name` field identifies the ability — calling it with two positional
 * arguments throws "Ability name is required". The call is also async.
 *
 * @param {Object}   def              Ability definition.
 * @param {string}   def.name         Fully-qualified ability name (sd-ai-agent-js/...).
 * @param {string}   def.label        Human-readable label.
 * @param {string}   def.description  Description of what the ability does.
 * @param {Object}   def.inputSchema  JSON Schema for the ability's input.
 * @param {Object}   def.outputSchema JSON Schema for the ability's output.
 * @param {Object}   def.annotations  Annotations (e.g. { readonly: true }).
 * @param {Function} def.callback     The function to execute when the ability is called.
 * @return {Promise<void>}
 */
export async function registerClientAbility( def ) {
	if ( ! def || typeof def.name !== 'string' || def.name === '' ) {
		// eslint-disable-next-line no-console
		console.warn(
			'[sd-ai-agent] registerClientAbility called without a name; skipping.'
		);
		return;
	}
	if ( registeredAbilityNames.has( def.name ) ) {
		return;
	}
	registeredAbilityNames.add( def.name );

	// Store the callback so executeClientAbility() can invoke it by name
	// without going through the WP abilities API (which may not expose the
	// raw return value needed for tool-result forwarding).
	//
	// IMPORTANT (sd-ai-86a): this MUST happen BEFORE the
	// abilitiesApiAvailable() guard. jobSlice's executeClientAbility()
	// reads only from this local map, so even on pages where the WP 7.0
	// `@wordpress/abilities` script module did not load in time (or at
	// all — e.g. some frontend contexts in WP 7.0-RC2), the chat job's
	// pending_client_tool_calls handler can still invoke
	// screenshot-url, navigate-to, capture-screenshot, and insert-block.
	// Without this ordering, executeClientAbility throws
	// 'Client ability "..." is not registered on this page' even though
	// the callback function is present in the bundle.
	if ( typeof def.callback === 'function' ) {
		clientCallbacks.set( def.name, def.callback );
	}

	// The WP 7.0 store is only updated when the abilities API is present
	// on this page. If it is not, the local callback above is sufficient
	// to keep client-side tool execution working; snapshotDescriptors()
	// will fall back to an empty descriptor list and the server-side
	// JsAbilityCatalog still advertises the abilities for tool calls.
	if ( ! abilitiesApiAvailable() ) {
		return;
	}

	try {
		await wp.abilities.registerAbility( {
			name: def.name,
			label: def.label,
			description: def.description,
			category: CATEGORY_SLUG,
			callback: def.callback,
			input_schema: def.inputSchema,
			output_schema: def.outputSchema,
			meta: {
				annotations: def.annotations || {},
			},
		} );
	} catch ( _err ) {
		// Already registered by another bundle on the same page — fine.
		// We've already added it to registeredAbilityNames so we won't
		// retry from this module instance.
	}
}

/**
 * Snapshot the current sd-ai-agent-js/* ability descriptors as plain objects.
 *
 * Returns an array of descriptor objects suitable for posting to the server
 * as `client_abilities` in the /chat request body. The server validates each
 * name against JsAbilityCatalog::get_descriptors() and drops unknown names.
 *
 * Reads via `wp.abilities.getAbilities()` (the script-module API) rather
 * than via `wp.data.select('core/abilities').getAbilities()`.
 *
 * Root cause (t169 / GH#825, investigated 2026-04-08):
 * The WP 7.0 dev note claims `@wordpress/core-abilities` is enqueued by
 * core on all admin pages, but in WP 7.0-RC2 the module is only
 * *registered* — never added to the script-module queue. Without an
 * explicit `wp_enqueue_script_module('@wordpress/core-abilities')` call,
 * the REST fetch that populates the `core/abilities` wp.data store never
 * runs, so `wp.data.select('core/abilities').getAbilities()` returns 0
 * items. Our PHP enqueue (FloatingWidget, UnifiedAdminMenu)
 * now explicitly enqueues `@wordpress/core-abilities` to fix this.
 *
 * The `wp.abilities.getAbilities()` call below reads from the same Redux
 * store via `select(store).getAbilities()` — it is synchronous and returns
 * an array, not a Promise. The `await` is kept for forward-compatibility
 * in case the API becomes async in a future WP version.
 *
 * TODO(t169): Once WP 7.0 final ships and the upstream enqueue gap is
 * confirmed fixed (or a core bug is filed), verify whether the explicit
 * `@wordpress/core-abilities` enqueue in our PHP files can be removed.
 * If core reliably enqueues it, the workaround becomes redundant.
 *
 * Callers should `await ensureRegistered()` from index.js before calling
 * this so the registration Promises are guaranteed to have resolved.
 *
 * @return {Promise<Array<{name: string, label: string, description: string, input_schema: Object, output_schema: Object, annotations: Object}>>} Promise of client ability descriptors.
 */
export async function snapshotDescriptors() {
	if (
		typeof wp === 'undefined' ||
		! wp.abilities ||
		typeof wp.abilities.getAbilities !== 'function'
	) {
		return [];
	}

	try {
		const allAbilities = ( await wp.abilities.getAbilities() ) || [];

		return allAbilities
			.filter(
				( ability ) =>
					ability &&
					ability.name &&
					ability.name.startsWith( CATEGORY_SLUG + '/' )
			)
			.map( ( ability ) => ( {
				name: ability.name,
				label: ability.label || ability.name,
				description: ability.description || '',
				input_schema: ability.input_schema || {},
				output_schema: ability.output_schema || {},
				annotations: ability.meta?.annotations || {},
			} ) );
	} catch ( _err ) {
		return [];
	}
}

/**
 * Execute a registered client-side ability by name.
 *
 * Called by the job-polling logic in jobSlice when the server returns
 * `pending_client_tool_calls`. The ability must have been registered via
 * `registerClientAbility()` in the same page lifetime.
 *
 * @param {string} name Fully-qualified ability name (sd-ai-agent-js/...).
 * @param {Object} args Ability arguments from the model's tool call.
 * @return {Promise<Object>} The ability's result object.
 * @throws {Error} When the ability is not registered in the current page.
 */
export async function executeClientAbility( name, args ) {
	const callback = clientCallbacks.get( name );
	if ( callback ) {
		return callback( args );
	}

	// Defensive fallback (sd-ai-86a): if the local callback map missed
	// (e.g. another bundle on the same page registered the ability but
	// this bundle's module scope did not), try the shared WP 7.0
	// abilities API. This is rare in practice — both bundles import
	// the same `src/abilities` tree — but keeps execution resilient
	// when only the WP store has the ability.
	if (
		typeof wp !== 'undefined' &&
		!! wp.abilities &&
		typeof wp.abilities.executeAbility === 'function'
	) {
		try {
			return await wp.abilities.executeAbility( name, args );
		} catch ( err ) {
			// Surface the WP error message rather than the generic
			// "not registered" string so the model gets actionable
			// feedback in the tool result.
			throw err instanceof Error ? err : new Error( String( err ) );
		}
	}

	throw new Error(
		`Client ability "${ name }" is not registered on this page. ` +
			'Ensure the ability was registered via registerClientAbility() before the job completed.'
	);
}
