/**
 * WordPress dependencies
 */
import { useEffect, useRef } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import STORE_NAME from '../store';
import ChatRedesign from './chat-redesign';

import './onboarding-theme-builder.css';

/**
 * Onboarding component for the empty-install branch — shown when a connector
 * is configured for the first time and the site has no real published content
 * yet (default seed posts only).
 *
 * Calls POST /onboarding/theme-builder-start to:
 *  1. Create a new session and resolve the unified Setup Assistant agent_id
 *     (the route name is retained for compatibility, but the separate legacy
 *     Theme Builder agent row has been retired).
 *  2. Return an `is_fresh_start` boolean indicating whether the server just
 *     created the session (true) or returned an existing one for resume
 *     (false). This is the authoritative signal — the `started_at`
 *     timestamp is also returned but MUST NOT be used to drive kickoff
 *     because both branches return a truthy timestamp (see #1522).
 *
 * On first call (is_fresh_start=true), the component selects the resolved
 * agent and auto-sends a kickoff message that matches Phase 1 of the unified
 * Setup Assistant prompt (one warm capture turn). On subsequent calls
 * (is_fresh_start=false), the component skips the kickoff to prevent duplicate
 * messages on reload.
 *
 * The agent's stored system prompt drives the conversational flow — no
 * parallel onboarding prompt exists.
 *
 * @return {JSX.Element} The onboarding empty-install element.
 */
export default function OnboardingThemeBuilder() {
	const { openSession, sendMessage, setSelectedAgentId } =
		useDispatch( STORE_NAME );
	const bootstrappedRef = useRef( false );

	useEffect( () => {
		// Guard against double-invocation in React 18 strict-mode or re-renders.
		if ( bootstrappedRef.current ) {
			return;
		}
		bootstrappedRef.current = true;

		apiFetch( {
			path: '/sd-ai-agent/v1/onboarding/theme-builder-start',
			method: 'POST',
		} )
			.then( ( data ) => {
				if ( ! data?.session_id ) {
					// Fallback: if the endpoint doesn't return a session, the
					// ChatRedesign will allow the user to start chatting manually.
					return;
				}

				// Select the Setup Assistant agent so streamMessage attaches
				// agent_id to the /run call and AgentLoop applies the agent's
				// system prompt + tool tier overrides for this session.
				if ( data.agent_id ) {
					setSelectedAgentId( data.agent_id );
				}

				// Activate the empty-install onboarding session in the store.
				openSession( data.session_id )
					.then( () => {
						// Only send the kickoff message on a genuine fresh start.
						// `is_fresh_start` is true only when the server just
						// created the session; on every resume it is false, so
						// reloads never re-fire the kickoff. See #1522 — the
						// pre-fix code keyed off `started_at`, which is truthy
						// on both branches, so kickoff never fired.
						if ( data.is_fresh_start ) {
							sendMessage(
								data.kickoff_message ||
									__(
										"Hi! I'm ready when you are — tell me what you're building (a name and a one-line description is plenty) and I'll have a homepage live in a couple of minutes.",
										'superdav-ai-agent'
									)
							);
						}
					} )
					.catch( () => {
						// Non-fatal: user can continue manually from chat UI.
					} );
			} )
			.catch( () => {
				// Non-fatal: the user can start chatting manually.
			} );
	}, [ openSession, sendMessage, setSelectedAgentId ] );

	return (
		<div className="sdaa-onboarding-theme-builder">
			<ChatRedesign />
		</div>
	);
}
