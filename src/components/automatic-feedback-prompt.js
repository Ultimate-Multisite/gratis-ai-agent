/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import STORE_NAME from '../store';
import {
	FEEDBACK_REPORTING_PREFERENCES,
	getFeedbackReportingPreference,
	setFeedbackReportingPreference,
	submitAutomaticFeedback,
} from '../utils/feedback-reporting';

/**
 * Ask whether a detected completed-job failure should be reported.
 *
 * @param {Object} props           Component props.
 * @param {number} props.sessionId Current session ID.
 * @param {Object} props.failure   Failure metadata from the completed job.
 * @return {JSX.Element|null} Automatic feedback prompt.
 */
export default function AutomaticFeedbackPrompt( { sessionId, failure } ) {
	const { setFeedbackBanner } = useDispatch( STORE_NAME );
	const [ preference, setPreference ] = useState(
		getFeedbackReportingPreference
	);
	const [ isSending, setIsSending ] = useState( false );
	const [ isSent, setIsSent ] = useState( false );
	const [ error, setError ] = useState( '' );
	const autoAttemptedRef = useRef( '' );

	const dismiss = useCallback( () => {
		setFeedbackBanner( null );
	}, [ setFeedbackBanner ] );

	const sendReport = useCallback( async () => {
		if ( ! sessionId || ! failure || isSending || isSent ) {
			return;
		}

		setIsSending( true );
		setError( '' );
		try {
			await submitAutomaticFeedback( sessionId, failure );
			setIsSent( true );
			setFeedbackBanner( null );
		} catch {
			setError(
				__(
					'The report could not be sent. You can try again.',
					'superdav-ai-agent'
				)
			);
		} finally {
			setIsSending( false );
		}
	}, [ failure, isSending, isSent, sessionId, setFeedbackBanner ] );

	useEffect( () => {
		if ( ! failure || ! sessionId ) {
			return;
		}

		if ( preference === FEEDBACK_REPORTING_PREFERENCES.NEVER ) {
			dismiss();
			return;
		}

		if ( preference === FEEDBACK_REPORTING_PREFERENCES.ALWAYS ) {
			const eventKey = `${ sessionId }:${
				failure.eventId ||
				failure.reason ||
				failure.exitReason ||
				'error'
			}`;
			if ( autoAttemptedRef.current === eventKey ) {
				return;
			}
			autoAttemptedRef.current = eventKey;
			sendReport();
		}
	}, [ dismiss, failure, preference, sendReport, sessionId ] );

	if (
		! failure ||
		! sessionId ||
		isSent ||
		( preference === FEEDBACK_REPORTING_PREFERENCES.NEVER && ! error ) ||
		( preference === FEEDBACK_REPORTING_PREFERENCES.ALWAYS && ! error )
	) {
		return null;
	}

	const choosePreference = ( nextPreference ) => {
		setFeedbackReportingPreference( nextPreference );
		setPreference( nextPreference );
	};

	return (
		<div
			className="sd-ai-agent-feedback-prompt"
			role="region"
			aria-label={ __( 'Report detected problem', 'superdav-ai-agent' ) }
		>
			<div className="sd-ai-agent-feedback-prompt__content">
				<div className="sd-ai-agent-feedback-prompt__copy">
					<p className="sd-ai-agent-feedback-prompt__text">
						{ __(
							'It looks like part of this job failed. Would you like to report the problem?',
							'superdav-ai-agent'
						) }
					</p>
					<p className="sd-ai-agent-feedback-prompt__privacy">
						{ __(
							'The report includes a sanitized copy of this conversation and basic environment details. Passwords, API keys, and credentials are removed.',
							'superdav-ai-agent'
						) }
					</p>
					{ error && (
						<p className="sd-ai-agent-feedback-prompt__error">
							{ error }
						</p>
					) }
				</div>
				<div className="sd-ai-agent-feedback-prompt__actions">
					<Button
						variant="tertiary"
						onClick={ () => {
							choosePreference(
								FEEDBACK_REPORTING_PREFERENCES.NEVER
							);
							dismiss();
						} }
						disabled={ isSending }
					>
						{ __( 'No, never', 'superdav-ai-agent' ) }
					</Button>
					<Button
						variant="secondary"
						onClick={ dismiss }
						disabled={ isSending }
					>
						{ __( 'No', 'superdav-ai-agent' ) }
					</Button>
					<Button
						variant="secondary"
						onClick={ sendReport }
						disabled={ isSending }
					>
						{ __( 'Yes', 'superdav-ai-agent' ) }
					</Button>
					<Button
						variant="primary"
						onClick={ () => {
							choosePreference(
								FEEDBACK_REPORTING_PREFERENCES.ALWAYS
							);
							sendReport();
						} }
						disabled={ isSending }
					>
						{ isSending
							? __( 'Sending…', 'superdav-ai-agent' )
							: __( 'Yes, always', 'superdav-ai-agent' ) }
					</Button>
				</div>
			</div>
		</div>
	);
}
