/**
 * Static-site embeddable customer chat widget.
 *
 * This bundle intentionally avoids WordPress runtime packages and globals so it
 * can run on Docusaurus or any plain HTML page. Configure it with either
 * `window.sdAiAgentEmbed` before loading the script or data attributes on the
 * script tag.
 */

import './style.css';
import {
	buildSuperdavCreditNoticeMessage,
	CREDIT_EXHAUSTED_REASON,
	PURCHASE_CREDITS_ACTION,
} from '../utils/superdav-credit-notice';

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

const TRAILING_URL_PUNCTUATION = /[.,;:!?'")\]]+$/;
const INLINE_MARKDOWN_WORD_BOUNDARY = /[A-Za-z0-9_]/;

/**
 * Create a fresh inline markdown matcher.
 *
 * The embeddable widget cannot rely on WordPress globals or React, so this
 * handles the small subset of assistant markdown we render in the static DOM:
 * links, raw URLs, strong/emphasis, and inline code.
 *
 * @return {RegExp} Stateful inline markdown matcher.
 */
function createInlineMarkdownRegex() {
	return /\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)|`([^`\n]+)`|\*\*([^*\n]+)\*\*|__([^_\n]+)__|\*([^*\n]+)\*|_([^_\n]+)_|https?:\/\/[^\s<>"']+/g;
}

/**
 * Check whether emphasis delimiters are outside identifier words.
 *
 * This avoids lookbehind so the embeddable widget remains compatible with older
 * Safari versions while keeping snake_case and word*star*word text literal.
 *
 * @param {string} source          Full markdown source.
 * @param {number} matchIndex      Matched delimiter start.
 * @param {number} matchLength     Full matched delimiter span.
 * @param {number} delimiterLength Number of delimiter characters.
 * @return {boolean} True when the match is boundary-safe.
 */
function isEmphasisBoundarySafe(
	source,
	matchIndex,
	matchLength,
	delimiterLength
) {
	const before = matchIndex > 0 ? source.charAt( matchIndex - 1 ) : '';
	const after = source.charAt( matchIndex + matchLength );

	return (
		! INLINE_MARKDOWN_WORD_BOUNDARY.test( before ) &&
		! INLINE_MARKDOWN_WORD_BOUNDARY.test( after ) &&
		! /^\s/.test( source.charAt( matchIndex + delimiterLength ) ) &&
		! /\s$/.test(
			source.slice(
				matchIndex + delimiterLength,
				matchIndex + matchLength - delimiterLength
			)
		)
	);
}

/**
 * Validate and normalize absolute HTTP(S) URLs before creating anchors.
 *
 * @param {string} url Candidate URL.
 * @return {string} Safe URL, or an empty string when unsafe/invalid.
 */
function normalizeHttpUrl( url ) {
	try {
		const parsed = new URL( url );
		return parsed.protocol === 'http:' || parsed.protocol === 'https:'
			? parsed.href
			: '';
	} catch {
		return '';
	}
}

/**
 * Split punctuation that usually belongs to prose, not the URL itself.
 *
 * @param {string} rawUrl URL token from the markdown source.
 * @return {{url: string, suffix: string}} Trimmed URL and trailing suffix.
 */
function splitTrailingUrlPunctuation( rawUrl ) {
	const url = rawUrl.replace( TRAILING_URL_PUNCTUATION, '' );
	return {
		url,
		suffix: rawUrl.slice( url.length ),
	};
}

/**
 * Append a safe external link, falling back to text if the URL is invalid.
 *
 * @param {HTMLElement|DocumentFragment} parent     Node to append to.
 * @param {string}                       href       Link destination.
 * @param {string}                       label      Link text.
 * @param {boolean}                      parseLabel Whether label markdown should be parsed.
 * @return {void}
 */
function appendLink( parent, href, label, parseLabel = true ) {
	const safeHref = normalizeHttpUrl( href );
	if ( ! safeHref ) {
		parent.appendChild( document.createTextNode( label ) );
		return;
	}

	const anchor = document.createElement( 'a' );
	anchor.href = safeHref;
	anchor.target = '_blank';
	anchor.rel = 'noopener noreferrer';
	if ( parseLabel ) {
		appendInlineMarkdown( anchor, label );
	} else {
		anchor.textContent = label;
	}
	parent.appendChild( anchor );
}

/**
 * Append inline markdown to a DOM node without using innerHTML.
 *
 * @param {HTMLElement|DocumentFragment} parent Node to append to.
 * @param {string}                       text   Inline markdown text.
 * @return {void}
 */
function appendInlineMarkdown( parent, text ) {
	const source = String( text || '' );
	const regex = createInlineMarkdownRegex();
	let lastIndex = 0;
	let match;

	while ( ( match = regex.exec( source ) ) !== null ) {
		const isStrong = Boolean( match[ 4 ] || match[ 5 ] );
		const isEmphasis = Boolean( match[ 6 ] || match[ 7 ] );

		if (
			( isStrong || isEmphasis ) &&
			! isEmphasisBoundarySafe(
				source,
				match.index,
				match[ 0 ].length,
				isStrong ? 2 : 1
			)
		) {
			continue;
		}

		if ( match.index > lastIndex ) {
			parent.appendChild(
				document.createTextNode(
					source.slice( lastIndex, match.index )
				)
			);
		}

		if ( match[ 1 ] && match[ 2 ] ) {
			appendLink( parent, match[ 2 ], match[ 1 ] );
		} else if ( match[ 3 ] ) {
			const code = document.createElement( 'code' );
			code.textContent = match[ 3 ];
			parent.appendChild( code );
		} else if ( match[ 4 ] || match[ 5 ] ) {
			const strong = document.createElement( 'strong' );
			appendInlineMarkdown( strong, match[ 4 ] || match[ 5 ] );
			parent.appendChild( strong );
		} else if ( match[ 6 ] || match[ 7 ] ) {
			const emphasis = document.createElement( 'em' );
			appendInlineMarkdown( emphasis, match[ 6 ] || match[ 7 ] );
			parent.appendChild( emphasis );
		} else {
			const { url, suffix } = splitTrailingUrlPunctuation( match[ 0 ] );
			appendLink( parent, url, url, false );
			if ( suffix ) {
				parent.appendChild( document.createTextNode( suffix ) );
			}
		}

		lastIndex = match.index + match[ 0 ].length;
	}

	if ( lastIndex < source.length ) {
		parent.appendChild(
			document.createTextNode( source.slice( lastIndex ) )
		);
	}
}

/**
 * Append multiple inline markdown lines with explicit line breaks.
 *
 * @param {HTMLElement} parent Node to append to.
 * @param {string[]}    lines  Lines to append.
 * @return {void}
 */
function appendInlineLines( parent, lines ) {
	lines.forEach( ( line, index ) => {
		if ( index > 0 ) {
			parent.appendChild( document.createElement( 'br' ) );
		}
		appendInlineMarkdown( parent, line );
	} );
}

/**
 * Detect whether a line starts a markdown block.
 *
 * @param {string} line Markdown source line.
 * @return {boolean} True when the line starts a block.
 */
function isBlockStart( line ) {
	return (
		/^```/.test( line ) ||
		/^#{1,6}\s+/.test( line ) ||
		/^>\s?/.test( line ) ||
		/^\s*[-*+]\s+/.test( line ) ||
		/^\s*\d+\.\s+/.test( line )
	);
}

