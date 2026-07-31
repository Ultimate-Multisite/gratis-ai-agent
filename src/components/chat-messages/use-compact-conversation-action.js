/**
 * Shared compact-and-continue behavior for React chat surfaces.
 */

import { useCallback, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Manage compact-conversation execution and its inline failure state.
 *
 * @param {Object}   root0
 * @param {Function} root0.compactConversation  Store compaction action.
 * @param {Function} root0.setPendingActionCard Store action-card setter.
 * @return {{compactError: string, compactAndContinue: Function}} Action state.
 */
export default function useCompactConversationAction( {
	compactConversation,
	setPendingActionCard,
} ) {
	const [ compactError, setCompactError ] = useState( '' );

	const compactAndContinue = useCallback( async () => {
		setCompactError( '' );
		const compacted = await compactConversation();
		if ( compacted === true ) {
			setPendingActionCard( null );
			return;
		}

		setCompactError(
			compacted?.error ||
				__(
					'Unable to compact this conversation. Please try again.',
					'superdav-ai-agent'
				)
		);
	}, [ compactConversation, setPendingActionCard ] );

	return { compactError, compactAndContinue };
}
