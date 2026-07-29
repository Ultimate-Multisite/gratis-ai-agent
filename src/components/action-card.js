/**
 * WordPress dependencies
 */
import { useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import RecoverableJobActionCard from './recoverable-job-action-card';

/**
 * Render a human-readable label for a tool call.
 *
 * Converts the internal wpab__ function name to a readable string and
 * formats the most relevant argument as a short description.
 *
 * @param {string} name The raw function name (e.g. wpab__ai-agent__post-delete).
 * @param {Object} args The tool arguments.
 * @return {{ title: string, description: string }} Human-readable title and description.
 */
function describeToolCall( name, args ) {
	// Strip the wpab__ prefix and convert dashes/underscores to spaces.
	const readable = name
		.replace( /^wpab__[^_]+__/, '' )
		.replace( /[-_]/g, ' ' );

	const title = readable
		.split( ' ' )
		.map( ( w ) => w.charAt( 0 ).toUpperCase() + w.slice( 1 ) )
		.join( ' ' );

	// Build a short description from the most relevant arg.
	const argEntries = args ? Object.entries( args ) : [];
	let description = '';
	if ( argEntries.length > 0 ) {
		// Prefer id, title, name, slug, path, url in that order.
		const preferred = [ 'id', 'title', 'name', 'slug', 'path', 'url' ];
		const found = preferred.find( ( k ) =>
			argEntries.some( ( [ key ] ) => key === k )
		);
		if ( found ) {
			const val = args[ found ];
			description = `${ found }: ${ val }`;
		} else {
			// Fall back to first arg.
			const [ key, val ] = argEntries[ 0 ];
			description = `${ key }: ${ String( val ).slice( 0, 80 ) }`;
		}
	}

	return { title, description };
}

/**
 * Format the allowlisted checkpoint phase for a short user-facing label.
 *
 * @param {string} phase Checkpoint phase from the safe diagnostic DTO.
 * @return {string} Human-readable phase label.
 */
function formatFailurePhase( phase ) {
	if ( ! /^[a-z0-9_]{1,60}$/.test( phase ) ) {
		return '';
	}

	return phase.replace( /_/g, ' ' );
}

/**
 * Explain the specific recovery action without exposing implementation detail.
 *
 * @param {string} nextAction Normalized recovery action.
 * @return {string} Customer-facing next-step guidance.
 */
function getFailureNextStep( nextAction ) {
	switch ( nextAction ) {
		case 'compact':
			return __(
				'Start a smaller continuation with a shorter recent message or fewer attachments.',
				'superdav-ai-agent'
			);
		case 'retry':
			return __(
				'Retry the last message. Completed work in the conversation is preserved.',
				'superdav-ai-agent'
			);
		case 'approve_review':
			return __(
				'Review the pending approval before continuing.',
				'superdav-ai-agent'
			);
		case 'continuation':
			return __(
				'Start a continuation from the saved conversation.',
				'superdav-ai-agent'
			);
		default:
			return __(
				'Contact support with the support ID below if the problem continues.',
				'superdav-ai-agent'
			);
	}
}

/**
 * Return the active or next unfinished phase for a durable plan.
 *
 * @param {Object} plan Browser-safe durable plan payload.
 * @return {Object|null} Current plan phase.
 */
function getCurrentPlanStep( plan ) {
	const steps = Array.isArray( plan?.steps ) ? plan.steps : [];
	const current = steps.find(
		( step ) => Number( step.position ) === Number( plan.current_step )
	);
	if (
		current &&
		! [ 'completed', 'cancelled' ].includes( current.status )
	) {
		return current;
	}

	return (
		steps.find(
			( step ) => ! [ 'completed', 'cancelled' ].includes( step.status )
		) || null
	);
}

/**
 * Build the user-facing controls for a durable plan lifecycle state.
 *
 * @param {Object}  root0                 State flags.
 * @param {boolean} root0.isScopeApproval Whether a scope change is pending.
 * @param {boolean} root0.isApproval      Whether any plan approval is pending.
 * @param {boolean} root0.isRetry         Whether an explicit retry is needed.
 * @param {boolean} root0.isRunning       Whether a phase is executing.
 * @return {{heading: string, confirmLabel: string, cancelLabel: string}} Labels.
 */
function getDurablePlanLabels( {
	isScopeApproval,
	isApproval,
	isRetry,
	isRunning,
} ) {
	if ( isScopeApproval ) {
		return {
			heading: __( 'Approve plan scope change', 'superdav-ai-agent' ),
			confirmLabel: __( 'Approve scope change', 'superdav-ai-agent' ),
			cancelLabel: __( 'Keep current scope', 'superdav-ai-agent' ),
		};
	}
	if ( isApproval ) {
		return {
			heading: __( 'Approve plan phase', 'superdav-ai-agent' ),
			confirmLabel: __( 'Approve phase', 'superdav-ai-agent' ),
			cancelLabel: __( 'Decline plan', 'superdav-ai-agent' ),
		};
	}
	if ( isRetry ) {
		return {
			heading: __( 'Plan phase needs review', 'superdav-ai-agent' ),
			confirmLabel: __( 'Retry phase', 'superdav-ai-agent' ),
			cancelLabel: __( 'Cancel plan', 'superdav-ai-agent' ),
		};
	}
	if ( isRunning ) {
		return {
			heading: __( 'Plan phase in progress', 'superdav-ai-agent' ),
			confirmLabel: '',
			cancelLabel: __( 'Cancel plan', 'superdav-ai-agent' ),
		};
	}

	return {
		heading: __( 'Site operation plan', 'superdav-ai-agent' ),
		confirmLabel: __( 'Start next phase', 'superdav-ai-agent' ),
		cancelLabel: __( 'Cancel plan', 'superdav-ai-agent' ),
	};
}

/**
 * ActionCard — inline confirmation card rendered in the message list.
 *
 * Shown when the AI proposes a destructive or significant operation and
 * the tool permission is set to "confirm". The user can approve or cancel
 * without leaving the chat flow.
 *
 * @param {Object}   props
 * @param {Object}   props.card      The pending action card data { jobId, tools }.
 * @param {Function} props.onConfirm Called with (alwaysAllow: boolean) on confirm.
 * @param {Function} props.onCancel  Called on cancel/reject.
 */
export default function ActionCard( { card, onConfirm, onCancel } ) {
	const confirmRef = useRef( null );

	// Focus the confirm button when the card appears.
	useEffect( () => {
		if ( confirmRef.current ) {
			confirmRef.current.focus();
		}
	}, [] );

	// Retry card — shown when the POST to /chat/tool-result failed after all
	// automatic retries.  The browser already ran the tools; this card lets
	// the user resubmit the results without re-executing them.
	if ( card?.type === 'retry_client_tools' ) {
		const names = card.toolNames || [];
		return (
			<div
				className="sdaa-action-card sdaa-action-card--retry"
				role="region"
				aria-label={ __(
					'Retry tool submission',
					'superdav-ai-agent'
				) }
			>
				<div className="sdaa-action-card-header">
					<span className="sdaa-action-card-icon" aria-hidden="true">
						&#8635;
					</span>
					<span className="sdaa-action-card-heading">
						{ __(
							'Submission failed — retry?',
							'superdav-ai-agent'
						) }
					</span>
				</div>
				<div className="sdaa-action-card-body">
					<p>
						{ __(
							'The browser finished the tool calls but could not deliver the results to the server. Your work is preserved — click Retry to resubmit without re-running the tools.',
							'superdav-ai-agent'
						) }
					</p>
					{ names.length > 0 && (
						<p className="sdaa-action-card-tool-names">
							{ __( 'Completed tools:', 'superdav-ai-agent' ) }{ ' ' }
							<code>{ names.join( ', ' ) }</code>
						</p>
					) }
				</div>
				<div className="sdaa-action-card-footer">
					<button
						type="button"
						className="button sdaa-action-card-btn-cancel"
						onClick={ onCancel }
					>
						{ __( 'Cancel', 'superdav-ai-agent' ) }
					</button>
					<button
						type="button"
						ref={ confirmRef }
						className="button button-primary sdaa-action-card-btn-confirm"
						onClick={ () => onConfirm() }
					>
						{ __( 'Retry', 'superdav-ai-agent' ) }
					</button>
				</div>
			</div>
		);
	}

	if ( card?.type === 'active_job_failure' ) {
		const diagnostic = card.diagnostic || {};
		const nextAction =
			typeof diagnostic.next_action === 'string'
				? diagnostic.next_action
				: 'contact_support';
		const phase = formatFailurePhase( diagnostic.last_safe_phase || '' );
		const correlationId =
			typeof diagnostic.correlation_id === 'string' &&
			/^job-(?:[a-f0-9]{12}|unknown)$/.test( diagnostic.correlation_id )
				? diagnostic.correlation_id
				: '';
		const canRetry =
			'retry' === nextAction && typeof onConfirm === 'function';

		return (
			<div
				className="sdaa-action-card sdaa-action-card--failure"
				role="region"
				aria-label={ __(
					'Background job recovery details',
					'superdav-ai-agent'
				) }
			>
				<div className="sdaa-action-card-header">
					<span className="sdaa-action-card-heading">
						{ __( 'Job needs attention', 'superdav-ai-agent' ) }
					</span>
				</div>
				<div className="sdaa-action-card-body">
					<p>
						{ card.message ||
							__(
								'The background agent job could not finish.',
								'superdav-ai-agent'
							) }
					</p>
					<p className="sdaa-action-card-failure-next-step">
						<strong>
							{ __( 'Next step:', 'superdav-ai-agent' ) }
						</strong>{ ' ' }
						{ getFailureNextStep( nextAction ) }
					</p>
					{ phase && (
						<p className="sdaa-action-card-failure-phase">
							<strong>
								{ __(
									'Last completed step:',
									'superdav-ai-agent'
								) }
							</strong>{ ' ' }
							{ phase }
						</p>
					) }
					{ correlationId && (
						<p className="sdaa-action-card-failure-correlation">
							<strong>
								{ __( 'Support ID:', 'superdav-ai-agent' ) }
							</strong>{ ' ' }
							<code>{ correlationId }</code>
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
					{ canRetry && (
						<button
							type="button"
							ref={ confirmRef }
							className="button button-primary sdaa-action-card-btn-confirm"
							onClick={ onConfirm }
						>
							{ __( 'Retry last message', 'superdav-ai-agent' ) }
						</button>
					) }
				</div>
			</div>
		);
	}

	if ( card?.type === 'resume_recoverable_job' ) {
		return (
			<RecoverableJobActionCard
				diagnostic={ card.diagnostic }
				onConfirm={ onConfirm }
				onCancel={ onCancel }
			/>
		);
	}

	if ( card?.type === 'durable_plan' && card.plan ) {
		const plan = card.plan;
		const currentStep = getCurrentPlanStep( plan );
		const isScopeApproval =
			plan.status === 'awaiting_approval' &&
			Boolean( plan.pending_scope );
		const isApproval = plan.status === 'awaiting_approval';
		const isRetry = [ 'failed', 'blocked' ].includes( plan.status );
		const isRunning = plan.status === 'running';
		const canContinue = plan.status === 'pending';
		const hasAction = isApproval || isRetry || canContinue;
		const { heading, confirmLabel, cancelLabel } = getDurablePlanLabels( {
			isScopeApproval,
			isApproval,
			isRetry,
			isRunning,
		} );

		return (
			<div
				className="sdaa-action-card sd-ai-agent-durable-plan-card"
				role="region"
				aria-label={ __(
					'Durable site operation plan',
					'superdav-ai-agent'
				) }
			>
				<div className="sdaa-action-card-header">
					<span className="sdaa-action-card-icon" aria-hidden="true">
						&#9776;
					</span>
					<span className="sdaa-action-card-heading">
						{ heading }
					</span>
				</div>
				<div className="sdaa-action-card-body">
					{ plan.summary && (
						<p className="sd-ai-agent-durable-plan-summary">
							{ plan.summary }
						</p>
					) }
					<p className="sd-ai-agent-durable-plan-scope">
						<strong>
							{ __( 'Approved scope:', 'superdav-ai-agent' ) }
						</strong>{ ' ' }
						{ plan.scope }
					</p>
					{ isScopeApproval && (
						<p className="sd-ai-agent-durable-plan-scope-change">
							<strong>
								{ __(
									'Requested scope:',
									'superdav-ai-agent'
								) }
							</strong>{ ' ' }
							{ plan.pending_scope }
						</p>
					) }
					{ currentStep && (
						<details
							className="sd-ai-agent-durable-plan-phase"
							open={ isApproval || isRetry }
						>
							<summary>
								{ sprintf(
									/* translators: 1: phase number, 2: phase title, 3: phase status. */
									__(
										'Phase %1$d: %2$s (%3$s)',
										'superdav-ai-agent'
									),
									Number( currentStep.position ),
									currentStep.title,
									currentStep.status
								) }
							</summary>
							{ currentStep.instruction && (
								<p>{ currentStep.instruction }</p>
							) }
							{ currentStep.preconditions && (
								<p>
									<strong>
										{ __(
											'Preconditions:',
											'superdav-ai-agent'
										) }
									</strong>{ ' ' }
									{ currentStep.preconditions }
								</p>
							) }
							{ currentStep.expected_evidence && (
								<p>
									<strong>
										{ __(
											'Expected evidence:',
											'superdav-ai-agent'
										) }
									</strong>{ ' ' }
									{ currentStep.expected_evidence }
								</p>
							) }
							{ currentStep.rollback_guidance && (
								<p>
									<strong>
										{ __(
											'Rollback:',
											'superdav-ai-agent'
										) }
									</strong>{ ' ' }
									{ currentStep.rollback_guidance }
								</p>
							) }
							{ currentStep.failure_message && (
								<p className="sd-ai-agent-durable-plan-failure">
									{ currentStep.failure_message }
								</p>
							) }
						</details>
					) }
					<ol className="sd-ai-agent-durable-plan-steps">
						{ ( plan.steps || [] ).map( ( step ) => (
							<li key={ step.key || step.position }>
								<span className="sd-ai-agent-durable-plan-step-details">
									<span>{ step.title }</span>
									{ step.evidence?.summary && (
										<span className="sd-ai-agent-durable-plan-evidence">
											{ step.evidence.summary }
										</span>
									) }
								</span>
								<small>{ step.status }</small>
							</li>
						) ) }
					</ol>
				</div>
				{ ( hasAction || ! isRunning ) && (
					<div className="sdaa-action-card-footer">
						{ ! isRunning && (
							<button
								type="button"
								className="button sdaa-action-card-btn-cancel"
								onClick={ onCancel }
							>
								{ cancelLabel }
							</button>
						) }
						{ hasAction && (
							<button
								type="button"
								ref={ confirmRef }
								className="button button-primary sdaa-action-card-btn-confirm"
								onClick={ onConfirm }
							>
								{ confirmLabel }
							</button>
						) }
					</div>
				) }
			</div>
		);
	}

	if ( ! card || ! card.tools?.length ) {
		return null;
	}

	return (
		<div
			className="sdaa-action-card"
			role="region"
			aria-label={ __( 'Action confirmation', 'superdav-ai-agent' ) }
		>
			<div className="sdaa-action-card-header">
				<span className="sdaa-action-card-icon" aria-hidden="true">
					&#9888;
				</span>
				<span className="sdaa-action-card-heading">
					{ __( 'Confirm Action', 'superdav-ai-agent' ) }
				</span>
			</div>

			<div className="sdaa-action-card-body">
				{ card.tools.map( ( tool ) => {
					const { title, description } = describeToolCall(
						tool.name,
						tool.args
					);
					return (
						<div
							key={ tool.id || tool.name }
							className="sdaa-action-card-tool"
						>
							<div className="sdaa-action-card-tool-title">
								{ title }
							</div>
							{ description && (
								<div className="sdaa-action-card-tool-desc">
									{ description }
								</div>
							) }
							{ tool.args && (
								<details className="sdaa-action-card-tool-args-details">
									<summary>
										{ __(
											'View details',
											'superdav-ai-agent'
										) }
									</summary>
									<pre className="sdaa-action-card-tool-args">
										{ JSON.stringify( tool.args, null, 2 ) }
									</pre>
								</details>
							) }
						</div>
					);
				} ) }
			</div>

			<div className="sdaa-action-card-footer">
				<button
					type="button"
					className="button sdaa-action-card-btn-cancel"
					onClick={ onCancel }
				>
					{ __( 'Cancel', 'superdav-ai-agent' ) }
				</button>
				<button
					type="button"
					ref={ confirmRef }
					className="button button-primary sdaa-action-card-btn-confirm"
					onClick={ () => onConfirm( false ) }
				>
					{ __( 'Confirm', 'superdav-ai-agent' ) }
				</button>
			</div>
		</div>
	);
}
