/**
 * WordPress dependencies
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import STORE_NAME from '../store';
import ChatRedesign from './chat-redesign';
import OnboardingPhotoUpload from './onboarding-photo-upload';

/**
 * Onboarding theme-builder component — shown when the user chose
 * "Design a custom theme" in the onboarding mode picker.
 *
 * Calls POST /onboarding/theme-builder-start to:
 *  1. Create a new session and resolve the Theme Builder agent_id.
 *  2. Return an `is_fresh_start` boolean indicating whether the server just
 *     created the session (true) or returned an existing one for resume
 *     (false). This is the authoritative signal — the `started_at`
 *     timestamp is also returned but MUST NOT be used to drive kickoff
 *     because both branches return a truthy timestamp (see #1522).
 *
 * On first call (is_fresh_start=true), the component selects the Theme Builder
 * agent and auto-sends a kickoff message. On subsequent calls (is_fresh_start
 * =false), the component skips the kickoff to prevent duplicate messages on
 * reload.
 *
 * The agent's stored system prompt drives the design-theme conversational flow
 * — no parallel onboarding prompt exists.
 *
 * @return {JSX.Element} The onboarding theme-builder element.
 */
export default function OnboardingThemeBuilder() {
	const { openSession, sendMessage, setSelectedAgentId } =
		useDispatch( STORE_NAME );
	const bootstrappedRef = useRef( false );
	const [ sessionId, setSessionId ] = useState( null );

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

				setSessionId( data.session_id );

				// Select the Theme Builder agent so streamMessage attaches
				// agent_id to the /run call and AgentLoop applies the agent's
				// system prompt + tool tier overrides for this session.
				if ( data.agent_id ) {
					setSelectedAgentId( data.agent_id );
				}

				// Activate the theme-builder session in the store.
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
										"Hello! I'm ready to help you design a custom theme for your WordPress site. Let's start by discussing your vision — what style, colours, and feel are you aiming for?",
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
			<OnboardingPhotoUpload sessionId={ sessionId } />
			<ChatRedesign />
		</div>
	);
}
