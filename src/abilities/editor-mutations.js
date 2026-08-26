/**
 * Safe Gutenberg markup mutation abilities.
 *
 * Raw model markup is parsed, validated, canonicalized, and constrained before
 * a single block-editor transaction is dispatched. These callbacks never use
 * DOM mutation or attempt a retry after a write is uncertain.
 */

import {
	getByteLength,
	getEditorSelection,
	getEditorSelectionForIds,
} from './editor';
import { registerClientAbility } from './registry';

export const MAX_MARKUP_BYTES = 64 * 1024;
export const MAX_TOP_LEVEL_BLOCKS = 50;
export const MAX_TOTAL_BLOCKS = 200;
export const MAX_BLOCK_DEPTH = 20;
const HISTORY_SETTLEMENT_TIMEOUT = 1_000;

const UNSUPPORTED_BLOCKS = new Set( [ 'core/freeform', 'core/missing' ] );

/**
 * Normalize WordPress validation return values.
 *
 * @param {*} result Gutenberg validation response.
 * @return {boolean} Whether the block is valid.
 */
function isValidBlock( result ) {
	if ( typeof result === 'boolean' ) {
		return result;
	}
	if ( Array.isArray( result ) ) {
		return result[ 0 ] === true;
	}
	return result?.isValid === true;
}

/**
 * Return a structured rejection without exposing unbounded markup.
 *
 * @param {string}   reason Rejection code.
 * @param {Object[]} errors Bounded per-block errors.
 * @return {Object} Safe mutation rejection.
 */
function rejected( reason, errors = [] ) {
	return { applied: false, reason, errors: errors.slice( 0, 20 ) };
}

/**
 * Serialize the current block-editor state without exposing editor internals.
 *
 * @param {Object} editor Block editor selector.
 * @return {string} Current editor serialization.
 */
function getEditorSerialization( editor ) {
	return wp.blocks?.serialize?.( editor.getBlocks?.() || [] ) || '';
}

/**
 * Wait for one block-editor store update that proves a history mutation changed content.
 *
 * @param {Object} editor Block editor selector.
 * @param {string} before Serialized content before the history dispatch.
 * @return {Promise<boolean>} Whether a settled store state differs from before.
 */
function waitForHistorySettlement( editor, before ) {
	return new Promise( ( resolve ) => {
		if ( typeof wp.data?.subscribe !== 'function' ) {
			resolve( false );
			return;
		}

		let complete = false;
		let unsubscribe;
		const finish = ( changed ) => {
			if ( complete ) {
				return;
			}
			complete = true;
			if ( timeoutId ) {
				clearTimeout( timeoutId );
			}
			if ( typeof unsubscribe === 'function' ) {
				unsubscribe();
			}
			resolve( changed );
		};
		const check = () => {
			if ( getEditorSerialization( editor ) !== before ) {
				finish( true );
			}
		};

		const timeoutId = setTimeout(
			() => finish( false ),
			HISTORY_SETTLEMENT_TIMEOUT
		);
		try {
			unsubscribe = wp.data.subscribe( check, 'core/block-editor' );
		} catch ( _error ) {
			finish( false );
			return;
		}
		if ( complete && typeof unsubscribe === 'function' ) {
			unsubscribe();
			return;
		}
		check();
	} );
}

/**
 * Capture pre-dispatch history state and request one native history action.
 *
 * @param {Object} editor     Block editor selector.
 * @param {Object} dispatcher Editor history dispatcher.
 * @param {string} direction  Requested history direction.
 * @return {{before: string, hasHistory: boolean|undefined, dispatchFailed: boolean}} Dispatch evidence.
 */
function dispatchEditorHistory( editor, dispatcher, direction ) {
	const history = wp.data.select( 'core/editor' );
	const before = getEditorSerialization( editor );
	const hasHistory =
		direction === 'undo'
			? history?.hasEditorUndo?.()
			: history?.hasEditorRedo?.();
	try {
		dispatcher[ direction ]();
		return { before, hasHistory, dispatchFailed: false };
	} catch ( _error ) {
		return { before, hasHistory, dispatchFailed: true };
	}
}

/**
 * Determine whether a registered block is permitted in this editor root.
 *
 * @param {Object} editor       Block editor selector.
 * @param {Object} settings     Editor settings.
 * @param {string} name         Block name.
 * @param {string} rootClientId Target root client ID.
 * @return {boolean} Whether insertion is allowed.
 */
