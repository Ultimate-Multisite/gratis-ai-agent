/**
 * Static-site embeddable customer chat widget.
 *
 * This bundle intentionally avoids WordPress runtime packages and globals so it
 * can run on Docusaurus or any plain HTML page. Configure it with either
 * `window.sdAiAgentEmbed` before loading the script or data attributes on the
 * script tag.
 */

import './style.css';

const DEFAULTS = {
	apiBase: '',
	embedId: '',
	agentId: '',
	collection: '',
	locale: 'en',
	theme: 'light',
	mount: '',
	greeting: 'Hi! Ask me a question about the docs.',
};

const STRINGS = {
	unavailable:
		'The chat assistant is not available right now. Please try again later.',
	placeholder: 'Ask a question…',
	send: 'Send',
	open: 'Open docs chat',
	close: 'Close',
	thinking: 'Thinking…',
};

/**
 * Resolve configuration from script attributes and the optional global object.
 *
 * @param {HTMLScriptElement|null} script       Script element that loaded the bundle.
 * @param {Object}                 globalConfig Optional global configuration object.
 * @return {Object} Normalized embed configuration.
 */
export function resolveConfig(
	script = document.currentScript,
	globalConfig = window.sdAiAgentEmbed || {}
) {
	const dataset = script?.dataset || {};
	return {
		...DEFAULTS,
		...globalConfig,
		apiBase: dataset.apiBase || globalConfig.apiBase || DEFAULTS.apiBase,
		embedId: dataset.embedId || globalConfig.embedId || DEFAULTS.embedId,
		agentId: dataset.agentId || globalConfig.agentId || DEFAULTS.agentId,
		collection:
			dataset.collection ||
			globalConfig.collection ||
			DEFAULTS.collection,
		locale: dataset.locale || globalConfig.locale || DEFAULTS.locale,
		theme: dataset.theme || globalConfig.theme || DEFAULTS.theme,
		mount: dataset.mount || globalConfig.mount || DEFAULTS.mount,
		greeting:
			dataset.greeting || globalConfig.greeting || DEFAULTS.greeting,
	};
}

/**
 * Join a REST API base URL and path without leaking credentials/cookies.
 *
 * @param {string} apiBase Base REST URL.
 * @param {string} path    REST path.
 * @return {string} Absolute endpoint URL.
 */
export function endpoint( apiBase, path ) {
	return `${ apiBase.replace( /\/$/, '' ) }/${ path.replace( /^\//, '' ) }`;
}

/**
 * Create the public chat API client.
 *
 * @param {Object} config Embed configuration.
 * @return {Object} Client methods.
 */
export function createPublicClient( config ) {
	const request = async ( path, options = {} ) => {
		const response = await fetch( endpoint( config.apiBase, path ), {
			...options,
			credentials: 'omit',
			headers: {
				Accept: 'application/json',
				'Content-Type': 'application/json',
				...( options.headers || {} ),
			},
		} );
		const data = await response.json().catch( () => ( {} ) );
		if ( ! response.ok ) {
			throw new Error( data?.message || STRINGS.unavailable );
		}
		return data;
	};

	return {
		config: () => request( '/public-chat/config', { method: 'GET' } ),
		session: () => request( '/public-chat/session', { method: 'POST' } ),
		send: ( message, sessionToken ) =>
			request( '/public-chat/run', {
				method: 'POST',
				body: JSON.stringify( {
					message,
					token: sessionToken,
				} ),
			} ),
		poll: ( jobId, sessionToken ) =>
			request(
				`/public-chat/job/${ encodeURIComponent(
					jobId
				) }?token=${ encodeURIComponent( sessionToken ) }`,
				{ method: 'GET' }
			),
	};
}

/**
 * Mount a dependency-free public chat widget.
 *
 * @param {Object} config Embed configuration.
 * @return {HTMLElement} Root element.
 */
