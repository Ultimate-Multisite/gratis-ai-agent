/**
 * WordPress dependencies
 */
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

import STORE_NAME from '../store';
import { extractText } from './chat-redesign/message-helpers';
import useSpeechRecognition from './use-speech-recognition';
import useTextToSpeech from './use-text-to-speech';

let activePlayback = null;

const latestModelResponse = ( messages ) => {
	for ( let index = messages.length - 1; index >= 0; index-- ) {
		const message = messages[ index ];
		if ( message?.role === 'model' ) {
			const text = extractText( message );
			return {
				identity: `${ index }:${ message.id || '' }:${ text }`,
				text,
			};
		}
	}
	return { identity: '', text: '' };
};

const STATUS_LABELS = {
	error: __( 'Voice turn stopped', 'superdav-ai-agent' ),
	idle: __( 'Voice ready', 'superdav-ai-agent' ),
	listening: __( 'Listening', 'superdav-ai-agent' ),
	loading_capabilities: __( 'Preparing voice', 'superdav-ai-agent' ),
	recording: __( 'Listening', 'superdav-ai-agent' ),
	requesting_permission: __(
		'Waiting for microphone permission',
		'superdav-ai-agent'
	),
	sending: __( 'Sending voice message', 'superdav-ai-agent' ),
	speaking: __( 'Reading response aloud', 'superdav-ai-agent' ),
	stopping: __( 'Finishing recording', 'superdav-ai-agent' ),
	thinking: __( 'Waiting for response', 'superdav-ai-agent' ),
	transcribing: __( 'Transcribing', 'superdav-ai-agent' ),
};

/**
 * Coordinate one turn-based managed voice conversation surface.
 *
 * Microphone capture starts only from startListening(), which UI controls must
 * call directly from a user gesture. Completing playback never reopens it.
 *
 * @param {Object} options         Coordinator options.
 * @param {string} options.surface Surface identity: main or widget.
 * @return {Object} Voice state and controls for one chat surface.
 */
