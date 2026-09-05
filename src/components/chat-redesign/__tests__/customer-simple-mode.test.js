/**
 * Unit tests for customer/simple chat UI mode.
 */

import { createElement } from '@wordpress/element';
import { createRoot } from 'react-dom/client';
import { act } from 'react';

global.IS_REACT_ACT_ENVIRONMENT = true;

const useDispatch = jest.fn();
const useSelect = jest.fn();
const wordpressData = jest.requireActual( '@wordpress/data' );

jest.doMock( '@wordpress/data', () => ( {
	...wordpressData,
	useDispatch,
	useSelect,
} ) );

const ChatRedesign = require( '../index' ).default;
const InputArea = require( '../InputArea' ).default;
const WidgetHeader = require( '../../chat-widget/widget-header' ).default;

jest.mock( '@wordpress/i18n', () => ( {
	__: ( str ) => str,
	_n: ( single, plural, count ) => ( count === 1 ? single : plural ),
	sprintf: ( format, value ) => format.replace( '%d', value ),
} ) );

jest.mock( '@wordpress/icons', () => ( {
	Icon: () => null,
	arrowUp: 'arrow-up',
	close: 'close',
	chevronDown: 'chevron-down',
	commentContent: 'comment-content',
	moreHorizontal: 'more-horizontal',
	plus: 'plus',
	sidebar: 'sidebar',
} ) );

jest.mock( '@wordpress/components', () => {
	const React = require( 'react' );
	return {
		Button: ( { children, label, disabled, onClick, className } ) =>
			React.createElement(
				'button',
				{
					type: 'button',
					className,
					'aria-label': label,
					disabled,
					onClick,
				},
				children
			),
		CheckboxControl: ( { label, checked, disabled, onChange } ) =>
			React.createElement( 'input', {
				type: 'checkbox',
				'aria-label': label,
				checked,
				disabled,
				onChange: ( event ) => onChange( event.target.checked ),
			} ),
	};
} );

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '../../../store', () => 'sd-ai-agent' );
jest.mock( '../../../utils/branding', () => ( {
	getBranding: () => ( { agentName: 'Docs Assistant' } ),
} ) );
jest.mock( '../../use-speech-recognition', () => () => ( {
	cancelListening: jest.fn(),
	detectedLanguage: '',
	error: null,
	isListening: false,
	isSupported: false,
	isTranscribing: false,
	startListening: jest.fn(),
	status: 'idle',
	stopListening: jest.fn(),
	toggleListening: jest.fn(),
} ) );
jest.mock( '../../use-text-to-speech', () => {
	const useTextToSpeech = () => ( {
		cancel: jest.fn(),
		isSpeaking: false,
		isSupported: false,
		speak: jest.fn(),
	} );
	useTextToSpeech.isTTSSupported = false;

	return useTextToSpeech;
} );
jest.mock(
	'../../slash-command-menu',
	() => () =>
		require( '@wordpress/element' ).createElement( 'div', {
			className: 'mock-slash-command-menu',
		} )
);
jest.mock( '../../feedback-consent-modal', () => () => null );
jest.mock(
	'../ModelPicker',
	() => () =>
		require( '@wordpress/element' ).createElement( 'div', {
			className: 'mock-model-picker',
		} )
);
jest.mock(
	'../AgentPicker',
	() => () =>
		require( '@wordpress/element' ).createElement( 'div', {
			className: 'mock-agent-picker',
		} )
);
jest.mock( '../../chat-banners', () => () => null );
jest.mock(
	'../../error-boundary',
	() =>
		( { children } ) =>
			children
);
jest.mock(
	'../../tool-confirmation-dialog',
	() => () =>
		require( '@wordpress/element' ).createElement( 'div', {
			className: 'mock-tool-confirmation',
		} )
);
jest.mock(
	'../MessageList',
	() => () =>
		require( '@wordpress/element' ).createElement( 'div', {
			className: 'mock-message-list',
		} )
);
jest.mock(
	'../ChangesDrawer',
	() => () =>
		require( '@wordpress/element' ).createElement( 'div', {
			className: 'mock-changes',
		} )
);
jest.mock(
	'../../session-context-menu',
	() => () =>
		require( '@wordpress/element' ).createElement( 'div', {
			className: 'mock-session-context-menu',
		} )
);
jest.mock( '../icons', () => ( {
	AiIcon: () => null,
	Microphone: () => null,
	Paperclip: () => null,
	Speaker: () => null,
	SpeakerMuted: () => null,
	Stop: () => null,
} ) );

/**
 *
 */
function buildSelectors() {
	return {
		getCurrentSessionId: () => 7,
		getPendingConfirmation: () => null,
		getPendingActionCard: () => null,
		getPendingProposal: () => null,
		isYoloMode: () => false,
		isSending: () => false,
		isFloatingMinimized: () => false,
		getCurrentSessionMessages: () => [],
		getMessageQueue: () => [],
		getSessions: () => [
			{
				id: 7,
				title: 'Admin session',
				updated_at: '2026-01-01 00:00:00',
			},
		],
		getSessionJobs: () => ( {} ),
		getProviders: () => [
			{
				id: 'openai',
				models: [ { id: 'gpt-4o', name: 'GPT-4o' } ],
			},
		],
		getSelectedProviderId: () => 'openai',
		getSelectedModelId: () => 'gpt-4o',
		isTtsEnabled: () => false,
	};
}

