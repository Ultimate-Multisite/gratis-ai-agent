/**
 * Sessions slice — session list, current session, messages, sending state,
 * and session management thunks.
 *
 * Job state (currentJobId, liveToolCalls, sessionJobs, pendingConfirmation,
 * pendingActionCard) and the pollJob thunk are owned by jobSlice (t203/t204).
 */

/**
 * @typedef {import('../../types').Session}  Session
 * @typedef {import('../../types').Message}  Message
 * @typedef {import('../../types').ToolCall} ToolCall
 * @typedef {import('../../types').TokenUsage} TokenUsage
 * @typedef {import('../../types').PendingConfirmation} PendingConfirmation
 */

import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { snapshotDescriptors } from '../../abilities/registry';
import { ensureRegistered as ensureClientAbilitiesRegistered } from '../../abilities';
import { clearNotification } from '../../utils/notification-manager';
import {
	extractMessageText,
	isVisibleTextPart,
} from '../../utils/message-parts';

/**
 * Coerce session-like objects from the REST API so that `id` is always an
 * integer.  The WP REST API serialises numeric columns as strings; normalising
 * at the store boundary eliminates the need for `parseInt(session.id, 10)` in
 * every consumer component.
 *
 * @param {Object} session - Raw session object from the API.
 * @return {Object} Session with `id` coerced to a number.
 */
function normalizeSession( session ) {
	return {
		...session,
		id:
			typeof session.id === 'string'
				? parseInt( session.id, 10 )
				: session.id,
	};
}

/**
 * Associate tool call log entries with the correct model text messages.
 *
 * The DB stores tool calls as a flat chronological array and messages separately.
 * This function walks through both, matching tool call IDs found in model message
 * function-call parts to entries in the tool_calls log, and attaches them to the
 * next model message that contains text content.
 *
 * @param {Message[]}  messages  - Messages from the session DB row.
 * @param {ToolCall[]} toolCalls - Flat tool call log from the session DB row.
 * @return {Message[]} Messages with toolCalls property attached to model text messages.
 */
function associateToolCallsWithMessages( messages, toolCalls ) {
	if ( ! toolCalls?.length || ! messages?.length ) {
		return messages;
	}

	// Build a map of callId → { call entry, response entry } from the flat log.
	const callMap = {};
	for ( const tc of toolCalls ) {
		if ( tc.type === 'call' && tc.id ) {
			callMap[ tc.id ] = { call: tc };
		} else if ( tc.type === 'response' && tc.id && callMap[ tc.id ] ) {
			callMap[ tc.id ].response = tc;
		}
	}

	// Walk through messages, collecting function call IDs from model messages
	// and attaching the matched tool call entries to visible model messages.
	const result = [];
	let pendingCallIds = [];

	for ( const msg of messages ) {
		if ( msg.role === 'model' && msg.parts?.length ) {
			// Collect function call IDs from this message's parts.
			for ( const part of msg.parts ) {
				if ( part.functionCall?.id ) {
					pendingCallIds.push( part.functionCall.id );
				}
			}

			// Check if this model message has text content (visible message).
			const hasText = msg.parts.some( isVisibleTextPart );
			if ( hasText && pendingCallIds.length > 0 ) {
				// Build the toolCalls array for this message from matched pairs.
				const msgToolCalls = [];
				for ( const id of pendingCallIds ) {
					const pair = callMap[ id ];
					if ( pair?.call ) {
						msgToolCalls.push( pair.call );
						if ( pair.response ) {
							msgToolCalls.push( pair.response );
						}
					}
				}
				if ( msgToolCalls.length > 0 ) {
					result.push( { ...msg, toolCalls: msgToolCalls } );
				} else {
					result.push( msg );
				}
				pendingCallIds = [];
				continue;
			}
		}

		result.push( msg );
	}

	// If there are unmatched tool calls (e.g. model never produced text after
	// the last round of tool calls), attach them to the last model message
	// that has text, if any.
	if ( pendingCallIds.length > 0 ) {
		const unmatched = [];
		for ( const id of pendingCallIds ) {
			const pair = callMap[ id ];
			if ( pair?.call ) {
				unmatched.push( pair.call );
				if ( pair.response ) {
					unmatched.push( pair.response );
				}
			}
		}
		if ( unmatched.length > 0 ) {
			// Find last model message (with or without text) so failed
			// trailing tool calls stay on the message that made them.
			for ( let i = result.length - 1; i >= 0; i-- ) {
				if ( result[ i ].role === 'model' ) {
					const existing = result[ i ].toolCalls || [];
					result[ i ] = {
						...result[ i ],
						toolCalls: [ ...existing, ...unmatched ],
					};
					break;
				}
			}
		}
	}

	return result;
}

export const initialState = {
	sessions: [],
	sessionsLoaded: false,
	currentSessionId: null,
	currentSessionMessages: [],
	currentSessionToolCalls: [],
	sending: false,

	// Token usage (current session)
	tokenUsage: { prompt: 0, completion: 0 },

	// Live token counter (t111) — accumulated from done events.
	sessionTokens: 0,
	sessionCost: 0,
	// Per-message token data: array of { prompt, completion, cost } indexed by
	// message position. Populated when a done event arrives.
	messageTokens: [],

	// Stream error state — true when the last send attempt failed.
	// Used to show a "Try again" button in the message list.
	streamError: false,
	streamErrorSessionId: null,

	// Last user message text — stored so retryLastMessage can resend it.
	lastUserMessage: '',

	// Shared sessions — sessions shared with all admins (t077).
	sharedSessions: [],
	sharedSessionsLoaded: false,

	// Pending optimistic titles — { [sessionId]: title } set by updateSessionTitle()
	// and merged into state.sessions by SET_SESSIONS so that a fetchSessions()
	// round-trip returning "Untitled" from the server does not overwrite a title
	// that was already delivered via the SSE done event.
	pendingTitles: {},

	// Inability-reported flag (t185) — set when the AI calls report-inability.
	// { reason: string, attempted_steps: string[] } or null.
	inabilityReported: null,

	// Feedback banner (t183) — set when the AI exits due to spin/timeout/max_iterations.
	// { exitReason: string } or null.
	feedbackBanner: null,

	// Message queue — messages typed while the agent is processing.
	// Each entry: { text: string, attachments: [], timestamp: number }
	// Drained automatically when the current job completes.
	messageQueue: [],

	// Index of the message currently in edit mode, or null when no message is
	// being edited.  Keyed by the actual message index (position in
	// currentSessionMessages) so only a single message can be editing at once.
	editingMessageIndex: null,

	// Open tabs for the chat tab bar (t207).
	// Array of integer session IDs representing sessions pinned as tabs.
	// Persisted to localStorage so tabs survive page reload.
	openTabs: ( () => {
		try {
			const saved = localStorage.getItem( 'sdAiAgent_openTabs' );
			return saved ? JSON.parse( saved ) : [];
		} catch {
			return [];
		}
	} )(),

	// Pending proposal for file-write/file-edit approval (GH#1824).
	// { proposal_id, file_path, diff_preview } or null.
	pendingProposal: null,
};

