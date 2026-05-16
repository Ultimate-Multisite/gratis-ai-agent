/**
 * Shared message item components used by both the main chat (MessageList)
 * and the floating widget (WidgetMessageList).
 *
 * All rendering is scoped to `.sdaa-cr-*` classes so the widget and full
 * chat look identical (the widget's bundle also loads chat-redesign.css
 * via components/chat-widget/index.js).
 *
 * Actions are deliberately minimal:
 *   - assistant message:  copy + thumbs-down (opens FeedbackConsentModal)
 *   - user message:       edit/resend + copy
 * A meta row below each message shows model · duration · tokens · cost
 * (assistant) or model · time (user), sourced from store.messageTokens.
 */

import { useState, useRef, useEffect, useCallback } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import {
	Icon,
	copy as copyIcon,
	check,
	pencil,
	thumbsDown,
} from '@wordpress/icons';

import STORE_NAME from '../../store';
import MarkdownMessage from '../markdown-message';
import { AiIcon } from './icons';
import ToolCard from './ToolCard';
import {
	buildRunningItems,
	extractText,
	parseSuggestions,
} from './message-helpers';
import { linkifyText } from '../../utils/linkify';

/**
 *
 * @param {number} n
 */
function formatNumber( n ) {
	if ( ! Number.isFinite( n ) ) {
		return '';
	}
	return new Intl.NumberFormat( undefined ).format( Math.round( n ) );
}

/**
 *
 * @param {number} seconds
 */
function formatDuration( seconds ) {
	if ( ! Number.isFinite( seconds ) || seconds <= 0 ) {
		return '';
	}
	if ( seconds < 1 ) {
		return `${ Math.round( seconds * 1000 ) }ms`;
	}
	return `${ seconds.toFixed( 1 ) }s`;
}

/**
 *
 * @param {number} cost
 */
function formatCost( cost ) {
	if ( ! Number.isFinite( cost ) || cost <= 0 ) {
		return '';
	}
	// Round to 4 decimals if small, else 2.
	return cost < 0.01 ? `$${ cost.toFixed( 4 ) }` : `$${ cost.toFixed( 2 ) }`;
}

/**
 *
 * @param {string} ts
 */
function formatTime( ts ) {
	if ( ! ts ) {
		return '';
	}
	try {
		const date = new Date( ts );
		return date.toLocaleTimeString( undefined, {
			hour: 'numeric',
			minute: '2-digit',
		} );
	} catch {
		return '';
	}
}

/**
 * Meta row shown under an assistant message: model · duration · tokens · cost.
 *
 * @param {Object} root0
 * @param {*}      root0.tokens Per-message token record from store.
 */
function AssistantMeta( { tokens } ) {
	const parts = [];
	if ( tokens?.modelName ) {
		parts.push(
			<span key="model" className="sdaa-cr-msg-meta-model">
				{ tokens.modelName }
			</span>
		);
	}
	const dur = formatDuration( tokens?.duration );
	if ( dur ) {
		parts.push( <span key="dur">{ dur }</span> );
	}
	const total = ( tokens?.prompt || 0 ) + ( tokens?.completion || 0 );
	if ( total > 0 ) {
		parts.push(
			<span key="tok">{ `${ formatNumber( total ) } tok` }</span>
		);
	}
	const cost = formatCost( tokens?.cost );
	if ( cost ) {
		parts.push( <span key="cost">{ cost }</span> );
	}
	if ( parts.length === 0 ) {
		return null;
	}
	const withSeps = [];
	parts.forEach( ( p, i ) => {
		if ( i > 0 ) {
			withSeps.push(
				<span key={ `sep${ i }` } className="sdaa-cr-msg-meta-sep">
					·
				</span>
			);
		}
		withSeps.push( p );
	} );
	return <span className="sdaa-cr-msg-meta-text">{ withSeps }</span>;
}

/**
 *
 * @param {Object} root0
 * @param {*}      root0.msg
 * @param {number} root0.index
 */
