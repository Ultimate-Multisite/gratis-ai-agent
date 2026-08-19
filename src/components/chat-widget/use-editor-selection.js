/**
 * Subscribe to compact Gutenberg selection metadata for the floating widget.
 *
 * Selected markup and block attributes deliberately stay out of React state.
 */

import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const EMPTY_SELECTION = {
	available: false,
	count: 0,
	labels: [],
	signature: 'unavailable',
};

/**
 * Convert a registered block name into a short localized display label.
 *
 * @param {string} blockName Registered Gutenberg block name.
 * @return {string} Human-readable block label.
 */
function getBlockLabel( blockName ) {
	const type = wp.blocks?.getBlockType?.( blockName );
	if ( typeof type?.title === 'string' && type.title.trim() ) {
		return type.title.trim();
	}

	const fallback = String( blockName || '' )
		.split( '/' )
		.pop();
	return fallback || __( 'Block', 'superdav-ai-agent' );
}

/**
 * Read only the metadata needed to present the current editor selection.
 *
 * @return {{available: boolean, count: number, labels: string[], signature: string}} Compact selection metadata.
 */
export function readEditorSelection() {
	if ( typeof wp === 'undefined' || ! wp.data?.select ) {
		return EMPTY_SELECTION;
	}

	try {
		const editor = wp.data.select( 'core/block-editor' );
		if ( ! editor || typeof editor.getBlock !== 'function' ) {
			return EMPTY_SELECTION;
		}

		let selectedIds = [];
		if ( typeof editor.getSelectedBlockClientIds === 'function' ) {
			selectedIds = editor.getSelectedBlockClientIds();
		} else if ( typeof editor.getSelectedBlockClientId === 'function' ) {
			const selectedId = editor.getSelectedBlockClientId();
			selectedIds = selectedId ? [ selectedId ] : [];
		}

		const clientIds = Array.isArray( selectedIds ) ? selectedIds : [];
		const visibleIds = clientIds.slice( 0, 2 );
		const labels = visibleIds.map( ( clientId ) => {
			const block = editor.getBlock( clientId );
			return getBlockLabel( block?.name );
		} );

		return {
			available: true,
			count: clientIds.length,
			labels,
			signature:
				`${ clientIds.length }:` +
				visibleIds
					.map(
						( clientId, index ) =>
							`${ clientId }:${ labels[ index ] }`
					)
					.join( '|' ),
		};
	} catch ( _error ) {
		return EMPTY_SELECTION;
	}
}

/**
 * Clear Gutenberg selection without mutating block content.
 *
 * @return {boolean} Whether a supported selection action was dispatched.
 */
export function clearEditorSelection() {
	if ( typeof wp === 'undefined' || ! wp.data?.dispatch ) {
		return false;
	}

	try {
		const dispatcher = wp.data.dispatch( 'core/block-editor' );
		if ( typeof dispatcher?.clearSelectedBlock === 'function' ) {
			dispatcher.clearSelectedBlock();
			return true;
		}
		if ( typeof dispatcher?.selectBlock === 'function' ) {
			dispatcher.selectBlock( null );
			return true;
		}
	} catch ( _error ) {
		return false;
	}

	return false;
}

/**
 * Subscribe to Gutenberg selection changes without polling or render storms.
 *
 * @return {{available: boolean, count: number, labels: string[], clearSelection: Function}} Selection presentation state.
 */
export default function useEditorSelection() {
	const [ selection, setSelection ] = useState( EMPTY_SELECTION );
	const signatureRef = useRef( EMPTY_SELECTION.signature );

	useEffect( () => {
		if ( typeof wp === 'undefined' || ! wp.data?.subscribe ) {
			return undefined;
		}

		let active = true;
		const update = () => {
			const next = readEditorSelection();
			if ( next.signature === signatureRef.current ) {
				return;
			}
			signatureRef.current = next.signature;
			if ( active ) {
				setSelection( next );
			}
		};

		update();
		const unsubscribe = wp.data.subscribe( update, 'core/block-editor' );

		return () => {
			active = false;
			if ( typeof unsubscribe === 'function' ) {
				unsubscribe();
			}
		};
	}, [] );

	const clearSelection = useCallback( () => clearEditorSelection(), [] );

	return {
		available: selection.available,
		count: selection.count,
		labels: selection.labels,
		clearSelection,
	};
}
