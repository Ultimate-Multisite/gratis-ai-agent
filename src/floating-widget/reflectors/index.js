/**
 * Frontend live-preview reflector dispatcher.
 *
 * Subscribes to the reflection bus and sends each affected tool result to the
 * best available DOM refresh strategy. Unknown kinds intentionally fall back to
 * an honest reload prompt so the user is not left looking at stale content.
 */

import bus from '../../store/reflection-bus';
import { showFallbackToast } from './fallback-toast';
import { reflectMenu } from './menu';
import { reflectPost } from './post';

bus.on( ( event ) => {
	if ( event.type !== 'tool-applied' || ! event.affected?.kind ) {
		return;
	}

	switch ( event.affected.kind ) {
		case 'post':
			reflectPost( event );
			break;
		case 'menu':
			reflectMenu( event );
			break;
		default:
			showFallbackToast( event );
			break;
	}
} );
