/** Unit tests for the read-only Gutenberg editor selection ability. */

/**
 * Load an isolated editor ability module.
 *
 * @return {Object} Editor ability exports.
 */
function loadEditorModule() {
	let mod;
	jest.isolateModules( () => {
		// eslint-disable-next-line global-require
		mod = require( '../editor' );
	} );
	return mod;
}

/**
 * Load isolated editor and registry modules from the same module instance.
 *
 * @return {{ editor: Object, registry: Object }} Editor and registry exports.
 */
function loadEditorAndRegistry() {
	let editor;
	let registry;
	jest.isolateModules( () => {
		// eslint-disable-next-line global-require
		editor = require( '../editor' );
		// eslint-disable-next-line global-require
		registry = require( '../registry' );
	} );
	return { editor, registry };
}

/**
 * Set a minimal Gutenberg editor environment.
 *
 * @param {Object}   options               Mock editor state.
 * @param {string[]} [options.selectedIds] Selected block client IDs.
 * @param {Object}   [options.blocks]      Blocks keyed by client ID.
 * @param {Object}   [options.rootIds]     Parent client IDs keyed by client ID.
 * @param {string}   [options.markup]      Serialized selected-block markup.
 * @return {void}
 */
function setEditor( {
	selectedIds = [],
	blocks = {},
	rootIds = {},
	markup = '',
} ) {
	global.wp = {
		data: {
			select: jest.fn( () => ( {
				getSelectedBlockClientIds: () => selectedIds,
				getBlock: ( clientId ) => blocks[ clientId ],
				getBlockRootClientId: ( clientId ) => rootIds[ clientId ] || '',
				getBlockIndex: ( clientId ) => selectedIds.indexOf( clientId ),
			} ) ),
			dispatch: jest.fn(),
		},
		blocks: {
			serialize: jest.fn( () => markup ),
		},
	};
}

describe( 'get-editor-selection', () => {
	afterEach( () => {
		delete global.wp;
	} );

	test( 'returns ordered selected-only markup and parent context', async () => {
		setEditor( {
			selectedIds: [ 'first', 'second' ],
			blocks: {
				first: { clientId: 'first', name: 'core/paragraph' },
				second: { clientId: 'second', name: 'core/image' },
				parent: { clientId: 'parent', name: 'core/group' },
			},
			rootIds: { first: 'parent' },
			markup: '<!-- wp:paragraph -->One<!-- /wp:paragraph --><!-- wp:image /-->',
		} );

		const { getEditorSelection } = loadEditorModule();
		const result = await getEditorSelection();

		expect( result ).toMatchObject( {
			available: true,
			selected: true,
			count: 2,
			originalCount: 2,
			markup: '<!-- wp:paragraph -->One<!-- /wp:paragraph --><!-- wp:image /-->',
			truncated: false,
			reason: '',
		} );
		expect( result.blocks ).toEqual( [
			{
				clientId: 'first',
				name: 'core/paragraph',
				parent: { clientId: 'parent', name: 'core/group', position: 0 },
			},
			{
				clientId: 'second',
				name: 'core/image',
				parent: { clientId: '', name: '', position: 1 },
			},
		] );
		expect( result.fingerprint ).toMatch( /^(sha256|fallback):/ );
		expect( global.wp.data.dispatch ).not.toHaveBeenCalled();
	} );

	test( 'registers the current-selection descriptor as read-only', async () => {
		const { editor, registry } = loadEditorAndRegistry();
		await editor.registerEditorAbility();
		const descriptor = ( await registry.snapshotDescriptors() ).find(
			( candidate ) =>
				candidate.name === 'sd-ai-agent-js/get-editor-selection'
		);

		expect( descriptor ).toMatchObject( {
			name: 'sd-ai-agent-js/get-editor-selection',
			annotations: { readonly: true },
		} );
		expect( descriptor.output_schema.properties ).toHaveProperty(
			'fingerprint'
		);
	} );

	test( 'returns a stable fingerprint for the same current selection', async () => {
		setEditor( {
			selectedIds: [ 'first' ],
			blocks: { first: { clientId: 'first', name: 'core/paragraph' } },
			markup: '<!-- wp:paragraph -->One<!-- /wp:paragraph -->',
		} );
		const { getEditorSelection } = loadEditorModule();

		expect( ( await getEditorSelection() ).fingerprint ).toBe(
			( await getEditorSelection() ).fingerprint
		);
	} );

	test( 'returns successful structured empty states outside the editor and without a selection', async () => {
		let { getEditorSelection } = loadEditorModule();
		expect( await getEditorSelection() ).toMatchObject( {
			available: false,
			selected: false,
			reason: 'unavailable',
		} );

		setEditor( {} );
		( { getEditorSelection } = loadEditorModule() );
		expect( await getEditorSelection() ).toMatchObject( {
			available: true,
			selected: false,
			reason: 'no_selection',
		} );
	} );

	test( 'reports stale selected block IDs instead of serializing another selection', async () => {
		setEditor( {
			selectedIds: [ 'missing' ],
			blocks: {},
		} );
		const { getEditorSelection } = loadEditorModule();

		expect( await getEditorSelection() ).toMatchObject( {
			available: true,
			selected: true,
			truncated: true,
			reason: 'missing_selected_block',
			markup: '',
			fingerprint: '',
		} );
	} );

	test( 'caps oversized selections and markup without returning unrelated content', async () => {
		const selectedIds = Array.from(
			{ length: 51 },
			( _, index ) => `block-${ index }`
		);
		const blocks = Object.fromEntries(
			selectedIds.map( ( clientId ) => [
				clientId,
				{ clientId, name: 'core/paragraph' },
			] )
		);
		setEditor( {
			selectedIds,
			blocks,
			markup: '<!-- wp:paragraph -->One<!-- /wp:paragraph -->',
		} );
		let { getEditorSelection } = loadEditorModule();
		expect( await getEditorSelection() ).toMatchObject( {
			count: 50,
			originalCount: 51,
			truncated: true,
			reason: 'selection_limit',
		} );

		setEditor( {
			selectedIds: [ 'large' ],
			blocks: { large: { clientId: 'large', name: 'core/paragraph' } },
			markup: 'x'.repeat( 64 * 1024 + 1 ),
		} );
		( { getEditorSelection } = loadEditorModule() );
		expect( await getEditorSelection() ).toMatchObject( {
			truncated: true,
			reason: 'markup_limit',
			markup: '',
			fingerprint: '',
		} );
	} );
} );
