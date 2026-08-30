/**
 * Unit tests for Trash management in the redesigned chat sidebar.
 */

import { createElement } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { act } from 'react';
import { createRoot } from 'react-dom/client';

import Sidebar from '../Sidebar';

global.IS_REACT_ACT_ENVIRONMENT = true;

jest.mock( '@wordpress/data', () => ( {
	useDispatch: jest.fn(),
	useSelect: jest.fn(),
} ) );

jest.mock( '@wordpress/i18n', () => ( {
	__: ( value ) => value,
	_n: ( single, plural, count ) => ( count === 1 ? single : plural ),
	sprintf: ( template, value ) => template.replace( /%[sd]/, value ),
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

jest.mock( '../../../store', () => 'sd-ai-agent' );
jest.mock( '../../session-context-menu', () => () => null );

describe( 'Sidebar Trash actions', () => {
	let container;
	let root;
	let dispatch;

	beforeEach( async () => {
		dispatch = {
			clearCurrentSession: jest.fn(),
			fetchSessions: jest.fn(),
			fetchSharedSessions: jest.fn(),
			setSessionSearch: jest.fn(),
			setSessionFilter: jest.fn(),
			openSession: jest.fn(),
			bulkSessionAction: jest.fn().mockResolvedValue( { updated: 2 } ),
			emptySessionTrash: jest.fn().mockResolvedValue( { deleted: 2 } ),
		};
		useDispatch.mockReturnValue( dispatch );

		const sessions = [
			{ id: 11, title: 'First trashed chat', status: 'trash' },
			{ id: 12, title: 'Second trashed chat', status: 'trash' },
		];
		const selectors = {
			getSessions: () => sessions,
			getCurrentSessionId: () => null,
			getSessionSearch: () => '',
			getSessionFilter: () => 'trash',
			getSessionJobs: () => ( {} ),
		};
		useSelect.mockImplementation( ( callback ) =>
			callback( () => selectors )
		);

		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
		await act( async () => {
			root.render(
				createElement( Sidebar, {
					collapsed: false,
					onToggleCollapse: jest.fn(),
				} )
			);
		} );
	} );

	afterEach( async () => {
		await act( async () => root.unmount() );
		document.body.removeChild( container );
		jest.clearAllMocks();
	} );

	test( 'selects all trashed chats and restores them in one action', async () => {
		const selectAll = Array.from(
			container.querySelectorAll( 'input[type="checkbox"]' )
		).find(
			( input ) => input.getAttribute( 'aria-label' ) === 'Select all'
		);

		await act( async () => selectAll.click() );
		expect( container.textContent ).toContain( '2 selected' );

		const restore = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( button ) => button.textContent === 'Restore' );
		await act( async () => restore.click() );

		expect( dispatch.bulkSessionAction ).toHaveBeenCalledWith(
			[ 11, 12 ],
			'restore'
		);
	} );
} );
