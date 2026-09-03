/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

const DEFAULT_CONSTRAINTS = {
	audio: {
		autoGainControl: true,
		echoCancellation: true,
		noiseSuppression: true,
	},
};

const BROWSER_RECORDING_MIME_TYPES = [
	'audio/webm;codecs=opus',
	'audio/webm',
	'audio/ogg;codecs=opus',
	'audio/mp4',
];

const recorderError = ( error ) => {
	if (
		error?.name === 'NotAllowedError' ||
		error?.name === 'SecurityError'
	) {
		return 'Microphone permission was denied.';
	}
	if ( error?.name === 'NotFoundError' ) {
		return 'No microphone is available.';
	}
	return error?.message || 'Unable to record audio.';
};

/**
 * Capture one bounded microphone recording without retaining audio after use.
 *
 * @param {Object}   options                   Configuration supplied by speech capabilities.
 * @param {string[]} options.acceptedMimeTypes Service-supported recording MIME types.
 * @param {number}   options.maxBytes          Maximum accepted recording size.
 * @param {number}   options.maxDurationMs     Maximum accepted recording duration.
 * @param {Function} options.onComplete        Called with the temporary recording.
 * @return {Object} Recording state and controls.
 */
export default function useAudioRecorder( {
	acceptedMimeTypes = [],
	maxBytes = 0,
	maxDurationMs = 0,
	onComplete,
} = {} ) {
	const [ status, setStatus ] = useState( 'idle' );
	const [ error, setError ] = useState( null );
	const activeRef = useRef( false );
	const generationRef = useRef( 0 );
	const turnRef = useRef( null );

	const cleanupTurn = useCallback( ( turn ) => {
		if ( turn?.timeout ) {
			clearTimeout( turn.timeout );
			turn.timeout = null;
		}
		turn?.stream?.getTracks().forEach( ( track ) => track.stop() );
		if ( turnRef.current === turn ) {
			turnRef.current = null;
			activeRef.current = false;
		}
	}, [] );

	const stop = useCallback( () => {
		const recorder = turnRef.current?.recorder;
		if ( recorder && recorder.state !== 'inactive' ) {
			setStatus( 'stopping' );
			recorder.stop();
			return;
		}
		cleanupTurn( turnRef.current );
		setStatus( 'idle' );
	}, [ cleanupTurn ] );

	const cancel = useCallback( () => {
		generationRef.current += 1;
		activeRef.current = false;
		const turn = turnRef.current;
		if ( turn ) {
			turn.cancelled = true;
			turn.chunks = [];
			if ( turn.recorder.state !== 'inactive' ) {
				turn.recorder.stop();
			} else {
				cleanupTurn( turn );
			}
		}
		setStatus( 'idle' );
	}, [ cleanupTurn ] );

	const start = useCallback(
		async ( capabilityOverrides = {} ) => {
			const supportedMimeTypes =
				capabilityOverrides.acceptedMimeTypes || acceptedMimeTypes;
			const byteLimit = capabilityOverrides.maxBytes ?? maxBytes;
			const durationLimit =
				capabilityOverrides.maxDurationMs ?? maxDurationMs;
			if ( activeRef.current || typeof MediaRecorder === 'undefined' ) {
				setError( 'Audio recording is not supported by this browser.' );
				setStatus( 'error' );
				return false;
			}
			const candidates = supportedMimeTypes.some( ( candidate ) =>
				candidate.toLowerCase().startsWith( 'audio/wav' )
			)
				? [ ...supportedMimeTypes, ...BROWSER_RECORDING_MIME_TYPES ]
				: supportedMimeTypes;
			const mimeType = candidates.find( ( candidate ) =>
				MediaRecorder.isTypeSupported?.( candidate )
			);
			if ( ! mimeType ) {
				setError( 'No supported audio format is available.' );
				setStatus( 'error' );
				return false;
			}

			const generation = generationRef.current + 1;
			generationRef.current = generation;
			activeRef.current = true;
			setError( null );
			setStatus( 'requesting_permission' );
			let stream = null;
			try {
				stream =
					await navigator.mediaDevices.getUserMedia(
						DEFAULT_CONSTRAINTS
					);
				if (
					generation !== generationRef.current ||
					! activeRef.current
				) {
					stream.getTracks().forEach( ( track ) => track.stop() );
					return false;
				}
				const recorder = new MediaRecorder( stream, { mimeType } );
				const turn = {
					cancelled: false,
					chunks: [],
					generation,
					limitReached: false,
					recorder,
					stream,
					timeout: null,
					totalBytes: 0,
				};
				turnRef.current = turn;
				recorder.ondataavailable = ( event ) => {
					if ( turn.cancelled || ! event.data?.size ) {
						return;
					}
					turn.chunks.push( event.data );
					turn.totalBytes += event.data.size;
					if ( byteLimit > 0 && turn.totalBytes > byteLimit ) {
						turn.limitReached = true;
						if ( recorder.state !== 'inactive' ) {
							recorder.stop();
						}
					}
				};
				recorder.onerror = () => {
					turn.cancelled = true;
					turn.chunks = [];
					cleanupTurn( turn );
					if ( generation === generationRef.current ) {
						setError( 'The audio recorder failed.' );
						setStatus( 'error' );
					}
				};
				recorder.onstop = () => {
					const chunks = turn.chunks;
					turn.chunks = [];
					cleanupTurn( turn );
					if (
						turn.cancelled ||
						generation !== generationRef.current
					) {
						return;
					}
					if ( turn.limitReached ) {
						setError( 'The recording reached the service limit.' );
						setStatus( 'error' );
						return;
					}
					const blob = new Blob( chunks, {
						type: recorder.mimeType || mimeType,
					} );
					if ( ! blob.size ) {
						setError( 'The recording did not contain audio.' );
						setStatus( 'error' );
						return;
					}
					setStatus( 'idle' );
					onComplete?.( { blob, mimeType } );
				};
				recorder.start( 250 );
				setStatus( 'recording' );
				if ( durationLimit > 0 ) {
					turn.timeout = setTimeout( () => {
						turn.limitReached = true;
						if ( recorder.state !== 'inactive' ) {
							recorder.stop();
						}
					}, durationLimit );
				}
				return true;
			} catch ( caughtError ) {
				stream?.getTracks().forEach( ( track ) => track.stop() );
				if ( generation === generationRef.current ) {
					activeRef.current = false;
					setError( recorderError( caughtError ) );
					setStatus( 'error' );
				}
				return false;
			}
		},
		[ acceptedMimeTypes, cleanupTurn, maxBytes, maxDurationMs, onComplete ]
	);

	useEffect(
		() => () => {
			generationRef.current += 1;
			activeRef.current = false;
			const turn = turnRef.current;
			if ( turn ) {
				turn.cancelled = true;
				turn.chunks = [];
				if ( turn.recorder.state !== 'inactive' ) {
					turn.recorder.stop();
				} else {
					cleanupTurn( turn );
				}
			}
		},
		[ cleanupTurn ]
	);

	return { cancel, error, start, status, stop };
}