/**
 *
 */
function buildActions() {
	return {
		clearCurrentSession: jest.fn(),
		compactConversation: jest.fn(),
		confirmToolCall: jest.fn(),
		exportSession: jest.fn(),
		fetchSessions: jest.fn(),
		openSession: jest.fn(),
		rejectToolCall: jest.fn(),
		retryClientToolSubmission: jest.fn(),
		renameSession: jest.fn(),
		sendMessage: jest.fn(),
		setFloatingOpen: jest.fn(),
		setFloatingMinimized: jest.fn(),
		setPendingActionCard: jest.fn(),
		setShowShortcutsHelp: jest.fn(),
		setTtsEnabled: jest.fn(),
		stopGeneration: jest.fn(),
	};
}

let actions;

/**
 *
 */
function setupStore() {
	useSelect.mockImplementation( ( fn ) => fn( () => buildSelectors() ) );
	actions = buildActions();
	useDispatch.mockReturnValue( actions );
}

/**
 *
 * @param {JSX.Element} component Component to render.
 */
async function renderComponent( component ) {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );

	await act( async () => {
		root.render( component );
	} );

	return { container, root };
}

/**
 *
 * @param {HTMLElement} container Rendered component container.
 * @param {string}      value     Textarea value.
 */
async function setTextareaValue( container, value ) {
	const textarea = container.querySelector( 'textarea' );
	const setter = Object.getOwnPropertyDescriptor(
		window.HTMLTextAreaElement.prototype,
		'value'
	).set;

	await act( async () => {
		setter.call( textarea, value );
		textarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
}

describe( 'customer/simple chat UI mode', () => {
	let mounted;

	beforeEach( () => {
		setupStore();
	} );

	afterEach( async () => {
		if ( mounted ) {
			await act( async () => {
				mounted.root.unmount();
			} );
			document.body.removeChild( mounted.container );
			mounted = undefined;
		}
	} );

	test( 'InputArea hides model picker, agent picker, and slash menu in simple mode', async () => {
		mounted = await renderComponent(
			createElement( InputArea, { isSimpleMode: true } )
		);

		expect(
			mounted.container.querySelector( '.mock-model-picker' )
		).toBeNull();
		expect(
			mounted.container.querySelector( '.mock-agent-picker' )
		).toBeNull();

		await setTextareaValue( mounted.container, '/' );

		expect(
			mounted.container.querySelector( '.mock-slash-command-menu' )
		).toBeNull();

		await setTextareaValue( mounted.container, 'How do I set up docs?' );
		await act( async () => {
			mounted.container
				.querySelector( 'button[aria-label="Send message"]' )
				.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
		} );

		expect( actions.sendMessage ).toHaveBeenCalledWith(
			'How do I set up docs?',
			[]
		);
	} );

	test( 'InputArea keeps admin selectors outside simple mode', async () => {
		mounted = await renderComponent( createElement( InputArea ) );

		expect(
			mounted.container.querySelector( '.mock-model-picker' )
		).not.toBeNull();
		expect(
			mounted.container.querySelector( '.mock-agent-picker' )
		).not.toBeNull();
	} );

	test( 'WidgetHeader hides session drawer affordance and new-chat button in simple mode', async () => {
		mounted = await renderComponent(
			createElement( WidgetHeader, {
				isMinimized: false,
				onToggleMinimize: jest.fn(),
				isSimpleMode: true,
			} )
		);

		expect(
			mounted.container.querySelector( '.sdaa-w-new-btn' )
		).toBeNull();
		expect(
			mounted.container.querySelector( '.sdaa-w-head-caret' )
		).toBeNull();
		expect( mounted.container.textContent ).not.toContain( 'GPT-4o' );
		expect( mounted.container.textContent ).toContain( 'Docs Assistant' );

		await act( async () => {
			mounted.container
				.querySelector( '.sdaa-w-head-session' )
				.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
		} );

		expect(
			mounted.container.querySelector( '.sdaa-w-session-drawer' )
		).toBeNull();
		expect( actions.fetchSessions ).not.toHaveBeenCalled();
	} );

	test( 'ChatRedesign suppresses sidebar, selectors, and conversation menu in simple mode', async () => {
		mounted = await renderComponent(
			createElement( ChatRedesign, { uiMode: 'customer_simple' } )
		);

		expect(
			mounted.container.querySelector( '.sdaa-cr-sidebar' )
		).toBeNull();
		expect(
			mounted.container.querySelector( '.mock-model-picker' )
		).toBeNull();
		expect(
			mounted.container.querySelector( '.mock-agent-picker' )
		).toBeNull();
		expect(
			mounted.container.querySelector( '.sdaa-cr-convo-head-menu-wrap' )
		).toBeNull();
		expect( mounted.container.textContent ).not.toContain(
			'Click to rename'
		);
	} );
} );
