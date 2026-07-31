/**
 * Account-action system notice for the full chat surface.
 */

import { __ } from '@wordpress/i18n';

/**
 * Render an account-action notice with an optional CTA.
 *
 * @param {Object} root0
 * @param {Array}  root0.notice Notice text and optional action URL.
 * @return {JSX.Element} Notice row.
 */
export default function AccountActionSystemMessage( { notice } ) {
	const actionUrl = notice[ 1 ];
	const isCreditExhausted = notice[ 2 ] === 'credit_exhausted';
	const accountSettings = __( 'account settings', 'superdav-ai-agent' );
	const creditPrefix = __(
		"You've used all of your available Superdav credits. Purchase more credits in your",
		'superdav-ai-agent'
	);
	const creditSuffix = __(
		'to continue using Superdav Chat Pro.',
		'superdav-ai-agent'
	);
	const accountSettingsLink = actionUrl ? (
		<a
			className="sd-ai-agent-cr-msg-system-inline-action"
			href={ actionUrl }
			target="_blank"
			rel="noopener noreferrer"
		>
			{ accountSettings }
		</a>
	) : (
		accountSettings
	);
	return (
		<div className="sdaa-cr-msg-row">
			<div
				className="sd-ai-agent-cr-msg-system sd-ai-agent-cr-msg-system--account-action"
				role="status"
			>
				{ isCreditExhausted ? (
					<>
						<span>{ creditPrefix } </span>
						<span>{ accountSettingsLink } </span>
						<span>{ creditSuffix }</span>
					</>
				) : (
					notice[ 0 ]
				) }
				{ actionUrl && (
					<a
						className="sd-ai-agent-cr-msg-system-action"
						href={ actionUrl }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __( 'Purchase credits', 'superdav-ai-agent' ) }
					</a>
				) }
			</div>
		</div>
	);
}
