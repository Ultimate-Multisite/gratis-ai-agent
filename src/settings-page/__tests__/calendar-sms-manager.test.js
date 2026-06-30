/**
 * Unit tests for settings-page/calendar-sms-manager.js.
 */

import { createElement } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

import CalendarSmsManager from '../calendar-sms-manager';

global.IS_REACT_ACT_ENVIRONMENT = true;

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const routeResponses = {
	'/sd-ai-agent/v1/settings/google-calendar': {
		has_credentials: true,
		default_calendar_id: 'primary',
	},
	'/sd-ai-agent/v1/settings/sms-provider': {
		configured: true,
		has_api_key: true,
		api_base_url: 'https://api.textbee.dev',
		device_id_redacted: '********1234',
	},
	'/sd-ai-agent/v1/settings/contact-mappings': {
		contacts: [
			{
				id: 7,
				attendee_email: 'attendee@example.com',
				phone_e164: '+15551234567',
				sms_consent: true,
				display_name: 'Attendee Example',
			},
		],
	},
	'/sd-ai-agent/v1/automations': [
		{
			id: 11,
			name: 'Calendar SMS reminders',
			prompt: 'Send calendar SMS reminders',
			enabled: true,
			last_run_at: '2026-06-29 08:00:00',
		},
	],
	'/sd-ai-agent/v1/automation-approvals?status=pending&limit=25': [],
	'/sd-ai-agent/v1/calendar-reminder-records?limit=25': [
		{
			id: 19,
			updated_at: '2026-06-29 08:05:00',
			attendee_email: 'attendee@example.com',
			status: 'sent',
			skip_reason: '',
		},
	],
};

describe( 'CalendarSmsManager', () => {
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
		apiFetch.mockImplementation( ( options ) => {
			if (
				Object.prototype.hasOwnProperty.call(
					routeResponses,
					options.path
				)
			) {
				return Promise.resolve( routeResponses[ options.path ] );
			}

			return Promise.reject(
				new Error( `Unexpected path: ${ options.path }` )
			);
		} );
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

	test( 'renders setup status, contact mapping, and reminder history', async () => {
		await act( async () => {
			root.render( createElement( CalendarSmsManager, {} ) );
		} );
		await act( async () => {
			await Promise.resolve();
		} );

		expect( container.textContent ).toContain(
			'Google Calendar: Configured'
		);
		expect( container.textContent ).toContain( 'TextBee SMS: Configured' );
		expect( container.textContent ).toContain( 'attendee@example.com' );
		expect( container.textContent ).toContain( '2026-06-29 08:05:00' );
	} );

	test( 'renders legacy raw-array contact mappings', async () => {
		apiFetch.mockImplementation( ( options ) => {
			if (
				options.path === '/sd-ai-agent/v1/settings/contact-mappings'
			) {
				return Promise.resolve( [
					{
						id: 8,
						attendee_email: 'legacy@example.com',
						phone_e164: '+15557654321',
						sms_consent: true,
						display_name: 'Legacy Example',
					},
				] );
			}

			if (
				Object.prototype.hasOwnProperty.call(
					routeResponses,
					options.path
				)
			) {
				return Promise.resolve( routeResponses[ options.path ] );
			}

			return Promise.reject(
				new Error( `Unexpected path: ${ options.path }` )
			);
		} );

		await act( async () => {
			root.render( createElement( CalendarSmsManager, {} ) );
		} );
		await act( async () => {
			await Promise.resolve();
		} );

		expect( container.textContent ).toContain( 'legacy@example.com' );
		expect( container.textContent ).toContain( '+15557654321' );
	} );
} );
