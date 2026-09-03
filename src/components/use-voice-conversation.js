/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

import STORE_NAME from '../store';
import { extractText } from './chat-redesign/message-helpers';
import useSpeechRecognition from './use-speech-recognition';
import useTextToSpeech from './use-text-to-speech';

let playbackOwner = null;

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
 * @return {Object} Voice state and controls for one chat surface.
 */
export default function useVoiceConversation() {
	const ownerRef = useRef( Symbol( 'voice-conversation' ) );
	const previousSendingRef = useRef( false );
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
		voiceId,
		voiceModeEnabled,
	} = useSelect( ( select ) => {
		const store = select( STORE_NAME );
		return {
			currentSessionId: store.getCurrentSessionId(),
			messages: store.getCurrentSessionMessages(),
			readAloudEnabled: store.isTtsEnabled(),
			sending: store.isSending(),
			speechFallbackEnabled: store.isSpeechFallbackEnabled(),
			speed: store.getTtsRate(),
			voiceId: store.getTtsVoiceURI(),
			voiceModeEnabled: store.isVoiceConversationEnabled(),
		};
	}, [] );
	const voiceModeRef = useRef( voiceModeEnabled );

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

	const stopSpeaking = useCallback( () => {
		if ( playbackOwner === ownerRef.current ) {
			playbackOwner = null;
		}
		cancelSpeech();
		setPhase( 'idle' );
	}, [ cancelSpeech ] );

	const readAloud = useCallback(
		async ( text, options = {} ) => {
			if ( playbackOwner && playbackOwner !== ownerRef.current ) {
				setPhase( 'idle' );
				return false;
			}
			playbackOwner = ownerRef.current;
			setPhase( 'speaking' );
			const completed = await speak( text, options );
			if ( playbackOwner === ownerRef.current ) {
				playbackOwner = null;
				setPhase( completed ? 'idle' : 'error' );
			}
			return completed;
		},
		[ speak ]
	);

	const startListening = useCallback( async () => {
		if ( isSpeaking ) {
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
		setVoiceConversationEnabled( enabled );
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
			previousSendingRef.current = true;
			if ( voiceTurnRef.current ) {
				setPhase( 'thinking' );
			}
			return;
		}
		if ( ! previousSendingRef.current ) {
			return;
		}
		previousSendingRef.current = false;
		const latestResponse = [ ...messages ]
			.reverse()
			.find( ( message ) => message?.role === 'model' );
		const text = latestResponse ? extractText( latestResponse ) : '';
		const shouldSpeak = voiceTurnRef.current || readAloudEnabled;
		voiceTurnRef.current = false;
		if ( shouldSpeak && text ) {
			readAloud( text );
		} else {
			setPhase( 'idle' );
		}
	}, [ messages, readAloud, readAloudEnabled, sending ] );

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
		cancelRecognition();
		stopSpeaking();
		voiceTurnRef.current = false;
		previousSendingRef.current = sending;
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
			if ( playbackOwner === owner ) {
				playbackOwner = null;
			}
		};
	}, [ cancelRecognition, stopSpeaking ] );

	return {
		consumeTranscript,
		error: recognitionError || speechError,
		isListening,
		isSpeaking,
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
	};
}
