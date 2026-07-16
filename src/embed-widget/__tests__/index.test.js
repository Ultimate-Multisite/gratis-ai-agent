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

	test( 'starts collapsed and close button collapses the panel', () => {
		const root = module.mountEmbed( {
			...module.resolveConfig( null, {} ),
			apiBase: 'https://example.test/wp-json/sd-ai-agent/v1',
			mount: '#mount',
		} );
		const launcher = root.querySelector( '.sdaa-embed__launcher' );
		const panel = root.querySelector( '.sdaa-embed__panel' );
		const close = root.querySelector( '.sdaa-embed__close' );

		expect( panel.hidden ).toBe( true );
		expect( launcher.getAttribute( 'aria-expanded' ) ).toBe( 'false' );

		launcher.click();
		expect( panel.hidden ).toBe( false );
		expect( launcher.getAttribute( 'aria-expanded' ) ).toBe( 'true' );

		close.click();
		expect( panel.hidden ).toBe( true );
		expect( launcher.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
	} );

	test( 'uses an autosizing multiline textarea for user input', () => {
		const root = module.mountEmbed( {
			...module.resolveConfig( null, {} ),
			apiBase: 'https://example.test/wp-json/sd-ai-agent/v1',
			mount: '#mount',
		} );
		const input = root.querySelector( '.sdaa-embed__input' );

		expect( input.tagName ).toBe( 'TEXTAREA' );
		expect( input.getAttribute( 'rows' ) ).toBe( '1' );

		Object.defineProperty( input, 'scrollHeight', {
			configurable: true,
			value: 72,
		} );
		input.value = 'Line one\nLine two\nLine three';
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );

		expect( input.style.height ).toBe( '72px' );
	} );

	test( 'submits textarea on Enter while preserving Shift+Enter for newlines', () => {
		const root = module.mountEmbed( {
			...module.resolveConfig( null, {} ),
			apiBase: 'https://example.test/wp-json/sd-ai-agent/v1',
			mount: '#mount',
		} );
		const form = root.querySelector( '.sdaa-embed__form' );
		const input = root.querySelector( '.sdaa-embed__input' );
		form.requestSubmit = jest.fn();

		input.dispatchEvent(
			new KeyboardEvent( 'keydown', {
				bubbles: true,
				key: 'Enter',
				shiftKey: true,
			} )
		);
		expect( form.requestSubmit ).not.toHaveBeenCalled();

		input.dispatchEvent(
			new KeyboardEvent( 'keydown', { bubbles: true, key: 'Enter' } )
		);
		expect( form.requestSubmit ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'renders assistant markdown and makes source URLs clickable', () => {
		const root = module.mountEmbed( {
			...module.resolveConfig( null, {} ),
			apiBase: 'https://example.test/wp-json/sd-ai-agent/v1',
			greeting:
				'Read **Setup Wizard**. Source: https://example.test/docs/setup.',
			mount: '#mount',
		} );

		const message = root.querySelector( '.sdaa-embed__message--assistant' );
		const sourceLink = message.querySelector( 'a' );

		expect( message.querySelector( 'strong' ).textContent ).toBe(
			'Setup Wizard'
		);
		expect( sourceLink.textContent ).toBe(
			'https://example.test/docs/setup'
		);
		expect( sourceLink.getAttribute( 'href' ) ).toBe(
			'https://example.test/docs/setup'
		);
		expect( sourceLink.getAttribute( 'target' ) ).toBe( '_blank' );
		expect( sourceLink.getAttribute( 'rel' ) ).toBe(
			'noopener noreferrer'
		);
		expect( message.textContent ).toBe(
			'Read Setup Wizard. Source: https://example.test/docs/setup.'
		);
	} );

	test( 'escapes raw HTML while rendering inline code', () => {
		const container = document.createElement( 'div' );
		container.appendChild(
			module.renderMarkdown(
				'Use <img src=x onerror=alert(1)> and `wp config`.'
			)
		);

		expect( container.querySelector( 'img' ) ).toBeNull();
		expect( container.querySelector( 'code' ).textContent ).toBe(
			'wp config'
		);
		expect( container.textContent ).toContain(
			'<img src=x onerror=alert(1)>'
		);
	} );

	test( 'keeps underscores and asterisks inside identifiers literal', () => {
		const container = document.createElement( 'div' );
		container.appendChild(
			module.renderMarkdown(
				'Keep wp_config_value, some_snake_case, and word*star*word literal; render _emphasis_.'
			)
		);

		expect( container.querySelectorAll( 'em' ) ).toHaveLength( 1 );
		expect( container.querySelector( 'em' ).textContent ).toBe(
			'emphasis'
		);
		expect( container.textContent ).toContain( 'wp_config_value' );
		expect( container.textContent ).toContain( 'some_snake_case' );
		expect( container.textContent ).toContain( 'word*star*word' );
	} );
} );
