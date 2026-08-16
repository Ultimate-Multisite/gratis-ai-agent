import { createElement, createRoot } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { act } from 'react';

import WidgetLauncher from '../widget-launcher';

global.IS_REACT_ACT_ENVIRONMENT = true;

jest.mock( '@wordpress/data', () => ( {
	useDispatch: jest.fn(),
	useSelect: jest.fn(),
} ) );
jest.mock( '@wordpress/i18n', () => ( {
	__: ( text ) => text,
	sprintf: ( template, value ) => template.replace( '%s', value ),
} ) );
jest.mock( '../../../store', () => 'sd-ai-agent' );
jest.mock( '../../../utils/branding', () => ( {
	getBranding: () => ( {} ),
} ) );
jest.mock( '../../chat-redesign/icons', () => ( {
	AiIcon: () => null,
} ) );
jest.mock( '../use-drag', () => () => ( {
	position: null,
	moved: { current: false },
	handleMouseDown: jest.fn(),
} ) );

describe( 'WidgetLauncher', () => {
	let container;
	let root;
	let setFloatingOpen;

	beforeEach( () => {
		setFloatingOpen = jest.fn();
		useDispatch.mockReturnValue( { setFloatingOpen } );
		useSelect.mockImplementation( ( callback ) =>
			callback( () => ( {
				getSessionJobs: () => ( {} ),
				getAlertCount: () => 0,
			} ) )
		);
		container = document.createElement( 'div' );
		root = createRoot( container );
	} );

	afterEach( async () => {
		await act( async () => root.unmount() );
		jest.clearAllMocks();
	} );

	test( 'opens the widget by default', async () => {
		await act( async () => root.render( createElement( WidgetLauncher ) ) );

		container.querySelector( 'button' ).click();

		expect( setFloatingOpen ).toHaveBeenCalledWith( true );
	} );

	test( 'uses an activation override for deferred-load retries', async () => {
		const onActivate = jest.fn();
		await act( async () =>
			root.render(
				createElement( WidgetLauncher, {
					label: 'Retry opening AI Agent',
					onActivate,
				} )
			)
		);

		const button = container.querySelector( 'button' );
		button.click();

		expect( button.getAttribute( 'aria-label' ) ).toBe(
			'Retry opening AI Agent'
		);
		expect( onActivate ).toHaveBeenCalledTimes( 1 );
		expect( setFloatingOpen ).not.toHaveBeenCalled();
	} );
} );
