/**
 * Regression tests for retry action-card composition in the chat redesign.
 */

import { createElement } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { createRoot } from 'react-dom/client';
import { act } from 'react';

import ChatRedesign from '../index';

global.IS_REACT_ACT_ENVIRONMENT = true;

jest.mock( '@wordpress/data', () => ( {
	useDispatch: jest.fn(),
	useSelect: jest.fn(),
} ) );

jest.mock( '@wordpress/i18n', () => ( {
	__: ( string ) => string,
	sprintf: ( string, ...values ) =>
		values.reduce(
			( text, value, index ) =>
				text
					.replace( `%${ index + 1 }$d`, value )
					.replace( `%${ index + 1 }$s`, value ),
			string
		),
} ) );

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '../../../store', () => 'sd-ai-agent' );
jest.mock( '../../chat-banners', () => () => null );
jest.mock(
	'../../error-boundary',
	() =>
		( { children } ) =>
			children
);
jest.mock( '../../tool-confirmation-dialog', () => () => null );
jest.mock( '../Sidebar', () => () => null );
jest.mock( '../ConvoHeader', () => () => null );
jest.mock( '../ChangesDrawer', () => () => null );
jest.mock( '../MessageList', () => () => null );
jest.mock( '../InputArea', () => () => null );

/**
 * Build the store selector map required by ChatRedesign.
 *
 * @param {Object} pendingActionCard Pending action-card state.
 * @return {Object} Selector map.
 */
function buildSelectors( pendingActionCard ) {
	return {
		getCurrentSessionId: () => pendingActionCard?.sessionId || null,
		getPendingConfirmation: () => null,
		getPendingActionCard: () => pendingActionCard,
		isYoloMode: () => false,
		isSending: () => false,
	};
}

describe( 'ChatRedesign retry action card', () => {
	let container;
	let root;
	let retryClientToolSubmission;
	let pendingActionCard;
	let dispatch;

	beforeEach( () => {
		retryClientToolSubmission = jest.fn();
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( {} );
		pendingActionCard = {
			type: 'retry_client_tools',
			toolNames: [ 'sd-ai-agent-js/validate-theme-completion' ],
		};
		useSelect.mockImplementation( ( select ) =>
			select( () => buildSelectors( pendingActionCard ) )
		);
		dispatch = {
			confirmToolCall: jest.fn(),
			rejectToolCall: jest.fn(),
			retryClientToolSubmission,
			appendMessage: jest.fn(),
			setCurrentJobId: jest.fn(),
			setSending: jest.fn(),
			setSessionJob: jest.fn(),
			pollJob: jest.fn(),
			setPendingActionCard: jest.fn(),
		};
		useDispatch.mockReturnValue( dispatch );
	} );

	afterEach( async () => {
		if ( root ) {
			await act( async () => {
				root.unmount();
			} );
			document.body.removeChild( container );
		}
		container = undefined;
		root = undefined;
		jest.clearAllMocks();
	} );

	test( 'renders Retry and dispatches retryClientToolSubmission for preserved client results', async () => {
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );

		await act( async () => {
			root.render( createElement( ChatRedesign, { uiMode: 'admin' } ) );
		} );

		const retryButton = container.querySelector(
			'.sdaa-action-card-btn-confirm'
		);
		expect( retryButton ).not.toBeNull();
		expect( container.textContent ).toContain(
			'sd-ai-agent-js/validate-theme-completion'
		);

		await act( async () => {
			retryButton.dispatchEvent(
				new MouseEvent( 'click', { bubbles: true } )
			);
		} );

		expect( retryClientToolSubmission ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'shows completed evidence and posts the explicit phase approval', async () => {
		pendingActionCard = {
			type: 'durable_plan',
			sessionId: 42,
			plan: {
				plan_id: '00000000-0000-0000-0000-000000000042',
				status: 'awaiting_approval',
				current_step: 2,
				approval_request_id: 12,
				scope: 'Update the site navigation.',
				steps: [
					{
						key: 'inspect',
						position: 1,
						title: 'Inspect navigation',
						status: 'completed',
						evidence: { summary: 'Inventory captured.' },
					},
					{
						key: 'configure',
						position: 2,
						title: 'Configure navigation',
						instruction: 'Apply the reviewed changes.',
						status: 'awaiting_approval',
					},
				],
			},
		};
		apiFetch.mockResolvedValue( { plan: pendingActionCard.plan } );
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );

		await act( async () => {
			root.render( createElement( ChatRedesign, { uiMode: 'admin' } ) );
		} );

		expect( container.textContent ).toContain( 'Inventory captured.' );
		expect( container.textContent ).toContain( 'Approve phase' );

		await act( async () => {
			container
				.querySelector( '.sdaa-action-card-btn-confirm' )
				.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
			await Promise.resolve();
			await Promise.resolve();
		} );

		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/sd-ai-agent/v1/sessions/42/plan/approve',
			method: 'POST',
			data: {
				plan_id: '00000000-0000-0000-0000-000000000042',
				approval_request_id: 12,
			},
		} );
	} );

	test( 'keeps Cancel plan available while a durable phase is running', async () => {
		pendingActionCard = {
			type: 'durable_plan',
			sessionId: 42,
			plan: {
				plan_id: '00000000-0000-0000-0000-000000000042',
				status: 'running',
				current_step: 1,
				scope: 'Update the site navigation.',
				steps: [
					{
						key: 'inspect',
						position: 1,
						title: 'Inspect navigation',
						status: 'running',
					},
				],
			},
		};
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );

		await act( async () => {
			root.render( createElement( ChatRedesign, { uiMode: 'admin' } ) );
		} );

		const cancelButton = container.querySelector(
			'.sdaa-action-card-btn-cancel'
		);
		expect( cancelButton ).not.toBeNull();
		expect( cancelButton.textContent ).toBe( 'Cancel plan' );

		await act( async () => {
			cancelButton.dispatchEvent(
				new MouseEvent( 'click', { bubbles: true } )
			);
			await Promise.resolve();
			await Promise.resolve();
		} );

		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/sd-ai-agent/v1/sessions/42/plan/cancel',
			method: 'POST',
			data: {
				plan_id: '00000000-0000-0000-0000-000000000042',
			},
		} );
	} );

	test( 'reports stale approval cards instead of silently ignoring them', async () => {
		pendingActionCard = {
			type: 'durable_plan',
			sessionId: 42,
			plan: {
				plan_id: '00000000-0000-0000-0000-000000000042',
				status: 'awaiting_approval',
				current_step: 1,
				scope: 'Update the site navigation.',
				steps: [
					{
						key: 'configure',
						position: 1,
						title: 'Configure navigation',
						status: 'awaiting_approval',
					},
				],
			},
		};
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );

		await act( async () => {
			root.render( createElement( ChatRedesign, { uiMode: 'admin' } ) );
		} );

		await act( async () => {
			container
				.querySelector( '.sdaa-action-card-btn-confirm' )
				.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
			await Promise.resolve();
		} );

		expect( apiFetch ).not.toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/sd-ai-agent/v1/sessions/42/plan/approve',
			} )
		);
		expect( dispatch.appendMessage ).toHaveBeenCalledWith(
			expect.objectContaining( {
				role: 'system',
				parts: [
					expect.objectContaining( {
						text: expect.stringContaining(
							'The plan approval is no longer available.'
						),
					} ),
				],
			} )
		);
	} );
} );
