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
 * Onboarding bootstrap component — shown after a connector is configured
 * for the first time on an install that already has published content
 * (established-site branch).
 *
 * Calls POST /onboarding/bootstrap-start to:
 *  1. Mark onboarding as complete on the server.
 *  2. Auto-detect WooCommerce and queue RAG indexing.
 *  3. Create a new session and resolve the unified Setup Assistant agent_id.
 *
 * Once the session is ready the component selects the Setup Assistant agent
 * and auto-sends a kickoff message. As of t276 the agent's stored system
 * prompt runs Phase 0 silent discovery before the first reply, then takes
 * the established-site branch (2–4 sentence inferred summary + suggestion
 * chips). No parallel onboarding prompt exists.
 *
 * @return {JSX.Element} The onboarding bootstrap element.
 */
export default function OnboardingBootstrap() {
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
			path: '/sd-ai-agent/v1/onboarding/bootstrap-start',
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

				// Activate the bootstrap session in the store.
				openSession( data.session_id )
					.then( () =>
						sendMessage(
							data.kickoff_message ||
								__(
									"Hi! I just set up this plugin and I'm ready to get started.",
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
