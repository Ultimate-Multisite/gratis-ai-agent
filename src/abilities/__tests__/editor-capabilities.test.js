/** Unit tests for the read-only Gutenberg editor capability manifest. */

/**
 * Load an isolated editor capabilities module.
 *
 * @return {Object} Module exports.
 */
function loadModule() {
	let mod;
	jest.isolateModules( () => {
		// eslint-disable-next-line global-require
		mod = require( '../editor-capabilities' );
	} );
	return mod;
}

/**
 * Load editor capabilities and its matching registry module instance.
 *
 * @return {{ capabilities: Object, registry: Object }} Isolated modules.
 */
function loadCapabilitiesAndRegistry() {
	let capabilities;
	let registry;
	jest.isolateModules( () => {
		// eslint-disable-next-line global-require
		capabilities = require( '../editor-capabilities' );
		// eslint-disable-next-line global-require
		registry = require( '../registry' );
	} );
	return { capabilities, registry };
}

/**
 * Set a minimal Gutenberg editor environment.
 *
 * @param {Object}   root0
 * @param {Object[]} [root0.blocks]
 * @param {Object}   [root0.settings]
 * @param {Function} [root0.canInsertBlockType]
 */
function setEditor( { blocks = [], settings, canInsertBlockType } = {} ) {
	global.wp = {
		blocks: {
			getBlockTypes: jest.fn( () => blocks ),
			getBlockVariations: jest.fn( () => [] ),
			getBlockStyles: jest.fn( () => [] ),
		},
		data: {
			select: jest.fn( () => ( {
				getSettings: () => settings,
				canInsertBlockType,
			} ) ),
		},
	};
}

describe( 'get-editor-capabilities', () => {
	afterEach( () => {
		delete global.wp;
	} );

	test( 'returns bounded allowlisted details for requested blocks', () => {
		setEditor( {
			blocks: [
				{
					name: 'core/paragraph',
					title: 'Paragraph',
					category: 'text',
					supports: {
						color: { text: true, custom: 'unsafe' },
						html: false,
					},
					attributes: {
						content: { type: 'string' },
						unknown: { type: 'object', default: { private: true } },
					},
				},
			],
			settings: {
				colors: [
					{ name: 'Primary', slug: 'primary', color: '#000000' },
				],
				layout: { contentSize: '640px', wideSize: '1200px' },
			},
			canInsertBlockType: ( name ) => name === 'core/paragraph',
		} );

		const { getEditorCapabilities } = loadModule();
		const result = getEditorCapabilities( {
			blockNames: [ 'core/paragraph', 'missing/block' ],
		} );

		expect( result ).toMatchObject( {
			available: true,
			block_names: [ 'core/paragraph' ],
			unregistered: [ 'missing/block' ],
			global: { colors: [ { slug: 'primary', color: '#000000' } ] },
		} );
		expect( result.block_details[ 0 ] ).toMatchObject( {
			name: 'core/paragraph',
			allowed: true,
			supports: { color: [ 'text' ], html: false },
			attributes: [ { name: 'content', type: 'string' } ],
		} );
		expect( JSON.stringify( result ) ).not.toContain( 'private' );
	} );

	test( 'caps summary and detailed requested blocks', () => {
		const blocks = Array.from( { length: 101 }, ( _, index ) => ( {
			name: `plugin/block-${ index }`,
		} ) );
		setEditor( { blocks, settings: {} } );
		const { getEditorCapabilities } = loadModule();
		const result = getEditorCapabilities( {
			blockNames: Array.from(
				{ length: 21 },
				( _, index ) => `plugin/block-${ index }`
			),
		} );

		expect( result.block_names ).toHaveLength( 100 );
		expect( result.block_details ).toHaveLength( 20 );
		expect( result.omitted_blocks ).toBe( 1 );
		expect( result.truncated ).toBe( true );
	} );

	test( 'reports unavailable optional sources without throwing', () => {
		global.wp = {
			blocks: { getBlockTypes: () => [ { name: 'core/paragraph' } ] },
			data: { select: () => ( {} ) },
		};
		const { getEditorCapabilities } = loadModule();
		const result = getEditorCapabilities();

		expect( result ).toMatchObject( {
			available: true,
			block_names: [ 'core/paragraph' ],
		} );
		expect( result.unavailable_sources ).toEqual(
			expect.arrayContaining( [
				'editor_settings',
				'allowed_block_constraints',
				'block_style_metadata',
			] )
		);
	} );

	test( 'returns a structured unavailable response outside Gutenberg', () => {
		const { getEditorCapabilities } = loadModule();
		expect( getEditorCapabilities() ).toMatchObject( {
			available: false,
			unavailable_sources: [ 'block_registry', 'editor_settings' ],
		} );
	} );

	test( 'registers an optional blockNames schema without required', async () => {
		const { capabilities, registry } = loadCapabilitiesAndRegistry();
		await capabilities.registerEditorCapabilitiesAbility();
		const descriptor = ( await registry.snapshotDescriptors() ).find(
			( candidate ) =>
				candidate.name === 'sd-ai-agent-js/get-editor-capabilities'
		);

		expect( descriptor.input_schema ).toMatchObject( {
			type: 'object',
			properties: {
				blockNames: { type: 'array' },
			},
		} );
		expect( descriptor.input_schema ).not.toHaveProperty( 'required' );
	} );
} );
