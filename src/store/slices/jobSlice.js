/**
 * Job slice — background job polling, live tool calls, and pending confirmations.
 *
 * Extracted from sessionsSlice (t203) and extended with session-scoped polling,
 * exponential backoff, and visibility throttling (t204).
 */

import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { onVisibilityChange } from '../../utils/visibility-manager';
import { setActiveJob, clearActiveJob } from '../../utils/active-jobs-storage';
import { notifyConfirmationNeeded } from '../../utils/notification-manager';
import { playDing, playDong, playThinking } from '../../utils/sound-manager';
import { executeClientAbility } from '../../abilities/registry';

export const initialState = {
	// Active polling job ID (most-recently-started job for the current session).
	currentJobId: null,

	// Live tool call progress from the background job (shown while processing).
	liveToolCalls: [],

	// Per-session background job tracking.
	// Map of sessionId → { jobId, toolCalls, status }
	// Allows multiple sessions to have active jobs simultaneously.
	sessionJobs: {},

	// Pending confirmation (Batch 8)
	pendingConfirmation: null,

	// Action card — inline confirmation rendered in the message list (t074).
	pendingActionCard: null,

	// Retry state for failed client tool result submissions.
	// null | { sessionId: number, jobId: string, toolResults: Array, toolNames: string[] }
	// Preserved so the retry action card can resubmit without re-executing the tools.
	pendingToolResultRetry: null,
};

