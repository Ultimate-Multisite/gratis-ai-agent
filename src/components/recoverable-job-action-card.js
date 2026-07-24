/**
 * WordPress dependencies
 */
import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Render the retry action for a failed job with durable recovery state.
 *
 * Kept separate from the broader confirmation ActionCard so the floating
 * widget does not need to include confirmation-only rendering code.
 *
 * @param {Object}   props
 * @param {Function} props.onConfirm Called to resume the failed job.
 * @param {Function} props.onCancel  Called to dismiss the action.
 * @return {JSX.Element} Recovery action card.
 */
export default function RecoverableJobActionCard( { onConfirm, onCancel } ) {
	const confirmRef = useRef( null );

	useEffect( () => {
		confirmRef.current?.focus();
	}, [] );

	return (
		<div
			className="sdaa-action-card sdaa-action-card--resume"
			role="region"
			aria-label={ __( 'Resume failed job', 'superdav-ai-agent' ) }
		>
			<div className="sdaa-action-card-header">
				<span className="sdaa-action-card-icon" aria-hidden="true">
					&#8635;
				</span>
				<span className="sdaa-action-card-heading">
					{ __(
						'Continue from the failed step?',
						'superdav-ai-agent'
					) }
				</span>
			</div>
			<div className="sdaa-action-card-body">
				<p>
					{ __(
						'Your conversation and completed work were saved. Resume continues the agent from that state without repeating your request.',
						'superdav-ai-agent'
					) }
				</p>
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
					ref={ confirmRef }
					className="button button-primary sdaa-action-card-btn-confirm"
					onClick={ onConfirm }
				>
					{ __( 'Retry failed step', 'superdav-ai-agent' ) }
				</button>
			</div>
		</div>
	);
}
