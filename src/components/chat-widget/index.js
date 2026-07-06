/**
 * Floating chat widget top-level — renders either the launcher (FAB)
 * when closed, or the redesigned widget panel when open. State for
 * open/minimized comes from the shared store so every surface
 * (keyboard shortcut, close button, legacy code paths) stays in sync.
 *
 * Bundle strategy: WidgetPanel (and every component it imports —
 * ChangesDrawer, WidgetInput, ModelPicker, AgentPicker,
 * WidgetMessageList, ToolConfirmationDialog, SlashCommandMenu, …) lives
 * in a separate async chunk.  The browser downloads that chunk only the
 * first time the user opens the widget.
 *
 * webpackPrefetch causes the browser to fetch the chunk in the background
 * during idle time after the main page loads, so when the user clicks the
 * FAB the chunk is already cached — no visible delay on first open.
 */

import { lazy, Suspense } from '@wordpress/element';
import { useSelect } from '@wordpress/data';

import STORE_NAME from '../../store';
import { getChatUiMode } from '../../utils/chat-ui-mode';
import WidgetLauncher from './widget-launcher';
// widget.css contains the launcher (FAB) styles and is required in the
// initial bundle so the FAB is styled immediately on every page load.
// chat-redesign.css is imported inside widget-panel.js so it lands in
// the async chunk and is only fetched when the panel first opens.
import './widget.css';

const WidgetPanel = lazy( () =>
	import(
		/* webpackChunkName: "widget-panel", webpackPrefetch: true */
		'./widget-panel'
	)
);

/**
 * @param {Object}      root0                        Component props.
 * @param {string|null} root0.frontendOnboardingMode Frontend onboarding layout mode.
 */
export default function ChatWidget( { frontendOnboardingMode = null } ) {
	const uiMode = getChatUiMode();
	const isOpen = useSelect(
		( sel ) => sel( STORE_NAME ).isFloatingOpen(),
		[]
	);

	if ( ! isOpen ) {
		return <WidgetLauncher />;
	}

	// Suspense renders nothing while the panel chunk is downloading.
	// On a cache hit (prefetch or repeat visit) this is imperceptible.
	return (
		<Suspense fallback={ null }>
			<WidgetPanel
				frontendOnboardingMode={ frontendOnboardingMode }
				uiMode={ uiMode }
			/>
		</Suspense>
	);
}
