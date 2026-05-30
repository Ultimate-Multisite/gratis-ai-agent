/**
 * Frontend live-preview reflector dispatcher.
 */

import bus from '../../store/reflection-bus';
import { reflectMenu } from './menu';

bus.on( ( event ) => {
	if ( event.type !== 'tool-applied' || ! event.affected ) {
		return;
	}

	switch ( event.affected.kind ) {
		case 'menu':
			reflectMenu( event );
			break;
		default:
			// Phase 2e fallback toast lands here.
			break;
	}
} );
