/**
 * Tests for fallback agent-status rotation.
 */

import { createElement } from '@wordpress/element';
import { createRoot } from 'react-dom/client';
import { act } from 'react';

import useRunningStatus, {
	RUNNING_STATUS_ROTATION_INTERVAL,
} from '../use-running-status';

global.IS_REACT_ACT_ENVIRONMENT = true;

jest.mock( '@wordpress/i18n', () => ( {
	__: ( text ) => text,
} ) );

/**
 * Render the currently rotated fallback status.
 *
 * @param {Object}  root0
 * @param {boolean} root0.isRunning Whether fallback rotation is active.
 * @return {JSX.Element} Status probe.
 */
function StatusProbe( { isRunning } ) {
	return <output>{ useRunningStatus( isRunning ) }</output>;
}

describe( 'useRunningStatus', () => {
	let container;
	let root;

	beforeEach( () => {
		jest.useFakeTimers();
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
	} );

	afterEach( async () => {
		await act( async () => {
			root.unmount();
		} );
		document.body.removeChild( container );
		jest.useRealTimers();
	} );

	test( 'starts precisely, advances in order, and resets for the next request', async () => {
		await act( async () => {
			root.render( createElement( StatusProbe, { isRunning: true } ) );
		} );
		expect( container.textContent ).toBe( 'Thinking…' );

		await act( async () => {
			jest.advanceTimersByTime( RUNNING_STATUS_ROTATION_INTERVAL );
		} );
		expect( container.textContent ).toBe( 'Working…' );

		await act( async () => {
			root.render( createElement( StatusProbe, { isRunning: false } ) );
		} );
		await act( async () => {
			root.render( createElement( StatusProbe, { isRunning: true } ) );
		} );
		expect( container.textContent ).toBe( 'Thinking…' );
	} );
} );