export function mountEmbed( config ) {
	const resolvedMount = config.mount
		? document.querySelector( config.mount )
		: null;
	const root = resolvedMount || document.createElement( 'div' );
	root.className = `sdaa-embed sdaa-embed--${ config.theme }`;
	root.innerHTML = `
		<button class="sdaa-embed__launcher" type="button" aria-expanded="false">
			<span aria-hidden="true">✦</span>
			<span class="screen-reader-text">${ STRINGS.open }</span>
		</button>
		<section class="sdaa-embed__panel" aria-live="polite" hidden>
			<header class="sdaa-embed__header">
				<strong>Docs assistant</strong>
				<button class="sdaa-embed__close" type="button">${ STRINGS.close }</button>
			</header>
			<div class="sdaa-embed__messages"></div>
			<form class="sdaa-embed__form">
				<input class="sdaa-embed__input" type="text" autocomplete="off" placeholder="${ STRINGS.placeholder }" />
				<button class="sdaa-embed__send" type="submit">${ STRINGS.send }</button>
			</form>
		</section>`;

	if ( ! resolvedMount ) {
		document.body.appendChild( root );
	}

	const client = createPublicClient( config );
	const launcher = root.querySelector( '.sdaa-embed__launcher' );
	const panel = root.querySelector( '.sdaa-embed__panel' );
	const close = root.querySelector( '.sdaa-embed__close' );
	const messages = root.querySelector( '.sdaa-embed__messages' );
	const form = root.querySelector( '.sdaa-embed__form' );
	const input = root.querySelector( '.sdaa-embed__input' );
	let sessionToken = '';

	const addMessage = ( role, text ) => {
		const item = document.createElement( 'div' );
		item.className = `sdaa-embed__message sdaa-embed__message--${ role }`;
		item.textContent = text;
		messages.appendChild( item );
		messages.scrollTop = messages.scrollHeight;
		return item;
	};

	addMessage( 'assistant', config.greeting );

	const setUnavailable = ( message = STRINGS.unavailable ) => {
		root.classList.add( 'is-unavailable' );
		input.disabled = true;
		form.querySelector( 'button' ).disabled = true;
		addMessage( 'system', message );
	};

	client
		.config()
		.then( ( serverConfig ) => {
			if ( ! serverConfig?.enabled ) {
				setUnavailable();
				return;
			}

			return client.session().then( ( session ) => {
				sessionToken = session?.token || '';
				if ( ! sessionToken ) {
					setUnavailable();
				}
			} );
		} )
		.catch( () => setUnavailable() );

	launcher.addEventListener( 'click', () => {
		const expanded = panel.hidden;
		panel.hidden = ! expanded;
		launcher.setAttribute( 'aria-expanded', expanded ? 'true' : 'false' );
		if ( expanded ) {
			input.focus();
		}
	} );

	close.addEventListener( 'click', () => {
		panel.hidden = true;
		launcher.setAttribute( 'aria-expanded', 'false' );
	} );

	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		const message = input.value.trim();
		if ( ! message || ! sessionToken ) {
			return;
		}
		input.value = '';
		addMessage( 'user', message );
		const pending = addMessage( 'assistant', STRINGS.thinking );
		try {
			const run = await client.send( message, sessionToken );
			let status = run;
			for ( let attempt = 0; attempt < 60; attempt += 1 ) {
				if (
					status.status === 'complete' ||
					status.status === 'error'
				) {
					break;
				}
				await new Promise( ( resolve ) => setTimeout( resolve, 1500 ) );
				status = await client.poll( run.job_id, sessionToken );
			}
			if ( status.status !== 'complete' ) {
				throw new Error( status.error || STRINGS.unavailable );
			}
			pending.textContent = status.reply || STRINGS.unavailable;
		} catch ( error ) {
			pending.textContent = error.message || STRINGS.unavailable;
		}
	} );

	return root;
}

if ( typeof window !== 'undefined' && typeof document !== 'undefined' ) {
	mountEmbed( resolveConfig() );
}
