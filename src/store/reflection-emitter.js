/**
 * Producer side of the frontend live-preview reflection bus.
 *
 * Walks a job's `tool_call_log` array starting from a cursor index and emits
 * one `tool-applied` event per server-side tool response that carries a
 * structured `affected` descriptor. Tool responses without `affected` are
 * ignored (no event fired), so abilities that have not yet opted in to the
 * Phase 1 protocol are invisible to subscribers.
 *
 * The function is pure-ish — it mutates nothing in `entries`, only calls
 * `bus.emit()` for each new event. It returns the new cursor so the caller
 * can persist it across poll ticks and avoid double-emitting events that
 * remain in the log on subsequent polls.
 *
 * Pairing: `response` entries match `call` entries by `id`. When a matching
 * `call` is found, its `args` are included on the event for reflectors that
 * need the original input (e.g. to know which post_id was targeted before the
 * response carries it). Responses without a matching call still fire — the
 * `affected` descriptor is the authoritative source.
 */

import bus from './reflection-bus';

/**
 * @typedef {Object} ToolCallLogEntry
 * @property {string} type       Entry kind — 'call' | 'response' | 'preamble' | 'event' | etc.
 * @property {string} [id]       Call/response correlation id assigned by the SDK.
 * @property {string} [name]     Canonical ability/tool name.
 * @property {Object} [args]     Tool-call arguments (present on 'call' entries).
 * @property {*}      [response] Tool execution result (present on 'response' entries).
 * @property {string} [source]   Origin of the entry — 'client' for browser-side abilities, otherwise server.
 */

/**
 * @typedef {Object} EmitContext
 * @property {number} sessionId Owning session.
 * @property {string} jobId     Background job ID.
 */

/**
 * Emit `tool-applied` events for every new `response` entry in `entries`
 * whose response contains an `affected` descriptor.
 *
 * Safe to call with an out-of-date cursor (e.g. after a polling reset): it
 * will simply re-walk from the cursor and emit any events not yet seen. To
 * avoid duplicates, store the returned cursor and pass it on the next call.
 *
 * @param {ToolCallLogEntry[]} entries Full tool_call_log so far.
 * @param {number}             cursor  Index of the first entry that has not
 *                                     yet been considered for emission.
 * @param {EmitContext}        context Session and job identifiers.
 * @return {number} New cursor (== entries.length on success).
 */
export function emitReflectionEvents( entries, cursor, context ) {
	if ( ! Array.isArray( entries ) ) {
		return cursor;
	}

	// Coerce the cursor to a finite, non-negative integer without bitwise
	// tricks (ESLint's no-bitwise rule).
	const safeCursor = Number.isFinite( cursor )
		? Math.max( 0, Math.floor( cursor ) )
		: 0;
	const start = Math.min( safeCursor, entries.length );

	for ( let i = start; i < entries.length; i++ ) {
		const entry = entries[ i ];
		if ( ! entry || entry.type !== 'response' ) {
			continue;
		}

		const response = entry.response;
		if ( ! response || typeof response !== 'object' ) {
			continue;
		}

		const affected = response.affected;
		if ( ! affected || typeof affected !== 'object' ) {
			continue;
		}

		// Pair with the matching call entry by id (best-effort).
		let args;
		if ( entry.id ) {
			for ( let j = i - 1; j >= 0; j-- ) {
				const candidate = entries[ j ];
				if (
					candidate &&
					candidate.type === 'call' &&
					candidate.id === entry.id
				) {
					args = candidate.args;
					break;
				}
			}
		}

		bus.emit( {
			type: 'tool-applied',
			tool: entry.name || '',
			sessionId: context.sessionId,
			jobId: context.jobId,
			args,
			result: response,
			affected,
		} );
	}

	return entries.length;
}
