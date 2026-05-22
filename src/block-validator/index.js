/**
 * Block validator JS entry (GH#1584 Phase 1).
 *
 * Loaded by the hidden `sd-ai-agent-block-validator` admin page. Exposes
 * a single global API:
 *
 *   window.sdAiAgentValidateBlocks( blockMarkup: string ): Promise<Report>
 *
 * The function:
 *  1. Calls `wp.blocks.parse( blockMarkup )` to materialise an editor block
 *     tree (this fires every registered block's `parse` step, including
 *     third-party blocks).
 *  2. Walks the tree recursively. For each block it calls
 *     `wp.blocks.validateBlock( block, blockType )` — the real save-output
 *     comparison the editor uses on load.
 *  3. Computes `expectedContent` from
 *     `wp.blocks.getSaveContent( blockType, block.attributes, block.innerBlocks )`
 *     so invalid blocks return a concrete diff for the model.
 *  4. POSTs the resulting Studio-shaped report to
 *     `/sd-ai-agent/v1/blocks/validate-cache` so any subsequent PHP-side
 *     `BlockValidator::validate()` call (e.g. from the AI tool dispatcher)
 *     receives the live results instead of the PHP fallback.
 *
 * The entry also accepts `postMessage` calls so the page can be iframed
 * from the chat / unified-admin app:
 *
 *   iframe.contentWindow.postMessage(
 *     { type: 'sd-ai-agent:validate', requestId, content },
 *     window.location.origin
 *   );
 *
 * It replies with:
 *
 *   { type: 'sd-ai-agent:validate-result', requestId, report }
 *
 * @package
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1584
 */

const STATUS_NODE_ID = 'sd-ai-agent-block-validator-status';
const MESSAGE_TYPE_REQUEST = 'sd-ai-agent:validate';
const MESSAGE_TYPE_RESULT = 'sd-ai-agent:validate-result';

/**
 * Read the WordPress runtime helper.
 *
 * @return {object|null} The wp.blocks namespace, or null if blocks have not
 *                       been registered yet.
 */
function getBlocksApi() {
	if ( typeof window === 'undefined' || ! window.wp || ! window.wp.blocks ) {
		return null;
	}
	return window.wp.blocks;
}

/**
 * Update the status node on the validator page so the user sees that the
 * validator is ready (helpful when debugging the iframe).
 *
 * @param {string} message Status message to render.
 */
function setStatus( message ) {
	const node = document.getElementById( STATUS_NODE_ID );
	if ( node ) {
		node.textContent = message;
	}
}

/**
 * Recursively validate an editor block tree.
 *
 * @param {Object}   block          Editor block instance from wp.blocks.parse().
 * @param {Function} getBlockType   wp.blocks.getBlockType.
 * @param {Function} validateBlock  wp.blocks.validateBlock.
 * @param {Function} getSaveContent wp.blocks.getSaveContent.
 * @return {Array<object>} Flattened per-block result entries.
 */
