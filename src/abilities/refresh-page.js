/**
 * Client-side refresh-page ability.
 *
 * Schedules a reload of the current browser page after the tool result has been posted back.
 * to the server. The job poller performs the actual reload so the in-flight
 * tool-result POST is not aborted. It also stores the active session/widget
 * state so the floating widget reopens to the same conversation after reload.
 */

import { registerClientAbility } from './registry';

/**
 * Execute the refresh-page ability.
 *
 * @return {{ refresh_scheduled: boolean, url: string }} Refresh scheduling result, not render evidence.
 */
function executeRefreshPage() {
	const url = window.location.href;
	window._sdAiAgentPendingPageRefresh = url;

	return {
		refresh_scheduled: true,
		url,
	};
}

/**
 * Register the refresh-page ability with the client-side abilities registry.
 *
 * @return {Promise<void>}
 */
export async function registerRefreshPageAbility() {
	await registerClientAbility( {
		name: 'sd-ai-agent-js/refresh-page',
		label: 'Refresh Current Page',
		description:
			'Schedule a refresh of the current browser page while preserving the open AI Agent widget and current session. Use after site changes that did not return an affected descriptor for live preview. This only schedules navigation and does not validate rendered output.',
		inputSchema: {
			type: 'object',
			properties: {},
			required: [],
		},
		outputSchema: {
			type: 'object',
			properties: {
				refresh_scheduled: { type: 'boolean' },
				url: { type: 'string' },
			},
		},
		annotations: { readonly: true },
		callback: executeRefreshPage,
	} );
}
