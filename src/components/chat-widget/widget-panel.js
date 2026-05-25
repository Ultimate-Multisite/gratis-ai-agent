/**
 * Redesigned floating widget panel shell — header, body (empty state or
 * messages), running / changes strip, and input. Keeps store wiring
 * (open/minimize, session, sending, changes) identical to the legacy
 * FloatingPanel so the surrounding feature set is unchanged.
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { __, _n } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import STORE_NAME from '../../store';
import ErrorBoundary from '../error-boundary';
import ToolConfirmationDialog from '../tool-confirmation-dialog';
import ProposalPanel from '../proposal-panel';
import ChangesDrawer from '../chat-redesign/ChangesDrawer';
// chat-redesign base styles (.sdaa-cr-*) are only needed by panel
// sub-components, so the import lives here rather than in index.js.
// This keeps the CSS in the async panel chunk and out of the initial bundle.
import '../chat-redesign/chat-redesign.css';
import WidgetHeader from './widget-header';
import WidgetEmpty from './widget-empty';
import WidgetMessageList from './widget-message-list';
import WidgetInput from './widget-input';
import useDrag from './use-drag';
import useResize from './use-resize';

const PANEL_POSITION_STORAGE_KEY = 'aiAgentWidgetPanelPosition';
const PANEL_SIZE_STORAGE_KEY = 'aiAgentWidgetPanelSize';

/**
 *
 */
