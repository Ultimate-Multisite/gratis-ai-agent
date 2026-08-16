/**
 * Redesigned floating widget panel shell — header, body (empty state or
 * messages), running / changes strip, and input. Keeps store wiring
 * (open/minimize, session, sending, changes) identical to the legacy
 * FloatingPanel so the surrounding feature set is unchanged.
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { __, _n } from '@wordpress/i18n';

import STORE_NAME from '../../store';
import ErrorBoundary from '../error-boundary';
import ToolConfirmationDialog from '../tool-confirmation-dialog';
import ProposalPanel from '../proposal-panel';
import useChangesCount from '../use-changes-count';
import ChangesDrawer from '../chat-redesign/ChangesDrawer';
import { isCustomerSimpleMode } from '../../utils/chat-ui-mode';
// chat-redesign base styles (.sdaa-cr-*) are only needed by panel
// sub-components, so the import lives here rather than in index.js.
// This keeps the CSS in the async panel chunk and out of the initial bundle.
import '../chat-redesign/chat-redesign.css';
import './widget-panel.css';
import WidgetHeader from './widget-header';
import WidgetEmpty from './widget-empty';
import WidgetMessageList from './widget-message-list';
import WidgetInput from './widget-input';
import useDrag from './use-drag';
import useResize from './use-resize';

const PANEL_POSITION_STORAGE_KEY = 'aiAgentWidgetPanelPosition';
const PANEL_SIZE_STORAGE_KEY = 'aiAgentWidgetPanelSize';

/**
 * @param {Object}      root0                        Component props.
 * @param {string|null} root0.frontendOnboardingMode Frontend onboarding layout mode.
 * @param {string}      root0.uiMode                 Chat UI mode.
 */
export default function WidgetPanel( {
	frontendOnboardingMode = null,
	uiMode = 'admin',
} ) {
	const isSimpleMode = isCustomerSimpleMode( uiMode );
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

	const [ showChanges, setShowChanges ] = useState( false );

	// Auto-confirm pending tool calls when YOLO is on.
	useEffect( () => {
		if ( yoloMode && pendingConfirmation ) {
			confirmToolCall( pendingConfirmation.jobId, false );
		}
	}, [ yoloMode, pendingConfirmation, confirmToolCall ] );

	const { changesCount, setChangesCount } = useChangesCount( {
		sessionId: currentSessionId,
		sending,
		enabled: ! isSimpleMode,
	} );

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
	if ( position && ! frontendOnboardingMode ) {
		// Bottom-anchored so minimizing keeps the pill visually at the
		// bottom of its previous rect (the input row sits where it was).
		panelStyle.left = `${ position.x }px`;
		panelStyle.bottom = `${ position.y }px`;
		panelStyle.right = 'auto';
		panelStyle.top = 'auto';
	}
	if ( size && ! isMinimized && ! frontendOnboardingMode ) {
		panelStyle.width = `${ size.w }px`;
		panelStyle.height = `${ size.h }px`;
	}
	const onboardingClass = frontendOnboardingMode
		? ` is-frontend-onboarding is-onboarding-${ frontendOnboardingMode }`
		: '';
	const onboardingMessage =
		frontendOnboardingMode === 'building'
			? __(
					'Live build mode: I moved aside so you can watch the site update.',
					'superdav-ai-agent'
			  )
			: __(
					'Setup starts here. I’ll move aside as soon as live changes begin.',
					'superdav-ai-agent'
			  );

	return (
		<>
			<div
				className={ `sdaa-w-panel${
					isMinimized ? ' is-minimized' : ''
				}${ isDragging ? ' is-dragging' : '' }${
					isResizing ? ' is-resizing' : ''
				}${ onboardingClass }${
					isSimpleMode ? ' is-customer-simple' : ''
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
					isSimpleMode={ isSimpleMode }
					onDragHandleMouseDown={
						frontendOnboardingMode
							? undefined
							: handlePanelDragStart
					}
				/>

				{ ! isMinimized && frontendOnboardingMode && (
					<div
						className={ `sdaa-w-onboarding-strip is-${ frontendOnboardingMode }` }
					>
						<span
							className="sdaa-w-onboarding-strip-dot"
							aria-hidden="true"
						/>
						<span>{ onboardingMessage }</span>
					</div>
				) }

				{ ! isMinimized && ! isSimpleMode && changesCount > 0 && (
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
						{ ! isSimpleMode && showChanges && (
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
						<WidgetInput isSimpleMode={ isSimpleMode } />
					</ErrorBoundary>
				) }

				{ ! isMinimized && ! frontendOnboardingMode && (
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

			{ pendingConfirmation && ! yoloMode && ! isSimpleMode && (
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

			{ ! isSimpleMode && pendingProposal && (
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