export const actions = {
	/**
	 * Replace the sessions list.
	 *
	 * @param {Session[]} sessions - Session summaries.
	 * @return {Object} Redux action.
	 */
	setSessions( sessions ) {
		return { type: 'SET_SESSIONS', sessions };
	},

	/**
	 * Set the active session and its messages/tool-calls.
	 *
	 * @param {number}     sessionId - Session identifier.
	 * @param {Message[]}  messages  - Messages for the session.
	 * @param {ToolCall[]} toolCalls - Tool calls for the session.
	 * @return {Object} Redux action.
	 */
	setCurrentSession( sessionId, messages, toolCalls ) {
		return {
			type: 'SET_CURRENT_SESSION',
			sessionId,
			messages,
			toolCalls,
		};
	},

	/**
	 * Clear the active session (start a new chat).
	 *
	 * Also cancels any in-flight request so the UI returns to idle state
	 * immediately, allowing the empty state to render without waiting for
	 * the current job to complete or error.
	 *
	 * @return {Function} Redux thunk.
	 */
	clearCurrentSession() {
		return async ( { dispatch } ) => {
			// Stop polling / sending state so the empty state renders immediately.
			dispatch.setCurrentJobId( null );
			dispatch.setSending( false );
			// Clear the session.
			dispatch( { type: 'CLEAR_CURRENT_SESSION' } );
		};
	},

	/**
	 * Set the sending/loading state.
	 *
	 * @param {boolean} sending - Whether a message is in-flight.
	 * @return {Object} Redux action.
	 */
	setSending( sending ) {
		return { type: 'SET_SENDING', sending };
	},

	/**
	 * Append a message to the current session.
	 *
	 * @param {Message} message - Message to append.
	 * @return {Object} Redux action.
	 */
	appendMessage( message ) {
		return { type: 'APPEND_MESSAGE', message };
	},

	/**
	 * Remove the last message from the current session.
	 *
	 * @return {Object} Redux action.
	 */
	removeLastMessage() {
		return { type: 'REMOVE_LAST_MESSAGE' };
	},

	/**
	 * Update cumulative token usage for the current session.
	 *
	 * @param {TokenUsage} tokenUsage - Token usage counters.
	 * @return {Object} Redux action.
	 */
	setTokenUsage( tokenUsage ) {
		return { type: 'SET_TOKEN_USAGE', tokenUsage };
	},

	// ─── Live token counter (t111) ───────────────────────────────

	/**
	 * Accumulate session-level token counts and cost from a done event.
	 *
	 * @param {number} tokens - Total tokens for this exchange (prompt + completion).
	 * @param {number} cost   - Estimated cost in USD for this exchange.
	 * @return {Object} Redux action.
	 */
	accumulateSessionTokens( tokens, cost ) {
		return { type: 'ACCUMULATE_SESSION_TOKENS', tokens, cost };
	},

	/**
	 * Record per-message token data at the given message index.
	 *
	 * @param {number} index     - Message index in currentSessionMessages.
	 * @param {Object} tokenData - { prompt, completion, cost } for this message.
	 * @return {Object} Redux action.
	 */
	setMessageTokens( index, tokenData ) {
		return { type: 'SET_MESSAGE_TOKENS', index, tokenData };
	},

	/**
	 * Reset session token counters (called when a new session starts).
	 *
	 * @return {Object} Redux action.
	 */
	resetSessionTokens() {
		return { type: 'RESET_SESSION_TOKENS' };
	},

	/**
	 * Truncate the message list to the given index (exclusive).
	 *
	 * @param {number} index - Keep messages[0..index-1]; discard the rest.
	 * @return {Object} Redux action.
	 */
	truncateMessagesTo( index ) {
		return { type: 'TRUNCATE_MESSAGES_TO', index };
	},

	/**
	 * Set the index of the message currently in edit mode, or null to clear.
	 *
	 * Only one message may be in edit mode at a time; keying on the actual
	 * message index prevents the "all user messages enter edit mode" bug where
	 * local component state coupled with non-unique visibility-position keys
	 * caused every user bubble to show the editing UI.
	 *
	 * @param {number|null} index - Message index to enter edit mode, or null.
	 * @return {Object} Redux action.
	 */
	setEditingMessageIndex( index ) {
		return { type: 'SET_EDITING_MESSAGE_INDEX', index };
	},

	/**
	 * Record the timestamp of the most recent send (for latency calculation).
	 *
	 * @param {number} ts - Timestamp in milliseconds since epoch.
	 * @return {Object} Redux action.
	 */
	setSendTimestamp( ts ) {
		return { type: 'SET_SEND_TIMESTAMP', ts };
	},

	/**
	 * Set or clear the stream error flag.
	 *
	 * @param {boolean}     error     - Whether the last stream attempt failed.
	 * @param {number|null} sessionId - Session the error belongs to.
	 * @return {Object} Redux action.
	 */
	setStreamError( error, sessionId = null ) {
		return { type: 'SET_STREAM_ERROR', error, sessionId };
	},

	/**
	 * Store the last user message text for retry purposes.
	 *
	 * @param {string} message - The user message text.
	 * @return {Object} Redux action.
	 */
	setLastUserMessage( message ) {
		return { type: 'SET_LAST_USER_MESSAGE', message };
	},

	/**
	 * Set or clear the inability-reported data (t185).
	 * Set to an object { reason, attempted_steps } when the AI calls
	 * report-inability; set to null to dismiss the banner.
	 *
	 * @param {Object|null} data - Inability data or null.
	 * @return {Object} Redux action.
	 */
	setInabilityReported( data ) {
		return { type: 'SET_INABILITY_REPORTED', data };
	},

	/**
	 * Set or clear the feedback banner (t183).
	 * Set to an object { exitReason } when the AI exits due to
	 * spin_detected, timeout, or max_iterations; set to null to dismiss.
	 *
	 * @param {Object|null} data - Banner data or null.
	 * @return {Object} Redux action.
	 */
	setFeedbackBanner( data ) {
		return { type: 'SET_FEEDBACK_BANNER', data };
	},

	// ─── Message queue (always-on input) ────────────────────────

	/**
	 * Enqueue a message to be processed after the current job finishes.
	 *
	 * @param {string} text        - Message text.
	 * @param {Array}  attachments - Optional attachment objects.
	 * @return {Object} Redux action.
	 */
	enqueueMessage( text, attachments ) {
		return {
			type: 'ENQUEUE_MESSAGE',
			text,
			attachments: attachments || [],
			timestamp: Date.now(),
		};
	},

	/**
	 * Remove the first message from the queue (after it's been sent).
	 *
	 * @return {Object} Redux action.
	 */
	dequeueMessage() {
		return { type: 'DEQUEUE_MESSAGE' };
	},

	/**
	 * Clear the entire message queue.
	 *
	 * @return {Object} Redux action.
	 */
	clearMessageQueue() {
		return { type: 'CLEAR_MESSAGE_QUEUE' };
	},

	/**
	 * Replace the shared sessions list.
	 *
	 * @param {Session[]} sessions - Shared session summaries.
	 * @return {Object} Redux action.
	 */
	setSharedSessions( sessions ) {
		return { type: 'SET_SHARED_SESSIONS', sessions };
	},

	/**
	 * Optimistically update the title of a session in the sessions list.
	 *
	 * Called immediately after the AI generates a title so the sidebar
	 * reflects the new title without waiting for a full fetchSessions round-trip.
	 *
	 * @param {number} sessionId - Session identifier.
	 * @param {string} title     - New session title.
	 * @return {Object} Redux action.
	 */
	updateSessionTitle( sessionId, title ) {
		return { type: 'UPDATE_SESSION_TITLE', sessionId, title };
	},

	// ─── Tab bar (t207) ──────────────────────────────────────────

	/**
	 * Add a session to the open tabs list.
	 * No-ops if the session is already present.
	 *
	 * @param {number} sessionId - Session identifier to add.
	 * @return {Object} Redux action.
	 */
	addOpenTab( sessionId ) {
		return { type: 'ADD_OPEN_TAB', sessionId };
	},

	/**
	 * Remove a session from the open tabs list.
	 *
	 * @param {number} sessionId - Session identifier to remove.
	 * @return {Object} Redux action.
	 */
	removeOpenTab( sessionId ) {
		return { type: 'REMOVE_OPEN_TAB', sessionId };
	},

	/**
	 * Replace the entire open tabs list.
	 *
	 * @param {number[]} tabs - Array of session IDs to set as open tabs.
	 * @return {Object} Redux action.
	 */
	setOpenTabs( tabs ) {
		return { type: 'SET_OPEN_TABS', tabs };
	},

	// ─── Thunks ──────────────────────────────────────────────────

	/**
	 * Fetch sessions from the REST API, applying the current filter/folder/search.
	 *
	 * @return {Function} Redux thunk.
	 */
	fetchSessions() {
		return async ( { dispatch, select } ) => {
			// Skip if a boot error was already raised — prevents looping
			// on persistent auth failures (e.g. expired nonce / 403).
			if ( select.getBootError() ) {
				return;
			}
			try {
				const params = new URLSearchParams();
				const filter = select.getSessionFilter();
				const folder = select.getSessionFolder();
				const search = select.getSessionSearch();

				if ( filter ) {
					params.set( 'status', filter );
				}
				if ( folder ) {
					params.set( 'folder', folder );
				}
				if ( search ) {
					params.set( 'search', search );
				}

				const qs = params.toString();
				const path =
					'/sd-ai-agent/v1/sessions' + ( qs ? '?' + qs : '' );

				const sessions = await apiFetch( { path } );
				dispatch.setSessions( sessions );
			} catch ( err ) {
				dispatch.setSessions( [] );
				const status = err?.data?.status ?? err?.code;
				if ( status === 403 || status === 401 ) {
					dispatch.setBootError( {
						message:
							err?.message ||
							__(
								'Unable to connect to the AI Agent API.',
								'sd-ai-agent'
							),
						status,
					} );
				}
			}
		};
	},

	/**
	 * Load a session by ID and make it the active session.
	 * Restores the provider/model selection if the provider is still available.
	 *
	 * @param {number} sessionId - Session identifier.
	 * @return {Function} Redux thunk.
	 */
	openSession( sessionId ) {
		return async ( { dispatch, select } ) => {
			try {
				const session = await apiFetch( {
					path: `/sd-ai-agent/v1/sessions/${ sessionId }`,
				} );
				dispatch.setCurrentSession(
					session.id,
					session.messages || [],
					session.tool_calls || []
				);
				// Only restore provider/model if the provider is still available.
				if ( session.provider_id ) {
					const providers = select.getProviders();
					const providerExists = providers.some(
						( p ) => p.id === session.provider_id
					);
					if ( providerExists ) {
						dispatch.setSelectedProvider( session.provider_id );
						if ( session.model_id ) {
							dispatch.setSelectedModel( session.model_id );
						}
					}
				}
				if ( session.token_usage ) {
					dispatch.setTokenUsage( session.token_usage );
				}
				// Reset live counter when switching sessions.
				dispatch.resetSessionTokens();
				// Auto-add to the tab bar so opened sessions appear as tabs (t207).
				// Session ID is normalized to an integer by setCurrentSession.
				const openSessionId =
					typeof session.id === 'string'
						? parseInt( session.id, 10 )
						: session.id;
				dispatch.addOpenTab( openSessionId );

				// Resume polling for any active background job on this session (t202).
				try {
					const activeJob = await apiFetch( {
						path: `/sd-ai-agent/v1/sessions/${ sessionId }/active-job`,
					} );
					if ( activeJob && activeJob.job_id ) {
						dispatch.pollJob( activeJob.job_id, sessionId );
					}
				} catch {
					// 404 means no active job — normal case, ignore.
				}
			} catch {
				// ignore
			}
		};
	},

	/**
	 * Restore active background jobs for all sessions after a page navigation.
	 *
	 * Calls GET /sessions/active-jobs to discover jobs that were in-progress
	 * when the user navigated away. Starts pollJob() for each so the UI
	 * resumes displaying live tool progress without requiring re-submission.
	 *
	 * Called from FloatingWidget and AdminPageApp on mount (t202).
	 *
	 * @return {Function} Redux thunk.
	 */
	restoreActiveJobs() {
		return async ( { dispatch } ) => {
			try {
				const activeJobs = await apiFetch( {
					path: '/sd-ai-agent/v1/sessions/active-jobs',
				} );
				if ( ! Array.isArray( activeJobs ) ) {
					return;
				}
				for ( const job of activeJobs ) {
					if ( job.job_id && job.session_id ) {
						dispatch.pollJob( job.job_id, job.session_id );
					}
				}
			} catch {
				// Non-fatal — if the endpoint fails, polling simply won't resume.
			}
		};
	},

	/**
	 * Permanently delete a session.
	 *
	 * @param {number} sessionId - Session identifier.
	 * @return {Function} Redux thunk.
	 */
	deleteSession( sessionId ) {
		return async ( { dispatch, select } ) => {
			try {
				await apiFetch( {
					path: `/sd-ai-agent/v1/sessions/${ sessionId }`,
					method: 'DELETE',
				} );
				if ( select.getCurrentSessionId() === sessionId ) {
					dispatch.clearCurrentSession();
				}
				dispatch.fetchSessions();
			} catch {
				// ignore
			}
		};
	},

	/**
	 * Pin or unpin a session.
	 *
	 * @param {number}  sessionId - Session identifier.
	 * @param {boolean} pinned    - Whether to pin (true) or unpin (false).
	 * @return {Function} Redux thunk.
	 */
	pinSession( sessionId, pinned ) {
		return async ( { dispatch } ) => {
			await apiFetch( {
				path: `/sd-ai-agent/v1/sessions/${ sessionId }`,
				method: 'PATCH',
				data: { pinned },
			} );
			dispatch.fetchSessions();
		};
	},

	/**
	 * Archive a session (move to archived status).
	 *
	 * @param {number} sessionId - Session identifier.
	 * @return {Function} Redux thunk.
	 */
	archiveSession( sessionId ) {
		return async ( { dispatch, select } ) => {
			await apiFetch( {
				path: `/sd-ai-agent/v1/sessions/${ sessionId }`,
				method: 'PATCH',
				data: { status: 'archived' },
			} );
			if ( select.getCurrentSessionId() === sessionId ) {
				dispatch.clearCurrentSession();
			}
			dispatch.fetchSessions();
		};
	},

	/**
	 * Move a session to trash.
	 *
	 * @param {number} sessionId - Session identifier.
	 * @return {Function} Redux thunk.
	 */
	trashSession( sessionId ) {
		return async ( { dispatch, select } ) => {
			await apiFetch( {
				path: `/sd-ai-agent/v1/sessions/${ sessionId }`,
				method: 'PATCH',
				data: { status: 'trash' },
			} );
			if ( select.getCurrentSessionId() === sessionId ) {
				dispatch.clearCurrentSession();
			}
			dispatch.fetchSessions();
		};
	},

	/**
	 * Restore a session from archived or trash back to active.
	 *
	 * @param {number} sessionId - Session identifier.
	 * @return {Function} Redux thunk.
	 */
	restoreSession( sessionId ) {
		return async ( { dispatch } ) => {
			await apiFetch( {
				path: `/sd-ai-agent/v1/sessions/${ sessionId }`,
				method: 'PATCH',
				data: { status: 'active' },
			} );
			dispatch.fetchSessions();
		};
	},

	/**
	 * Move a session to a folder (or remove from folder when folder is empty string).
	 *
	 * @param {number} sessionId - Session identifier.
	 * @param {string} folder    - Target folder name, or '' to remove from folder.
	 * @return {Function} Redux thunk.
	 */
	moveSessionToFolder( sessionId, folder ) {
		return async ( { dispatch } ) => {
			await apiFetch( {
				path: `/sd-ai-agent/v1/sessions/${ sessionId }`,
				method: 'PATCH',
				data: { folder },
			} );
			dispatch.fetchSessions();
			dispatch.fetchFolders();
		};
	},

	/**
	 * Rename a session.
	 *
	 * @param {number} sessionId - Session identifier.
	 * @param {string} title     - New session title.
	 * @return {Function} Redux thunk.
	 */
	renameSession( sessionId, title ) {
		return async ( { dispatch } ) => {
			await apiFetch( {
				path: `/sd-ai-agent/v1/sessions/${ sessionId }`,
				method: 'PATCH',
				data: { title },
			} );
			dispatch.fetchSessions();
		};
	},

	/**
	 * Export a session and trigger a browser download.
	 *
	 * @param {number}            sessionId       - Session identifier.
	 * @param {'json'|'markdown'} [format='json'] - Export format.
	 * @return {Function} Redux thunk.
	 */
	exportSession( sessionId, format = 'json' ) {
		return async () => {
			const result = await apiFetch( {
				path: `/sd-ai-agent/v1/sessions/${ sessionId }/export?format=${ format }`,
			} );
			const content =
				format === 'json'
					? JSON.stringify( result.content, null, 2 )
					: result.content;
			const blob = new Blob( [ content ], {
				type: format === 'json' ? 'application/json' : 'text/markdown',
			} );
			const url = URL.createObjectURL( blob );
			const a = document.createElement( 'a' );
			a.href = url;
			a.download = result.filename;
			a.click();
			URL.revokeObjectURL( url );
		};
	},

	/**
	 * Import a session from exported JSON data.
	 *
	 * @param {Object} data - Parsed export JSON (sd-ai-agent-v1 format).
	 * @return {Function} Redux thunk.
	 */
	importSession( data ) {
		return async ( { dispatch } ) => {
			const session = await apiFetch( {
				path: '/sd-ai-agent/v1/sessions/import',
				method: 'POST',
				data,
			} );
			dispatch.fetchSessions();
			dispatch.openSession( session.id );
		};
	},

	/**
	 * Regenerate the model response for the message at the given index.
	 * Finds the preceding user message, truncates to that point, and resends.
	 *
	 * @param {number} index - Index of the message to regenerate from.
	 * @return {Function} Redux thunk.
	 */
	regenerateMessage( index ) {
		return async ( { dispatch, select } ) => {
			const messages = select.getCurrentSessionMessages();
			// Find the user message at or before this index.
			let userIdx = index;
			while ( userIdx >= 0 && messages[ userIdx ]?.role !== 'user' ) {
				userIdx--;
			}
			if ( userIdx < 0 ) {
				return;
			}
			const userText = extractMessageText( messages[ userIdx ] );
			if ( ! userText ) {
				return;
			}
			// Truncate to just before this user message.
			dispatch.truncateMessagesTo( userIdx );
			dispatch.sendMessage( userText );
		};
	},

	/**
	 * Edit a user message and resend from that point.
	 *
	 * @param {number} index   - Index of the message to replace.
	 * @param {string} newText - Replacement message text.
	 * @return {Function} Redux thunk.
	 */
	editAndResend( index, newText ) {
		return async ( { dispatch } ) => {
			dispatch.setEditingMessageIndex( null );
			dispatch.truncateMessagesTo( index );
			dispatch.sendMessage( newText );
		};
	},

	/**
	 * Abort any active SSE stream or polling job and reset sending state.
	 *
	 * @return {Function} Redux thunk.
	 */
	stopGeneration() {
		return async ( { dispatch } ) => {
			dispatch.setCurrentJobId( null );
			dispatch.setSending( false );
		};
	},

	/**
	 * Retry the last failed send by rewinding to the last user message and
	 * resending it. This avoids blindly removing the last two messages, which can
	 * delete unrelated messages if the session was reloaded or the failure shape
	 * differs from the expected user+system pair.
	 *
	 * @return {Function} Redux thunk.
	 */
	retryLastMessage() {
		return async ( { dispatch, select } ) => {
			const messages = select.getCurrentSessionMessages() || [];
			let userIndex = -1;
			for ( let i = messages.length - 1; i >= 0; i-- ) {
				if ( messages[ i ]?.role === 'user' ) {
					userIndex = i;
					break;
				}
			}

			if ( userIndex < 0 ) {
				return;
			}

			const lastUserMessage = messages[ userIndex ];
			const messageText =
				extractMessageText( lastUserMessage ) ||
				select.getLastUserMessage();
			const attachments = Array.isArray( lastUserMessage.attachments )
				? lastUserMessage.attachments
				: [];

			if ( ! messageText && attachments.length === 0 ) {
				return;
			}

			// Remove the failed user message and any local error messages after it,
			// then resend through the normal stream path so provider/model selection is
			// read fresh and UI state is rebuilt consistently.
			dispatch.truncateMessagesTo( userIndex );
			dispatch.setStreamError( false );
			dispatch.streamMessage( messageText, attachments );
		};
	},

	/**
	 * Set a pending proposal for user approval (GH#1824).
	 *
	 * @param {Object} proposal - Proposal object with proposal_id, file_path, diff_preview.
	 * @return {Object} Redux action.
	 */
	setPendingProposal( proposal ) {
		return { type: 'SET_PENDING_PROPOSAL', proposal };
	},

	/**
	 * Clear the pending proposal after apply or reject.
	 *
	 * @return {Object} Redux action.
	 */
	clearPendingProposal() {
		return { type: 'CLEAR_PENDING_PROPOSAL' };
	},

	/**
	 * Handle proposal applied — feed the result back into the conversation.
	 *
	 * @return {Function} Redux thunk.
	 */
	proposalApplied() {
		return async ( { dispatch } ) => {
			// Clear the pending proposal.
			dispatch.clearPendingProposal();
			// TODO: Feed the result back into the conversation as a tool result.
		};
	},

	/**
	 * Handle proposal rejected — feed rejection back into the conversation.
	 *
	 * @return {Function} Redux thunk.
	 */
	proposalRejected() {
		return async ( { dispatch } ) => {
			// Clear the pending proposal.
			dispatch.clearPendingProposal();
			// TODO: Feed rejection back into the conversation as a tool result.
		};
	},

	/**
	 * Send a message and stream the response token-by-token via SSE.
	 *
	 * Uses the Fetch API with a ReadableStream reader to consume the
	 * text/event-stream response from POST /sd-ai-agent/v1/stream.
	 *
	 * @param {string}  message           The user message to send.
	 * @param {Array}   attachments       Optional array of attachment objects with
	 *                                    { name, type, dataUrl, isImage } shape.
	 * @param {Object}  options           Optional flags.
	 * @param {boolean} options.fromQueue When true, the user message is already
	 *                                    visible in the message list (queued earlier)
	 *                                    so we skip appending it again.
	 */
	streamMessage( message, attachments = [], options = {} ) {
		return async ( { dispatch, select } ) => {
			dispatch.setSending( true );
			dispatch.setStreamError( false );
			dispatch.setInabilityReported( null );
			dispatch.setFeedbackBanner( null );
			dispatch.setLastUserMessage( message );

			// Build message parts — text first, then image attachments.
			const parts = [];
			if ( message ) {
				parts.push( { text: message } );
			}
			const imageAttachments = attachments.filter( ( a ) => a.isImage );
			imageAttachments.forEach( ( att ) => {
				parts.push( { image_url: att.dataUrl, image_name: att.name } );
			} );

			// Append user message immediately (with attachment previews),
			// unless this message came from the queue (already visible).
			if ( ! options.fromQueue ) {
				dispatch.appendMessage( {
					role: 'user',
					parts: parts.length ? parts : [ { text: '' } ],
					attachments: imageAttachments,
					ts: Date.now(),
				} );
			}

			let sessionId = select.getCurrentSessionId();

			// Lazy-create session on first message.
			if ( ! sessionId ) {
				try {
					const sessionData = {
						provider_id: select.getSelectedProviderId(),
						model_id: select.getSelectedModelId(),
					};
					const agentIdForSession = select.getSelectedAgentId();
					if ( agentIdForSession ) {
						sessionData.agent_id = agentIdForSession;
					}
					const session = await apiFetch( {
						path: '/sd-ai-agent/v1/sessions',
						method: 'POST',
						data: sessionData,
					} );
					sessionId = session.id;
					dispatch.setCurrentSession(
						session.id,
						select.getCurrentSessionMessages(),
						[]
					);
				} catch {
					dispatch.appendMessage( {
						role: 'system',
						parts: [ { text: 'Error: Failed to create session.' } ],
					} );
					dispatch.setSending( false );
					return;
				}
			}

			const body = {
				message,
				session_id: sessionId,
				provider_id: select.getSelectedProviderId(),
				model_id: select.getSelectedModelId(),
			};

			// Include image attachments as base64 data URLs for vision models.
			if ( attachments?.length ) {
				body.attachments = attachments.map( ( att ) => ( {
					name: att.name,
					type: att.type,
					data_url: att.dataUrl,
					is_image: att.isImage,
				} ) );
			}

			const pageContext = select.getPageContext();
			if ( pageContext ) {
				// Normalise to object — setPageContext may receive a string.
				body.page_context =
					typeof pageContext === 'string'
						? { summary: pageContext }
						: pageContext;
			}

			const selectedAgentId = select.getSelectedAgentId();
			if ( selectedAgentId ) {
				body.agent_id = selectedAgentId;
			}

			// Allow callers (e.g. onboarding bootstrap) to supply a one-off
			// system instruction override that locks the prompt for this session.
			if ( options.systemInstruction ) {
				body.system_instruction = options.systemInstruction;
			}

			// Include client-side ability descriptors so the server can route
			// JS tool calls back to the browser instead of executing them
			// server-side. The WP 7.0 abilities API is fully async — both
			// registerAbilityCategory and registerAbility return Promises —
			// so we must await ensureRegistered() before snapshotting,
			// otherwise the @wordpress/data store will be empty even though
			// the registration calls have been kicked off (the t166 fix
			// for the bug t165 only partially closed). snapshotDescriptors()
			// itself is synchronous: it reads directly from the data store
			// once registration has completed.
			try {
				await ensureClientAbilitiesRegistered();
			} catch ( _err ) {
				// Registration failure must never block the user's chat
				// message — fall through with an empty descriptor list.
			}
			let clientAbilities = [];
			try {
				clientAbilities = await snapshotDescriptors();
			} catch ( _err ) {
				// snapshotDescriptors() calls wp.abilities.getAbilities()
				// which may throw or reject if the WP 7.0 abilities API is
				// not available or returns an error. Fall through with an
				// empty descriptor list so the message is still sent.
			}
			if (
				Array.isArray( clientAbilities ) &&
				clientAbilities.length > 0
			) {
				body.client_abilities = clientAbilities;
			}

			dispatch.setSendTimestamp( Date.now() );

			// POST to /run — returns a job_id immediately, processes
			// the agent loop in a background PHP worker. The browser
			// polls /job/{id} for progress and the final result.
			let runResult;
			try {
				runResult = await apiFetch( {
					path: '/sd-ai-agent/v1/run',
					method: 'POST',
					data: body,
				} );
			} catch ( err ) {
				const errorMessage =
					err.message ||
					__( 'Failed to start agent', 'superdav-ai-agent' );
				dispatch.appendMessage( {
					role: 'system',
					parts: [
						{
							text: `${ __( 'Error:', 'superdav-ai-agent' ) } ${
								errorMessage ||
								__(
									'Failed to start agent',
									'superdav-ai-agent'
								)
							}`,
						},
					],
				} );
				dispatch.setStreamError( true, sessionId );
				dispatch.setSending( false );
				return;
			}

			if ( runResult?.job_id ) {
				dispatch.setCurrentJobId( runResult.job_id );
				// Track job per-session via jobSlice. pollJob is also responsible
				// for setting up per-session tracking, but we set initial state here
				// so the UI shows "processing" immediately before the first poll.
				if ( sessionId ) {
					dispatch.setSessionJob( sessionId, {
						jobId: runResult.job_id,
						toolCalls: [],
						status: 'processing',
					} );
				}
				// Pass sessionId so jobSlice can do session-scoped polling (t204).
				dispatch.pollJob( runResult.job_id, sessionId );
			} else {
				dispatch.appendMessage( {
					role: 'system',
					parts: [
						{
							text: __(
								'Error: No job ID returned.',
								'sd-ai-agent'
							),
						},
					],
				} );
				dispatch.setStreamError( true, sessionId );
				dispatch.setSending( false );
			}
		};
	},

	/**
	 * Confirm a pending tool call and resume the job.
	 *
	 * @param {string}  jobId               - Job identifier awaiting confirmation.
	 * @param {boolean} [alwaysAllow=false] - Whether to grant permanent auto-allow.
	 * @return {Function} Redux thunk.
	 */
	confirmToolCall( jobId, alwaysAllow = false ) {
		return async ( { dispatch, select } ) => {
			dispatch.setPendingConfirmation( null );
			dispatch.setPendingActionCard( null );
			const sessionId = select.getCurrentSessionId();
			// Dismiss any browser notification that was fired for this job.
			clearNotification( jobId );
			try {
				await apiFetch( {
					path: `/sd-ai-agent/v1/job/${ jobId }/confirm`,
					method: 'POST',
					data: { always_allow: alwaysAllow },
				} );
				dispatch.pollJob( jobId, sessionId );
			} catch ( err ) {
				dispatch.appendMessage( {
					role: 'system',
					parts: [
						{
							text: `Error: ${
								err.message || 'Failed to confirm tool call'
							}`,
						},
					],
				} );
				dispatch.setSending( false );
				dispatch.setCurrentJobId( null );
			}
		};
	},

	/**
	 * Reject a pending tool call and resume the job without executing the tool.
	 *
	 * @param {string} jobId - Job identifier awaiting confirmation.
	 * @return {Function} Redux thunk.
	 */
	rejectToolCall( jobId ) {
		return async ( { dispatch, select } ) => {
			dispatch.setPendingConfirmation( null );
			dispatch.setPendingActionCard( null );
			const sessionId = select.getCurrentSessionId();
			// Dismiss any browser notification that was fired for this job.
			clearNotification( jobId );
			try {
				await apiFetch( {
					path: `/sd-ai-agent/v1/job/${ jobId }/reject`,
					method: 'POST',
				} );
				dispatch.pollJob( jobId, sessionId );
			} catch ( err ) {
				dispatch.appendMessage( {
					role: 'system',
					parts: [
						{
							text: `Error: ${
								err.message || 'Failed to reject tool call'
							}`,
						},
					],
				} );
				dispatch.setSending( false );
				dispatch.setCurrentJobId( null );
			}
		};
	},

	/**
	 * Send a message or enqueue it if the agent is currently processing.
	 *
	 * When the agent is idle, dispatches streamMessage immediately.
	 * When the agent is busy (sending === true), the message is added to
	 * the queue and will be sent automatically when the current job
	 * completes. The user message is still appended to the message list
	 * immediately with a "queued" flag so it appears in the chat.
	 *
	 * @param {string} message     - User message text.
	 * @param {Array}  attachments - Optional array of attachment objects with
	 *                             { name, type, dataUrl, isImage } shape.
	 * @param {Object} options     - Optional overrides (e.g. systemInstruction).
	 * @return {Function} Redux thunk.
	 */
	sendMessage( message, attachments = [], options = {} ) {
		return ( { dispatch, select } ) => {
			const isBusy = select.isSending();

			if ( ! isBusy ) {
				dispatch.streamMessage( message, attachments, options );
			} else {
				// Enqueue the message for later processing.
				dispatch.enqueueMessage( message, attachments );

				// Show the user message in the chat immediately with a
				// "queued" marker so the user sees their message was accepted.
				const parts = [];
				if ( message ) {
					parts.push( { text: message } );
				}
				const imageAttachments = ( attachments || [] ).filter(
					( a ) => a.isImage
				);
				imageAttachments.forEach( ( att ) => {
					parts.push( {
						image_url: att.dataUrl,
						image_name: att.name,
					} );
				} );
				dispatch.appendMessage( {
					role: 'user',
					parts: parts.length ? parts : [ { text: '' } ],
					attachments: imageAttachments,
					queued: true,
				} );
			}
		};
	},

	/**
	 * Process the next message in the queue.
	 *
	 * Called automatically when a job completes and the queue is non-empty.
	 * Dequeues the first message and sends it via streamMessage.
	 *
	 * @return {Function} Redux thunk.
	 */
	drainMessageQueue() {
		return ( { dispatch, select } ) => {
			const queue = select.getMessageQueue();
			if ( ! queue.length ) {
				return;
			}

			const next = queue[ 0 ];
			dispatch.dequeueMessage();
			// Pass fromQueue: true so streamMessage doesn't re-append
			// the user message that was already shown when enqueued.
			dispatch.streamMessage( next.text, next.attachments, {
				fromQueue: true,
			} );
		};
	},

	/**
	 * Send an interrupt message to the currently running agent job.
	 *
	 * The message is injected into the running agent loop's context
	 * so the AI becomes aware of the new information immediately.
	 * The user message is also shown in the chat.
	 *
	 * @param {string} message - The interrupt message text.
	 * @return {Function} Redux thunk.
	 */
	interruptAgent( message ) {
		return async ( { dispatch, select } ) => {
			const jobId = select.getCurrentJobId();
			if ( ! jobId ) {
				return;
			}

			// Show the interrupt message in the chat.
			dispatch.appendMessage( {
				role: 'user',
				parts: [ { text: message } ],
				interrupt: true,
			} );

			try {
				await apiFetch( {
					path: `/sd-ai-agent/v1/job/${ jobId }/interrupt`,
					method: 'POST',
					data: { message },
				} );
			} catch {
				// Best-effort — the message is already visible in the chat.
			}
		};
	},

	/**
	 * Compact the current conversation into a new session with a summary.
	 * Builds a text summary of all messages, creates a new session, and
	 * sends the summary as the first message to preserve context.
	 *
	 * @return {Function} Redux thunk.
	 */
	compactConversation() {
		return async ( { dispatch, select } ) => {
			const messages = select.getCurrentSessionMessages();
			if ( ! messages.length ) {
				return;
			}

			// Build a summary request.
			const summaryText = messages
				.map( ( m ) => {
					const role = m.role === 'model' ? 'Assistant' : 'User';
					const text = extractMessageText( m );
					return text ? `${ role }: ${ text }` : null;
				} )
				.filter( Boolean )
				.join( '\n' );

			// Create a new session.
			try {
				const session = await apiFetch( {
					path: '/sd-ai-agent/v1/sessions',
					method: 'POST',
					data: {
						title: 'Compacted conversation',
						provider_id: select.getSelectedProviderId(),
						model_id: select.getSelectedModelId(),
					},
				} );

				// Send the summary as the first message in the new session.
				dispatch.setCurrentSession( session.id, [], [] );
				dispatch.setTokenUsage( { prompt: 0, completion: 0 } );
				dispatch.resetSessionTokens();
				dispatch.sendMessage(
					'Please provide a concise summary of this conversation so we can continue in a fresh context:\n\n' +
						summaryText
				);
				dispatch.fetchSessions();
			} catch {
				// ignore
			}
		};
	},

	/**
	 * Fetch all sessions shared with admins.
	 *
	 * @return {Function} Redux thunk.
	 */
	fetchSharedSessions() {
		return async ( { dispatch } ) => {
			try {
				const sessions = await apiFetch( {
					path: '/sd-ai-agent/v1/sessions/shared',
				} );
				dispatch.setSharedSessions( sessions );
			} catch {
				dispatch.setSharedSessions( [] );
			}
		};
	},

	/**
	 * Share a session with all admins.
	 *
	 * @param {number} sessionId - Session identifier.
	 * @return {Function} Redux thunk.
	 */
	shareSession( sessionId ) {
		return async ( { dispatch } ) => {
			await apiFetch( {
				path: `/sd-ai-agent/v1/sessions/${ sessionId }/share`,
				method: 'POST',
			} );
			dispatch.fetchSessions();
			dispatch.fetchSharedSessions();
		};
	},

	/**
	 * Unshare a session (remove from shared sessions).
	 *
	 * @param {number} sessionId - Session identifier.
	 * @return {Function} Redux thunk.
	 */
	unshareSession( sessionId ) {
		return async ( { dispatch } ) => {
			await apiFetch( {
				path: `/sd-ai-agent/v1/sessions/${ sessionId }/share`,
				method: 'DELETE',
			} );
			dispatch.fetchSessions();
			dispatch.fetchSharedSessions();
		};
	},
};

