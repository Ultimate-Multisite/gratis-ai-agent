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
 * The browser fetches the panel chunk only after the user opens the widget,
 * avoiding speculative work on pages where the launcher is never used.
 */

import { lazy, Suspense } from '@wordpress/element';
import { useSelect } from '@wordpress/data';

import STORE_NAME from '../../store';
import { getChatUiMode } from '../../utils/chat-ui-mode';
import WidgetLauncher from './widget-launcher';
// Only the launcher (FAB) styles are required in the initial bundle.
// Panel and chat-redesign styles are imported inside widget-panel.js.
import './widget-launcher.css';

const WidgetPanel = lazy( () =>
	import( /* webpackChunkName: "widget-panel" */ './widget-panel' )
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
	// On a repeat visit this is normally served from the browser cache.
	return (
		<Suspense fallback={ null }>
			<WidgetPanel
				frontendOnboardingMode={ frontendOnboardingMode }
				uiMode={ uiMode }
			/>
		</Suspense>
	);
}
