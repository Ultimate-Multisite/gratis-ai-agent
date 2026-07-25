/**
 * Unit tests for settings-page/superdav-account-manager.js.
 */

import { createElement } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

import SuperdavAccountManager, {
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

	test( 'uses dedicated service URLs for credit and payment actions', async () => {
		apiFetch.mockResolvedValue( {
			configured: true,
			account_portal_url: 'https://account.example/portal',
			purchase_credits_url: 'https://account.example/credits',
			payment_methods_url: 'https://account.example/payment-methods',
		} );

		await act( async () => {
			root.render( createElement( SuperdavAccountManager, {} ) );
		} );
		await act( async () => {
			await Promise.resolve();
		} );

		expect(
			container.querySelector(
				'a[href="https://account.example/credits"]'
			).textContent
		).toBe( 'Add credits' );
		expect(
			container.querySelector(
				'a[href="https://account.example/payment-methods"]'
			).textContent
		).toBe( 'Manage payment methods' );
		expect(
			container.querySelector(
				'a[href="https://account.example/portal"]'
			).textContent
		).toBe( 'Open account portal' );
	} );

	test( 'renders only the generic portal fallback when dedicated URLs are absent', async () => {
		apiFetch.mockResolvedValue( {
			configured: true,
			account_portal_url: 'https://account.example/portal',
		} );

		await act( async () => {
			root.render( createElement( SuperdavAccountManager, {} ) );
		} );
		await act( async () => {
			await Promise.resolve();
		} );

		expect( container.textContent ).not.toContain( 'Add credits' );
		expect( container.textContent ).not.toContain(
			'Manage payment methods'
		);
		expect(
			container.querySelector(
				'a[href="https://account.example/portal"]'
			).textContent
		).toBe( 'Open account portal' );
	} );
} );
