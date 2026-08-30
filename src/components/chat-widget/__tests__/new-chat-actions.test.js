/**
 * Regression coverage for floating-widget new-chat entry points.
 */

import { createElement } from '@wordpress/element';
import { createRoot } from 'react-dom/client';
import { act } from 'react';
import { useDispatch, useSelect } from '@wordpress/data';

import WidgetHeader from '../widget-header';
import WidgetInput from '../widget-input';

global.IS_REACT_ACT_ENVIRONMENT = true;

jest.mock( '@wordpress/data', () => ( {
	useDispatch: jest.fn(),
	useSelect: jest.fn(),
} ) );

jest.mock( '@wordpress/i18n', () => ( {
	__: ( text ) => text,
	sprintf: ( format, value ) => format.replace( '%d', value ),
} ) );

jest.mock( '@wordpress/icons', () => ( {
	Icon: () => null,
	arrowUp: 'arrow-up',
	chevronDown: 'chevron-down',
	close: 'close',
	commentContent: 'comment-content',
	plus: 'plus',
} ) );

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '../../../store', () => 'sd-ai-agent' );
jest.mock( '../../../utils/branding', () => ( {
	getBranding: () => ( { agentName: 'SD AI Agent' } ),
} ) );
jest.mock( '../../use-speech-recognition', () => () => ( {
	isListening: false,
	isSupported: false,
	toggleListening: jest.fn(),
} ) );
jest.mock( '../../feedback-consent-modal', () => () => null );
jest.mock( '../../chat-redesign/ModelPicker', () => () => null );
jest.mock( '../../chat-redesign/AgentPicker', () => () => null );
jest.mock( '../editor-selection-status', () => () => null );
jest.mock( '../../chat-redesign/icons', () => ( {
	AiIcon: () => null,
	Microphone: () => null,
	Paperclip: () => null,
	Stop: () => null,
} ) );
jest.mock(
	'../../slash-command-menu',
	() =>
		( { onSelect } ) =>
			require( '@wordpress/element' ).createElement(
				'button',
				{
					type: 'button',
					'data-slash-new': 'true',
					onClick: () => onSelect( { action: 'new' } ),
				},
				'/new'
			)
);

/**
 * Build the selectors required by the tested widget components.
 *
 * @return {Object} Mocked store selectors.
 */
function selectors() {
	return {
		getCurrentSessionId: () => 7,
		getMessageQueue: () => [],
		getPendingActionCard: () => null,
		getProviders: () => [],
		getSelectedProviderId: () => '',
		getSelectedModelId: () => '',
		getSessionJobs: () => ( {} ),
		getSessions: () => [],
		isSending: () => false,
	};
}

/**
 * Build the actions required by the tested widget components.
 *
 * @return {Object} Mocked store actions.
 */
function actions() {
	return {
		clearCurrentSession: jest.fn(),
		compactConversation: jest.fn(),
		exportSession: jest.fn(),
		fetchSessions: jest.fn(),
		openSession: jest.fn(),
		resumeRecoverableJob: jest.fn(),
		retryClientToolSubmission: jest.fn(),
		sendMessage: jest.fn(),
		setFloatingOpen: jest.fn(),
		stopGeneration: jest.fn(),
	};
}

/**
 * Render a component into an isolated DOM container.
 *
 * @param {JSX.Element} component Component to render.
 * @return {Promise<{container: HTMLElement, root: import('@wordpress/element').Root}>} Mounted component.
 */
async function render( component ) {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );

	await act( async () => {
		root.render( component );
	} );

	return { container, root };
}

describe( 'floating-widget new-chat actions', () => {
	let dispatch;
	let mounted;

	beforeEach( () => {
		dispatch = actions();
		useDispatch.mockReturnValue( dispatch );
		useSelect.mockImplementation( ( select ) =>
			select( () => selectors() )
		);
	} );

	afterEach( async () => {
		if ( mounted ) {
			await act( async () => {
				mounted.root.unmount();
			} );
			document.body.removeChild( mounted.container );
			mounted = undefined;
		}
		jest.clearAllMocks();
	} );

	test( 'clears the current session from the header button', async () => {
		mounted = await render(
			createElement( WidgetHeader, {
				isMinimized: false,
				onToggleMinimize: jest.fn(),
			} )
		);

		await act( async () => {
			mounted.container
				.querySelector( '.sdaa-w-new-btn' )
				.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
		} );

		expect( dispatch.clearCurrentSession ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'clears the current session when /new is selected', async () => {
		mounted = await render( createElement( WidgetInput ) );
		const textarea = mounted.container.querySelector( 'textarea' );
		const setter = Object.getOwnPropertyDescriptor(
			window.HTMLTextAreaElement.prototype,
			'value'
		).set;

		await act( async () => {
			setter.call( textarea, '/' );
			textarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );

		await act( async () => {
			mounted.container
				.querySelector( '[data-slash-new="true"]' )
				.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
		} );

		expect( dispatch.clearCurrentSession ).toHaveBeenCalledTimes( 1 );
	} );
} );
