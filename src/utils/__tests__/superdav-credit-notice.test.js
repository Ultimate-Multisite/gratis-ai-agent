/**
 * Unit tests for Superdav managed-credit account notices.
 */

import {
	buildSuperdavCreditNoticeMessage,
	getSuperdavAccountConnectUrl,
	isSuperdavCreditBalanceNotice,
} from '../superdav-credit-notice';

jest.mock( '@wordpress/i18n', () => ( {
	__: ( str ) => str,
} ) );

describe( 'isSuperdavCreditBalanceNotice', () => {
	test( 'detects the managed Superdav 402 credit message', () => {
		expect(
			isSuperdavCreditBalanceNotice(
				'Client error (402): Request was rejected due to client-side issue - Superdav credit balance is insufficient for this request. Add payment information and purchase more credit to continue.'
			)
		).toBe( true );
	} );

	test( 'ignores unrelated errors', () => {
		expect(
			isSuperdavCreditBalanceNotice( 'Error: Request timed out.' )
		).toBe( false );
	} );
} );

describe( 'getSuperdavAccountConnectUrl', () => {
	test( 'returns the provider account-connect URL', () => {
		expect(
			getSuperdavAccountConnectUrl( [
				{
					id: 'sd-ai-agent-cloud',
					status: {
						account_connect_url:
							'https://account.example.test/magic-login',
					},
				},
			] )
		).toBe( 'https://account.example.test/magic-login' );
	} );

	test( 'rejects non-http account URLs', () => {
		expect(
			getSuperdavAccountConnectUrl( [
				{
					id: 'sd-ai-agent-cloud',
					status: { account_connect_url: 'javascript:alert(1)' },
				},
			] )
		).toBe( '' );
	} );
} );

describe( 'buildSuperdavCreditNoticeMessage', () => {
	test( 'builds friendly copy and a payment CTA', () => {
		const message = buildSuperdavCreditNoticeMessage( [
			{
				id: 'sd-ai-agent-cloud',
				status: {
					account_connect_url: 'https://account.example.test/login',
				},
			},
		] );

		expect( message.role ).toBe( 'system' );
		expect( message.notice[ 1 ] ).toBe(
			'https://account.example.test/login'
		);
		expect( message.notice[ 0 ] ).toContain(
			'Purchase more credits in your account settings'
		);
		expect( message.notice[ 0 ] ).not.toMatch(
			/\b(error|rejected|insufficient)\b/i
		);
	} );
} );
