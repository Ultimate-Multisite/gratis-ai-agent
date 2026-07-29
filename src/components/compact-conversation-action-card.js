/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Render the safe continuation action offered after a local envelope rejection.
 *
 * @param {Object}   props
 * @param {Function} props.onConfirm Creates and opens the bounded continuation.
 * @param {Function} props.onCancel  Dismisses the action.
 * @param {string}   props.error     Latest compaction failure message.
 * @return {JSX.Element} Compaction action card.
 */
export default function CompactConversationActionCard( {
	onConfirm,
	onCancel,
	error = '',
} ) {
	return (
		<div className="sd-ai-agent-compact-conversation-action-card">
			<div className="sd-ai-agent-compact-conversation-action-card__body">
				<p>
					{ __(
						'Too large to send. Compact it; your original chat stays available.',
						'superdav-ai-agent'
					) }
				</p>
				{ error && <p role="alert">{ error }</p> }
			</div>
			<div className="sd-ai-agent-compact-conversation-action-card__footer">
				<button
					type="button"
					className="button sd-ai-agent-compact-conversation-action-card__cancel"
					onClick={ onCancel }
				>
					{ __( 'Keep original', 'superdav-ai-agent' ) }
				</button>
				<button
					type="button"
					className="button button-primary sd-ai-agent-compact-conversation-action-card__confirm"
					onClick={ onConfirm }
				>
					{ __( 'Compact and continue', 'superdav-ai-agent' ) }
				</button>
			</div>
		</div>
	);
}
