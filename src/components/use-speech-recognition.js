/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

import { loadSpeechCapabilities, recordingToWav } from '../utils/speech';
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
 * @param {Object}   options            Configuration.
 * @param {string}   options.lang       Initial BCP-47 language hint.
 * @param {string}   options.sessionKey Active conversation identity.
 * @param {Function} options.onResult   Called once with final text and language.
 * @param {Function} options.onEnd      Called after a completed or failed turn.
 * @return {Object} Speech recording state and controls.
 */
export default function useSpeechRecognition( {
	lang = '',
	sessionKey = '',
	onResult,
	onEnd,
} = {} ) {
	const [ transcript, setTranscript ] = useState( '' );
	const [ error, setError ] = useState( null );
	const [ capabilities, setCapabilities ] = useState( null );
	const [ detectedLanguage, setDetectedLanguage ] = useState( '' );
	const [ isLoadingCapabilities, setIsLoadingCapabilities ] =
		useState( false );
	const [ isTranscribing, setIsTranscribing ] = useState( false );
	const capabilitiesRef = useRef( null );
	const detectedLanguageRef = useRef( '' );
	const requestRef = useRef( null );
	const generationRef = useRef( 0 );
	const sessionRef = useRef( sessionKey );

	const transcribe = useCallback(
		async ( { blob } ) => {
			const generation = generationRef.current;
			const controller = new AbortController();
			requestRef.current = controller;
			setIsTranscribing( true );

			try {
				const currentCapabilities = capabilitiesRef.current;
				const transcription = currentCapabilities?.transcription;
				const wav = await recordingToWav(
					blob,
					transcription?.max_bytes || 0
				);
				if ( generation !== generationRef.current ) {
					return;
				}
				const body = new FormData();
				body.append( 'audio', wav, 'recording.wav' );
				const language =
					detectedLanguageRef.current ||
					lang ||
					currentCapabilities?.locales?.initial_locale ||
					( typeof navigator !== 'undefined'
						? navigator.language
						: '' );
				if ( language ) {
					body.append( 'language', language );
				}

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
					if ( result.language ) {
						detectedLanguageRef.current = result.language;
						setDetectedLanguage( result.language );
					}
					onResult?.( finalTranscript, result.language || language );
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
					requestRef.current = null;
					setIsTranscribing( false );
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
		status: recorderStatus,
		stop,
	} = useAudioRecorder( {
		acceptedMimeTypes:
			capabilities?.transcription?.accepted_input_mime_types || [],
		maxBytes: capabilities?.transcription?.max_bytes || 0,
		maxDurationMs:
			( capabilities?.transcription?.max_duration_seconds || 0 ) * 1000,
		onComplete: transcribe,
	} );

	useEffect( () => {
		return () => {
			generationRef.current += 1;
			requestRef.current?.abort();
			cancel();
		};
	}, [ cancel ] );

	const cancelListening = useCallback( () => {
		generationRef.current += 1;
		requestRef.current?.abort();
		requestRef.current = null;
		cancel();
		setIsLoadingCapabilities( false );
		setIsTranscribing( false );
	}, [ cancel ] );

	useEffect( () => {
		if ( sessionRef.current === sessionKey ) {
			return;
		}
		sessionRef.current = sessionKey;
		cancelListening();
		detectedLanguageRef.current = '';
		setDetectedLanguage( '' );
		setTranscript( '' );
		setError( null );
	}, [ cancelListening, sessionKey ] );

	const startListening = useCallback( async () => {
		if (
			! isSpeechRecognitionSupported ||
			isLoadingCapabilities ||
			isTranscribing ||
			[ 'requesting_permission', 'recording', 'stopping' ].includes(
				recorderStatus
			)
		) {
			return false;
		}
		const generation = generationRef.current + 1;
		generationRef.current = generation;
		requestRef.current?.abort();
		setError( null );
		setTranscript( '' );
		setIsLoadingCapabilities( true );
		try {
			const nextCapabilities =
				capabilities || ( await loadSpeechCapabilities() );
			if ( generation !== generationRef.current ) {
				return false;
			}
			capabilitiesRef.current = nextCapabilities;
			setCapabilities( nextCapabilities );
			const transcription = nextCapabilities.transcription;
			const wavDurationLimit =
				transcription.max_bytes > 44
					? Math.floor( ( transcription.max_bytes - 44 ) / 32 )
					: 0;
			return await start( {
				acceptedMimeTypes:
					transcription.accepted_input_mime_types || [],
				maxBytes: transcription.max_bytes || 0,
				maxDurationMs: Math.min(
					( transcription.max_duration_seconds || 0 ) * 1000,
					wavDurationLimit
				),
			} );
		} catch ( caughtError ) {
			if ( generation === generationRef.current ) {
				setError(
					caughtError?.message || 'Speech services are unavailable.'
				);
			}
			return false;
		} finally {
			if ( generation === generationRef.current ) {
				setIsLoadingCapabilities( false );
			}
		}
	}, [
		capabilities,
		isLoadingCapabilities,
		isTranscribing,
		recorderStatus,
		start,
	] );

	const stopListening = useCallback( () => {
		stop();
	}, [ stop ] );

	const toggleListening = useCallback( () => {
		if (
			[ 'requesting_permission', 'recording', 'stopping' ].includes(
				recorderStatus
			)
		) {
			stopListening();
			return;
		}
		startListening();
	}, [ recorderStatus, startListening, stopListening ] );

	const resetTranscript = useCallback( () => setTranscript( '' ), [] );
	let status = recorderStatus;
	if ( isLoadingCapabilities ) {
		status = 'loading_capabilities';
	}
	if ( isTranscribing ) {
		status = 'transcribing';
	}
	const isListening = [
		'requesting_permission',
		'recording',
		'stopping',
	].includes( recorderStatus );

	return {
		capabilities,
		cancelListening,
		detectedLanguage,
		isListening,
		isTranscribing,
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
