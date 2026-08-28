import { __ } from '@wordpress/i18n';
import { executeClientAbility } from '../../abilities/registry';

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

	return Promise.all(
		pendingClientToolCalls.map(
			async ( {
				id,
				name,
				client_name: clientName,
				args = {},
				annotations,
				user_confirmed: userConfirmed,
			} ) => {
				const abilityName = clientName || name;
				const timeoutMs =
					abilityName === 'sd-ai-agent-js/validate-page-quality'
						? 120000
						: 30000;

				if (
					annotations?.readonly !== true &&
					userConfirmed !== true
				) {
					return {
						id,
						name,
						error: __(
							'Confirmation required.',
							'superdav-ai-agent'
						),
					};
				}

				try {
					await readiness;
					const abilityResult = await withTimeout(
						executeClientAbility( abilityName, args ),
						timeoutMs,
						`Client tool timed out after ${
							timeoutMs / 1000
						} seconds.`
					);
					return { id, name, result: abilityResult };
				} catch ( execErr ) {
					return {
						id,
						name,
						error: String(
							execErr?.message || execErr || Error.name
						),
					};
				}
			}
		)
	);
}