export const selectors = {
	/**
	 * @param {import('../../types').StoreState} state
	 * @return {Session[]} Session list.
	 */
	getSessions( state ) {
		return state.sessions;
	},

	/**
	 * @param {import('../../types').StoreState} state
	 * @return {boolean} Whether sessions have been fetched.
	 */
	getSessionsLoaded( state ) {
		return state.sessionsLoaded;
	},

	/**
	 * @param {import('../../types').StoreState} state
	 * @return {number|null} Active session ID, or null.
	 */
	getCurrentSessionId( state ) {
		return state.currentSessionId;
	},

	/**
	 * @param {import('../../types').StoreState} state
	 * @return {Message[]} Messages in the active session.
	 */
	getCurrentSessionMessages( state ) {
		return state.currentSessionMessages;
	},

	/**
	 * @param {import('../../types').StoreState} state
	 * @return {ToolCall[]} Tool calls in the active session.
	 */
	getCurrentSessionToolCalls( state ) {
		return state.currentSessionToolCalls;
	},

	/**
	 * @param {import('../../types').StoreState} state
	 * @return {boolean} Whether a message is in-flight.
	 */
	isSending( state ) {
		return state.sending;
	},

	/**
	 * @param {import('../../types').StoreState} state
	 * @return {import('../../types').TokenUsage} Cumulative token usage for the current session.
	 */
	getTokenUsage( state ) {
		return state.tokenUsage;
	},

	/**
	 * @param {import('../../types').StoreState} state
	 * @return {number} Accumulated session token count (prompt + completion).
	 */
	getSessionTokens( state ) {
		return state.sessionTokens;
	},

	/**
	 * @param {import('../../types').StoreState} state
	 * @return {number} Accumulated session cost estimate in USD.
	 */
	getSessionCost( state ) {
		return state.sessionCost;
	},

	/**
	 * @param {import('../../types').StoreState} state
	 * @return {Array} Per-message token data array.
	 */
	getMessageTokens( state ) {
		return state.messageTokens;
	},

	/**
	 * @param {import('../../types').StoreState} state
	 * @return {boolean} Whether the last stream attempt failed with an error.
	 */
	hasStreamError( state ) {
		return Boolean(
			state.streamError &&
				state.streamErrorSessionId === state.currentSessionId
		);
	},

	/**
	 * @param {import('../../types').StoreState} state
	 * @return {string} The last user message text (for retry).
	 */
	getLastUserMessage( state ) {
		return state.lastUserMessage;
	},

	/**
	 * Get the index of the message currently in edit mode, or null.
	 *
	 * @param {import('../../types').StoreState} state
	 * @return {number|null} Message index currently being edited, or null.
	 */
	getEditingMessageIndex( state ) {
		return state.editingMessageIndex ?? null;
	},

	/**
	 * Get inability-reported data (t185).
	 * Returns the data object { reason, attempted_steps } or null.
	 *
	 * @param {import('../../types').StoreState} state
	 * @return {Object|null} Inability data or null.
	 */
	getInabilityReported( state ) {
		return state.inabilityReported || null;
	},

	/**
	 * Get feedback banner data (t183).
	 * Returns { exitReason } when the AI exited due to spin/timeout/max_iterations, or null.
	 *
	 * @param {import('../../types').StoreState} state
	 * @return {Object|null} Feedback banner data or null.
	 */
	getFeedbackBanner( state ) {
		return state.feedbackBanner || null;
	},

	/**
	 * @param {import('../../types').StoreState} state
	 * @return {Session[]} Sessions shared with all admins.
	 */
	getSharedSessions( state ) {
		return state.sharedSessions;
	},

	/**
	 * @param {import('../../types').StoreState} state
	 * @return {boolean} Whether shared sessions have been fetched.
	 */
	getSharedSessionsLoaded( state ) {
		return state.sharedSessionsLoaded;
	},

	// ─── Message queue ──────────────────────────────────────────

	/**
	 * Get the current message queue.
	 *
	 * @param {import('../../types').StoreState} state
	 * @return {Array} Queued messages: { text, attachments, timestamp }.
	 */
	getMessageQueue( state ) {
		return state.messageQueue;
	},

	/**
	 * Whether there are messages waiting in the queue.
	 *
	 * @param {import('../../types').StoreState} state
	 * @return {boolean} True when the queue is non-empty.
	 */
	hasQueuedMessages( state ) {
		return state.messageQueue.length > 0;
	},

	// ─── Tab bar (t207) ──────────────────────────────────────────

	/**
	 * Get the list of session IDs currently open as tabs.
	 *
	 * @param {import('../../types').StoreState} state
	 * @return {number[]} Array of open tab session IDs.
	 */
	getOpenTabs( state ) {
		return state.openTabs || [];
	},

	// ─── Proposals (GH#1824) ──────────────────────────────────────

	/**
	 * Get the pending proposal for user approval, or null.
	 *
	 * @param {import('../../types').StoreState} state
	 * @return {Object|null} Proposal object or null.
	 */
	getPendingProposal( state ) {
		return state.pendingProposal;
	},
};

