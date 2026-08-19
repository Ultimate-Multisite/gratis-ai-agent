/**
 * Client-side insert-block ability.
 *
 * Inserts a Gutenberg block into the active block editor. Guards on
 * wp.data.select('core/block-editor') being defined so this module is safe
 * to import on non-editor screens.
 *
 * Annotated readonly: false because it writes to the editor state.
 */

import { registerClientAbility } from './registry';

const MAX_SELECTED_BLOCKS = 50;
const MAX_SELECTION_MARKUP_BYTES = 64 * 1024;

/**
 * Return a deterministic non-cryptographic hash when SubtleCrypto is absent.
 *
 * @param {string} value Value to hash.
 * @return {string} Stable fingerprint.
 */
function fallbackFingerprint( value ) {
	let hash = 7;
	for ( let index = 0; index < value.length; index++ ) {
		hash = ( hash * 31 + value.charCodeAt( index ) ) % 2147483647;
	}

	return `fallback:${ hash.toString( 16 ).padStart( 8, '0' ) }`;
}

/**
 * Calculate the UTF-8 byte length of a string in browsers without TextEncoder.
 *
 * @param {string} value Value to measure.
 * @return {number} UTF-8 byte length.
 */
export function getByteLength( value ) {
	if ( typeof TextEncoder !== 'undefined' ) {
		return new TextEncoder().encode( value ).byteLength;
	}

	return Array.from( value ).reduce( ( length, character ) => {
		const codePoint = character.codePointAt( 0 );
		if ( codePoint <= 127 ) {
			return length + 1;
		}
		if ( codePoint <= 2047 ) {
			return length + 2;
		}
		return length + ( codePoint <= 65535 ? 3 : 4 );
	}, 0 );
}

/**
 * Create a fingerprint from ordered IDs and canonical selected-only markup.
 *
 * @param {string[]} clientIds Ordered selected block client IDs.
 * @param {string}   markup    Canonical serialized selected-block markup.
 * @return {Promise<string>} Selection fingerprint.
 */
async function createSelectionFingerprint( clientIds, markup ) {
	const value = `${ clientIds.join( '\n' ) }\n${ markup }`;
	const subtle = globalThis.crypto?.subtle;

	if ( subtle && typeof TextEncoder !== 'undefined' ) {
		try {
			const digest = await subtle.digest(
				'SHA-256',
				new TextEncoder().encode( value )
			);
			return `sha256:${ Array.from( new Uint8Array( digest ) )
				.map( ( byte ) => byte.toString( 16 ).padStart( 2, '0' ) )
				.join( '' ) }`;
		} catch ( _error ) {
			// Fall through to the deterministic browser-independent fallback.
		}
	}

	return fallbackFingerprint( value );
}

/**
 * Return the selected editor blocks without dispatching a state mutation.
 *
 * @return {Promise<Object>} Bounded current-selection snapshot.
 */
export async function getEditorSelection() {
	return getEditorSelectionForIds();
}

/**
 * Return a bounded snapshot for either the current selection or exact IDs.
 *
 * Mutation abilities use the explicit-ID form after a transaction so their
 * response is bound to the blocks that were actually written, not to a UI
 * selection that may have changed as a side effect of the transaction.
 *
 * @param {string[]} [requestedIds] Ordered block IDs to snapshot.
 * @return {Promise<Object>} Bounded current-selection snapshot.
 */
export async function getEditorSelectionForIds( requestedIds ) {
	const empty = {
		available: false,
		selected: false,
		count: 0,
		originalCount: 0,
		blocks: [],
		markup: '',
		fingerprint: '',
		truncated: false,
		reason: 'unavailable',
	};

	if ( typeof wp === 'undefined' || ! wp.data || ! wp.blocks ) {
		return empty;
	}

	try {
		const editor = wp.data.select( 'core/block-editor' );
		if ( ! editor || typeof editor.getBlock !== 'function' ) {
			return empty;
		}

		let selectedIds = Array.isArray( requestedIds ) ? requestedIds : [];
		if (
			! selectedIds.length &&
			typeof editor.getSelectedBlockClientIds === 'function'
		) {
			selectedIds = editor.getSelectedBlockClientIds();
		} else if (
			! selectedIds.length &&
			typeof editor.getSelectedBlockClientId === 'function'
		) {
			selectedIds = [ editor.getSelectedBlockClientId() ];
		}
		const clientIds = Array.isArray( selectedIds )
			? selectedIds.filter( Boolean )
			: [];

		if ( ! clientIds.length ) {
			return { ...empty, available: true, reason: 'no_selection' };
		}

		const originalCount = clientIds.length;
		const truncatedByCount = originalCount > MAX_SELECTED_BLOCKS;
		const boundedIds = clientIds.slice( 0, MAX_SELECTED_BLOCKS );
		const selectedBlocks = boundedIds.map( ( clientId ) =>
			editor.getBlock( clientId )
		);

		if ( selectedBlocks.some( ( block ) => ! block ) ) {
			return {
				...empty,
				available: true,
				selected: true,
				count: boundedIds.length,
				originalCount,
				truncated: true,
				reason: 'missing_selected_block',
			};
		}

		const markup = wp.blocks.serialize( selectedBlocks );
		if ( getByteLength( markup ) > MAX_SELECTION_MARKUP_BYTES ) {
			return {
				...empty,
				available: true,
				selected: true,
				count: boundedIds.length,
				originalCount,
				truncated: true,
				reason: 'markup_limit',
			};
		}

		const blocks = selectedBlocks.map( ( block, index ) => {
			const parentId =
				typeof editor.getBlockRootClientId === 'function'
					? editor.getBlockRootClientId( block.clientId ) || ''
					: '';
			const parent = parentId ? editor.getBlock( parentId ) : null;
			return {
				clientId: block.clientId,
				name: block.name,
				parent: {
					clientId: parentId,
					name: parent?.name || '',
					position:
						typeof editor.getBlockIndex === 'function'
							? editor.getBlockIndex(
									block.clientId,
									parentId || undefined
							  )
							: index,
				},
			};
		} );

		return {
			available: true,
			selected: true,
			count: boundedIds.length,
			originalCount,
			blocks,
			markup,
			fingerprint: await createSelectionFingerprint( boundedIds, markup ),
			truncated: truncatedByCount,
			reason: truncatedByCount ? 'selection_limit' : '',
		};
	} catch ( _error ) {
		return empty;
	}
}

