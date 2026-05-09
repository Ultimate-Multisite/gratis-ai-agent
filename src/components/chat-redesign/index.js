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
 */
export default function ChatRedesign() {
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

	const { currentSessionId, pendingConfirmation, yoloMode, sending } =
		useSelect(
			( sel ) => ( {
				currentSessionId: sel( STORE_NAME ).getCurrentSessionId(),
				pendingConfirmation: sel( STORE_NAME ).getPendingConfirmation(),
				yoloMode: sel( STORE_NAME ).isYoloMode(),
				sending: sel( STORE_NAME ).isSending(),
			} ),
			[]
		);

	const { confirmToolCall, rejectToolCall } = useDispatch( STORE_NAME );

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

	return (
		<div className={ `sdaa-cr is-density-${ density }` }>
			<div
				className={ `sdaa-cr-shell${
					sidebarCollapsed ? ' is-sidebar-collapsed' : ''
				}` }
			>
				<ErrorBoundary label={ __( 'Sidebar', 'sd-ai-agent' ) }>
					<Sidebar
						collapsed={ sidebarCollapsed }
						onToggleCollapse={ toggleSidebar }
					/>
				</ErrorBoundary>

				<section className="sdaa-cr-convo">
					<ConvoHeader
						sidebarCollapsed={ sidebarCollapsed }
						onExpandSidebar={ () => setSidebarCollapsed( false ) }
						changesCount={ changesCount }
						onShowChanges={ () => setShowChanges( true ) }
					/>

					<ErrorBoundary
						label={ __( 'Chat banners', 'sd-ai-agent' ) }
					>
						<ChatBanners />
					</ErrorBoundary>

					{ showChanges && (
						<ChangesDrawer
							sessionId={ currentSessionId }
							onClose={ () => setShowChanges( false ) }
							onChangesCountChange={ setChangesCount }
						/>
					) }

					<ErrorBoundary
						label={ __( 'Message list', 'sd-ai-agent' ) }
					>
						<MessageList />
					</ErrorBoundary>

					<ErrorBoundary
						label={ __( 'Message input', 'sd-ai-agent' ) }
					>
						<InputArea />
					</ErrorBoundary>
				</section>
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
		</div>
	);
}
