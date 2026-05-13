/**
 * UI slice — floating panel, alerts, page context,
 * text-to-speech, and send timestamp.
 */

import apiFetch from '@wordpress/api-fetch';

export const initialState = {
	floatingOpen: false,
	floatingMinimized: false,
	pageContext: '',

	sendTimestamp: 0,

	// Whether the keyboard shortcuts help overlay is visible.
	// Set to true when the /help slash command is selected or Mod+/ is pressed.
	showShortcutsHelp: false,

	// Proactive alerts — count of issues surfaced as a badge on the FAB.
	alertCount: 0,

	// Boot error — set when initial API calls persistently fail (e.g. 403).
	// Shows a friendly error screen instead of an infinite request loop.
	// { message: string, status: number } or null.
	bootError: null,

	// Bootstrap session flag (t223) — true when the current session is the
	// AI-driven auto-discovery run. Prevents the empty-state placeholder from
	// appearing while the agent is actively exploring the site.
	isBootstrapSession: false,

	// Text-to-speech (t084) — persisted to localStorage.
	ttsEnabled: localStorage.getItem( 'sdAiAgentTtsEnabled' ) === 'true',
	ttsVoiceURI: localStorage.getItem( 'sdAiAgentTtsVoiceURI' ) || '',
	ttsRate: parseFloat( localStorage.getItem( 'sdAiAgentTtsRate' ) || '1' ),
	ttsPitch: parseFloat( localStorage.getItem( 'sdAiAgentTtsPitch' ) || '1' ),

	// Sound notifications — persisted to localStorage.
	soundSuccessEnabled:
		localStorage.getItem( 'sdAiAgentSoundSuccess' ) === 'true',
	soundErrorEnabled: localStorage.getItem( 'sdAiAgentSoundError' ) === 'true',
	soundThinkingEnabled:
		localStorage.getItem( 'sdAiAgentSoundThinking' ) === 'true',
};

