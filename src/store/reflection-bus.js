/**
 * Frontend live-preview reflection bus.
 *
 * Tiny pub/sub channel that fires `tool-applied` events whenever an agent
 * tool call completes with a structured `affected` descriptor. This is the
 * Phase 1 spike of the frontend live-preview pipeline (todo:
 * spike/frontend-live-preview-bus): the producer side lives in
 * `src/store/slices/jobSlice.js` (pollJob inspects each new entry of
 * `tool_call_log[].response.affected`); consumers are free to wire any
 * DOM-refresh strategy without coupling to Redux.
 *
 * Event shape:
 *   {
 *     type: 'tool-applied',
 *     tool: 'sd-ai-agent/update-post',
 *     sessionId: 123,
 *     jobId: 'abc',
 *     args: { ... },        // tool-call args from the matching `call` entry
 *     result: { ... },      // full ability response
 *     affected: {           // mirror of result.affected for convenience
 *       kind: 'post',
 *       post_id: 42,
 *       post_type: 'page',
 *       url: 'https://example.com/about/',
 *       fields: ['post_title']
 *     }
 *   }
 *
 * The module also exposes the bus on `window.sdAiAgentReflection` so
 * external scripts (or browser devtools) can subscribe without importing
 * the bundle:
 *
 *   window.sdAiAgentReflection.on( ( event ) => console.log( event ) );
 *
 * Design notes:
 * - Pure-JS, no React or Redux dependency, so it stays bundle-cheap and is
 *   safe to reference from non-React reflectors in Phase 2.
 * - Errors thrown by listeners are swallowed and logged so a broken
 *   reflector cannot abort the chat loop.
 * - Singleton: the first import wins; subsequent imports see the same
 *   bus instance (the window-global is also re-used to dedupe across
 *   webpack bundles, matching the pattern in `src/abilities/index.js`).
 */

const WIN_KEY = '__sdAiAgentReflectionBus';

/**
 * @typedef {Object} ReflectionEvent
 * @property {string} type      Event type — currently always 'tool-applied'.
 * @property {string} tool      Canonical ability name.
 * @property {number} sessionId Session that owns the job.
 * @property {string} jobId     Background job ID.
 * @property {Object} [args]    Tool call arguments, if known.
 * @property {*}      result    Raw tool response.
 * @property {Object} affected  Structured descriptor (mirror of result.affected).
 */

/**
 * @typedef {(event: ReflectionEvent) => void} ReflectionListener
 */

/**
 * Build a fresh bus instance. Exported only for tests.
 *
 * @return {{
 *   on: (listener: ReflectionListener) => () => void,
 *   off: (listener: ReflectionListener) => void,
 *   emit: (event: ReflectionEvent) => void,
 *   clear: () => void,
 *   listenerCount: () => number,
 * }} New bus with its own listener set.
 */
export function createReflectionBus() {
	const listeners = new Set();

	return {
		/**
		 * Subscribe to reflection events.
		 *
		 * @param {ReflectionListener} listener Handler invoked for each event.
		 * @return {() => void} Unsubscribe function.
		 */
		on( listener ) {
			if ( typeof listener !== 'function' ) {
				return () => {};
			}
			listeners.add( listener );
			return () => listeners.delete( listener );
		},

		/**
		 * Unsubscribe a previously-registered listener.
		 *
		 * @param {ReflectionListener} listener Handler reference to remove.
		 */
		off( listener ) {
			listeners.delete( listener );
		},

		/**
		 * Broadcast an event to all subscribers.
		 *
		 * Listener errors are caught and logged so a broken reflector cannot
		 * abort the producer (the chat poll loop).
		 *
		 * @param {ReflectionEvent} event Event payload.
		 */
		emit( event ) {
			for ( const listener of listeners ) {
				try {
					listener( event );
				} catch ( err ) {
					// eslint-disable-next-line no-console
					console.error(
						'[sd-ai-agent] reflection listener threw:',
						err
					);
				}
			}
		},

		/**
		 * Remove all listeners. Used by tests and hot-reload paths.
		 */
		clear() {
			listeners.clear();
		},

		/**
		 * @return {number} Current listener count.
		 */
		listenerCount() {
			return listeners.size;
		},
	};
}

/**
 * Resolve the shared bus instance. Cross-bundle dedup mirrors the pattern in
 * `src/abilities/index.js`: the first bundle to import this module on a page
 * creates the bus and stores it on `window`; subsequent bundles reuse it.
 *
 * In non-browser contexts (Jest, SSR) the window global is unavailable, so a
 * fresh instance is returned. Tests can call `__resetReflectionBusForTests()`
 * to start from a known state.
 *
 * @return {ReturnType<typeof createReflectionBus>} The page-singleton bus.
 */
function resolveBus() {
	if ( typeof window === 'undefined' ) {
		return createReflectionBus();
	}
	if ( ! window[ WIN_KEY ] ) {
		window[ WIN_KEY ] = createReflectionBus();
	}
	if ( window.sdAiAgentReflection !== window[ WIN_KEY ] ) {
		window.sdAiAgentReflection = window[ WIN_KEY ];
	}
	return window[ WIN_KEY ];
}

const bus = resolveBus();

export default bus;

/**
 * Test-only reset hook. Clears all listeners on the shared bus instance.
 *
 * Critically, this mutates the existing instance in-place rather than
 * replacing the window global, so callers that captured the default
 * export at module-load time keep a valid reference. Not part of the
 * public API.
 *
 * @return {ReturnType<typeof createReflectionBus>} The cleared shared bus.
 */
export function __resetReflectionBusForTests() {
	bus.clear();
	return bus;
}