/**
 * Render assistant markdown into safe DOM nodes for the dependency-free embed.
 *
 * This intentionally supports a compact markdown subset instead of raw HTML so
 * source links are clickable without exposing the host site to injected markup.
 *
 * @param {string} markdown Markdown response text.
 * @return {DocumentFragment} Rendered DOM fragment.
 */
export function renderMarkdown( markdown ) {
	const fragment = document.createDocumentFragment();
	const lines = String( markdown || '' )
		.replace( /\r\n?/g, '\n' )
		.split( '\n' );
	let index = 0;

	while ( index < lines.length ) {
		const line = lines[ index ];

		if ( ! line.trim() ) {
			index += 1;
			continue;
		}

		const fence = line.match( /^```\s*([\w-]+)?\s*$/ );
		if ( fence ) {
			const codeLines = [];
			index += 1;
			while (
				index < lines.length &&
				! /^```\s*$/.test( lines[ index ] )
			) {
				codeLines.push( lines[ index ] );
				index += 1;
			}
			if ( index < lines.length ) {
				index += 1;
			}

			const pre = document.createElement( 'pre' );
			const code = document.createElement( 'code' );
			if ( fence[ 1 ] ) {
				code.className = `language-${ fence[ 1 ] }`;
			}
			code.textContent = codeLines.join( '\n' );
			pre.appendChild( code );
			fragment.appendChild( pre );
			continue;
		}

		const heading = line.match( /^(#{1,6})\s+(.+)$/ );
		if ( heading ) {
			const headingNode = document.createElement(
				`h${ heading[ 1 ].length }`
			);
			appendInlineMarkdown( headingNode, heading[ 2 ].trim() );
			fragment.appendChild( headingNode );
			index += 1;
			continue;
		}

		if ( /^>\s?/.test( line ) ) {
			const quoteLines = [];
			while ( index < lines.length && /^>\s?/.test( lines[ index ] ) ) {
				quoteLines.push( lines[ index ].replace( /^>\s?/, '' ) );
				index += 1;
			}

			const blockquote = document.createElement( 'blockquote' );
			appendInlineLines( blockquote, quoteLines );
			fragment.appendChild( blockquote );
			continue;
		}

		const unorderedList = line.match( /^\s*[-*+]\s+(.+)$/ );
		const orderedList = line.match( /^\s*\d+\.\s+(.+)$/ );
		if ( unorderedList || orderedList ) {
			const list = document.createElement( unorderedList ? 'ul' : 'ol' );
			const lineRegex = unorderedList
				? /^\s*[-*+]\s+(.+)$/
				: /^\s*\d+\.\s+(.+)$/;

			while ( index < lines.length ) {
				const itemMatch = lines[ index ].match( lineRegex );
				if ( ! itemMatch ) {
					break;
				}

				const item = document.createElement( 'li' );
				appendInlineMarkdown( item, itemMatch[ 1 ].trim() );
				list.appendChild( item );
				index += 1;
			}

			fragment.appendChild( list );
			continue;
		}

		const paragraphLines = [];
		while (
			index < lines.length &&
			lines[ index ].trim() &&
			( paragraphLines.length === 0 || ! isBlockStart( lines[ index ] ) )
		) {
			paragraphLines.push( lines[ index ].trim() );
			index += 1;
		}

		const paragraph = document.createElement( 'p' );
		appendInlineLines( paragraph, paragraphLines );
		fragment.appendChild( paragraph );
	}

	return fragment;
}

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
 * Resolve the owning WordPress site's account-settings URL from its REST base.
 *
 * The public widget never receives service account or payment URLs. This
 * capability-gated WordPress admin destination is safe to expose and lets a
 * signed-in site owner purchase credits without exposing provider secrets.
 *
 * @param {string} apiBase Public REST API base URL.
 * @return {string} Absolute admin settings URL, or an empty string.
 */
export function getAccountSettingsUrl( apiBase ) {
	try {
		const apiUrl = new URL( apiBase, window.location.href );
		const rootPath = apiUrl.pathname.replace( /\/wp-json(?:\/.*)?$/, '' );

		if ( rootPath === apiUrl.pathname ) {
			return '';
		}

		return `${ apiUrl.origin }${ rootPath }/wp-admin/admin.php?page=sd-ai-agent#/settings`;
	} catch {
		return '';
	}
}

/**
 * Render the safe managed-credit account action without provider error text.
 *
 * @param {HTMLElement} item   Message element receiving the notice.
 * @param {Object}      notice Semantic account-action notice.
 * @return {void}
 */
export function renderAccountActionNotice( item, notice ) {
	if (
		notice?.reason !== CREDIT_EXHAUSTED_REASON ||
		notice?.action !== PURCHASE_CREDITS_ACTION
	) {
		item.textContent = STRINGS.unavailable;
		return;
	}

	const accountUrl = notice.actionUrl;
	item.textContent = '';
	item.appendChild(
		document.createTextNode(
			"You've used all of your available SD AI credits. Purchase more credits in your "
		)
	);

	if ( accountUrl ) {
		const inlineLink = document.createElement( 'a' );
		inlineLink.className = 'sdaa-embed__credit-inline-link';
		inlineLink.href = accountUrl;
		inlineLink.target = '_blank';
		inlineLink.rel = 'noopener noreferrer';
		inlineLink.textContent = 'account settings';
		item.appendChild( inlineLink );
	} else {
		item.appendChild( document.createTextNode( 'account settings' ) );
	}
	item.appendChild(
		document.createTextNode( ' to continue using Standard.' )
	);

	if ( accountUrl ) {
		const action = document.createElement( 'a' );
		action.className = 'sdaa-embed__credit-action';
		action.href = accountUrl;
		action.target = '_blank';
		action.rel = 'noopener noreferrer';
		action.textContent = 'Purchase credits';
		item.appendChild( action );
	}
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
				<textarea class="sdaa-embed__input" rows="1" autocomplete="off" placeholder="${ STRINGS.placeholder }"></textarea>
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

	const autosizeInput = () => {
		input.style.height = 'auto';
		input.style.height = `${ input.scrollHeight }px`;
	};

	const setMessageContent = ( item, role, text ) => {
		item.textContent = '';
		if ( role === 'assistant' ) {
			item.appendChild( renderMarkdown( text ) );
			return;
		}

		item.textContent = text;
	};

	const addMessage = ( role, text ) => {
		const item = document.createElement( 'div' );
		item.className = `sdaa-embed__message sdaa-embed__message--${ role }`;
		setMessageContent( item, role, text );
		messages.appendChild( item );
		messages.scrollTop = messages.scrollHeight;
		return item;
	};

	addMessage( 'assistant', config.greeting );
	autosizeInput();

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

	input.addEventListener( 'input', autosizeInput );

	input.addEventListener( 'keydown', ( event ) => {
		if ( event.key !== 'Enter' || event.shiftKey || event.isComposing ) {
			return;
		}

		event.preventDefault();
		if ( typeof form.requestSubmit === 'function' ) {
			form.requestSubmit();
		} else {
			form.dispatchEvent(
				new Event( 'submit', { bubbles: true, cancelable: true } )
			);
		}
	} );

	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		const message = input.value.trim();
		if ( ! message || ! sessionToken ) {
			return;
		}
		input.value = '';
		autosizeInput();
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
				if ( status?.diagnostic?.reason === CREDIT_EXHAUSTED_REASON ) {
					renderAccountActionNotice(
						pending,
						buildSuperdavCreditNoticeMessage( [], {
							settingsPageUrl: getAccountSettingsUrl(
								config.apiBase
							),
						} ).notice
					);
					return;
				}
				throw new Error( status.error || STRINGS.unavailable );
			}
			setMessageContent(
				pending,
				'assistant',
				status.reply || STRINGS.unavailable
			);
		} catch ( error ) {
			setMessageContent(
				pending,
				'assistant',
				error.message || STRINGS.unavailable
			);
		}
	} );

	return root;
}

if ( typeof window !== 'undefined' && typeof document !== 'undefined' ) {
	mountEmbed( resolveConfig() );
}
