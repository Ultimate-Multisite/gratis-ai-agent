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
 * for the first time.
 *
 * Calls POST /onboarding/bootstrap-start to:
 *  1. Mark onboarding as complete on the server.
 *  2. Auto-detect WooCommerce and queue RAG indexing.
 *  3. Create a new session and resolve the Setup Assistant agent_id.
 *
 * Once the session is ready the component selects the Setup Assistant agent
 * and auto-sends a kickoff message. The agent's stored system prompt drives
 * the conversational discovery flow — no parallel onboarding prompt exists.
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
				openSession( data.session_id ).then( () => {
					sendMessage(
						data.kickoff_message ||
							__(
								"Hello! I'm just getting set up. Please explore this WordPress site and introduce yourself — tell me what you notice and what you can help with.",
								'superdav-ai-agent'
							)
					);
				} );
			} )
			.catch( () => {
				// Non-fatal: the user can start chatting manually.
			} );
	}, [ openSession, sendMessage, setSelectedAgentId ] );

	return <ChatRedesign />;
}
