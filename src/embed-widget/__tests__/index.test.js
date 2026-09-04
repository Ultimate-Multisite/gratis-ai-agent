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

	/**
	 * Flush queued mocked fetch promises.
	 *
	 * @return {Promise<void>} Resolved after queued promise callbacks.
	 */
	async function flushRequests() {
		await Promise.resolve();
		await Promise.resolve();
		await Promise.resolve();
		await Promise.resolve();
		await Promise.resolve();
		await Promise.resolve();
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	}

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

	test( 'derives the owning WordPress account settings page from the REST base', () => {
		expect(
			module.getAccountSettingsUrl(
				'https://example.test/subsite/wp-json/sd-ai-agent/v1'
			)
		).toBe(
			'https://example.test/subsite/wp-admin/admin.php?page=sd-ai-agent#/settings'
		);
		expect(
			module.getAccountSettingsUrl( 'https://example.test/api' )
		).toBe( '' );
	} );

	test( 'renders managed-credit notices with linked settings text and CTA', () => {
		const message = document.createElement( 'div' );
		module.renderAccountActionNotice( message, {
			type: 'account_action',
			reason: 'credit_exhausted',
			action: 'purchase_credits',
			actionUrl:
				'https://example.test/wp-admin/admin.php?page=sd-ai-agent#/settings',
		} );

		const links = message.querySelectorAll( 'a' );
		expect( message.textContent ).toContain(
			'Purchase more credits in your account settings'
		);
		expect( links ).toHaveLength( 2 );
		expect( links[ 0 ].textContent ).toBe( 'account settings' );
		expect( links[ 1 ].textContent ).toBe( 'Purchase credits' );
		expect( links[ 0 ].getAttribute( 'href' ) ).toBe(
			'https://example.test/wp-admin/admin.php?page=sd-ai-agent#/settings'
		);
		expect( links[ 1 ].getAttribute( 'href' ) ).toBe(
			'https://example.test/wp-admin/admin.php?page=sd-ai-agent#/settings'
		);
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

	test( 'does not create a recorded session until a visitor explicitly chooses it', async () => {
		global.fetch = jest.fn( ( url ) => {
			if ( url.endsWith( '/public-chat/config' ) ) {
				return Promise.resolve( {
					ok: true,
					json: () =>
						Promise.resolve( {
							enabled: true,
							recording: {
								enabled: true,
								disclosure:
									'Opt in before this chat is retained for review.',
							},
						} ),
				} );
			}

			return Promise.resolve( {
				ok: true,
				json: () => Promise.resolve( { token: 'public-token' } ),
			} );
		} );

		const root = module.mountEmbed( {
			...module.resolveConfig( null, {} ),
			apiBase: 'https://example.test/wp-json/sd-ai-agent/v1',
			mount: '#mount',
		} );
		await flushRequests();

		expect(
			root.querySelector( '.sdaa-embed__recording-choice' ).textContent
		).toContain( 'Opt in before this chat is retained for review.' );
		expect(
			global.fetch.mock.calls.filter( ( [ url ] ) =>
				url.endsWith( '/public-chat/session' )
			)
		).toHaveLength( 0 );

		root.querySelector( '[data-recording-consent="false"]' ).click();
		await flushRequests();

		const sessionRequest = global.fetch.mock.calls.find( ( [ url ] ) =>
			url.endsWith( '/public-chat/session' )
		);
		expect( sessionRequest[ 1 ].body ).toBe(
			JSON.stringify( {
				recording_consent: false,
				embed_id: '',
				locale: 'en',
			} )
		);
	} );

	test( 'sends explicit recording consent only after the visitor agrees', async () => {
		global.fetch = jest.fn( ( url ) => {
			if ( url.endsWith( '/public-chat/config' ) ) {
				return Promise.resolve( {
					ok: true,
					json: () =>
						Promise.resolve( {
							enabled: true,
							recording: {
								enabled: true,
								disclosure: 'Recording is optional.',
							},
						} ),
				} );
			}

			return Promise.resolve( {
				ok: true,
				json: () => Promise.resolve( { token: 'public-token' } ),
			} );
		} );

		const root = module.mountEmbed( {
			...module.resolveConfig( null, {} ),
			apiBase: 'https://example.test/wp-json/sd-ai-agent/v1',
			mount: '#mount',
		} );
		await flushRequests();

		root.querySelector( '[data-recording-consent="true"]' ).click();
		await flushRequests();

		const sessionRequest = global.fetch.mock.calls.find( ( [ url ] ) =>
			url.endsWith( '/public-chat/session' )
		);
		expect( sessionRequest[ 1 ].body ).toBe(
			JSON.stringify( {
				recording_consent: true,
				embed_id: '',
				locale: 'en',
			} )
		);
		expect(
			root.querySelector( '.sdaa-embed__recording-choice' )
		).toBeNull();
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

	test( 'renders assistant markdown and makes source URLs clickable', async () => {
		global.fetch = jest.fn( ( url ) => {
			if ( url.endsWith( '/public-chat/config' ) ) {
				return Promise.resolve( {
					ok: true,
					json: () => Promise.resolve( { enabled: true } ),
				} );
			}

			return Promise.resolve( {
				ok: true,
				json: () => Promise.resolve( { token: 'public-token' } ),
			} );
		} );

		const root = module.mountEmbed( {
			...module.resolveConfig( null, {} ),
			apiBase: 'https://example.test/wp-json/sd-ai-agent/v1',
			greeting:
				'Read **Setup Wizard**. Source: https://example.test/docs/setup.',
			mount: '#mount',
		} );
		await flushRequests();

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

	test( 'keeps public polling tokens out of the URL', async () => {
		const client = module.createPublicClient( {
			apiBase: 'https://example.test/wp-json/sd-ai-agent/v1',
		} );

		await client.poll( 'job-id', 'session-token' );

		expect( global.fetch ).toHaveBeenLastCalledWith(
			'https://example.test/wp-json/sd-ai-agent/v1/public-chat/job/job-id',
			expect.objectContaining( {
				credentials: 'omit',
				headers: expect.objectContaining( {
					Authorization: 'Bearer session-token',
				} ),
			} )
		);
		expect( global.fetch.mock.calls.at( -1 )[ 0 ] ).not.toContain(
			'session-token'
		);
	} );

	test( 'shows disclosure before requesting the microphone and releases capture on close', async () => {
		const mediaDevicesDescriptor = Object.getOwnPropertyDescriptor(
			navigator,
			'mediaDevices'
		);
		const OriginalMediaRecorder = global.MediaRecorder;
		const OriginalAudioContext = global.AudioContext;
		const track = { stop: jest.fn() };
		const getUserMedia = jest.fn().mockResolvedValue( {
			getTracks: () => [ track ],
		} );
		class MockMediaRecorder {
			constructor() {
				this.state = 'inactive';
			}

			start() {
				this.state = 'recording';
			}

			stop() {
				this.state = 'inactive';
				this.onstop?.();
			}
		}
		MockMediaRecorder.isTypeSupported = jest.fn().mockReturnValue( true );

		Object.defineProperty( navigator, 'mediaDevices', {
			configurable: true,
			value: { getUserMedia },
		} );
		global.MediaRecorder = MockMediaRecorder;
		global.AudioContext = jest.fn();
		global.fetch = jest.fn( ( url ) =>
			Promise.resolve( {
				ok: true,
				json: () =>
					Promise.resolve(
						url.endsWith( '/public-chat/config' )
							? {
									enabled: true,
									speech: {
										enabled: true,
										capture_mime_types: [ 'audio/webm' ],
										disclosure:
											'Audio is sent for transcription.',
										max_audio_bytes: 1000,
										max_recording_duration_seconds: 10,
										voice_conversation_enabled: false,
									},
							  }
							: { token: 'public-token' }
					),
			} )
		);

		try {
			const root = module.mountEmbed( {
				...module.resolveConfig( null, {} ),
				apiBase: 'https://example.test/wp-json/sd-ai-agent/v1',
				mount: '#mount',
			} );
			await flushRequests();

			const disclosure = root.querySelector(
				'.sd-ai-agent-embed-speech-disclosure'
			);
			const consent = root.querySelector(
				'.sd-ai-agent-embed-speech-consent'
			);
			expect( disclosure.hidden ).toBe( false );
			expect( disclosure.textContent ).toContain(
				'Audio is sent for transcription.'
			);
			expect( getUserMedia ).not.toHaveBeenCalled();

			consent.click();
			await flushRequests();
			expect( getUserMedia ).toHaveBeenCalledTimes( 1 );
			expect(
				root.querySelector( '.sd-ai-agent-embed-microphone' ).hidden
			).toBe( false );

			root.querySelector( '.sdaa-embed__close' ).click();
			expect( track.stop ).toHaveBeenCalledTimes( 1 );
		} finally {
			if ( mediaDevicesDescriptor ) {
				Object.defineProperty(
					navigator,
					'mediaDevices',
					mediaDevicesDescriptor
				);
			} else {
				delete navigator.mediaDevices;
			}
			global.MediaRecorder = OriginalMediaRecorder;
			global.AudioContext = OriginalAudioContext;
		}
	} );

	test( 'returns voice mode to idle after playback and requires a fresh microphone gesture', async () => {
		const mediaDevicesDescriptor = Object.getOwnPropertyDescriptor(
			navigator,
			'mediaDevices'
		);
		const createObjectUrlDescriptor = Object.getOwnPropertyDescriptor(
			URL,
			'createObjectURL'
		);
		const revokeObjectUrlDescriptor = Object.getOwnPropertyDescriptor(
			URL,
			'revokeObjectURL'
		);
		const OriginalMediaRecorder = global.MediaRecorder;
		const OriginalAudioContext = global.AudioContext;
		const OriginalAudio = global.Audio;
		const tracks = [];
		const getUserMedia = jest.fn().mockImplementation( () => {
			const track = { stop: jest.fn() };
			tracks.push( track );
			return Promise.resolve( { getTracks: () => [ track ] } );
		} );
		class MockMediaRecorder {
			constructor() {
				this.state = 'inactive';
			}

			start() {
				this.state = 'recording';
			}

			stop() {
				this.state = 'inactive';
				this.onstop?.();
			}
		}
		MockMediaRecorder.isTypeSupported = jest.fn().mockReturnValue( true );
		const audio = {
			pause: jest.fn(),
			play: jest.fn().mockResolvedValue( undefined ),
			removeAttribute: jest.fn(),
		};
		const createObjectURL = jest.fn().mockReturnValue( 'blob:reply' );
		const revokeObjectURL = jest.fn();

		Object.defineProperty( navigator, 'mediaDevices', {
			configurable: true,
			value: { getUserMedia },
		} );
		Object.defineProperty( URL, 'createObjectURL', {
			configurable: true,
			value: createObjectURL,
		} );
		Object.defineProperty( URL, 'revokeObjectURL', {
			configurable: true,
			value: revokeObjectURL,
		} );
		global.MediaRecorder = MockMediaRecorder;
		global.AudioContext = jest.fn();
		global.Audio = jest.fn( () => audio );
		global.fetch = jest.fn( ( url ) => {
			let data;
			if ( url.endsWith( '/public-chat/config' ) ) {
				data = {
					enabled: true,
					speech: {
						enabled: true,
						capture_mime_types: [ 'audio/webm' ],
						disclosure: 'Audio is sent for transcription.',
						max_audio_bytes: 1000,
						max_recording_duration_seconds: 10,
						voice_conversation_enabled: true,
					},
				};
			} else if ( url.endsWith( '/public-chat/session' ) ) {
				data = { token: 'public-token' };
			} else if ( url.endsWith( '/public-chat/run' ) ) {
				data = {
					reply: 'Spoken reply.',
					speech: { synthesis_grant: 'reply-grant' },
					status: 'complete',
				};
			} else {
				data = { audio: 'YQ==', mime_type: 'audio/mpeg' };
			}

			return Promise.resolve( {
				ok: true,
				json: () => Promise.resolve( data ),
			} );
		} );

		try {
			const root = module.mountEmbed( {
				...module.resolveConfig( null, {} ),
				apiBase: 'https://example.test/wp-json/sd-ai-agent/v1',
				mount: '#mount',
			} );
			root.querySelector( '.sdaa-embed__launcher' ).click();
			await flushRequests();
			root.querySelector( '.sd-ai-agent-embed-speech-consent' ).click();
			await flushRequests();
			expect( getUserMedia ).toHaveBeenCalledTimes( 1 );

			const voiceMode = root.querySelector(
				'.sd-ai-agent-embed-voice-mode input'
			);
			voiceMode.checked = true;
			root.querySelector( '.sd-ai-agent-embed-microphone' ).click();
			await flushRequests();

			const input = root.querySelector( '.sdaa-embed__input' );
			input.value = 'Typed voice-mode turn';
			root.querySelector( '.sdaa-embed__form' ).dispatchEvent(
				new Event( 'submit', { bubbles: true, cancelable: true } )
			);
			await flushRequests();
			expect( audio.play ).toHaveBeenCalledTimes( 1 );
			expect( getUserMedia ).toHaveBeenCalledTimes( 1 );

			audio.onended();
			await flushRequests();
			expect( revokeObjectURL ).toHaveBeenCalledWith( 'blob:reply' );
			expect( getUserMedia ).toHaveBeenCalledTimes( 1 );

			root.querySelector( '.sdaa-embed__close' ).click();
			expect( tracks ).toHaveLength( 1 );
			expect(
				tracks.every( ( track ) => track.stop.mock.calls.length > 0 )
			).toBe( true );
		} finally {
			if ( mediaDevicesDescriptor ) {
				Object.defineProperty(
					navigator,
					'mediaDevices',
					mediaDevicesDescriptor
				);
			} else {
				delete navigator.mediaDevices;
			}
			if ( createObjectUrlDescriptor ) {
				Object.defineProperty(
					URL,
					'createObjectURL',
					createObjectUrlDescriptor
				);
			} else {
				delete URL.createObjectURL;
			}
			if ( revokeObjectUrlDescriptor ) {
				Object.defineProperty(
					URL,
					'revokeObjectURL',
					revokeObjectUrlDescriptor
				);
			} else {
				delete URL.revokeObjectURL;
			}
			global.MediaRecorder = OriginalMediaRecorder;
			global.AudioContext = OriginalAudioContext;
			global.Audio = OriginalAudio;
		}
	} );
} );
