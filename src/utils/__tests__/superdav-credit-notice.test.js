/**
 * Unit tests for Superdav managed-credit account notices.
 */

import {
	buildSuperdavCreditNoticeMessage,
	getSuperdavAccountActionUrl,
	isSuperdavCreditBalanceNotice,
} from '../superdav-credit-notice';

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

describe( 'getSuperdavAccountActionUrl', () => {
	test( 'prefers the provider credit-purchase URL', () => {
		expect(
			getSuperdavAccountActionUrl( [
				{
					id: 'sd-ai-agent-cloud',
					status: {
						purchase_credits_url:
							'https://account.example.test/credits',
						account_connect_url:
							'https://account.example.test/magic-login',
					},
				},
			] )
		).toBe( 'https://account.example.test/credits' );
	} );

	test( 'falls back to the localized absolute settings URL', () => {
		expect(
			getSuperdavAccountActionUrl(
				[
					{
						id: 'sd-ai-agent-cloud',
						status: {
							account_connect_url: 'javascript:alert(1)',
						},
					},
				],
				{
					settingsPageUrl:
						'https://site.example.test/wp-admin/admin.php?page=sd-ai-agent#/settings',
				}
			)
		).toBe(
			'https://site.example.test/wp-admin/admin.php?page=sd-ai-agent#/settings'
		);
	} );
} );

describe( 'buildSuperdavCreditNoticeMessage', () => {
	test( 'builds a semantic payment action', () => {
		const message = buildSuperdavCreditNoticeMessage( [
			{
				id: 'sd-ai-agent-cloud',
				status: {
					account_connect_url: 'https://account.example.test/login',
				},
			},
		] );

		expect( message ).toEqual( {
			role: 'system',
			notice: {
				type: 'account_action',
				reason: 'credit_exhausted',
				action: 'purchase_credits',
				actionUrl: 'https://account.example.test/login',
			},
		} );
	} );
} );
