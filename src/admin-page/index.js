/**
 * WordPress dependencies
 */
import {
	createRoot,
	useEffect,
	useMemo,
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
import ChatRedesign from '../components/chat-redesign';
import BootError from '../components/boot-error';
import { useKeyboardShortcuts } from '../utils/keyboard-shortcuts';
import '../components/shared.css';
import './style.css';

// These components are rendered only in specific, uncommon states:
//  - ConnectorGate          → zero providers configured (first install)
//  - OnboardingWizard       → provider exists but onboarding not yet done (once per site)
//  - OnboardingBootstrap    → user chose "explore" in the mode picker
//  - OnboardingThemeBuilder → user chose "theme-builder" in the mode picker
//  - ShortcutsHelp          → user presses Mod+/ (explicitly intentional)
// None of them appear during a normal chat session, so they are lazy-loaded.
const ConnectorGate = lazy( () =>
	import(
		/* webpackChunkName: "connector-gate", webpackPrefetch: true */
		'../components/connector-gate'
	)
);
const OnboardingWizard = lazy( () =>
	import(
		/* webpackChunkName: "onboarding-wizard", webpackPrefetch: true */
		'../components/onboarding-wizard'
	)
);
const OnboardingBootstrap = lazy( () =>
	import(
		/* webpackChunkName: "onboarding-bootstrap", webpackPrefetch: true */
		'../components/onboarding-bootstrap'
	)
);
const OnboardingThemeBuilder = lazy( () =>
	import(
		/* webpackChunkName: "onboarding-theme-builder", webpackPrefetch: true */
		'../components/onboarding-theme-builder'
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
 * Implements a three-state onboarding flow:
 *
 * 1. **Connector gate** — shown when no AI provider is configured. The user
 *    is directed to the WordPress Connectors page. The gate polls every 5 s
 *    so it disappears automatically once a provider becomes available.
 *
 * 2. **Onboarding wizard** — shown when a provider exists but onboarding has
 *    not yet completed. Guides the user through provider selection and a
 *    mode picker:
 *    - 'explore'       → OnboardingBootstrap (existing site exploration)
 *    - 'theme-builder' → OnboardingThemeBuilder (custom theme design)
 *    - 'skip'          → ChatRedesign directly (wizard marks onboarding done)
 *
 * 3. After onboarding completes the full redesigned chat layout is shown.
 *
 * @return {JSX.Element|null} Admin page app element, or null while settings are loading.
 */
function AdminPageApp() {
	/**
	 * The onboarding mode chosen by the user in the wizard.
	 * null            = wizard not yet completed.
	 * 'explore'       = mount OnboardingBootstrap.
	 * 'theme-builder' = mount OnboardingThemeBuilder.
	 * 'skip'          = wizard already marked onboarding done; fall through.
	 */
	const [ onboardingMode, setOnboardingMode ] = useState( null );

	const {
		fetchProviders,
		fetchSessions,
		fetchSettings,
		clearCurrentSession,
		restoreActiveJobs,
		setShowShortcutsHelp,
	} = useDispatch( STORE_NAME );
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
	}, [ fetchProviders, fetchSessions, fetchSettings, restoreActiveJobs ] );

	// Poll for providers every 5 s while the connector gate is shown.
	// Stops once at least one provider appears.
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
	// Gating logic while providers are still loading:
	//   - Assume a provider exists (optimistic) so ChatRedesign renders.
	//   - The model picker already handles an empty providers array gracefully.
	//   - If providers finish loading with an empty list, we swap to ConnectorGate.
	//   - Onboarding state is derived from settings (already loaded), so that
	//     gate can still fire correctly without waiting for providers.
	if ( ! settingsLoaded ) {
		return null;
	}

	// Phase 1 gate: no connector → show connector gate.
	// While providers are still loading we skip this gate (assume configured).
	const hasProvider = ! providersLoaded || providers.length > 0;
	if ( ! hasProvider ) {
		return (
			<Suspense fallback={ null }>
				<ConnectorGate />
			</Suspense>
		);
	}

	// Phase 2 gate: connector exists but onboarding not yet complete.
	const onboardingComplete = settings?.onboarding_complete !== false;
	if ( ! onboardingComplete ) {
		// No mode chosen yet → show the onboarding wizard.
		if ( onboardingMode === null ) {
			return (
				<Suspense fallback={ null }>
					<OnboardingWizard onComplete={ setOnboardingMode } />
				</Suspense>
			);
		}

		// Mode chosen: mount the appropriate bootstrapper.
		if ( onboardingMode === 'theme-builder' ) {
			return (
				<Suspense fallback={ null }>
					<OnboardingThemeBuilder />
				</Suspense>
			);
		}

		if ( onboardingMode === 'explore' ) {
			return (
				<Suspense fallback={ null }>
					<OnboardingBootstrap />
				</Suspense>
			);
		}

		// 'skip' mode: the wizard saved onboarding_complete=true, so the
		// store settings will update and re-render with onboardingComplete=true.
		// Fall through to ChatRedesign as a synchronous safety net.
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
 * @param {HTMLElement} container - DOM element to mount into.
 * @return {import('@wordpress/element').Root} React root.
 */
function mountAdminPageApp( container ) {
	const root = createRoot( container );
	root.render( <AdminPageApp /> );
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
	 * @param {HTMLElement} container - Target DOM element.
	 */
	mount( container ) {
		if ( ! container ) {
			return;
		}
		// Store the root so unmount() can tear it down cleanly.
		container.__sdAiRoot = mountAdminPageApp( container );
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
