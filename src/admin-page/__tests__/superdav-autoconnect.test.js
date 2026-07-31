import {
	buildConnectionNoticeText,
	findSuperdavProvider,
	formatUsdMicros,
	getStarterCreditAmount,
	SUPERDAV_PROVIDER_ID,
} from '../superdav-autoconnect';

jest.mock( '@wordpress/i18n', () => ( {
	__: ( str ) => str,
	sprintf: ( str, ...args ) =>
		args.reduce( ( result, value ) => result.replace( '%s', value ), str ),
} ) );

describe( 'superdav-autoconnect helpers', () => {
	test( 'finds the bundled SD AI provider', () => {
		const provider = { id: SUPERDAV_PROVIDER_ID, name: 'SD AI' };

		expect( findSuperdavProvider( [ { id: 'openai' }, provider ] ) ).toBe(
			provider
		);
	} );

	test( 'formats USD micros without unnecessary cents', () => {
		expect( formatUsdMicros( 10000000 ) ).toBe( '$10' );
		expect( formatUsdMicros( 10500000 ) ).toBe( '$10.50' );
	} );

	test( 'uses wallet promo credit for the free-tier starter amount', () => {
		expect(
			getStarterCreditAmount( {
				wallet: { promo_usd_micros: 10000000 },
			} )
		).toBe( '$10' );
	} );

	test( 'builds a free-tier token-created notice', () => {
		const notice = buildConnectionNoticeText( {
			tier: 'free',
			wallet: { promo_usd_micros: 10000000 },
		} );

		expect( notice ).toContain( 'secure site token' );
		expect( notice ).toContain( 'SD AI is connected' );
		expect( notice ).toContain( 'free tier' );
		expect( notice ).toContain( '$10' );
	} );
} );
