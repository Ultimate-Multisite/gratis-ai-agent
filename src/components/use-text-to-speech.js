/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

import { chunkSpeechText, toSpeakableText } from '../utils/speech';

export const isTTSSupported = typeof Audio !== 'undefined';

/**
 *
 */
export function useAvailableVoices() {
	return [];
}

/**
 * Queue managed speech audio without relying on the browser speech APIs.
 *
 * @param {Object} options               Speech preferences and service limits.
 * @param {string} options.voiceId       Selected service voice ID.
 * @param {number} options.rate          Selected service speech speed.
 * @param {string} options.lang          Current session language hint.
 * @param {number} options.maxTextLength Service maximum text size per request.
 * @return {Object} Playback state and controls.
 */
export default function useTextToSpeech( {
	voiceId = 'auto',
	rate = 1,
	lang = '',
	maxTextLength = 1200,
} = {} ) {
	const [ isSpeaking, setIsSpeaking ] = useState( false );
	const [ error, setError ] = useState( null );
	const audioRef = useRef( null );
	const controllerRef = useRef( null );
	const generationRef = useRef( 0 );

	const cancel = useCallback( () => {
		generationRef.current += 1;
		controllerRef.current?.abort();
		const audio = audioRef.current;
		if ( audio ) {
			audio.pause();
			URL.revokeObjectURL( audio.src );
		}
		audioRef.current = null;
		setIsSpeaking( false );
	}, [] );

	useEffect( () => cancel, [ cancel ] );

	const speak = useCallback(
		async ( text ) => {
			cancel();
			const chunks = chunkSpeechText(
				toSpeakableText( text || '' ),
				maxTextLength
			);
			if ( ! chunks.length || ! isTTSSupported ) {
				return;
			}
			const generation = generationRef.current;
			setError( null );
			setIsSpeaking( true );
			try {
				for ( const chunk of chunks ) {
					const controller = new AbortController();
					controllerRef.current = controller;
					const result = await apiFetch( {
						data: {
							language: lang,
							speed: rate,
							text: chunk,
							voice_id: voiceId,
						},
						method: 'POST',
						path: '/sd-ai-agent/v1/speech/synthesis',
						signal: controller.signal,
					} );
					if ( generation !== generationRef.current ) {
						return;
					}
					const bytes = Uint8Array.from(
						atob( result.audio ),
						( character ) => character.charCodeAt( 0 )
					);
					const url = URL.createObjectURL(
						new Blob( [ bytes ], { type: result.mime_type } )
					);
					const audio = new Audio( url );
					audioRef.current = audio;
					await audio.play();
					await new Promise( ( resolve, reject ) => {
						audio.onended = resolve;
						audio.onerror = reject;
					} );
					URL.revokeObjectURL( url );
				}
			} catch ( caughtError ) {
				if ( generation === generationRef.current ) {
					setError(
						caughtError?.message || 'Unable to play speech audio.'
					);
				}
			} finally {
				if ( generation === generationRef.current ) {
					setIsSpeaking( false );
				}
			}
		},
		[ cancel, lang, maxTextLength, rate, voiceId ]
	);

	return { cancel, error, isSpeaking, isSupported: isTTSSupported, speak };
}
