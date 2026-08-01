/**
 * Shared session-change count behavior for the main and floating chat shells.
 */

import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * Load and refresh the revertable change count for the active session.
 *
 * @param {Object}      root0
 * @param {number|null} root0.sessionId Active session ID.
 * @param {boolean}     root0.sending   Whether a turn is active.
 * @param {boolean}     root0.enabled   Whether this UI mode exposes changes.
 * @return {{changesCount: number, setChangesCount: Function, refreshChangesCount: Function}}
 *   Shared changes-count state.
 */
export default function useChangesCount( {
	sessionId,
	sending,
	enabled = true,
} ) {
	const [ changesCount, setChangesCount ] = useState( 0 );
	const requestIdRef = useRef( 0 );
	const activeSessionIdRef = useRef( sessionId );
	const enabledRef = useRef( enabled );
	const previousSessionIdRef = useRef( sessionId );
	const wasSendingRef = useRef( sending );
	activeSessionIdRef.current = sessionId;
	enabledRef.current = enabled;

	const refreshChangesCount = useCallback( async () => {
		const requestId = ++requestIdRef.current;
		const requestSessionId = sessionId;
		if ( ! enabled || ! requestSessionId ) {
			setChangesCount( 0 );
			return;
		}
		try {
			const data = await apiFetch( {
				path: `/sd-ai-agent/v1/changes?session_id=${ requestSessionId }&reverted=false&revertable=true&per_page=1`,
			} );
			if (
				requestId === requestIdRef.current &&
				requestSessionId === activeSessionIdRef.current &&
				enabledRef.current
			) {
				setChangesCount( data?.total ?? ( data?.items?.length || 0 ) );
			}
		} catch {
			if (
				requestId === requestIdRef.current &&
				requestSessionId === activeSessionIdRef.current &&
				enabledRef.current
			) {
				setChangesCount( 0 );
			}
		}
	}, [ enabled, sessionId ] );

	useEffect( () => {
		refreshChangesCount();
	}, [ refreshChangesCount ] );

	useEffect( () => {
		const sameSession = previousSessionIdRef.current === sessionId;
		if ( sameSession && wasSendingRef.current && ! sending ) {
			refreshChangesCount();
		}
		previousSessionIdRef.current = sessionId;
		wasSendingRef.current = sending;
	}, [ sessionId, sending, refreshChangesCount ] );

	return { changesCount, setChangesCount, refreshChangesCount };
}
