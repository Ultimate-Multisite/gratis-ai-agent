/**
 * Redesigned sidebar — status tabs (Active / Archived / Trash) with a flat
 * session list under them. Each session row shows the leading emoji from
 * its generated title (falls back to a chat glyph) and a per-row "more"
 * menu. Pulls sessions/search from the existing sd-ai-agent store.
 */

import { useState, useCallback, useRef, useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Button, CheckboxControl } from '@wordpress/components';
import {
	Icon,
	plus,
	search,
	moreVertical,
	pin,
	commentContent,
	sidebar as sidebarIcon,
} from '@wordpress/icons';

import STORE_NAME from '../../store';
import SessionContextMenu from '../session-context-menu';

// Match the leading emoji in a session title (extended grapheme) so we
// can surface it next to the row. Preceded by start-of-string and
// optionally followed by a space.
const LEADING_EMOJI_RE =
	/^(\p{Extended_Pictographic}(\p{Extended_Pictographic}|‍|️|[\u{1F3FB}-\u{1F3FF}])*)\s*/u;

/**
 * Split the stored title into `{ emoji, title }`. If the first character
 * isn't an emoji, `emoji` is an empty string and the whole title is kept.
 *
 * @param {string} raw
 * @return {{emoji: string, title: string}} Parsed emoji and title parts.
 */
function splitTitleEmoji( raw ) {
	const src = ( raw || '' ).trim();
	if ( ! src ) {
		return { emoji: '', title: '' };
	}
	const m = src.match( LEADING_EMOJI_RE );
	if ( m ) {
		return {
			emoji: m[ 1 ],
			title: src.slice( m[ 0 ].length ),
		};
	}
	return { emoji: '', title: src };
}

/**
 *
 * @param {*} dateStr
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
 * @param {Object}   root0
 * @param {*}        root0.session
 * @param {*}        root0.isActive
 * @param {*}        root0.job
 * @param {*}        root0.onPick
 * @param {boolean}  root0.selectable
 * @param {boolean}  root0.selected
 * @param {Function} root0.onToggleSelected
 */
function SessionRow( {
	session,
	isActive,
	job,
	onPick,
	selectable = false,
	selected = false,
	onToggleSelected,
} ) {
	const [ showMenu, setShowMenu ] = useState( false );
	const isPinned = parseInt( session.pinned, 10 ) === 1;
	const isRunning = !! job && job.status === 'processing';
	const isAwaiting = !! job && job.status === 'awaiting_confirmation';
	const changesCount = job?.changesCount;
	const { emoji, title } = splitTitleEmoji( session.title );

	let leadIcon;
	if ( isRunning ) {
		leadIcon = (
			<span
				className="sdaa-cr-dot"
				title={ __( 'Agent running', 'sd-ai-agent' ) }
			/>
		);
	} else if ( emoji ) {
		leadIcon = (
			<span className="sdaa-cr-session-row-emoji" aria-hidden="true">
				{ emoji }
			</span>
		);
	} else if ( isPinned ) {
		leadIcon = <Icon icon={ pin } size={ 16 } />;
	} else {
		leadIcon = <Icon icon={ commentContent } size={ 16 } />;
	}

	let metaLabel;
	let metaIsRunning = false;
	if ( isRunning ) {
		metaLabel = changesCount
			? `${ __( 'Running', 'sd-ai-agent' ) } · ${ changesCount } ${ __(
					'changes',
					'sd-ai-agent'
			  ) }`
			: __( 'Running…', 'sd-ai-agent' );
		metaIsRunning = true;
	} else if ( isAwaiting ) {
		metaLabel = __( 'Approval needed', 'sd-ai-agent' );
		metaIsRunning = true;
	} else {
		metaLabel = relativeTime( session.updated_at );
	}

	return (
		<div
			className={ `sdaa-cr-session-row${ isActive ? ' is-active' : '' }${
				selected ? ' is-selected' : ''
			}` }
		>
			{ selectable && (
				<CheckboxControl
					className="sdaa-cr-session-select"
					label={ sprintf(
						/* translators: %s: conversation title. */
						__( 'Select %s', 'superdav-ai-agent' ),
						title || __( 'Untitled', 'superdav-ai-agent' )
					) }
					hideLabelFromVision
					checked={ selected }
					onChange={ () => onToggleSelected( session.id ) }
					__nextHasNoMarginBottom
				/>
			) }
			<Button
				className="sdaa-cr-session-row-main"
				onClick={ () => onPick( session.id ) }
				aria-current={ isActive ? 'true' : undefined }
			>
				<span className="sdaa-cr-session-row-icon">{ leadIcon }</span>
				<span className="sdaa-cr-session-row-body">
					<span className="sdaa-cr-session-row-title">
						{ title || __( 'Untitled', 'sd-ai-agent' ) }
					</span>
					<span
						className={ `sdaa-cr-session-row-meta${
							metaIsRunning ? ' is-running' : ''
						}` }
					>
						{ metaLabel }
					</span>
				</span>
			</Button>
			<div className="sdaa-cr-session-row-actions">
				<Button
					className="sdaa-cr-icon-btn is-small"
					onClick={ () => setShowMenu( ( v ) => ! v ) }
					label={ __( 'Session options', 'sd-ai-agent' ) }
					showTooltip
					aria-haspopup="menu"
					aria-expanded={ showMenu }
				>
					<Icon icon={ moreVertical } size={ 16 } />
				</Button>
				{ showMenu && (
					<div className="sdaa-cr-context-menu">
						<SessionContextMenu
							session={ session }
							onClose={ () => setShowMenu( false ) }
							isOwner={ true }
						/>
					</div>
				) }
			</div>
		</div>
	);
}

