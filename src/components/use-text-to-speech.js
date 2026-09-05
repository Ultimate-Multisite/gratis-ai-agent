/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
	base64ToBlob,
	chunkSpeechText,
	isBrowserSpeechSynthesisSupported,
	isManagedAudioPlaybackSupported,
	loadSpeechCapabilities,
	toSpeakableText,
} from '../utils/speech';

/** Whether managed audio playback is available in this browser. */
export const isTTSSupported = isManagedAudioPlaybackSupported;
export const isBrowserFallbackSupported = isBrowserSpeechSynthesisSupported;

/**
 * Load the managed speech contract for preference controls.
 *
 * @return {Object|null} Managed speech capabilities.
 */
export function useSpeechCapabilities() {
	const [ capabilities, setCapabilities ] = useState( null );
	useEffect( () => {
		let active = true;
		loadSpeechCapabilities()
			.then( ( result ) => {
				if ( active ) {
					setCapabilities( result );
				}
			} )
			.catch( () => undefined );
		return () => {
			active = false;
		};
	}, [] );
	return capabilities;
}

/**
 * Load managed voices in the legacy selector shape used by settings.
 *
 * @return {Object[]} Managed voice options.
 */
export function useAvailableVoices() {
	const capabilities = useSpeechCapabilities();
	return (
		capabilities?.text_to_speech?.voices.map( ( voice ) => ( {
			lang: voice.locales[ 0 ] || '',
			name: voice.name,
			voiceURI: voice.id,
		} ) ) || []
	);
}

/**
 * Speak text through the authenticated managed synthesis route.
 *
 * @param {Object}   options                      Configuration.
 * @param {boolean}  options.allowBrowserFallback Allow explicit browser fallback.
 * @param {string}   options.lang                 Initial BCP-47 language hint.
 * @param {Function} options.onEnd                Called after playback finishes.
 * @param {Function} options.onError              Called after synthesis/playback fails.
 * @param {Function} options.onStart              Called when managed playback starts.
 * @param {number}   options.rate                 Requested playback speed.
 * @param {string}   options.sessionId            Optional managed session identifier.
 * @param {string}   options.voice                Preferred managed voice identifier.
 * @param {string}   options.voiceURI             Legacy preferred voice identifier.
 * @return {Object} Managed speech controls and state.
 */
