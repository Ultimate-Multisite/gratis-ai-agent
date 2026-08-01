/**
 * Shared message-presentation helpers for the main and floating chats.
 */

import { __ } from '@wordpress/i18n';

import {
	buildSuperdavCreditNoticeMessage,
	isSuperdavCreditBalanceNotice,
} from '../../utils/superdav-credit-notice';
import {
	extractText,
	getFriendlyToolLabel,
	getRunningToolName,
} from '../chat-redesign/message-helpers';

/**
 * Filter messages that have visible chat content.
 *
 * @param {Array} messages Store messages.
 * @return {Array<{msg: Object, index: number}>} Visible messages with source indexes.
 */
export function getVisibleMessages( messages ) {
	const visible = [];
	for ( let index = 0; index < messages.length; index++ ) {
		const msg = messages[ index ];
		if ( msg.role === 'function' ) {
			continue;
		}
		if ( msg.role === 'model' ) {
			const text = extractText( msg );
			if ( ! text && ! msg.toolCalls?.length ) {
				continue;
			}
		}
		if ( msg.role === 'user' && ! extractText( msg ) ) {
			continue;
		}
		visible.push( { msg, index } );
	}
	return visible;
}

/**
 * Normalize a system message into one shared presentation model.
 *
 * Structured notices are authoritative. Text matching remains only as a
 * centralized compatibility path for messages created before diagnostics
 * carried a semantic credit-exhaustion reason.
 *
 * @param {Object} msg       System message.
 * @param {Array}  providers Provider records used to resolve account URLs.
 * @return {{type: 'account_action', notice: Object}|{type: 'system', text: string}}
 *   System-message presentation.
 */
export function resolveSystemMessagePresentation( msg, providers ) {
	if ( msg?.notice?.type === 'account_action' ) {
		return { type: 'account_action', notice: msg.notice };
	}

	const text = extractText( msg );
	if ( isSuperdavCreditBalanceNotice( text ) ) {
		return {
			type: 'account_action',
			notice: buildSuperdavCreditNoticeMessage( providers ).notice,
		};
	}

	return { type: 'system', text };
}

/**
 * Resolve the running-job rows and friendly status text shared by chat lists.
 *
 * @param {Object}      root0
 * @param {number|null} root0.currentSessionId Current session ID.
 * @param {Object}      root0.sessionJobs      Per-session job records.
 * @param {Array}       root0.liveToolCalls    Current live activity.
 * @return {{toolCalls: Array, step: string}} Running presentation.
 */
export function getRunningJobPresentation( {
	currentSessionId,
	sessionJobs,
	liveToolCalls,
} ) {
	const lastRunningJob = currentSessionId
		? sessionJobs[ currentSessionId ]
		: null;
	const toolCalls =
		lastRunningJob?.toolCalls?.length > 0
			? lastRunningJob.toolCalls
			: liveToolCalls;
	const runningToolName = getRunningToolName( toolCalls );

	return {
		toolCalls,
		step: runningToolName
			? `${ getFriendlyToolLabel( runningToolName ) }…`
			: __( 'Composing reply…', 'superdav-ai-agent' ),
	};
}