export const actions = {
	/**
	 * Open or close the floating panel.
	 *
	 * @param {boolean} open - Whether the panel should be open.
	 * @return {Object} Redux action.
	 */
	setFloatingOpen( open ) {
		return { type: 'SET_FLOATING_OPEN', open };
	},

	/**
	 * Minimize or expand the floating panel.
	 *
	 * @param {boolean} minimized - Whether the panel should be minimized.
	 * @return {Object} Redux action.
	 */
	setFloatingMinimized( minimized ) {
		return { type: 'SET_FLOATING_MINIMIZED', minimized };
	},

	/**
	 * Set structured page context for the AI.
	 *
	 * @param {string|Object} context - Page context object or string.
	 * @return {Object} Redux action.
	 */
	setPageContext( context ) {
		return { type: 'SET_PAGE_CONTEXT', context };
	},

	setAlertCount( count ) {
		return { type: 'SET_ALERT_COUNT', count };
	},

	/**
	 * Show or hide the keyboard shortcuts help overlay.
	 *
	 * Set to true when the /help slash command is selected; set to false when
	 * the overlay is closed. Also toggled by the Mod+/ keyboard shortcut.
	 *
	 * @param {boolean} show - Whether to show the overlay.
	 * @return {Object} Redux action.
	 */
	setShowShortcutsHelp( show ) {
		return { type: 'SET_SHOW_SHORTCUTS_HELP', show };
	},

	// ─── Text-to-speech (t084) ───────────────────────────────────

	/**
	 * Enable or disable text-to-speech and persist the choice to localStorage.
	 *
	 * @param {boolean} enabled - Whether TTS should be active.
	 * @return {Object} Redux action.
	 */
	setTtsEnabled( enabled ) {
		localStorage.setItem(
			'sdAiAgentTtsEnabled',
			enabled ? 'true' : 'false'
		);
		return { type: 'SET_TTS_ENABLED', enabled };
	},

	/**
	 * Set the TTS voice URI and persist to localStorage.
	 *
	 * @param {string} voiceURI - SpeechSynthesisVoice.voiceURI value.
	 * @return {Object} Redux action.
	 */
	setTtsVoiceURI( voiceURI ) {
		localStorage.setItem( 'sdAiAgentTtsVoiceURI', voiceURI );
		return { type: 'SET_TTS_VOICE_URI', voiceURI };
	},

	/**
	 * Set the TTS speech rate and persist to localStorage.
	 *
	 * @param {number} rate - Speech rate (0.1–10).
	 * @return {Object} Redux action.
	 */
	setTtsRate( rate ) {
		localStorage.setItem( 'sdAiAgentTtsRate', String( rate ) );
		return { type: 'SET_TTS_RATE', rate };
	},

	/**
	 * Set the TTS speech pitch and persist to localStorage.
	 *
	 * @param {number} pitch - Speech pitch (0–2).
	 * @return {Object} Redux action.
	 */
	setTtsPitch( pitch ) {
		localStorage.setItem( 'sdAiAgentTtsPitch', String( pitch ) );
		return { type: 'SET_TTS_PITCH', pitch };
	},

	// ─── Sound notifications ──────────────────────────────────────

	/**
	 * Enable or disable the success "ding" sound and persist to localStorage.
	 *
	 * @param {boolean} enabled - Whether the ding sound should play on success.
	 * @return {Object} Redux action.
	 */
	setSoundSuccessEnabled( enabled ) {
		localStorage.setItem(
			'sdAiAgentSoundSuccess',
			enabled ? 'true' : 'false'
		);
		return { type: 'SET_SOUND_SUCCESS_ENABLED', enabled };
	},

	/**
	 * Enable or disable the error "dong" sound and persist to localStorage.
	 *
	 * @param {boolean} enabled - Whether the dong sound should play on error.
	 * @return {Object} Redux action.
	 */
	setSoundErrorEnabled( enabled ) {
		localStorage.setItem(
			'sdAiAgentSoundError',
			enabled ? 'true' : 'false'
		);
		return { type: 'SET_SOUND_ERROR_ENABLED', enabled };
	},

	/**
	 * Enable or disable the thinking tick sound and persist to localStorage.
	 *
	 * @param {boolean} enabled - Whether the tick should play on each tool action.
	 * @return {Object} Redux action.
	 */
	setSoundThinkingEnabled( enabled ) {
		localStorage.setItem(
			'sdAiAgentSoundThinking',
			enabled ? 'true' : 'false'
		);
		return { type: 'SET_SOUND_THINKING_ENABLED', enabled };
	},

	/**
	 * Set or clear the bootstrap session flag (t223).
	 *
	 * Set to true when the current session is the AI-driven auto-discovery run
	 * so the UI can suppress the empty-state placeholder while the agent works.
	 *
	 * @param {boolean} isBootstrap - Whether the current session is a bootstrap session.
	 * @return {Object} Redux action.
	 */
	setBootstrapSession( isBootstrap ) {
		return { type: 'SET_BOOTSTRAP_SESSION', isBootstrap };
	},

	/**
	 * Set or clear the boot error state.
	 *
	 * @param {Object|null} error - Error object { message, status } or null to clear.
	 * @return {Object} Redux action.
	 */
	setBootError( error ) {
		return { type: 'SET_BOOT_ERROR', error };
	},

	/**
	 * Clear the boot error and re-attempt initial data fetches.
	 *
	 * Before retrying, refresh the REST nonce via wp.apiFetch's built-in
	 * nonce endpoint so that expired-nonce 403s resolve without a page reload.
	 *
	 * @return {Function} Redux thunk.
	 */
	retryBoot() {
		return async ( { dispatch } ) => {
			// Refresh nonce if the apiFetch middleware exposes one.
			// wp.apiFetch.nonceMiddleware.nonce holds the current token and
			// nonceEndpoint holds the URL to fetch a fresh one from.
			try {
				const nonceEndpoint = window.wp?.apiFetch?.nonceEndpoint;
				const nonceMiddleware = window.wp?.apiFetch?.nonceMiddleware;
				if ( nonceEndpoint && nonceMiddleware ) {
					const response = await window.fetch( nonceEndpoint );
					if ( response.ok ) {
						const newNonce = await response.text();
						nonceMiddleware.nonce = newNonce.trim();
					}
				}
			} catch {
				// Nonce refresh failure is non-fatal — the fetch attempts
				// below may still succeed if the session is still valid.
			}

			dispatch.setBootError( null );
			dispatch.fetchProviders();
			dispatch.fetchSessions();
			dispatch.fetchSettings();
		};
	},

	fetchAlerts() {
		return async ( { dispatch } ) => {
			try {
				const data = await apiFetch( {
					path: '/sd-ai-agent/v1/alerts',
				} );
				dispatch.setAlertCount( data.count || 0 );
			} catch {
				// Non-fatal — badge simply stays at 0 on error.
				dispatch.setAlertCount( 0 );
			}
		};
	},
};