export const actions = {
	/**
	 * Set the active polling job ID.
	 *
	 * @param {string|null} jobId - Job identifier, or null to clear.
	 * @return {Object} Redux action.
	 */
	setCurrentJobId( jobId ) {
		return { type: 'SET_CURRENT_JOB_ID', jobId };
	},

	/**
	 * Set live tool call progress from the background job.
	 * Shown in the UI while the job is still processing.
	 *
	 * @param {Array} toolCalls - Tool call log entries from the job.
	 * @return {Object} Redux action.
	 */
	setLiveToolCalls( toolCalls ) {
		return { type: 'SET_LIVE_TOOL_CALLS', toolCalls };
	},

	/**
	 * Set a background job for a specific session.
	 *
	 * @param {number}      sessionId - Session identifier.
	 * @param {Object|null} job       - Job data { jobId, toolCalls, status } or null to clear.
	 * @return {Object} Redux action.
	 */
	setSessionJob( sessionId, job ) {
		return { type: 'SET_SESSION_JOB', sessionId, job };
	},

	/**
	 * Set or clear the pending tool confirmation.
	 *
	 * @param {Object|null} confirmation - Confirmation payload, or null to clear.
	 * @return {Object} Redux action.
	 */
	setPendingConfirmation( confirmation ) {
		return { type: 'SET_PENDING_CONFIRMATION', confirmation };
	},

	/**
	 * Set or clear the pending action card (inline confirmation in message list).
	 *
	 * @param {Object|null} card - Action card data or null.
	 * @return {Object} Redux action.
	 */
	setPendingActionCard( card ) {
		return { type: 'SET_PENDING_ACTION_CARD', card };
	},

	/**
	 * Store or clear the pending client tool result retry payload.
	 *
	 * Set when all POST retries to /chat/tool-result have been exhausted so the
	 * user can trigger a manual retry via the action card without re-running the
	 * browser-side tools.
	 *
	 * @param {Object|null} data - { sessionId, jobId, toolResults, toolNames } or null.
	 * @return {Object} Redux action.
	 */
	setPendingToolResultRetry( data ) {
		return { type: 'SET_PENDING_TOOL_RESULT_RETRY', data };
	},

	/**
	 * Re-submit previously computed client tool results to the server.
	 *
	 * Called when the user clicks Retry on the retry action card.  Clears the
	 * pending retry state, re-POSTs the stored tool results (up to 3 attempts),
	 * and — on success — resumes polling the same job.  A 409 response means
	 * the server already processed the results (the POST succeeded but the
	 * response was lost), so we treat it as success and resume polling.
	 *
	 * @return {Function} Redux thunk.
	 */
	retryClientToolSubmission() {
		return async ( { dispatch, select } ) => {
			const retry = select.getPendingToolResultRetry();
			if ( ! retry ) {
				return;
			}
			const { sessionId, jobId, toolResults } = retry;

			dispatch.setPendingToolResultRetry( null );
			dispatch.setPendingActionCard( null );
			dispatch.setSending( true );

			let postSucceeded = false;
			let lastErr = null;
			for ( let attempt = 0; attempt < 3; attempt++ ) {
				try {
					await apiFetch( {
						path: '/sd-ai-agent/v1/chat/tool-result',
						method: 'POST',
						data: {
							session_id: sessionId,
							job_id: jobId,
							tool_results: toolResults,
						},
					} );
					postSucceeded = true;
					break;
				} catch ( err ) {
					// 409: server already processed results (POST got through
					// on a prior attempt but the response was lost) — resume.
					if (
						err?.data?.status === 409 ||
						err?.code === 'rest_sd_ai_agent_no_paused_state'
					) {
						postSucceeded = true;
						break;
					}
					lastErr = err;
					if ( attempt < 2 ) {
						await new Promise( ( r ) =>
							setTimeout( r, 1000 * Math.pow( 2, attempt ) )
						);
					}
				}
			}

			if ( postSucceeded ) {
				// Re-register and resume polling the existing job.
				setActiveJob( sessionId, jobId );
				dispatch.setCurrentJobId( jobId );
				dispatch.setSessionJob( sessionId, {
					jobId,
					toolCalls: [],
					status: 'processing',
				} );
				dispatch.pollJob( jobId, sessionId );
			} else {
				// Still failing — restore retry state and surface the error.
				dispatch.setPendingToolResultRetry( retry );
				dispatch.setPendingActionCard( {
					type: 'retry_client_tools',
					toolNames: retry.toolNames,
				} );
				dispatch.appendMessage( {
					role: 'system',
					parts: [
						{
							text: `${ __( 'Error:', 'superdav-ai-agent' ) } ${
								lastErr instanceof Error
									? lastErr.message
									: __(
											'Failed to submit client tool results.',
											'superdav-ai-agent'
									  )
							}`,
						},
					],
				} );
				dispatch.setSending( false );
			}
		};
	},

	/**
	 * Poll a background job with session-scoped tracking and exponential backoff.
	 *
	 * Session-scoped: multiple sessions can poll independently — no check against
	 * currentJobId. Backoff schedule: 1s start → 5s after 10 polls → 10s after 30
	 * polls (hard cap). Resets to 1s on progress (tool_calls length change).
	 * When document is hidden, slows to 15s. On visibility restore, immediately
	 * polls once per active session then resumes normal intervals.
	 *
	 * @param {string} jobId     - Job identifier to poll.
	 * @param {number} sessionId - Session the job belongs to.
	 * @return {Function} Redux thunk.
	 */
	pollJob( jobId, sessionId ) {
		return async ( { dispatch, select } ) => {
			let attempts = 0;
			const maxAttempts = 200;
			let lastToolCallsLength = 0;
			let visibilityPaused = false;
			let resumeCallback = null;

			// Register for visibility-resume notification.
			// When the page becomes visible again, we want to poll immediately.
			const unsubscribeVisibility = onVisibilityChange( ( hidden ) => {
				if ( ! hidden && visibilityPaused ) {
					// Page just became visible — trigger an immediate poll.
					visibilityPaused = false;
					if ( resumeCallback ) {
						resumeCallback();
					}
				}
			} );

			/**
			 * Calculate the poll interval based on attempt count and visibility.
			 *
			 * @param {number} attemptCount - Number of polls completed so far.
			 * @return {number} Interval in milliseconds.
			 */
			const getInterval = ( attemptCount ) => {
				if ( typeof document !== 'undefined' && document.hidden ) {
					return 15000; // 15s when tab is hidden.
				}
				if ( attemptCount >= 30 ) {
					return 10000; // 10s cap after 30 polls.
				}
				if ( attemptCount >= 10 ) {
					return 5000; // 5s after 10 polls.
				}
				return 1000; // 1s initially.
			};

			// Tracks whether the last poll returned status 'complete'.
			// Used outside the try block to decide whether to play the ding.
			let lastStatusComplete = false;

			const poll = async () => {
				lastStatusComplete = false;
				attempts++;
				if ( attempts > maxAttempts ) {
					unsubscribeVisibility();
					clearActiveJob( sessionId );
					// Only append error and update UI for the current session.
					if ( select.getCurrentSessionId() === sessionId ) {
						dispatch.appendMessage( {
							role: 'system',
							parts: [ { text: 'Error: Request timed out.' } ],
						} );
						dispatch.setSending( false );
					}
					dispatch.setCurrentJobId( null );
					dispatch.setSessionJob( sessionId, null );
					return;
				}

				try {
					const result = await apiFetch( {
						path: `/sd-ai-agent/v1/job/${ jobId }`,
					} );

					if ( result.status === 'processing' ) {
						// Update per-session job state for ALL sessions.
						if ( result.tool_calls?.length ) {
							dispatch.setSessionJob( sessionId, {
								jobId,
								toolCalls: result.tool_calls,
								status: 'processing',
							} );
						}

						// Only update live tool calls when this is the active session.
						if (
							result.tool_calls?.length &&
							select.getCurrentSessionId() === sessionId
						) {
							dispatch.setLiveToolCalls( result.tool_calls );
						}

						// Detect progress: reset backoff when tool_calls length increases.
						const newLen = result.tool_calls?.length || 0;
						if ( newLen > lastToolCallsLength ) {
							lastToolCallsLength = newLen;
							attempts = 0; // Reset backoff on progress.
							// Play thinking tick for each new tool action.
							if ( select.getCurrentSessionId() === sessionId ) {
								playThinking();
							}
						}

						// Slow poll if tab is hidden.
						if (
							typeof document !== 'undefined' &&
							document.hidden
						) {
							visibilityPaused = true;
							// Set up a promise that resolves on visibility restore
							// OR after the slow interval, whichever comes first.
							await new Promise( ( resolve ) => {
								resumeCallback = resolve;
								setTimeout( () => {
									visibilityPaused = false;
									resolve();
								}, getInterval( attempts ) );
							} );
						} else {
							await new Promise( ( resolve ) =>
								setTimeout( resolve, getInterval( attempts ) )
							);
						}

						// Re-check job is still active before continuing.
						const currentJobId = select.getCurrentJobId();
						if ( currentJobId !== jobId && currentJobId !== null ) {
							// Different job is now active; stop this poller.
							unsubscribeVisibility();
							clearActiveJob( sessionId );
							return;
						}

						poll();
						return;
					}

					if ( result.status === 'awaiting_confirmation' ) {
						dispatch.setSessionJob( sessionId, {
							jobId,
							toolCalls: result.tool_calls || [],
							status: 'awaiting_confirmation',
						} );

						// Only show confirmation UI for the active session.
						if ( select.getCurrentSessionId() === sessionId ) {
							const cardData = {
								jobId,
								tools: result.pending_tools || [],
							};
							dispatch.setPendingConfirmation( cardData );
							dispatch.setPendingActionCard( cardData );
						}

						// Fire a browser notification when the user is not
						// looking at the page so they know approval is needed.
						if (
							typeof document !== 'undefined' &&
							document.hidden
						) {
							const firstTool = result.pending_tools?.[ 0 ];
							const toolName =
								firstTool?.function?.name ||
								firstTool?.name ||
								'';
							notifyConfirmationNeeded( jobId, toolName );
						}

						// Don't clear sending — still waiting.
						unsubscribeVisibility();
						clearActiveJob( sessionId );
						return;
					}

					if ( result.status === 'awaiting_client_tools' ) {
						// The agent loop has paused and handed a set of JS
						// abilities to the browser for execution.  Each call
						// carries an `annotations` object; abilities with
						// `readonly: true` execute immediately without a
						// confirmation dialog (screenshots, DOM reads, etc.).
						// Non-readonly abilities are not yet supported without
						// an explicit confirmation flow — they will be handled
						// in a future iteration.
						const pendingCalls =
							result.pending_client_tool_calls || [];

						const toolResults = await Promise.all(
							pendingCalls.map( async ( call ) => {
								const isReadonly =
									call.annotations?.readonly === true;

								if ( ! isReadonly ) {
									// Non-readonly client abilities are not yet
									// auto-executed — return an error so the
									// model gets feedback instead of silently
									// hanging.
									return {
										id: call.id,
										name: call.name,
										error: __(
											'Client-side ability requires user confirmation (not yet supported for non-readonly abilities).',
											'superdav-ai-agent'
										),
									};
								}

								try {
									const abilityResult =
										await executeClientAbility(
											call.name,
											call.args || {}
										);
									return {
										id: call.id,
										name: call.name,
										result: abilityResult,
									};
								} catch ( execErr ) {
									return {
										id: call.id,
										name: call.name,
										error:
											execErr instanceof Error
												? execErr.message
												: String( execErr ),
									};
								}
							} )
						);

						// POST results back to the server so the agent loop
						// can continue with the screenshot/DOM data.
						// Retry up to 3 times (1 s → 2 s backoff) for transient
						// network failures before surfacing an error to the user.
						// A 409 response means the server already processed the
						// results (POST succeeded but response was lost) — treat
						// as success and resume polling.
						// job_id is passed so the server can update the job
						// transient from 'awaiting_client_tools' to the correct
						// post-resume state, preventing an infinite 409 loop.
						const currentSessionId = select.getCurrentSessionId();
						let postSucceeded = false;
						let postErr = null;
						for ( let attempt = 0; attempt < 3; attempt++ ) {
							try {
								await apiFetch( {
									path: '/sd-ai-agent/v1/chat/tool-result',
									method: 'POST',
									data: {
										session_id: currentSessionId,
										job_id: jobId,
										tool_results: toolResults,
									},
								} );
								postSucceeded = true;
								break;
							} catch ( err ) {
								if (
									err?.data?.status === 409 ||
									err?.code ===
										'rest_sd_ai_agent_no_paused_state'
								) {
									// Already processed on a prior attempt.
									postSucceeded = true;
									break;
								}
								postErr = err;
								if ( attempt < 2 ) {
									await new Promise( ( r ) =>
										setTimeout(
											r,
											1000 * Math.pow( 2, attempt )
										)
									);
								}
							}
						}

						if ( ! postSucceeded ) {
							// All retries exhausted — preserve the tool results so
							// the user can retry via the action card without
							// re-running the browser-side tools.
							if ( currentSessionId === sessionId ) {
								const toolNames = toolResults.map(
									( r ) => r.name
								);
								dispatch.setPendingToolResultRetry( {
									sessionId: currentSessionId,
									jobId,
									toolResults,
									toolNames,
								} );
								dispatch.setPendingActionCard( {
									type: 'retry_client_tools',
									toolNames,
								} );
								dispatch.appendMessage( {
									role: 'system',
									parts: [
										{
											text: `${ __(
												'Error:',
												'superdav-ai-agent'
											) } ${
												postErr instanceof Error
													? postErr.message
													: __(
															'Failed to submit client tool results.',
															'superdav-ai-agent'
													  )
											} ${ __(
												'Use the Retry button to resubmit without re-running the tools.',
												'superdav-ai-agent'
											) }`,
										},
									],
								} );
								dispatch.setSending( false );
							}
							unsubscribeVisibility();
							clearActiveJob( sessionId );
							dispatch.setCurrentJobId( null );
							dispatch.setSessionJob( sessionId, null );
							return;
						}

						// If a client ability deferred navigation (e.g.
						// navigate-to), trigger it now that the tool result
						// has been successfully posted. Clear sessionStorage
						// first so the job is not replayed on the next page —
						// this is the primary fix for the infinite-reload loop
						// caused by navigate-to calling window.location.assign()
						// before the POST could complete.
						if ( window._sdAiAgentPendingNavigation ) {
							const target = window._sdAiAgentPendingNavigation;
							delete window._sdAiAgentPendingNavigation;
							clearActiveJob( sessionId );
							unsubscribeVisibility();
							window.location.assign( target );
							return;
						}

						// Resume polling — the server has resumed the agent
						// loop; we continue polling the same job for the
						// model's next response or another pause.
						await new Promise( ( resolve ) =>
							setTimeout( resolve, getInterval( attempts ) )
						);
						poll();
						return;
					}

					if ( result.status === 'error' ) {
						let errorText = `${ __(
							'Error:',
							'superdav-ai-agent'
						) } ${
							result.message ||
							__( 'Unknown error', 'superdav-ai-agent' )
						}`;
						if ( result.error_context ) {
							const ctx = result.error_context;
							errorText += `\n\n**${ __(
								'Location:',
								'superdav-ai-agent'
							) }** \`${ ctx.file }:${ ctx.line }\``;
							if (
								Array.isArray( ctx.trace ) &&
								ctx.trace.length > 0
							) {
								errorText +=
									'\n\n**' +
									__( 'Stack trace:', 'superdav-ai-agent' ) +
									'**\n```\n' +
									ctx.trace.join( '\n' ) +
									'\n```';
							}
						}

						if ( select.getCurrentSessionId() === sessionId ) {
							dispatch.appendMessage( {
								role: 'system',
								parts: [ { text: errorText } ],
							} );
							// WP_Error max_iterations — show feedback banner (t183).
							const errMsg = result.message || '';
							if ( /max.?iteration/i.test( errMsg ) ) {
								dispatch.setFeedbackBanner( {
									exitReason: 'max_iterations',
								} );
							}
							// Play error sound for the active session.
							playDong();
						}
					}

					if ( result.status === 'complete' ) {
						// Reload the session from the DB when it's the active session.
						if (
							result.session_id &&
							select.getCurrentSessionId() === sessionId
						) {
							try {
								const session = await apiFetch( {
									path: `/sd-ai-agent/v1/sessions/${ result.session_id }`,
								} );
								// Guard: still the active session after the async fetch.
								if (
									select.getCurrentSessionId() ===
									result.session_id
								) {
									dispatch.setCurrentSession(
										session.id,
										session.messages || [],
										session.tool_calls || []
									);
								}
							} catch {
								// Fallback: append locally if DB reload fails.
								if ( result.reply ) {
									dispatch.appendMessage( {
										role: 'model',
										parts: [ { text: result.reply } ],
										toolCalls: result.tool_calls,
									} );
								}
							}
						} else if (
							result.reply &&
							select.getCurrentSessionId() === sessionId
						) {
							dispatch.appendMessage( {
								role: 'model',
								parts: [ { text: result.reply } ],
								toolCalls: result.tool_calls,
							} );
						}

						if (
							result.token_usage &&
							select.getCurrentSessionId() === sessionId
						) {
							const current = select.getTokenUsage();
							dispatch.setTokenUsage( {
								prompt:
									current.prompt +
									( result.token_usage.prompt || 0 ),
								completion:
									current.completion +
									( result.token_usage.completion || 0 ),
							} );
							const tu = result.token_usage;
							const totalTokens =
								( tu.prompt || 0 ) + ( tu.completion || 0 );
							const cost = result.cost_estimate || 0;
							dispatch.accumulateSessionTokens(
								totalTokens,
								cost
							);

							// Record per-message metadata on the last model
							// message so the MessageList action row can
							// display model · duration · tokens · cost.
							const msgs =
								select.getCurrentSessionMessages() || [];
							let lastModelIdx = -1;
							for ( let i = msgs.length - 1; i >= 0; i-- ) {
								if ( msgs[ i ].role === 'model' ) {
									lastModelIdx = i;
									break;
								}
							}
							if ( lastModelIdx >= 0 ) {
								const sentAt = select.getSendTimestamp() || 0;
								const duration = sentAt
									? ( Date.now() - sentAt ) / 1000
									: null;
								const providers = select.getProviders() || [];
								const pid = select.getSelectedProviderId();
								const mid = select.getSelectedModelId();
								const provider = providers.find(
									( p ) => p.id === pid
								);
								const model = provider?.models?.find(
									( m ) => m.id === mid
								);
								dispatch.setMessageTokens( lastModelIdx, {
									prompt: tu.prompt || 0,
									completion: tu.completion || 0,
									cost,
									duration,
									modelId: model?.id || mid,
									modelName: model?.name || model?.id || mid,
									providerId: provider?.id || pid,
									providerName: provider?.name || '',
								} );
							}
						}

						if ( result.generated_title && result.session_id ) {
							dispatch.updateSessionTitle(
								result.session_id,
								result.generated_title
							);
						}

						if (
							result.inability_reported &&
							select.getCurrentSessionId() === sessionId
						) {
							dispatch.setInabilityReported(
								result.inability_reported
							);
						}

						const FEEDBACK_EXIT_REASONS = [
							'spin_detected',
							'timeout',
							'max_iterations',
						];
						if (
							FEEDBACK_EXIT_REASONS.includes(
								result.exit_reason
							) &&
							select.getCurrentSessionId() === sessionId
						) {
							dispatch.setFeedbackBanner( {
								exitReason: result.exit_reason,
							} );
						}

						if ( select.getCurrentSessionId() === sessionId ) {
							dispatch.fetchSessions();
						}
						// Mark successful completion so the ding can fire below.
						lastStatusComplete = true;
					}
				} catch {
					// Network blip — keep polling with backoff.
					await new Promise( ( resolve ) =>
						setTimeout( resolve, getInterval( attempts ) )
					);
					poll();
					return;
				}

				// Job finished (complete or error).
				unsubscribeVisibility();
				clearActiveJob( sessionId );
				if ( select.getCurrentSessionId() === sessionId ) {
					// Play success sound when the job completed without error.
					if ( lastStatusComplete ) {
						playDing();
					}
					dispatch.setSending( false );
					dispatch.setLiveToolCalls( [] );
					// Auto-drain the message queue.
					dispatch.drainMessageQueue();
				}
				dispatch.setCurrentJobId( null );
				dispatch.setSessionJob( sessionId, null );
			};

			// Persist to sessionStorage so the poll loop survives same-tab
			// wp-admin page navigation (Phase 4 / t206).
			setActiveJob( sessionId, jobId );

			// Initial delay before first poll.
			setTimeout( poll, 2000 );
		};
	},
};

