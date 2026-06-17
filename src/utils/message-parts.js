/**
 * Helpers for serialized AI Client message parts.
 *
 * Missing channel means content in the PHP AI Client SDK. Explicit thought (or
 * any future non-content channel) is hidden from display, copy, speech, exports,
 * and local transcript summaries.
 */

/**
 * Whether a serialized message part belongs to the visible content channel.
 *
 * @param {Object} part Serialized message part.
 * @return {boolean} True when the part is visible user-facing content.
 */
export function isContentPart( part ) {
	const channel = part?.channel;
	return (
		channel === undefined ||
		channel === null ||
		channel === '' ||
		channel === 'content'
	);
}

/**
 * Whether a serialized message part has visible text.
 *
 * @param {Object} part Serialized message part.
 * @return {boolean} True when the part has visible text.
 */
export function isVisibleTextPart( part ) {
	return (
		typeof part?.text === 'string' &&
		part.text !== '' &&
		isContentPart( part )
	);
}

/**
 * Extract visible text from a serialized message.
 *
 * @param {Object} message Serialized message.
 * @return {string} Concatenated visible text.
 */
export function extractMessageText( message ) {
	if ( ! message?.parts?.length ) {
		return '';
	}

	return message.parts
		.filter( isVisibleTextPart )
		.map( ( part ) => part.text )
		.join( '' );
}
