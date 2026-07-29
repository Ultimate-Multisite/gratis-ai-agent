/**
 * WordPress dependencies
 */
import {
	createRoot,
	useEffect,
	useCallback,
	useRef,
	useState,
} from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import STORE_NAME from '../store';
// Register sd-ai-agent-js/* client-side abilities into core/abilities
// before the chat mounts (t165 — closes the wiring gap in #815).
import '../abilities';
// Subscribe real DOM reflectors to frontend live-preview events.
import './reflectors';
import ErrorBoundary from '../components/error-boundary';
import ChatWidget from '../components/chat-widget';
import useKeyboardShortcut from './use-keyboard-shortcut';
import { getActiveJobs } from '../utils/active-jobs-storage';
import '../components/shared.css';

/**
 * Root floating widget component.
 *
 * Fetches providers and sessions on mount, gathers page context, and
 * renders the redesigned ChatWidget (launcher or panel). Registers a
 * configurable keyboard shortcut (default: Alt+A) to toggle the panel.
 *
 * @return {JSX.Element|null} The floating widget element, or null on boot error.
 */
function FloatingWidget() {
	const {
		fetchProviders,
		fetchSessions,
		fetchAlerts,
		openSession,
		sendMessage,
		setSelectedAgentId,
		setPageContext,
		setFloatingOpen,
		setFloatingMinimized,
		pollJob,
		restoreActiveJobs,
	} = useDispatch( STORE_NAME );

	const frontendOnboardingEnabled =
		!! window.sdAiAgentData?.frontendOnboarding;
	const [ frontendOnboardingMode, setFrontendOnboardingMode ] = useState(
		frontendOnboardingEnabled ? 'intro' : null
	);
	const frontendOnboardingStartedRef = useRef( false );

	const [
		isOpen,
		settings,
		bootError,
		providers,
		providersLoaded,
		sessions,
		sessionsLoaded,
		currentSessionId,
		sessionJobs,
	] = useSelect(
		( select ) => [
			select( STORE_NAME ).isFloatingOpen(),
			select( STORE_NAME ).getSettings(),
			select( STORE_NAME ).getBootError(),
			select( STORE_NAME ).getProviders(),
			select( STORE_NAME ).getProvidersLoaded(),
			select( STORE_NAME ).getSessions(),
			select( STORE_NAME ).getSessionsLoaded(),
			select( STORE_NAME ).getCurrentSessionId(),
			select( STORE_NAME ).getSessionJobs(),
		],
		[]
	);

	// Keyboard shortcut — default "alt+a", configurable via settings.
	const shortcut = settings?.keyboard_shortcut ?? 'alt+a';
	const togglePanel = useCallback( () => {
		setFloatingOpen( ! isOpen );
	}, [ setFloatingOpen, isOpen ] );
	useKeyboardShortcut( shortcut, togglePanel );

	useEffect( () => {
		fetchProviders();
		fetchSessions();
		restoreActiveJobs();

		try {
			const raw = sessionStorage.getItem( 'sdAiAgent_refreshRestore' );
			if ( raw ) {
				sessionStorage.removeItem( 'sdAiAgent_refreshRestore' );
				const restore = JSON.parse( raw );
				const restoreSessionId = parseInt( restore?.sessionId, 10 );
				if ( restoreSessionId > 0 ) {
					setFloatingOpen( restore.open !== false );
					setFloatingMinimized( !! restore.minimized );
					openSession( restoreSessionId );
				}
			}
		} catch ( _err ) {}
	}, [
		fetchProviders,
		fetchSessions,
		restoreActiveJobs,
		setFloatingOpen,
		setFloatingMinimized,
		openSession,
	] );

	// First-run frontend onboarding: open the widget as a centered assistant,
	// start the same server-side onboarding session used by the admin chat page,
	// then dock/minimize once a tool result reports affected frontend content so
	// live DOM updates are visible on the page being built.
	useEffect( () => {
		if ( ! frontendOnboardingEnabled ) {
			return;
		}

		setFloatingOpen( true );
		setFloatingMinimized( false );
	}, [ frontendOnboardingEnabled, setFloatingOpen, setFloatingMinimized ] );

	useEffect( () => {
		if ( ! sessionsLoaded ) {
			return;
		}

		// Keep the public widget attached to the same active/latest conversation as
		// the dedicated chat page after reloads or frontend navigation.
		if ( sessions.length || ! providers.length ) {
			setFrontendOnboardingMode( null );
			if ( sessions.length && ! currentSessionId ) {
				import( './frontend-onboarding' ).then(
					( { getHydrationSessionId } ) => {
						const sessionId = getHydrationSessionId(
							sessions,
							sessionJobs
						);
						if ( sessionId ) {
							openSession( sessionId );
						}
					}
				);
			}
			return;
		}

		if (
			! frontendOnboardingEnabled ||
			frontendOnboardingStartedRef.current ||
			! providersLoaded ||
			currentSessionId
		) {
			return;
		}

		frontendOnboardingStartedRef.current = true;
		import( './frontend-onboarding' )
			.then( ( { startOnboarding } ) =>
				startOnboarding( {
					openSession,
					sendMessage,
					setSelectedAgentId,
				} )
			)
			.catch( () => {
				setFrontendOnboardingMode( null );
			} );
	}, [
		frontendOnboardingEnabled,
		providersLoaded,
		providers.length,
		sessionsLoaded,
		sessions,
		sessionJobs,
		currentSessionId,
		openSession,
		sendMessage,
		setSelectedAgentId,
	] );

	useEffect( () => {
		const onboardingJob = sessionJobs?.[ currentSessionId ];

		if (
			! frontendOnboardingMode ||
			! onboardingJob ||
			onboardingJob.status !== 'processing'
		) {
			if ( frontendOnboardingMode === 'building' ) {
				setFrontendOnboardingMode( null );
			}

			return;
		}

		import( './frontend-onboarding' ).then( ( { hasActivity } ) => {
			if ( ! hasActivity( onboardingJob.toolCalls ) ) {
				return;
			}

			setFrontendOnboardingMode( 'building' );
			setFloatingMinimized( innerWidth < 601 );
		} );
	}, [
		frontendOnboardingMode,
		currentSessionId,
		sessionJobs,
		setFloatingMinimized,
	] );

	// Refresh providers when user returns to the tab (e.g., after making
	// changes on the Connectors admin page).
	useEffect( () => {
		const handleVisibilityChange = () => {
			if ( ! document.hidden && providersLoaded ) {
				fetchProviders();
			}
		};

		document.addEventListener( 'visibilitychange', handleVisibilityChange );
		return () =>
			document.removeEventListener(
				'visibilitychange',
				handleVisibilityChange
			);
	}, [ providersLoaded, fetchProviders ] );

	// Cross-page navigation survival (Phase 4 / t206):
	// Restore any active poll loops from sessionStorage. If the user navigated
	// away from an admin page while a background job was running, sessionStorage
	// still holds the sessionId → jobId mapping. Re-starting the poll loop here
	// reconnects to the in-progress job without a full page reload.
	// sessionStorage is cleared when the tab closes, so stale entries from a
	// previous tab session are never restored. pollJob handles already-completed
	// jobs gracefully — the first poll returns 'complete' and exits cleanly.
	useEffect( () => {
		const activeJobs = getActiveJobs();
		const entries = Object.entries( activeJobs );
		if ( entries.length === 0 ) {
			return;
		}
		for ( const [ sessionIdStr, jobId ] of entries ) {
			const sessionId = parseInt( sessionIdStr, 10 );
			if ( ! isNaN( sessionId ) && jobId ) {
				pollJob( jobId, sessionId );
			}
		}
	}, [ pollJob ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Fetch settings on mount so the keyboard shortcut is available.
	const { fetchSettings } = useDispatch( STORE_NAME );
	useEffect( () => {
		fetchSettings();
	}, [ fetchSettings ] );

	// Fetch alerts on mount and refresh every 5 minutes.
	// Skip if a boot error was raised — no point polling a broken API.
	useEffect( () => {
		if ( bootError ) {
			return;
		}
		fetchAlerts();
		const interval = setInterval( fetchAlerts, 5 * 60 * 1000 );
		return () => clearInterval( interval );
	}, [ fetchAlerts, bootError ] );

	// Gather page context on mount.
	useEffect( () => {
		const context = gatherPageContext();
		if ( context ) {
			setPageContext( context );
		}
	}, [ setPageContext ] );

	// If API calls failed, hide the widget entirely — the full error
	// screen is shown by the admin-page bundle instead.
	if ( bootError ) {
		return null;
	}

	return <ChatWidget frontendOnboardingMode={ frontendOnboardingMode } />;
}

/**
 * Gather structured context about the current WordPress admin/frontend page.
 *
 * Reads from localized widget data, body classes, `window.pagenow`,
 * `window.adminpage`, URL params, and page headings to build context for the AI.
 *
 * @return {{url: string, surface: string, is_frontend: boolean, live_preview: Object, admin_page?: string, screen_id?: string, post_id?: number, post_type?: string, page_title?: string}}
 *   Context object with available page metadata.
 */
function gatherPageContext() {
	const widgetData = window.sdAiAgentData || {};
	const isFrontend = !! widgetData.isFrontend;
	const context = {
		url: window.location.href,
		surface: isFrontend ? 'frontend' : 'admin',
		is_frontend: isFrontend,
		live_preview: {
			affected_descriptor_supported: true,
			reflection_bus: 'frontend-widget',
			requires_refresh_when_missing_affected: true,
			refresh_tool: 'sd-ai-agent-js/refresh-page',
		},
	};

	// Admin page slug from body classes.
	const bodyClasses = document.body.className;
	const pageMatch = bodyClasses.match(
		/(?:toplevel|[\w-]+)_page_[\w-]+|edit-php|post-php|upload-php|edit-tags-php/
	);
	if ( pageMatch ) {
		context.admin_page = pageMatch[ 0 ];
	}

	// Use window.pagenow if available (set by WordPress).
	if ( window.pagenow ) {
		context.admin_page = window.pagenow;
	}

	// Screen ID from window.adminpage (set by WordPress).
	if ( window.adminpage ) {
		context.screen_id = window.adminpage;
	}

	// Post ID if on an edit screen or frontend singular view.
	const urlParams = new URLSearchParams( window.location.search );
	const postParam = urlParams.get( 'post' );
	if ( postParam ) {
		context.post_id = parseInt( postParam, 10 ) || 0;
	}
	if ( widgetData.viewedPostId ) {
		context.post_id = parseInt( widgetData.viewedPostId, 10 ) || 0;
	}
	if ( widgetData.viewedPostType ) {
		context.post_type = widgetData.viewedPostType;
	}

	// Frontend fallback: WordPress body classes commonly include page-id-123
	// or postid-123 even when localized metadata is unavailable.
	if ( ! context.post_id && isFrontend ) {
		const idMatch = document.body.className.match(
			/(?:page-id|postid|attachmentid)-(\d+)/
		);
		if ( idMatch ) {
			context.post_id = parseInt( idMatch[ 1 ], 10 ) || 0;
		}
	}

	// Page title for extra context.
	const heading =
		document.querySelector( '.wrap > h1' ) ||
		document.querySelector( '#wpbody-content h1' ) ||
		document.querySelector( '.wp-block-post-title' ) ||
		document.querySelector( '.entry-title' ) ||
		document.querySelector( 'h1' );
	if ( heading ) {
		context.page_title = heading.textContent.trim();
	} else if ( widgetData.viewedTitle ) {
		context.page_title = widgetData.viewedTitle;
	} else if ( document.title ) {
		context.page_title = document.title;
	}

	return context;
}

// Admin shell plugins can load WordPress screens in an iframe. The parent
// screen owns the floating widget, so avoid rendering a duplicate in the frame.
if ( window.self === window.top ) {
	const wrapper = document.createElement( 'div' );
	wrapper.id = 'sdaa-floating-root';
	document.body.appendChild( wrapper );

	const root = createRoot( wrapper );
	root.render(
		<ErrorBoundary label={ __( 'AI Agent widget', 'sd-ai-agent' ) }>
			<FloatingWidget />
		</ErrorBoundary>
	);
}
