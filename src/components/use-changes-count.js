/**
 * Shared session-change count behavior for the main and floating chat shells.
 */

import { useCallback, useEffect, useState } from '@wordpress/element';
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

	const refreshChangesCount = useCallback( async () => {
		if ( ! enabled || ! sessionId ) {
			setChangesCount( 0 );
			return;
		}
		try {
			const data = await apiFetch( {
				path: `/sd-ai-agent/v1/changes?session_id=${ sessionId }&reverted=false&revertable=true&per_page=1`,
			} );
			setChangesCount( data?.total ?? ( data?.items?.length || 0 ) );
		} catch {
			setChangesCount( 0 );
		}
	}, [ enabled, sessionId ] );

	useEffect( () => {
		refreshChangesCount();
	}, [ refreshChangesCount ] );

	useEffect( () => {
		if ( ! sending && sessionId ) {
			refreshChangesCount();
		}
	}, [ sending, sessionId, refreshChangesCount ] );

	return { changesCount, setChangesCount, refreshChangesCount };
}
