/**
 * WordPress dependencies
 */
import {
	createRoot,
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
	lazy,
	Suspense,
} from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';

/**
 * Internal dependencies
 */
import STORE_NAME from '../store';
// Register sd-ai-agent-js/* client-side abilities into core/abilities
// before the chat mounts (t165 — closes the wiring gap in #815).
import '../abilities';
// The generated-theme completion validator is needed by the Setup Assistant,
// but not by the customer-facing floating widget.
import '../abilities/theme-completion-registration';
import ChatRedesign from '../components/chat-redesign';
import BootError from '../components/boot-error';
import { useKeyboardShortcuts } from '../utils/keyboard-shortcuts';
import {
	buildConnectionNoticeText,
	claimConnectionNotice,
	findSuperdavProvider,
} from './superdav-autoconnect';
import '../components/shared.css';
import './style.css';

// These components are rendered only in specific, uncommon states:
//  - OnboardingBootstrap    → first install after a provider is configured
//                             (drops the user straight into the unified
//                             Setup Assistant agent)
//  - ShortcutsHelp          → user presses Mod+/ (explicitly intentional)
// None of them appear during a normal chat session, so they are lazy-loaded.
const OnboardingBootstrap = lazy( () =>
	import(
		/* webpackChunkName: "onboarding-bootstrap", webpackPrefetch: true */
		'../components/onboarding-bootstrap'
	)
);
const ShortcutsHelp = lazy( () =>
	import(
		/* webpackChunkName: "shortcuts-help", webpackPrefetch: true */
		'../components/shortcuts-help'
	)
);

/**
 * Root admin page application component.
 *
 * Implements the Onboarding v2 flow (see todo/PLANS.md "Onboarding v2: Gate
 * + AI-Driven Discovery"). The legacy multi-step wizard is gone — the AI
 * agent drives discovery conversationally.
 *
 * 1. **Silent service connection** — the chat opens immediately. If no AI
 *    provider is configured yet, /providers auto-provisions the bundled
 *    Superdav AI service token and the chat shows a one-time notice.
 *
 * 2. **First-run agent** — shown when a provider exists but onboarding has
 *    not yet completed. The app opens one unified Setup Assistant session;
 *    the agent investigates the site and can build a theme if requested or
 *    required. The old content-count split and Theme Builder route are gone.
 *
 * 3. After onboarding completes the full redesigned chat layout is shown.
 *
 * @param {Object}      props                  Component props.
 * @param {number|null} props.initialSessionId Session selected by a deep link.
 * @return {JSX.Element|null} Admin page app element, or null while settings are loading.
 */
