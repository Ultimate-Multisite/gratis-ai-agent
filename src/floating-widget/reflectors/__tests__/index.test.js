jest.mock( '../../../store/reflection-bus', () => ( {
	__esModule: true,
	default: { on: jest.fn() },
} ) );
jest.mock( '../fallback-toast', () => ( {
	showFallbackToast: jest.fn(),
} ) );
jest.mock( '../global-styles', () => ( {
	reflectGlobalStyles: jest.fn(),
} ) );
jest.mock( '../menu', () => ( {
	reflectMenu: jest.fn(),
} ) );
jest.mock( '../post', () => ( {
	reflectPost: jest.fn(),
} ) );
jest.mock( '../editor-post', () => ( {
	reflectEditorPost: jest.fn(),
} ) );

import { showFallbackToast } from '../fallback-toast';
import { reflectGlobalStyles } from '../global-styles';
import { reflectMenu } from '../menu';
import { reflectPost } from '../post';
import { reflectEditorPost } from '../editor-post';
import bus from '../../../store/reflection-bus';
import { dispatchReflectionEvent } from '..';

const makeEvent = ( kind ) => ( {
	type: 'tool-applied',
	affected: { kind },
} );

describe( 'reflector dispatcher', () => {
	beforeEach( () => {
		showFallbackToast.mockClear();
		reflectGlobalStyles.mockClear();
		reflectMenu.mockClear();
		reflectPost.mockClear();
		reflectEditorPost.mockClear();
	} );

	test( 'subscribes the deferred dispatcher to the reflection bus', () => {
		expect( bus.on ).toHaveBeenCalledWith( dispatchReflectionEvent );
	} );

	test.each( [
		[ 'post', reflectPost ],
		[ 'global_styles', reflectGlobalStyles ],
		[ 'menu', reflectMenu ],
	] )( 'loads and runs the %s reflector', async ( kind, reflector ) => {
		const event = makeEvent( kind );

		await dispatchReflectionEvent( event );

		expect( reflector ).toHaveBeenCalledWith( event );
		expect( showFallbackToast ).not.toHaveBeenCalled();
	} );

	test( 'runs the editor post reflector before public post reflection', async () => {
		const event = makeEvent( 'post' );
		const callOrder = [];
		reflectEditorPost.mockImplementation( () =>
			callOrder.push( 'editor' )
		);
		reflectPost.mockImplementation( () => callOrder.push( 'public' ) );

		await dispatchReflectionEvent( event );

		expect( callOrder ).toEqual( [ 'editor', 'public' ] );
	} );

	test( 'uses the fallback for an unknown affected kind', () => {
		const event = makeEvent( 'unknown' );

		dispatchReflectionEvent( event );

		expect( showFallbackToast ).toHaveBeenCalledWith( event );
	} );

	test( 'uses the fallback when a deferred reflector rejects', async () => {
		const event = makeEvent( 'post' );
		reflectPost.mockRejectedValueOnce( new Error( 'reflection failed' ) );

		await dispatchReflectionEvent( event );

		expect( showFallbackToast ).toHaveBeenCalledWith( event );
	} );

	test( 'runs public reflection when the editor reflector module fails to load', async () => {
		jest.resetModules();
		jest.doMock( '../editor-post', () => {
			throw new Error( 'editor chunk unavailable' );
		} );
		const { reflectPost: reloadedReflectPost } = require( '../post' );
		const {
			dispatchReflectionEvent: reloadedDispatchReflectionEvent,
		} = require( '..' );
		const event = makeEvent( 'post' );

		await reloadedDispatchReflectionEvent( event );

		expect( reloadedReflectPost ).toHaveBeenCalledWith( event );
	} );
} );
