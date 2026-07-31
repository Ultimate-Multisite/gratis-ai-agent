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
	sprintf: ( template, value ) => template.replace( '%s', value ),
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
 * @param {Object}  root0
 * @param {boolean} root0.isSimpleMode Whether simple customer mode is active.
 * @return {JSX.Element} Test harness.
 */
function ComposerHarness( { isSimpleMode = false } ) {
	const composer = useChatComposer( {
		isSimpleMode,
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
			<button
				type="button"
				data-attach
				onClick={ () =>
					composer.processFiles( [
						new File( [ 'brief' ], 'brief.txt', {
							type: 'text/plain',
						} ),
					] )
				}
			>
				Attach
			</button>
			<button
				type="button"
				data-reject
				onClick={ () =>
					composer.processFiles( [
						new File( [ 'binary' ], 'plugin.zip', {
							type: 'application/zip',
						} ),
					] )
				}
			>
				Reject
			</button>
			{ composer.feedbackModal.isOpen && <span data-feedback /> }
			{ composer.attachmentError && (
				<span data-attachment-error>{ composer.attachmentError }</span>
			) }
		</div>
	);
}

/**
 * Update the controlled textarea value through React's input event.
 *
 * @param {HTMLElement} container Composer fixture container.
 * @param {string}      value     New textarea value.
 */
function setTextareaValue( container, value ) {
	const textarea = container.querySelector( 'textarea' );
	const valueSetter = Object.getOwnPropertyDescriptor(
		HTMLTextAreaElement.prototype,
		'value'
	).set;
	valueSetter.call( textarea, value );
	textarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
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
			setTextareaValue( container, '/plan Build a landing page' );
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

	test( 'preserves attachments submitted with a durable plan', async () => {
		await act( async () => {
			container
				.querySelector( '[data-attach]' )
				.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
			await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
		} );
		await act( async () => {
			setTextareaValue( container, '/plan Review this brief' );
		} );
		await act( async () => {
			container
				.querySelector( '[data-send]' )
				.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
		} );

		expect( dispatchers.sendMessage ).toHaveBeenCalledWith(
			'Review this brief',
			[
				expect.objectContaining( {
					name: 'brief.txt',
					type: 'text/plain',
				} ),
			],
			{ durablePlan: true }
		);
	} );

	test( 'does not open issue reporting in simple customer mode', async () => {
		await act( async () => {
			root.render(
				createElement( ComposerHarness, { isSimpleMode: true } )
			);
		} );
		await act( async () => {
			setTextareaValue( container, '/report-issue private details' );
		} );
		await act( async () => {
			container
				.querySelector( '[data-send]' )
				.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
		} );

		expect( container.querySelector( '[data-feedback]' ) ).toBeNull();
		expect( dispatchers.sendMessage ).toHaveBeenCalledWith(
			'/report-issue private details',
			[]
		);
	} );

	test( 'surfaces rejected attachment names', async () => {
		await act( async () => {
			container
				.querySelector( '[data-reject]' )
				.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
			await Promise.resolve();
		} );

		expect(
			container.querySelector( '[data-attachment-error]' ).textContent
		).toContain( 'plugin.zip' );
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
