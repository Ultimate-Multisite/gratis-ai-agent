import apiFetch from '@wordpress/api-fetch';
import { reflectEditorPost } from '../editor-post';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

/**
 * Create a minimal clean or dirty Gutenberg editor state.
 *
 * @param {Object} options Editor options.
 * @return {Object} Editor test state and dispatchers.
 */
function setEditor( options = {} ) {
	const { postId = 42, dirty = false, postType = 'page' } = options;
	const state = { dirty };
	const editor = {
		getCurrentPostId: jest.fn( () => postId ),
		getCurrentPostType: jest.fn( () => postType ),
		isEditedPostDirty: jest.fn( () => state.dirty ),
	};
	const coreData = { receiveEntityRecords: jest.fn() };
	const blockDispatcher = { resetBlocks: jest.fn() };
	global.wp = {
		data: {
			select: jest.fn( ( store ) =>
				store === 'core/editor' ? editor : {}
			),
			dispatch: jest.fn( ( store ) => {
				if ( store === 'core' ) {
					return coreData;
				}
				return blockDispatcher;
			} ),
		},
		blocks: { parse: jest.fn( () => [ { clientId: 'fresh' } ] ) },
	};
	return { state, editor, coreData, blockDispatcher };
}

/**
 * Build a successful server-side post mutation event.
 *
 * @param {Object} overrides Event properties to override.
 * @return {Object} Reflection event.
 */
function postEvent( overrides = {} ) {
	return {
		type: 'tool-applied',
		affected: { kind: 'post', post_id: 42, fields: [ 'post_content' ] },
		...overrides,
	};
}

describe( 'editor post reflector', () => {
	afterEach( () => {
		apiFetch.mockReset();
		delete global.wp;
	} );

	test( 'synchronizes fetched server content into a matching clean editor', async () => {
		const { coreData, blockDispatcher } = setEditor();
		const record = {
			id: 42,
			content: {
				raw: '<!-- wp:paragraph -->Fresh<!-- /wp:paragraph -->',
			},
		};
		apiFetch.mockResolvedValue( record );

		await reflectEditorPost( postEvent() );

		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/wp/v2/page/42?context=edit',
		} );
		expect( coreData.receiveEntityRecords ).toHaveBeenCalledWith(
			'postType',
			'page',
			[ record ]
		);
		expect( blockDispatcher.resetBlocks ).toHaveBeenCalledWith( [
			{ clientId: 'fresh' },
		] );
	} );

	test.each( [
		[
			'a mismatched post',
			postEvent( {
				affected: { post_id: 7, fields: [ 'post_content' ] },
			} ),
		],
		[
			'a preview mutation',
			postEvent( {
				affected: {
					post_id: 42,
					fields: [ 'post_content' ],
					render_mode: 'preview',
				},
			} ),
		],
		[
			'a mutation without post content',
			postEvent( {
				affected: { post_id: 42, fields: [ 'post_title' ] },
			} ),
		],
	] )( 'skips %s', async ( _name, event ) => {
		const { blockDispatcher } = setEditor();

		await reflectEditorPost( event );

		expect( apiFetch ).not.toHaveBeenCalled();
		expect( blockDispatcher.resetBlocks ).not.toHaveBeenCalled();
	} );

	test( 'does not fetch or replace a dirty editor', async () => {
		const { blockDispatcher } = setEditor( { dirty: true } );

		await reflectEditorPost( postEvent() );

		expect( apiFetch ).not.toHaveBeenCalled();
		expect( blockDispatcher.resetBlocks ).not.toHaveBeenCalled();
	} );

	test( 'preserves local edits made while fetching', async () => {
		const { state, coreData, blockDispatcher } = setEditor();
		let resolveFetch;
		apiFetch.mockReturnValue(
			new Promise( ( resolve ) => {
				resolveFetch = resolve;
			} )
		);

		const reflection = reflectEditorPost( postEvent() );
		state.dirty = true;
		resolveFetch( {
			id: 42,
			content: {
				raw: '<!-- wp:paragraph -->Fresh<!-- /wp:paragraph -->',
			},
		} );
		await reflection;

		expect( coreData.receiveEntityRecords ).not.toHaveBeenCalled();
		expect( blockDispatcher.resetBlocks ).not.toHaveBeenCalled();
	} );

	test.each( [
		[ 'malformed REST content', { id: 42, content: {} } ],
		[ 'a fetch failure', new Error( 'offline' ) ],
	] )( 'fails safely for %s', async ( _name, response ) => {
		const { blockDispatcher } = setEditor();
		if ( response instanceof Error ) {
			apiFetch.mockRejectedValue( response );
		} else {
			apiFetch.mockResolvedValue( response );
		}

		await reflectEditorPost( postEvent() );

		expect( blockDispatcher.resetBlocks ).not.toHaveBeenCalled();
	} );

	test( 'fails safely when Gutenberg APIs are unavailable or parsing fails', async () => {
		setEditor();
		delete global.wp.blocks;

		await reflectEditorPost( postEvent() );

		expect( apiFetch ).not.toHaveBeenCalled();
	} );
} );
