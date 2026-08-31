/**
 * Unit tests for settings-page/superdav-account-manager.js.
 */

import { createElement } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

import SuperdavAccountManager, {
	formatCreditActivityDate,
	formatCreditActivityType,
	formatManagedModelName,
	formatTokenCount,
	formatUsageCost,
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
		Modal: ( { children, title } ) =>
			React.createElement(
				'div',
				{ role: 'dialog', 'aria-label': title },
				React.createElement( 'h2', null, title ),
				children
			),
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
		ToggleControl: ( { label, checked, onChange, disabled } ) =>
			React.createElement(
				'label',
				null,
				label,
				React.createElement( 'input', {
					type: 'checkbox',
					checked,
					disabled,
					onChange: ( event ) => onChange( event.target.checked ),
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

	/**
	 * Find a rendered button by its exact visible label.
	 *
	 * @param {string} label Button label.
	 * @return {HTMLButtonElement|undefined} Matching button.
	 */
	function findButton( label ) {
		return [ ...container.querySelectorAll( 'button' ) ].find(
			( button ) => button.textContent === label
		);
	}

	test( 'treats absent wallet amounts as unknown', () => {
		expect( formatWalletAmount( null ) ).toBe( '—' );
		expect( formatWalletAmount( undefined ) ).toBe( '—' );
		expect( formatWalletAmount( '' ) ).toBe( '—' );
		expect( formatWalletAmount( 0 ) ).not.toBe( '—' );
		expect( formatUsageCost( 1250 ) ).toMatch( /0[.,]00125/ );
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
		expect( formatManagedModelName( 'superdav-chat-pro' ) ).toBe(
			'Standard'
		);
		expect( formatTokenCount( 1234 ) ).toMatch( /1.234|1,234/ );
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
			'SD AI is not connected for this site yet.'
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
			'SD AI is not connected for this site yet.'
		);
		expect(
			container.querySelector( '.sd-ai-agent-superdav-account' )
		).not.toBeNull();
	} );

	test( 'refreshes the balance on page visit and replaces the manual refresh action', async () => {
		apiFetch.mockResolvedValue( { configured: true } );

		await act( async () => {
			root.render( createElement( SuperdavAccountManager, {} ) );
		} );
		await act( async () => {
			await Promise.resolve();
		} );

		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/sd-ai-agent/v1/superdav-account',
			method: 'POST',
		} );
		expect( findButton( 'Redeem Coupon' ) ).toBeDefined();
		expect( container.textContent ).not.toContain( 'Refresh balance' );
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
			'No purchases, promotions, or adjustments are available.'
		);
	} );

	test( 'expands actual usage grouped by chat session and links to the full chat', async () => {
		apiFetch.mockResolvedValue( {
			configured: true,
			site_timezone: 'UTC',
			chat_sessions: [
				{
					session_id: 42,
					title: 'Build the landing page',
					last_used_at: '2026-07-31T10:01:00+00:00',
					input_tokens: 1200,
					cached_input_tokens: 300,
					output_tokens: 400,
					total_tokens: 1600,
					cost_usd_micros: 125000,
					loop_count: 3,
					tool_call_count: 7,
					models: [
						{
							model_id: 'superdav-chat-pro',
							total_tokens: 1400,
							cost_usd_micros: 100000,
							loop_count: 2,
						},
						{
							model_id: 'superdav-chat-fast',
							total_tokens: 200,
							cost_usd_micros: 25000,
							loop_count: 1,
						},
					],
				},
			],
			credit_activity: [],
		} );

		await act( async () => {
			root.render( createElement( SuperdavAccountManager, {} ) );
		} );
		await act( async () => {
			await Promise.resolve();
		} );

		expect( container.textContent ).toContain( 'Build the landing page' );
		expect( container.textContent ).toContain( 'Standard, Speedy' );
		expect( container.textContent ).toContain( '$0.125' );
		expect( container.textContent ).not.toContain( 'Agent loops' );

		await act( async () => {
			container
				.querySelector( '.sd-ai-agent-superdav-session-usage-summary' )
				.click();
		} );

		expect( container.textContent ).toContain( 'Agent loops3' );
		expect( container.textContent ).toContain( 'Tool calls7' );
		expect(
			container.querySelector( 'a[href$="#/chat/42"]' )
		).not.toBeNull();
	} );

	test( 'shows the linked user and mints a fresh URL when an account action is clicked', async () => {
		const portalWindow = {
			location: { assign: jest.fn() },
			close: jest.fn(),
			opener: window,
		};
		window.open = jest.fn( () => portalWindow );
		apiFetch
			.mockResolvedValueOnce( {
				configured: true,
				account_portal_available: true,
				purchase_credits_available: true,
				payment_methods_available: true,
				link_account_available: true,
				linked_user: {
					display_name: 'Verified Customer',
					masked_email: 'v***@example.test',
					email_verified: true,
				},
			} )
			.mockResolvedValueOnce( {
				action: 'account_portal',
				url: 'https://account.example/fresh-account-action',
			} );

		await act( async () => {
			root.render( createElement( SuperdavAccountManager, {} ) );
		} );
		await act( async () => {
			await Promise.resolve();
		} );

		expect( container.textContent ).toContain( 'Verified Customer' );
		expect( container.textContent ).toContain( 'v***@example.test' );
		expect( findButton( 'Link a different user' ) ).toBeDefined();

		const headerActions = container.querySelector(
			'.sd-ai-agent-superdav-account-header-actions'
		);
		expect(
			[ ...headerActions.querySelectorAll( 'a, button' ) ].map(
				( action ) => action.textContent
			)
		).toEqual( [
			'Open account portal',
			'Manage payment methods',
			'Redeem Coupon',
			'Add credits',
		] );

		await act( async () => {
			findButton( 'Open account portal' ).click();
			await Promise.resolve();
		} );

		expect( apiFetch ).toHaveBeenLastCalledWith( {
			path: '/sd-ai-agent/v1/superdav-account/action',
			method: 'POST',
			data: { action: 'account_portal' },
		} );
		expect( portalWindow.opener ).toBeNull();
		expect( portalWindow.location.assign ).toHaveBeenCalledWith(
			'https://account.example/fresh-account-action'
		);
	} );

	test( 'offers to connect the site when no verified user is linked', async () => {
		apiFetch.mockResolvedValue( {
			configured: true,
			link_account_available: true,
			linked_user: null,
		} );

		await act( async () => {
			root.render( createElement( SuperdavAccountManager, {} ) );
		} );
		await act( async () => {
			await Promise.resolve();
		} );

		expect( findButton( 'Connect account to site' ) ).toBeDefined();
		expect( findButton( 'Link a different user' ) ).toBeUndefined();
	} );

	test( 'installs free Advanced without a linked user and enables automatic updates', async () => {
		apiFetch
			.mockResolvedValueOnce( {
				configured: true,
				linked_user: null,
				advanced_plugin: {
					installed: false,
					active: false,
					bundled: false,
					can_manage: true,
					file_mods_allowed: true,
				},
			} )
			.mockResolvedValueOnce( {
				installed: true,
				active: true,
				bundled: false,
				version: '1.22.4',
				auto_updates_enabled: true,
				can_manage: true,
				file_mods_allowed: true,
			} );

		await act( async () => {
			root.render( createElement( SuperdavAccountManager, {} ) );
		} );
		await act( async () => {
			await Promise.resolve();
		} );

		await act( async () => {
			findButton( 'Install and activate' ).click();
			await Promise.resolve();
		} );

		expect( apiFetch ).toHaveBeenLastCalledWith( {
			path: '/sd-ai-agent/v1/superdav-account/advanced-plugin/install',
			method: 'POST',
		} );
		expect( container.textContent ).toContain( 'Active — version 1.22.4' );
		expect( container.textContent ).toContain( 'Automatic updates' );
		expect(
			container.querySelector( 'input[type="checkbox"]' ).checked
		).toBe( true );
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

		await act( async () => {
			findButton( 'Redeem Coupon' ).click();
		} );

		const dialog = container.querySelector( '[role="dialog"]' );
		const input = dialog.querySelector( 'input' );
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

		expect( container.textContent ).toContain(
			'Coupon redeemed. Your balance has been updated.'
		);
		expect( container.textContent ).toContain( '$6.00' );
		expect( container.querySelector( '[role="dialog"]' ) ).toBeNull();
		expect( apiFetch ).toHaveBeenLastCalledWith( {
			path: '/sd-ai-agent/v1/superdav-account/redeem-coupon',
			method: 'POST',
			data: { coupon_code: 'test-coupon-code' },
		} );
	} );

	test( 'keeps a failed coupon available for correction and renders only its stable error message', async () => {
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

		await act( async () => {
			findButton( 'Redeem Coupon' ).click();
		} );

		const input = container.querySelector( '[role="dialog"] input' );
		await setInputValue( input, 'test-coupon-code' );
		await act( async () => {
			container
				.querySelector( '.sd-ai-agent-superdav-coupon-redemption' )
				.dispatchEvent(
					new Event( 'submit', { bubbles: true, cancelable: true } )
				);
			await Promise.resolve();
		} );

		expect( input.value ).toBe( 'test-coupon-code' );
		expect( container.querySelector( '[role="dialog"]' ) ).not.toBeNull();
		expect( container.textContent ).toContain( 'The coupon has expired.' );
		expect( container.textContent ).not.toContain(
			'test-coupon-code must not be rendered'
		);
	} );
} );
