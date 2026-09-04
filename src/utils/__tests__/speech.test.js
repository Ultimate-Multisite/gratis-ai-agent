/**
 * Focused managed-speech utility tests.
 */

import apiFetch from '@wordpress/api-fetch';

import {
	base64ToBlob,
	chunkSpeechText,
	encodeAudioBufferToWav,
	loadSpeechCapabilities,
	recordingToWav,
	resetSpeechCapabilities,
	toSpeakableText,
} from '../speech';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

describe( 'managed speech utilities', () => {
	afterEach( () => {
		resetSpeechCapabilities();
		jest.clearAllMocks();
	} );

	test( 'loads and caches the authenticated capability contract', async () => {
		const capabilities = {
			available: true,
			text_to_speech: { voices: [] },
			transcription: { accepted_input_mime_types: [ 'audio/wav' ] },
		};
		apiFetch.mockResolvedValue( capabilities );

		await expect( loadSpeechCapabilities() ).resolves.toBe( capabilities );
		await expect( loadSpeechCapabilities() ).resolves.toBe( capabilities );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/sd-ai-agent/v1/speech/capabilities',
		} );
	} );

	test( 'rejects incomplete capability responses', async () => {
		apiFetch.mockResolvedValue( { available: true } );

		await expect( loadSpeechCapabilities() ).rejects.toThrow(
			'Speech services are unavailable.'
		);
	} );

	test( 'cleans Markdown and chunks Unicode text at service bounds', () => {
		expect(
			toSpeakableText(
				'# Heading\nA [link](https://example.com). `code`\n```secret```'
			)
		).toBe( 'Heading A link. code' );

		const chunks = chunkSpeechText( '😀😀. Next sentence.', 6 );
		expect( chunks ).toEqual( [ '😀😀.', 'Next s', 'entenc', 'e.' ] );
		expect(
			chunks.every( ( chunk ) => Array.from( chunk ).length <= 6 )
		).toBe( true );
	} );

	test( 'decodes inline audio into a temporary blob', () => {
		const blob = base64ToBlob( 'UklGRg==', 'audio/wav' );

		expect( blob.type ).toBe( 'audio/wav' );
		expect( blob.size ).toBe( 4 );
	} );

	test( 'encodes mono 16-bit WAV and enforces the output byte limit', () => {
		const audioBuffer = {
			duration: 0.001,
			getChannelData: () => new Float32Array( 48 ),
			length: 48,
			numberOfChannels: 1,
			sampleRate: 48000,
		};
		const wav = encodeAudioBufferToWav( audioBuffer, 100 );

		expect( wav.type ).toBe( 'audio/wav' );
		expect( wav.size ).toBe( 76 );
		expect( () => encodeAudioBufferToWav( audioBuffer, 75 ) ).toThrow(
			'The recording reached the service limit.'
		);
	} );

	test( 'normalizes recordings already labelled as WAV', async () => {
		const audioBuffer = {
			duration: 0.001,
			getChannelData: () => new Float32Array( 48 ),
			length: 48,
			numberOfChannels: 1,
			sampleRate: 48000,
		};
		const decodeAudioData = jest.fn().mockResolvedValue( audioBuffer );
		const close = jest.fn().mockResolvedValue( undefined );
		const OriginalAudioContext = global.AudioContext;
		global.AudioContext = jest.fn( () => ( { close, decodeAudioData } ) );
		const recording = {
			arrayBuffer: jest.fn().mockResolvedValue( new ArrayBuffer( 4 ) ),
			type: 'audio/wav',
		};

		const wav = await recordingToWav( recording, 100 );

		expect( decodeAudioData ).toHaveBeenCalledTimes( 1 );
		expect( wav.type ).toBe( 'audio/wav' );
		expect( wav.size ).toBe( 76 );
		expect( close ).toHaveBeenCalledTimes( 1 );
		global.AudioContext = OriginalAudioContext;
	} );
} );
