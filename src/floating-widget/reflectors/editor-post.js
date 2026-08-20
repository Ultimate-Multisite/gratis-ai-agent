/**
 * Gutenberg editor post-content reflector.
 *
 * Synchronizes persisted server-side post mutations into the currently open,
 * clean editor without replaying tool arguments or triggering a save.
 */

import apiFetch from '@wordpress/api-fetch';

const reflectionQueues = new Map();
const REFLECTION_REQUEST_TIMEOUT_MS = 15_000;

/**
 * Compare post identifiers without making numeric/string REST values diverge.
 *
 * @param {number|string} first  First post ID.
 * @param {number|string} second Second post ID.
 * @return {boolean} Whether both values identify the same post.
 */
function samePost( first, second ) {
	return String( first ) === String( second );
}

/**
 * Return the active editor APIs when they can safely synchronize a post.
 *
 * @param {number|string} postId Affected post ID.
 * @return {Object|null} Current editor APIs and post type, or null.
 */
function getEditorContext( postId ) {
	if (
		typeof wp === 'undefined' ||
		! wp.data?.select ||
		! wp.data?.dispatch
	) {
		return null;
	}

	try {
		const editor = wp.data.select( 'core/editor' );
		const core = wp.data.select( 'core' );
		const coreData = wp.data.dispatch( 'core' );
		const editorDispatcher = wp.data.dispatch( 'core/editor' );
		const currentPostId = editor?.getCurrentPostId?.();
		const postType = editor?.getCurrentPostType?.();
		const postTypeRecord = core?.getPostType?.( postType );

		if (
			! editor ||
			! samePost( currentPostId, postId ) ||
			editor.isEditedPostDirty?.() !== false ||
			typeof postType !== 'string' ||
			! postType ||
			typeof coreData?.receiveEntityRecords !== 'function' ||
			typeof editorDispatcher?.resetEditorBlocks !== 'function' ||
			typeof wp.blocks?.parse !== 'function'
		) {
			return null;
		}

		return {
			editor,
			postType,
			restBase: postTypeRecord?.rest_base || postType,
			restNamespace: postTypeRecord?.rest_namespace || 'wp/v2',
			coreData,
			editorDispatcher,
		};
	} catch ( _error ) {
		return null;
	}
}

/**
 * Synchronize a server-side post-content mutation into a clean open editor.
 *
 * @param {{affected?: {fields?: string[], post_id?: number|string, render_mode?: string}, result?: {preview?: Object}}} event Reflection event.
 * @return {Promise<void>} Resolves after safely applying or skipping the update.
 */
async function reflectEditorPostEvent( event ) {
	const affected = event?.affected;
	if (
		! Array.isArray( affected?.fields ) ||
		! affected.fields.includes( 'post_content' ) ||
		affected.render_mode === 'preview' ||
		event?.result?.preview ||
		affected.post_id === undefined ||
		affected.post_id === null
	) {
		return;
	}

	const context = getEditorContext( affected.post_id );
	if ( ! context ) {
		return;
	}

	let record;
	try {
		const controller = new AbortController();
		const timeout = setTimeout(
			() => controller.abort(),
			REFLECTION_REQUEST_TIMEOUT_MS
		);
		try {
			record = await apiFetch( {
				path: `/${ context.restNamespace }/${ encodeURIComponent(
					context.restBase
				) }/${ encodeURIComponent( affected.post_id ) }?context=edit`,
				signal: controller.signal,
			} );
		} finally {
			clearTimeout( timeout );
		}
	} catch ( _error ) {
		// A missing REST route is safe to ignore: the persisted revision remains
		// available after a reload.
		return;
	}

	try {
		const rawContent = record?.content?.raw;
		if ( typeof rawContent !== 'string' ) {
			return;
		}

		const blocks = wp.blocks.parse( rawContent );
		if ( ! Array.isArray( blocks ) ) {
			return;
		}

		// Re-read immediately before writing so a local edit or navigation that
		// occurred during the fetch is never overwritten.
		const current = getEditorContext( affected.post_id );
		if ( ! current || current.editor.isEditedPostDirty() ) {
			return;
		}

		current.coreData.receiveEntityRecords( 'postType', current.postType, [
			record,
		] );
		current.editorDispatcher.resetEditorBlocks( blocks, {
			__unstableShouldCreateUndoLevel: false,
		} );
	} catch ( _error ) {
		// A parsing failure or unavailable editor is safe to ignore: the persisted
		// revision remains available after a reload.
	}
}

/**
 * Serialize server reflections for one post so an older REST response cannot
 * replace a newer revision after overlapping tool events.
 *
 * @param {Object} event Reflection event.
 * @return {Promise<void>} Resolves after safely applying or skipping the update.
 */
export function reflectEditorPost( event ) {
	const postId = event?.affected?.post_id;
	if ( postId === undefined || postId === null ) {
		return Promise.resolve();
	}

	const queueKey = String( postId );
	const previous = reflectionQueues.get( queueKey );
	const reflection = previous
		? previous
				.catch( () => undefined )
				.then( () => reflectEditorPostEvent( event ) )
		: reflectEditorPostEvent( event );

	reflectionQueues.set( queueKey, reflection );

	return reflection.finally( () => {
		if ( reflectionQueues.get( queueKey ) === reflection ) {
			reflectionQueues.delete( queueKey );
		}
	} );
}
