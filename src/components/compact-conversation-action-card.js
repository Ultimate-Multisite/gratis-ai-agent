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
		<div className="sdaa-action-card">
			<div className="sdaa-action-card-body">
				<p>
					{ __(
						'Too large to send. Compact it; your original chat stays available.',
						'superdav-ai-agent'
					) }
				</p>
				{ error && <p role="alert">{ error }</p> }
			</div>
			<div className="sdaa-action-card-footer">
				<button
					type="button"
					className="button sdaa-action-card-btn-cancel"
					onClick={ onCancel }
				>
					{ __( 'Keep original', 'superdav-ai-agent' ) }
				</button>
				<button
					type="button"
					className="button button-primary sdaa-action-card-btn-confirm"
					onClick={ onConfirm }
				>
					{ __( 'Compact and continue', 'superdav-ai-agent' ) }
				</button>
			</div>
		</div>
	);
}
