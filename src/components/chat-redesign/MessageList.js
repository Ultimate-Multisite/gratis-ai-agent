/**
 * Message list for the redesigned chat.
 *
 * The actual message row components live in ./message-items.js so the
 * floating widget can reuse them verbatim and the two UIs render
 * identically. This file is only responsible for the scroll container,
 * filter of visible messages, the running placeholder, and wiring the
 * feedback consent modal invoked by a thumbs-down click.
 *
 * Scroll behaviour:
 *   - Auto-scrolls to the bottom only when the user is already near the bottom.
 *   - When scrolled away and new messages arrive, a "scroll to bottom" button
 *     appears with a badge showing how many new messages were missed.
 *   - The button disappears when the user clicks it or scrolls back down.
 */

import { useSelect, useDispatch } from '@wordpress/data';
import { useRef, useEffect, useState, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import STORE_NAME from '../../store';
import RecoverableJobActionCard from '../recoverable-job-action-card';
import FeedbackConsentModal from '../feedback-consent-modal';
import useTextToSpeech from '../use-text-to-speech';
import { extractText, getRunningToolName } from './message-helpers';
import AccountActionSystemMessage from './account-action-system-message';
import {
	buildSuperdavCreditNoticeMessage,
	isSuperdavCreditBalanceNotice,
} from '../../utils/superdav-credit-notice';
import {
	AssistantMessage,
	RunningMessage,
	SystemMessage,
	UserMessage,
} from './message-items';

/** Distance (px) from the scroll bottom that is treated as "at the bottom". */
const SCROLL_THRESHOLD = 100;

/**
 *
 */
export default function MessageList() {
	const {
		messages,
		sending,
		currentSessionId,
		liveToolCalls,
		sessionJobs,
		greeting,
		ttsEnabled,
		ttsVoiceURI,
		ttsRate,
		ttsPitch,
		hasStreamError,
		pendingActionCard,
		providers,
	} = useSelect( ( sel ) => {
		const store = sel( STORE_NAME );
		return {
			messages: store.getCurrentSessionMessages(),
			sending: store.isSending(),
			currentSessionId: store.getCurrentSessionId(),
			liveToolCalls: store.getLiveToolCalls(),
			sessionJobs: store.getSessionJobs(),
			greeting:
				store.getSettings()?.greeting_message ||
				__(
					'Ask the agent to make a change, write a post, or audit your site.',
					'sd-ai-agent'
				),
			ttsEnabled: store.isTtsEnabled(),
			ttsVoiceURI: store.getTtsVoiceURI(),
			ttsRate: store.getTtsRate(),
			ttsPitch: store.getTtsPitch(),
			hasStreamError: store.hasStreamError(),
			pendingActionCard: store.getPendingActionCard(),
			providers: store.getProviders(),
		};
	}, [] );

	const {
		sendMessage,
		retryLastMessage,
		resumeRecoverableJob,
		setPendingActionCard,
	} = useDispatch( STORE_NAME );
	const ref = useRef( null );

	/** True when the scroll container is within SCROLL_THRESHOLD px of the bottom. */
	const isAtBottomRef = useRef( true );
	/** Visible-message count from the previous auto-scroll effect run. */
	const prevVisibleCountRef = useRef( 0 );
	/**
	 * Current render's visible-message count, written during render so the
	 * effect can read it without adding the `visible` array (new reference on
	 * every render) to its dependency array.
	 */
	const visibleCountRef = useRef( 0 );

	const [ unseenCount, setUnseenCount ] = useState( 0 );
	const [ thumbsDownMessageIndex, setThumbsDownMessageIndex ] =
		useState( null );

	// TTS hook — mirrors the old message-list.js TTS integration.
	const { speak, cancel } = useTextToSpeech( {
		voiceURI: ttsVoiceURI,
		rate: ttsRate,
		pitch: ttsPitch,
	} );

	// Track the index of the last message spoken to avoid re-speaking.
	const lastSpokenIndexRef = useRef( -1 );

	// Speak new model messages when TTS is enabled and streaming completes.
	useEffect( () => {
		if ( ! ttsEnabled || sending ) {
			return;
		}

		const lastIdx = messages.length - 1;
		if ( lastIdx < 0 ) {
			return;
		}

		const lastMsg = messages[ lastIdx ];
		if ( lastMsg.role !== 'model' ) {
			return;
		}

		if ( lastIdx === lastSpokenIndexRef.current ) {
			return;
		}

		const text = extractText( lastMsg );
		if ( ! text ) {
			return;
		}

		lastSpokenIndexRef.current = lastIdx;
		speak( text );
	}, [ messages, ttsEnabled, sending, speak ] );

	// Cancel speech when TTS is disabled mid-conversation.
	useEffect( () => {
		if ( ! ttsEnabled ) {
			cancel();
		}
	}, [ ttsEnabled, cancel ] );

	// ── Compute visible messages ──────────────────────────────────────────────
	// Placed before effects so visibleCountRef is updated before they fire.

	const visible = [];
	for ( let i = 0; i < messages.length; i++ ) {
		const m = messages[ i ];
		if ( m.role === 'function' ) {
			continue;
		}
		if ( m.role === 'model' ) {
			const text = extractText( m );
			if ( ! text && ! m.toolCalls?.length ) {
				continue;
			}
		}
		if ( m.role === 'user' ) {
			const text = extractText( m );
			if ( ! text ) {
				continue;
			}
		}
		visible.push( { msg: m, index: i } );
	}

	// Keep the ref in sync so effects read the correct count without `visible`
	// (new array reference each render) being in their dependency arrays.
	visibleCountRef.current = visible.length;

	// ── Effects ───────────────────────────────────────────────────────────────

	// Reset scroll state on session switch so we always start at the bottom.
	useEffect( () => {
		isAtBottomRef.current = true;
		prevVisibleCountRef.current = 0;
		setUnseenCount( 0 );
	}, [ currentSessionId ] );

	// Passive scroll listener — tracks whether the user is near the bottom and
	// clears the unseen badge when they scroll back down.
	useEffect( () => {
		const el = ref.current;
		if ( ! el ) {
			return;
		}

		const handleScroll = () => {
			const atBottom =
				el.scrollHeight - el.scrollTop - el.clientHeight <
				SCROLL_THRESHOLD;
			isAtBottomRef.current = atBottom;
			if ( atBottom ) {
				setUnseenCount( 0 );
			}
		};

		el.addEventListener( 'scroll', handleScroll, { passive: true } );
		return () => el.removeEventListener( 'scroll', handleScroll );
	}, [] );

	// Auto-scroll when the user is already at the bottom; accumulate an
	// unseen-message count when they have scrolled away.
	useEffect( () => {
		const el = ref.current;
		if ( ! el ) {
			return;
		}

		const newCount = visibleCountRef.current;
		const prevCount = prevVisibleCountRef.current;
		prevVisibleCountRef.current = newCount;

		if ( isAtBottomRef.current ) {
			const savedY = window.scrollY;
			el.scrollTop = el.scrollHeight;
			if ( window.scrollY !== savedY ) {
				window.scrollTo( 0, savedY );
			}
		} else if ( newCount > prevCount ) {
			setUnseenCount( ( c ) => c + ( newCount - prevCount ) );
		}
	}, [ messages, sending, liveToolCalls ] );

	// ── Callbacks ─────────────────────────────────────────────────────────────

	const scrollToBottom = useCallback( () => {
		const el = ref.current;
		if ( ! el ) {
			return;
		}
		el.scrollTo( { top: el.scrollHeight, behavior: 'smooth' } );
		isAtBottomRef.current = true;
		setUnseenCount( 0 );
	}, [] );

	// ── Derived values ────────────────────────────────────────────────────────

	const lastRunningJob = currentSessionId
		? sessionJobs[ currentSessionId ]
		: null;
	const runningToolCalls =
		lastRunningJob?.toolCalls?.length > 0
			? lastRunningJob.toolCalls
			: liveToolCalls;

	const runningToolName = getRunningToolName( runningToolCalls );
	const runningStep = runningToolName
		? `${ __( 'Running', 'sd-ai-agent' ) } ${ runningToolName }…`
		: __( 'Composing reply…', 'sd-ai-agent' );

	// ── Render ────────────────────────────────────────────────────────────────

	return (
		<>
			<div className="sdaa-cr-messages" ref={ ref }>
				<div className="sdaa-cr-messages-inner">
					{ visible.length === 0 && ! sending && (
						<div className="sdaa-cr-empty">{ greeting }</div>
					) }

					{ visible.map( ( { msg, index }, i ) => {
						const isLast = i === visible.length - 1;
						if ( msg.role === 'user' ) {
							return (
								<UserMessage
									key={ index }
									msg={ msg }
									index={ index }
								/>
							);
						}
						if ( msg.role === 'model' ) {
							return (
								<AssistantMessage
									key={ index }
									msg={ msg }
									index={ index }
									onSuggestionSelect={ sendMessage }
									onThumbsDown={ setThumbsDownMessageIndex }
									isLastModel={ isLast && ! sending }
								/>
							);
						}
						if ( msg.role === 'system' ) {
							if ( msg.notice ) {
								return (
									<AccountActionSystemMessage
										key={ index }
										notice={ msg.notice }
									/>
								);
							}
							const text = extractText( msg );
							if ( isSuperdavCreditBalanceNotice( text ) ) {
								return (
									<AccountActionSystemMessage
										key={ index }
										notice={
											buildSuperdavCreditNoticeMessage(
												providers
											).notice
										}
									/>
								);
							}

							return (
								<SystemMessage key={ index } text={ text } />
							);
						}
						return null;
					} ) }

					{ sending && (
						<RunningMessage
							step={ runningStep }
							liveToolCalls={ runningToolCalls }
						/>
					) }

					{ hasStreamError && currentSessionId && ! sending && (
						<div className="sdaa-cr-error-banner" role="status">
							<span className="sdaa-cr-error-banner__message">
								{ __(
									'Something went wrong while sending your message.',
									'superdav-ai-agent'
								) }
							</span>
							<button
								type="button"
								className="sdaa-cr-error-banner__retry"
								onClick={ retryLastMessage }
							>
								{ __( 'Try again', 'superdav-ai-agent' ) }
							</button>
						</div>
					) }

					{ pendingActionCard?.type === 'resume_recoverable_job' &&
						pendingActionCard.sessionId === currentSessionId &&
						! sending && (
							<RecoverableJobActionCard
								onConfirm={ resumeRecoverableJob }
								onCancel={ () => setPendingActionCard( null ) }
							/>
						) }
				</div>
			</div>

			{ unseenCount > 0 && (
				<div className="sdaa-cr-scroll-to-bottom">
					<button
						type="button"
						className="sdaa-cr-scroll-btn"
						onClick={ scrollToBottom }
						aria-label={ __(
							'Scroll to latest messages',
							'sd-ai-agent'
						) }
					>
						{ /* Down-arrow chevron */ }
						<svg
							xmlns="http://www.w3.org/2000/svg"
							viewBox="0 0 24 24"
							aria-hidden="true"
							focusable="false"
						>
							<path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z" />
						</svg>
						<span className="sdaa-cr-scroll-btn-badge">
							{ unseenCount }
						</span>
					</button>
				</div>
			) }

			{ thumbsDownMessageIndex !== null && (
				<FeedbackConsentModal
					reportType="thumbs_down"
					sessionId={ currentSessionId }
					messageIndex={ thumbsDownMessageIndex }
					onClose={ () => setThumbsDownMessageIndex( null ) }
				/>
			) }
		</>
	);
}
