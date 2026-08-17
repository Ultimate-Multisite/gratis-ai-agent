/**
 * Shared helpers used by both the chat-redesign message list and the
 * chat-widget compact message list. Keep the message transformation logic
 * in one place so the two UIs render the same store data identically.
 */

import { __ } from '@wordpress/i18n';

import { extractMessageText } from '../../utils/message-parts';

const FRIENDLY_TOOL_LABELS = {
	'ability-search': __( 'Finding the right capability', 'superdav-ai-agent' ),
	'ability-call': __(
		'Running the selected capability',
		'superdav-ai-agent'
	),
	'skill-load': __( 'Loading specialist guidance', 'superdav-ai-agent' ),
	'skill-list': __( 'Checking available skills', 'superdav-ai-agent' ),
	'site-info': __( 'Checking site details', 'superdav-ai-agent' ),
	'memory-list': __( 'Checking memory', 'superdav-ai-agent' ),
	'memory-save': __( 'Saving a useful memory', 'superdav-ai-agent' ),
	'knowledge-search': __( 'Searching knowledge', 'superdav-ai-agent' ),
	'get-post': __( 'Reading content', 'superdav-ai-agent' ),
	'get-page-blocks': __( 'Reading page content', 'superdav-ai-agent' ),
	'create-post': __( 'Creating content', 'superdav-ai-agent' ),
	'append-post-content': __( 'Adding content', 'superdav-ai-agent' ),
	'update-post': __( 'Updating content', 'superdav-ai-agent' ),
	'update-blocks': __( 'Updating page content', 'superdav-ai-agent' ),
	'generate-image': __( 'Generating an image', 'superdav-ai-agent' ),
	'stock-image-search': __( 'Finding images', 'superdav-ai-agent' ),
	'render-design-previews': __(
		'Rendering design previews',
		'superdav-ai-agent'
	),
	'compile-design-tokens': __(
		'Preparing design tokens',
		'superdav-ai-agent'
	),
	'validate-palette-contrast': __(
		'Checking colour contrast',
		'superdav-ai-agent'
	),
	'get-theme-json': __( 'Reading theme settings', 'superdav-ai-agent' ),
	'get-global-styles': __( 'Reading site styles', 'superdav-ai-agent' ),
	'update-global-styles': __( 'Applying site styles', 'superdav-ai-agent' ),
	'scaffold-block-theme': __( 'Preparing theme files', 'superdav-ai-agent' ),
	'apply-design-artifact-release': __(
		'Applying the design package',
		'superdav-ai-agent'
	),
};

const PROGRESS_TOOL_PATTERN =
	/`?(?:wpab__)?(?:sd-ai-agent|sd-ai-agent-js)(?:__|\/)[a-z0-9][a-z0-9-]*`?/gi;
const THINKING_TAG_PATTERN = /<\/?thinking\b[^>]*>/gi;

/**
 * Normalize provider/native ability bridge names into canonical ability IDs.
 *
 * @param {string} name Raw tool/function name.
 * @return {string} Canonical display name.
 */
