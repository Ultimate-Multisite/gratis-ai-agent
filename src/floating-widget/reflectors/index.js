/**
 * Frontend live-preview reflector dispatcher.
 *
 * Subscribes to the reflection bus and sends each affected tool result to the
 * best available DOM refresh strategy. Unknown kinds intentionally fall back to
 * an honest reload prompt so the user is not left looking at stale content.
 */

import bus from '../../store/reflection-bus';
import { showFallbackToast } from './fallback-toast';

const reflectorLoaders = {
	post: () =>
		import( /* webpackChunkName: "reflector-post" */ './post' ).then(
			( module ) => module.reflectPost
		),
	global_styles: () =>
		import(
			/* webpackChunkName: "reflector-global-styles" */ './global-styles'
		).then( ( module ) => module.reflectGlobalStyles ),
	menu: () =>
		import( /* webpackChunkName: "reflector-menu" */ './menu' ).then(
			( module ) => module.reflectMenu
		),
};

/**
 * Dispatch a reflection event without loading unused DOM strategies up front.
 *
 * @param {Object} event Reflection bus event.
 * @return {Promise<void>|void} Resolves after the selected reflector runs.
 */
export function dispatchReflectionEvent( event ) {
	if ( event.type !== 'tool-applied' || ! event.affected?.kind ) {
		return;
	}

	const loadReflector = reflectorLoaders[ event.affected.kind ];
	if ( ! loadReflector ) {
		showFallbackToast( event );
		return;
	}

	return loadReflector()
		.then( ( reflect ) => reflect( event ) )
		.catch( () => showFallbackToast( event ) );
}

bus.on( dispatchReflectionEvent );
