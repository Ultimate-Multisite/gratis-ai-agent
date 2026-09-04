/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

export {
	base64ToBlob,
	BROWSER_RECORDING_MIME_TYPES,
	chunkSpeechText,
	encodeAudioBufferToWav,
	isBrowserSpeechSynthesisSupported,
	isManagedAudioPlaybackSupported,
	recordingToWav,
	selectRecordingMimeType,
	toSpeakableText,
} from './speech-core';

let capabilitiesRequest = null;

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
