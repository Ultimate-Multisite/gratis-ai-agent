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
		canUndo: false,
		canRedo: false,
		subscribers: [],
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
		canInsertBlockType: jest.fn( () => true ),
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
	const historySelector = {
		hasEditorUndo: () => state.canUndo,
		hasEditorRedo: () => state.canRedo,
	};
	const notify = () => {
		for ( const subscriber of state.subscribers ) {
			subscriber();
		}
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
			select: jest.fn( ( storeName ) =>
				storeName === 'core/editor' ? historySelector : selector
			),
			dispatch: jest.fn( ( storeName ) =>
				storeName === 'core/editor' ? historyDispatcher : dispatcher
			),
			subscribe: jest.fn( ( callback ) => {
				state.subscribers.push( callback );
				return () => {
					state.subscribers = state.subscribers.filter(
						( subscriber ) => subscriber !== callback
					);
				};
			} ),
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
	return { state, selector, dispatcher, historyDispatcher, notify, parse };
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

	test( 'allows valid nested trees but rejects invalid direct children', () => {
		const { selector } = setEditor();
		const { parseCanonicalMarkup } = loadModule();
		const makeBlock = ( name, innerBlocks = [] ) => ( {
			clientId: name,
			name,
			markup: name,
			attributes: {},
			innerBlocks,
		} );
		const nestedTrees = {
			'core/list': () => [
				makeBlock( 'core/list', [
					makeBlock( 'core/list-item' ),
					makeBlock( 'core/list-item' ),
				] ),
			],
			'core/columns': () => [
				makeBlock( 'core/columns', [
					makeBlock( 'core/column' ),
					makeBlock( 'core/column' ),
				] ),
			],
			'core/buttons': () => [
				makeBlock( 'core/buttons', [
					makeBlock( 'core/button' ),
					makeBlock( 'core/button' ),
				] ),
			],
		};
		const blockTypes = {
			'core/list': {
				name: 'core/list',
				allowedBlocks: [ 'core/list-item' ],
			},
			'core/list-item': {
				name: 'core/list-item',
				parent: [ 'core/list' ],
			},
			'core/columns': {
				name: 'core/columns',
				allowedBlocks: [ 'core/column' ],
			},
			'core/column': {
				name: 'core/column',
				parent: [ 'core/columns' ],
			},
			'core/buttons': {
				name: 'core/buttons',
				allowedBlocks: [ 'core/button' ],
			},
			'core/button': {
				name: 'core/button',
				parent: [ 'core/buttons' ],
			},
		};
		const originalSerialize =
			global.wp.blocks.serialize.getMockImplementation();

		global.wp.blocks.getBlockType.mockImplementation(
			( name ) => blockTypes[ name ] || null
		);
		global.wp.blocks.parse.mockImplementation( ( markup ) => {
			const name = markup
				.replace( 'nested-', '' )
				.replace( 'canonical-', '' );
			return nestedTrees[ name ]
				? nestedTrees[ name ]()
				: [ makeBlock( name ) ];
		} );
		global.wp.blocks.serialize.mockImplementation( ( blocks ) => {
			if ( nestedTrees[ blocks[ 0 ]?.name ] ) {
				return `canonical-${ blocks[ 0 ].name }`;
			}
			return originalSerialize( blocks );
		} );
		selector.canInsertBlockType.mockImplementation(
			( name, rootClientId ) =>
				rootClientId !== 'group' ||
				[ 'core/list', 'core/columns', 'core/buttons' ].includes( name )
		);

		for ( const name of Object.keys( nestedTrees ) ) {
			expect(
				parseCanonicalMarkup( `nested-${ name }`, selector, 'group' )
			).toMatchObject( { blocks: [ { name } ] } );
		}
		for ( const name of [
			'core/list-item',
			'core/column',
			'core/button',
		] ) {
			expect(
				parseCanonicalMarkup( name, selector, 'group' )
			).toMatchObject( {
				reason: 'validation_failed',
				errors: [ { code: 'disallowed_block', block: name } ],
			} );
		}
	} );

	test( 'rejects a template-locked insertion destination without writing', async () => {
		const { selector, dispatcher } = setEditor();
		const { insertBlockMarkup } = loadModule();

		selector.getBlockListSettings = ( rootClientId ) =>
			rootClientId === 'locked-group' ? { templateLock: 'all' } : {};
		await expect(
			insertBlockMarkup( {
				markup: 'valid',
				rootClientId: 'locked-group',
				index: 1,
			} )
		).resolves.toMatchObject( {
			applied: false,
			reason: 'template_locked',
		} );
		expect( dispatcher.insertBlocks ).not.toHaveBeenCalled();
	} );

	test( 'reports a no-history undo after one native dispatch', async () => {
		const { historyDispatcher } = setEditor();
		const { changeEditorHistory } = loadModule();

		await expect(
			changeEditorHistory( { direction: 'undo' } )
		).resolves.toEqual( {
			applied: false,
			direction: 'undo',
		} );
		expect( historyDispatcher.undo ).toHaveBeenCalledTimes( 1 );
		expect( historyDispatcher.redo ).not.toHaveBeenCalled();
		expect( global.wp.data.dispatch ).toHaveBeenCalledWith( 'core/editor' );
	} );

	test( 'reports a settled synchronous history change and rejects an invalid direction', async () => {
		const { state, historyDispatcher } = setEditor();
		state.canUndo = true;
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

		await expect(
			changeEditorHistory( { direction: 'undo' } )
		).resolves.toEqual( {
			applied: true,
			direction: 'undo',
		} );
		await expect(
			changeEditorHistory( { direction: 'sideways' } )
		).resolves.toMatchObject( {
			applied: false,
			reason: 'invalid_history_direction',
		} );
		expect( historyDispatcher.undo ).toHaveBeenCalledTimes( 1 );
		expect( historyDispatcher.redo ).not.toHaveBeenCalled();
	} );

	test( 'waits for delayed undo and redo store settlement before reporting success', async () => {
		const { state, historyDispatcher, notify } = setEditor();
		state.canUndo = true;
		historyDispatcher.undo.mockImplementation( () => {
			setTimeout( () => {
				state.blocks = {
					restored: {
						clientId: 'restored',
						name: 'core/paragraph',
						markup: '<!-- wp:paragraph -->Restored<!-- /wp:paragraph -->',
						attributes: {},
						innerBlocks: [],
					},
				};
				state.canUndo = false;
				state.canRedo = true;
				notify();
			}, 0 );
		} );
		historyDispatcher.redo.mockImplementation( () => {
			setTimeout( () => {
				state.blocks = {
					redone: {
						clientId: 'redone',
						name: 'core/paragraph',
						markup: '<!-- wp:paragraph -->Redone<!-- /wp:paragraph -->',
						attributes: {},
						innerBlocks: [],
					},
				};
				state.canUndo = true;
				state.canRedo = false;
				notify();
			}, 0 );
		} );
		const { changeEditorHistory } = loadModule();

		await expect(
			changeEditorHistory( { direction: 'undo' } )
		).resolves.toEqual( {
			applied: true,
			direction: 'undo',
		} );
		expect(
			global.wp.blocks.serialize( Object.values( state.blocks ) )
		).toBe( '<!-- wp:paragraph -->Restored<!-- /wp:paragraph -->' );
		await expect(
			changeEditorHistory( { direction: 'redo' } )
		).resolves.toEqual( {
			applied: true,
			direction: 'redo',
		} );
		expect(
			global.wp.blocks.serialize( Object.values( state.blocks ) )
		).toBe( '<!-- wp:paragraph -->Redone<!-- /wp:paragraph -->' );
		expect( historyDispatcher.undo ).toHaveBeenCalledTimes( 1 );
		expect( historyDispatcher.redo ).toHaveBeenCalledTimes( 1 );
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
		await expect(
			changeEditorHistory( { direction: 'undo' } )
		).resolves.toMatchObject( {
			applied: 'unknown',
			direction: 'undo',
			reason: 'dispatch_failed',
		} );
	} );
} );
