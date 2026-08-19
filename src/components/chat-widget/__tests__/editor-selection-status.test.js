/**
 * Tests for editor selection and mutation status presentation.
 */

import { createElement } from '@wordpress/element';
import { createRoot } from 'react-dom/client';
import { act } from 'react';

import {
	EditorSelectionStatus,
	getEditorMutationStatus,
	getSelectionLabel,
} from '../editor-selection-status';

global.IS_REACT_ACT_ENVIRONMENT = true;

jest.mock( '@wordpress/components', () => {
	const React = require( 'react' );
	return {
		Button: ( { label, onClick, className } ) =>
			React.createElement(
				'button',
				{ 'aria-label': label, onClick, className },
				label
			),
		Spinner: () => React.createElement( 'span', { 'data-spinner': true } ),
		Tooltip: ( { children } ) => children,
	};
} );

jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn(),
} ) );

jest.mock( '../../../store', () => 'sd-ai-agent/store' );

jest.mock( '@wordpress/icons', () => ( {
	Icon: () => null,
	blockDefault: {},
	closeSmall: {},
} ) );

jest.mock( '@wordpress/i18n', () => ( {
	__: ( text ) => text,
	sprintf: ( template, ...values ) => {
		let nextIndex = 0;
		return template.replace( /%(?:(\d+)\$)?[sd]/g, ( _match, position ) => {
			const index = position ? Number( position ) - 1 : nextIndex++;
			return String( values[ index ] );
		} );
	},
} ) );

jest.mock( '../use-editor-selection', () => jest.fn() );

const REPLACE_CALL = {
	type: 'call',
	id: 'replace-1',
	name: 'sd-ai-agent-js/replace-editor-selection',
};

describe( 'getEditorMutationStatus', () => {
	test( 'shows progress only for an active unmatched editor call', () => {
		expect(
			getEditorMutationStatus( [ REPLACE_CALL ], { isActive: true } )
		).toEqual( {
			kind: 'running',
			text: 'Updating selected blocks…',
		} );
		expect( getEditorMutationStatus( [ REPLACE_CALL ] ) ).toBeNull();
	} );

	test( 'maps stale selection to actionable non-success copy', () => {
		expect(
			getEditorMutationStatus( [
				REPLACE_CALL,
				{
					type: 'response',
					id: 'replace-1',
					response: {
						applied: false,
						reason: 'stale_selection',
					},
				},
			] )
		).toEqual( {
			kind: 'warning',
			text: 'Selection changed. Reselect the blocks and try again.',
		} );
	} );

	test( 'states that validation rejection changed no blocks', () => {
		expect(
			getEditorMutationStatus( [
				{
					type: 'call',
					id: 'insert-1',
					name: 'wpab__sd-ai-agent-js__insert-block-markup',
				},
				{
					type: 'result',
					id: 'insert-1',
					response: JSON.stringify( {
						result: {
							applied: false,
							reason: 'validation_failed',
						},
					} ),
				},
			] )
		).toEqual( {
			kind: 'warning',
			text: 'No blocks were changed. Review the selection or markup and try again.',
		} );
	} );

	test.each( [
		[
			'block_api_unavailable',
			'sd-ai-agent-js/replace-editor-selection',
			'The block editor API is unavailable. Wait for the editor to finish loading and try again.',
		],
		[
			'editor_unavailable',
			'sd-ai-agent-js/replace-editor-selection',
			'The block editor is unavailable. Return to the editor and try again.',
		],
		[
			'history_unavailable',
			'sd-ai-agent-js/change-editor-history',
			'Editor history is unavailable. Wait for the editor to finish loading and try again.',
		],
		[
			'insertion_point_unavailable',
			'sd-ai-agent-js/insert-block-markup',
			'No valid insertion point is available. Place the cursor in the editor and try again.',
		],
	] )(
		'maps %s to truthful unavailable-context copy',
		( reason, name, text ) => {
			expect(
				getEditorMutationStatus( [
					{ type: 'call', id: 'unavailable-1', name },
					{
						type: 'response',
						id: 'unavailable-1',
						response: { applied: false, reason },
					},
				] )
			).toEqual( { kind: 'warning', text } );
		}
	);

	test( 'does not claim success for an uncertain dispatch', () => {
		expect(
			getEditorMutationStatus( [
				REPLACE_CALL,
				{
					type: 'response',
					id: 'replace-1',
					response: {
						applied: 'unknown',
						reason: 'post_dispatch_unverified',
					},
				},
			] )
		).toEqual( {
			kind: 'warning',
			text: 'The editor result could not be confirmed. Review the blocks before retrying.',
		} );
	} );

	test( 'maps a confirmed history result using its direction', () => {
		expect(
			getEditorMutationStatus( [
				{
					type: 'call',
					id: 'history-1',
					name: 'sd-ai-agent-js/change-editor-history',
					args: { direction: 'redo' },
				},
				{
					type: 'response',
					id: 'history-1',
					response: { applied: true, direction: 'redo' },
				},
			] )
		).toEqual( {
			kind: 'success',
			text: 'Editor change redone.',
		} );
	} );
} );

