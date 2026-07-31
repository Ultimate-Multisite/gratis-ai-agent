/**
 * Shared account-action message rendered by every React chat surface.
 */

import { __ } from '@wordpress/i18n';

import {
	CREDIT_EXHAUSTED_REASON,
	PURCHASE_CREDITS_ACTION,
} from '../../utils/superdav-credit-notice';

/**
 * Resolve translated presentation copy for a semantic account action.
 *
 * @param {Object} notice Structured account-action notice.
 * @return {{prefix: string, linkText: string, suffix: string, actionText: string}}
 *   Translated notice presentation.
 */
export function getAccountActionPresentation( notice ) {
	if (
		notice?.reason === CREDIT_EXHAUSTED_REASON &&
		notice?.action === PURCHASE_CREDITS_ACTION
	) {
		return {
			prefix: __(
				"You've used all of your available SD AI credits. Purchase more credits in your",
				'superdav-ai-agent'
			),
			linkText: __( 'account settings', 'superdav-ai-agent' ),
			suffix: __( 'to continue using Standard.', 'superdav-ai-agent' ),
			actionText: __( 'Purchase credits', 'superdav-ai-agent' ),
		};
	}

	return {
		prefix:
			typeof notice?.message === 'string'
				? notice.message
				: __(
						'Review your account settings to continue.',
						'superdav-ai-agent'
				  ),
		linkText: '',
		suffix: '',
		actionText: __( 'Account settings', 'superdav-ai-agent' ),
	};
}

/**
 * Render a semantic account-action notice with an optional CTA.
 *
 * @param {Object} root0
 * @param {Object} root0.notice Structured account-action notice.
 * @return {JSX.Element} Notice row.
 */
export default function AccountActionMessage( { notice } ) {
	const actionUrl = notice?.actionUrl || '';
	const presentation = getAccountActionPresentation( notice );
	const inlineLink =
		actionUrl && presentation.linkText ? (
			<a
				className="sd-ai-agent-cr-msg-system-inline-action"
				href={ actionUrl }
				target="_blank"
				rel="noopener noreferrer"
			>
				{ presentation.linkText }
			</a>
		) : (
			presentation.linkText
		);

	return (
		<div className="sdaa-cr-msg-row">
			<div
				className="sd-ai-agent-cr-msg-system sd-ai-agent-cr-msg-system--account-action"
				role="status"
			>
				<span>{ presentation.prefix }</span>
				{ presentation.linkText && (
					<>
						{ ' ' }
						<span>{ inlineLink }</span>
					</>
				) }
				{ presentation.suffix && (
					<>
						{ ' ' }
						<span>{ presentation.suffix }</span>
					</>
				) }
				{ actionUrl && (
					<a
						className="sd-ai-agent-cr-msg-system-action"
						href={ actionUrl }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ presentation.actionText }
					</a>
				) }
			</div>
		</div>
	);
}
