/**
 * Shared account-action message rendered by every React chat surface.
 */

import { createInterpolateElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
	CREDIT_EXHAUSTED_REASON,
	PURCHASE_CREDITS_ACTION,
} from '../../utils/superdav-credit-notice';

/**
 * Resolve translated presentation copy for a semantic account action.
 *
 * @param {Object} notice Structured account-action notice.
 * @return {{template: string, actionText: string, hasInlineLink: boolean}}
 *   Translated notice presentation.
 */
export function getAccountActionPresentation( notice ) {
	if (
		notice?.reason === CREDIT_EXHAUSTED_REASON &&
		notice?.action === PURCHASE_CREDITS_ACTION
	) {
		return {
			template: __(
				"You've used all of your available SD AI credits. Purchase more credits in your <link>account settings</link> to continue using Standard.",
				'superdav-ai-agent'
			),
			actionText: __( 'Purchase credits', 'superdav-ai-agent' ),
			hasInlineLink: true,
		};
	}

	return {
		template:
			typeof notice?.message === 'string'
				? notice.message
				: __(
						'Review your account settings to continue.',
						'superdav-ai-agent'
				  ),
		actionText: __( 'Account settings', 'superdav-ai-agent' ),
		hasInlineLink: false,
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
	const inlineElement = actionUrl ? (
		// The translated <link>…</link> content is supplied by createInterpolateElement.
		// eslint-disable-next-line jsx-a11y/anchor-has-content
		<a
			className="sd-ai-agent-cr-msg-system-inline-action"
			href={ actionUrl }
			target="_blank"
			rel="noopener noreferrer"
		/>
	) : (
		<span />
	);
	const message = presentation.hasInlineLink
		? createInterpolateElement( presentation.template, {
				link: inlineElement,
		  } )
		: presentation.template;

	return (
		<div className="sdaa-cr-msg-row">
			<div
				className="sd-ai-agent-cr-msg-system sd-ai-agent-cr-msg-system--account-action"
				role="status"
			>
				{ message }
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
