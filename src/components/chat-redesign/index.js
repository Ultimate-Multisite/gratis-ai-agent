/**
 * Top-level chat redesign composition.
 *
 * Renders the page header, two-column shell (sidebar + conversation panel),
 * and wires the changes drawer. Mounts into the admin page content area.
 */

import { useState, useCallback, useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import STORE_NAME from '../../store';
import ChatBanners from '../chat-banners';
import ErrorBoundary from '../error-boundary';
import ToolConfirmationDialog from '../tool-confirmation-dialog';
import ActionCard from '../action-card';
import { getChatUiMode, isCustomerSimpleMode } from '../../utils/chat-ui-mode';
import Sidebar from './Sidebar';
import ConvoHeader from './ConvoHeader';
import ChangesDrawer from './ChangesDrawer';
import MessageList from './MessageList';
import InputArea from './InputArea';
import './chat-redesign.css';

const SIDEBAR_STORAGE_KEY = 'sdAiAgentChatSidebarCollapsed';
const DENSITY_STORAGE_KEY = 'sdAiAgentChatDensity';

/**
 *
 * @param {Object} [root0]
 * @param {string} [root0.uiMode] Chat UI mode.
 */
export default function ChatRedesign( { uiMode = getChatUiMode() } = {} ) {
	const isSimpleMode = isCustomerSimpleMode( uiMode );
	const [ sidebarCollapsed, setSidebarCollapsed ] = useState( () => {
		// On medium or larger screens (≥782px — wp-admin's tablet breakpoint)
		// the sidebar should always start open. Saved collapse preference
		// only kicks in on small screens where horizontal space is tight.
		try {
			const isMediumOrLarger =
				typeof window !== 'undefined' &&
				window.matchMedia &&
				window.matchMedia( '(min-width: 782px)' ).matches;
			if ( isMediumOrLarger ) {
				return false;
			}
			return localStorage.getItem( SIDEBAR_STORAGE_KEY ) === '1';
		} catch {
			return false;
		}
	} );
	const [ density ] = useState( () => {
		try {
			return localStorage.getItem( DENSITY_STORAGE_KEY ) || 'comfortable';
		} catch {
			return 'comfortable';
		}
	} );
	const [ showChanges, setShowChanges ] = useState( false );
	const [ changesCount, setChangesCount ] = useState( 0 );

	const {
		currentSessionId,
		pendingConfirmation,
		pendingActionCard,
		yoloMode,
		sending,
	} = useSelect(
		( sel ) => ( {
			currentSessionId: sel( STORE_NAME ).getCurrentSessionId(),
			pendingConfirmation: sel( STORE_NAME ).getPendingConfirmation(),
			pendingActionCard: sel( STORE_NAME ).getPendingActionCard(),
			yoloMode: sel( STORE_NAME ).isYoloMode(),
			sending: sel( STORE_NAME ).isSending(),
		} ),
		[]
	);

	const durablePlanDispatchers = useDispatch( STORE_NAME );
	const {
		confirmToolCall,
		rejectToolCall,
		retryClientToolSubmission,
		setPendingActionCard,
	} = durablePlanDispatchers;

	// Auto-confirm pending tool calls when YOLO is on.
	useEffect( () => {
		if ( yoloMode && pendingConfirmation ) {
			confirmToolCall( pendingConfirmation.jobId, false );
		}
	}, [ yoloMode, pendingConfirmation, confirmToolCall ] );

	const toggleSidebar = useCallback( () => {
		setSidebarCollapsed( ( v ) => {
			const next = ! v;
			try {
				localStorage.setItem( SIDEBAR_STORAGE_KEY, next ? '1' : '0' );
			} catch {
				// ignore
			}
			return next;
		} );
	}, [] );

	// Refresh the changes count when the session changes or a turn finishes.
	const refreshChangesCount = useCallback( async () => {
		if ( isSimpleMode ) {
			setChangesCount( 0 );
			return;
		}
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
	}, [ currentSessionId, isSimpleMode ] );

	useEffect( () => {
		refreshChangesCount();
	}, [ refreshChangesCount ] );

	useEffect( () => {
		if ( ! sending && currentSessionId ) {
			refreshChangesCount();
		}
	}, [ sending, currentSessionId, refreshChangesCount ] );

	useEffect( () => {
		let active = true;
		if (
			! currentSessionId ||
			isSimpleMode ||
			( pendingActionCard &&
				pendingActionCard.type !== 'durable_plan' ) ||
			( pendingActionCard?.type === 'durable_plan' &&
				pendingActionCard.sessionId === currentSessionId )
		) {
			return () => {
				active = false;
			};
		}

		if ( pendingActionCard?.type === 'durable_plan' ) {
			setPendingActionCard( null );
		}

		import(
			/* webpackChunkName: "durable-plan-actions" */
			'../../store/slices/durable-plan-actions'
		)
			.then( ( { loadDurablePlan } ) =>
				loadDurablePlan( currentSessionId )
			)
			.then( ( plan ) => {
				if (
					! active ||
					! plan ||
					[ 'completed', 'cancelled' ].includes( plan.status )
				) {
					return;
				}
				setPendingActionCard( {
					type: 'durable_plan',
					sessionId: currentSessionId,
					plan,
				} );
			} )
			.catch( () => undefined );

		return () => {
			active = false;
		};
	}, [
		currentSessionId,
		isSimpleMode,
		pendingActionCard,
		setPendingActionCard,
	] );

	const runDurablePlanAction = useCallback(
		( actionName ) => {
			import(
				/* webpackChunkName: "durable-plan-actions" */
				'../../store/slices/durable-plan-actions'
			)
				.then( ( { runDurablePlanAction: runAction } ) =>
					runAction( actionName, {
						dispatch: durablePlanDispatchers,
						card: pendingActionCard,
					} )
				)
				.catch( ( error ) => {
					durablePlanDispatchers.appendMessage( {
						role: 'system',
						parts: [
							{
								text: `${ __(
									'Error:',
									'superdav-ai-agent'
								) } ${
									error instanceof Error
										? error.message
										: __(
												'Unable to load the durable plan action.',
												'superdav-ai-agent'
										  )
								}`,
							},
						],
					} );
					durablePlanDispatchers.setSending( false );
				} );
		},
		[ durablePlanDispatchers, pendingActionCard ]
	);

	const handleDurablePlanConfirm = useCallback( () => {
		const status = pendingActionCard?.plan?.status;
		if ( status === 'awaiting_approval' ) {
			runDurablePlanAction( 'approve' );
			return;
		}
		if ( [ 'failed', 'blocked' ].includes( status ) ) {
			runDurablePlanAction( 'retry' );
			return;
		}
		runDurablePlanAction( 'continue' );
	}, [ pendingActionCard, runDurablePlanAction ] );

	const handleDurablePlanCancel = useCallback( () => {
		if ( pendingActionCard?.plan?.status === 'awaiting_approval' ) {
			runDurablePlanAction( 'reject' );
			return;
		}
		runDurablePlanAction( 'cancel' );
	}, [ pendingActionCard, runDurablePlanAction ] );

	return (
		<div
			className={ `sdaa-cr is-density-${ density }${
				isSimpleMode ? ' is-customer-simple' : ''
			}` }
		>
			<div
				className={ `sdaa-cr-shell${
					sidebarCollapsed || isSimpleMode
						? ' is-sidebar-collapsed'
						: ''
				}` }
			>
				{ ! isSimpleMode && (
					<ErrorBoundary
						label={ __( 'Sidebar', 'superdav-ai-agent' ) }
					>
						<Sidebar
							collapsed={ sidebarCollapsed }
							onToggleCollapse={ toggleSidebar }
						/>
					</ErrorBoundary>
				) }

				<section className="sdaa-cr-convo">
					<ConvoHeader
						sidebarCollapsed={ sidebarCollapsed }
						onExpandSidebar={ () => setSidebarCollapsed( false ) }
						changesCount={ changesCount }
						onShowChanges={ () => setShowChanges( true ) }
						isSimpleMode={ isSimpleMode }
					/>

					<ErrorBoundary
						label={ __( 'Chat banners', 'superdav-ai-agent' ) }
					>
						<ChatBanners />
					</ErrorBoundary>

					{ ! isSimpleMode && showChanges && (
						<ChangesDrawer
							sessionId={ currentSessionId }
							onClose={ () => setShowChanges( false ) }
							onChangesCountChange={ setChangesCount }
						/>
					) }

					<ErrorBoundary
						label={ __( 'Message list', 'superdav-ai-agent' ) }
					>
						<MessageList />
					</ErrorBoundary>

					{ pendingActionCard?.type === 'retry_client_tools' &&
						! isSimpleMode && (
							<ErrorBoundary
								label={ __(
									'Retry tool submission',
									'superdav-ai-agent'
								) }
							>
								<ActionCard
									card={ pendingActionCard }
									onConfirm={ retryClientToolSubmission }
									onCancel={ () =>
										setPendingActionCard( null )
									}
								/>
							</ErrorBoundary>
						) }

					{ pendingActionCard?.type === 'durable_plan' &&
						pendingActionCard.sessionId === currentSessionId &&
						! isSimpleMode && (
							<ErrorBoundary
								label={ __(
									'Durable site operation plan',
									'superdav-ai-agent'
								) }
							>
								<ActionCard
									card={ pendingActionCard }
									onConfirm={ handleDurablePlanConfirm }
									onCancel={ handleDurablePlanCancel }
								/>
							</ErrorBoundary>
						) }

					<ErrorBoundary
						label={ __( 'Message input', 'superdav-ai-agent' ) }
					>
						<InputArea isSimpleMode={ isSimpleMode } />
					</ErrorBoundary>
				</section>
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
		</div>
	);
}
