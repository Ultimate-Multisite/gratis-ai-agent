/**
 * Shared role and system-notice renderer for every React chat surface.
 */

import {
	AssistantMessage,
	SystemMessage,
	UserMessage,
} from '../chat-redesign/message-items';
import AccountActionMessage from './account-action-message';
import { resolveSystemMessagePresentation } from './message-presentation';

/**
 * Render one visible chat message using the shared semantic presentation rules.
 *
 * @param {Object}   root0
 * @param {Object}   root0.msg                Message record.
 * @param {number}   root0.index              Source message index.
 * @param {boolean}  root0.isLast             Whether this is the final visible row.
 * @param {boolean}  root0.sending            Whether generation is active.
 * @param {Array}    root0.providers          Provider records.
 * @param {Function} root0.onSuggestionSelect Suggested-prompt callback.
 * @param {Function} root0.onThumbsDown       Feedback callback.
 * @return {JSX.Element|null} Shared message row.
 */
export default function MessageRow( {
	msg,
	index,
	isLast,
	sending,
	providers,
	onSuggestionSelect,
	onThumbsDown,
} ) {
	if ( msg.role === 'user' ) {
		return <UserMessage msg={ msg } index={ index } />;
	}

	if ( msg.role === 'model' ) {
		return (
			<AssistantMessage
				msg={ msg }
				index={ index }
				onSuggestionSelect={ onSuggestionSelect }
				onThumbsDown={ onThumbsDown }
				isLastModel={ isLast && ! sending }
			/>
		);
	}

	if ( msg.role === 'system' ) {
		const presentation = resolveSystemMessagePresentation( msg, providers );
		if ( presentation.type === 'account_action' ) {
			return <AccountActionMessage notice={ presentation.notice } />;
		}
		return <SystemMessage text={ presentation.text } />;
	}

	return null;
}
