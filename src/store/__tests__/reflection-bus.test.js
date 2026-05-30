/**
 * Unit tests for the frontend live-preview reflection bus
 * (src/store/reflection-bus.js).
 *
 * Covers:
 * - on/off/emit happy path
 * - unsubscribe via the returned closure
 * - listener errors are swallowed (one bad listener does not break others)
 * - clear() drops all listeners
 * - cross-bundle singleton on `window`
 * - __resetReflectionBusForTests() returns a fresh bus
 * - non-function listener is a no-op
 */

import sharedBus, {
	createReflectionBus,
	__resetReflectionBusForTests,
} from '../reflection-bus';

describe( 'reflection-bus', () => {
	let consoleErrorSpy;

	beforeEach( () => {
		consoleErrorSpy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );
		__resetReflectionBusForTests();
	} );

	afterEach( () => {
		consoleErrorSpy.mockRestore();
		__resetReflectionBusForTests();
	} );

	test( 'on() registers a listener that receives emitted events', () => {
		const bus = createReflectionBus();
		const listener = jest.fn();

		bus.on( listener );
		bus.emit( { type: 'tool-applied', tool: 'sd-ai-agent/update-post' } );

		expect( listener ).toHaveBeenCalledTimes( 1 );
		expect( listener ).toHaveBeenCalledWith( {
			type: 'tool-applied',
			tool: 'sd-ai-agent/update-post',
		} );
	} );

	test( 'on() returns an unsubscribe closure', () => {
		const bus = createReflectionBus();
		const listener = jest.fn();

		const unsubscribe = bus.on( listener );
		bus.emit( { type: 'tool-applied' } );
		unsubscribe();
		bus.emit( { type: 'tool-applied' } );

		expect( listener ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'off() removes a previously-registered listener', () => {
		const bus = createReflectionBus();
		const listener = jest.fn();

		bus.on( listener );
		bus.off( listener );
		bus.emit( { type: 'tool-applied' } );

		expect( listener ).not.toHaveBeenCalled();
	} );

	test( 'non-function listener is ignored by on()', () => {
		const bus = createReflectionBus();

		const unsubscribe = bus.on( /** @type {*} */ ( 'not a function' ) );

		expect( bus.listenerCount() ).toBe( 0 );
		expect( typeof unsubscribe ).toBe( 'function' );
		// Unsubscribe of an ignored listener must not throw.
		expect( () => unsubscribe() ).not.toThrow();
	} );

	test( 'a throwing listener does not break sibling listeners', () => {
		const bus = createReflectionBus();
		const goodBefore = jest.fn();
		const bad = jest.fn( () => {
			throw new Error( 'boom' );
		} );
		const goodAfter = jest.fn();

		bus.on( goodBefore );
		bus.on( bad );
		bus.on( goodAfter );
		bus.emit( { type: 'tool-applied' } );

		expect( goodBefore ).toHaveBeenCalledTimes( 1 );
		expect( bad ).toHaveBeenCalledTimes( 1 );
		expect( goodAfter ).toHaveBeenCalledTimes( 1 );
		expect( consoleErrorSpy ).toHaveBeenCalledWith(
			'[sd-ai-agent] reflection listener threw:',
			expect.any( Error )
		);
	} );

	test( 'clear() removes all listeners', () => {
		const bus = createReflectionBus();
		const a = jest.fn();
		const b = jest.fn();

		bus.on( a );
		bus.on( b );
		expect( bus.listenerCount() ).toBe( 2 );

		bus.clear();
		expect( bus.listenerCount() ).toBe( 0 );

		bus.emit( { type: 'tool-applied' } );
		expect( a ).not.toHaveBeenCalled();
		expect( b ).not.toHaveBeenCalled();
	} );

	test( 'default export is a singleton across imports', () => {
		const listener = jest.fn();
		sharedBus.on( listener );

		// Re-import inside an isolated module registry. The module re-runs
		// resolveBus() but finds window[WIN_KEY] populated, so it must hand
		// back the SAME instance the top-level import resolved earlier.
		let reimported;
		jest.isolateModules( () => {
			// eslint-disable-next-line global-require
			reimported = require( '../reflection-bus' ).default;
		} );

		expect( reimported ).toBe( sharedBus );
		reimported.emit( { type: 'tool-applied' } );
		expect( listener ).toHaveBeenCalledTimes( 1 );
	} );

	test( '__resetReflectionBusForTests() clears listeners in-place on the shared bus', () => {
		sharedBus.on( jest.fn() );
		expect( sharedBus.listenerCount() ).toBe( 1 );

		const returned = __resetReflectionBusForTests();

		// The shared instance must be preserved so module-level captures
		// stay valid; only its listener set is cleared.
		expect( returned ).toBe( sharedBus );
		expect( sharedBus.listenerCount() ).toBe( 0 );
	} );
} );