export const selectors = {
	/**
	 * @param {import('../../types').StoreState} state
	 * @return {string|null} Active polling job ID, or null.
	 */
	getCurrentJobId( state ) {
		return state.currentJobId;
	},

	/**
	 * @param {import('../../types').StoreState} state
	 * @return {Array} Live tool call progress from the background job.
	 */
	getLiveToolCalls( state ) {
		return state.liveToolCalls;
	},

	/**
	 * Get the full sessionJobs map.
	 *
	 * @param {import('../../types').StoreState} state
	 * @return {Object} Map of sessionId → job data.
	 */
	getSessionJobs( state ) {
		return state.sessionJobs;
	},

	/**
	 * Get the job for a specific session.
	 *
	 * @param {import('../../types').StoreState} state
	 * @param {number}                           sessionId - Session identifier.
	 * @return {Object|null} Job data or null.
	 */
	getSessionJob( state, sessionId ) {
		return state.sessionJobs[ sessionId ] || null;
	},

	/**
	 * @param {import('../../types').StoreState} state
	 * @return {Object|null} Pending tool confirmation, or null.
	 */
	getPendingConfirmation( state ) {
		return state.pendingConfirmation;
	},

	/**
	 * @param {import('../../types').StoreState} state
	 * @return {Object|null} Pending action card data, or null.
	 */
	getPendingActionCard( state ) {
		return state.pendingActionCard;
	},

	/**
	 * Get the pending client tool result retry payload.
	 *
	 * @param {import('../../types').StoreState} state
	 * @return {Object|null} Retry data { sessionId, jobId, toolResults, toolNames } or null.
	 */
	getPendingToolResultRetry( state ) {
		return state.pendingToolResultRetry;
	},
};

/**
 * @param {import('../../types').StoreState} state  - Current state.
 * @param {Object}                           action - Dispatched action.
 * @return {import('../../types').StoreState} Next state.
 */
export function reducer( state, action ) {
	switch ( action.type ) {
		case 'SET_CURRENT_JOB_ID':
			return {
				...state,
				currentJobId: action.jobId,
				// Clear live tool calls when job is cleared.
				liveToolCalls: action.jobId ? state.liveToolCalls : [],
			};
		case 'SET_LIVE_TOOL_CALLS':
			return { ...state, liveToolCalls: action.toolCalls };
		case 'SET_SESSION_JOB': {
			const newJobs = { ...state.sessionJobs };
			if ( action.job ) {
				newJobs[ action.sessionId ] = action.job;
			} else {
				delete newJobs[ action.sessionId ];
			}
			return { ...state, sessionJobs: newJobs };
		}
		case 'SET_PENDING_CONFIRMATION':
			return { ...state, pendingConfirmation: action.confirmation };
		case 'SET_PENDING_ACTION_CARD':
			return { ...state, pendingActionCard: action.card };
		case 'SET_PENDING_TOOL_RESULT_RETRY':
			return { ...state, pendingToolResultRetry: action.data };
		default:
			return state;
	}
}