export function UserMessage( { msg, index } ) {
	const [ copied, setCopied ] = useState( false );
	const [ draft, setDraft ] = useState( '' );
	const textareaRef = useRef( null );
	const { editAndResend, setEditingMessageIndex } = useDispatch( STORE_NAME );
	const { sending, messageToken, selectedModelName, editing } = useSelect(
		( sel ) => {
			const store = sel( STORE_NAME );
			const tokens = store.getMessageTokens() || [];
			const providers = store.getProviders() || [];
			const provider = providers.find(
				( p ) => p.id === store.getSelectedProviderId()
			);
			const model = provider?.models?.find(
				( m ) => m.id === store.getSelectedModelId()
			);
			return {
				sending: store.isSending(),
				messageToken: tokens[ index ],
				selectedModelName: model?.name || model?.id || '',
				// Derive editing from the store's editingMessageIndex so only the
				// exact message whose index matches enters edit mode. Using a
				// store-level flag (rather than local useState) means a single
				// dispatch controls which message is active, preventing the bug
				// where all user messages simultaneously showed the editing UI.
				editing: store.getEditingMessageIndex() === index,
			};
		},
		[ index ]
	);

	const attachments = msg.attachments || [];
	const text = extractText( msg );

	useEffect( () => {
		if ( editing && textareaRef.current ) {
			textareaRef.current.focus();
			textareaRef.current.select();
		}
	}, [ editing ] );

	const handleCopy = useCallback( () => {
		if ( ! text ) {
			return;
		}
		navigator.clipboard.writeText( text ).then( () => {
			setCopied( true );
			setTimeout( () => setCopied( false ), 1500 );
		} );
	}, [ text ] );

	const handleSubmit = useCallback( () => {
		if ( draft.trim() && draft.trim() !== text ) {
			editAndResend( index, draft.trim() );
		} else {
			setEditingMessageIndex( null );
		}
	}, [ draft, text, index, editAndResend, setEditingMessageIndex ] );

	// Prefer the model actually used for the nearest assistant reply if
	// available, else show the currently-selected model.
	const modelLabel = messageToken?.modelName || selectedModelName || '';
	const timeLabel = formatTime( msg.ts || msg.created_at );

	if ( editing ) {
		return (
			<div className="sdaa-cr-msg-row sdaa-cr-msg-user">
				<div className="sdaa-cr-bubble-user sdaa-cr-bubble-user--editing">
					<textarea
						ref={ textareaRef }
						className="sdaa-cr-bubble-user-edit"
						value={ draft }
						onChange={ ( e ) => setDraft( e.target.value ) }
						onKeyDown={ ( e ) => {
							if ( e.key === 'Enter' && ! e.shiftKey ) {
								e.preventDefault();
								handleSubmit();
							}
							if ( e.key === 'Escape' ) {
								setEditingMessageIndex( null );
							}
						} }
						rows={ 3 }
					/>
					<div className="sdaa-cr-bubble-user-edit-actions">
						<button
							type="button"
							className="sdaa-cr-btn-sm"
							onClick={ () => setEditingMessageIndex( null ) }
						>
							{ __( 'Cancel', 'sd-ai-agent' ) }
						</button>
						<button
							type="button"
							className="sdaa-cr-btn-sm is-primary"
							onClick={ handleSubmit }
							disabled={ sending || ! draft.trim() }
						>
							{ __( 'Send', 'sd-ai-agent' ) }
						</button>
					</div>
				</div>
			</div>
		);
	}

	return (
		<div className="sdaa-cr-msg-row sdaa-cr-msg-user">
			<div className="sdaa-cr-bubble-user">
				{ attachments.length > 0 && (
					<div className="sdaa-cr-bubble-attachments">
						{ attachments.map( ( a, i ) => (
							<img
								key={ i }
								src={ a.dataUrl || a.image_url }
								alt={ a.name || a.image_name || '' }
							/>
						) ) }
					</div>
				) }
				{ text }
			</div>
			<div className="sdaa-cr-msg-meta sdaa-cr-msg-meta-user">
				<span className="sdaa-cr-msg-meta-text">
					{ modelLabel && (
						<span className="sdaa-cr-msg-meta-model">
							{ modelLabel }
						</span>
					) }
					{ modelLabel && timeLabel && (
						<span className="sdaa-cr-msg-meta-sep">·</span>
					) }
					{ timeLabel && <span>{ timeLabel }</span> }
				</span>
				<span className="sdaa-cr-msg-meta-actions">
					<button
						type="button"
						className="sdaa-cr-icon-btn"
						onClick={ () => {
							setDraft( text || '' );
							setEditingMessageIndex( index );
						} }
						disabled={ sending }
						title={ __( 'Edit & resend', 'sd-ai-agent' ) }
						aria-label={ __( 'Edit & resend', 'sd-ai-agent' ) }
					>
						<Icon icon={ pencil } size={ 16 } />
					</button>
					<button
						type="button"
						className="sdaa-cr-icon-btn"
						onClick={ handleCopy }
						title={
							copied
								? __( 'Copied!', 'sd-ai-agent' )
								: __( 'Copy', 'sd-ai-agent' )
						}
						aria-label={
							copied
								? __( 'Copied!', 'sd-ai-agent' )
								: __( 'Copy message', 'sd-ai-agent' )
						}
					>
						<Icon icon={ copied ? check : copyIcon } size={ 16 } />
					</button>
				</span>
			</div>
		</div>
	);
}

/**
 *
 * @param {Object} root0
 * @param {*}      root0.msg
 * @param {*}      root0.index
 * @param {*}      root0.onSuggestionSelect
 * @param {*}      root0.onThumbsDown
 * @param {*}      root0.isLastModel
 */
