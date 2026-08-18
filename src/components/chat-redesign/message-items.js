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
import { AiIcon } from './icons';
import ToolCard, { ToolResultHighlights } from './ToolCard';
import {
	buildRunningItems,
	buildToolProgressSummary,
	extractText,
	parseSuggestions,
} from './message-helpers';
import { linkifyText } from '../../utils/linkify';
import { copyToClipboard } from '../../utils/clipboard';

let markdownMessagePromise = null;

/**
 * Share the Markdown chunk request across message rows. A rejected request is
 * cleared so a later mount can retry after a deployment or transient failure.
 *
 * @return {Promise<Function>} Markdown message component.
 */
function loadMarkdownMessage() {
	if ( ! markdownMessagePromise ) {
		markdownMessagePromise = import(
			/* webpackChunkName: "markdown-message" */ '../markdown-message'
		)
			.then( ( module ) => module.default )
			.catch( ( error ) => {
				markdownMessagePromise = null;
				throw error;
			} );
	}

	return markdownMessagePromise;
}

/**
 * Keep message text visible while the Markdown renderer downloads.
 *
 * @param {Object} root0
 * @param {string} root0.content Markdown source.
 * @return {JSX.Element} Deferred Markdown output with a plain-text fallback.
 */
function DeferredMarkdownMessage( { content } ) {
	const [ MarkdownMessage, setMarkdownMessage ] = useState( null );

	useEffect( () => {
		let active = true;
		loadMarkdownMessage()
			.then( ( Component ) => {
				if ( active ) {
					setMarkdownMessage( () => Component );
				}
			} )
			.catch( () => undefined );

		return () => {
			active = false;
		};
	}, [] );

	if ( ! MarkdownMessage ) {
		return <span className="sdaa-cr-markdown-fallback">{ content }</span>;
	}

	return <MarkdownMessage content={ content } />;
}

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
 * Build the headline for a friendly progress summary.
 *
 * @param {Object} summary      Progress summary from buildToolProgressSummary().
 * @param {string} mode         Progress display mode.
 * @param {string} fallbackStep Fallback text when no step label is available.
 * @return {string} Summary headline.
 */
function getProgressSummaryTitle( summary, mode, fallbackStep ) {
	if ( mode === 'running' ) {
		return (
			summary.currentLabel ||
			fallbackStep ||
			__( 'Working on it…', 'superdav-ai-agent' )
		);
	}

	if ( mode === 'error' ) {
		return __( 'Work paused', 'superdav-ai-agent' );
	}

	if ( summary.attentionCount > 0 ) {
		return __( 'Some steps need attention', 'superdav-ai-agent' );
	}

	return __( 'Work completed', 'superdav-ai-agent' );
}

/**
 * Human-readable status text for a summarized tool step.
 *
 * @param {string} status Progress status from buildToolProgressSummary().
 * @return {string} User-facing status label.
 */
function getProgressStepStatusLabel( status ) {
	if ( status === 'running' ) {
		return __( 'In progress', 'superdav-ai-agent' );
	}
	if ( status === 'error' ) {
		return __( 'Needs attention', 'superdav-ai-agent' );
	}
	if ( status === 'warn' ) {
		return __( 'Completed with a note', 'superdav-ai-agent' );
	}
	return __( 'Completed', 'superdav-ai-agent' );
}

/**
 * Friendly progress card shown instead of raw tool-call cards by default.
 *
 * @param {Object} root0
 * @param {Object} root0.summary      Progress summary.
 * @param {string} root0.mode         Either 'running', 'complete', or 'error'.
 * @param {string} root0.fallbackStep Fallback running text.
 * @param {Array}  root0.items        Paired tool calls available for inspection.
 */