export default function useTextToSpeech( {
	allowBrowserFallback = false,
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
	const nativeRef = useRef( null );
	const nativeResolveRef = useRef( null );
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

	const releaseNative = useCallback( ( settlePlayback = true ) => {
		if ( nativeRef.current ) {
			nativeRef.current.onend = null;
			nativeRef.current.onerror = null;
			globalThis.speechSynthesis.cancel();
			nativeRef.current = null;
		}
		if ( settlePlayback && nativeResolveRef.current ) {
			nativeResolveRef.current();
		}
		nativeResolveRef.current = null;
	}, [] );

	const cancel = useCallback( () => {
		generationRef.current += 1;
		abortRef.current?.abort();
		abortRef.current = null;
		releaseAudio();
		releaseNative();
		setIsSpeaking( false );
	}, [ releaseAudio, releaseNative ] );

	useEffect( () => {
		if ( ! isTTSSupported ) {
			return undefined;
		}
		let active = true;
		loadSpeechCapabilities()
			.then( ( result ) => {
				if ( active ) {
					setCapabilities( result );
				}
			} )
			.catch( () => undefined );
		return () => {
			active = false;
		};
	}, [] );

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
					reject(
						new Error(
							__(
								'Unable to play synthesized speech.',
								'superdav-ai-agent'
							)
						)
					);
				};
				audio.play().catch( ( caughtError ) => {
					releaseAudio( false );
					reject( caughtError );
				} );
			} ),
		[ releaseAudio ]
	);

	const playBrowserFallback = useCallback(
		( text, generation, language, speed ) =>
			new Promise( ( resolve, reject ) => {
				if ( generation !== generationRef.current ) {
					resolve();
					return;
				}
				const utterance = new globalThis.SpeechSynthesisUtterance(
					text
				);
				utterance.lang = language;
				utterance.rate = Math.min( 10, Math.max( 0.1, speed ) );
				nativeRef.current = utterance;
				nativeResolveRef.current = resolve;
				utterance.onend = () => {
					releaseNative( false );
					resolve();
				};
				utterance.onerror = () => {
					releaseNative( false );
					reject(
						new Error(
							__(
								'Unable to play browser speech.',
								'superdav-ai-agent'
							)
						)
					);
				};
				globalThis.speechSynthesis.speak( utterance );
			} ),
		[ releaseNative ]
	);

	const speak = useCallback(
		async ( text, turnOptions = {} ) => {
			const speechText = toSpeakableText( String( text || '' ) );
			if ( ! speechText ) {
				return false;
			}

			cancel();
			const generation = generationRef.current;
			const fallbackLanguage =
				turnOptions.lang ||
				lang ||
				globalThis.navigator?.language ||
				'';
			const fallbackSpeed = Number( turnOptions.rate ?? rate ) || 1;
			let playbackStarted = false;
			setError( null );
			setIsSpeaking( true );

			try {
				if ( ! isTTSSupported ) {
					if (
						! allowBrowserFallback ||
						! isBrowserFallbackSupported
					) {
						return false;
					}
					playbackStarted = true;
					onStart?.();
					await playBrowserFallback(
						speechText,
						generation,
						fallbackLanguage,
						fallbackSpeed
					);
					return true;
				}
				const nextCapabilities =
					capabilities || ( await loadSpeechCapabilities() );
				if ( generation !== generationRef.current ) {
					return false;
				}
				setCapabilities( nextCapabilities );
				if ( false === nextCapabilities?.availability?.available ) {
					if (
						! allowBrowserFallback ||
						! isBrowserFallbackSupported
					) {
						return false;
					}
					playbackStarted = true;
					onStart?.();
					await playBrowserFallback(
						speechText,
						generation,
						fallbackLanguage,
						fallbackSpeed
					);
					return true;
				}
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
							__(
								'The synthesized audio is too large.',
								'superdav-ai-agent'
							)
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
				const failure = caughtError;
				if (
					failure?.name !== 'AbortError' &&
					generation === generationRef.current
				) {
					const message =
						failure?.message ||
						__(
							'Unable to synthesize speech.',
							'superdav-ai-agent'
						);
					setError( message );
					onError?.( message );
				}
				return false;
			} finally {
				if ( generation === generationRef.current ) {
					abortRef.current = null;
					releaseAudio();
					releaseNative();
					setIsSpeaking( false );
					onEnd?.();
				}
			}
		},
		[
			allowBrowserFallback,
			cancel,
			capabilities,
			lang,
			onEnd,
			onError,
			onStart,
			playBlob,
			playBrowserFallback,
			rate,
			releaseAudio,
			releaseNative,
			sessionId,
			voice,
			voiceURI,
		]
	);

	const synthesisCapabilities = capabilities?.text_to_speech;
	const hasManagedSynthesis = Boolean(
		isTTSSupported &&
			capabilities?.available &&
			Array.isArray( synthesisCapabilities?.voices ) &&
			synthesisCapabilities.voices.length > 0 &&
			Array.isArray( synthesisCapabilities.output_mime_types ) &&
			synthesisCapabilities.output_mime_types.length > 0 &&
			Number( synthesisCapabilities.max_input_characters ) > 0 &&
			Number( synthesisCapabilities.max_response_bytes ) > 0
	);

	return {
		cancel,
		capabilities,
		error,
		isSpeaking,
		isSupported:
			hasManagedSynthesis ||
			( allowBrowserFallback &&
				isBrowserFallbackSupported &&
				( ! isTTSSupported ||
					false === capabilities?.availability?.available ) ),
		speak,
	};
}