export default function useVoiceConversation( { surface = 'main' } = {} ) {
	const awaitingResponseRef = useRef( false );
	const responseBaselineRef = useRef( '' );
	const ownerRef = useRef( Symbol( 'voice-conversation' ) );
	const voiceTurnRef = useRef( false );
	const [ pendingTranscript, setPendingTranscript ] = useState( null );
	const [ phase, setPhase ] = useState( 'idle' );
	const { sendMessage, setVoiceConversationEnabled } =
		useDispatch( STORE_NAME );
	const {
		currentSessionId,
		messages,
		readAloudEnabled,
		sending,
		speechFallbackEnabled,
		speed,
		streamError,
		voiceId,
		voiceModeEnabled,
	} = useSelect( ( select ) => {
		const store = select( STORE_NAME );
		return {
			currentSessionId: store.getCurrentSessionId?.() || null,
			messages: store.getCurrentSessionMessages?.() || [],
			readAloudEnabled: store.isTtsEnabled?.() || false,
			sending: store.isSending?.() || false,
			speechFallbackEnabled: store.isSpeechFallbackEnabled?.() || false,
			speed: store.getTtsRate?.() || 1,
			streamError: store.hasStreamError?.() || false,
			voiceId: store.getTtsVoiceURI?.() || '',
			voiceModeEnabled: store.isVoiceConversationEnabled?.() || false,
		};
	}, [] );
	const voiceModeRef = useRef( voiceModeEnabled );
	const previousSessionIdRef = useRef( currentSessionId );

	useEffect( () => {
		voiceModeRef.current = voiceModeEnabled;
	}, [ voiceModeEnabled ] );

	const handleTranscript = useCallback(
		( text ) => {
			if ( voiceModeRef.current ) {
				voiceTurnRef.current = true;
				setPhase( 'sending' );
				sendMessage( text, [] );
				return;
			}
			setPendingTranscript( {
				id: globalThis.crypto?.randomUUID?.() || Date.now(),
				text,
			} );
		},
		[ sendMessage ]
	);

	const {
		cancelListening: cancelRecognition,
		detectedLanguage,
		error: recognitionError,
		isListening,
		isSupported: recognitionSupported,
		isTranscribing,
		startListening: startRecognition,
		status: recognitionStatus,
		stopListening: stopRecognition,
	} = useSpeechRecognition( {
		lang: globalThis.sdAiAgentData?.speechLocales?.initial_locale || '',
		onResult: handleTranscript,
		sessionKey: currentSessionId || '',
	} );
	const {
		cancel: cancelSpeech,
		error: speechError,
		isSpeaking,
		isSupported: speechSupported,
		speak,
	} = useTextToSpeech( {
		allowBrowserFallback: speechFallbackEnabled,
		lang:
			detectedLanguage ||
			globalThis.sdAiAgentData?.speechLocales?.initial_locale ||
			'',
		rate: speed,
		sessionId: currentSessionId || '',
		voice: voiceId,
	} );

	const stopSpeaking = useCallback(
		( { resetPhase = true } = {} ) => {
			if ( activePlayback?.owner === ownerRef.current ) {
				activePlayback = null;
			}
			cancelSpeech();
			if ( resetPhase ) {
				setPhase( 'idle' );
			}
		},
		[ cancelSpeech ]
	);

	const readAloud = useCallback(
		async ( text, options = {} ) => {
			if ( activePlayback && activePlayback.owner !== ownerRef.current ) {
				setPhase( 'idle' );
				return false;
			}
			const owner = ownerRef.current;
			const playback = {
				cancel: () => {
					cancelSpeech();
					setPhase( 'idle' );
				},
				owner,
			};
			activePlayback = playback;
			setPhase( 'speaking' );
			const completed = await speak( text, options );
			if ( activePlayback === playback ) {
				activePlayback = null;
				setPhase( completed ? 'idle' : 'error' );
			}
			return completed;
		},
		[ cancelSpeech, speak ]
	);

	const startListening = useCallback( async () => {
		if ( activePlayback && activePlayback.owner !== ownerRef.current ) {
			const cancelOwnerPlayback = activePlayback.cancel;
			activePlayback = null;
			cancelOwnerPlayback();
		} else if ( activePlayback || isSpeaking ) {
			stopSpeaking();
		}
		setPhase( 'loading_capabilities' );
		const started = await startRecognition();
		if ( ! started ) {
			setPhase( 'error' );
		}
		return started;
	}, [ isSpeaking, startRecognition, stopSpeaking ] );

	const stopListening = useCallback( () => {
		stopRecognition();
		setPhase( 'transcribing' );
	}, [ stopRecognition ] );

	const toggleListening = useCallback( () => {
		if ( isListening ) {
			stopListening();
			return;
		}
		startListening();
	}, [ isListening, startListening, stopListening ] );

	const consumeTranscript = useCallback( ( id ) => {
		setPendingTranscript( ( current ) =>
			current?.id === id ? null : current
		);
	}, [] );

	const toggleVoiceMode = useCallback( () => {
		const enabled = ! voiceModeEnabled;
		setVoiceConversationEnabled?.( enabled );
		if ( ! enabled ) {
			voiceTurnRef.current = false;
			cancelRecognition();
			stopSpeaking();
		}
	}, [
		cancelRecognition,
		setVoiceConversationEnabled,
		stopSpeaking,
		voiceModeEnabled,
	] );

	useEffect( () => {
		if ( sending ) {
			if ( ! awaitingResponseRef.current ) {
				responseBaselineRef.current =
					latestModelResponse( messages ).identity;
				awaitingResponseRef.current = true;
			}
			if ( voiceTurnRef.current || readAloudEnabled ) {
				setPhase( 'thinking' );
			}
			return;
		}
		if ( ! awaitingResponseRef.current ) {
			return;
		}
		const latestResponse = latestModelResponse( messages );
		if (
			! latestResponse.text ||
			latestResponse.identity === responseBaselineRef.current
		) {
			setPhase( 'thinking' );
			return;
		}
		awaitingResponseRef.current = false;
		const shouldSpeak = voiceTurnRef.current || readAloudEnabled;
		voiceTurnRef.current = false;
		const mainSurfacePresent =
			typeof document !== 'undefined' &&
			document.querySelector( '.sdaa-cr' );
		const observesPlayback = surface !== 'widget' || ! mainSurfacePresent;
		if ( shouldSpeak && observesPlayback ) {
			readAloud( latestResponse.text );
		} else {
			setPhase( 'idle' );
		}
	}, [ messages, readAloud, readAloudEnabled, sending, surface ] );

	useEffect( () => {
		if ( streamError ) {
			awaitingResponseRef.current = false;
			voiceTurnRef.current = false;
			setPhase( 'error' );
		}
	}, [ streamError ] );

	useEffect( () => {
		if ( isTranscribing ) {
			setPhase( 'transcribing' );
			return;
		}
		if ( isListening ) {
			setPhase( recognitionStatus );
		}
	}, [ isListening, isTranscribing, recognitionStatus ] );

	useEffect( () => {
		if ( recognitionError || speechError ) {
			setPhase( 'error' );
		}
	}, [ recognitionError, speechError ] );

	useEffect( () => {
		const previousSessionId = previousSessionIdRef.current;
		previousSessionIdRef.current = currentSessionId;
		const createdSessionForPendingTurn = Boolean(
			! previousSessionId && currentSessionId && sending
		);
		cancelRecognition();
		stopSpeaking( { resetPhase: ! createdSessionForPendingTurn } );
		if ( createdSessionForPendingTurn ) {
			awaitingResponseRef.current = true;
			return;
		}
		voiceTurnRef.current = false;
		awaitingResponseRef.current = false;
		responseBaselineRef.current = latestModelResponse( messages ).identity;
	}, [ currentSessionId ] ); // eslint-disable-line react-hooks/exhaustive-deps

	useEffect( () => {
		const owner = ownerRef.current;
		const stopForPageChange = () => {
			cancelRecognition();
			stopSpeaking();
		};
		const handleVisibility = () => {
			if ( document.hidden ) {
				stopForPageChange();
			}
		};
		document.addEventListener( 'visibilitychange', handleVisibility );
		window.addEventListener( 'pagehide', stopForPageChange );
		return () => {
			document.removeEventListener(
				'visibilitychange',
				handleVisibility
			);
			window.removeEventListener( 'pagehide', stopForPageChange );
			stopForPageChange();
			if ( activePlayback?.owner === owner ) {
				activePlayback = null;
			}
		};
	}, [ cancelRecognition, stopSpeaking ] );

	return useMemo(
		() => ( {
			consumeTranscript,
			error: recognitionError || speechError,
			isListening,
			isSpeaking,
			isSpeechSupported: speechSupported,
			isSupported: recognitionSupported && speechSupported,
			pendingTranscript,
			phase,
			readAloud,
			startListening,
			statusLabel: STATUS_LABELS[ phase ] || STATUS_LABELS.idle,
			stopListening,
			stopSpeaking,
			toggleListening,
			toggleVoiceMode,
			voiceModeEnabled,
		} ),
		[
			consumeTranscript,
			isListening,
			isSpeaking,
			pendingTranscript,
			phase,
			readAloud,
			recognitionError,
			recognitionSupported,
			speechError,
			speechSupported,
			startListening,
			stopListening,
			stopSpeaking,
			toggleListening,
			toggleVoiceMode,
			voiceModeEnabled,
		]
	);
}
