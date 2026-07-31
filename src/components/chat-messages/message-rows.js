/**
 * Shared visible-message sequence for the main and floating chat surfaces.
 */

import MessageRow from './message-row';

/**
 * Render a sequence of already-filtered message records.
 *
 * @param {Object}   root0
 * @param {Array}    root0.items              Visible messages with source indexes.
 * @param {boolean}  root0.sending            Whether generation is active.
 * @param {Array}    root0.providers          Provider records.
 * @param {Function} root0.onSuggestionSelect Suggested-prompt callback.
 * @param {Function} root0.onThumbsDown       Feedback callback.
 * @return {JSX.Element} Shared message sequence.
 */
export default function MessageRows( {
	items,
	sending,
	providers,
	onSuggestionSelect,
	onThumbsDown,
} ) {
	return (
		<>
			{ items.map( ( { msg, index }, position ) => (
				<MessageRow
					key={ index }
					msg={ msg }
					index={ index }
					isLast={ position === items.length - 1 }
					sending={ sending }
					providers={ providers }
					onSuggestionSelect={ onSuggestionSelect }
					onThumbsDown={ onThumbsDown }
				/>
			) ) }
		</>
	);
}
