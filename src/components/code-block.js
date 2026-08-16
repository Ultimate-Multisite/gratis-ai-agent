/**
 * WordPress dependencies
 */
import { useState, useCallback, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { copyToClipboard } from '../utils/clipboard';

/**
 * CodeMirror 6 core
 */
import { EditorView, lineNumbers } from '@codemirror/view';
import { EditorState } from '@codemirror/state';
import { oneDark } from '@codemirror/theme-one-dark';

const languageLoaders = {
	javascript: () =>
		import(
			/* webpackChunkName: "codemirror-lang-javascript" */ '@codemirror/lang-javascript'
		).then( ( module ) => module.javascript ),
	php: () =>
		import(
			/* webpackChunkName: "codemirror-lang-php" */ '@codemirror/lang-php'
		).then( ( module ) => module.php ),
	css: () =>
		import(
			/* webpackChunkName: "codemirror-lang-css" */ '@codemirror/lang-css'
		).then( ( module ) => module.css ),
	html: () =>
		import(
			/* webpackChunkName: "codemirror-lang-html" */ '@codemirror/lang-html'
		).then( ( module ) => module.html ),
	sql: () =>
		import(
			/* webpackChunkName: "codemirror-lang-sql" */ '@codemirror/lang-sql'
		).then( ( module ) => module.sql ),
	python: () =>
		import(
			/* webpackChunkName: "codemirror-lang-python" */ '@codemirror/lang-python'
		).then( ( module ) => module.python ),
	json: () =>
		import(
			/* webpackChunkName: "codemirror-lang-json" */ '@codemirror/lang-json'
		).then( ( module ) => module.json ),
	yaml: () =>
		import(
			/* webpackChunkName: "codemirror-lang-yaml" */ '@codemirror/lang-yaml'
		).then( ( module ) => module.yaml ),
	markdown: () =>
		import(
			/* webpackChunkName: "codemirror-lang-markdown" */ '@codemirror/lang-markdown'
		).then( ( module ) => module.markdown ),
	rust: () =>
		import(
			/* webpackChunkName: "codemirror-lang-rust" */ '@codemirror/lang-rust'
		).then( ( module ) => module.rust ),
};

const languageAliases = {
	javascript: [ 'javascript' ],
	js: [ 'javascript' ],
	jsx: [ 'javascript', { jsx: true } ],
	typescript: [ 'javascript', { typescript: true } ],
	ts: [ 'javascript', { typescript: true } ],
	tsx: [ 'javascript', { jsx: true, typescript: true } ],
	php: [ 'php' ],
	css: [ 'css' ],
	scss: [ 'css' ],
	sass: [ 'css' ],
	html: [ 'html' ],
	xml: [ 'html' ],
	svg: [ 'html' ],
	sql: [ 'sql' ],
	mysql: [ 'sql' ],
	postgresql: [ 'sql' ],
	sqlite: [ 'sql' ],
	python: [ 'python' ],
	py: [ 'python' ],
	json: [ 'json' ],
	jsonc: [ 'json' ],
	yaml: [ 'yaml' ],
	yml: [ 'yaml' ],
	markdown: [ 'markdown' ],
	md: [ 'markdown' ],
	rust: [ 'rust' ],
	rs: [ 'rust' ],
};

/**
 * Map raw language identifiers (from fenced code blocks) to a CodeMirror
 * language extension factory. Each parser remains in its own async chunk.
 *
 * @param {string|undefined} lang Raw language string from the markdown fence.
 * @return {Promise<import('@codemirror/state').Extension|null>} Language extension or null.
 */
export async function getLanguageExtension( lang ) {
	if ( ! lang ) {
		return null;
	}

	const normalised = lang.toLowerCase().trim();
	const descriptor = languageAliases[ normalised ];
	if ( ! descriptor ) {
		return null;
	}

	const [ canonical, options ] = descriptor;
	const factory = await languageLoaders[ canonical ]();
	return options ? factory( options ) : factory();
}

/**
 * Base CodeMirror extensions shared by all instances.
 * Read-only, no cursor, no selection, no line wrapping by default.
 */
const BASE_EXTENSIONS = [
	EditorView.editable.of( false ),
	EditorState.readOnly.of( true ),
	oneDark,
	EditorView.theme( {
		'&': {
			fontSize: '0.85em',
			borderRadius: '0 0 4px 4px',
		},
		'.cm-scroller': {
			fontFamily:
				'ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, "Liberation Mono", monospace',
			lineHeight: '1.5',
			overflow: 'auto',
		},
		'.cm-gutters': {
			borderRight: '1px solid #3a3a3a',
			background: '#21252b',
			color: '#636d83',
			minWidth: '2.5em',
		},
		'.cm-lineNumbers .cm-gutterElement': {
			padding: '0 8px 0 4px',
			minWidth: '2em',
		},
		'.cm-content': {
			padding: '8px 0',
		},
	} ),
];

/**
 * Syntax-highlighted, read-only code block powered by CodeMirror 6.
 *
 * Features:
 * - Syntax highlighting via CodeMirror language extensions
 * - Line numbers (always visible)
 * - Copy-to-clipboard button
 * - One Dark theme
 * - Read-only — no editing, no cursor blink
 *
 * CodeMirror is only instantiated when this component mounts, so it is
 * effectively lazy-loaded: it only runs when a code block is present in
 * the chat response.
 *
 * @param {Object} props            - Component props.
 * @param {string} [props.language] - Language identifier from the fenced code block.
 * @param {*}      props.children   - Code content (string or React nodes).
 * @return {JSX.Element} The rendered code block.
 */
export default function CodeBlock( { language, children } ) {
	const [ copied, setCopied ] = useState( false );
	const containerRef = useRef( null );
	const viewRef = useRef( null );

	const code = String( children ).replace( /\n$/, '' );

	// Create (or recreate) the CodeMirror view whenever code or language changes.
	// Destroy+recreate is the simplest correct approach for a read-only display
	// component — it avoids the complexity of Compartment-based reconfiguration.
	useEffect( () => {
		if ( ! containerRef.current ) {
			return;
		}

		// Destroy any existing view before creating a new one.
		if ( viewRef.current ) {
			viewRef.current.destroy();
			viewRef.current = null;
		}

		let active = true;
		const createView = ( langExtension = null ) =>
			new EditorView( {
				state: EditorState.create( {
					doc: code,
					extensions: [
						...BASE_EXTENSIONS,
						lineNumbers(),
						...( langExtension ? [ langExtension ] : [] ),
					],
				} ),
				parent: containerRef.current,
			} );

		// Render readable, unhighlighted code immediately, then replace the view
		// once the requested language parser arrives.
		viewRef.current = createView();
		getLanguageExtension( language )
			.then( ( langExtension ) => {
				if ( ! active || ! langExtension ) {
					return;
				}
				viewRef.current?.destroy();
				viewRef.current = createView( langExtension );
			} )
			.catch( () => undefined );

		// Cleanup: destroy the view when the effect re-runs or the component unmounts.
		return () => {
			active = false;
			if ( viewRef.current ) {
				viewRef.current.destroy();
				viewRef.current = null;
			}
		};
	}, [ code, language ] );

	const handleCopy = useCallback( () => {
		copyToClipboard( code ).then( () => {
			setCopied( true );
			setTimeout( () => setCopied( false ), 2000 );
		} );
	}, [ code ] );

	return (
		<div className="sdaa-code-block">
			<div className="sdaa-code-header">
				{ language && (
					<span className="sdaa-code-language">{ language }</span>
				) }
				<button
					className="sdaa-code-copy"
					onClick={ handleCopy }
					type="button"
					aria-label={ __(
						'Copy code to clipboard',
						'superdav-ai-agent'
					) }
				>
					{ copied
						? __( 'Copied!', 'sd-ai-agent' )
						: __( 'Copy', 'sd-ai-agent' ) }
				</button>
			</div>
			{ /* CodeMirror mounts into this div */ }
			<div ref={ containerRef } className="sdaa-code-cm" />
		</div>
	);
}