describe( 'EditorSelectionStatus', () => {
	test( 'summarizes multiple selected blocks with bounded labels', () => {
		expect(
			getSelectionLabel( {
				count: 4,
				labels: [ 'Paragraph', 'Heading', 'Image', 'Group' ],
			} )
		).toBe( '4 blocks selected: Paragraph, Heading, +2 more' );
	} );

	test( 'renders a keyboard button that invokes selection-only clearing', async () => {
		const onClearSelection = jest.fn();
		const container = document.createElement( 'div' );
		const root = createRoot( container );
		await act( async () =>
			root.render(
				createElement( EditorSelectionStatus, {
					selection: {
						available: true,
						count: 1,
						labels: [ 'Paragraph' ],
					},
					onClearSelection,
					mutationStatus: null,
				} )
			)
		);

		expect( container.textContent ).toContain(
			'Selected block: Paragraph'
		);
		const button = container.querySelector(
			'[aria-label="Clear block selection"]'
		);
		expect( button ).not.toBeNull();
		await act( async () =>
			button.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) )
		);
		expect( onClearSelection ).toHaveBeenCalledTimes( 1 );

		await act( async () => root.unmount() );
	} );

	test( 'renders mutation copy as a polite atomic live region', async () => {
		const container = document.createElement( 'div' );
		const root = createRoot( container );
		await act( async () =>
			root.render(
				createElement( EditorSelectionStatus, {
					selection: { available: true, count: 0, labels: [] },
					onClearSelection: jest.fn(),
					mutationStatus: {
						kind: 'warning',
						text: 'No blocks were changed.',
					},
				} )
			)
		);

		const status = container.querySelector( '[role="status"]' );
		expect( status.getAttribute( 'aria-live' ) ).toBe( 'polite' );
		expect( status.getAttribute( 'aria-atomic' ) ).toBe( 'true' );
		expect( status.className ).toBe(
			'sd-ai-agent-editor-mutation-status sd-ai-agent-editor-mutation-status--warning'
		);
		expect( status.textContent ).toBe( 'No blocks were changed.' );

		await act( async () => root.unmount() );
	} );

	test( 'renders nothing outside the editor', async () => {
		const container = document.createElement( 'div' );
		const root = createRoot( container );
		await act( async () =>
			root.render(
				createElement( EditorSelectionStatus, {
					selection: { available: false, count: 2, labels: [] },
					onClearSelection: jest.fn(),
					mutationStatus: {
						kind: 'success',
						text: 'Blocks inserted.',
					},
				} )
			)
		);
		expect( container.innerHTML ).toBe( '' );

		await act( async () => root.unmount() );
	} );
} );
