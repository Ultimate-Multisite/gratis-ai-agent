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
import apiFetch from '@wordpress/api-fetch';

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
//  - OnboardingBootstrap    → first install, site already has content
//                             (drops the user straight into the Setup Assistant agent)
//  - OnboardingThemeBuilder → first install, site has no content yet
//                             (drops the user straight into the Theme Builder agent)
//  - ShortcutsHelp          → user presses Mod+/ (explicitly intentional)
// None of them appear during a normal chat session, so they are lazy-loaded.
const ConnectorGate = lazy( () =>
	import(
		/* webpackChunkName: "connector-gate", webpackPrefetch: true */
		'../components/connector-gate'
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
 * Implements the Onboarding v2 flow (see todo/PLANS.md "Onboarding v2: Gate
 * + AI-Driven Discovery"). The legacy multi-step wizard is gone — the AI
 * agent drives discovery conversationally.
 *
 * 1. **Connector gate** — shown when no AI provider is configured. The user
 *    is directed to the WordPress Connectors page. The gate polls every 5 s
 *    so it disappears automatically once a provider becomes available.
 *
 * 2. **First-run agent** — shown when a provider exists but onboarding has
 *    not yet completed. A single heuristic picks the right bootstrapper:
 *      - site has published content  → OnboardingBootstrap (Setup Assistant
 *        agent: explores the existing site and introduces itself).
 *      - site has no content yet     → OnboardingThemeBuilder (Theme Builder
 *        agent: helps design and scaffold a custom block theme).
 *    Both bootstrappers POST to their server endpoint, which flips
 *    `onboarding_complete` to true and opens an agent session.
 *
 * 3. After onboarding completes the full redesigned chat layout is shown.
 *
 * @return {JSX.Element|null} Admin page app element, or null while settings are loading.
 */
function AdminPageApp() {
	/**
	 * Tracks whether the site has any "real" published content yet. Used to
	 * pick the default first-run agent: empty install → Theme Builder;
	 * otherwise → Setup Assistant. `null` means the heuristic probe is still
	 * in flight.
	 *
	 * Probe target: GET /wp/v2/posts?per_page=2&status=publish. The probe
	 * returns true when at least TWO published posts exist, so the WordPress
	 * default "Hello world!" seed post is treated as "no real content yet".
	 * Fired lazily — only when we actually need to show one of the
	 * bootstrappers — so existing installs pay no probe cost.
	 */
	const [ siteHasContent, setSiteHasContent ] = useState( null );

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

	// First-run content probe.
	//
	// When we are about to mount one of the onboarding bootstrappers, probe
	// /wp/v2/posts once to decide which agent to drop the user into. Fired
	// lazily — existing installs (onboarding already complete) never call it.
	//
	// Threshold is `> 1`, not `> 0`, because every fresh WordPress install
	// ships with one seeded "Hello world!" post. A `> 0` check would always
	// be true on a default install and the Theme Builder branch would be
	// unreachable. `> 1` treats the seed post as "no real content yet" and
	// only flips to Setup Assistant once the user has at least two
	// published posts. Edge case: a user who deletes the seed post and
	// writes exactly one real post will still be routed to Theme Builder
	// (they can switch agents from the chat picker if they prefer the
	// Setup Assistant). Tracked in the v1.16.1 follow-up issue alongside
	// the broader probe (it currently only counts `post`-type entries and
	// misses page-only / CPT-only / WooCommerce-only installs).
	const onboardingComplete = settings?.onboarding_complete !== false;
	useEffect( () => {
		if ( ! settingsLoaded || onboardingComplete ) {
			return;
		}
		if ( siteHasContent !== null ) {
			return;
		}
		apiFetch( { path: '/wp/v2/posts?per_page=2&status=publish' } )
			.then( ( posts ) => {
				setSiteHasContent( Array.isArray( posts ) && posts.length > 1 );
			} )
			.catch( () => {
				// Probe failed (e.g., REST blocked, network error): treat
				// the site as having content so we land on the Setup
				// Assistant (the safer default for an unknown state).
				setSiteHasContent( true );
			} );
	}, [ settingsLoaded, onboardingComplete, siteHasContent ] );

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
	//
	// Onboarding v2 — no wizard, no mode picker. We pick a bootstrapper
	// based on whether the site has any published content yet:
	//   - empty install → OnboardingThemeBuilder (Theme Builder agent)
	//   - has content   → OnboardingBootstrap    (Setup Assistant agent)
	// Both bootstrappers POST to a `*-start` endpoint that flips
	// `onboarding_complete` to true and opens an agent session.
	if ( ! onboardingComplete ) {
		// Heuristic probe still in flight — render nothing until we know
		// which bootstrapper to mount. The probe is one REST call (~50 ms
		// on a warm install).
		if ( siteHasContent === null ) {
			return null;
		}

		if ( siteHasContent === false ) {
			return (
				<Suspense fallback={ null }>
					<OnboardingThemeBuilder />
				</Suspense>
			);
		}

		return (
			<Suspense fallback={ null }>
				<OnboardingBootstrap />
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
