/**
 * Proposal Panel — displays a file diff and allows the user to apply or reject
 * a proposal before the AI executes a file-write or file-edit ability.
 */

import { useState, useCallback } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, Modal, TextareaControl } from '@wordpress/components';

import STORE_NAME from '../../store';
import './style.css';

/**
 * ProposalPanel component.
 *
 * @param {Object}   props          - Component props.
 * @param {Object}   props.proposal - The proposal object from the server with proposal_id, file_path, and diff_preview.
 * @param {Function} props.onClose  - Callback when the panel is closed.
 */
export default function ProposalPanel( { proposal, onClose } ) {
	const { proposalApplied, proposalRejected } = useDispatch( STORE_NAME );

	const [ isApplying, setIsApplying ] = useState( false );
	const [ isRejecting, setIsRejecting ] = useState( false );
	const [ error, setError ] = useState( null );

	const handleApply = useCallback( async () => {
		setIsApplying( true );
		setError( null );

		try {
			const result = await apiFetch( {
				path: `/sd-ai-agent/v1/proposals/${ proposal.proposal_id }/apply`,
				method: 'POST',
			} );

			// Dispatch the proposal applied action to update the store.
			proposalApplied( {
				proposal_id: proposal.proposal_id,
				result: result.result,
			} );

			onClose();
		} catch ( err ) {
			setError(
				err.message ||
					__( 'Failed to apply proposal.', 'superdav-ai-agent' )
			);
		} finally {
			setIsApplying( false );
		}
	}, [ proposal.proposal_id, proposalApplied, onClose ] );

	const handleReject = useCallback( async () => {
		setIsRejecting( true );
		setError( null );

		try {
			await apiFetch( {
				path: `/sd-ai-agent/v1/proposals/${ proposal.proposal_id }/reject`,
				method: 'POST',
			} );

			// Dispatch the proposal rejected action to update the store.
			proposalRejected( {
				proposal_id: proposal.proposal_id,
			} );

			onClose();
		} catch ( err ) {
			setError(
				err.message ||
					__( 'Failed to reject proposal.', 'superdav-ai-agent' )
			);
		} finally {
			setIsRejecting( false );
		}
	}, [ proposal.proposal_id, proposalRejected, onClose ] );

	if ( ! proposal ) {
		return null;
	}

	return (
		<Modal
			title={ sprintf(
				/* translators: %s: file path */
				__( 'Review Changes: %s', 'superdav-ai-agent' ),
				proposal.file_path
			) }
			onRequestClose={ onClose }
			className="sd-ai-agent-proposal-panel"
		>
			<div className="sd-ai-agent-proposal-content">
				<div className="sd-ai-agent-proposal-file-path">
					<strong>{ __( 'File:', 'superdav-ai-agent' ) }</strong>
					<code>{ proposal.file_path }</code>
				</div>

				<div className="sd-ai-agent-proposal-diff-container">
					<TextareaControl
						label={ __( 'Diff Preview', 'superdav-ai-agent' ) }
						value={ proposal.diff_preview || '' }
						readOnly={ true }
						className="sd-ai-agent-proposal-diff"
					/>
				</div>

				{ error && (
					<div className="sd-ai-agent-proposal-error">{ error }</div>
				) }

				<div className="sd-ai-agent-proposal-actions">
					<Button
						variant="primary"
						onClick={ handleApply }
						disabled={ isApplying || isRejecting }
						isBusy={ isApplying }
					>
						{ __( 'Apply', 'superdav-ai-agent' ) }
					</Button>
					<Button
						variant="secondary"
						onClick={ handleReject }
						disabled={ isApplying || isRejecting }
						isBusy={ isRejecting }
					>
						{ __( 'Reject', 'superdav-ai-agent' ) }
					</Button>
				</div>
			</div>
		</Modal>
	);
}
