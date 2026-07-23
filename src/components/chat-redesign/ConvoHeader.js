/**
 * Conversation header — session title, running dot, changes pill,
 * read-aloud / more icon buttons.
 */

import { useState, useCallback, useRef, useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { Icon, moreHorizontal, sidebar as sidebarIcon } from '@wordpress/icons';

import STORE_NAME from '../../store';
import { getBranding } from '../../utils/branding';
import { isTTSSupported } from '../use-text-to-speech';
import SessionContextMenu from '../session-context-menu';
import { AiIcon, Speaker, SpeakerMuted } from './icons';

/**
 *
 * @param {Object}  root0
 * @param {*}       root0.sidebarCollapsed
 * @param {*}       root0.onExpandSidebar
 * @param {*}       root0.changesCount
 * @param {*}       root0.onShowChanges
 * @param {boolean} root0.isSimpleMode
 */
export default function ConvoHeader( {
	sidebarCollapsed,
	onExpandSidebar,
	changesCount,
	onShowChanges,
	isSimpleMode = false,
} ) {
	const { renameSession, setTtsEnabled } = useDispatch( STORE_NAME );
	const { session, isRunning, ttsEnabled } = useSelect( ( sel ) => {
		const store = sel( STORE_NAME );
		const currentSessionId = store.getCurrentSessionId();
		const sessions = store.getSessions();
		const sessionJobs = store.getSessionJobs();
		return {
			session:
				sessions.find( ( s ) => s.id === currentSessionId ) || null,
			isRunning: !! sessionJobs[ currentSessionId ],
			ttsEnabled: store.isTtsEnabled(),
		};
	}, [] );

	const [ editing, setEditing ] = useState( false );
	const [ draft, setDraft ] = useState( '' );
	const [ showMenu, setShowMenu ] = useState( false );
	const inputRef = useRef( null );

	useEffect( () => {
		if ( editing && inputRef.current ) {
			inputRef.current.focus();
			inputRef.current.select();
		}
	}, [ editing ] );

	const branding = getBranding();
	const title = isSimpleMode
		? branding.agentName || __( 'SD AI Agent', 'sd-ai-agent' )
		: session?.title || __( 'New conversation', 'sd-ai-agent' );

	const startRename = useCallback( () => {
		if ( ! session ) {
			return;
		}
		setDraft( session.title || '' );
		setEditing( true );
	}, [ session ] );

	const commitRename = useCallback( () => {
		if ( session && draft.trim() && draft.trim() !== session.title ) {
			renameSession( session.id, draft.trim() );
		}
		setEditing( false );
	}, [ session, draft, renameSession ] );

	return (
		<div className="sdaa-cr-convo-head">
			{ sidebarCollapsed && ! isSimpleMode && (
				<button
					type="button"
					className="sdaa-cr-icon-btn"
					onClick={ onExpandSidebar }
					aria-label={ __( 'Expand sidebar', 'sd-ai-agent' ) }
				>
					<Icon icon={ sidebarIcon } size={ 16 } />
				</button>
			) }

			{ editing ? (
				<input
					ref={ inputRef }
					className="sdaa-cr-convo-head-title-input"
					value={ draft }
					onChange={ ( e ) => setDraft( e.target.value ) }
					onBlur={ commitRename }
					onKeyDown={ ( e ) => {
						if ( e.key === 'Enter' ) {
							e.preventDefault();
							commitRename();
						} else if ( e.key === 'Escape' ) {
							setEditing( false );
						}
					} }
				/>
			) : (
				<button
					type="button"
					className="sdaa-cr-convo-head-title"
					onClick={
						session && ! isSimpleMode ? startRename : undefined
					}
					disabled={ ! session || isSimpleMode }
					title={ title }
				>
					<span className="sdaa-cr-convo-head-title-text">
						{ title }
					</span>
					{ isRunning && (
						<span
							className="sdaa-cr-dot"
							title={ __( 'Agent running', 'sd-ai-agent' ) }
						/>
					) }
					{ session && ! isSimpleMode && (
						<span className="sdaa-cr-convo-head-rename-hint">
							{ __( 'Click to rename', 'sd-ai-agent' ) }
						</span>
					) }
				</button>
			) }

			<div className="sdaa-cr-convo-head-actions">
				<span
					className="sdaa-cr-convo-head-ai-avatar"
					aria-hidden="true"
				>
					<AiIcon thinking={ isRunning } size={ 16 } />
				</span>
				{ ! isSimpleMode && changesCount > 0 && (
					<span
						className="sdaa-cr-changes-pill"
						title={ __(
							'Changes made in this session',
							'sd-ai-agent'
						) }
					>
						<span className="sdaa-cr-changes-pill-count">
							{ changesCount }
						</span>
						<span>{ __( 'changes', 'sd-ai-agent' ) }</span>
						<button
							type="button"
							className="sdaa-cr-changes-pill-btn"
							onClick={ onShowChanges }
						>
							{ __( 'View', 'sd-ai-agent' ) }
						</button>
					</span>
				) }
				{ isTTSSupported && (
					<button
						type="button"
						className={ `sdaa-cr-icon-btn sdaa-tts-btn${
							ttsEnabled ? ' is-active' : ''
						}` }
						onClick={ () => setTtsEnabled( ! ttsEnabled ) }
						aria-label={
							ttsEnabled
								? __( 'Disable read aloud', 'sd-ai-agent' )
								: __( 'Read responses aloud', 'sd-ai-agent' )
						}
						aria-pressed={ ttsEnabled }
					>
						{ ttsEnabled ? <Speaker /> : <SpeakerMuted /> }
					</button>
				) }
				{ ! isSimpleMode && (
					<div className="sdaa-cr-convo-head-menu-wrap">
						<button
							type="button"
							className="sdaa-cr-icon-btn"
							aria-label={ __( 'More options', 'sd-ai-agent' ) }
							aria-haspopup="menu"
							aria-expanded={ showMenu }
							disabled={ ! session }
							onClick={ () => setShowMenu( ( v ) => ! v ) }
						>
							<Icon icon={ moreHorizontal } size={ 16 } />
						</button>
						{ showMenu && session && (
							<div className="sdaa-cr-context-menu sdaa-cr-context-menu--header">
								<SessionContextMenu
									session={ session }
									onClose={ () => setShowMenu( false ) }
									isOwner={ true }
								/>
							</div>
						) }
					</div>
				) }
			</div>
		</div>
	);
}
