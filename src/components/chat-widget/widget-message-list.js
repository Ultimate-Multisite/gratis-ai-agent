/**
 * Floating-widget scroll container around the shared message presentation.
 *
 * This component owns widget-only container classes and compact error layout;
 * message filtering, role rendering, account actions, running presentation,
 * scrolling, and compaction behavior are shared with the main chat.
 */

import { useSelect, useDispatch } from '@wordpress/data';
import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import STORE_NAME from '../../store';
import CompactConversationActionCard from '../compact-conversation-action-card';
import RecoverableJobActionCard from '../recoverable-job-action-card';
import FeedbackConsentModal from '../feedback-consent-modal';
import MessageRows from '../chat-messages/message-rows';
import {
	getRunningJobPresentation,
	getVisibleMessages,
} from '../chat-messages/message-presentation';
import useCompactConversationAction from '../chat-messages/use-compact-conversation-action';
import useMessageListScroll from '../chat-messages/use-message-list-scroll';
import useRunningStatus from '../chat-messages/use-running-status';
import { RunningMessage } from '../chat-redesign/message-items';

/**
 * Lightweight retry card for a browser-result POST that did not reach the
 * server. Kept local so the floating-widget bundle does not load the much
 * broader confirmation/proposal ActionCard component.
 *
 * @param {Object}   props           Component props.
 * @param {string[]} props.toolNames Completed client tool names.
 * @param {Function} props.onConfirm Retry callback.
 * @param {Function} props.onCancel  Dismiss callback.
 * @return {JSX.Element} Client-result retry action.
 */
function ClientToolRetryActionCard( { toolNames = [], onConfirm, onCancel } ) {
	return (
		<div
			className="sdaa-action-card sdaa-action-card--retry"
			role="region"
			aria-label={ __(
				'Retry client tool results',
				'superdav-ai-agent'
			) }
		>
			<div className="sdaa-action-card-header">
				<span className="sdaa-action-card-heading">
					{ __(
						'Submit completed results again?',
						'superdav-ai-agent'
					) }
				</span>
			</div>
			<div className="sdaa-action-card-body">
				<p>
					{ __(
						'The browser tools already ran. Retry only resubmits their saved results.',
						'superdav-ai-agent'
					) }
				</p>
				{ toolNames.length > 0 && (
					<p className="sdaa-action-card-tool-names">
						{ toolNames.join( ', ' ) }
					</p>
				) }
			</div>
			<div className="sdaa-action-card-footer">
				<button
					type="button"
					className="button sdaa-action-card-btn-cancel"
					onClick={ onCancel }
				>
					{ __( 'Dismiss', 'superdav-ai-agent' ) }
				</button>
				<button
					type="button"
					className="button button-primary sdaa-action-card-btn-confirm"
					onClick={ onConfirm }
				>
					{ __( 'Retry', 'superdav-ai-agent' ) }
				</button>
			</div>
		</div>
	);
}

/**
 * Render the floating widget's message list and surface-specific actions.
 *
 * @return {JSX.Element} Floating widget message list.
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
		pendingToolResultRetry,
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
			pendingToolResultRetry: store.getPendingToolResultRetry?.(),
			providers: store.getProviders(),
			showToolCallDetails: settings?.show_tool_call_details === true,
		};
	}, [] );

	const {
		sendMessage,
		retryLastMessage,
		retryClientToolSubmission,
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
	const actionableCardTypes = [
		'retry_client_tools',
		'resume_recoverable_job',
	];
	const actionCardSessionId =
		pendingActionCard?.sessionId ||
		( pendingActionCard?.type === 'retry_client_tools'
			? pendingToolResultRetry?.sessionId
			: null );
	const hasActionableCard =
		actionableCardTypes.includes( pendingActionCard?.type ) &&
		( ! actionCardSessionId || actionCardSessionId === currentSessionId );
	const confirmRecoveryAction = () => {
		if ( pendingActionCard?.type === 'retry_client_tools' ) {
			retryClientToolSubmission();
			return;
		}
		if ( pendingActionCard?.type === 'resume_recoverable_job' ) {
			resumeRecoverableJob();
		}
	};

	return (
		<>
			<div className="sdaa-w-body" ref={ containerRef }>
				<div className="sdaa-w-body-inner">
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
						! hasActionableCard &&
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

					{ hasActionableCard &&
						! sending &&
						pendingActionCard.type === 'retry_client_tools' && (
							<ClientToolRetryActionCard
								toolNames={ pendingActionCard.toolNames }
								onConfirm={ confirmRecoveryAction }
								onCancel={ () => setPendingActionCard( null ) }
							/>
						) }

					{ hasActionableCard &&
						! sending &&
						pendingActionCard.type === 'resume_recoverable_job' && (
							<RecoverableJobActionCard
								diagnostic={ pendingActionCard.diagnostic }
								onConfirm={ confirmRecoveryAction }
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
