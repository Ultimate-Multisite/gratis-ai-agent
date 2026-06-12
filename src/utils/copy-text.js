/**
 * Copies text to the clipboard.
 *
 * navigator.clipboard (Clipboard API) is only available in secure contexts
 * (HTTPS or localhost). On plain HTTP origins the object is undefined and
 * calling .writeText() throws a TypeError. This helper tries the modern API
 * first and falls back to the legacy document.execCommand path so copy
 * buttons work during local HTTP development as well as in production.
 *
 * @param {string} text Text to copy.
 * @return {Promise<void>} Resolves when the copy succeeds, rejects on failure.
 */
export async function copyToClipboard( text ) {
	if ( navigator.clipboard ) {
		await navigator.clipboard.writeText( text );
		return;
	}

	// Fallback for non-secure contexts (HTTP, e.g. http://wordpress.local).
	const el = document.createElement( 'textarea' );
	el.value = text;
	el.style.cssText = 'position:fixed;top:-9999px;left:-9999px';
	el.readOnly = true;
	document.body.appendChild( el );
	el.focus();
	el.select();
	const ok = document.execCommand( 'copy' );
	document.body.removeChild( el );
	if ( ! ok ) {
		throw new Error( 'execCommand copy failed' );
	}
}