function walkBlocks( block, getBlockType, validateBlock, getSaveContent ) {
	const results = [];

	if (
		! block ||
		! block.name ||
		block.name === 'core/freeform' ||
		block.name === 'core/missing'
	) {
		return results;
	}

	const blockType = getBlockType( block.name );
	let isValid = true;
	let issues = [];
	let expectedContent = block.originalContent || '';

	if ( blockType ) {
		try {
			const v = validateBlock( block, blockType );
			// Recent Gutenberg returns a tuple [isValid, issues]; older releases
			// returned { isValid, validationIssues }. Support both.
			if ( Array.isArray( v ) ) {
				isValid = Boolean( v[ 0 ] );
				issues = Array.isArray( v[ 1 ] )
					? v[ 1 ].map( ( i ) =>
							typeof i === 'string' ? i : i?.message || ''
					  )
					: [];
			} else if ( v && typeof v === 'object' ) {
				isValid = Boolean( v.isValid );
				issues = Array.isArray( v.validationIssues )
					? v.validationIssues.map( ( i ) =>
							typeof i === 'string' ? i : i?.message || ''
					  )
					: [];
			}

			if ( ! isValid ) {
				try {
					expectedContent = getSaveContent(
						blockType,
						block.attributes,
						block.innerBlocks
					);
				} catch ( saveError ) {
					expectedContent = block.originalContent || '';
					issues.push(
						`Could not compute expected save content: ${ saveError.message }`
					);
				}
			}
		} catch ( error ) {
			isValid = false;
			issues = [ `validateBlock threw: ${ error.message }` ];
		}
	} else {
		// Block type isn't registered in this context. Treat as a warning,
		// not a hard failure — PHP fallback can still flag deeper issues.
		issues = [
			`Block type "${ block.name }" is not registered in the validator page; result may be incomplete.`,
		];
	}

	results.push( {
		blockName: block.name,
		isValid,
		issues,
		originalContent: block.originalContent || '',
		expectedContent: expectedContent || block.originalContent || '',
	} );

	if ( Array.isArray( block.innerBlocks ) ) {
		for ( const inner of block.innerBlocks ) {
			results.push(
				...walkBlocks(
					inner,
					getBlockType,
					validateBlock,
					getSaveContent
				)
			);
		}
	}

	return results;
}

/**
 * Run the validator against a raw block-markup string.
 *
 * @param {string} blockMarkup Raw Gutenberg block content.
 * @return {Promise<object>} Studio-shaped report.
 */
async function validateBlocks( blockMarkup ) {
	const api = getBlocksApi();

	if ( ! api ) {
		return {
			totalBlocks: 0,
			validBlocks: 0,
			invalidBlocks: 0,
			results: [],
			source: 'js',
			error: 'wp.blocks is not available — block library failed to load.',
		};
	}

	const { parse, getBlockType, validateBlock, getSaveContent } = api;

	let parsed = [];
	try {
		parsed = parse( blockMarkup );
	} catch ( error ) {
		return {
			totalBlocks: 0,
			validBlocks: 0,
			invalidBlocks: 0,
			results: [],
			source: 'js',
			error: `wp.blocks.parse threw: ${ error.message }`,
		};
	}

	const results = [];
	for ( const block of parsed ) {
		results.push(
			...walkBlocks( block, getBlockType, validateBlock, getSaveContent )
		);
	}

	const invalidBlocks = results.filter( ( r ) => ! r.isValid ).length;
	const report = {
		totalBlocks: results.length,
		validBlocks: results.length - invalidBlocks,
		invalidBlocks,
		results,
		source: 'js',
		error: null,
	};

	// Best-effort cache write so PHP can pick this report up.
	try {
		const config = window.sdAiAgentBlockValidatorConfig;
		if ( config && config.cacheEndpoint ) {
			await window.fetch( config.cacheEndpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.nonce || '',
				},
				body: JSON.stringify( {
					content: blockMarkup,
					report,
				} ),
			} );
		}
	} catch ( cacheError ) {
		// Cache write is non-fatal; the JS report is still returned.
		report.cacheError = cacheError.message;
	}

	return report;
}

/**
 * Wire up the postMessage bridge so the page can be iframed from the chat UI.
 */
function installPostMessageBridge() {
	window.addEventListener( 'message', async ( event ) => {
		if ( event.origin !== window.location.origin ) {
			return;
		}
		const data = event.data || {};
		if ( data.type !== MESSAGE_TYPE_REQUEST ) {
			return;
		}

		const report = await validateBlocks( String( data.content || '' ) );

		// Reply to whichever window sent the request.
		const target = event.source;
		if ( target && typeof target.postMessage === 'function' ) {
			target.postMessage(
				{
					type: MESSAGE_TYPE_RESULT,
					requestId: data.requestId,
					report,
				},
				event.origin
			);
		}
	} );
}

// Expose the global API so any same-origin admin script can call into us.
if ( typeof window !== 'undefined' ) {
	window.sdAiAgentValidateBlocks = validateBlocks;
	installPostMessageBridge();
	setStatus( 'Validator ready.' );
}

export { validateBlocks };
