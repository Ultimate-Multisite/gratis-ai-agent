/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

import {
	base64ToBlob,
	chunkSpeechText,
	loadSpeechCapabilities,
	toSpeakableText,
} from '../utils/speech';

/** Whether managed audio playback is available in this browser. */
export const isTTSSupported =
	typeof Audio !== 'undefined' &&
	typeof URL !== 'undefined' &&
	typeof URL.createObjectURL === 'function';

/**
 * Load managed voices in the legacy selector shape used by settings.
 *
 * @return {Object[]} Managed voice options.
 */
export function useAvailableVoices() {
	const [ voices, setVoices ] = useState( [] );
	useEffect( () => {
		let active = true;
		loadSpeechCapabilities()
			.then( ( capabilities ) => {
				if ( ! active ) {
					return;
				}
				setVoices(
					capabilities.text_to_speech.voices.map( ( voice ) => ( {
						lang: voice.locales[ 0 ] || '',
						name: voice.name,
						voiceURI: voice.id,
					} ) )
				);
			} )
			.catch( () => {
				if ( active ) {
					setVoices( [] );
				}
			} );
		return () => {
			active = false;
		};
	}, [] );
	return voices;
}

/**
 * Speak text through the authenticated managed synthesis route.
 *
 * @param {Object}   options           Configuration.
 * @param {string}   options.lang      Initial BCP-47 language hint.
 * @param {Function} options.onEnd     Called after playback finishes.
 * @param {Function} options.onError   Called after synthesis/playback fails.
 * @param {Function} options.onStart   Called when managed playback starts.
 * @param {number}   options.rate      Requested playback speed.
 * @param {string}   options.sessionId Optional managed session identifier.
 * @param {string}   options.voice     Preferred managed voice identifier.
 * @param {string}   options.voiceURI  Legacy preferred voice identifier.
 * @return {Object} Managed speech controls and state.
 */
export default function useTextToSpeech( {
	lang = '',
	onEnd,
	onError,
	onStart,
	rate = 1,
	sessionId = '',
	voice = '',
	voiceURI = '',
} = {} ) {
	const [ capabilities, setCapabilities ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ isSpeaking, setIsSpeaking ] = useState( false );
	const abortRef = useRef( null );
	const audioRef = useRef( null );
	const generationRef = useRef( 0 );
	const objectUrlRef = useRef( '' );
	const playbackResolveRef = useRef( null );

	const releaseAudio = useCallback( ( settlePlayback = true ) => {
		if ( audioRef.current ) {
			audioRef.current.onended = null;
			audioRef.current.onerror = null;
			audioRef.current.pause();
			audioRef.current.removeAttribute( 'src' );
			audioRef.current = null;
		}
		if ( objectUrlRef.current ) {
			URL.revokeObjectURL( objectUrlRef.current );
			objectUrlRef.current = '';
		}
		if ( settlePlayback && playbackResolveRef.current ) {
			playbackResolveRef.current();
		}
		playbackResolveRef.current = null;
	}, [] );

	const cancel = useCallback( () => {
		generationRef.current += 1;
		abortRef.current?.abort();
		abortRef.current = null;
		releaseAudio();
		setIsSpeaking( false );
	}, [ releaseAudio ] );

	useEffect( () => cancel, [ cancel ] );

	const playBlob = useCallback(
		( blob, generation ) =>
			new Promise( ( resolve, reject ) => {
				if ( generation !== generationRef.current ) {
					resolve();
					return;
				}
				const objectUrl = URL.createObjectURL( blob );
				const audio = new Audio( objectUrl );
				playbackResolveRef.current = resolve;
				objectUrlRef.current = objectUrl;
				audioRef.current = audio;
				audio.onended = () => {
					releaseAudio( false );
					resolve();
				};
				audio.onerror = () => {
					releaseAudio( false );
					reject( new Error( 'Unable to play synthesized speech.' ) );
				};
				audio.play().catch( ( caughtError ) => {
					releaseAudio( false );
					reject( caughtError );
				} );
			} ),
		[ releaseAudio ]
	);

	const speak = useCallback(
		async ( text, turnOptions = {} ) => {
			const speechText = toSpeakableText( String( text || '' ) );
			if ( ! isTTSSupported || ! speechText ) {
				return false;
			}

			cancel();
			const generation = generationRef.current;
			setError( null );
			setIsSpeaking( true );

			try {
				const nextCapabilities =
					capabilities || ( await loadSpeechCapabilities() );
				if ( generation !== generationRef.current ) {
					return false;
				}
				setCapabilities( nextCapabilities );
				const synthesis = nextCapabilities.text_to_speech;
				const requestedLanguage =
					turnOptions.lang ||
					lang ||
					nextCapabilities.locales?.initial_locale ||
					'';
				const preferredVoice = turnOptions.voice || voice || voiceURI;
				const selectedVoice =
					synthesis.voices.find(
						( candidate ) => candidate.id === preferredVoice
					) ||
					synthesis.voices.find( ( candidate ) =>
						candidate.locales.includes( requestedLanguage )
					) ||
					synthesis.voices[ 0 ];
				const language = selectedVoice.locales.includes(
					requestedLanguage
				)
					? requestedLanguage
					: '';
				const requestedSpeed = Number( turnOptions.rate ?? rate );
				const minimumSpeed = synthesis.speed.minimum;
				const maximumSpeed = synthesis.speed.maximum;
				let speed = 1;
				if ( Number.isFinite( requestedSpeed ) ) {
					speed = requestedSpeed;
				}
				speed = Math.min(
					maximumSpeed,
					Math.max( minimumSpeed, speed )
				);
				const mimeType = synthesis.output_mime_types[ 0 ];
				const chunks = chunkSpeechText(
					speechText,
					synthesis.max_input_characters
				);
				let playbackStarted = false;

				for ( const chunk of chunks ) {
					if ( generation !== generationRef.current ) {
						return false;
					}
					const controller = new AbortController();
					abortRef.current = controller;
					const result = await apiFetch( {
						data: {
							language: language || undefined,
							mime_type: mimeType,
							session_id:
								turnOptions.sessionId || sessionId || undefined,
							speed,
							text: chunk,
							voice: selectedVoice.id,
						},
						method: 'POST',
						path: '/sd-ai-agent/v1/speech/synthesis',
						signal: controller.signal,
					} );
					if ( generation !== generationRef.current ) {
						return false;
					}
					const blob = base64ToBlob( result.audio, result.mime_type );
					if ( blob.size > synthesis.max_response_bytes ) {
						throw new Error(
							'The synthesized audio is too large.'
						);
					}
					if ( ! playbackStarted ) {
						playbackStarted = true;
						onStart?.();
					}
					await playBlob( blob, generation );
				}
				return true;
			} catch ( caughtError ) {
				if (
					caughtError?.name !== 'AbortError' &&
					generation === generationRef.current
				) {
					const message =
						caughtError?.message || 'Unable to synthesize speech.';
					setError( message );
					onError?.( message );
				}
				return false;
			} finally {
				if ( generation === generationRef.current ) {
					abortRef.current = null;
					releaseAudio();
					setIsSpeaking( false );
					onEnd?.();
				}
			}
		},
		[
			cancel,
			capabilities,
			lang,
			onEnd,
			onError,
			onStart,
			playBlob,
			rate,
			releaseAudio,
			sessionId,
			voice,
			voiceURI,
		]
	);

	return {
		cancel,
		capabilities,
		error,
		isSpeaking,
		isSupported: isTTSSupported,
		speak,
	};
}
