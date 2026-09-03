/**
 * Convert Markdown response content into bounded speech-ready text.
 *
 * @param {string} text Raw response content.
 * @return {string} Plain text without code payloads.
 */
export const toSpeakableText = ( text ) =>
	text
		.replace( /```[\s\S]*?```/g, '' )
		.replace( /`([^`]+)`/g, '$1' )
		.replace( /!\[[^\]]*\]\([^)]+\)/g, '' )
		.replace( /\[([^\]]+)\]\([^)]+\)/g, '$1' )
		.replace( /^[#>*\-+]\s*/gm, '' )
		.replace( /\*{1,3}([^*]+)\*{1,3}/g, '$1' )
		.replace( /\s+/g, ' ' )
		.trim();

/**
 * Split a string at sentence/word boundaries without splitting Unicode units.
 *
 * @param {string} text  Input text.
 * @param {number} limit Maximum code points per chunk.
 * @return {string[]} Bounded chunks.
 */
export const chunkSpeechText = ( text, limit ) => {
	const characters = Array.from( text );
	const chunks = [];
	let remaining = characters;
	while ( remaining.length ) {
		let end = Math.min( limit, remaining.length );
		if ( end < remaining.length ) {
			const boundary = remaining
				.slice( 0, end )
				.join( '' )
				.search( /[.!?]\s[^.!?]*$/ );
			if ( boundary > 0 ) {
				end = boundary + 2;
			}
		}
		chunks.push( remaining.slice( 0, end ).join( '' ).trim() );
		remaining = remaining.slice( end );
	}
	return chunks.filter( Boolean );
};
