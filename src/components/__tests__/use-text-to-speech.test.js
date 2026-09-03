/**
 * Focused managed synthesis queue tests.
 */

import { act } from 'react';
import { createRoot } from 'react-dom/client';
import apiFetch from '@wordpress/api-fetch';

global.IS_REACT_ACT_ENVIRONMENT = true;

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const createdAudio = [];

class FakeAudio {
	constructor( source ) {
		this.source = source;
		createdAudio.push( this );
	}

	pause() {}

	removeAttribute() {}

	play() {
		return Promise.resolve().then( () => this.onended?.() );
	}
}

global.Audio = FakeAudio;
global.URL.createObjectURL = jest.fn( () => `blob:${ createdAudio.length }` );
global.URL.revokeObjectURL = jest.fn();

const useTextToSpeech = require( '../use-text-to-speech' ).default;

/**
 * @return {JSX.Element} Managed synthesis trigger.
 */
function SpeechHarness() {
	const speech = useTextToSpeech( {
		lang: 'en-US',
		rate: 1.25,
		sessionId: 'session-7',
		voice: 'voice-1',
	} );
	return (
		<div>
			<span data-status>{ speech.isSpeaking ? 'speaking' : 'idle' }</span>
			<button
				type="button"
				data-speak
				onClick={ () => speech.speak( '**Hello.** Next.' ) }
			>
				Speak
			</button>
		</div>
	);
}

describe( 'useTextToSpeech', () => {
	let container;
	let root;

	beforeEach( () => {
		createdAudio.length = 0;
		apiFetch.mockReset();
		global.URL.createObjectURL.mockClear();
		global.URL.revokeObjectURL.mockClear();
		apiFetch.mockImplementation( ( request ) => {
			if ( request.path.endsWith( '/capabilities' ) ) {
				return Promise.resolve( {
					available: true,
					locales: { initial_locale: 'en-US' },
					text_to_speech: {
						max_input_characters: 8,
						max_response_bytes: 100,
						output_formats: [ 'mp3' ],
						output_mime_types: [ 'audio/mpeg' ],
						speed: { minimum: 0.5, maximum: 2 },
						voices: [
							{
								id: 'voice-1',
								locales: [ 'en-US' ],
								name: 'Voice One',
							},
						],
					},
					transcription: {},
				} );
			}
			return Promise.resolve( {
				audio: 'UklGRg==',
				mime_type: 'audio/mpeg',
			} );
		} );
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
	} );

	afterEach( () => {
		act( () => root.unmount() );
		container.remove();
	} );

	test( 'uses service voice contracts and revokes each sequential URL', async () => {
		await act( async () => {
			root.render( <SpeechHarness /> );
		} );
		await act( async () => {
			container.querySelector( '[data-speak]' ).click();
		} );

		const synthesisCalls = apiFetch.mock.calls
			.map( ( [ request ] ) => request )
			.filter( ( request ) => request.path.endsWith( '/synthesis' ) );
		expect( synthesisCalls ).toHaveLength( 2 );
		expect( synthesisCalls[ 0 ].data ).toMatchObject( {
			language: 'en-US',
			mime_type: 'audio/mpeg',
			session_id: 'session-7',
			speed: 1.25,
			voice: 'voice-1',
		} );
		expect( synthesisCalls[ 0 ].data ).not.toHaveProperty( 'voice_id' );
		expect( createdAudio ).toHaveLength( 2 );
		expect( global.URL.revokeObjectURL ).toHaveBeenCalledTimes( 2 );
		expect( container.querySelector( '[data-status]' ).textContent ).toBe(
			'idle'
		);
	} );
} );