function isAllowedBlock( editor, settings, name, rootClientId ) {
	try {
		if ( typeof editor?.canInsertBlockType === 'function' ) {
			return editor.canInsertBlockType( name, rootClientId || undefined );
		}
	} catch ( _error ) {
		return false;
	}
	if ( Array.isArray( settings?.allowedBlockTypes ) ) {
		return settings.allowedBlockTypes.includes( name );
	}
	return settings?.allowedBlockTypes === true;
}

/**
 * Determine whether a parsed child is permitted by its parsed parent.
 *
 * The editor selector can only validate a client ID already present in the
 * editor. Parsed descendants have not been inserted yet, so validate their
 * registered parent, ancestor, and allowed-block constraints directly.
 *
 * @param {Object}   settings      Editor settings.
 * @param {Object}   blockType     Parsed child block type.
 * @param {Object}   parentType    Parsed parent block type.
 * @param {string[]} ancestorNames Parsed ancestor block names.
 * @return {boolean} Whether the parsed child is allowed.
 */
function isAllowedNestedBlock(
	settings,
	blockType,
	parentType,
	ancestorNames
) {
	if (
		Array.isArray( settings?.allowedBlockTypes ) &&
		! settings.allowedBlockTypes.includes( blockType.name )
	) {
		return false;
	}
	if (
		settings?.allowedBlockTypes !== undefined &&
		settings?.allowedBlockTypes !== null &&
		settings.allowedBlockTypes !== true
	) {
		return false;
	}
	if (
		Array.isArray( blockType.parent ) &&
		! blockType.parent.includes( parentType?.name )
	) {
		return false;
	}
	if (
		Array.isArray( blockType.ancestor ) &&
		! blockType.ancestor.some( ( name ) => ancestorNames.includes( name ) )
	) {
		return false;
	}
	return ! (
		Array.isArray( parentType?.allowedBlocks ) &&
		! parentType.allowedBlocks.includes( blockType.name )
	);
}

/**
 * Count and validate one parsed block tree.
 *
 * @param {Object}   api          Gutenberg blocks API.
 * @param {Object}   editor       Block editor selector.
 * @param {Object}   settings     Editor settings.
 * @param {Object[]} blocks       Parsed blocks.
 * @param {string}   rootClientId Target root client ID.
 * @return {Object[]} Structured validation errors.
 */
function validateTree( api, editor, settings, blocks, rootClientId ) {
	let count = 0;
	const errors = [];
	const walk = ( block, depth, parentType = null, ancestorNames = [] ) => {
		count++;
		if ( depth > MAX_BLOCK_DEPTH ) {
			errors.push( {
				code: 'block_depth_exceeded',
				block: block?.name || '',
			} );
			return;
		}
		if ( count > MAX_TOTAL_BLOCKS ) {
			errors.push( { code: 'block_count_exceeded' } );
			return;
		}
		if ( ! block?.name || UNSUPPORTED_BLOCKS.has( block.name ) ) {
			errors.push( {
				code: 'unsupported_block',
				block: block?.name || '',
			} );
			return;
		}
		const type = api.getBlockType( block.name );
		if ( ! type ) {
			errors.push( { code: 'unregistered_block', block: block.name } );
			return;
		}
		const isAllowed = parentType
			? isAllowedNestedBlock( settings, type, parentType, ancestorNames )
			: isAllowedBlock( editor, settings, block.name, rootClientId );
		if ( ! isAllowed ) {
			errors.push( { code: 'disallowed_block', block: block.name } );
			return;
		}
		try {
			if ( ! isValidBlock( api.validateBlock( block, type ) ) ) {
				errors.push( { code: 'invalid_block', block: block.name } );
			}
		} catch ( _error ) {
			errors.push( { code: 'invalid_block', block: block.name } );
		}
		for ( const child of block.innerBlocks || [] ) {
			walk( child, depth + 1, type, [ ...ancestorNames, block.name ] );
		}
	};
	for ( const block of blocks ) {
		walk( block, 1 );
	}
	return errors;
}

/**
 * Parse and canonically validate model-provided block markup without writing.
 *
 * @param {string} markup       Markup to validate.
 * @param {Object} editor       Block editor selector.
 * @param {string} rootClientId Target root client ID.
 * @return {{blocks?: Object[], markup?: string, errors?: Object[], reason?: string}} Validated canonical result.
 */
