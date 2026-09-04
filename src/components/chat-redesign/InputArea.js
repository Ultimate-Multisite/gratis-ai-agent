/**
 * Main-chat composer presentation.
 *
 * Commands, attachments, speech, submission, and queue behavior are shared
 * with the floating widget through useChatComposer.
 */

import { __, sprintf } from '@wordpress/i18n';
import { Icon, arrowUp } from '@wordpress/icons';

import useChatComposer, {
	CHAT_ATTACHMENT_ACCEPT,
} from '../chat-composer/use-chat-composer';
import SlashCommandMenu from '../slash-command-menu';
import FeedbackConsentModal from '../feedback-consent-modal';
import { Paperclip, Microphone, Stop } from './icons';
import ModelPicker from './ModelPicker';
import AgentPicker from './AgentPicker';

/**
 * Render the full-size chat composer.
 *
 * @param {Object}  root0                   Component properties.
 * @param {boolean} root0.isSimpleMode      Whether customer/simple UI mode is active.
 * @param {Object}  root0.voiceConversation Managed voice coordinator.
 * @return {JSX.Element} Main-chat composer.
 */
export default function InputArea( {
	isSimpleMode = false,
	voiceConversation,
} = {} ) {
	const composer = useChatComposer( {
		isSimpleMode,
		maxTextareaHeight: 200,
		defaultPlaceholder: __(
			'Ask the agent to do something, or type / for commands…',
			'superdav-ai-agent'
		),
		voiceConversation,
	} );
	const sendLabel = composer.sending
		? __( 'Queue message', 'superdav-ai-agent' )
		: __( 'Send message', 'superdav-ai-agent' );

	return (
		<div className="sdaa-cr-input-area">
			{ composer.speechPhase !== 'idle' && composer.speechStatus && (
				<span className="sd-ai-agent-voice-status" aria-live="polite">
					{ composer.speechStatus }
				</span>
			) }
			{ ! isSimpleMode && composer.showSlash && (
				<SlashCommandMenu
					filter={ composer.text }
					onSelect={ composer.handleSlashSelect }
					onClose={ () => composer.setShowSlash( false ) }
				/>
			) }
			{ composer.feedbackModal.isOpen && (
				<FeedbackConsentModal
					reportType={ composer.feedbackModal.reportType }
					userDescription={ composer.feedbackModal.userDescription }
					sessionId={ composer.currentSessionId }
					onClose={ composer.closeFeedbackModal }
				/>
			) }
			{ composer.queueCount > 0 && (
				<div className="sdaa-cr-queue-indicator">
					{ composer.queueCount === 1
						? __( '1 message queued', 'superdav-ai-agent' )
						: sprintf(
								/* translators: %d: queued message count */
								__( '%d messages queued', 'superdav-ai-agent' ),
								composer.queueCount
						  ) }
				</div>
			) }
			{ composer.attachmentError && (
				<div
					className="sd-ai-agent-composer-attachment-error"
					role="alert"
				>
					{ composer.attachmentError }
				</div>
			) }
			{ composer.speechError && (
				<div
					className="sd-ai-agent-composer-attachment-error"
					role="alert"
				>
					{ composer.speechError }
				</div>
			) }
			<div className="sdaa-cr-input-inner">
				<div
					className={ `sdaa-cr-input-frame${
						composer.isDragOver ? ' is-drag-over' : ''
					}` }
					role="presentation"
					onMouseDown={ composer.handleFrameMouseDown }
					onDragOver={ ( event ) => {
						event.preventDefault();
						composer.setIsDragOver( true );
					} }
					onDragLeave={ ( event ) => {
						event.preventDefault();
						composer.setIsDragOver( false );
					} }
					onDrop={ composer.handleDrop }
				>
					{ composer.attachments.length > 0 && (
						<div className="sdaa-cr-attachments">
							{ composer.attachments.map(
								( attachment, index ) => (
									<div
										key={ index }
										className="sdaa-cr-attachment-thumb"
									>
										{ attachment.isImage ? (
											<img
												src={ attachment.dataUrl }
												alt={ attachment.name }
											/>
										) : (
											<span>
												{ attachment.name
													.split( '.' )
													.pop()
													.toUpperCase() }
											</span>
										) }
										<span className="sdaa-cr-attachment-thumb-name">
											{ attachment.name }
										</span>
										<button
											type="button"
											className="sdaa-cr-attachment-thumb-remove"
											onClick={ () =>
												composer.removeAttachment(
													index
												)
											}
											aria-label={ __(
												'Remove attachment',
												'superdav-ai-agent'
											) }
										>
											&times;
										</button>
									</div>
								)
							) }
						</div>
					) }
					<textarea
						ref={ composer.textareaRef }
						className="sdaa-cr-input-textarea"
						placeholder={ composer.placeholder }
						value={ composer.text }
						onChange={ ( event ) =>
							composer.setText( event.target.value )
						}
						onKeyDown={ composer.handleKeyDown }
						onPaste={ composer.handlePaste }
						rows={ 2 }
					/>
					<div className="sdaa-cr-input-toolbar">
						<div className="sdaa-cr-input-toolbar-left">
							<input
								ref={ composer.fileInputRef }
								type="file"
								accept={ CHAT_ATTACHMENT_ACCEPT }
								multiple
								style={ { display: 'none' } }
								onChange={ ( event ) => {
									if ( event.target.files?.length ) {
										composer.processFiles(
											event.target.files
										);
										event.target.value = '';
									}
								} }
							/>
							<button
								type="button"
								className="sdaa-cr-icon-btn"
								onClick={ () =>
									composer.fileInputRef.current?.click()
								}
								aria-label={ __(
									'Attach file',
									'superdav-ai-agent'
								) }
							>
								<Paperclip />
							</button>
							{ ! isSimpleMode && <ModelPicker /> }
							{ ! isSimpleMode && <AgentPicker /> }
						</div>
						<div className="sdaa-cr-input-toolbar-right">
							{ composer.micSupported && (
								<button
									type="button"
									className={ `sdaa-cr-icon-btn${
										composer.isListening ? ' is-active' : ''
									}` }
									onClick={ composer.toggleListening }
									disabled={ composer.micDisabled }
									aria-label={
										composer.isListening
											? __(
													'Stop recording',
													'superdav-ai-agent'
											  )
											: __(
													'Voice input',
													'superdav-ai-agent'
											  )
									}
									aria-pressed={ composer.isListening }
								>
									<Microphone />
								</button>
							) }
							<button
								type="button"
								className="sdaa-cr-send-btn"
								onClick={ composer.handleSend }
								disabled={ ! composer.canSend }
								aria-label={ sendLabel }
							>
								<Icon icon={ arrowUp } size={ 16 } />
							</button>
							{ composer.sending && (
								<button
									type="button"
									className="sdaa-cr-send-btn is-stop"
									onClick={ composer.stopGeneration }
									aria-label={ __(
										'Stop generation',
										'superdav-ai-agent'
									) }
								>
									<Stop />
								</button>
							) }
						</div>
					</div>
				</div>
			</div>
		</div>
	);
}
