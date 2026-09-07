import { __ } from '@wordpress/i18n';
import { executeClientAbility } from '../../abilities/registry';

const SCREENSHOT_URL_ABILITY = 'sd-ai-agent-js/screenshot-url';
const SCREENSHOT_URL_CONCURRENCY = 1;
// This covers the 60-second iframe navigation window, render settling, and capture.
const SCREENSHOT_URL_TIMEOUT = 120000;

/**
 * Reject a promise after a bounded interval and clear the timer on settlement.
 *
 * @param {Promise|*} promise   Value or promise to await.
 * @param {number}    timeoutMs Maximum wait in milliseconds.
 * @param {string}    message   Timeout error message.
 * @return {Promise<*>} The bounded promise.
 */
function withTimeout( promise, timeoutMs, message ) {
	let timeoutId;
	return Promise.race( [
		Promise.resolve( promise ).finally( () => clearTimeout( timeoutId ) ),
		new Promise( ( _resolve, reject ) => {
			timeoutId = setTimeout( reject, timeoutMs, new Error( message ) );
		} ),
	] );
}

/**
 * Serialize a bounded subset of client tools while leaving unrelated browser
 * abilities free to run in parallel.
 *
 * @param {number} concurrency Maximum active tasks.
 * @return {Function} Function that queues a task and returns its result.
 */
function createConcurrencyLimiter( concurrency ) {
	let active = 0;
	const queue = [];

	const runNext = () => {
		if ( active >= concurrency || queue.length === 0 ) {
			return;
		}

		const { task, resolve, reject } = queue.shift();
		active++;
		Promise.resolve()
			.then( task )
			.then( resolve, reject )
			.finally( () => {
				active--;
				runNext();
			} );
	};

	return ( task ) =>
		new Promise( ( resolve, reject ) => {
			queue.push( { task, resolve, reject } );
			runNext();
		} );
}

// The limiter is module-scoped so concurrent polling batches share one slot.
const runScreenshotUrl = createConcurrencyLimiter( SCREENSHOT_URL_CONCURRENCY );

/**
 * Run a server-approved batch of browser abilities with bounded readiness and
 * per-ability execution windows.
 *
 * @param {Array<Object>} pendingClientToolCalls Pending browser calls.
 * @return {Promise<Array<Object>>} Serializable tool results.
 */
export async function runClientTools( pendingClientToolCalls ) {
	const readiness = pendingClientToolCalls.some(
		( { annotations, user_confirmed: userConfirmed } ) =>
			annotations?.readonly === true || userConfirmed === true
	)
		? withTimeout(
				window.__sdAiAgentAbilitiesRegistering,
				30000,
				'Client ability registration timed out after 30 seconds.'
		  )
		: null;

	const runClientTool = async ( {
		id,
		name,
		client_name: clientName,
		args = {},
		annotations,
		user_confirmed: userConfirmed,
	} ) => {
		const abilityName = clientName || name;
		let timeoutMs = 30000;
		if ( abilityName === SCREENSHOT_URL_ABILITY ) {
			timeoutMs = SCREENSHOT_URL_TIMEOUT;
		} else if ( abilityName === 'sd-ai-agent-js/validate-page-quality' ) {
			timeoutMs = 120000;
		}

		if ( annotations?.readonly !== true && userConfirmed !== true ) {
			return {
				id,
				name,
				error: __( 'Confirmation required.', 'superdav-ai-agent' ),
			};
		}

		try {
			await readiness;
			const abilityResult = await withTimeout(
				executeClientAbility( abilityName, args ),
				timeoutMs,
				`Client tool timed out after ${ timeoutMs / 1000 } seconds.`
			);
			return { id, name, result: abilityResult };
		} catch ( execErr ) {
			return {
				id,
				name,
				error: String( execErr?.message || execErr || Error.name ),
			};
		}
	};

	return Promise.all(
		pendingClientToolCalls.map( ( call ) => {
			const abilityName = call.client_name || call.name;
			const task = () => runClientTool( call );
			return abilityName === SCREENSHOT_URL_ABILITY
				? runScreenshotUrl( task )
				: task();
		} )
	);
}