export function normalizeToolName( name ) {
	let display = String( name || '' ).trim();
	display = display.replace( /^[`"']+|[`"']+$/g, '' );
	if ( display.startsWith( 'wpab__' ) ) {
		display = display.substring( 6 );
	}
	return display.replace( /__/g, '/' );
}

/**
 * Return the ability slug after the namespace.
 *
 * @param {string} name Raw or normalized tool/function name.
 * @return {string} Ability slug.
 */
function getToolSlug( name ) {
	const normalized = normalizeToolName( name );
	const parts = normalized.split( '/' );
	return parts[ parts.length - 1 ] || normalized;
}

/**
 * Convert a slug into readable words while preserving common acronyms.
 *
 * @param {string} slug Hyphen/underscore-separated slug.
 * @return {string} Human-readable words.
 */
function humanizeSlug( slug ) {
	const acronymMap = {
		ai: 'AI',
		api: 'API',
		css: 'CSS',
		html: 'HTML',
		id: 'ID',
		json: 'JSON',
		seo: 'SEO',
		url: 'URL',
		wp: 'WordPress',
	};

	return String( slug || '' )
		.replace( /[_-]+/g, ' ' )
		.trim()
		.split( /\s+/ )
		.filter( Boolean )
		.map( ( word ) => acronymMap[ word.toLowerCase() ] || word )
		.join( ' ' );
}

/**
 * Produce a user-friendly action label for a tool without exposing ability IDs.
 *
 * @param {string} name Raw or normalized tool/function name.
 * @return {string} Friendly action label.
 */
export function getFriendlyToolLabel( name ) {
	const slug = getToolSlug( name ).toLowerCase();
	if ( FRIENDLY_TOOL_LABELS[ slug ] ) {
		return FRIENDLY_TOOL_LABELS[ slug ];
	}

	const verbGroups = [
		{
			verbs: [
				'get',
				'list',
				'read',
				'fetch',
				'search',
				'find',
				'inspect',
				'check',
				'validate',
				'verify',
				'audit',
				'analyze',
				'analyse',
			],
			label: __( 'Checking', 'superdav-ai-agent' ),
		},
		{
			verbs: [
				'create',
				'generate',
				'render',
				'compile',
				'scaffold',
				'build',
			],
			label: __( 'Creating', 'superdav-ai-agent' ),
		},
		{
			verbs: [ 'execute', 'run' ],
			label: __( 'Running', 'superdav-ai-agent' ),
		},
		{
			verbs: [
				'update',
				'set',
				'apply',
				'append',
				'save',
				'write',
				'publish',
				'draft',
				'move',
				'install',
				'activate',
				'deactivate',
				'delete',
				'remove',
				'clear',
				'import',
				'revert',
			],
			label: __( 'Updating', 'superdav-ai-agent' ),
		},
	];

	for ( const group of verbGroups ) {
		const verb = group.verbs.find( ( candidate ) =>
			slug.startsWith( `${ candidate }-` )
		);
		if ( verb ) {
			const target = humanizeSlug( slug.substring( verb.length + 1 ) );
			return target ? `${ group.label } ${ target }` : group.label;
		}
	}

	const fallback = humanizeSlug( slug );
	return fallback
		? fallback.charAt( 0 ).toUpperCase() + fallback.slice( 1 )
		: __( 'Working on a site step', 'superdav-ai-agent' );
}

/**
 * Remove raw ability IDs from progress narration before it is shown by default.
 *
 * @param {string} text Progress narration emitted by the model.
 * @return {string} Sanitized, user-friendly narration.
 */
export function sanitizeProgressText( text ) {
	return String( text || '' )
		.replace( THINKING_TAG_PATTERN, '' )
		.replace( PROGRESS_TOOL_PATTERN, ( match ) =>
			getFriendlyToolLabel( match ).toLowerCase()
		)
		.replace( /\s+/g, ' ' )
		.trim();
}

/**
 * Keep live progress narration compact in the chat timeline.
 *
 * @param {string} text Text to truncate.
 * @return {string} Truncated text.
 */
function truncateProgressText( text ) {
	const maxLength = 180;
	if ( text.length <= maxLength ) {
		return text;
	}
	return `${ text.substring( 0, maxLength - 1 ).trimEnd() }…`;
}

/**
 * Derive a compact status from a paired tool response.
 *
 * @param {*} response Tool response entry.
 * @return {'running'|'done'|'warn'|'error'} Progress status.
 */
function deriveProgressStatus( response ) {
	if ( ! response ) {
		return 'running';
	}
	const result = response.response;
	if ( result && typeof result === 'object' ) {
		if ( result.success === false || result.error ) {
			return 'error';
		}
		if ( result.warning ) {
			return 'warn';
		}
	}
	return 'done';
}

/**
 * Summarize a flat tool-call log into user-facing progress metadata.
 *
 * @param {Array} toolCalls Flat tool-call log entries.
 * @return {Object} Progress summary for the chat UI.
 */
export function buildToolProgressSummary( toolCalls ) {
	if ( ! toolCalls?.length ) {
		return {
			hasActivity: false,
			totalCount: 0,
			finishedCount: 0,
			completedCount: 0,
			failedCount: 0,
			runningCount: 0,
			currentLabel: '',
			latestThought: '',
			recentSteps: [],
		};
	}

	const responses = {};
	const calls = [];
	const thoughts = [];
	for ( const entry of toolCalls ) {
		if (
			( entry.type === 'response' || entry.type === 'result' ) &&
			entry.id
		) {
			responses[ entry.id ] = entry;
		}
		if ( entry.type === 'call' ) {
			calls.push( entry );
		}
		if ( entry.type === 'preamble' && typeof entry.text === 'string' ) {
			const text = sanitizeProgressText( entry.text );
			if ( text ) {
				thoughts.push( text );
			}
		}
	}

	const steps = calls.map( ( call ) => {
		const response = call.id ? responses[ call.id ] || null : null;
		return {
			id: call.id || call.name || '',
			label: getFriendlyToolLabel( call.name ),
			toolName: normalizeToolName( call.name ),
			status: deriveProgressStatus( response ),
		};
	} );

	const finishedCount = steps.filter(
		( step ) => step.status !== 'running'
	).length;
	const failedCount = steps.filter(
		( step ) => step.status === 'error'
	).length;
	const runningCount = steps.filter(
		( step ) => step.status === 'running'
	).length;
	const completedCount = steps.filter(
		( step ) => step.status === 'done' || step.status === 'warn'
	).length;
	const currentStep =
		[ ...steps ].reverse().find( ( step ) => step.status === 'running' ) ||
		steps[ steps.length - 1 ] ||
		null;

	return {
		hasActivity: steps.length > 0 || thoughts.length > 0,
		totalCount: steps.length,
		finishedCount,
		completedCount,
		failedCount,
		runningCount,
		currentLabel: currentStep?.label || '',
		latestThought: truncateProgressText(
			thoughts[ thoughts.length - 1 ] || ''
		),
		recentSteps: steps.slice( -3 ),
	};
}

/**
 *
 * @param {Object} message
 */
export function extractText( message ) {
	return extractMessageText( message );
}

/**
 *
 * @param {Array} toolCalls
 */
export function pairToolCalls( toolCalls ) {
	if ( ! toolCalls?.length ) {
		return [];
	}
	const responses = {};
	for ( const t of toolCalls ) {
		if ( ( t.type === 'response' || t.type === 'result' ) && t.id ) {
			responses[ t.id ] = t;
		}
	}
	const pairs = [];
	for ( const t of toolCalls ) {
		if ( t.type === 'call' ) {
			pairs.push( {
				call: t,
				response: t.id ? responses[ t.id ] || null : null,
			} );
		}
	}
	if ( pairs.length === 0 ) {
		// Defensive fallback: a log with no explicit type='call' entries
		// (e.g. a free-form preamble-only stream) still renders one card per
		// entry so the user sees something rather than an empty container.
		// Preamble entries deliberately skip this path — they are surfaced by
		// buildRunningItems() above text-friendly rendering.
		for ( const t of toolCalls ) {
			if ( t.type === 'preamble' ) {
				continue;
			}
			pairs.push( { call: t, response: null } );
		}
	}
	return pairs;
}

/**
 * Build the ordered list of items to render inside a model message body,
 * preserving the original emission order of preamble text blocks and tool
 * call pairs.
 *
 * Returns a heterogeneous list of items shaped as either:
 *   { kind: 'preamble', text: string, key: string }
 *   { kind: 'pair', call: ToolCall, response: ToolResponse|null, key: string }
 *
 * The polling frontend uses this for the live RunningMessage so the user
 * can see narration like "Looking that up first…" immediately above the
 * tool card it precedes. Finalised assistant messages also use it so live
 * and persisted views share the same layout pipeline.
 *
 * @param {Array} toolCalls Flat tool-call log entries.
 * @return {Array} Ordered render items.
 */
export function buildRunningItems( toolCalls ) {
	if ( ! toolCalls?.length ) {
		return [];
	}
	const responses = {};
	for ( const t of toolCalls ) {
		if ( ( t.type === 'response' || t.type === 'result' ) && t.id ) {
			responses[ t.id ] = t;
		}
	}
	const items = [];
	let preambleSeq = 0;
	let pairSeq = 0;
	for ( const t of toolCalls ) {
		if ( t.type === 'preamble' && typeof t.text === 'string' ) {
			const text = sanitizeProgressText( t.text );
			if ( text !== '' ) {
				items.push( {
					kind: 'preamble',
					text,
					key: `preamble-${ preambleSeq++ }`,
				} );
			}
			continue;
		}
		if ( t.type === 'call' ) {
			items.push( {
				kind: 'pair',
				call: t,
				response: t.id ? responses[ t.id ] || null : null,
				key: t.id || `pair-${ pairSeq++ }`,
			} );
		}
	}
	return items;
}

/**
 *
 * @param {string} text
 */
export function parseSuggestions( text ) {
	const lines = text.split( '\n' );
	const suggestions = [];
	let lastContentIdx = lines.length - 1;
	for ( let i = lines.length - 1; i >= 0; i-- ) {
		const trimmed = lines[ i ].trim();
		if ( trimmed.startsWith( '[suggestion]' ) ) {
			suggestions.unshift( trimmed.replace( /^\[suggestion\]\s*/, '' ) );
			lastContentIdx = i - 1;
		} else if ( trimmed === '' && suggestions.length > 0 ) {
			lastContentIdx = i - 1;
		} else {
			break;
		}
	}
	return {
		cleanText: lines
			.slice( 0, lastContentIdx + 1 )
			.join( '\n' )
			.trimEnd(),
		suggestions,
	};
}

/**
 * Returns the short name of the tool that is currently in-flight, or
 * null when all calls have a matching response (i.e. the model is just
 * composing its reply).
 *
 * @param {Array} toolCalls
 * @return {string|null} The running tool name or null.
 */
export function getRunningToolName( toolCalls ) {
	const calls = toolCalls?.filter( ( t ) => t.type === 'call' ) || [];
	const responses =
		toolCalls?.filter(
			( t ) => t.type === 'response' || t.type === 'result'
		) || [];
	const lastCall = calls[ calls.length - 1 ];
	if ( responses.length < calls.length && lastCall ) {
		return ( lastCall.name || '' )
			.replace( /^wpab__/, '' )
			.replace( /__/g, '/' );
	}
	return null;
}
