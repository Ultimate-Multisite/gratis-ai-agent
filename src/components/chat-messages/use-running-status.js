/**
 * Ordered fallback status rotation for agent work without a concrete tool step.
 */

import { useEffect, useState } from '@wordpress/element';

import { getRunningStatusMessage } from './message-presentation';

export const RUNNING_STATUS_ROTATION_INTERVAL = 3000;

/**
 * Rotate fallback work-status copy while an agent request is active.
 *
 * @param {boolean} isRunning Whether a request is running without tool status.
 * @return {string} Current user-facing status.
 */
export default function useRunningStatus( isRunning ) {
	const [ statusIndex, setStatusIndex ] = useState( 0 );

	useEffect( () => {
		setStatusIndex( 0 );
		if ( ! isRunning ) {
			return undefined;
		}

		const interval = setInterval( () => {
			setStatusIndex( ( index ) => index + 1 );
		}, RUNNING_STATUS_ROTATION_INTERVAL );

		return () => clearInterval( interval );
	}, [ isRunning ] );

	return getRunningStatusMessage( statusIndex );
}
