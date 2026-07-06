import { getChatUiMode, isCustomerSimpleMode } from '../chat-ui-mode';

describe( 'chat UI mode helpers', () => {
	afterEach( () => {
		delete window.sdAiAgentData;
	} );

	test( 'defaults to admin mode', () => {
		expect( getChatUiMode( {} ) ).toBe( 'admin' );
		expect( isCustomerSimpleMode( 'admin' ) ).toBe( false );
	} );

	test( 'normalizes explicit customer/public simple modes', () => {
		expect( getChatUiMode( { uiMode: 'customer-simple' } ) ).toBe(
			'customer_simple'
		);
		expect( isCustomerSimpleMode( { chat_ui_mode: 'public_docs' } ) ).toBe(
			true
		);
		expect( isCustomerSimpleMode( { embed: { uiMode: 'simple' } } ) ).toBe(
			true
		);
	} );

	test( 'supports localized window data and public chat boolean flags', () => {
		window.sdAiAgentData = { publicChat: true };

		expect( getChatUiMode() ).toBe( 'public_chat' );
		expect( isCustomerSimpleMode( window.sdAiAgentData ) ).toBe( true );
	} );
} );
