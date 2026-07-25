/**
 * Unit tests for settings-page/superdav-account-manager.js.
 */

import { createElement } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

import SuperdavAccountManager, {
	formatCreditActivityDate,
	formatCreditActivityType,
	formatWalletAmount,
} from '../superdav-account-manager';

global.IS_REACT_ACT_ENVIRONMENT = true;

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

describe( 'SuperdavAccountManager', () => {
	let createRoot;
	let act;
	let container;
	let root;

	beforeAll( () => {
		// eslint-disable-next-line global-require
		( { createRoot } = require( 'react-dom/client' ) );
		// eslint-disable-next-line global-require
		( { act } = require( 'react' ) );
	} );

	beforeEach( () => {
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
	} );

	afterEach( () => {
		act( () => {
			root.unmount();
		} );
		document.body.removeChild( container );
		apiFetch.mockReset();
	} );

	test( 'treats absent wallet amounts as unknown', () => {
		expect( formatWalletAmount( null ) ).toBe( '—' );
		expect( formatWalletAmount( undefined ) ).toBe( '—' );
		expect( formatWalletAmount( '' ) ).toBe( '—' );
		expect( formatWalletAmount( 0 ) ).not.toBe( '—' );
	} );

	test( 'formats safe activity states and unavailable timestamps', () => {
		expect( formatCreditActivityType( 'promotion' ) ).toBe(
			'Promotional credit'
		);
		expect( formatCreditActivityType( 'unknown' ) ).toBe(
			'Credit activity'
		);
		expect( formatCreditActivityDate( 'invalid', 'UTC' ) ).toBe(
			'Unavailable'
		);
	} );

	test( 'does not show a disconnected warning after a failed request', async () => {
		apiFetch.mockRejectedValue( new Error( 'Account request failed.' ) );

		await act( async () => {
			root.render( createElement( SuperdavAccountManager, {} ) );
		} );
		await act( async () => {
			await Promise.resolve();
		} );

		expect( container.textContent ).toContain( 'Account request failed.' );
		expect( container.textContent ).not.toContain(
			'Superdav AI is not connected for this site yet.'
		);
	} );

	test( 'shows a disconnected warning only after a successful response', async () => {
		apiFetch.mockResolvedValue( { configured: false } );

		await act( async () => {
			root.render( createElement( SuperdavAccountManager, {} ) );
		} );
		await act( async () => {
			await Promise.resolve();
		} );

		expect( container.textContent ).toContain(
			'Superdav AI is not connected for this site yet.'
		);
		expect(
			container.querySelector( '.sd-ai-agent-superdav-account' )
		).not.toBeNull();
	} );

	test( 'renders credit activity and labels missing promotional expiry as unavailable', async () => {
		apiFetch.mockResolvedValue( {
			configured: true,
			site_timezone: 'UTC',
			wallet: { total_usd_micros: 1000000 },
			credit_activity: [
				{
					type: 'promotion',
					amount_usd_micros: 1000000,
					effective_at: '2026-07-16T00:00:00+00:00',
					label: 'Welcome coupon',
				},
			],
		} );

		await act( async () => {
			root.render( createElement( SuperdavAccountManager, {} ) );
		} );
		await act( async () => {
			await Promise.resolve();
		} );

		expect( container.textContent ).toContain( 'Promotional credit' );
		expect( container.textContent ).toContain( 'Welcome coupon' );
		expect( container.textContent ).toContain( 'Expiry: Unavailable' );
	} );

	test( 'renders an explicit empty credit activity state', async () => {
		apiFetch.mockResolvedValueOnce( {
			configured: true,
			credit_activity: [],
		} );

		await act( async () => {
			root.render( createElement( SuperdavAccountManager, {} ) );
		} );
		await act( async () => {
			await Promise.resolve();
		} );

		expect( container.textContent ).toContain(
			'No recent credit activity is available.'
		);
	} );
} );
