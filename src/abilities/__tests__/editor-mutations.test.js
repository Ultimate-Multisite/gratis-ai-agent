/** Unit tests for safe editor markup mutation abilities. */

/** Load the mutations module without sharing registry state between tests. */
function loadModule() {
	let mod;
	jest.isolateModules( () => {
		// eslint-disable-next-line global-require
		mod = require( '../editor-mutations' );
	} );
	return mod;
}

/** Create a minimal block-editor runtime with one replaceable block. */
function setEditor() {
	const state = {
		selectedIds: [ 'old' ],
		onSerialize: null,
		blocks: {
			old: {
				clientId: 'old',
				name: 'core/paragraph',
				markup: '<!-- wp:paragraph -->Old<!-- /wp:paragraph -->',
				attributes: {},
				innerBlocks: [],
			},
		},
	};
	const selector = {
		getSelectedBlockClientIds: () => state.selectedIds,
		getBlock: ( clientId ) => state.blocks[ clientId ],
		getBlockRootClientId: () => '',
		getBlockIndex: ( clientId ) => state.selectedIds.indexOf( clientId ),
		getBlockParents: () => [],
		getBlockListSettings: () => ( {} ),
		getBlockInsertionPoint: () => ( { rootClientId: '', index: 1 } ),
		getSettings: () => ( { allowedBlockTypes: true } ),
		canInsertBlockType: () => true,
		getBlocks: () => Object.values( state.blocks ),
	};
	const dispatcher = {
		replaceBlocks: jest.fn( ( _ids, blocks ) => {
			state.blocks = Object.fromEntries(
				blocks.map( ( block ) => [ block.clientId, block ] )
			);
			state.selectedIds = blocks.map( ( block ) => block.clientId );
		} ),
		insertBlocks: jest.fn( ( blocks ) => {
			for ( const block of blocks ) {
				state.blocks[ block.clientId ] = block;
			}
			state.selectedIds = blocks.map( ( block ) => block.clientId );
		} ),
	};
	const historyDispatcher = {
		undo: jest.fn(),
		redo: jest.fn(),
	};
	const parse = jest.fn( ( markup ) => {
		if (
			markup === 'valid' ||
			markup === '<!-- wp:paragraph -->New<!-- /wp:paragraph -->'
		) {
			return [
				{
					clientId: 'new',
					name: 'core/paragraph',
					markup: '<!-- wp:paragraph -->New<!-- /wp:paragraph -->',
					attributes: {},
					innerBlocks: [],
				},
			];
		}
		return [];
	} );
	global.wp = {
		data: {
			select: jest.fn( () => selector ),
			dispatch: jest.fn( ( storeName ) =>
				storeName === 'core/editor' ? historyDispatcher : dispatcher
			),
		},
		blocks: {
			parse,
			serialize: jest.fn( ( blocks ) => {
				state.onSerialize?.( blocks );
				return blocks.map( ( block ) => block.markup ).join( '' );
			} ),
			getBlockType: jest.fn( ( name ) =>
				name === 'core/paragraph' ? { name } : null
			),
			validateBlock: jest.fn( () => [ true, [] ] ),
		},
	};
	return { state, selector, dispatcher, historyDispatcher, parse };
}