/**
 * @param {import('../../types').StoreState} state  - Current state.
 * @param {Object}                           action - Dispatched action.
 * @return {import('../../types').StoreState} Next state.
 */
export function reducer( state, action ) {
	switch ( action.type ) {
		case 'SET_SESSIONS': {
			// Normalize IDs to integers and merge any pending optimistic titles.
			const pending = state.pendingTitles || {};
			const sessions = action.sessions.map( ( s ) => {
				const normalized = normalizeSession( s );
				const optimistic = pending[ normalized.id ];
				return optimistic
					? { ...normalized, title: optimistic }
					: normalized;
			} );
			return {
				...state,
				sessions,
				sessionsLoaded: true,
				pendingTitles: {},
			};
		}
		case 'SET_CURRENT_SESSION':
			return {
				...state,
				currentSessionId:
					typeof action.sessionId === 'string'
						? parseInt( action.sessionId, 10 )
						: action.sessionId,
				currentSessionMessages: associateToolCallsWithMessages(
					action.messages,
					action.toolCalls
				),
				currentSessionToolCalls: action.toolCalls,
			};
		case 'CLEAR_CURRENT_SESSION':
			return {
				...state,
				currentSessionId: null,
				currentSessionMessages: [],
				currentSessionToolCalls: [],
				tokenUsage: { prompt: 0, completion: 0 },
				sessionTokens: 0,
				sessionCost: 0,
				messageTokens: [],
				streamError: false,
				streamErrorSessionId: null,
				lastUserMessage: '',
				feedbackBanner: null,
				messageQueue: [],
			};
		case 'SET_SENDING':
			return { ...state, sending: action.sending };
		case 'APPEND_MESSAGE':
			return {
				...state,
				currentSessionMessages: [
					...state.currentSessionMessages,
					action.message,
				],
			};
		case 'REMOVE_LAST_MESSAGE':
			return {
				...state,
				currentSessionMessages: state.currentSessionMessages.slice(
					0,
					-1
				),
			};
		case 'SET_TOKEN_USAGE':
			return { ...state, tokenUsage: action.tokenUsage };
		case 'ACCUMULATE_SESSION_TOKENS':
			return {
				...state,
				sessionTokens: state.sessionTokens + action.tokens,
				sessionCost: state.sessionCost + action.cost,
			};
		case 'SET_MESSAGE_TOKENS': {
			const newMessageTokens = [ ...state.messageTokens ];
			newMessageTokens[ action.index ] = action.tokenData;
			return { ...state, messageTokens: newMessageTokens };
		}
		case 'RESET_SESSION_TOKENS':
			return {
				...state,
				sessionTokens: 0,
				sessionCost: 0,
				messageTokens: [],
			};
		case 'TRUNCATE_MESSAGES_TO':
			return {
				...state,
				currentSessionMessages: state.currentSessionMessages.slice(
					0,
					action.index
				),
				editingMessageIndex: null,
			};
		case 'SET_SEND_TIMESTAMP':
			return { ...state, sendTimestamp: action.ts };
		case 'SET_STREAM_ERROR': {
			let errorSessionId = action.sessionId ?? state.currentSessionId;
			if ( typeof errorSessionId === 'string' ) {
				errorSessionId = parseInt( errorSessionId, 10 );
			}
			return {
				...state,
				streamError: Boolean( action.error ),
				streamErrorSessionId: action.error ? errorSessionId : null,
			};
		}
		case 'SET_LAST_USER_MESSAGE':
			return { ...state, lastUserMessage: action.message };
		case 'SET_EDITING_MESSAGE_INDEX':
			return { ...state, editingMessageIndex: action.index ?? null };
		case 'SET_INABILITY_REPORTED':
			return { ...state, inabilityReported: action.data };
		case 'SET_FEEDBACK_BANNER':
			return { ...state, feedbackBanner: action.data };
		case 'SET_SHARED_SESSIONS':
			return {
				...state,
				sharedSessions: action.sessions.map( normalizeSession ),
				sharedSessionsLoaded: true,
			};
		case 'UPDATE_SESSION_TITLE': {
			const titleSessionId =
				typeof action.sessionId === 'string'
					? parseInt( action.sessionId, 10 )
					: action.sessionId;
			const exists = state.sessions.some(
				( s ) => s.id === titleSessionId
			);
			// If the session is already in the list, update its title in place.
			// If it is not yet in the list (e.g. a brand-new session whose
			// setCurrentSession ran before fetchSessions populated state.sessions),
			// prepend a minimal stub so the sidebar shows the generated title
			// immediately without waiting for the fetchSessions round-trip.
			const updatedSessions = exists
				? state.sessions.map( ( s ) =>
						s.id === titleSessionId
							? { ...s, title: action.title }
							: s
				  )
				: [
						{
							id: titleSessionId,
							title: action.title,
							created_at: new Date().toISOString(),
							updated_at: new Date().toISOString(),
							status: 'active',
							message_count: 0,
						},
						...state.sessions,
				  ];
			// Record the title in pendingTitles so SET_SESSIONS can preserve it
			// when the subsequent fetchSessions() round-trip returns "Untitled"
			// from the server (the server never writes the AI-generated title back
			// to the DB in the same request cycle).
			return {
				...state,
				sessions: updatedSessions,
				pendingTitles: {
					...( state.pendingTitles || {} ),
					[ titleSessionId ]: action.title,
				},
			};
		}
		// ─── Message queue ──────────────────────────────────────
		case 'ENQUEUE_MESSAGE':
			return {
				...state,
				messageQueue: [
					...state.messageQueue,
					{
						text: action.text,
						attachments: action.attachments,
						timestamp: action.timestamp,
					},
				],
			};
		case 'DEQUEUE_MESSAGE':
			return {
				...state,
				messageQueue: state.messageQueue.slice( 1 ),
			};
		case 'CLEAR_MESSAGE_QUEUE':
			return {
				...state,
				messageQueue: [],
			};
		// ─── Tab bar (t207) ─────────────────────────────────────
		case 'ADD_OPEN_TAB': {
			const tabId = parseInt( action.sessionId, 10 );
			if ( ( state.openTabs || [] ).includes( tabId ) ) {
				return state;
			}
			const nextTabs = [ ...( state.openTabs || [] ), tabId ];
			try {
				localStorage.setItem(
					'sdAiAgent_openTabs',
					JSON.stringify( nextTabs )
				);
			} catch {
				// ignore storage errors
			}
			return { ...state, openTabs: nextTabs };
		}
		case 'REMOVE_OPEN_TAB': {
			const removeId = parseInt( action.sessionId, 10 );
			const filteredTabs = ( state.openTabs || [] ).filter(
				( t ) => t !== removeId
			);
			try {
				localStorage.setItem(
					'sdAiAgent_openTabs',
					JSON.stringify( filteredTabs )
				);
			} catch {
				// ignore storage errors
			}
			return { ...state, openTabs: filteredTabs };
		}
		case 'SET_OPEN_TABS': {
			try {
				localStorage.setItem(
					'sdAiAgent_openTabs',
					JSON.stringify( action.tabs )
				);
			} catch {
				// ignore storage errors
			}
			return { ...state, openTabs: action.tabs };
		}
		case 'SET_PENDING_PROPOSAL':
			return { ...state, pendingProposal: action.proposal };
		case 'CLEAR_PENDING_PROPOSAL':
			return { ...state, pendingProposal: null };
		default:
			return state;
	}
}