export const selectors = {
	/**
	 * @param {import('../../types').StoreState} state
	 * @return {boolean} Whether the floating panel is open.
	 */
	isFloatingOpen( state ) {
		return state.floatingOpen;
	},

	/**
	 * @param {import('../../types').StoreState} state
	 * @return {boolean} Whether the floating panel is minimized.
	 */
	isFloatingMinimized( state ) {
		return state.floatingMinimized;
	},

	/**
	 * @param {import('../../types').StoreState} state
	 * @return {string|Object} Structured page context for the AI.
	 */
	getPageContext( state ) {
		return state.pageContext;
	},

	/**
	 * @param {import('../../types').StoreState} state
	 * @return {number} Timestamp of the last send in ms since epoch.
	 */
	getSendTimestamp( state ) {
		return state.sendTimestamp;
	},

	getAlertCount( state ) {
		return state.alertCount;
	},

	/**
	 * @param {import('../../types').StoreState} state
	 * @return {boolean} Whether the keyboard shortcuts help overlay is visible.
	 */
	isShowingShortcutsHelp( state ) {
		return state.showShortcutsHelp ?? false;
	},

	/**
	 * Whether the current session is the AI-driven bootstrap auto-discovery run (t223).
	 * Returns true while the agent is exploring the site on first activation.
	 *
	 * @param {import('../../types').StoreState} state
	 * @return {boolean} True when the active session is a bootstrap session.
	 */
	isBootstrapSession( state ) {
		return state.isBootstrapSession ?? false;
	},

	/**
	 * @param {import('../../types').StoreState} state
	 * @return {Object|null} Boot error { message, status } or null.
	 */
	getBootError( state ) {
		return state.bootError;
	},

	// Text-to-speech (t084)

	/**
	 * @param {import('../../types').StoreState} state
	 * @return {boolean} Whether text-to-speech is enabled.
	 */
	isTtsEnabled( state ) {
		return state.ttsEnabled;
	},

	/**
	 * @param {import('../../types').StoreState} state
	 * @return {string} Selected TTS voice URI (empty = browser default).
	 */
	getTtsVoiceURI( state ) {
		return state.ttsVoiceURI;
	},

	/**
	 * @param {import('../../types').StoreState} state
	 * @return {number} TTS speech rate.
	 */
	getTtsRate( state ) {
		return state.ttsRate;
	},

	/**
	 * @param {import('../../types').StoreState} state
	 * @return {number} TTS speech pitch.
	 */
	getTtsPitch( state ) {
		return state.ttsPitch;
	},

	// Sound notifications

	/**
	 * @param {import('../../types').StoreState} state
	 * @return {boolean} Whether the success "ding" sound is enabled.
	 */
	isSoundSuccessEnabled( state ) {
		return state.soundSuccessEnabled;
	},

	/**
	 * @param {import('../../types').StoreState} state
	 * @return {boolean} Whether the error "dong" sound is enabled.
	 */
	isSoundErrorEnabled( state ) {
		return state.soundErrorEnabled;
	},

	/**
	 * @param {import('../../types').StoreState} state
	 * @return {boolean} Whether the thinking tick sound is enabled.
	 */
	isSoundThinkingEnabled( state ) {
		return state.soundThinkingEnabled;
	},
};

/**
 * @param {import('../../types').StoreState} state  - Current state.
 * @param {Object}                           action - Dispatched action.
 * @return {import('../../types').StoreState} Next state.
 */
export function reducer( state, action ) {
	switch ( action.type ) {
		case 'SET_FLOATING_OPEN':
			return { ...state, floatingOpen: action.open };
		case 'SET_FLOATING_MINIMIZED':
			return { ...state, floatingMinimized: action.minimized };
		case 'SET_PAGE_CONTEXT':
			return { ...state, pageContext: action.context };
		case 'SET_ALERT_COUNT':
			return { ...state, alertCount: action.count };
		case 'SET_BOOTSTRAP_SESSION':
			return { ...state, isBootstrapSession: action.isBootstrap };
		case 'SET_BOOT_ERROR':
			return { ...state, bootError: action.error };
		case 'SET_TTS_ENABLED':
			return { ...state, ttsEnabled: action.enabled };
		case 'SET_TTS_VOICE_URI':
			return { ...state, ttsVoiceURI: action.voiceURI };
		case 'SET_TTS_RATE':
			return { ...state, ttsRate: action.rate };
		case 'SET_TTS_PITCH':
			return { ...state, ttsPitch: action.pitch };
		case 'SET_SOUND_SUCCESS_ENABLED':
			return { ...state, soundSuccessEnabled: action.enabled };
		case 'SET_SOUND_ERROR_ENABLED':
			return { ...state, soundErrorEnabled: action.enabled };
		case 'SET_SOUND_THINKING_ENABLED':
			return { ...state, soundThinkingEnabled: action.enabled };
		case 'SET_SHOW_SHORTCUTS_HELP':
			return { ...state, showShortcutsHelp: action.show };
		default:
			return state;
	}
}