const FILTERS = [
	{ key: 'active', label: __( 'Active', 'sd-ai-agent' ) },
	{ key: 'archived', label: __( 'Archived', 'sd-ai-agent' ) },
	{ key: 'trash', label: __( 'Trash', 'sd-ai-agent' ) },
];

/**
 *
 * @param {Object} root0
 * @param {*}      root0.collapsed
 * @param {*}      root0.onToggleCollapse
 */
export default function Sidebar( { collapsed, onToggleCollapse } ) {
	const {
		clearCurrentSession,
		fetchSessions,
		fetchSharedSessions,
		setSessionSearch,
		setSessionFilter,
		openSession,
		bulkSessionAction,
		emptySessionTrash,
	} = useDispatch( STORE_NAME );
	const {
		sessions,
		currentSessionId,
		sessionSearch,
		sessionFilter,
		sessionJobs,
	} = useSelect(
		( sel ) => ( {
			sessions: sel( STORE_NAME ).getSessions(),
			currentSessionId: sel( STORE_NAME ).getCurrentSessionId(),
			sessionSearch: sel( STORE_NAME ).getSessionSearch(),
			sessionFilter: sel( STORE_NAME ).getSessionFilter(),
			sessionJobs: sel( STORE_NAME ).getSessionJobs(),
		} ),
		[]
	);

	const searchTimer = useRef( null );
	const [ localQuery, setLocalQuery ] = useState( sessionSearch || '' );
	const [ selectedIds, setSelectedIds ] = useState( [] );

	useEffect( () => {
		fetchSessions();
	}, [ fetchSessions, sessionSearch, sessionFilter ] );

	// Fetch shared sessions on mount so the context menu can show
	// Share/Unshare based on whether each session is currently shared.
	useEffect( () => {
		fetchSharedSessions();
	}, [ fetchSharedSessions ] );

	useEffect( () => {
		const visibleIds = new Set( sessions.map( ( session ) => session.id ) );
		setSelectedIds( ( current ) =>
			current.filter( ( id ) => visibleIds.has( id ) )
		);
	}, [ sessions ] );

	useEffect( () => {
		if ( sessionFilter !== 'trash' ) {
			setSelectedIds( [] );
		}
	}, [ sessionFilter ] );

	const handleSearchChange = useCallback(
		( e ) => {
			const value = e.target.value;
			setLocalQuery( value );
			clearTimeout( searchTimer.current );
			searchTimer.current = setTimeout(
				() => setSessionSearch( value ),
				300
			);
		},
		[ setSessionSearch ]
	);

	// On medium+ screens (≥782px) opening a conversation keeps the sidebar
	// open so users can jump between conversations quickly. Only small
	// screens auto-collapse so the conversation panel isn't crowded out.
	const handlePickSession = useCallback(
		( id ) => {
			openSession( id );
			const isSmall =
				typeof window !== 'undefined' &&
				window.matchMedia &&
				window.matchMedia( '(max-width: 781px)' ).matches;
			if ( isSmall ) {
				onToggleCollapse();
			}
		},
		[ openSession, onToggleCollapse ]
	);

	const handleToggleSelected = useCallback( ( id ) => {
		setSelectedIds( ( current ) =>
			current.includes( id )
				? current.filter( ( selectedId ) => selectedId !== id )
				: [ ...current, id ]
		);
	}, [] );

	const handleSelectAll = useCallback( () => {
		setSelectedIds( ( current ) =>
			current.length === sessions.length
				? []
				: sessions.map( ( session ) => session.id )
		);
	}, [ sessions ] );

	const handleBulkRestore = useCallback( async () => {
		await bulkSessionAction( selectedIds, 'restore' );
		setSelectedIds( [] );
	}, [ bulkSessionAction, selectedIds ] );

	const handleBulkDelete = useCallback( async () => {
		const count = selectedIds.length;
		// eslint-disable-next-line no-alert
		const confirmed = window.confirm(
			sprintf(
				/* translators: %d: number of selected conversations. */
				_n(
					'Permanently delete %d conversation? This cannot be undone.',
					'Permanently delete %d conversations? This cannot be undone.',
					count,
					'superdav-ai-agent'
				),
				count
			)
		);
		if ( confirmed ) {
			await bulkSessionAction( selectedIds, 'delete' );
			setSelectedIds( [] );
		}
	}, [ bulkSessionAction, selectedIds ] );

	const handleEmptyTrash = useCallback( async () => {
		// eslint-disable-next-line no-alert
		const confirmed = window.confirm(
			__(
				'Permanently delete all conversations in Trash? This cannot be undone.',
				'superdav-ai-agent'
			)
		);
		if ( confirmed ) {
			await emptySessionTrash();
			setSelectedIds( [] );
		}
	}, [ emptySessionTrash ] );

	if ( collapsed ) {
		return null;
	}

	const total = sessions.length;

	return (
		<aside
			className="sdaa-cr-sidebar"
			aria-label={ __( 'Conversations', 'sd-ai-agent' ) }
		>
			<div className="sdaa-cr-sidebar-brand">
				<div className="sdaa-cr-sidebar-brand-text">
					<div className="sdaa-cr-sidebar-brand-title">
						{ __( 'SD AI Agent', 'sd-ai-agent' ) }
					</div>
					<div className="sdaa-cr-sidebar-brand-subtitle">
						{ __(
							'Your AI teammate for WordPress',
							'sd-ai-agent'
						) }
					</div>
				</div>
				<button
					type="button"
					className="sdaa-cr-icon-btn sdaa-cr-sidebar-brand-collapse"
					onClick={ onToggleCollapse }
					aria-label={ __( 'Hide sidebar', 'sd-ai-agent' ) }
				>
					<Icon icon={ sidebarIcon } size={ 16 } />
				</button>
			</div>
			<div className="sdaa-cr-sidebar-head">
				<button
					type="button"
					className="components-button is-primary is-compact sdaa-cr-new-chat"
					onClick={ () => {
						clearCurrentSession();
					} }
				>
					<Icon icon={ plus } size={ 16 } />
					<span>{ __( 'New chat', 'sd-ai-agent' ) }</span>
				</button>
			</div>

			<div
				className="sdaa-cr-sidebar-tabs"
				role="tablist"
				aria-label={ __( 'Session filter', 'sd-ai-agent' ) }
			>
				{ FILTERS.map( ( f ) => {
					const active = sessionFilter === f.key;
					return (
						<button
							key={ f.key }
							type="button"
							role="tab"
							aria-selected={ active }
							className={ `sdaa-cr-sidebar-tab${
								active ? ' is-active' : ''
							}` }
							onClick={ () => setSessionFilter( f.key ) }
						>
							{ f.label }
						</button>
					);
				} ) }
			</div>

			<div className="sdaa-cr-sidebar-search">
				<div className="sdaa-cr-search-field">
					<span className="sdaa-cr-search-icon" aria-hidden="true">
						<Icon icon={ search } size={ 14 } />
					</span>
					<input
						type="text"
						className="sdaa-cr-search-input"
						placeholder={ __(
							'Search conversations',
							'sd-ai-agent'
						) }
						aria-label={ __(
							'Search conversations',
							'sd-ai-agent'
						) }
						value={ localQuery }
						onChange={ handleSearchChange }
					/>
				</div>
			</div>

			{ sessionFilter === 'trash' && (
				<div className="sdaa-cr-trash-actions">
					<div className="sdaa-cr-trash-selection">
						<CheckboxControl
							label={ __( 'Select all', 'superdav-ai-agent' ) }
							checked={
								total > 0 && selectedIds.length === total
							}
							onChange={ handleSelectAll }
							disabled={ total === 0 }
							__nextHasNoMarginBottom
						/>
						<span aria-live="polite">
							{ sprintf(
								/* translators: %d: number of selected conversations. */
								__( '%d selected', 'superdav-ai-agent' ),
								selectedIds.length
							) }
						</span>
					</div>
					<div className="sdaa-cr-trash-action-buttons">
						<Button
							variant="secondary"
							onClick={ handleBulkRestore }
							disabled={ selectedIds.length === 0 }
						>
							{ __( 'Restore', 'superdav-ai-agent' ) }
						</Button>
						<Button
							variant="secondary"
							isDestructive
							onClick={ handleBulkDelete }
							disabled={ selectedIds.length === 0 }
						>
							{ __( 'Delete permanently', 'superdav-ai-agent' ) }
						</Button>
					</div>
					<Button
						className="sdaa-cr-empty-trash"
						variant="tertiary"
						isDestructive
						onClick={ handleEmptyTrash }
						disabled={ total === 0 }
					>
						{ __( 'Empty Trash', 'superdav-ai-agent' ) }
					</Button>
				</div>
			) }

			<div className="sdaa-cr-sidebar-list">
				{ total === 0 && (
					<div className="sdaa-cr-session-empty">
						{ sessionFilter === 'trash' &&
							__( 'Trash is empty', 'sd-ai-agent' ) }
						{ sessionFilter === 'archived' &&
							__( 'No archived conversations', 'sd-ai-agent' ) }
						{ sessionFilter === 'active' &&
							__( 'No conversations yet', 'sd-ai-agent' ) }
					</div>
				) }
				{ sessions.map( ( s ) => (
					<SessionRow
						key={ s.id }
						session={ s }
						isActive={ currentSessionId === s.id }
						job={ sessionJobs[ s.id ] || null }
						onPick={ handlePickSession }
						selectable={ sessionFilter === 'trash' }
						selected={ selectedIds.includes( s.id ) }
						onToggleSelected={ handleToggleSelected }
					/>
				) ) }
			</div>

			<div className="sdaa-cr-sidebar-foot">
				<span>
					{ total === 1
						? __( '1 conversation', 'sd-ai-agent' )
						: `${ total } ${ __(
								'conversations',
								'sd-ai-agent'
						  ) }` }
				</span>
			</div>
		</aside>
	);
}
