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

import { useEffect, useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

import STORE_NAME from '../../store';
import { getChatUiMode } from '../../utils/chat-ui-mode';
import WidgetLauncher from './widget-launcher';
// Only the launcher (FAB) styles are required in the initial bundle.
// Panel and chat-redesign styles are imported inside widget-panel.js.
import './widget-launcher.css';

/**
 * Load the panel without letting a stale deployment chunk replace the whole
 * widget with an unrecoverable error boundary.
 *
 * @param {Object}      root0                        Component props.
 * @param {string|null} root0.frontendOnboardingMode Frontend onboarding layout mode.
 * @param {string}      root0.uiMode                 Chat UI mode.
 * @return {JSX.Element|null} Loaded panel, retry launcher, or loading fallback.
 */
function DeferredWidgetPanel( { frontendOnboardingMode, uiMode } ) {
	const [ WidgetPanel, setWidgetPanel ] = useState( null );
	const [ failed, setFailed ] = useState( false );
	const [ attempt, setAttempt ] = useState( 0 );

	useEffect( () => {
		let active = true;
		setFailed( false );

		import( /* webpackChunkName: "widget-panel" */ './widget-panel' )
			.then( ( module ) => {
				if ( active ) {
					setWidgetPanel( () => module.default );
				}
			} )
			.catch( () => {
				if ( active ) {
					setFailed( true );
				}
			} );

		return () => {
			active = false;
		};
	}, [ attempt ] );

	if ( failed ) {
		return (
			<WidgetLauncher
				label={ __( 'Retry opening AI Agent', 'superdav-ai-agent' ) }
				onActivate={ () => setAttempt( ( current ) => current + 1 ) }
			/>
		);
	}

	if ( ! WidgetPanel ) {
		return null;
	}

	return (
		<WidgetPanel
			frontendOnboardingMode={ frontendOnboardingMode }
			uiMode={ uiMode }
		/>
	);
}

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

	return (
		<DeferredWidgetPanel
			frontendOnboardingMode={ frontendOnboardingMode }
			uiMode={ uiMode }
		/>
	);
}