export default function WidgetPanel() {
	const { confirmToolCall, rejectToolCall, setFloatingMinimized } =
		useDispatch( STORE_NAME );

	const {
		isMinimized,
		pendingConfirmation,
		pendingProposal,
		yoloMode,
		sending,
		currentSessionId,
		messageCount,
	} = useSelect( ( sel ) => {
		const store = sel( STORE_NAME );
		return {
			isMinimized: store.isFloatingMinimized(),
			pendingConfirmation: store.getPendingConfirmation(),
			pendingProposal: store.getPendingProposal(),
			yoloMode: store.isYoloMode(),
			sending: store.isSending(),
			currentSessionId: store.getCurrentSessionId(),
			messageCount: store.getCurrentSessionMessages().length,
		};
	}, [] );

	const [ changesCount, setChangesCount ] = useState( 0 );
	const [ showChanges, setShowChanges ] = useState( false );

	// Auto-confirm pending tool calls when YOLO is on.
	useEffect( () => {
		if ( yoloMode && pendingConfirmation ) {
			confirmToolCall( pendingConfirmation.jobId, false );
		}
	}, [ yoloMode, pendingConfirmation, confirmToolCall ] );

	const refreshChangesCount = useCallback( async () => {
		if ( ! currentSessionId ) {
			setChangesCount( 0 );
			return;
		}
		try {
			const data = await apiFetch( {
				path: `/sd-ai-agent/v1/changes?session_id=${ currentSessionId }&reverted=false&revertable=true&per_page=1`,
			} );
			setChangesCount( data?.total ?? ( data?.items?.length || 0 ) );
		} catch {
			setChangesCount( 0 );
		}
	}, [ currentSessionId ] );

	useEffect( () => {
		refreshChangesCount();
	}, [ refreshChangesCount ] );

	useEffect( () => {
		if ( ! sending && currentSessionId ) {
			refreshChangesCount();
		}
	}, [ sending, currentSessionId, refreshChangesCount ] );

	const toggleMinimize = useCallback( () => {
		setFloatingMinimized( ! isMinimized );
	}, [ isMinimized, setFloatingMinimized ] );

	const {
		position,
		isDragging,
		moved: dragMoved,
		handleMouseDown: handlePanelDragStart,
		reclampForSize,
	} = useDrag( {
		storageKey: PANEL_POSITION_STORAGE_KEY,
		sizeFallback: { w: 400, h: 640 },
	} );

	const { size, isResizing, handleResizeMouseDown } = useResize( {
		storageKey: PANEL_SIZE_STORAGE_KEY,
		min: { w: 320, h: 400 },
		max: { w: 900, h: 1000 },
		defaultSize: { w: 400, h: 640 },
	} );

	// When the panel transitions from minimized back to expanded, the
	// saved position was clamped against the tiny header height. Re-clamp
	// now against the full panel size so the expanded panel fits fully
	// inside the viewport with a small margin on every edge.
	useEffect( () => {
		if ( isMinimized ) {
			return;
		}
		const w = size?.w || 400;
		const h = size?.h || 640;
		reclampForSize( w, h, 16 );
	}, [ isMinimized, size?.w, size?.h, reclampForSize ] );

	// While minimized, a click anywhere on the panel (except the close
	// button) should expand it. The synthetic click that follows a drag
	// must be swallowed so moving the minimized pill doesn't also expand.
	const handleMinimizedClick = useCallback(
		( e ) => {
			if ( ! isMinimized ) {
				return;
			}
			if ( dragMoved.current ) {
				dragMoved.current = false;
				return;
			}
			if (
				e.target.closest(
					'[data-dismiss-only], button[aria-label="Close"]'
				)
			) {
				return;
			}
			setFloatingMinimized( false );
		},
		[ isMinimized, dragMoved, setFloatingMinimized ]
	);

	const showEmpty = messageCount === 0 && ! sending;

	const panelStyle = {};
	if ( position ) {
		// Bottom-anchored so minimizing keeps the pill visually at the
		// bottom of its previous rect (the input row sits where it was).
		panelStyle.left = `${ position.x }px`;
		panelStyle.bottom = `${ position.y }px`;
		panelStyle.right = 'auto';
		panelStyle.top = 'auto';
	}
	if ( size && ! isMinimized ) {
		panelStyle.width = `${ size.w }px`;
		panelStyle.height = `${ size.h }px`;
	}

	return (
		<>
			<div
				className={ `sdaa-w-panel${
					isMinimized ? ' is-minimized' : ''
				}${ isDragging ? ' is-dragging' : '' }${
					isResizing ? ' is-resizing' : ''
				}` }
				style={ panelStyle }
				role="presentation"
				data-drag-target="true"
				onClick={ handleMinimizedClick }
				onKeyDown={ ( e ) =>
					e.key === 'Enter' && handleMinimizedClick()
				}
			>
				<WidgetHeader
					isMinimized={ isMinimized }
					onToggleMinimize={ toggleMinimize }
					onDragHandleMouseDown={ handlePanelDragStart }
				/>

				{ ! isMinimized && changesCount > 0 && (
					<div className="sdaa-w-changes-strip">
						<span className="sdaa-w-changes-strip-text">
							<span className="sdaa-w-changes-count">
								{ changesCount }
							</span>
							{ _n(
								'change this session',
								'changes this session',
								changesCount,
								'sd-ai-agent'
							) }
						</span>
						<button
							type="button"
							className="sdaa-w-changes-strip-btn"
							onClick={ () => setShowChanges( ( v ) => ! v ) }
							aria-expanded={ showChanges }
						>
							{ showChanges
								? __( 'Hide', 'sd-ai-agent' )
								: __( 'View', 'sd-ai-agent' ) }
							<span aria-hidden="true">
								{ showChanges ? ' ↑' : ' →' }
							</span>
						</button>
					</div>
				) }

				{ ! isMinimized && (
					<div className="sdaa-w-body-wrap">
						<ErrorBoundary
							label={ __( 'Message list', 'sd-ai-agent' ) }
						>
							{ showEmpty ? (
								<WidgetEmpty />
							) : (
								<WidgetMessageList />
							) }
						</ErrorBoundary>
						{ showChanges && (
							<div className="sdaa-w-changes-drawer-wrap sdaa-cr">
								<ChangesDrawer
									sessionId={ currentSessionId }
									onClose={ () => setShowChanges( false ) }
									onChangesCountChange={ setChangesCount }
								/>
							</div>
						) }
					</div>
				) }

				{ ! isMinimized && (
					<ErrorBoundary
						label={ __( 'Message input', 'sd-ai-agent' ) }
					>
						<WidgetInput />
					</ErrorBoundary>
				) }

				{ ! isMinimized && (
					<>
						<div
							className="sdaa-w-resize-handle sdaa-w-resize-handle--right"
							role="presentation"
							onMouseDown={ ( e ) =>
								handleResizeMouseDown( e, 'right' )
							}
						/>
						<div
							className="sdaa-w-resize-handle sdaa-w-resize-handle--bottom"
							role="presentation"
							onMouseDown={ ( e ) =>
								handleResizeMouseDown( e, 'bottom' )
							}
						/>
						<div
							className="sdaa-w-resize-handle sdaa-w-resize-handle--corner"
							role="presentation"
							onMouseDown={ ( e ) =>
								handleResizeMouseDown( e, 'corner' )
							}
						/>
					</>
				) }
			</div>

		{ pendingConfirmation && ! yoloMode && (
			<ToolConfirmationDialog
				confirmation={ pendingConfirmation }
				onConfirm={ ( alwaysAllow ) =>
					confirmToolCall(
						pendingConfirmation.jobId,
						alwaysAllow
					)
				}
				onReject={ () =>
					rejectToolCall( pendingConfirmation.jobId )
				}
			/>
		) }

		{ pendingProposal && (
			<ProposalPanel
				proposal={ pendingProposal }
				onClose={ () => {
					// Clear the pending proposal from the store.
					// The ProposalPanel component handles the apply/reject actions.
				} }
			/>
		) }
	</>
	);
}
