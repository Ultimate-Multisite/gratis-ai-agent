/**
 * WordPress dependencies
 */
import { useEffect } from '@wordpress/element';

import useVoiceConversation from '../use-voice-conversation';

/**
 * Load the managed voice coordinator outside the budgeted widget panel chunk.
 *
 * @param {Object}   props          Component properties.
 * @param {Function} props.onChange Receive the current coordinator state.
 * @return {null} This controller has no presentation.
 */
export default function WidgetVoiceController( { onChange } ) {
	const voice = useVoiceConversation( { surface: 'widget' } );

	useEffect( () => {
		onChange( voice );
	}, [ onChange, voice ] );

	return null;
}
