/**
 * Tests for floating-widget composer controls.
 */

import { createElement } from '@wordpress/element';
import { createRoot } from 'react-dom/client';
import { act } from 'react';

import useChatComposer from '../../chat-composer/use-chat-composer';
import WidgetInput from '../widget-input';

global.IS_REACT_ACT_ENVIRONMENT = true;

jest.mock( '../../chat-composer/use-chat-composer', () => ( {
	__esModule: true,
	default: jest.fn(),
	CHAT_ATTACHMENT_ACCEPT: 'text/plain',
} ) );

jest.mock( '../../slash-command-menu', () => () => null );
jest.mock( '../../feedback-consent-modal', () => () => null );
jest.mock( '../../chat-redesign/ModelPicker', () => () => null );
jest.mock( '../../chat-redesign/AgentPicker', () => () => null );
jest.mock( '../editor-selection-status', () => () => (
	<div data-testid="editor-selection-status" />
) );
jest.mock( '../../chat-redesign/icons', () => ( {
	Paperclip: () => null,
	Microphone: () => null,
	Stop: () => null,
} ) );

jest.mock( '@wordpress/i18n', () => ( {
	__: ( text ) => text,
	sprintf: ( template, value ) => template.replace( '%d', value ),
} ) );

/**
 * Build the hook shape used by the widget presentation.
 *
 * @param {Object} overrides Hook overrides.
 * @return {Object} Composer hook result.
 */
function composerState( overrides = {} ) {
	return {
		text: 'Queue this',
		setText: jest.fn(),
		showSlash: false,
		setShowSlash: jest.fn(),
		attachments: [],
		attachmentError: '',
		isDragOver: false,
		setIsDragOver: jest.fn(),
		feedbackModal: { isOpen: false },
		textareaRef: { current: null },
		fileInputRef: { current: null },
		sending: true,
		queueCount: 0,
		currentSessionId: 1,
		isListening: false,
		micSupported: false,
		canSend: true,
		placeholder: 'Type to queue a message…',
		processFiles: jest.fn(),
		removeAttachment: jest.fn(),
		handleSend: jest.fn(),
		handleSlashSelect: jest.fn(),
		handleKeyDown: jest.fn(),
		handlePaste: jest.fn(),
		handleDrop: jest.fn(),
		handleFrameMouseDown: jest.fn(),
		stopGeneration: jest.fn(),
		closeFeedbackModal: jest.fn(),
		...overrides,
	};
}

describe( 'WidgetInput', () => {
	test( 'places editor context immediately above the composer frame', async () => {
		useChatComposer.mockReturnValue( composerState( { sending: false } ) );
		const container = document.createElement( 'div' );
		const root = createRoot( container );
		await act( async () => root.render( createElement( WidgetInput ) ) );

		const context = container.querySelector(
			'[data-testid="editor-selection-status"]'
		);
		const frame = container.querySelector( '.sdaa-w-input-frame' );
		expect( context ).not.toBeNull();
		expect( context.nextElementSibling ).toBe( frame );

		await act( async () => root.unmount() );
	} );

	test( 'keeps pointer controls for queue and stop while sending', async () => {
		const composer = composerState();
		useChatComposer.mockReturnValue( composer );
		const container = document.createElement( 'div' );
		document.body.appendChild( container );
		const root = createRoot( container );
		await act( async () => root.render( createElement( WidgetInput ) ) );

		const queue = container.querySelector( '[aria-label="Queue message"]' );
		const stop = container.querySelector(
			'[aria-label="Stop generation"]'
		);
		expect( queue ).not.toBeNull();
		expect( stop ).not.toBeNull();

		await act( async () => {
			queue.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
			stop.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
		} );
		expect( composer.handleSend ).toHaveBeenCalledTimes( 1 );
		expect( composer.stopGeneration ).toHaveBeenCalledTimes( 1 );

		await act( async () => root.unmount() );
		container.remove();
	} );
} );
