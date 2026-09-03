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
	const streamRef = useRef( null );
	const recorderRef = useRef( null );
	const chunksRef = useRef( [] );
	const timeoutRef = useRef( null );
	const limitReachedRef = useRef( false );

	const cleanup = useCallback( () => {
		if ( timeoutRef.current ) {
			clearTimeout( timeoutRef.current );
			timeoutRef.current = null;
		}
		streamRef.current?.getTracks().forEach( ( track ) => track.stop() );
		streamRef.current = null;
		recorderRef.current = null;
	}, [] );

	const stop = useCallback( () => {
		const recorder = recorderRef.current;
		if ( recorder && recorder.state !== 'inactive' ) {
			setStatus( 'stopping' );
			recorder.stop();
			return;
		}
		cleanup();
		setStatus( 'idle' );
	}, [ cleanup ] );

	const cancel = useCallback( () => {
		chunksRef.current = [];
		stop();
	}, [ stop ] );

	const start = useCallback(
		async ( capabilityOverrides = {} ) => {
			const supportedMimeTypes =
				capabilityOverrides.acceptedMimeTypes || acceptedMimeTypes;
			const byteLimit = capabilityOverrides.maxBytes ?? maxBytes;
			const durationLimit =
				capabilityOverrides.maxDurationMs ?? maxDurationMs;
			if ( status !== 'idle' || typeof MediaRecorder === 'undefined' ) {
				setError( 'Audio recording is not supported by this browser.' );
				setStatus( 'error' );
				return;
			}
			const mimeType = supportedMimeTypes.find( ( candidate ) =>
				MediaRecorder.isTypeSupported( candidate )
			);
			if ( ! mimeType ) {
				setError( 'No supported audio format is available.' );
				setStatus( 'error' );
				return;
			}

			setError( null );
			setStatus( 'requesting_permission' );
			limitReachedRef.current = false;
			chunksRef.current = [];
			try {
				const stream =
					await navigator.mediaDevices.getUserMedia(
						DEFAULT_CONSTRAINTS
					);
				streamRef.current = stream;
				const recorder = new MediaRecorder( stream, { mimeType } );
				recorderRef.current = recorder;
				recorder.ondataavailable = ( event ) => {
					if ( ! event.data?.size ) {
						return;
					}
					chunksRef.current.push( event.data );
					if (
						byteLimit > 0 &&
						chunksRef.current.reduce(
							( total, chunk ) => total + chunk.size,
							0
						) >= byteLimit
					) {
						limitReachedRef.current = true;
						stop();
					}
				};
				recorder.onerror = () => {
					setError( 'The audio recorder failed.' );
					chunksRef.current = [];
					cleanup();
					setStatus( 'error' );
				};
				recorder.onstop = () => {
					const chunks = chunksRef.current;
					chunksRef.current = [];
					const blob = new Blob( chunks, { type: mimeType } );
					cleanup();
					if ( limitReachedRef.current ) {
						setError( 'The recording reached the service limit.' );
						setStatus( 'error' );
						return;
					}
					setStatus( 'idle' );
					onComplete?.( { blob, mimeType } );
				};
				recorder.start();
				setStatus( 'recording' );
				if ( durationLimit > 0 ) {
					timeoutRef.current = setTimeout( () => {
						limitReachedRef.current = true;
						stop();
					}, durationLimit );
				}
			} catch ( caughtError ) {
				cleanup();
				setError( recorderError( caughtError ) );
				setStatus( 'error' );
			}
		},
		[
			acceptedMimeTypes,
			cleanup,
			maxBytes,
			maxDurationMs,
			onComplete,
			status,
			stop,
		]
	);

	useEffect( () => cleanup, [ cleanup ] );

	return { cancel, error, start, status, stop };
}