export function parseCanonicalMarkup( markup, editor, rootClientId = '' ) {
	if ( typeof markup !== 'string' || ! markup.trim() ) {
		return { reason: 'empty_markup' };
	}
	if ( getByteLength( markup ) > MAX_MARKUP_BYTES ) {
		return { reason: 'markup_limit' };
	}
	const api = typeof wp !== 'undefined' ? wp.blocks : null;
	if (
		! api ||
		typeof api.parse !== 'function' ||
		typeof api.serialize !== 'function' ||
		typeof api.getBlockType !== 'function' ||
		typeof api.validateBlock !== 'function'
	) {
		return { reason: 'block_api_unavailable' };
	}
	let parsed;
	try {
		parsed = api.parse( markup );
	} catch ( _error ) {
		return { reason: 'parse_failed' };
	}
	if ( ! Array.isArray( parsed ) || ! parsed.length ) {
		return { reason: 'parse_failed' };
	}
	if ( parsed.length > MAX_TOP_LEVEL_BLOCKS ) {
		return { reason: 'top_level_block_limit' };
	}
	const settings = editor?.getSettings?.() || null;
	const errors = validateTree( api, editor, settings, parsed, rootClientId );
	if ( errors.length ) {
		return { reason: 'validation_failed', errors };
	}
	let canonical;
	let reparsed;
	let stable;
	try {
		canonical = api.serialize( parsed );
		reparsed = api.parse( canonical );
		stable = api.serialize( reparsed );
	} catch ( _error ) {
		return { reason: 'canonicalization_failed' };
	}
	if (
		! canonical ||
		canonical !== stable ||
		getByteLength( canonical ) > MAX_MARKUP_BYTES ||
		! Array.isArray( reparsed ) ||
		reparsed.length !== parsed.length
	) {
		return { reason: 'unstable_markup' };
	}
	const canonicalErrors = validateTree(
		api,
		editor,
		settings,
		reparsed,
		rootClientId
	);
	return canonicalErrors.length
		? { reason: 'validation_failed', errors: canonicalErrors }
		: { blocks: reparsed, markup: canonical };
}

/**
 * Return true when a block tree contains bindings or structural locks.
 *
 * @param {Object} block Editor block.
 * @return {boolean} Whether this block tree is protected.
 */
function blockHasProtectedState( block ) {
	const attrs = block?.attributes || {};
	if (
		Object.keys( attrs?.metadata?.bindings || {} ).length ||
		Object.keys( attrs?.lock || block?.lock || {} ).length ||
		attrs?.templateLock ||
		block?.templateLock
	) {
		return true;
	}

	return ( block?.innerBlocks || [] ).some( blockHasProtectedState );
}

/**
 * Return true when the target insertion root is template locked.
 *
 * @param {Object} editor       Block editor selector.
 * @param {string} rootClientId Target insertion root client ID.
 * @return {boolean} Whether the insertion root is protected.
 */
function hasProtectedInsertionRoot( editor, rootClientId ) {
	const rootBlock = rootClientId ? editor.getBlock?.( rootClientId ) : null;
	if ( rootBlock?.attributes?.templateLock || rootBlock?.templateLock ) {
		return true;
	}
	return Boolean(
		editor.getBlockListSettings?.( rootClientId || '' )?.templateLock
	);
}

/**
 * Return true when a selected block or its ancestors have protected state.
 *
 * @param {Object}   editor    Block editor selector.
 * @param {string[]} clientIds Selected block client IDs.
 * @return {boolean} Whether a binding or lock prevents replacement.
 */
function hasProtectedSelectionState( editor, clientIds ) {
	for ( const clientId of clientIds ) {
		const block = editor.getBlock?.( clientId );
		if ( blockHasProtectedState( block ) ) {
			return true;
		}
		const parentIds = editor.getBlockParents?.( clientId ) || [];
		for ( const parentId of parentIds ) {
			const parent = editor.getBlock?.( parentId );
			if ( parent?.attributes?.templateLock || parent?.templateLock ) {
				return true;
			}
		}
		const rootId = editor.getBlockRootClientId?.( clientId ) || '';
		const listSettings = editor.getBlockListSettings?.( rootId );
		if ( listSettings?.templateLock ) {
			return true;
		}
	}
	return false;
}

/**
 * Read the exact, ordered selection expected by a replacement request.
 *
 * @param {Object} args Replacement arguments.
 * @return {Promise<Object>} Current selection or a rejection.
 */
