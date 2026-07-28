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

jest.mock( '@wordpress/components', () => {
	const React = require( 'react' );

	return {
		Button: ( { children, href, onClick, type = 'button', disabled } ) =>
			href
				? React.createElement( 'a', { href, onClick }, children )
				: React.createElement(
						'button',
						{ type, disabled, onClick },
						children
				  ),
		Notice: ( { children } ) =>
			React.createElement( 'div', null, children ),
		Spinner: () => React.createElement( 'div', { role: 'status' } ),
		TextControl: ( { label, value, onChange, type = 'text', disabled } ) =>
			React.createElement(
				'label',
				null,
				label,
				React.createElement( 'input', {
					value,
					type,
					disabled,
					onChange: ( event ) => onChange( event.target.value ),
				} )
			),
	};
} );

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

	/**
	 * Set a controlled input value through React's native event bridge.
	 *
	 * @param {HTMLInputElement} input Input element.
	 * @param {string}           value Input value.
	 */
	async function setInputValue( input, value ) {
		const setter = Object.getOwnPropertyDescriptor(
			window.HTMLInputElement.prototype,
			'value'
		).set;

		await act( async () => {
			setter.call( input, value );
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
	}

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

	test( 'renders dedicated billing actions with their service-issued URLs', async () => {
		apiFetch.mockResolvedValueOnce( {
			configured: true,
			purchase_credits_url: 'https://account.example/credits/purchase',
			payment_methods_url:
				'https://account.example/billing/payment-methods',
		} );

		await act( async () => {
			root.render( createElement( SuperdavAccountManager, {} ) );
		} );
		await act( async () => {
			await Promise.resolve();
		} );

		expect(
			container.querySelector(
				'a[href="https://account.example/credits/purchase"]'
			)
		).not.toBeNull();
		expect(
			container.querySelector(
				'a[href="https://account.example/billing/payment-methods"]'
			)
		).not.toBeNull();
	} );

	test( 'redeems a coupon, disables submission while pending, and updates the balance', async () => {
		let resolveRedemption;
		apiFetch
			.mockResolvedValueOnce( {
				configured: true,
				wallet: { total_usd_micros: 1000000 },
			} )
			.mockImplementationOnce(
				() =>
					new Promise( ( resolve ) => {
						resolveRedemption = resolve;
					} )
			);

		await act( async () => {
			root.render( createElement( SuperdavAccountManager, {} ) );
		} );
		await act( async () => {
			await Promise.resolve();
		} );

		const input = container.querySelector( 'input' );
		await setInputValue( input, ' test-coupon-code ' );
		await act( async () => {
			container
				.querySelector( '.sd-ai-agent-superdav-coupon-redemption' )
				.dispatchEvent(
					new Event( 'submit', { bubbles: true, cancelable: true } )
				);
		} );

		expect( input.disabled ).toBe( true );
		expect(
			container.querySelector( 'button[type="submit"]' ).disabled
		).toBe( true );

		await act( async () => {
			resolveRedemption( {
				configured: true,
				wallet: { total_usd_micros: 6000000 },
			} );
			await Promise.resolve();
		} );

		expect( input.value ).toBe( '' );
		expect( container.textContent ).toContain(
			'Coupon redeemed. Your balance has been updated.'
		);
		expect( container.textContent ).toContain( '$6.00' );
		expect( apiFetch ).toHaveBeenLastCalledWith( {
			path: '/sd-ai-agent/v1/superdav-account/redeem-coupon',
			method: 'POST',
			data: { coupon_code: 'test-coupon-code' },
		} );
	} );

	test( 'clears a failed coupon and renders only its stable error message', async () => {
		apiFetch
			.mockResolvedValueOnce( { configured: true } )
			.mockRejectedValueOnce( {
				code: 'sd_ai_agent_coupon_expired',
				message: 'test-coupon-code must not be rendered',
			} );

		await act( async () => {
			root.render( createElement( SuperdavAccountManager, {} ) );
		} );
		await act( async () => {
			await Promise.resolve();
		} );

		const input = container.querySelector( 'input' );
		await setInputValue( input, 'test-coupon-code' );
		await act( async () => {
			container
				.querySelector( '.sd-ai-agent-superdav-coupon-redemption' )
				.dispatchEvent(
					new Event( 'submit', { bubbles: true, cancelable: true } )
				);
			await Promise.resolve();
		} );

		expect( input.value ).toBe( '' );
		expect( container.textContent ).toContain( 'The coupon has expired.' );
		expect( container.textContent ).not.toContain(
			'test-coupon-code must not be rendered'
		);
	} );
} );
