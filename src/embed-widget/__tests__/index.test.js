describe( 'embed widget', () => {
	let module;

	beforeEach( () => {
		document.body.innerHTML = '<div id="mount"></div>';
		window.sdAiAgentEmbed = undefined;
		global.fetch = jest.fn( () =>
			Promise.resolve( {
				ok: true,
				json: () => Promise.resolve( { enabled: true } ),
			} )
		);
		jest.isolateModules( () => {
			module = require( '../index' );
		} );
	} );

	afterEach( () => {
		delete global.fetch;
	} );

	test( 'resolves script data attributes without WordPress globals', () => {
		const script = document.createElement( 'script' );
		script.dataset.apiBase = 'https://example.test/wp-json/sd-ai-agent/v1';
		script.dataset.embedId = 'docs';
		script.dataset.theme = 'dark';

		const config = module.resolveConfig( script, {} );

		expect( config.apiBase ).toBe(
			'https://example.test/wp-json/sd-ai-agent/v1'
		);
		expect( config.embedId ).toBe( 'docs' );
		expect( config.theme ).toBe( 'dark' );
		expect( window.wp ).toBeUndefined();
	} );

	test( 'mounts a launcher and panel in a plain DOM fixture', () => {
		const root = module.mountEmbed( {
			...module.resolveConfig( null, {} ),
			apiBase: 'https://example.test/wp-json/sd-ai-agent/v1',
			mount: '#mount',
		} );

		expect( root.querySelector( '.sdaa-embed__launcher' ) ).not.toBeNull();
		expect( root.querySelector( '.sdaa-embed__panel' ) ).not.toBeNull();
		expect( global.fetch ).toHaveBeenCalledWith(
			'https://example.test/wp-json/sd-ai-agent/v1/public-chat/config',
			expect.objectContaining( { credentials: 'omit' } )
		);
	} );
} );