async function currentReplacementSelection( args ) {
	const expectedIds = args?.expectedClientIds;
	if (
		! Array.isArray( expectedIds ) ||
		! expectedIds.length ||
		! args?.expectedFingerprint
	) {
		return { error: rejected( 'expected_selection_required' ) };
	}
	const snapshot = await getEditorSelection();
	const currentIds = snapshot.blocks.map( ( block ) => block.clientId );
	if (
		! snapshot.available ||
		! snapshot.selected ||
		snapshot.truncated ||
		snapshot.fingerprint !== args.expectedFingerprint ||
		currentIds.length !== expectedIds.length ||
		currentIds.some( ( id, index ) => id !== expectedIds[ index ] )
	) {
		return { error: rejected( 'stale_selection' ) };
	}
	return { snapshot, clientIds: currentIds };
}

/**
 * Replace an exact current selection with validated canonical markup.
 *
 * @param {Object} args Replacement arguments.
 * @return {Promise<Object>} Mutation result.
 */
export async function replaceEditorSelection( args = {} ) {
	if (
		typeof wp === 'undefined' ||
		! wp.data?.select ||
		! wp.data?.dispatch
	) {
		return rejected( 'editor_unavailable' );
	}
	const editor = wp.data.select( 'core/block-editor' );
	const dispatcher = wp.data.dispatch( 'core/block-editor' );
	if ( ! editor || typeof dispatcher?.replaceBlocks !== 'function' ) {
		return rejected( 'editor_unavailable' );
	}
	const selection = await currentReplacementSelection( args );
	if ( selection.error ) {
		return selection.error;
	}
	if ( hasProtectedSelectionState( editor, selection.clientIds ) ) {
		return rejected( 'protected_selection' );
	}
	const rootClientId =
		editor.getBlockRootClientId?.( selection.clientIds[ 0 ] ) || '';
	const parsed = parseCanonicalMarkup( args.markup, editor, rootClientId );
	if ( ! parsed.blocks ) {
		return rejected( parsed.reason, parsed.errors );
	}
	// Compare immediately before the sole write; never validate one selection and write another.
	const current = await currentReplacementSelection( args );
	if ( current.error ) {
		return current.error;
	}
	if ( hasProtectedSelectionState( editor, current.clientIds ) ) {
		return rejected( 'protected_selection' );
	}
	try {
		dispatcher.replaceBlocks( current.clientIds, parsed.blocks );
	} catch ( _error ) {
		return { applied: 'unknown', reason: 'dispatch_failed' };
	}
	const resultIds = parsed.blocks
		.map( ( block ) => block.clientId )
		.filter( Boolean );
	const applied = await getEditorSelectionForIds( resultIds );
	if (
		! resultIds.length ||
		applied.count !== resultIds.length ||
		applied.truncated
	) {
		return { applied: 'unknown', reason: 'post_dispatch_unverified' };
	}
	return {
		applied: true,
		markup: parsed.markup,
		clientIds: resultIds,
		fingerprint: applied.fingerprint,
	};
}

/**
 * Insert canonical markup at an explicit or current editor insertion point.
 *
 * @param {Object} args Insertion arguments.
 * @return {Promise<Object>} Mutation result.
 */
export async function insertBlockMarkup( args = {} ) {
	if (
		typeof wp === 'undefined' ||
		! wp.data?.select ||
		! wp.data?.dispatch
	) {
		return rejected( 'editor_unavailable' );
	}
	const editor = wp.data.select( 'core/block-editor' );
	const dispatcher = wp.data.dispatch( 'core/block-editor' );
	if ( ! editor || typeof dispatcher?.insertBlocks !== 'function' ) {
		return rejected( 'editor_unavailable' );
	}
	const insertion = editor.getBlockInsertionPoint?.() || {};
	const explicitRoot = args.rootClientId;
	const explicitIndex = args.index;
	if (
		( explicitRoot !== undefined || explicitIndex !== undefined ) &&
		( typeof explicitRoot !== 'string' ||
			! Number.isInteger( explicitIndex ) ||
			explicitIndex < 0 )
	) {
		return rejected( 'invalid_insertion_point' );
	}
	const rootClientId =
		explicitRoot === undefined
			? insertion.rootClientId || ''
			: explicitRoot;
	const index = explicitIndex === undefined ? insertion.index : explicitIndex;
	if ( ! Number.isInteger( index ) || index < 0 ) {
		return rejected( 'insertion_point_unavailable' );
	}
	if ( hasProtectedInsertionRoot( editor, rootClientId ) ) {
		return rejected( 'template_locked' );
	}
	const parsed = parseCanonicalMarkup( args.markup, editor, rootClientId );
	if ( ! parsed.blocks ) {
		return rejected( parsed.reason, parsed.errors );
	}
	try {
		dispatcher.insertBlocks(
			parsed.blocks,
			index,
			rootClientId || undefined
		);
	} catch ( _error ) {
		return { applied: 'unknown', reason: 'dispatch_failed' };
	}
	const resultIds = parsed.blocks
		.map( ( block ) => block.clientId )
		.filter( Boolean );
	const applied = await getEditorSelectionForIds( resultIds );
	if (
		! resultIds.length ||
		applied.count !== resultIds.length ||
		applied.truncated
	) {
		return { applied: 'unknown', reason: 'post_dispatch_unverified' };
	}
	return {
		applied: true,
		markup: parsed.markup,
		clientIds: resultIds,
		fingerprint: applied.fingerprint,
	};
}

