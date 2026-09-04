/**
 * Focused MediaRecorder lifecycle tests.
 */

import { act } from 'react';
import { createRoot } from 'react-dom/client';

import useAudioRecorder from '../use-audio-recorder';

global.IS_REACT_ACT_ENVIRONMENT = true;

let latestRecorder;

class FakeMediaRecorder {
	static isTypeSupported( mimeType ) {
		return mimeType === 'audio/webm;codecs=opus';
	}

	constructor( stream, options ) {
		this.mimeType = options.mimeType;
		this.state = 'inactive';
		this.stream = stream;
		latestRecorder = this;
	}

	start() {
		this.state = 'recording';
	}

	stop() {
		this.state = 'inactive';
		this.onstop?.();
	}
}

/**
 * @param {Object}   root0
 * @param {Function} root0.onComplete
 * @return {JSX.Element} Recorder controls.
 */
function RecorderHarness( { onComplete } ) {
	const recorder = useAudioRecorder( {
		acceptedMimeTypes: [ 'audio/wav' ],
		maxBytes: 100,
		maxDurationMs: 5000,
		onComplete,
	} );
	return (
		<div>
			<span data-status>{ recorder.status }</span>
			<button type="button" data-start onClick={ recorder.start }>
				Start
			</button>
			<button type="button" data-stop onClick={ recorder.stop }>
				Stop
			</button>
			<button type="button" data-cancel onClick={ recorder.cancel }>
				Cancel
			</button>
		</div>
	);
}

describe( 'useAudioRecorder', () => {
	let container;
	let root;
	let stopTrack;

	beforeEach( () => {
		latestRecorder = null;
		stopTrack = jest.fn();
		global.MediaRecorder = FakeMediaRecorder;
		Object.defineProperty( navigator, 'mediaDevices', {
			configurable: true,
			value: {
				getUserMedia: jest.fn().mockResolvedValue( {
					getTracks: () => [ { stop: stopTrack } ],
				} ),
			},
		} );
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
	} );

	afterEach( () => {
		act( () => root.unmount() );
		container.remove();
		delete global.MediaRecorder;
	} );

	test( 'records one bounded turn and releases its media track', async () => {
		const onComplete = jest.fn();
		await act( async () => {
			root.render( <RecorderHarness onComplete={ onComplete } /> );
		} );
		await act( async () => {
			container.querySelector( '[data-start]' ).click();
		} );
		expect( latestRecorder.mimeType ).toBe( 'audio/webm;codecs=opus' );
		expect( container.querySelector( '[data-status]' ).textContent ).toBe(
			'recording'
		);

		act( () => {
			latestRecorder.ondataavailable( {
				data: new Blob( [ 'voice' ], {
					type: latestRecorder.mimeType,
				} ),
			} );
			container.querySelector( '[data-stop]' ).click();
		} );

		expect( stopTrack ).toHaveBeenCalledTimes( 1 );
		expect( onComplete ).toHaveBeenCalledTimes( 1 );
		expect( onComplete.mock.calls[ 0 ][ 0 ].blob.size ).toBe( 5 );
	} );

	test( 'cancels a late permission result without retaining its stream', async () => {
		let resolvePermission;
		navigator.mediaDevices.getUserMedia.mockReturnValue(
			new Promise( ( resolve ) => {
				resolvePermission = resolve;
			} )
		);
		await act( async () => {
			root.render( <RecorderHarness onComplete={ jest.fn() } /> );
		} );
		act( () => container.querySelector( '[data-start]' ).click() );
		act( () => container.querySelector( '[data-cancel]' ).click() );
		await act( async () => {
			resolvePermission( {
				getTracks: () => [ { stop: stopTrack } ],
			} );
		} );

		expect( latestRecorder ).toBeNull();
		expect( stopTrack ).toHaveBeenCalledTimes( 1 );
		expect( container.querySelector( '[data-status]' ).textContent ).toBe(
			'idle'
		);
	} );
} );