function AdminPageApp( { initialSessionId = null } ) {
	const {
		fetchProviders,
		fetchSessions,
		fetchSettings,
		clearCurrentSession,
		openSession,
		restoreActiveJobs,
		setShowShortcutsHelp,
		appendMessage,
	} = useDispatch( STORE_NAME );
	const serviceNoticeDisplayedRef = useRef( false );
	const initialSessionOpenedRef = useRef( false );
	const [ serviceConnectionNotice, setServiceConnectionNotice ] =
		useState( '' );
	const [
		serviceConnectionNoticeSettled,
		setServiceConnectionNoticeSettled,
	] = useState( false );
	const {
		settings,
		settingsLoaded,
		bootError,
		providers,
		providersLoaded,
		showShortcuts,
	} = useSelect(
		( select ) => ( {
			settings: select( STORE_NAME ).getSettings(),
			settingsLoaded: select( STORE_NAME ).getSettingsLoaded(),
			bootError: select( STORE_NAME ).getBootError(),
			providers: select( STORE_NAME ).getProviders(),
			providersLoaded: select( STORE_NAME ).getProvidersLoaded(),
			showShortcuts: select( STORE_NAME ).isShowingShortcutsHelp(),
		} ),
		[]
	);

	useEffect( () => {
		fetchProviders();
		fetchSessions();
		fetchSettings();
		restoreActiveJobs();

		if (
			! initialSessionOpenedRef.current &&
			Number.isInteger( initialSessionId ) &&
			initialSessionId > 0
		) {
			initialSessionOpenedRef.current = true;
			openSession( initialSessionId );
		}
	}, [
		fetchProviders,
		fetchSessions,
		fetchSettings,
		initialSessionId,
		openSession,
		restoreActiveJobs,
	] );

	const appendServiceConnectionNotice = useCallback(
		( notice ) => {
			if ( ! notice || serviceNoticeDisplayedRef.current ) {
				return;
			}

			serviceNoticeDisplayedRef.current = true;
			appendMessage( {
				role: 'system',
				parts: [ { text: notice } ],
				ts: new Date().toISOString(),
			} );
		},
		[ appendMessage ]
	);
	const markServiceConnectionNoticeDisplayed = useCallback( () => {
		serviceNoticeDisplayedRef.current = true;
	}, [] );

	// Build a one-time chat notice when the backend reports a freshly-created
	// managed service token. This notice is held back from the normal chat path
	// when onboarding will open a session so it can be inserted after openSession()
	// and before the Setup Assistant kickoff message.
	useEffect( () => {
		if ( ! settingsLoaded || ! providersLoaded ) {
			return;
		}

		const superdavProvider = findSuperdavProvider( providers );
		const status = superdavProvider?.status || {};
		if (
			status.connection_notice_pending &&
			claimConnectionNotice( status )
		) {
			setServiceConnectionNotice( buildConnectionNoticeText( status ) );
		}
		setServiceConnectionNoticeSettled( true );
	}, [ settingsLoaded, providersLoaded, providers ] );

	// Poll for providers while /providers is still trying to auto-provision the
	// managed Superdav connection. The chat remains open; no connector screen is
	// rendered for this state.
	useEffect( () => {
		const hasProvider = providers.length > 0;
		if ( ! providersLoaded || hasProvider ) {
			return;
		}

		const timer = setInterval( () => {
			fetchProviders();
		}, 5000 );

		return () => clearInterval( timer );
	}, [ providers, providersLoaded, fetchProviders ] );

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

	const onboardingComplete = settings?.onboarding_complete !== false;
	const hasProvider = providersLoaded && providers.length > 0;

	useEffect( () => {
		if ( ! settingsLoaded || ! onboardingComplete ) {
			return;
		}

		appendServiceConnectionNotice( serviceConnectionNotice );
	}, [
		settingsLoaded,
		onboardingComplete,
		serviceConnectionNotice,
		appendServiceConnectionNotice,
	] );

	// Keyboard shortcuts.
	const shortcuts = useMemo(
		() => ( {
			'mod+n': () => clearCurrentSession(),
			'mod+k': () => {
				const searchInput = document.querySelector(
					'.sdaa-cr-search-input'
				);
				if ( searchInput ) {
					searchInput.focus();
				}
			},
			'mod+/': () => setShowShortcutsHelp( ! showShortcuts ),
		} ),
		[ clearCurrentSession, setShowShortcutsHelp, showShortcuts ]
	);

	useKeyboardShortcuts( shortcuts );

	// Show a friendly error instead of spinning forever when API calls fail.
	if ( bootError ) {
		return <BootError />;
	}

	// Block only until settings are available (~90 ms). Do NOT block on
	// providersLoaded (~1,180 ms with the SDK's live model-listing call) so
	// the chat shell renders within one network round-trip.
	//
	// Startup logic while providers are still loading:
	//   - Assume a provider exists (optimistic) so ChatRedesign renders.
	//   - The model picker already handles an empty providers array gracefully.
	//   - If providers finish loading with an empty list, background /providers
	//     retries keep running without replacing the chat shell.
	//   - Onboarding waits until a provider exists so the Setup Assistant kickoff
	//     can use the managed service token that was just provisioned.
	if ( ! settingsLoaded ) {
		return null;
	}

	// Phase 1 is intentionally not a visible gate. The chat opens immediately;
	// /providers handles managed Superdav auto-provisioning and this component
	// only surfaces the resulting token-created notice inside the conversation.

	// Phase 2 gate: connector exists but onboarding not yet complete.
	//
	// Onboarding v2 — no wizard, no mode picker, no content-count route split.
	// The bootstrapper opens one unified Setup Assistant session through
	// /onboarding/start. The agent investigates first and can build a theme if
	// the user asks or the site requires it.
	if (
		! onboardingComplete &&
		hasProvider &&
		serviceConnectionNoticeSettled
	) {
		return (
			<Suspense fallback={ null }>
				<OnboardingBootstrap
					initialSystemNotice={ serviceConnectionNotice }
					onInitialSystemNoticeAppended={
						markServiceConnectionNoticeDisplayed
					}
				/>
			</Suspense>
		);
	}

	// Normal chat layout — redesigned shell.
	return (
		<>
			<ChatRedesign />
			{ showShortcuts && (
				<Suspense fallback={ null }>
					<ShortcutsHelp
						onClose={ () => setShowShortcutsHelp( false ) }
					/>
				</Suspense>
			) }
		</>
	);
}

/**
 * Mount the AdminPageApp into a given container element.
 *
 * Called by the unified admin's ChatRoute via window.sdAiAgentChat.mount().
 * Returns a root instance so the caller can unmount cleanly.
 *
 * @param {HTMLElement}          container DOM element to mount into.
 * @param {{sessionId?: number}} options   Optional mount options.
 * @return {import('@wordpress/element').Root} React root.
 */
function mountAdminPageApp( container, options = {} ) {
	const root = createRoot( container );
	root.render(
		<AdminPageApp initialSessionId={ options.sessionId ?? null } />
	);
	return root;
}

/**
 * Expose the mount/unmount API for the unified admin's ChatRoute.
 *
 * The unified admin (src/unified-admin/routes/chat.js) calls
 * window.sdAiAgentChat.mount(container) to embed the full chat UI
 * (sidebar + chat panel) inside the #sdaa-chat-container div that
 * ChatRoute renders. This avoids the old pattern of both the unified admin
 * and the admin-page bundle competing to mount into #sdaa-root.
 */
window.sdAiAgentChat = {
	/**
	 * Mount the admin page app into the given container.
	 *
	 * @param {HTMLElement}          container Target DOM element.
	 * @param {{sessionId?: number}} options   Optional mount options.
	 */
	mount( container, options = {} ) {
		if ( ! container ) {
			return;
		}
		// Store the root so unmount() can tear it down cleanly.
		container.__sdAiRoot = mountAdminPageApp( container, options );
	},

	/**
	 * Unmount the admin page app from the given container.
	 *
	 * @param {HTMLElement} container - Target DOM element.
	 */
	unmount( container ) {
		if ( container && container.__sdAiRoot ) {
			container.__sdAiRoot.unmount();
			delete container.__sdAiRoot;
		}
	},
};

// Notify ChatRoute that the mount API is now available. ChatRoute listens for
// this event and calls mount() immediately, replacing the previous 0–50 ms
// polling interval with a near-zero-latency handshake.
window.dispatchEvent( new CustomEvent( 'sd-ai-agent-chat-ready' ) );
