/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

import useAudioRecorder from './use-audio-recorder';

/**
 * Whether the browser can capture audio for managed transcription.
 *
 * @type {boolean}
 */
export const isSpeechRecognitionSupported =
	typeof navigator !== 'undefined' &&
	!! navigator.mediaDevices?.getUserMedia &&
	typeof MediaRecorder !== 'undefined';

/**
 * Record one user-initiated turn and send it to the authenticated speech API.
 *
 * @param {Object}   options          Configuration.
 * @param {string}   options.lang     Initial BCP-47 language hint.
 * @param {Function} options.onResult Called once with final text and language.
 * @param {Function} options.onEnd    Called after a completed or failed turn.
 * @return {Object} Speech recording state and controls.
 */
export default function useSpeechRecognition( {
	lang = '',
	onResult,
	onEnd,
} = {} ) {
	const [ isListening, setIsListening ] = useState( false );
	const [ transcript, setTranscript ] = useState( '' );
	const [ error, setError ] = useState( null );
	const [ capabilities, setCapabilities ] = useState( null );
	const requestRef = useRef( null );
	const generationRef = useRef( 0 );

	const transcribe = useCallback(
		async ( { blob, mimeType } ) => {
			const generation = generationRef.current;
			const controller = new AbortController();
			requestRef.current = controller;
			const body = new FormData();
			body.append(
				'audio',
				blob,
				`recording.${ mimeType.split( '/' )[ 1 ] }`
			);
			if ( lang ) {
				body.append( 'language', lang );
			}

			try {
				const result = await apiFetch( {
					body,
					method: 'POST',
					path: '/sd-ai-agent/v1/speech/transcriptions',
					signal: controller.signal,
				} );
				if ( generation !== generationRef.current ) {
					return;
				}
				const finalTranscript = result?.text?.trim();
				if ( finalTranscript ) {
					setTranscript( finalTranscript );
					onResult?.( finalTranscript, result.language || '' );
				}
			} catch ( caughtError ) {
				if (
					caughtError?.name !== 'AbortError' &&
					generation === generationRef.current
				) {
					setError(
						caughtError?.message ||
							'Unable to transcribe the recording.'
					);
				}
			} finally {
				if ( generation === generationRef.current ) {
					setIsListening( false );
					onEnd?.();
				}
			}
		},
		[ lang, onEnd, onResult ]
	);

	const {
		cancel,
		error: recorderError,
		start,
		status,
		stop,
	} = useAudioRecorder( {
		acceptedMimeTypes: capabilities?.accepted_mime_types || [],
		maxBytes: capabilities?.max_recording_bytes || 0,
		maxDurationMs: capabilities?.max_recording_duration_ms || 0,
		onComplete: transcribe,
	} );

	useEffect( () => {
		return () => {
			generationRef.current += 1;
			requestRef.current?.abort();
			cancel();
		};
	}, [ cancel ] );

	const startListening = useCallback( async () => {
		if ( ! isSpeechRecognitionSupported || isListening ) {
			return;
		}
		setError( null );
		setTranscript( '' );
		try {
			const nextCapabilities =
				capabilities ||
				( await apiFetch( {
					path: '/sd-ai-agent/v1/speech/capabilities',
				} ) );
			setCapabilities( nextCapabilities );
			setIsListening( true );
			start( {
				acceptedMimeTypes: nextCapabilities.accepted_mime_types || [],
				maxBytes: nextCapabilities.max_recording_bytes || 0,
				maxDurationMs: nextCapabilities.max_recording_duration_ms || 0,
			} );
		} catch ( caughtError ) {
			setError(
				caughtError?.message || 'Speech services are unavailable.'
			);
		}
	}, [ capabilities, isListening, start ] );

	const stopListening = useCallback( () => {
		stop();
		setIsListening( false );
	}, [ stop ] );

	const toggleListening = useCallback( () => {
		if ( isListening ) {
			stopListening();
			return;
		}
		startListening();
	}, [ isListening, startListening, stopListening ] );

	const resetTranscript = useCallback( () => setTranscript( '' ), [] );

	return {
		isListening,
		isSupported: isSpeechRecognitionSupported,
		transcript,
		error: error || recorderError,
		status,
		startListening,
		stopListening,
		toggleListening,
		resetTranscript,
	};
}
