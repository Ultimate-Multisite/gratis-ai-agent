/**
 * Spike-only reflection debug consumer.
 *
 * Subscribes to the frontend live-preview reflection bus and logs every
 * `tool-applied` event to the browser console. This is a temporary
 * Phase 1 verification surface — once Phase 2 ships real reflectors
 * (DOM morphing for posts, CSS swap for global-styles, etc.) this file
 * should be removed.
 *
 * Side-effect module: importing it once is enough to start listening.
 * Safe to import on both admin and frontend bundles.
 *
 * Browser usage (no rebuild required for one-off inspection):
 *
 *   window.sdAiAgentReflection.on( ( e ) => console.log( e ) );
 */

import bus from '../store/reflection-bus';

bus.on( ( event ) => {
	// eslint-disable-next-line no-console
	console.info( `[sd-ai-agent] reflection ${ event.type }`, {
		tool: event.tool,
		sessionId: event.sessionId,
		jobId: event.jobId,
		affected: event.affected,
		args: event.args,
	} );
} );
