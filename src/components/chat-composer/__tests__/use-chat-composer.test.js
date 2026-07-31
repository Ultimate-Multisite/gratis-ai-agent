/**
 * Tests for behavior shared by the main and floating chat composers.
 */

import { createElement } from '@wordpress/element';
import { createRoot } from 'react-dom/client';
import { act } from 'react';
import { useDispatch, useSelect } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

import useChatComposer from '../use-chat-composer';

global.IS_REACT_ACT_ENVIRONMENT = true;

jest.mock( '@wordpress/data', () => ( {
	useDispatch: jest.fn(),
	useSelect: jest.fn(),
} ) );

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

jest.mock( '@wordpress/i18n', () => ( {
	__: ( text ) => text,
} ) );

jest.mock( '../../../store', () => 'sd-ai-agent' );

jest.mock( '../../use-speech-recognition', () => () => ( {
	isListening: false,
	isSupported: false,
	toggleListening: jest.fn(),
} ) );

/**
 * Minimal presentation harness for shared composer events.
 *
 * @return {JSX.Element} Test harness.
 */
function ComposerHarness() {
	const composer = useChatComposer( {
		defaultPlaceholder: 'Ask',
		maxTextareaHeight: 140,
	} );
	return (
		<div>
			<textarea
				value={ composer.text }
				onChange={ ( event ) => composer.setText( event.target.value ) }
			/>
			<button type="button" data-send onClick={ composer.handleSend }>
				Send
			</button>
			<button
				type="button"
				data-plan
				onClick={ () =>
					composer.handleSlashSelect( { action: 'plan' } )
				}
			>
				Plan
			</button>
			<button
				type="button"
				data-help
				onClick={ () =>
					composer.handleSlashSelect( { action: 'help' } )
				}
			>
				Help
			</button>
		</div>
	);
}

describe( 'useChatComposer', () => {
	let container;
	let root;
	let dispatchers;

	beforeEach( async () => {
		dispatchers = {
			sendMessage: jest.fn(),
			stopGeneration: jest.fn(),
			clearCurrentSession: jest.fn(),
			compactConversation: jest.fn(),
			exportSession: jest.fn(),
			setShowShortcutsHelp: jest.fn(),
		};
		const selectors = {
			isSending: () => false,
			getMessageQueue: () => [],
			getCurrentSessionId: () => 17,
		};
		useDispatch.mockReturnValue( dispatchers );
		useSelect.mockImplementation( ( callback ) =>
			callback( () => selectors )
		);
		apiFetch.mockResolvedValue( {} );

		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
		await act( async () => {
			root.render( createElement( ComposerHarness ) );
		} );
	} );

	afterEach( async () => {
		await act( async () => root.unmount() );
		container.remove();
		jest.clearAllMocks();
	} );

	test( 'supports durable-plan commands identically on both surfaces', async () => {
		await act( async () => {
			container
				.querySelector( '[data-plan]' )
				.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
		} );
		expect( container.querySelector( 'textarea' ).value ).toBe( '/plan ' );

		await act( async () => {
			const textarea = container.querySelector( 'textarea' );
			const valueSetter = Object.getOwnPropertyDescriptor(
				HTMLTextAreaElement.prototype,
				'value'
			).set;
			valueSetter.call( textarea, '/plan Build a landing page' );
			textarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		await act( async () => {
			container
				.querySelector( '[data-send]' )
				.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
		} );

		expect( dispatchers.sendMessage ).toHaveBeenCalledWith(
			'Build a landing page',
			[],
			{ durablePlan: true }
		);
	} );

	test( 'shares the shortcuts-help command', async () => {
		await act( async () => {
			container
				.querySelector( '[data-help]' )
				.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
		} );

		expect( dispatchers.setShowShortcutsHelp ).toHaveBeenCalledWith( true );
	} );
} );
