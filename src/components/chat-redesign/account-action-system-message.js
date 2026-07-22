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
	return (
		<div className="sdaa-cr-msg-row">
			<div
				className="sdaa-cr-msg-system sdaa-cr-msg-system--account-action"
				role="status"
			>
				{ notice[ 0 ] }
				{ actionUrl && (
					<a
						className="sdaa-cr-msg-system-action"
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
