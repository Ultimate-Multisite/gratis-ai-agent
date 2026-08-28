/**
 * WordPress dependencies
 */
import { useState, useCallback, useRef, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/**
 * Feedback consent modal.
 *
 * Shown when the user invokes /report-issue, clicks a thumbs-down button, or
 * when the auto-prompt banner fires on spin_detected / timeout / max_iterations.
 *
 * Features (t182):
 *   1. Summary stats: message count, tool call count, environment keys.
 *   2. Collapsible "View full payload" section with the sanitized JSON preview.
 *   3. Privacy notice.
 *   4. Optional user description textarea (pre-filled from the triggering context).
 *   5. "Strip tool results" checkbox for aggressive privacy.
 *   6. "Send Report" and "Dismiss" buttons.
 *
 * Features (t186):
 *   7. Optional messageIndex prop: when set, the payload is scoped to the targeted
 *      response's complete user turn rather than the full conversation.
 *   8. "Include full conversation" checkbox, visible only when messageIndex is set.
 *
 * @param {Object}   props                      - Component props.
 * @param {string}   props.reportType           - Type of report sent in the
 *                                              payload: 'user_reported',
 *                                              'thumbs_down', 'self_reported',
 *                                              'spin_detected', 'timeout', etc.
 * @param {string}   [props.userDescription=''] - Pre-filled description text.
 *                                              Editable by the user before sending.
 * @param {number}   [props.sessionId]          - Current session ID used to build
 *                                              the payload preview and the report.
 * @param {number}   [props.messageIndex]       - Zero-based index of the specific
 *                                              message that triggered the report.
 *                                              When provided, the payload includes
 *                                              only that response's complete user turn
 *                                              unless the user opts into the full
 *                                              conversation via the checkbox.
 * @param {Function} props.onClose              - Called when the modal should close.
 * @return {JSX.Element} The feedback consent modal element.
 */
export default function FeedbackConsentModal( {
	reportType,
	userDescription = '',
	sessionId,
	messageIndex,
	onClose,
} ) {
	const [ description, setDescription ] = useState( userDescription );
	const [ stripToolResults, setStripToolResults ] = useState( false );
	// When messageIndex is provided, the payload is scoped to that response's user turn.
	// The user can opt out of the scope restriction by checking this box (t186).
	const [ includeFullConversation, setIncludeFullConversation ] =
		useState( false );
	const [ isSending, setIsSending ] = useState( false );
	const [ isSent, setIsSent ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ payloadExpanded, setPayloadExpanded ] = useState( false );

	// Preview data fetched from the server.
	const [ preview, setPreview ] = useState( null );
	const [ previewLoading, setPreviewLoading ] = useState( false );

	const dialogRef = useRef( null );

	// Close on Escape key.
	useEffect( () => {
		const handler = ( e ) => {
			if ( e.key === 'Escape' ) {
				onClose();
			}
		};
		document.addEventListener( 'keydown', handler );
		return () => document.removeEventListener( 'keydown', handler );
	}, [ onClose ] );

	// Close on click outside the dialog box.
	useEffect( () => {
		const handler = ( e ) => {
			if (
				dialogRef.current &&
				! dialogRef.current.contains( e.target )
			) {
				onClose();
			}
		};
		document.addEventListener( 'mousedown', handler );
		return () => document.removeEventListener( 'mousedown', handler );
	}, [ onClose ] );

	// Fetch payload preview from the server when sessionId is present.
	// Re-fetch whenever stripToolResults, includeFullConversation, or messageIndex
	// changes so the preview stays accurate (t186: scoped vs full context).
	useEffect( () => {
		if ( ! sessionId ) {
			return;
		}

		let cancelled = false;
		setPreviewLoading( true );
		setPreview( null );

		// Build the preview query: include message_index only when the user has
		// not opted into the full conversation and a specific message was targeted.
		const useMessageScope =
			messageIndex !== undefined &&
			messageIndex !== null &&
			! includeFullConversation;

		let path = `/sd-ai-agent/v1/feedback/preview?session_id=${ sessionId }&strip_tool_results=${
			stripToolResults ? '1' : '0'
		}`;
		if ( useMessageScope ) {
			path += `&message_index=${ messageIndex }`;
		}

		apiFetch( { path } )
			.then( ( data ) => {
				if ( ! cancelled ) {
					setPreview( data );
				}
			} )
			.catch( () => {
				// Preview fetch failure is non-fatal — the modal remains functional.
				if ( ! cancelled ) {
					setPreview( null );
				}
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setPreviewLoading( false );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ sessionId, stripToolResults, messageIndex, includeFullConversation ] );

	const handleSend = useCallback( async () => {
		setIsSending( true );
		setError( null );
		try {
			// Include message_index when the payload should be scoped to the
			// specific message and its surrounding context (t186).
			const useMessageScope =
				messageIndex !== undefined &&
				messageIndex !== null &&
				! includeFullConversation;

			const postData = {
				report_type: reportType,
				user_description: description,
				session_id: sessionId ?? 0,
				strip_tool_results: stripToolResults,
			};
			if ( useMessageScope ) {
				postData.message_index = messageIndex;
			}

			await apiFetch( {
				path: '/sd-ai-agent/v1/feedback/send',
				method: 'POST',
				data: postData,
			} );
			setIsSent( true );
			// Auto-close after a short confirmation delay.
			setTimeout( onClose, 1500 );
		} catch {
			setError(
				__(
					'Failed to send report. Please try again.',
					'superdav-ai-agent'
				)
			);
			setIsSending( false );
		}
	}, [
		reportType,
		description,
		sessionId,
		stripToolResults,
		messageIndex,
		includeFullConversation,
		onClose,
	] );

	const summary = preview?.summary;

	return (
		<div className="sdaa-shortcuts-overlay">
			<div
				className="sdaa-feedback-modal"
				ref={ dialogRef }
				role="dialog"
				aria-modal="true"
				aria-labelledby="sdaa-feedback-title"
			>
				<div className="sdaa-feedback-modal__header">
					<h3 id="sdaa-feedback-title">
						{ __( 'Send Feedback Report', 'superdav-ai-agent' ) }
					</h3>
					<button
						type="button"
						className="sdaa-feedback-modal__close"
						onClick={ onClose }
						aria-label={ __( 'Close', 'superdav-ai-agent' ) }
					>
						&times;
					</button>
				</div>
				<div className="sdaa-feedback-modal__body">
					{ isSent ? (
						<p className="sdaa-feedback-modal__success">
							{ __(
								'Report sent. Thank you!',
								'superdav-ai-agent'
							) }
						</p>
					) : (
						<>
							<p className="sdaa-feedback-modal__notice">
								{ __(
									'No passwords, API keys, or credentials are included. Server paths are anonymized. Review the full payload below.',
									'superdav-ai-agent'
								) }
							</p>

							{ /* Summary stats — shown when a session is loaded */ }
							{ sessionId && (
								<div className="sdaa-feedback-modal__stats">
									{ previewLoading && (
										<p className="sdaa-feedback-modal__stats-loading">
											{ __(
												'Loading report preview…',
												'superdav-ai-agent'
											) }
										</p>
									) }
									{ ! previewLoading && summary && (
										<ul className="sdaa-feedback-modal__stats-list">
											<li>
												{ __(
													'Messages:',
													'superdav-ai-agent'
												) }{ ' ' }
												<strong>
													{ summary.message_count }
												</strong>
											</li>
											<li>
												{ __(
													'Tool calls:',
													'superdav-ai-agent'
												) }{ ' ' }
												<strong>
													{ summary.tool_call_count }
												</strong>
											</li>
											<li>
												{ __(
													'Environment keys:',
													'superdav-ai-agent'
												) }{ ' ' }
												<strong>
													{ summary.environment_keys
														?.length ?? 0 }
												</strong>
											</li>
											{ summary.model_id && (
												<li>
													{ __(
														'Model:',
														'superdav-ai-agent'
													) }{ ' ' }
													<strong>
														{ summary.model_id }
													</strong>
												</li>
											) }
										</ul>
									) }
								</div>
							) }

							{ /* Full conversation opt-in — only shown for thumbs-down (messageIndex is set) */ }
							{ sessionId &&
								messageIndex !== undefined &&
								messageIndex !== null && (
									<label
										htmlFor="sdaa-full-conversation"
										className="sdaa-feedback-modal__strip-label"
									>
										<input
											id="sdaa-full-conversation"
											type="checkbox"
											className="sdaa-feedback-modal__strip-checkbox"
											checked={ includeFullConversation }
											onChange={ ( e ) =>
												setIncludeFullConversation(
													e.target.checked
												)
											}
										/>
										{ __(
											'Include full conversation (by default only the user turn containing the selected response is sent)',
											'superdav-ai-agent'
										) }
									</label>
								) }

							{ /* Strip tool results checkbox */ }
							{ sessionId && (
								<label
									htmlFor="sdaa-strip-tool-results"
									className="sdaa-feedback-modal__strip-label"
								>
									<input
										id="sdaa-strip-tool-results"
										type="checkbox"
										className="sdaa-feedback-modal__strip-checkbox"
										checked={ stripToolResults }
										onChange={ ( e ) =>
											setStripToolResults(
												e.target.checked
											)
										}
									/>
									{ __(
										'Strip tool results (aggressive privacy — keeps tool names and arguments but redacts all outputs)',
										'superdav-ai-agent'
									) }
								</label>
							) }

							{ /* Collapsible payload preview */ }
							{ sessionId && preview?.payload && (
								<div className="sdaa-feedback-modal__payload-section">
									<button
										type="button"
										className="sdaa-feedback-modal__payload-toggle"
										onClick={ () =>
											setPayloadExpanded( ( v ) => ! v )
										}
										aria-expanded={ payloadExpanded }
									>
										{ payloadExpanded
											? __(
													'Hide full payload ▲',
													'superdav-ai-agent'
											  )
											: __(
													'View full payload ▼',
													'superdav-ai-agent'
											  ) }
									</button>
									{ payloadExpanded && (
										<pre className="sdaa-feedback-modal__payload-json">
											{ JSON.stringify(
												preview.payload,
												null,
												2
											) }
										</pre>
									) }
								</div>
							) }

							<label
								htmlFor="sdaa-feedback-description"
								className="sdaa-feedback-modal__label"
							>
								{ __(
									'Describe the issue (optional):',
									'superdav-ai-agent'
								) }
							</label>
							<textarea
								id="sdaa-feedback-description"
								className="sdaa-feedback-modal__textarea"
								value={ description }
								onChange={ ( e ) =>
									setDescription( e.target.value )
								}
								rows={ 4 }
								placeholder={ __(
									'What went wrong?',
									'superdav-ai-agent'
								) }
							/>
							{ error && (
								<p className="sdaa-feedback-modal__error">
									{ error }
								</p>
							) }
						</>
					) }
				</div>
				{ ! isSent && (
					<div className="sdaa-feedback-modal__footer">
						<button
							type="button"
							className="button"
							onClick={ onClose }
							disabled={ isSending }
						>
							{ __( 'Dismiss', 'superdav-ai-agent' ) }
						</button>
						<button
							type="button"
							className="button button-primary"
							onClick={ handleSend }
							disabled={ isSending }
						>
							{ isSending
								? __( 'Sending\u2026', 'superdav-ai-agent' )
								: __( 'Send Report', 'superdav-ai-agent' ) }
						</button>
					</div>
				) }
			</div>
		</div>
	);
}
