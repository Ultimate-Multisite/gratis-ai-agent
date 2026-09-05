/**
 * Main-chat scroll container around the shared message presentation layer.
 *
 * Message filtering, role rendering, semantic account actions, running-job
 * presentation, scrolling, and compaction behavior are shared with the
 * floating widget. This component owns only main-chat concerns such as the
 * greeting, text-to-speech, and the full-size error/action-card layout.
 */

import { useSelect, useDispatch } from '@wordpress/data';
import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import STORE_NAME from '../../store';
import ActionCard from '../action-card';
import AutomaticFeedbackPrompt from '../automatic-feedback-prompt';
import CompactConversationActionCard from '../compact-conversation-action-card';
import FeedbackConsentModal from '../feedback-consent-modal';
import MessageRows from '../chat-messages/message-rows';
import {
	getRunningJobPresentation,
	getVisibleMessages,
} from '../chat-messages/message-presentation';
import useCompactConversationAction from '../chat-messages/use-compact-conversation-action';
import useMessageListScroll from '../chat-messages/use-message-list-scroll';
import useRunningStatus from '../chat-messages/use-running-status';
import { RunningMessage } from './message-items';

/**
 * Render the main chat's message list and surface-specific actions.
 *
 * @param {Object} root0                   Voice-aware component properties.
 * @param {Object} root0.voiceConversation Managed voice coordinator.
 * @return {JSX.Element} Main chat message list.
 */
export default function MessageList( { voiceConversation } ) {
	const {
		messages,
		sending,
		currentSessionId,
		liveToolCalls,
		sessionJobs,
		greeting,
		hasStreamError,
		pendingActionCard,
		providers,
		showToolCallDetails,
		feedbackBanner,
	} = useSelect( ( sel ) => {
		const store = sel( STORE_NAME );
		const settings = store.getSettings();
		return {
			messages: store.getCurrentSessionMessages(),
			sending: store.isSending(),
			currentSessionId: store.getCurrentSessionId(),
			liveToolCalls: store.getLiveToolCalls(),
			sessionJobs: store.getSessionJobs(),
			greeting:
				settings?.greeting_message ||
				__(
					'Ask the agent to make a change, write a post, or audit your site.',
					'superdav-ai-agent'
				),
			hasStreamError: store.hasStreamError(),
			pendingActionCard: store.getPendingActionCard(),
			providers: store.getProviders(),
			showToolCallDetails: settings?.show_tool_call_details === true,
			feedbackBanner: store.getFeedbackBanner?.() || null,
		};
	}, [] );

	const {
		sendMessage,
		retryLastMessage,
		resumeRecoverableJob,
		compactConversation,
		setPendingActionCard,
	} = useDispatch( STORE_NAME );
	const [ thumbsDownMessageIndex, setThumbsDownMessageIndex ] =
		useState( null );

	const visible = useMemo(
		() => getVisibleMessages( messages ),
		[ messages ]
	);
	const { containerRef, unseenCount, scrollToBottom } = useMessageListScroll(
		{
			visibleCount: visible.length,
			currentSessionId,
			sending,
			liveToolCalls,
			preservePageScroll: true,
		}
	);
	const { compactError, compactAndContinue } = useCompactConversationAction( {
		compactConversation,
		setPendingActionCard,
	} );
	const running = getRunningJobPresentation( {
		currentSessionId,
		sessionJobs,
		liveToolCalls,
	} );
	const runningStatus = useRunningStatus( sending && running.isFallback );

	const confirmJobFailureAction = () => {
		if ( pendingActionCard?.type === 'resume_recoverable_job' ) {
			resumeRecoverableJob();
			return;
		}

		if (
			pendingActionCard?.type === 'active_job_failure' &&
			pendingActionCard.diagnostic?.next_action === 'retry'
		) {
			retryLastMessage();
		}
	};

	return (
		<>
			<div
				className="sdaa-cr-messages"
				ref={ containerRef }
				aria-live={ voiceConversation?.isSpeaking ? 'off' : 'polite' }
			>
				<div className="sdaa-cr-messages-inner">
					{ visible.length === 0 && ! sending && (
						<div className="sdaa-cr-empty">{ greeting }</div>
					) }

					<MessageRows
						items={ visible }
						sending={ sending }
						providers={ providers }
						onSuggestionSelect={ sendMessage }
						onThumbsDown={ setThumbsDownMessageIndex }
					/>

					{ sending && (
						<RunningMessage
							step={
								running.isFallback
									? runningStatus
									: running.step
							}
							liveToolCalls={ running.toolCalls }
							showToolCallDetails={ showToolCallDetails }
						/>
					) }

					{ hasStreamError &&
						currentSessionId &&
						! sending &&
						pendingActionCard?.type !== 'compact_session' && (
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

					{ [
						'resume_recoverable_job',
						'active_job_failure',
					].includes( pendingActionCard?.type ) &&
						pendingActionCard.sessionId === currentSessionId &&
						! sending && (
							<ActionCard
								card={ pendingActionCard }
								onConfirm={ confirmJobFailureAction }
								onCancel={ () => setPendingActionCard( null ) }
							/>
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

					{ ! sending && (
						<AutomaticFeedbackPrompt
							sessionId={ currentSessionId }
							failure={ feedbackBanner }
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
							'superdav-ai-agent'
						) }
					>
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