function ToolProgressSummary( {
	summary,
	mode = 'running',
	fallbackStep = '',
	items = [],
} ) {
	const [ showDetails, setShowDetails ] = useState( false );
	const isRunning = mode === 'running';
	const isError = mode === 'error';
	const attentionCount = summary.attentionCount;
	const recoveredCount = summary.recoveredCount;
	let statusClass = 'is-complete';
	if ( isRunning ) {
		statusClass = 'is-running';
	} else if ( isError ) {
		statusClass = 'is-error';
	}
	const title = getProgressSummaryTitle( summary, mode, fallbackStep );
	let description = isRunning ? summary.latestThought : '';

	if ( ! description ) {
		if ( isRunning ) {
			description = __(
				'I’m working through your request step by step.',
				'superdav-ai-agent'
			);
		} else if ( isError ) {
			description = __(
				'The agent completed several steps, then stopped before finishing the reply.',
				'superdav-ai-agent'
			);
		} else {
			description = __(
				'The agent used several steps and summarized the result below.',
				'superdav-ai-agent'
			);
		}
	}
	const toggleDetails = () => setShowDetails( ( visible ) => ! visible );

	return (
		<div className={ `sdaa-cr-progress-summary ${ statusClass }` }>
			<div className="sdaa-cr-progress-main">
				<div className="sdaa-cr-progress-title">
					<span
						className={ `sdaa-cr-progress-title-dot ${ statusClass }` }
						aria-hidden="true"
					/>
					<span>{ title }</span>
				</div>
				<div className="sdaa-cr-progress-description">
					{ description }
				</div>
			</div>

			{ summary.totalCount > 0 && (
				<div className="sdaa-cr-progress-stats">
					{ summary.completedCount > 0 && (
						<button
							type="button"
							className="sdaa-cr-progress-stat is-complete"
							onClick={ toggleDetails }
							aria-expanded={ showDetails }
						>
							{ summary.completedCount }{ ' ' }
							{ __( 'completed', 'superdav-ai-agent' ) }
						</button>
					) }
					{ summary.runningCount > 0 && (
						<button
							type="button"
							className="sdaa-cr-progress-stat is-running"
							onClick={ toggleDetails }
							aria-expanded={ showDetails }
						>
							{ summary.runningCount }{ ' ' }
							{ __( 'in progress', 'superdav-ai-agent' ) }
						</button>
					) }
					{ recoveredCount > 0 && (
						<button
							type="button"
							className="sdaa-cr-progress-stat is-recovered"
							onClick={ toggleDetails }
							aria-expanded={ showDetails }
						>
							{ recoveredCount }{ ' ' }
							{ __( 'recovered', 'superdav-ai-agent' ) }
						</button>
					) }
					{ attentionCount > 0 && (
						<button
							type="button"
							className="sdaa-cr-progress-stat is-error"
							onClick={ toggleDetails }
							aria-expanded={ showDetails }
						>
							{ attentionCount }{ ' ' }
							{ __( 'need attention', 'superdav-ai-agent' ) }
						</button>
					) }
				</div>
			) }
			{ showDetails && items.length > 0 && (
				<div className="sdaa-cr-progress-details">
					<div className="sdaa-cr-progress-details-label">
						{ __( 'Tool call details', 'superdav-ai-agent' ) }
					</div>
					{ items.map( ( item ) => (
						<ToolCard
							key={ `${ item.key }-details` }
							call={ item.call }
							response={ item.response }
							defaultOpen={ Boolean(
								item.response?.response?.error
							) }
						/>
					) ) }
				</div>
			) }

			{ summary.recentSteps.length > 0 && (
				<ul className="sdaa-cr-progress-steps">
					{ summary.recentSteps.map( ( step, index ) => (
						<li
							key={ `${ step.id || step.label }-${ index }` }
							className={ `sdaa-cr-progress-step is-${ step.status }` }
						>
							<span
								className="sdaa-cr-progress-step-dot"
								aria-hidden="true"
							/>
							<span className="sd-ai-agent-progress-step-details">
								<span className="sdaa-cr-progress-step-label">
									{ step.label }
								</span>
								{ step.toolName && (
									<code className="sd-ai-agent-progress-step-tool-name">
										{ step.toolName }
									</code>
								) }
							</span>
							<span className="sdaa-cr-progress-step-status">
								{ getProgressStepStatusLabel( step.status ) }
							</span>
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
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
	const { sending, messageToken, messageModelName, editing } = useSelect(
		( sel ) => {
			const store = sel( STORE_NAME );
			const tokens = store.getMessageTokens() || [];
			const providers = store.getProviders() || [];
			const provider = providers.find(
				( p ) => p.id === msg.provider_id
			);
			const model = provider?.models?.find(
				( m ) => m.id === msg.model_id
			);
			return {
				sending: store.isSending(),
				messageToken: tokens[ index ],
				messageModelName:
					model?.name || model?.id || msg.model_id || '',
				// Derive editing from the store's editingMessageIndex so only the
				// exact message whose index matches enters edit mode. Using a
				// store-level flag (rather than local useState) means a single
				// dispatch controls which message is active, preventing the bug
				// where all user messages simultaneously showed the editing UI.
				editing: store.getEditingMessageIndex() === index,
			};
		},
		[ index, msg.provider_id, msg.model_id ]
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
		copyToClipboard( text ).then( () => {
			setCopied( true );
			setTimeout( () => setCopied( false ), 1500 );
		} );
	}, [ text ] );

	const handleSubmit = useCallback( () => {
		// Always resend when Send is clicked, even if the draft equals the
		// original text. The user opens this editor explicitly to resend
		// (typically after a model-availability failure), so clicking Send
		// must always re-dispatch through editAndResend → sendMessage →
		// streamMessage so the request body picks up the current
		// selectedProviderId / selectedModelId from the store (GH#1495).
		// "Cancel" is the only path that closes the editor without sending.
		const trimmed = draft.trim();
		if ( ! trimmed ) {
			// Empty draft — fall back to the original text when it exists,
			// otherwise close the editor (nothing to send).
			if ( text ) {
				editAndResend( index, text );
			} else {
				setEditingMessageIndex( null );
			}
			return;
		}
		editAndResend( index, trimmed );
	}, [ draft, text, index, editAndResend, setEditingMessageIndex ] );

	// Model selection is captured with the message, so changing the picker only
	// affects future turns. Older rows without persisted metadata remain unlabeled
	// rather than being misrepresented as having used the current selection.
	const modelLabel = messageModelName || messageToken?.modelName || '';
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

	const { messageToken, showToolCallDetails, hasStreamError } = useSelect(
		( sel ) => {
			const store = sel( STORE_NAME );
			const tokens = store.getMessageTokens() || [];
			return {
				messageToken: tokens[ index ],
				showToolCallDetails:
					store.getSettings()?.show_tool_call_details === true,
				hasStreamError: store.hasStreamError?.() === true,
			};
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
	const progressSummary = buildToolProgressSummary( msg.toolCalls );
	const hasUnfinishedReply =
		! cleanText &&
		progressSummary.completedCount > 0 &&
		progressSummary.failedCount === 0 &&
		progressSummary.runningCount === 0;
	const showInterruptedProgress =
		isLastModel && ( hasStreamError || hasUnfinishedReply );
	const showProgressSummary =
		progressSummary.hasActivity &&
		! showToolCallDetails &&
		( progressSummary.totalCount > 1 ||
			progressSummary.failedCount > 0 ||
			showInterruptedProgress ||
			! cleanText );

	const handleCopy = () => {
		if ( ! cleanText ) {
			return;
		}
		copyToClipboard( cleanText ).then( () => {
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
				{ showProgressSummary && (
					<ToolProgressSummary
						summary={ progressSummary }
						mode={ showInterruptedProgress ? 'error' : 'complete' }
						items={ items }
					/>
				) }
				{ showToolCallDetails &&
					items.map( ( item ) => (
						<ToolCard
							key={ item.key }
							call={ item.call }
							response={ item.response }
							defaultOpen={ Boolean(
								item.response?.response?.error
							) }
						/>
					) ) }
				{ ! showToolCallDetails &&
					items.map( ( item ) => (
						<ToolResultHighlights
							key={ `${ item.key }-highlights` }
							call={ item.call }
							response={ item.response }
						/>
					) ) }
				{ cleanText && (
					<DeferredMarkdownMessage content={ cleanText } />
				) }
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
 * @param {Object}  root0
 * @param {*}       root0.step
 * @param {*}       root0.liveToolCalls
 * @param {boolean} root0.showToolCallDetails
 */
export function RunningMessage( {
	step,
	liveToolCalls,
	showToolCallDetails = false,
} ) {
	// Render preamble narration and tool-call cards in original emission
	// order so the user sees context like "Looking that up first…" directly
	// above the tool card it precedes. buildRunningItems returns a flat list
	// of { kind: 'preamble' | 'pair', ... } items.
	const items = buildRunningItems( liveToolCalls );
	const progressSummary = buildToolProgressSummary( liveToolCalls );
	return (
		<div className="sdaa-cr-msg-row sdaa-cr-msg-assistant">
			<div className="sdaa-cr-avatar" aria-hidden="true">
				<AiIcon thinking={ true } />
			</div>
			<div className="sdaa-cr-msg-body">
				<ToolProgressSummary
					summary={ progressSummary }
					mode="running"
					fallbackStep={ step }
					items={ items.filter( ( item ) => item.kind === 'pair' ) }
				/>
				{ showToolCallDetails &&
					items.map( ( item ) => {
						if ( item.kind === 'preamble' ) {
							return (
								<div
									key={ item.key }
									className="sdaa-cr-running-preamble"
								>
									<DeferredMarkdownMessage
										content={ item.text }
									/>
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
				{ ! showToolCallDetails &&
					items
						.filter( ( item ) => item.kind === 'pair' )
						.map( ( item ) => (
							<ToolResultHighlights
								key={ `${ item.key }-highlights` }
								call={ item.call }
								response={ item.response }
							/>
						) ) }
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
			<div className="sd-ai-agent-cr-msg-system">
				{ linkifyText( text ) }
			</div>
		</div>
	);
}