describe( 'editor markup mutations', () => {
	afterEach( () => {
		delete global.wp;
	} );

	test( 'rejects a stale replacement before dispatching', async () => {
		const { dispatcher } = setEditor();
		const { replaceEditorSelection } = loadModule();
		const result = await replaceEditorSelection( {
			markup: 'valid',
			expectedFingerprint: 'stale',
			expectedClientIds: [ 'old' ],
		} );

		expect( result ).toMatchObject( {
			applied: false,
			reason: 'stale_selection',
		} );
		expect( dispatcher.replaceBlocks ).not.toHaveBeenCalled();
	} );

	test( 'replaces a current selection once and returns canonical evidence', async () => {
		const { dispatcher } = setEditor();
		const { getEditorSelection } = require( '../editor' );
		const snapshot = await getEditorSelection();
		const { replaceEditorSelection } = loadModule();
		const result = await replaceEditorSelection( {
			markup: 'valid',
			expectedFingerprint: snapshot.fingerprint,
			expectedClientIds: [ 'old' ],
		} );

		expect( dispatcher.replaceBlocks ).toHaveBeenCalledTimes( 1 );
		expect( result ).toMatchObject( {
			applied: true,
			markup: '<!-- wp:paragraph -->New<!-- /wp:paragraph -->',
			clientIds: [ 'new' ],
		} );
		expect( result.fingerprint ).toMatch( /^(sha256|fallback):/ );
	} );

	test( 'rejects protected selections and malformed markup before writing', async () => {
		const { state, dispatcher } = setEditor();
		const { getEditorSelection } = require( '../editor' );
		const snapshot = await getEditorSelection();
		state.blocks.old.attributes = {
			metadata: { bindings: { content: {} } },
		};
		const { replaceEditorSelection, insertBlockMarkup } = loadModule();
		await expect(
			replaceEditorSelection( {
				markup: 'valid',
				expectedFingerprint: snapshot.fingerprint,
				expectedClientIds: [ 'old' ],
			} )
		).resolves.toMatchObject( {
			applied: false,
			reason: 'protected_selection',
		} );
		await expect(
			insertBlockMarkup( { markup: 'not block markup' } )
		).resolves.toMatchObject( {
			applied: false,
			reason: 'parse_failed',
		} );
		expect( dispatcher.replaceBlocks ).not.toHaveBeenCalled();
		expect( dispatcher.insertBlocks ).not.toHaveBeenCalled();
	} );

	test( 'rejects a selection that would remove a protected descendant or lock', async () => {
		const { state, dispatcher } = setEditor();
		const { getEditorSelection } = require( '../editor' );
		const snapshot = await getEditorSelection();
		state.blocks.old.innerBlocks = [
			{
				attributes: { lock: { remove: true } },
				innerBlocks: [],
			},
		];
		const { replaceEditorSelection } = loadModule();
		const result = await replaceEditorSelection( {
			markup: 'valid',
			expectedFingerprint: snapshot.fingerprint,
			expectedClientIds: [ 'old' ],
		} );

		expect( result ).toMatchObject( {
			applied: false,
			reason: 'protected_selection',
		} );
		expect( dispatcher.replaceBlocks ).not.toHaveBeenCalled();
	} );

	test( 'rechecks protected state immediately before replacement', async () => {
		const { state, dispatcher } = setEditor();
		const { getEditorSelection } = require( '../editor' );
		const snapshot = await getEditorSelection();
		let selectionSerializations = 0;
		state.onSerialize = ( blocks ) => {
			if (
				blocks[ 0 ]?.clientId === 'old' &&
				++selectionSerializations === 2
			) {
				state.blocks.old.attributes.lock = { remove: true };
			}
		};
		const { replaceEditorSelection } = loadModule();

		await expect(
			replaceEditorSelection( {
				markup: 'valid',
				expectedFingerprint: snapshot.fingerprint,
				expectedClientIds: [ 'old' ],
			} )
		).resolves.toMatchObject( {
			applied: false,
			reason: 'protected_selection',
		} );
		expect( dispatcher.replaceBlocks ).not.toHaveBeenCalled();
	} );

	test( 'inserts canonical markup once and makes no retry', async () => {
		const { dispatcher } = setEditor();
		const { insertBlockMarkup } = loadModule();
		const result = await insertBlockMarkup( { markup: 'valid' } );

		expect( dispatcher.insertBlocks ).toHaveBeenCalledTimes( 1 );
		expect( dispatcher.insertBlocks ).toHaveBeenCalledWith(
			expect.any( Array ),
			1,
			undefined
		);
		expect( result ).toMatchObject( {
			applied: true,
			clientIds: [ 'new' ],
		} );
	} );

	test( 'runs one native history operation and reports whether it changed blocks', () => {
		const { historyDispatcher } = setEditor();
		const { changeEditorHistory } = loadModule();

		expect( changeEditorHistory( { direction: 'undo' } ) ).toEqual( {
			applied: false,
			direction: 'undo',
		} );
		expect( historyDispatcher.undo ).toHaveBeenCalledTimes( 1 );
		expect( historyDispatcher.redo ).not.toHaveBeenCalled();
		expect( global.wp.data.dispatch ).toHaveBeenCalledWith( 'core/editor' );
	} );

	test( 'reports a history change and rejects an invalid direction', () => {
		const { state, historyDispatcher } = setEditor();
		historyDispatcher.undo.mockImplementation( () => {
			state.blocks.changed = {
				clientId: 'changed',
				name: 'core/paragraph',
				markup: '<!-- wp:paragraph -->Changed<!-- /wp:paragraph -->',
				attributes: {},
				innerBlocks: [],
			};
		} );
		const { changeEditorHistory } = loadModule();

		expect( changeEditorHistory( { direction: 'undo' } ) ).toEqual( {
			applied: true,
			direction: 'undo',
		} );
		expect(
			changeEditorHistory( { direction: 'sideways' } )
		).toMatchObject( {
			applied: false,
			reason: 'invalid_history_direction',
		} );
		expect( historyDispatcher.undo ).toHaveBeenCalledTimes( 1 );
		expect( historyDispatcher.redo ).not.toHaveBeenCalled();
	} );

	test( 'returns an uncertain result when an editor dispatch throws', async () => {
		const { dispatcher, historyDispatcher } = setEditor();
		const { getEditorSelection } = require( '../editor' );
		const snapshot = await getEditorSelection();
		const {
			changeEditorHistory,
			insertBlockMarkup,
			replaceEditorSelection,
		} = loadModule();
		dispatcher.replaceBlocks.mockImplementation( () => {
			throw new Error( 'replace failed' );
		} );
		dispatcher.insertBlocks.mockImplementation( () => {
			throw new Error( 'insert failed' );
		} );
		historyDispatcher.undo.mockImplementation( () => {
			throw new Error( 'undo failed' );
		} );

		await expect(
			replaceEditorSelection( {
				markup: 'valid',
				expectedFingerprint: snapshot.fingerprint,
				expectedClientIds: [ 'old' ],
			} )
		).resolves.toMatchObject( {
			applied: 'unknown',
			reason: 'dispatch_failed',
		} );
		await expect(
			insertBlockMarkup( { markup: 'valid' } )
		).resolves.toMatchObject( {
			applied: 'unknown',
			reason: 'dispatch_failed',
		} );
		expect( changeEditorHistory( { direction: 'undo' } ) ).toMatchObject( {
			applied: 'unknown',
			direction: 'undo',
			reason: 'dispatch_failed',
		} );
	} );
} );
