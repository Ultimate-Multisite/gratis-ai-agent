/**
 * Gutenberg selection context and editor-mutation status for the chat widget.
 */

import { Button, Spinner, Tooltip } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import { Icon, blockDefault, closeSmall } from '@wordpress/icons';

import STORE_NAME from '../../store';
import { getToolCallDisplayName } from '../chat-redesign/message-helpers';
import useEditorSelection from './use-editor-selection';

const EDITOR_MUTATION_TOOLS = new Set( [
	'sd-ai-agent-js/replace-editor-selection',
	'sd-ai-agent-js/insert-block-markup',
	'sd-ai-agent-js/change-editor-history',
] );

const VALIDATION_REASONS = new Set( [
	'block_count_exceeded',
	'block_depth_exceeded',
	'canonicalization_failed',
	'disallowed_block',
	'empty_markup',
	'expected_selection_required',
	'invalid_block',
	'invalid_history_direction',
	'invalid_insertion_point',
	'markup_limit',
	'parse_failed',
	'protected_selection',
	'top_level_block_limit',
	'unregistered_block',
	'unstable_markup',
	'unsupported_block',
	'validation_failed',
] );

/**
 * Return actionable copy for unavailable editor execution contexts.
 *
 * @param {string} reason Editor mutation result reason.
 * @return {string|null} Localized status copy when the reason is unavailable.
 */
function getUnavailableCopy( reason ) {
	switch ( reason ) {
		case 'block_api_unavailable':
			return __(
				'The block editor API is unavailable. Wait for the editor to finish loading and try again.',
				'superdav-ai-agent'
			);
		case 'editor_unavailable':
			return __(
				'The block editor is unavailable. Return to the editor and try again.',
				'superdav-ai-agent'
			);
		case 'history_unavailable':
			return __(
				'Editor history is unavailable. Wait for the editor to finish loading and try again.',
				'superdav-ai-agent'
			);
		case 'insertion_point_unavailable':
			return __(
				'No valid insertion point is available. Place the cursor in the editor and try again.',
				'superdav-ai-agent'
			);
		default:
			return null;
	}
}

/**
 * Parse a possibly encoded tool result without exposing arbitrary payload data.
 *
 * @param {*} value Candidate result value.
 * @return {*} Parsed result value.
 */
function parseResultValue( value ) {
	if ( typeof value !== 'string' ) {
		return value;
	}

	try {
		return JSON.parse( value );
	} catch ( _error ) {
		return value;
	}
}

/**
 * Extract the bounded editor result from a paired response entry.
 *
 * @param {Object} response Tool response entry.
 * @return {*} Editor mutation result.
 */
function getResponseResult( response ) {
	if ( response?.error ) {
		return { error: true };
	}

	let result = parseResultValue( response?.response ?? response?.result );
	for ( let depth = 0; depth < 2; depth++ ) {
		if (
			result &&
			typeof result === 'object' &&
			! Array.isArray( result ) &&
			! Object.prototype.hasOwnProperty.call( result, 'applied' )
		) {
			if ( Object.prototype.hasOwnProperty.call( result, 'result' ) ) {
				result = parseResultValue( result.result );
				continue;
			}
			if ( Object.prototype.hasOwnProperty.call( result, 'response' ) ) {
				result = parseResultValue( result.response );
				continue;
			}
		}
		break;
	}

	return result;
}

/**
 * Read call arguments in either object or encoded form.
 *
 * @param {Object} call Tool call entry.
 * @return {Object} Safe call arguments.
 */
function getCallArguments( call ) {
	const args = parseResultValue( call?.args ?? call?.arguments );
	return args && typeof args === 'object' && ! Array.isArray( args )
		? args
		: {};
}

/**
 * Return concise running copy for an editor mutation.
 *
 * @param {string} toolName Canonical editor ability name.
 * @param {Object} call     Tool call entry.
 * @return {string} Localized status copy.
 */
