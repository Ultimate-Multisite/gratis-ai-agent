/**
 * Chat UI mode helpers.
 *
 * Customer/public docs embeds need the ask/answer chat surface without the
 * admin controls for switching sessions, agents, models, or tool profiles.
 */

const DEFAULT_CHAT_UI_MODE = 'admin';

const SIMPLE_MODE_ALIASES = new Set( [
	'customer_simple',
	'public_docs',
	'public_simple',
	'public_chat',
	'customer',
	'simple',
] );

/**
 * Normalize a chat UI mode value.
 *
 * @param {*} value Candidate mode value.
 * @return {string} Normalized mode string.
 */
function normalizeMode( value ) {
	return String( value || '' )
		.trim()
		.toLowerCase()
		.replace( /-/g, '_' );
}

/**
 * Resolve the configured chat UI mode from localized/embed data.
 *
 * @param {Object} [data] Localized/embed configuration. Defaults to window.sdAiAgentData.
 * @return {string} Normalized chat UI mode.
 */
export function getChatUiMode( data = undefined ) {
	const source =
		data ||
		( typeof window !== 'undefined' ? window.sdAiAgentData || {} : {} );
	const mode = normalizeMode(
		source.chatUiMode ||
			source.chat_ui_mode ||
			source.uiMode ||
			source.ui_mode ||
			source.mode ||
			source.embed?.chatUiMode ||
			source.embed?.uiMode
	);

	if ( mode ) {
		return mode;
	}

	if ( source.publicChat === true || source.public_chat === true ) {
		return 'public_chat';
	}

	return DEFAULT_CHAT_UI_MODE;
}

/**
 * Check whether a resolved UI mode should render the simplified customer UI.
 *
 * @param {string|Object} modeOrData Normalized mode string or localized/embed data object.
 * @return {boolean} True when admin controls should be hidden.
 */
export function isCustomerSimpleMode( modeOrData ) {
	const mode =
		typeof modeOrData === 'string'
			? normalizeMode( modeOrData )
			: getChatUiMode( modeOrData );

	return SIMPLE_MODE_ALIASES.has( mode );
}
