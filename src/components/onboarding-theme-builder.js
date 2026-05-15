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

/**
 * Onboarding theme-builder component — shown when the user chose
 * "Design a custom theme" in the onboarding mode picker.
 *
 * Calls POST /onboarding/theme-builder-start to:
 *  1. Mark onboarding as complete on the server.
 *  2. Create a new session and resolve the Theme Builder agent_id.
 *
 * Once the session is ready the component selects the Theme Builder agent
 * and auto-sends a kickoff message. The agent's stored system prompt drives
 * the design-theme conversational flow — no parallel onboarding prompt exists.
 *
 * @return {JSX.Element} The onboarding theme-builder element.
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

				// Select the Theme Builder agent so streamMessage attaches
				// agent_id to the /run call and AgentLoop applies the agent's
				// system prompt + tool tier overrides for this session.
				if ( data.agent_id ) {
					setSelectedAgentId( data.agent_id );
				}

				// Activate the theme-builder session in the store.
				openSession( data.session_id )
					.then( () =>
						sendMessage(
							data.kickoff_message ||
								__(
									"Hello! I'm ready to help you design a custom theme for your WordPress site. Let's start by discussing your vision — what style, colours, and feel are you aiming for?",
									'superdav-ai-agent'
								)
						)
					)
					.catch( () => {
						// Non-fatal: user can continue manually from chat UI.
					} );
			} )
			.catch( () => {
				// Non-fatal: the user can start chatting manually.
			} );
	}, [ openSession, sendMessage, setSelectedAgentId ] );

	return <ChatRedesign />;
}
