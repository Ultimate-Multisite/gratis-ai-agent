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
 * Ordered fallback copy for work that has not yet produced a concrete tool
 * status. The first entries keep the status clear and precise; later entries
 * become deliberately more playful for longer-running requests.
 */
export const RUNNING_STATUS_MESSAGES = [
	__( 'Thinking…', 'superdav-ai-agent' ),
	__( 'Working…', 'superdav-ai-agent' ),
	__( 'Analyzing…', 'superdav-ai-agent' ),
	__( 'Reviewing…', 'superdav-ai-agent' ),
	__( 'Checking…', 'superdav-ai-agent' ),
	__( 'Planning…', 'superdav-ai-agent' ),
	__( 'Calculating…', 'superdav-ai-agent' ),
	__( 'Organizing…', 'superdav-ai-agent' ),
	__( 'Processing…', 'superdav-ai-agent' ),
	__( 'Preparing…', 'superdav-ai-agent' ),
	__( 'Drafting…', 'superdav-ai-agent' ),
	__( 'Researching…', 'superdav-ai-agent' ),
	__( 'Comparing…', 'superdav-ai-agent' ),
	__( 'Validating…', 'superdav-ai-agent' ),
	__( 'Refining…', 'superdav-ai-agent' ),
	__( 'Synthesizing…', 'superdav-ai-agent' ),
	__( 'Evaluating…', 'superdav-ai-agent' ),
	__( 'Mapping…', 'superdav-ai-agent' ),
	__( 'Parsing…', 'superdav-ai-agent' ),
	__( 'Reasoning…', 'superdav-ai-agent' ),
	__( 'Generating…', 'superdav-ai-agent' ),
	__( 'Creating…', 'superdav-ai-agent' ),
	__( 'Shaping…', 'superdav-ai-agent' ),
	__( 'Assembling…', 'superdav-ai-agent' ),
	__( 'Building…', 'superdav-ai-agent' ),
	__( 'Composing…', 'superdav-ai-agent' ),
	__( 'Formulating…', 'superdav-ai-agent' ),
	__( 'Connecting…', 'superdav-ai-agent' ),
	__( 'Exploring…', 'superdav-ai-agent' ),
	__( 'Balancing…', 'superdav-ai-agent' ),
	__( 'Polishing…', 'superdav-ai-agent' ),
	__( 'Iterating…', 'superdav-ai-agent' ),
	__( 'Arranging…', 'superdav-ai-agent' ),
	__( 'Structuring…', 'superdav-ai-agent' ),
	__( 'Reframing…', 'superdav-ai-agent' ),
	__( 'Translating…', 'superdav-ai-agent' ),
	__( 'Transforming…', 'superdav-ai-agent' ),
	__( 'Transposing…', 'superdav-ai-agent' ),
	__( 'Tuning…', 'superdav-ai-agent' ),
	__( 'Aligning…', 'superdav-ai-agent' ),
	__( 'Calibrating…', 'superdav-ai-agent' ),
	__( 'Harmonizing…', 'superdav-ai-agent' ),
	__( 'Sketching…', 'superdav-ai-agent' ),
	__( 'Brainstorming…', 'superdav-ai-agent' ),
	__( 'Weaving…', 'superdav-ai-agent' ),
	__( 'Juggling…', 'superdav-ai-agent' ),
	__( 'Wrangling…', 'superdav-ai-agent' ),
	__( 'Untangling…', 'superdav-ai-agent' ),
	__( 'Noodling…', 'superdav-ai-agent' ),
	__( 'Pondering…', 'superdav-ai-agent' ),
	__( 'Ruminating…', 'superdav-ai-agent' ),
	__( 'Conjuring…', 'superdav-ai-agent' ),
	__( 'Brewing…', 'superdav-ai-agent' ),
	__( 'Cooking…', 'superdav-ai-agent' ),
	__( 'Stirring…', 'superdav-ai-agent' ),
	__( 'Whisking…', 'superdav-ai-agent' ),
	__( 'Kneading…', 'superdav-ai-agent' ),
	__( 'Sifting…', 'superdav-ai-agent' ),
	__( 'Spinning…', 'superdav-ai-agent' ),
	__( 'Twirling…', 'superdav-ai-agent' ),
	__( 'Warming…', 'superdav-ai-agent' ),
	__( 'Sparking…', 'superdav-ai-agent' ),
	__( 'Igniting…', 'superdav-ai-agent' ),
	__( 'Whirring…', 'superdav-ai-agent' ),
	__( 'Humming…', 'superdav-ai-agent' ),
	__( 'Buzzing…', 'superdav-ai-agent' ),
	__( 'Percolating…', 'superdav-ai-agent' ),
	__( 'Marinating…', 'superdav-ai-agent' ),
	__( 'Fermenting…', 'superdav-ai-agent' ),
	__( 'Moonwalking…', 'superdav-ai-agent' ),
	__( 'Cartwheeling…', 'superdav-ai-agent' ),
	__( 'Somersaulting…', 'superdav-ai-agent' ),
	__( 'Gyrating…', 'superdav-ai-agent' ),
	__( 'Orbiting…', 'superdav-ai-agent' ),
	__( 'Time-traveling…', 'superdav-ai-agent' ),
	__( 'Teleporting…', 'superdav-ai-agent' ),
	__( 'Shape-shifting…', 'superdav-ai-agent' ),
	__( 'Transmogrifying…', 'superdav-ai-agent' ),
	__( 'Discombobulating…', 'superdav-ai-agent' ),
	__( 'Bamboozling…', 'superdav-ai-agent' ),
	__( 'Galvanizing…', 'superdav-ai-agent' ),
	__( 'Pixelating…', 'superdav-ai-agent' ),
	__( 'Jazz-handing…', 'superdav-ai-agent' ),
	__( 'Spellcasting…', 'superdav-ai-agent' ),
	__( 'Wizarding…', 'superdav-ai-agent' ),
	__( 'Alchemizing…', 'superdav-ai-agent' ),
	__( 'Enchanting…', 'superdav-ai-agent' ),
	__( 'Bewitching…', 'superdav-ai-agent' ),
	__( 'Sparkling…', 'superdav-ai-agent' ),
	__( 'Glittering…', 'superdav-ai-agent' ),
	__( 'Swooshing…', 'superdav-ai-agent' ),
	__( 'Zooming…', 'superdav-ai-agent' ),
	__( 'Scooting…', 'superdav-ai-agent' ),
	__( 'Shimmying…', 'superdav-ai-agent' ),
	__( 'Boogieing…', 'superdav-ai-agent' ),
	__( 'Grooving…', 'superdav-ai-agent' ),
	__( 'Vibing…', 'superdav-ai-agent' ),
	__( 'High-fiving…', 'superdav-ai-agent' ),
	__( 'Finessing…', 'superdav-ai-agent' ),
	__( 'Finagling…', 'superdav-ai-agent' ),
];

/**
 * Return a rotation status for an arbitrary counter value.
 *
 * @param {number} index Rotation counter.
 * @return {string} Translated user-facing status.
 */
export function getRunningStatusMessage( index ) {
	return RUNNING_STATUS_MESSAGES[ index % RUNNING_STATUS_MESSAGES.length ];
}

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
 * @return {{isFallback: boolean, toolCalls: Array, step: string}} Running presentation.
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
		isFallback: ! runningToolName,
		step: runningToolName
			? `${ getFriendlyToolLabel( runningToolName ) }…`
			: getRunningStatusMessage( 0 ),
	};
}
