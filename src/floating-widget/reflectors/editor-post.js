/**
 * Gutenberg editor post-content reflector.
 *
 * Synchronizes persisted server-side post mutations into the currently open,
 * clean editor without replaying tool arguments or triggering a save.
 */

import apiFetch from '@wordpress/api-fetch';

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
		const blockEditor = wp.data.select( 'core/block-editor' );
		const coreData = wp.data.dispatch( 'core' );
		const blockDispatcher = wp.data.dispatch( 'core/block-editor' );
		const currentPostId = editor?.getCurrentPostId?.();
		const postType = editor?.getCurrentPostType?.();

		if (
			! editor ||
			! blockEditor ||
			! samePost( currentPostId, postId ) ||
			editor.isEditedPostDirty?.() !== false ||
			typeof postType !== 'string' ||
			! postType ||
			typeof coreData?.receiveEntityRecords !== 'function' ||
			typeof blockDispatcher?.resetBlocks !== 'function' ||
			typeof wp.blocks?.parse !== 'function'
		) {
			return null;
		}

		return { editor, postType, coreData, blockDispatcher };
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
export async function reflectEditorPost( event ) {
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

	try {
		const record = await apiFetch( {
			path: `/wp/v2/${ encodeURIComponent(
				context.postType
			) }/${ encodeURIComponent( affected.post_id ) }?context=edit`,
		} );
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
		current.blockDispatcher.resetBlocks( blocks );
	} catch ( _error ) {
		// A missing REST route, parsing failure, or unavailable editor is safe to
		// ignore: the persisted revision remains available after a reload.
	}
}
