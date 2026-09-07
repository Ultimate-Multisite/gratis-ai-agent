/**
 * Header for the floating widget — avatar + session title with dropdown,
 * status sub-line, new-chat button, minimize and close controls.
 *
 * The session dropdown is a compact drawer listing recent sessions and
 * reuses the same store actions as the full chat (openSession, new chat).
 */

import { useState, useRef, useEffect, useCallback } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import {
	Icon,
	plus,
	close,
	chevronDown,
	commentContent,
} from '@wordpress/icons';

import STORE_NAME from '../../store';
import { getBranding } from '../../utils/branding';
import {
	AiIcon,
	Microphone,
	Speaker,
	SpeakerMuted,
} from '../chat-redesign/icons';

/**
 *
 */
function MinimizeIcon() {
	return (
		<svg
			xmlns="http://www.w3.org/2000/svg"
			viewBox="0 0 24 24"
			width="16"
			height="16"
			fill="none"
			stroke="currentColor"
			strokeWidth="2"
			strokeLinecap="round"
			aria-hidden="true"
			focusable="false"
		>
			<path d="M6 18h12" />
		</svg>
	);
}

/**
 *
 * @param {string} dateStr
 */
function relativeTime( dateStr ) {
	if ( ! dateStr ) {
		return '';
	}
	const date = new Date( dateStr + 'Z' );
	const now = new Date();
	const diff = Math.floor( ( now - date ) / 1000 );
	if ( diff < 60 ) {
		return __( 'just now', 'sd-ai-agent' );
	}
	if ( diff < 3600 ) {
		return Math.floor( diff / 60 ) + 'm ago';
	}
	if ( diff < 86400 ) {
		return Math.floor( diff / 3600 ) + 'h ago';
	}
	if ( diff < 604800 ) {
		return Math.floor( diff / 86400 ) + 'd ago';
	}
	return date.toLocaleDateString();
}

/**
 *
 * @param {Object}  root0
 * @param {*}       root0.isMinimized
 * @param {*}       root0.onToggleMinimize
 * @param {*}       root0.onDragHandleMouseDown
 * @param {boolean} root0.isSimpleMode
 * @param {Object}  root0.voiceConversation
 */
