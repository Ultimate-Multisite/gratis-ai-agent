/**
 * WordPress dependencies
 */
import { useEffect, useRef } from '@wordpress/element';

/**
 * Chat Route Component
 *
 * Mounts the AdminPageApp chat UI into a dedicated container.
 * The mount API (`window.sdAiAgentChat`) is exposed by the admin-page
 * bundle (build/admin-page.js), which is enqueued after unified-admin.js.
 * Because the two bundles load asynchronously, the API may not be defined
 * when this component first mounts. We poll with a short interval until the
 * API becomes available, then call mount() exactly once.
 *
 * The mount API must expose an `unmount()` method so React's cleanup
 * lifecycle is respected — never clear the container via `innerHTML = ''`,
 * which bypasses React's unmount hooks and leaks subscriptions and event
 * handlers.
 *
 * @param {Object}      props           Component props.
 * @param {number|null} props.sessionId Optional session to open after mount.
 * @return {JSX.Element} Chat route element.
 */
export default function ChatRoute( { sessionId = null } ) {
	const containerRef = useRef( null );
	// Track whether mount() has been called so we don't call it twice.
	const mountedRef = useRef( false );

	useEffect( () => {
		const container = containerRef.current;
		if ( ! container ) {
			return;
		}

		/**
		 * Attempt to mount the chat app. Returns true if mount succeeded.
		 *
		 * @return {boolean} Whether the mount API was available and called.
		 */
		function tryMount() {
			if (
				window.sdAiAgentChat &&
				typeof window.sdAiAgentChat.mount === 'function' &&
				! mountedRef.current
			) {
				mountedRef.current = true;
				window.sdAiAgentChat.mount( container, { sessionId } );
				return true;
			}
			return false;
		}

		// Try immediately — admin-page.js may already have executed if the
		// browser parsed both script tags before this effect ran.
		if ( tryMount() ) {
			return () => {
				if (
					mountedRef.current &&
					window.sdAiAgentChat &&
					typeof window.sdAiAgentChat.unmount === 'function'
				) {
					window.sdAiAgentChat.unmount( container );
				}
				mountedRef.current = false;
			};
		}

		// admin-page.js hasn't run yet. Listen for the 'sd-ai-agent-chat-ready'
		// CustomEvent that admin-page.js dispatches synchronously after setting
		// window.sdAiAgentChat. This fires within microseconds of the script
		// finishing, replacing the previous 0–50 ms polling interval.
		//
		// Falls back to a 50 ms poll as a safety net for environments where
		// the event fires before this listener is registered (e.g. the script
		// tag appeared synchronously before the React render cycle).
		const handleReady = () => tryMount();
		window.addEventListener( 'sd-ai-agent-chat-ready', handleReady );

		// Safety poll — catches any edge case where the event was missed.
		// 30 s matches the goToAgentPage() wait timeout in tests/e2e/utils/wp-admin.js.
		let intervalId = setInterval( () => {
			if ( tryMount() ) {
				clearInterval( intervalId );
				intervalId = null;
			}
		}, 50 );

		const timeoutId = setTimeout( () => {
			if ( intervalId ) {
				clearInterval( intervalId );
				intervalId = null;
				// eslint-disable-next-line no-console
				console.warn(
					'[Superdav AI Agent] ChatRoute: window.sdAiAgentChat.mount() not available after 30s. ' +
						'Ensure build/admin-page.js is enqueued.'
				);
			}
		}, 30_000 );

		return () => {
			window.removeEventListener( 'sd-ai-agent-chat-ready', handleReady );
			if ( intervalId ) {
				clearInterval( intervalId );
			}
			clearTimeout( timeoutId );
			if (
				mountedRef.current &&
				window.sdAiAgentChat &&
				typeof window.sdAiAgentChat.unmount === 'function'
			) {
				window.sdAiAgentChat.unmount( container );
				mountedRef.current = false;
			}
		};
	}, [ sessionId ] );

	return (
		<div className="sdaa-route sdaa-route-chat">
			<div
				ref={ containerRef }
				id="sdaa-chat-container"
				className="sdaa-chat-container"
			/>
		</div>
	);
}
