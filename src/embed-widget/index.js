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
import {
	base64ToBlob,
	recordingToWav,
	selectRecordingMimeType,
} from '../utils/speech-core';

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
	listening: 'Listening…',
	transcribing: 'Transcribing…',
	speaking: 'Speaking…',
	speechFallback: 'Speech is unavailable. You can continue with typed chat.',
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
		inlineLink.className = 'sd-ai-agent-embed-credit-inline-link';
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
		action.className = 'sd-ai-agent-embed-credit-action';
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
		const isFormData =
			typeof FormData !== 'undefined' && options.body instanceof FormData;
		const response = await fetch( endpoint( config.apiBase, path ), {
			...options,
			credentials: 'omit',
			headers: {
				Accept: 'application/json',
				...( isFormData ? {} : { 'Content-Type': 'application/json' } ),
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
		session: ( recordingConsent = false, locale = '' ) =>
			request( '/public-chat/session', {
				method: 'POST',
				body: JSON.stringify( {
					recording_consent: recordingConsent,
					embed_id: config.embedId,
					locale,
				} ),
			} ),
		send: ( message, sessionToken, signal ) =>
			request( '/public-chat/run', {
				method: 'POST',
				body: JSON.stringify( {
					message,
					token: sessionToken,
				} ),
				signal,
			} ),
		poll: ( jobId, sessionToken, signal ) =>
			request( `/public-chat/job/${ encodeURIComponent( jobId ) }`, {
				method: 'GET',
				headers: { Authorization: `Bearer ${ sessionToken }` },
				signal,
			} ),
		transcribe: ( recording, sessionToken, language, signal ) => {
			const body = new FormData();
			body.append( 'audio', recording, 'recording.wav' );
			body.append( 'token', sessionToken );
			body.append( 'embed_id', config.embedId );
			if ( language ) {
				body.append( 'language', language );
			}
			return request( '/public-chat/speech/transcriptions', {
				method: 'POST',
				body,
				signal,
			} );
		},
		synthesize: ( grant, sessionToken, signal ) =>
			request( '/public-chat/speech/synthesis', {
				method: 'POST',
				body: JSON.stringify( {
					embed_id: config.embedId,
					grant,
					token: sessionToken,
				} ),
				signal,
			} ),
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
			<div class="sd-ai-agent-embed-speech-disclosure" hidden></div>
			<label class="sd-ai-agent-embed-voice-mode" hidden>
				<input type="checkbox" />
				<span>Voice conversation</span>
			</label>
			<div class="sd-ai-agent-embed-speech-status" role="status" aria-live="polite"></div>
			<form class="sdaa-embed__form">
				<textarea class="sdaa-embed__input" rows="1" autocomplete="off" placeholder="${ STRINGS.placeholder }"></textarea>
				<button class="sd-ai-agent-embed-microphone" type="button" hidden aria-pressed="false">Use microphone</button>
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
	const sendButton = form.querySelector( '.sdaa-embed__send' );
	const microphoneButton = form.querySelector(
		'.sd-ai-agent-embed-microphone'
	);
	const speechDisclosure = root.querySelector(
		'.sd-ai-agent-embed-speech-disclosure'
	);
	const speechStatus = root.querySelector(
		'.sd-ai-agent-embed-speech-status'
	);
	const voiceModeControl = root.querySelector(
		'.sd-ai-agent-embed-voice-mode'
	);
	const voiceModeInput = voiceModeControl.querySelector( 'input' );
	let sessionToken = '';
	let serverSpeech = null;
	let pendingSpeechConfig = null;
	let speechLocale = config.locale || globalThis.navigator?.language || '';
	let microphoneConsent = false;
	let recorderTurn = null;
	let playback = null;
	let chatAbort = null;
	let speechAbort = null;
	const activeTimers = new Set();
	input.disabled = true;
	sendButton.disabled = true;

	const autosizeInput = () => {
		input.style.height = 'auto';
		input.style.height = `${ input.scrollHeight }px`;
	};

	const speechLabel = ( key, fallback ) =>
		serverSpeech?.labels?.[ key ] || fallback;

	const setSpeechStatus = ( value = '' ) => {
		speechStatus.textContent = value;
	};

	const clearTimers = () => {
		activeTimers.forEach( ( timer ) => clearTimeout( timer ) );
		activeTimers.clear();
	};

	const wait = ( milliseconds, signal ) =>
		new Promise( ( resolve, reject ) => {
			const timer = setTimeout( () => {
				activeTimers.delete( timer );
				resolve();
			}, milliseconds );
			activeTimers.add( timer );
			signal?.addEventListener(
				'abort',
				() => {
					clearTimeout( timer );
					activeTimers.delete( timer );
					reject( new DOMException( 'Aborted', 'AbortError' ) );
				},
				{ once: true }
			);
		} );

	const releasePlayback = () => {
		if ( ! playback ) {
			return;
		}
		playback.audio.onended = null;
		playback.audio.onerror = null;
		playback.audio.pause();
		playback.audio.removeAttribute( 'src' );
		URL.revokeObjectURL( playback.url );
		if ( playback.button ) {
			playback.button.disabled = true;
			playback.button.textContent = speechLabel(
				'read_aloud',
				'Read aloud'
			);
			playback.button.setAttribute( 'aria-pressed', 'false' );
		}
		playback = null;
		setSpeechStatus();
	};

	const stopPlayback = () => {
		speechAbort?.abort();
		speechAbort = null;
		releasePlayback();
	};

	const releaseRecorder = ( turn, discard = false ) => {
		if ( ! turn ) {
			return;
		}
		if ( turn.timeout ) {
			clearTimeout( turn.timeout );
			activeTimers.delete( turn.timeout );
			turn.timeout = null;
		}
		turn.stream?.getTracks().forEach( ( track ) => track.stop() );
		if ( discard ) {
			turn.cancelled = true;
			turn.chunks = [];
		}
		if ( recorderTurn === turn ) {
			recorderTurn = null;
			microphoneButton.setAttribute( 'aria-pressed', 'false' );
			microphoneButton.textContent = speechLabel(
				'listen',
				'Use microphone'
			);
		}
	};

	const cancelRecorder = () => {
		const turn = recorderTurn;
		if ( ! turn ) {
			return;
		}
		turn.cancelled = true;
		turn.chunks = [];
		if ( turn.recorder.state !== 'inactive' ) {
			turn.recorder.stop();
		} else {
			releaseRecorder( turn, true );
		}
		setSpeechStatus();
	};

	const stopAll = () => {
		chatAbort?.abort();
		chatAbort = null;
		stopPlayback();
		cancelRecorder();
		clearTimers();
	};

	const playGrant = async ( grant, button ) => {
		if ( playback ) {
			stopPlayback();
			return;
		}
		if ( ! grant || ! sessionToken ) {
			return;
		}

		speechAbort?.abort();
		speechAbort = new AbortController();
		button.disabled = true;
		setSpeechStatus( speechLabel( 'speaking', STRINGS.speaking ) );
		try {
			const result = await client.synthesize(
				grant,
				sessionToken,
				speechAbort.signal
			);
			const blob = base64ToBlob( result.audio, result.mime_type );
			const url = URL.createObjectURL( blob );
			const audio = new Audio( url );
			playback = { audio, button, url };
			button.disabled = false;
			button.textContent = speechLabel( 'stop', 'Stop' );
			button.setAttribute( 'aria-pressed', 'true' );
			audio.onended = releasePlayback;
			audio.onerror = () => {
				releasePlayback();
				setSpeechStatus(
					speechLabel( 'fallback', STRINGS.speechFallback )
				);
			};
			await audio.play();
		} catch ( error ) {
			if ( error?.name !== 'AbortError' ) {
				setSpeechStatus(
					speechLabel( 'fallback', STRINGS.speechFallback )
				);
			}
			button.disabled = true;
			releasePlayback();
		} finally {
			speechAbort = null;
		}
	};

	const addReadAloudControl = ( item, grant ) => {
		if ( ! serverSpeech?.enabled || ! grant ) {
			return null;
		}
		const button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'sd-ai-agent-embed-read-aloud';
		button.textContent = speechLabel( 'read_aloud', 'Read aloud' );
		button.setAttribute( 'aria-pressed', 'false' );
		button.addEventListener( 'click', () => playGrant( grant, button ) );
		item.appendChild( button );
		return button;
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

	const finishRecording = async ( turn ) => {
		const chunks = turn.chunks;
		turn.chunks = [];
		releaseRecorder( turn );
		if ( turn.cancelled || ! chunks.length ) {
			return;
		}

		setSpeechStatus( speechLabel( 'transcribing', STRINGS.transcribing ) );
		speechAbort?.abort();
		speechAbort = new AbortController();
		try {
			const recording = new Blob( chunks, { type: turn.mimeType } );
			const wav = await recordingToWav(
				recording,
				Number( serverSpeech.max_audio_bytes )
			);
			const result = await client.transcribe(
				wav,
				sessionToken,
				speechLocale,
				speechAbort.signal
			);
			if ( result?.language ) {
				speechLocale = result.language;
			}
			input.value = result?.text || '';
			autosizeInput();
			if ( input.value && voiceModeInput.checked ) {
				form.requestSubmit?.();
			} else {
				input.focus();
			}
			setSpeechStatus();
		} catch ( error ) {
			if ( error?.name !== 'AbortError' ) {
				setSpeechStatus(
					error?.message ||
						speechLabel( 'fallback', STRINGS.speechFallback )
				);
			}
		} finally {
			speechAbort = null;
		}
	};

	const startRecording = async () => {
		if ( recorderTurn ) {
			if ( recorderTurn.recorder.state !== 'inactive' ) {
				recorderTurn.recorder.stop();
			}
			return;
		}
		if (
			! microphoneConsent ||
			! serverSpeech?.enabled ||
			! sessionToken
		) {
			return;
		}
		stopPlayback();
		const mimeType = selectRecordingMimeType(
			serverSpeech.capture_mime_types
		);
		if ( ! mimeType ) {
			setSpeechStatus(
				speechLabel( 'fallback', STRINGS.speechFallback )
			);
			return;
		}

		setSpeechStatus( speechLabel( 'listening', STRINGS.listening ) );
		try {
			const stream = await navigator.mediaDevices.getUserMedia( {
				audio: {
					autoGainControl: true,
					echoCancellation: true,
					noiseSuppression: true,
				},
			} );
			const recorder = new MediaRecorder( stream, { mimeType } );
			const turn = {
				cancelled: false,
				chunks: [],
				mimeType,
				recorder,
				stream,
				timeout: null,
				totalBytes: 0,
			};
			recorderTurn = turn;
			microphoneButton.setAttribute( 'aria-pressed', 'true' );
			microphoneButton.textContent = speechLabel( 'stop', 'Stop' );
			recorder.ondataavailable = ( event ) => {
				if ( turn.cancelled || ! event.data?.size ) {
					return;
				}
				turn.chunks.push( event.data );
				turn.totalBytes += event.data.size;
				if (
					turn.totalBytes > Number( serverSpeech.max_audio_bytes ) &&
					recorder.state !== 'inactive'
				) {
					turn.cancelled = true;
					recorder.stop();
					setSpeechStatus(
						speechLabel( 'fallback', STRINGS.speechFallback )
					);
				}
			};
			recorder.onerror = () => {
				releaseRecorder( turn, true );
				setSpeechStatus(
					speechLabel( 'fallback', STRINGS.speechFallback )
				);
			};
			recorder.onstop = () => finishRecording( turn );
			recorder.start( 250 );
			turn.timeout = setTimeout(
				() => {
					activeTimers.delete( turn.timeout );
					turn.timeout = null;
					if ( recorder.state !== 'inactive' ) {
						recorder.stop();
					}
				},
				Number( serverSpeech.max_recording_duration_seconds ) * 1000
			);
			activeTimers.add( turn.timeout );
		} catch ( error ) {
			setSpeechStatus(
				error?.name === 'NotAllowedError'
					? 'Microphone permission was denied. You can continue with typed chat.'
					: speechLabel( 'fallback', STRINGS.speechFallback )
			);
		}
	};

	const configureSpeech = ( speech ) => {
		serverSpeech = speech?.enabled ? speech : null;
		const supported = Boolean(
			serverSpeech &&
				navigator.mediaDevices?.getUserMedia &&
				typeof MediaRecorder !== 'undefined' &&
				( globalThis.AudioContext || globalThis.webkitAudioContext ) &&
				selectRecordingMimeType( serverSpeech.capture_mime_types )
		);
		if ( ! supported ) {
			return;
		}

		speechDisclosure.hidden = false;
		const disclosure = document.createElement( 'p' );
		disclosure.textContent = serverSpeech.disclosure;
		const consentButton = document.createElement( 'button' );
		consentButton.type = 'button';
		consentButton.className = 'sd-ai-agent-embed-speech-consent';
		consentButton.textContent = speechLabel(
			'continue',
			'Allow microphone'
		);
		consentButton.addEventListener( 'click', () => {
			microphoneConsent = true;
			consentButton.remove();
			microphoneButton.hidden = false;
			if ( serverSpeech.voice_conversation_enabled ) {
				voiceModeControl.hidden = false;
				voiceModeControl.querySelector( 'span' ).textContent =
					speechLabel( 'voice_mode', 'Voice conversation' );
			}
			startRecording();
		} );
		speechDisclosure.append( disclosure, consentButton );
		microphoneButton.textContent = speechLabel(
			'listen',
			'Use microphone'
		);
	};

	microphoneButton.addEventListener( 'click', startRecording );

	autosizeInput();

	const setUnavailable = ( message = STRINGS.unavailable ) => {
		root.classList.add( 'is-unavailable' );
		input.disabled = true;
		sendButton.disabled = true;
		addMessage( 'system', message );
	};

	const startSession = async ( recordingConsent, choice ) => {
		try {
			const session = await client.session(
				recordingConsent,
				speechLocale
			);
			sessionToken = session?.token || '';
			if ( ! sessionToken ) {
				setUnavailable();
				return;
			}

			choice?.remove();
			input.disabled = false;
			sendButton.disabled = false;
			addMessage( 'assistant', config.greeting );
			configureSpeech( pendingSpeechConfig );
			if ( ! panel.hidden ) {
				input.focus();
			}
		} catch {
			setUnavailable();
		}
	};

	const addRecordingChoice = ( recording ) => {
		const choice = document.createElement( 'section' );
		choice.className = 'sdaa-embed__recording-choice';
		choice.setAttribute( 'aria-label', 'Conversation recording choice' );

		const disclosure = document.createElement( 'p' );
		disclosure.textContent =
			typeof recording?.disclosure === 'string' && recording.disclosure
				? recording.disclosure
				: 'You can choose whether this conversation is retained for quality review.';
		choice.appendChild( disclosure );

		const note = document.createElement( 'p' );
		note.className = 'sdaa-embed__recording-choice-note';
		note.textContent =
			'Choose an option before starting. Starting without recording keeps this chat out of administrator review.';
		choice.appendChild( note );

		const actions = document.createElement( 'div' );
		actions.className = 'sdaa-embed__recording-actions';
		const startWithoutRecording = document.createElement( 'button' );
		startWithoutRecording.type = 'button';
		startWithoutRecording.className = 'sdaa-embed__recording-decline';
		startWithoutRecording.dataset.recordingConsent = 'false';
		startWithoutRecording.textContent = 'Start without recording';
		const agreeAndStart = document.createElement( 'button' );
		agreeAndStart.type = 'button';
		agreeAndStart.className = 'sdaa-embed__recording-accept';
		agreeAndStart.dataset.recordingConsent = 'true';
		agreeAndStart.textContent = 'Agree and start chat';
		actions.append( startWithoutRecording, agreeAndStart );
		choice.appendChild( actions );
		messages.appendChild( choice );
		messages.scrollTop = messages.scrollHeight;

		const chooseRecording = ( recordingConsent ) => {
			startWithoutRecording.disabled = true;
			agreeAndStart.disabled = true;
			startSession( recordingConsent, choice );
		};
		startWithoutRecording.addEventListener( 'click', () =>
			chooseRecording( false )
		);
		agreeAndStart.addEventListener( 'click', () =>
			chooseRecording( true )
		);
	};

	client
		.config()
		.then( ( serverConfig ) => {
			if ( ! serverConfig?.enabled ) {
				setUnavailable();
				return;
			}

			pendingSpeechConfig = serverConfig?.speech || null;
			if ( serverConfig?.recording?.enabled ) {
				addRecordingChoice( serverConfig.recording );
				return;
			}

			return startSession( false );
		} )
		.catch( () => setUnavailable() );

	launcher.addEventListener( 'click', () => {
		const expanded = panel.hidden;
		panel.hidden = ! expanded;
		launcher.setAttribute( 'aria-expanded', expanded ? 'true' : 'false' );
		if ( expanded ) {
			input.focus();
		} else {
			stopAll();
		}
	} );

	close.addEventListener( 'click', () => {
		stopAll();
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
		if ( voiceModeInput.checked ) {
			setSpeechStatus( speechLabel( 'thinking', STRINGS.thinking ) );
		}
		chatAbort?.abort();
		chatAbort = new AbortController();
		try {
			const run = await client.send(
				message,
				sessionToken,
				chatAbort.signal
			);
			let status = run;
			for ( let attempt = 0; attempt < 60; attempt += 1 ) {
				if (
					status.status === 'complete' ||
					status.status === 'error'
				) {
					break;
				}
				await wait( 1500, chatAbort.signal );
				status = await client.poll(
					run.job_id,
					sessionToken,
					chatAbort.signal
				);
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
			const readAloud = addReadAloudControl(
				pending,
				status?.speech?.synthesis_grant
			);
			if ( voiceModeInput.checked && readAloud ) {
				await playGrant( status.speech.synthesis_grant, readAloud );
			} else {
				setSpeechStatus();
			}
		} catch ( error ) {
			if ( error?.name === 'AbortError' ) {
				pending.remove();
				return;
			}
			setMessageContent(
				pending,
				'assistant',
				error.message || STRINGS.unavailable
			);
			setSpeechStatus(
				voiceModeInput.checked
					? speechLabel( 'fallback', STRINGS.speechFallback )
					: ''
			);
		} finally {
			chatAbort = null;
		}
	} );

	window.addEventListener( 'pagehide', stopAll );
	document.addEventListener( 'visibilitychange', () => {
		if ( document.hidden ) {
			stopAll();
		}
	} );

	return root;
}

if ( typeof window !== 'undefined' && typeof document !== 'undefined' ) {
	mountEmbed( resolveConfig() );
}