export default function WidgetHeader( {
	isMinimized,
	onToggleMinimize,
	onDragHandleMouseDown,
	isSimpleMode = false,
	voiceConversation = {},
} ) {
	const {
		setFloatingOpen,
		clearCurrentSession,
		openSession,
		fetchSessions,
		setTtsEnabled,
	} = useDispatch( STORE_NAME );

	const {
		sessions,
		currentSessionId,
		sessionJobs,
		activeModelName,
		ttsEnabled,
	} = useSelect( ( sel ) => {
		const store = sel( STORE_NAME );
		const providers = store.getProviders() || [];
		const providerId = store.getSelectedProviderId();
		const modelId = store.getSelectedModelId();
		const provider =
			providers.find( ( p ) => p.id === providerId ) || providers[ 0 ];
		const model =
			provider?.models?.find( ( m ) => m.id === modelId ) ||
			provider?.models?.[ 0 ];
		return {
			sessions: store.getSessions(),
			currentSessionId: store.getCurrentSessionId(),
			sessionJobs: store.getSessionJobs(),
			activeModelName: model?.name || model?.id || '',
			ttsEnabled: store.isTtsEnabled?.() || false,
		};
	}, [] );

	const [ drawerOpen, setDrawerOpen ] = useState( false );
	const drawerRef = useRef( null );

	useEffect( () => {
		if ( ! drawerOpen ) {
			return undefined;
		}
		const handler = ( e ) => {
			if (
				drawerRef.current &&
				! drawerRef.current.contains( e.target )
			) {
				setDrawerOpen( false );
			}
		};
		document.addEventListener( 'mousedown', handler );
		return () => document.removeEventListener( 'mousedown', handler );
	}, [ drawerOpen ] );

	useEffect( () => {
		if ( drawerOpen ) {
			fetchSessions();
		}
	}, [ drawerOpen, fetchSessions ] );

	const session = sessions.find( ( s ) => s.id === currentSessionId ) || null;
	const branding = getBranding();
	const agentName = branding.agentName || __( 'SD AI Agent', 'sd-ai-agent' );
	const title = isSimpleMode ? agentName : session?.title || agentName;
	const runningJob = currentSessionId
		? sessionJobs[ currentSessionId ]
		: null;
	const isRunning = runningJob?.status === 'processing';

	const statusLabel = ( () => {
		if ( isRunning ) {
			const runningCount = Object.values( sessionJobs ).filter(
				( j ) => j && j.status === 'processing'
			).length;
			if ( runningCount > 1 ) {
				return __( 'Working…', 'sd-ai-agent' );
			}
			return __( 'Working…', 'sd-ai-agent' );
		}
		if ( isSimpleMode ) {
			return __( 'Ready', 'sd-ai-agent' );
		}
		return activeModelName
			? `${ __( 'Ready', 'sd-ai-agent' ) } · ${ activeModelName }`
			: __( 'Ready', 'sd-ai-agent' );
	} )();
	const displayedStatusLabel =
		( voiceConversation.phase || 'idle' ) === 'idle'
			? statusLabel
			: voiceConversation.statusLabel;
	let readAloudLabel = ttsEnabled
		? __( 'Disable read aloud', 'superdav-ai-agent' )
		: __( 'Read responses aloud', 'superdav-ai-agent' );
	if ( voiceConversation.isSpeaking ) {
		readAloudLabel = __( 'Stop reading aloud', 'superdav-ai-agent' );
	}
	const voiceModeLabel = voiceConversation.voiceModeEnabled
		? __( 'Disable voice mode', 'superdav-ai-agent' )
		: __( 'Enable voice mode', 'superdav-ai-agent' );

	const handlePickSession = useCallback(
		( id ) => {
			openSession( id );
			setDrawerOpen( false );
		},
		[ openSession ]
	);

	const handleNewChat = useCallback(
		( event ) => {
			event.stopPropagation();
			clearCurrentSession();
			setDrawerOpen( false );
		},
		[ clearCurrentSession ]
	);

	const recent = sessions.slice( 0, 8 );

	return (
		<div
			className="sdaa-w-head"
			role="presentation"
			onMouseDown={ onDragHandleMouseDown }
		>
			<div className="sdaa-w-head-title-wrap" ref={ drawerRef }>
				<div
					className={ `sdaa-w-head-session${
						drawerOpen && ! isSimpleMode ? ' is-open' : ''
					}` }
					role={ isSimpleMode ? undefined : 'button' }
					tabIndex={ isSimpleMode ? undefined : 0 }
					onClick={
						isSimpleMode
							? undefined
							: () => setDrawerOpen( ( v ) => ! v )
					}
					onKeyDown={
						isSimpleMode
							? undefined
							: ( e ) => {
									if ( e.key === 'Enter' || e.key === ' ' ) {
										e.preventDefault();
										setDrawerOpen( ( v ) => ! v );
									}
							  }
					}
					title={
						isSimpleMode
							? title
							: __( 'Switch session', 'sd-ai-agent' )
					}
					aria-haspopup={ isSimpleMode ? undefined : 'menu' }
					aria-expanded={ isSimpleMode ? undefined : drawerOpen }
				>
					<span className="sdaa-w-avatar" aria-hidden="true">
						{ branding.logoUrl ? (
							<img
								src={ branding.logoUrl }
								alt=""
								className="sdaa-w-avatar-logo"
							/>
						) : (
							<AiIcon thinking={ isRunning } />
						) }
					</span>
					<span className="sdaa-w-head-titles">
						<span className="sdaa-w-head-name">
							<span className="sdaa-w-head-name-text">
								{ title }
							</span>
							{ ! isSimpleMode && (
								<span className="sdaa-w-head-caret">
									<Icon icon={ chevronDown } size={ 14 } />
								</span>
							) }
						</span>
						<span
							className={ `sdaa-w-head-status${
								isRunning ? ' is-running' : ''
							}` }
						>
							<span className="sdaa-w-head-status-dot" />
							<span className="sdaa-w-head-status-text">
								{ displayedStatusLabel }
							</span>
						</span>
					</span>
				</div>

				{ ! isSimpleMode && drawerOpen && (
					<div className="sdaa-w-session-drawer" role="menu">
						<div className="sdaa-w-session-drawer-head">
							<span>
								{ __( 'Conversations', 'sd-ai-agent' ) }
							</span>
							<button
								type="button"
								className="sdaa-w-icon-btn is-small"
								onClick={ () => setDrawerOpen( false ) }
								aria-label={ __( 'Close', 'sd-ai-agent' ) }
							>
								<Icon icon={ close } size={ 14 } />
							</button>
						</div>
						<div className="sdaa-w-session-drawer-list">
							{ recent.length === 0 && (
								<div className="sdaa-w-session-drawer-empty">
									{ __(
										'No conversations yet',
										'sd-ai-agent'
									) }
								</div>
							) }
							{ recent.map( ( s ) => {
								const active = s.id === currentSessionId;
								const job = sessionJobs[ s.id ];
								const running = job?.status === 'processing';
								return (
									<button
										type="button"
										key={ s.id }
										className={ `sdaa-w-session-drawer-item${
											active ? ' is-active' : ''
										}` }
										onClick={ () =>
											handlePickSession( s.id )
										}
										role="menuitem"
									>
										<span className="sdaa-w-session-drawer-item-icon">
											{ running ? (
												<span className="sdaa-w-head-status-dot is-running" />
											) : (
												<Icon
													icon={ commentContent }
													size={ 12 }
												/>
											) }
										</span>
										<span className="sdaa-w-session-drawer-item-title">
											{ s.title ||
												__(
													'Untitled',
													'sd-ai-agent'
												) }
										</span>
										<span className="sdaa-w-session-drawer-item-time">
											{ relativeTime( s.updated_at ) }
										</span>
									</button>
								);
							} ) }
						</div>
					</div>
				) }
			</div>

			{ ! isSimpleMode && (
				<button
					type="button"
					className="sdaa-w-new-btn"
					onMouseDown={ ( event ) => event.stopPropagation() }
					onClick={ handleNewChat }
					aria-label={ __( 'Start new chat', 'sd-ai-agent' ) }
					title={ __( 'Start new chat', 'sd-ai-agent' ) }
				>
					<Icon icon={ plus } size={ 18 } />
				</button>
			) }

			<div className="sdaa-w-head-actions">
				{ ! isMinimized && voiceConversation.isSpeechSupported && (
					<button
						type="button"
						className={ `sdaa-w-icon-btn${
							ttsEnabled || voiceConversation.isSpeaking
								? ' is-active'
								: ''
						}` }
						onMouseDown={ ( event ) => event.stopPropagation() }
						onClick={
							voiceConversation.isSpeaking
								? voiceConversation.stopSpeaking
								: () => setTtsEnabled( ! ttsEnabled )
						}
						aria-label={ readAloudLabel }
						aria-pressed={
							ttsEnabled || voiceConversation.isSpeaking
						}
					>
						{ ttsEnabled || voiceConversation.isSpeaking ? (
							<Speaker />
						) : (
							<SpeakerMuted />
						) }
					</button>
				) }
				{ ! isMinimized && voiceConversation.isSupported && (
					<button
						type="button"
						className={ `sdaa-w-icon-btn${
							voiceConversation.voiceModeEnabled
								? ' is-active'
								: ''
						}` }
						onMouseDown={ ( event ) => event.stopPropagation() }
						onClick={ voiceConversation.toggleVoiceMode }
						aria-label={ voiceModeLabel }
						aria-pressed={ voiceConversation.voiceModeEnabled }
					>
						<Microphone />
					</button>
				) }
				<button
					type="button"
					className="sdaa-w-icon-btn"
					onClick={ onToggleMinimize }
					aria-label={
						isMinimized
							? __( 'Expand', 'sd-ai-agent' )
							: __( 'Minimize', 'sd-ai-agent' )
					}
					title={
						isMinimized
							? __( 'Expand', 'sd-ai-agent' )
							: __( 'Minimize', 'sd-ai-agent' )
					}
				>
					<MinimizeIcon />
				</button>
				<button
					type="button"
					className="sdaa-w-icon-btn"
					data-dismiss-only="true"
					onClick={ ( e ) => {
						e.stopPropagation();
						setFloatingOpen( false );
					} }
					aria-label={ __( 'Close', 'sd-ai-agent' ) }
					title={ __( 'Close', 'sd-ai-agent' ) }
				>
					<Icon icon={ close } size={ 16 } />
				</button>
			</div>
		</div>
	);
}
