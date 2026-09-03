/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

let capabilitiesRequest = null;

export const isManagedAudioPlaybackSupported =
	typeof Audio !== 'undefined' &&
	typeof URL !== 'undefined' &&
	typeof URL.createObjectURL === 'function';

export const isBrowserSpeechSynthesisSupported =
	typeof globalThis.speechSynthesis !== 'undefined' &&
	typeof globalThis.SpeechSynthesisUtterance !== 'undefined';

/**
 * Load the authenticated speech contract once for the current page lifecycle.
 *
 * @param {Object}  options       Request options.
 * @param {boolean} options.force Force a fresh capability request.
 * @return {Promise<Object>} Validated speech capabilities.
 */
export const loadSpeechCapabilities = async ( { force = false } = {} ) => {
	if ( force || ! capabilitiesRequest ) {
		capabilitiesRequest = apiFetch( {
			path: '/sd-ai-agent/v1/speech/capabilities',
		} ).catch( ( error ) => {
			capabilitiesRequest = null;
			throw error;
		} );
	}

	const capabilities = await capabilitiesRequest;
	if (
		! capabilities?.available ||
		! capabilities.transcription ||
		! capabilities.text_to_speech
	) {
		throw new Error( 'Speech services are unavailable.' );
	}

	return capabilities;
};

/** Clear page-local capability state, primarily after authentication changes. */
export const resetSpeechCapabilities = () => {
	capabilitiesRequest = null;
};

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
	if ( ! Number.isFinite( limit ) || limit < 1 ) {
		return [];
	}
	const characters = Array.from( text );
	const chunks = [];
	let remaining = characters;
	while ( remaining.length ) {
		let end = Math.min( limit, remaining.length );
		if ( end < remaining.length ) {
			for ( let index = end - 2; index > 0; index-- ) {
				if (
					/[.!?]/.test( remaining[ index ] ) &&
					/\s/.test( remaining[ index + 1 ] )
				) {
					end = index + 2;
					break;
				}
			}
		}
		chunks.push( remaining.slice( 0, end ).join( '' ).trim() );
		remaining = remaining.slice( end );
	}
	return chunks.filter( Boolean );
};

/**
 * Decode a managed inline-audio response without persisting it.
 *
 * @param {string} value    Base64-encoded audio.
 * @param {string} mimeType Validated response MIME type.
 * @return {Blob} Temporary audio payload.
 */
export const base64ToBlob = ( value, mimeType ) => {
	if ( typeof value !== 'string' || ! value ) {
		throw new Error( 'The speech service returned invalid audio.' );
	}
	const decoded = globalThis.atob( value );
	const bytes = new Uint8Array( decoded.length );
	for ( let index = 0; index < decoded.length; index++ ) {
		bytes[ index ] = decoded.charCodeAt( index );
	}
	return new Blob( [ bytes ], { type: mimeType } );
};

const writeAscii = ( view, offset, value ) => {
	for ( let index = 0; index < value.length; index++ ) {
		view.setUint8( offset + index, value.charCodeAt( index ) );
	}
};

/**
 * Encode decoded browser audio as bounded 16 kHz mono PCM WAV.
 *
 * @param {AudioBuffer} audioBuffer Decoded browser audio.
 * @param {number}      maxBytes    Maximum encoded size.
 * @return {Blob} PCM WAV recording.
 */
export const encodeAudioBufferToWav = ( audioBuffer, maxBytes = 0 ) => {
	const targetRate = Math.min( 16000, audioBuffer.sampleRate );
	const frameCount = Math.max(
		1,
		Math.floor( audioBuffer.duration * targetRate )
	);
	const byteLength = 44 + frameCount * 2;
	if ( maxBytes > 0 && byteLength > maxBytes ) {
		throw new Error( 'The recording reached the service limit.' );
	}

	const output = new ArrayBuffer( byteLength );
	const view = new DataView( output );
	writeAscii( view, 0, 'RIFF' );
	view.setUint32( 4, byteLength - 8, true );
	writeAscii( view, 8, 'WAVE' );
	writeAscii( view, 12, 'fmt ' );
	view.setUint32( 16, 16, true );
	view.setUint16( 20, 1, true );
	view.setUint16( 22, 1, true );
	view.setUint32( 24, targetRate, true );
	view.setUint32( 28, targetRate * 2, true );
	view.setUint16( 32, 2, true );
	view.setUint16( 34, 16, true );
	writeAscii( view, 36, 'data' );
	view.setUint32( 40, frameCount * 2, true );

	const channels = Array.from(
		{ length: audioBuffer.numberOfChannels },
		( _, channel ) => audioBuffer.getChannelData( channel )
	);
	const sourceRate = audioBuffer.sampleRate;
	for ( let frame = 0; frame < frameCount; frame++ ) {
		const sourcePosition = ( frame * sourceRate ) / targetRate;
		const lower = Math.min(
			audioBuffer.length - 1,
			Math.floor( sourcePosition )
		);
		const upper = Math.min( audioBuffer.length - 1, lower + 1 );
		const fraction = sourcePosition - lower;
		let sample = 0;
		channels.forEach( ( channel ) => {
			sample +=
				channel[ lower ] +
				( channel[ upper ] - channel[ lower ] ) * fraction;
		} );
		sample = Math.max( -1, Math.min( 1, sample / channels.length ) );
		view.setInt16(
			44 + frame * 2,
			sample < 0 ? sample * 0x8000 : sample * 0x7fff,
			true
		);
	}

	return new Blob( [ output ], { type: 'audio/wav' } );
};

/**
 * Convert a browser MediaRecorder payload to the service's strict WAV format.
 *
 * @param {Blob}   recording Recording captured by MediaRecorder.
 * @param {number} maxBytes  Maximum encoded request size.
 * @return {Promise<Blob>} Strict PCM WAV payload.
 */
export const recordingToWav = async ( recording, maxBytes = 0 ) => {
	const AudioContextClass =
		globalThis.AudioContext || globalThis.webkitAudioContext;
	if ( ! AudioContextClass ) {
		throw new Error(
			'This browser cannot prepare audio for transcription.'
		);
	}

	const context = new AudioContextClass();
	try {
		const decoded = await context.decodeAudioData(
			await recording.arrayBuffer()
		);
		return encodeAudioBufferToWav( decoded, maxBytes );
	} finally {
		await context.close?.();
	}
};
