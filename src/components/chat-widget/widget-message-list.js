/**
 * Floating-widget scroll container around the shared message-items.
 *
 * Rendering of user / assistant / running / system messages is handled
 * by `chat-redesign/message-items.js` so the widget and full chat look
 * identical. This file only owns the widget-scoped scroll container,
 * the visible-messages filter, the running placeholder, and the
 * feedback consent modal invoked by a thumbs-down click.
 *
 * Scroll behaviour:
 *   - Auto-scrolls to the bottom only when the user is already near the bottom.
 *   - When scrolled away and new messages arrive, a "scroll to bottom" button
 *     appears with a badge showing how many new messages were missed.
 *   - The button disappears when the user clicks it or scrolls back down.
 */

import { useSelect, useDispatch } from '@wordpress/data';
import {
	lazy,
	Suspense,
	useRef,
	useEffect,
	useState,
	useCallback,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import STORE_NAME from '../../store';
import CompactConversationActionCard from '../compact-conversation-action-card';
import FeedbackConsentModal from '../feedback-consent-modal';
import {
	extractText,
	getFriendlyToolLabel,
	getRunningToolName,
} from '../chat-redesign/message-helpers';
import {
	AssistantMessage,
	RunningMessage,
	SystemMessage,
	UserMessage,
} from '../chat-redesign/message-items';
import {
	buildSuperdavCreditNoticeMessage,
	isSuperdavCreditBalanceNotice,
} from '../../utils/superdav-credit-notice';

/** Distance (px) from the scroll bottom that is treated as "at the bottom". */
const SCROLL_THRESHOLD = 100;

const AccountActionSystemMessage = lazy( () =>
	import( '../chat-redesign/account-action-system-message' )
);

/**
 *
 */
export default function WidgetMessageList() {
	const {
		messages,
		sending,
		currentSessionId,
		liveToolCalls,
		sessionJobs,
		hasStreamError,
		pendingActionCard,
		providers,
		showToolCallDetails,
	} = useSelect( ( sel ) => {
		const store = sel( STORE_NAME );
		const settings = store.getSettings();
		return {
			messages: store.getCurrentSessionMessages(),
			sending: store.isSending(),
			currentSessionId: store.getCurrentSessionId(),
			liveToolCalls: store.getLiveToolCalls(),
			sessionJobs: store.getSessionJobs(),
			hasStreamError: store.hasStreamError(),
			pendingActionCard: store.getPendingActionCard(),
			providers: store.getProviders(),
			showToolCallDetails: settings?.show_tool_call_details === true,
		};
	}, [] );

	const {
		sendMessage,
		retryLastMessage,
		compactConversation,
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
	const [ compactError, setCompactError ] = useState( '' );

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
			el.scrollTop = el.scrollHeight;
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

	const compactAndContinue = useCallback( async () => {
		setCompactError( '' );
		const compacted = await compactConversation();
		if ( compacted === true ) {
			setPendingActionCard( null );
			return;
		}

		setCompactError(
			compacted?.error ||
				__(
					'Unable to compact this conversation. Please try again.',
					'superdav-ai-agent'
				)
		);
	}, [ compactConversation, setPendingActionCard ] );

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
		? `${ getFriendlyToolLabel( runningToolName ) }…`
		: __( 'Composing reply…', 'superdav-ai-agent' );

	// ── Render ────────────────────────────────────────────────────────────────

	return (
		<>
			<div className="sdaa-w-body" ref={ ref }>
				<div className="sdaa-w-body-inner">
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
									<Suspense key={ index } fallback={ null }>
										<AccountActionSystemMessage
											notice={ msg.notice }
										/>
									</Suspense>
								);
							}
							const text = extractText( msg );
							if ( isSuperdavCreditBalanceNotice( text ) ) {
								return (
									<Suspense key={ index } fallback={ null }>
										<AccountActionSystemMessage
											notice={
												buildSuperdavCreditNoticeMessage(
													providers
												).notice
											}
										/>
									</Suspense>
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
							showToolCallDetails={ showToolCallDetails }
						/>
					) }

					{ hasStreamError &&
						currentSessionId &&
						! sending &&
						pendingActionCard?.type !== 'compact_session' && (
							<button
								type="button"
								className="button button-primary sdaa-w-retry-failed-step"
								onClick={ retryLastMessage }
							>
								{ __(
									'Retry failed step',
									'superdav-ai-agent'
								) }
							</button>
						) }

					{ pendingActionCard?.type === 'compact_session' &&
						pendingActionCard.sessionId === currentSessionId &&
						! sending && (
							<CompactConversationActionCard
								error={ compactError }
								onConfirm={ compactAndContinue }
								onCancel={ () => setPendingActionCard( null ) }
							/>
						) }
				</div>
			</div>

			{ unseenCount > 0 && (
				<div className="sdaa-w-scroll-to-bottom">
					<button
						type="button"
						className="sdaa-w-scroll-btn"
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
						<span className="sdaa-w-scroll-btn-badge">
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