function getRunningCopy( toolName, call ) {
	if ( toolName.endsWith( '/replace-editor-selection' ) ) {
		return __( 'Updating selected blocks…', 'superdav-ai-agent' );
	}
	if ( toolName.endsWith( '/insert-block-markup' ) ) {
		return __( 'Inserting blocks…', 'superdav-ai-agent' );
	}

	return getCallArguments( call ).direction === 'redo'
		? __( 'Redoing the editor change…', 'superdav-ai-agent' )
		: __( 'Undoing the editor change…', 'superdav-ai-agent' );
}

/**
 * Return concise success copy for an editor mutation.
 *
 * @param {string} toolName Canonical editor ability name.
 * @param {Object} call     Tool call entry.
 * @return {string} Localized status copy.
 */
function getSuccessCopy( toolName, call ) {
	if ( toolName.endsWith( '/replace-editor-selection' ) ) {
		return __( 'Selected blocks updated.', 'superdav-ai-agent' );
	}
	if ( toolName.endsWith( '/insert-block-markup' ) ) {
		return __( 'Blocks inserted.', 'superdav-ai-agent' );
	}

	return getCallArguments( call ).direction === 'redo'
		? __( 'Editor change redone.', 'superdav-ai-agent' )
		: __( 'Editor change undone.', 'superdav-ai-agent' );
}

/**
 * Derive the latest relevant editor mutation status from existing job activity.
 *
 * @param {Array}   toolCalls        Tool call and response entries.
 * @param {Object}  options          Resolver options.
 * @param {boolean} options.isActive Whether these entries belong to an active job.
 * @return {{kind: string, text: string}|null} Compact user-facing status.
 */
export function getEditorMutationStatus(
	toolCalls,
	{ isActive = false } = {}
) {
	if ( ! Array.isArray( toolCalls ) || ! toolCalls.length ) {
		return null;
	}

	const responses = new Map();
	const calls = [];
	for ( const entry of toolCalls ) {
		if (
			( entry?.type === 'response' || entry?.type === 'result' ) &&
			entry.id
		) {
			responses.set( entry.id, entry );
		}
		if ( entry?.type === 'call' ) {
			const toolName = getToolCallDisplayName( entry );
			if ( EDITOR_MUTATION_TOOLS.has( toolName ) ) {
				calls.push( { call: entry, toolName } );
			}
		}
	}

	const latest = calls[ calls.length - 1 ];
	if ( ! latest ) {
		return null;
	}

	const response = latest.call.id ? responses.get( latest.call.id ) : null;
	if ( ! response ) {
		return isActive
			? {
					kind: 'running',
					text: getRunningCopy( latest.toolName, latest.call ),
			  }
			: null;
	}

	const result = getResponseResult( response );
	if ( result?.applied === true ) {
		return {
			kind: 'success',
			text: getSuccessCopy( latest.toolName, latest.call ),
		};
	}
	if ( result?.reason === 'stale_selection' ) {
		return {
			kind: 'warning',
			text: __(
				'Selection changed. Reselect the blocks and try again.',
				'superdav-ai-agent'
			),
		};
	}
	if ( result?.applied === 'unknown' || result?.error || response?.error ) {
		return {
			kind: 'warning',
			text: __(
				'The editor result could not be confirmed. Review the blocks before retrying.',
				'superdav-ai-agent'
			),
		};
	}
	if ( result?.applied === false ) {
		const unavailableCopy = getUnavailableCopy( result.reason );
		if ( unavailableCopy ) {
			return {
				kind: 'warning',
				text: unavailableCopy,
			};
		}
		if ( VALIDATION_REASONS.has( result.reason ) ) {
			return {
				kind: 'warning',
				text: __(
					'No blocks were changed. Review the selection or markup and try again.',
					'superdav-ai-agent'
				),
			};
		}
		return {
			kind: 'warning',
			text: latest.toolName.endsWith( '/change-editor-history' )
				? __(
						'No editor history change was available.',
						'superdav-ai-agent'
				  )
				: __( 'No blocks were changed.', 'superdav-ai-agent' ),
		};
	}

	return {
		kind: 'warning',
		text: __(
			'The editor result could not be confirmed. Review the blocks before retrying.',
			'superdav-ai-agent'
		),
	};
}

