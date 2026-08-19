/**
 * Tests for compact Gutenberg selection subscriptions.
 */

import { createElement } from '@wordpress/element';
import { createRoot } from 'react-dom/client';
import { act } from 'react';

import useEditorSelection, {
	clearEditorSelection,
	readEditorSelection,
} from '../use-editor-selection';

global.IS_REACT_ACT_ENVIRONMENT = true;

jest.mock( '@wordpress/i18n', () => ( {
	__: ( text ) => text,
} ) );

describe( 'useEditorSelection', () => {
	let selectedIds;
	let blocks;
	let listener;
	let unsubscribe;
	let dispatcher;

	beforeEach( () => {
		selectedIds = [ 'first' ];
		blocks = {
			first: { clientId: 'first', name: 'core/paragraph' },
			second: { clientId: 'second', name: 'core/heading' },
		};
		listener = null;
		unsubscribe = jest.fn();
		dispatcher = { clearSelectedBlock: jest.fn() };
		global.wp = {
			data: {
				select: jest.fn( () => ( {
					getSelectedBlockClientIds: () => selectedIds,
					getBlock: jest.fn( ( clientId ) => blocks[ clientId ] ),
				} ) ),
				dispatch: jest.fn( () => dispatcher ),
				subscribe: jest.fn( ( callback ) => {
					listener = callback;
					return unsubscribe;
				} ),
			},
			blocks: {
				getBlockType: ( name ) => ( {
					title: name === 'core/heading' ? 'Heading' : 'Paragraph',
				} ),
			},
		};
	} );

	afterEach( () => {
		delete global.wp;
	} );

	test( 'reads labels without retaining markup or attributes', () => {
		blocks.first.attributes = { content: 'Private selected content' };

		expect( readEditorSelection() ).toEqual( {
			available: true,
			count: 1,
			labels: [ 'Paragraph' ],
			signature: '1:first:Paragraph',
		} );
	} );

	test( 'bounds block lookups and labels for large selections', () => {
		selectedIds = [
			'first',
			'second',
			...Array.from(
				{ length: 998 },
				( _value, index ) => `additional-${ index }`
			),
		];
		const selection = readEditorSelection();
		const editor = global.wp.data.select.mock.results[ 0 ].value;

		expect( selection.count ).toBe( 1000 );
		expect( selection.labels ).toEqual( [ 'Paragraph', 'Heading' ] );
		expect( selection.signature ).toBe(
			'1000:first:Paragraph|second:Heading'
		);
		expect( editor.getBlock ).toHaveBeenCalledTimes( 2 );
	} );

	test( 'subscribes once, updates changed selection, and cleans up', async () => {
		const states = [];
		/** Render the latest selection labels for subscription assertions. */
		function Probe() {
			const selection = useEditorSelection();
			states.push( selection );
			return createElement( 'span', null, selection.labels.join( ', ' ) );
		}

		const container = document.createElement( 'div' );
		const root = createRoot( container );
		await act( async () => root.render( createElement( Probe ) ) );
		expect( container.textContent ).toBe( 'Paragraph' );
		expect( global.wp.data.subscribe ).toHaveBeenCalledWith(
			expect.any( Function ),
			'core/block-editor'
		);

		const renderCount = states.length;
		await act( async () => listener() );
		expect( states ).toHaveLength( renderCount );

		selectedIds = [ 'first', 'second' ];
		await act( async () => listener() );
		expect( container.textContent ).toBe( 'Paragraph, Heading' );

		await act( async () => root.unmount() );
		expect( unsubscribe ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'becomes unavailable when the editor store disappears', async () => {
		/** Render editor availability for store-disappearance assertions. */
		function Probe() {
			const selection = useEditorSelection();
			return createElement(
				'span',
				null,
				selection.available ? 'available' : 'unavailable'
			);
		}

		const container = document.createElement( 'div' );
		const root = createRoot( container );
		await act( async () => root.render( createElement( Probe ) ) );
		expect( container.textContent ).toBe( 'available' );

		global.wp.data.select.mockReturnValue( null );
		await act( async () => listener() );
		expect( container.textContent ).toBe( 'unavailable' );

		await act( async () => root.unmount() );
	} );

	test( 'clears selection without dispatching a content action', () => {
		expect( clearEditorSelection() ).toBe( true );
		expect( dispatcher.clearSelectedBlock ).toHaveBeenCalledTimes( 1 );
		expect( global.wp.data.dispatch ).toHaveBeenCalledWith(
			'core/block-editor'
		);
	} );

	test( 'falls back to selectBlock when clearSelectedBlock is unavailable', () => {
		dispatcher = { selectBlock: jest.fn() };

		expect( clearEditorSelection() ).toBe( true );
		expect( dispatcher.selectBlock ).toHaveBeenCalledWith( null );
	} );
} );
