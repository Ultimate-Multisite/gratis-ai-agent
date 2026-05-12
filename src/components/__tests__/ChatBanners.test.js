/**
 * Unit tests for components/chat-banners.js
 *
 * Tests cover the generic chat-banner host:
 * - Renders nothing while the status fetch is in flight
 * - Renders nothing on apiFetch rejection (endpoint missing / 401)
 * - Renders nothing when the producer list is empty
 * - Renders a single banner with correct severity class and CTA
 * - Renders multiple banners in producer order
 * - Drops banners missing severity or message
 * - Drops banners with an unknown severity value
 * - CTA opens in a new tab with rel="noopener noreferrer"
 * - Skips CTA element when cta_label/cta_url are missing
 */

import { createElement } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

import ChatBanners from '../chat-banners';

global.IS_REACT_ACT_ENVIRONMENT = true;

// Mock @wordpress/api-fetch.
jest.mock( '@wordpress/api-fetch', () => jest.fn() );

/**
 * Configure the apiFetch mock.
 *
 * @param {Object|null} payload Payload to resolve with; if null, apiFetch rejects.
 */
function mockResponse( payload ) {
	apiFetch.mockReset();
	if ( payload === null ) {
		apiFetch.mockReturnValue( Promise.reject( new Error( '404' ) ) );
	} else {
		apiFetch.mockReturnValue( Promise.resolve( payload ) );
	}
}

describe( 'ChatBanners', () => {
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
	} );

	/**
	 * Render the component and flush the apiFetch microtask so the
	 * useEffect resolves and the second render runs.
	 *
	 * @param {Object|null} response Response payload, or null to reject.
	 * @return {Promise<string>} container.innerHTML after settle.
	 */
	async function renderWith( response ) {
		mockResponse( response );
		await act( async () => {
			root.render( createElement( ChatBanners, {} ) );
		} );
		await act( async () => {
			await Promise.resolve();
		} );
		return container.innerHTML;
	}

	test( 'renders nothing on apiFetch rejection', async () => {
		const html = await renderWith( null );
		expect( html ).toBe( '' );
	} );

	test( 'renders nothing when banners array is empty', async () => {
		const html = await renderWith( { banners: [] } );
		expect( html ).toBe( '' );
	} );

	test( 'renders nothing when response shape is unexpected', async () => {
		const html = await renderWith( { unrelated: 'shape' } );
		expect( html ).toBe( '' );
	} );

	test( 'renders a single info banner', async () => {
		const html = await renderWith( {
			banners: [
				{
					id: 'demo-info',
					severity: 'info',
					message: 'Heads up!',
				},
			],
		} );
		expect( html ).toContain( 'sd-ai-agent-chat-banner--info' );
		expect( html ).toContain( 'Heads up!' );
		expect( html ).not.toContain( '<a' );
	} );

	test( 'renders a warning banner with a CTA', async () => {
		const html = await renderWith( {
			banners: [
				{
					id: 'demo-warn',
					severity: 'warning',
					message: 'Approaching limit',
					cta_label: 'Upgrade →',
					cta_url: 'https://example.test/upgrade',
				},
			],
		} );
		expect( html ).toContain( 'sd-ai-agent-chat-banner--warning' );
		expect( html ).toContain( 'Approaching limit' );
		expect( html ).toContain( 'href="https://example.test/upgrade"' );
		expect( html ).toContain( 'target="_blank"' );
		expect( html ).toContain( 'rel="noopener noreferrer"' );
		expect( html ).toContain( 'Upgrade →' );
	} );

	test( 'renders an error banner with role="alert"', async () => {
		const html = await renderWith( {
			banners: [
				{
					id: 'demo-error',
					severity: 'error',
					message: 'Blocked',
				},
			],
		} );
		expect( html ).toContain( 'sd-ai-agent-chat-banner--error' );
		expect( html ).toContain( 'role="alert"' );
	} );

	test( 'renders multiple banners in order', async () => {
		const html = await renderWith( {
			banners: [
				{ id: 'a', severity: 'info', message: 'First' },
				{ id: 'b', severity: 'warning', message: 'Second' },
				{ id: 'c', severity: 'error', message: 'Third' },
			],
		} );
		expect( html.indexOf( 'First' ) ).toBeGreaterThan( -1 );
		expect( html.indexOf( 'Second' ) ).toBeGreaterThan(
			html.indexOf( 'First' )
		);
		expect( html.indexOf( 'Third' ) ).toBeGreaterThan(
			html.indexOf( 'Second' )
		);
	} );

	test( 'drops banners missing severity', async () => {
		const html = await renderWith( {
			banners: [
				{ id: 'no-severity', message: 'I should not render' },
				{ id: 'ok', severity: 'info', message: 'Visible' },
			],
		} );
		expect( html ).not.toContain( 'I should not render' );
		expect( html ).toContain( 'Visible' );
	} );

	test( 'drops banners missing message', async () => {
		const html = await renderWith( {
			banners: [
				{ id: 'no-msg', severity: 'warning' },
				{ id: 'ok', severity: 'info', message: 'Visible' },
			],
		} );
		expect( html ).toContain( 'Visible' );
		// No second banner element rendered.
		expect( html.match( /sd-ai-agent-chat-banner--/g ) ).toHaveLength( 1 );
	} );

	test( 'drops banners with an unknown severity', async () => {
		const html = await renderWith( {
			banners: [
				{
					id: 'bad-severity',
					severity: 'critical',
					message: 'Should not render',
				},
				{ id: 'ok', severity: 'info', message: 'Visible' },
			],
		} );
		expect( html ).not.toContain( 'Should not render' );
		expect( html ).toContain( 'Visible' );
	} );

	test( 'omits CTA when only one of label/url is set', async () => {
		const htmlNoUrl = await renderWith( {
			banners: [
				{
					id: 'no-url',
					severity: 'info',
					message: 'Hi',
					cta_label: 'Click',
				},
			],
		} );
		expect( htmlNoUrl ).not.toContain( '<a' );

		const htmlNoLabel = await renderWith( {
			banners: [
				{
					id: 'no-label',
					severity: 'info',
					message: 'Hi',
					cta_url: 'https://example.test/',
				},
			],
		} );
		expect( htmlNoLabel ).not.toContain( '<a' );
	} );
} );