/**
 * Execute the insert-block ability.
 *
 * @param {Object} args
 * @param {string} args.blockName    Block name, e.g. "core/paragraph".
 * @param {Object} [args.attributes] Block attributes.
 * @param {string} [args.innerHTML]  Optional inner HTML for the block.
 * @return {{ inserted: boolean, clientId: string, blockName: string }} Insert result.
 */
function executeInsertBlock( args ) {
	const { blockName, attributes = {}, innerHTML } = args || {};

	if ( ! blockName ) {
		return { inserted: false, clientId: '', blockName: '' };
	}

	// Guard: only run on editor screens.
	if (
		typeof wp === 'undefined' ||
		! wp.data ||
		! wp.data.select( 'core/block-editor' )
	) {
		return { inserted: false, clientId: '', blockName };
	}

	try {
		const { createBlock } = wp.blocks;
		const { dispatch } = wp.data;

		if ( ! createBlock || ! dispatch ) {
			return { inserted: false, clientId: '', blockName };
		}

		// Build the block — if innerHTML is provided, use it as the content.
		const blockAttributes =
			innerHTML && blockName === 'core/paragraph'
				? { ...attributes, content: innerHTML }
				: attributes;

		const block = createBlock( blockName, blockAttributes );
		dispatch( 'core/block-editor' ).insertBlocks( block );

		return {
			inserted: true,
			clientId: block.clientId || '',
			blockName,
		};
	} catch ( err ) {
		return { inserted: false, clientId: '', blockName };
	}
}

/**
 * Register the insert-block ability with the client-side abilities registry.
 *
 * Called by src/abilities/index.js after the sd-ai-agent-js category
 * has been registered. Must NOT self-register at module-eval time — ES
 * module imports are hoisted and would race the category registration
 * (the bug t166 fixes).
 *
 * Safe to call on non-editor screens — the executeInsertBlock callback
 * itself guards on `wp.data.select('core/block-editor')` being defined,
 * so calling the registered ability from a non-editor context returns
 * `{ inserted: false, ... }` instead of throwing.
 *
 * @return {void}
 */
export async function registerEditorAbility() {
	await registerClientAbility( {
		name: 'sd-ai-agent-js/get-editor-selection',
		label: 'Get Editor Selection',
		description:
			'Return a bounded, current snapshot of explicitly selected Gutenberg blocks without changing editor state.',
		inputSchema: {
			type: 'object',
			properties: {},
			required: [],
		},
		outputSchema: {
			type: 'object',
			properties: {
				available: { type: 'boolean' },
				selected: { type: 'boolean' },
				count: { type: 'integer' },
				originalCount: { type: 'integer' },
				blocks: { type: 'array', items: { type: 'object' } },
				markup: { type: 'string' },
				fingerprint: { type: 'string' },
				truncated: { type: 'boolean' },
				reason: { type: 'string' },
			},
		},
		annotations: { readonly: true },
		callback: getEditorSelection,
	} );

	await registerClientAbility( {
		name: 'sd-ai-agent-js/insert-block',
		label: 'Insert Block',
		description:
			'Insert a Gutenberg block into the active block editor. Only available on editor screens.',
		inputSchema: {
			type: 'object',
			properties: {
				blockName: {
					type: 'string',
					description: 'Block name, e.g. "core/paragraph".',
				},
				attributes: {
					type: 'object',
					description: 'Block attributes.',
				},
				innerHTML: {
					type: 'string',
					description: 'Optional inner HTML for the block.',
				},
			},
			required: [ 'blockName' ],
		},
		outputSchema: {
			type: 'object',
			properties: {
				inserted: { type: 'boolean' },
				clientId: { type: 'string' },
				blockName: { type: 'string' },
			},
		},
		annotations: { readonly: false },
		callback: executeInsertBlock,
	} );
}