/**
 * Request precisely one native editor undo or redo action.
 *
 * @param {Object} args History arguments.
 * @return {Promise<Object>} History mutation result.
 */
export async function changeEditorHistory( args = {} ) {
	if (
		typeof wp === 'undefined' ||
		! wp.data?.select ||
		! wp.data?.dispatch
	) {
		return rejected( 'editor_unavailable' );
	}
	const direction = args.direction;
	if ( direction !== 'undo' && direction !== 'redo' ) {
		return rejected( 'invalid_history_direction' );
	}
	const editor = wp.data.select( 'core/block-editor' );
	const dispatcher = wp.data.dispatch( 'core/editor' );
	if ( ! editor || typeof dispatcher?.[ direction ] !== 'function' ) {
		return rejected( 'history_unavailable' );
	}
	const historyResult = dispatchEditorHistory(
		editor,
		dispatcher,
		direction
	);
	if ( historyResult.dispatchFailed ) {
		return {
			applied: 'unknown',
			direction,
			reason: 'dispatch_failed',
		};
	}
	if ( historyResult.hasHistory === false ) {
		return { applied: false, direction };
	}
	if ( await waitForHistorySettlement( editor, historyResult.before ) ) {
		return { applied: true, direction };
	}
	return {
		applied: 'unknown',
		direction,
		reason: 'history_settlement_timeout',
	};
}

/**
 * Register all markup mutation abilities after the client category exists.
 *
 * @return {Promise<void>} Registration completion.
 */
export async function registerEditorMutationAbilities() {
	const commonOutput = {
		type: 'object',
		properties: {
			applied: { type: [ 'boolean', 'string' ] },
			reason: { type: 'string' },
			markup: { type: 'string' },
			clientIds: { type: 'array', items: { type: 'string' } },
			fingerprint: { type: 'string' },
			errors: { type: 'array', items: { type: 'object' } },
		},
	};
	await registerClientAbility( {
		name: 'sd-ai-agent-js/replace-editor-selection',
		label: 'Replace Editor Selection',
		description:
			'Replace exactly the selected Gutenberg blocks only when the supplied ordered IDs and fingerprint still match.',
		inputSchema: {
			type: 'object',
			properties: {
				markup: { type: 'string' },
				expectedFingerprint: { type: 'string' },
				expectedClientIds: { type: 'array', items: { type: 'string' } },
			},
			required: [ 'markup', 'expectedFingerprint', 'expectedClientIds' ],
		},
		outputSchema: commonOutput,
		annotations: { readonly: false },
		callback: replaceEditorSelection,
	} );
	await registerClientAbility( {
		name: 'sd-ai-agent-js/insert-block-markup',
		label: 'Insert Block Markup',
		description:
			'Insert validated canonical Gutenberg markup once at an explicit editor location or the current insertion point.',
		inputSchema: {
			type: 'object',
			properties: {
				markup: { type: 'string' },
				rootClientId: {
					type: 'string',
					description:
						'Supply rootClientId and index together; omit both to use the current insertion point.',
				},
				index: {
					type: 'integer',
					description:
						'Supply rootClientId and index together; omit both to use the current insertion point.',
				},
			},
			required: [ 'markup' ],
		},
		outputSchema: commonOutput,
		annotations: { readonly: false },
		callback: insertBlockMarkup,
	} );
	await registerClientAbility( {
		name: 'sd-ai-agent-js/change-editor-history',
		label: 'Change Editor History',
		description:
			'Request one native Gutenberg editor undo or redo operation.',
		inputSchema: {
			type: 'object',
			properties: {
				direction: { type: 'string', enum: [ 'undo', 'redo' ] },
			},
			required: [ 'direction' ],
		},
		outputSchema: {
			type: 'object',
			properties: {
				applied: { type: [ 'boolean', 'string' ] },
				direction: { type: 'string' },
				reason: { type: 'string' },
			},
		},
		annotations: { readonly: false },
		callback: changeEditorHistory,
	} );
}