export function AssistantMessage( {
	msg,
	index,
	onSuggestionSelect,
	onThumbsDown,
	isLastModel,
} ) {
	const [ copied, setCopied ] = useState( false );

	const { messageToken } = useSelect(
		( sel ) => {
			const tokens = sel( STORE_NAME ).getMessageTokens() || [];
			return { messageToken: tokens[ index ] };
		},
		[ index ]
	);

	const rawText = extractText( msg );
	const { cleanText, suggestions } = parseSuggestions( rawText );
	// Finalised assistant messages render only tool pairs here. Any preamble
	// text the model emitted alongside its tool calls is already included in
	// `cleanText` via the persisted assistant message parts, so we deliberately
	// drop preamble entries from the persisted message body to avoid showing
	// the same text twice. The live RunningMessage component below DOES show
	// preamble entries because the assistant message is not yet in history.
	const items = ( buildRunningItems( msg.toolCalls ) || [] ).filter(
		( it ) => it.kind === 'pair'
	);

	const handleCopy = () => {
		if ( ! cleanText ) {
			return;
		}
		navigator.clipboard.writeText( cleanText ).then( () => {
			setCopied( true );
			setTimeout( () => setCopied( false ), 1500 );
		} );
	};

	return (
		<div className="sdaa-cr-msg-row sdaa-cr-msg-assistant">
			<div className="sdaa-cr-avatar" aria-hidden="true">
				<AiIcon />
			</div>
			<div className="sdaa-cr-msg-body">
				{ items.map( ( item ) => (
					<ToolCard
						key={ item.key }
						call={ item.call }
						response={ item.response }
					/>
				) ) }
				{ cleanText && <MarkdownMessage content={ cleanText } /> }
				{ isLastModel && suggestions.length > 0 && (
					<div className="sdaa-cr-suggestions">
						{ suggestions.map( ( s, i ) => (
							<button
								type="button"
								key={ i }
								className="sdaa-cr-suggestion-chip"
								onClick={ () => onSuggestionSelect( s ) }
							>
								{ s }
							</button>
						) ) }
					</div>
				) }
				<div className="sdaa-cr-msg-meta sdaa-cr-msg-meta-assistant">
					<span className="sdaa-cr-msg-meta-actions">
						<button
							type="button"
							className="sdaa-cr-icon-btn"
							onClick={ handleCopy }
							title={
								copied
									? __( 'Copied!', 'sd-ai-agent' )
									: __( 'Copy', 'sd-ai-agent' )
							}
							aria-label={
								copied
									? __( 'Copied!', 'sd-ai-agent' )
									: __( 'Copy message', 'sd-ai-agent' )
							}
						>
							<Icon
								icon={ copied ? check : copyIcon }
								size={ 16 }
							/>
						</button>
						<button
							type="button"
							className="sdaa-cr-icon-btn"
							onClick={ () => onThumbsDown?.( index ) }
							title={ __(
								'Report an issue with this response',
								'sd-ai-agent'
							) }
							aria-label={ __(
								'Report an issue with this response',
								'sd-ai-agent'
							) }
						>
							<Icon icon={ thumbsDown } size={ 16 } />
						</button>
					</span>
					<AssistantMeta tokens={ messageToken } />
				</div>
			</div>
		</div>
	);
}

/**
 *
 * @param {Object} root0
 * @param {*}      root0.step
 * @param {*}      root0.liveToolCalls
 */
export function RunningMessage( { step, liveToolCalls } ) {
	// Render preamble narration and tool-call cards in original emission
	// order so the user sees context like "Looking that up first…" directly
	// above the tool card it precedes. buildRunningItems returns a flat list
	// of { kind: 'preamble' | 'pair', ... } items.
	const items = buildRunningItems( liveToolCalls );
	return (
		<div className="sdaa-cr-msg-row sdaa-cr-msg-assistant">
			<div className="sdaa-cr-avatar" aria-hidden="true">
				<AiIcon thinking={ true } />
			</div>
			<div className="sdaa-cr-msg-body">
				{ items.map( ( item ) => {
					if ( item.kind === 'preamble' ) {
						return (
							<div
								key={ item.key }
								className="sdaa-cr-running-preamble"
							>
								<MarkdownMessage content={ item.text } />
							</div>
						);
					}
					return (
						<ToolCard
							key={ item.key }
							call={ item.call }
							response={ item.response }
							defaultOpen={ ! item.response }
						/>
					);
				} ) }
				<div className="sdaa-cr-running-line">
					<span className="sdaa-cr-running-dot" aria-hidden="true" />
					<span>{ step }</span>
				</div>
			</div>
		</div>
	);
}

/**
 *
 * @param {Object} root0
 * @param {*}      root0.text
 */
export function SystemMessage( { text } ) {
	return (
		<div className="sdaa-cr-msg-row">
			<div className="sdaa-cr-msg-system">{ linkifyText( text ) }</div>
		</div>
	);
}
