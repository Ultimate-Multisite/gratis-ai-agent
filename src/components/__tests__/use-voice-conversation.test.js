/**
 * Focused turn coordinator tests.
 */

import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { useDispatch, useSelect } from '@wordpress/data';

import useSpeechRecognition from '../use-speech-recognition';
import useTextToSpeech from '../use-text-to-speech';
import useVoiceConversation from '../use-voice-conversation';

global.IS_REACT_ACT_ENVIRONMENT = true;

jest.mock( '@wordpress/data', () => ( {
	useDispatch: jest.fn(),
	useSelect: jest.fn(),
} ) );

jest.mock( '@wordpress/i18n', () => ( {
	__: ( text ) => text,
} ) );

jest.mock( '../../store', () => 'sd-ai-agent' );
jest.mock( '../use-speech-recognition', () => jest.fn() );
jest.mock( '../use-text-to-speech', () => jest.fn() );

let coordinator;

/**
 * @param {Object} props         Harness properties.
 * @param {string} props.surface Chat surface identity.
 * @return {null} Hook test harness.
 */
function VoiceHarness( { surface } ) {
	coordinator = useVoiceConversation( { surface } );
	return null;
}

describe( 'useVoiceConversation', () => {
	let container;
	let currentRecognition;
	let currentSpeech;
	let root;
	let store;

	beforeEach( () => {
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
		store = {
			getCurrentSessionId: () => 'session-1',
			getCurrentSessionMessages: () => [],
			getTtsRate: () => 1,
			getTtsVoiceURI: () => '',
			isSending: () => false,
			isSpeechFallbackEnabled: () => false,
			isTtsEnabled: () => false,
			isVoiceConversationEnabled: () => false,
		};
		useSelect.mockImplementation( ( callback ) => callback( () => store ) );
		useDispatch.mockReturnValue( {
			sendMessage: jest.fn(),
			setVoiceConversationEnabled: jest.fn(),
		} );
		currentRecognition = {
			cancelListening: jest.fn(),
			detectedLanguage: '',
			error: null,
			isListening: false,
			isSupported: true,
			isTranscribing: false,
			startListening: jest.fn().mockResolvedValue( true ),
			status: 'idle',
			stopListening: jest.fn(),
		};
		useSpeechRecognition.mockReturnValue( currentRecognition );
		currentSpeech = {
			cancel: jest.fn(),
			error: null,
			isSpeaking: false,
			isSupported: true,
			speak: jest.fn().mockResolvedValue( true ),
		};
		useTextToSpeech.mockReturnValue( currentSpeech );
	} );

	afterEach( () => {
		act( () => root.unmount() );
		container.remove();
		jest.clearAllMocks();
	} );

	test( 'keeps push-to-talk as draft input until voice mode is enabled', async () => {
		await act( async () => {
			root.render( <VoiceHarness /> );
		} );
		const recognitionOptions =
			useSpeechRecognition.mock.calls.at( -1 )[ 0 ];

		act( () => recognitionOptions.onResult( 'Draft transcript' ) );
		expect( coordinator.pendingTranscript.text ).toBe( 'Draft transcript' );
		expect(
			useDispatch.mock.results[ 0 ].value.sendMessage
		).not.toHaveBeenCalled();
	} );

	test( 'barge-in cancels active playback before requesting the microphone', async () => {
		currentSpeech.isSpeaking = true;
		useTextToSpeech.mockReturnValue( currentSpeech );
		await act( async () => root.render( <VoiceHarness /> ) );

		await act( async () => coordinator.startListening() );
		expect( currentSpeech.cancel ).toHaveBeenCalledTimes( 2 );
		expect( currentRecognition.startListening ).toHaveBeenCalledTimes( 1 );
		expect(
			currentSpeech.cancel.mock.invocationCallOrder.at( -1 )
		).toBeLessThan(
			currentRecognition.startListening.mock.invocationCallOrder[ 0 ]
		);
	} );

	test( 'waits for the new model response before starting playback', async () => {
		store.isTtsEnabled = () => true;
		store.getCurrentSessionMessages = () => [
			{
				id: 'old',
				parts: [ { text: 'Earlier response' } ],
				role: 'model',
			},
		];
		await act( async () => root.render( <VoiceHarness /> ) );

		store.isSending = () => true;
		await act( async () => root.render( <VoiceHarness /> ) );
		store.isSending = () => false;
		await act( async () => root.render( <VoiceHarness /> ) );
		expect( currentSpeech.speak ).not.toHaveBeenCalled();

		store.getCurrentSessionMessages = () => [
			{
				id: 'old',
				parts: [ { text: 'Earlier response' } ],
				role: 'model',
			},
			{
				id: 'new',
				parts: [ { text: 'Current response' } ],
				role: 'model',
			},
		];
		await act( async () => root.render( <VoiceHarness /> ) );

		expect( currentSpeech.speak ).toHaveBeenCalledWith(
			'Current response',
			{}
		);
	} );

	test( 'lets the main chat own automatic playback when both surfaces exist', async () => {
		const mainSurface = document.createElement( 'div' );
		mainSurface.className = 'sdaa-cr';
		document.body.appendChild( mainSurface );
		store.isTtsEnabled = () => true;

		await act( async () =>
			root.render( <VoiceHarness surface="widget" /> )
		);
		store.isSending = () => true;
		await act( async () =>
			root.render( <VoiceHarness surface="widget" /> )
		);
		store.isSending = () => false;
		store.getCurrentSessionMessages = () => [
			{
				id: 'new',
				parts: [ { text: 'Only once' } ],
				role: 'model',
			},
		];
		await act( async () =>
			root.render( <VoiceHarness surface="widget" /> )
		);

		expect( currentSpeech.speak ).not.toHaveBeenCalled();
		mainSurface.remove();
	} );
} );