/**
 * Build the compact selection label without exposing block IDs or attributes.
 *
 * @param {Object} selection Selection presentation metadata.
 * @return {string} Localized compact label.
 */
export function getSelectionLabel( selection ) {
	const labels = Array.isArray( selection?.labels ) ? selection.labels : [];
	if ( selection?.count === 1 ) {
		return sprintf(
			/* translators: %s: selected block type */
			__( 'Selected block: %s', 'superdav-ai-agent' ),
			labels[ 0 ] || __( 'Block', 'superdav-ai-agent' )
		);
	}

	const visible = labels.slice( 0, 2 ).join( ', ' );
	const overflow = Math.max( 0, selection.count - 2 );
	const details = overflow
		? sprintf(
				/* translators: 1: selected block labels, 2: additional block count */
				__( '%1$s, +%2$d more', 'superdav-ai-agent' ),
				visible,
				overflow
		  )
		: visible;

	return sprintf(
		/* translators: 1: selected block count, 2: selected block labels */
		__( '%1$d blocks selected: %2$s', 'superdav-ai-agent' ),
		selection.count,
		details || __( 'Blocks', 'superdav-ai-agent' )
	);
}

/**
 * Pure selection and mutation status presentation.
 *
 * @param {Object}      root0
 * @param {Object}      root0.selection        Compact editor selection metadata.
 * @param {Function}    root0.onClearSelection Selection-only clear action.
 * @param {Object|null} root0.mutationStatus   User-facing mutation status.
 * @return {JSX.Element|null} Editor context UI.
 */
export function EditorSelectionStatus( {
	selection,
	onClearSelection,
	mutationStatus,
} ) {
	if ( ! selection?.available || ( ! selection.count && ! mutationStatus ) ) {
		return null;
	}

	const clearLabel = __( 'Clear block selection', 'superdav-ai-agent' );

	return (
		<div className="sd-ai-agent-editor-context">
			{ selection.count > 0 && (
				<div className="sd-ai-agent-editor-selection-chip">
					<Icon icon={ blockDefault } size={ 16 } />
					<span className="sd-ai-agent-editor-selection-label">
						{ getSelectionLabel( selection ) }
					</span>
					<Tooltip text={ clearLabel }>
						<Button
							className="sd-ai-agent-editor-selection-clear"
							icon={ closeSmall }
							label={ clearLabel }
							onClick={ onClearSelection }
						/>
					</Tooltip>
				</div>
			) }
			{ mutationStatus && (
				<div
					className={ `sd-ai-agent-editor-mutation-status sd-ai-agent-editor-mutation-status--${ mutationStatus.kind }` }
					role="status"
					aria-live="polite"
					aria-atomic="true"
				>
					{ mutationStatus.kind === 'running' && <Spinner /> }
					<span>{ mutationStatus.text }</span>
				</div>
			) }
		</div>
	);
}

/**
 * Connect compact editor context to existing selection and job stores.
 *
 * @return {JSX.Element|null} Connected editor context UI.
 */
export default function ConnectedEditorSelectionStatus() {
	const selection = useEditorSelection();
	const activity = useSelect( ( select ) => {
		const store = select( STORE_NAME );
		const sessionId = store.getCurrentSessionId();
		const sessionJob = store.getSessionJob?.( sessionId );
		const liveToolCalls = store.getLiveToolCalls?.() || [];
		return {
			active: Boolean( sessionJob || store.getCurrentJobId?.() ),
			activeToolCalls:
				sessionJob?.toolCalls?.length > 0
					? sessionJob.toolCalls
					: liveToolCalls,
			historyToolCalls: store.getCurrentSessionToolCalls?.() || [],
		};
	}, [] );
	const mutationStatus = activity.active
		? getEditorMutationStatus( activity.activeToolCalls, {
				isActive: true,
		  } )
		: getEditorMutationStatus( activity.historyToolCalls );

	return (
		<EditorSelectionStatus
			selection={ selection }
			onClearSelection={ selection.clearSelection }
			mutationStatus={ mutationStatus }
		/>
	);
}
