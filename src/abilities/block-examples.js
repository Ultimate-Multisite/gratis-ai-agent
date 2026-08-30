/**
 * Read-only canonical Gutenberg block examples.
 *
 * Builds examples only from currently registered block definitions, then
 * parses, validates, and reserializes them before returning canonical markup.
 */

import { registerClientAbility } from './registry';

export const MAX_BLOCK_NAMES = 20;
export const MAX_BLOCK_MARKUP_BYTES = 32 * 1024;
export const MAX_RESPONSE_BYTES = 128 * 1024;

const MAX_METADATA_LENGTH = 200;
const UNSUPPORTED_BLOCKS = new Set( [ 'core/freeform', 'core/missing' ] );

/**
 * Return a bounded string suitable for a tool response.
 *
 * @param {*} value Candidate value.
 * @return {string} Bounded string.
 */
function safeString( value ) {
	if ( typeof value === 'string' ) {
		return value.slice( 0, MAX_METADATA_LENGTH );
	}

	return typeof value === 'number'
		? String( value ).slice( 0, MAX_METADATA_LENGTH )
		: '';
}

/**
 * Get the UTF-8 byte length of a string.
 *
 * @param {string} value String to measure.
 * @return {number} UTF-8 byte length.
 */
function getByteLength( value ) {
	if ( typeof TextEncoder !== 'undefined' ) {
		return new TextEncoder().encode( value ).byteLength;
	}

	return unescape( encodeURIComponent( value ) ).length;
}

/**
 * Extract validation state from Gutenberg's supported return shapes.
 *
 * @param {*} validation Gutenberg validation result.
 * @return {{isValid: boolean, warnings: string[]}} Normalized validation result.
 */
function normalizeValidation( validation ) {
	if ( typeof validation === 'boolean' ) {
		return { isValid: validation, warnings: [] };
	}
	if ( Array.isArray( validation ) ) {
		return {
			isValid: validation[ 0 ] === true,
			warnings: Array.isArray( validation[ 1 ] )
				? validation[ 1 ].map( ( issue ) =>
						safeString(
							typeof issue === 'string' ? issue : issue?.message
						)
				  )
				: [],
		};
	}
	if ( validation && typeof validation === 'object' ) {
		return {
			isValid: validation.isValid === true,
			warnings: Array.isArray( validation.validationIssues )
				? validation.validationIssues.map( ( issue ) =>
						safeString(
							typeof issue === 'string' ? issue : issue?.message
						)
				  )
				: [],
		};
	}

	return { isValid: false, warnings: [ 'Unsupported validation result.' ] };
}

/**
 * Convert a registered inner-block template to editor blocks when possible.
 *
 * @param {Object} api      Gutenberg block API.
 * @param {*}      template Registered example inner blocks.
 * @return {Object[]|null} Editor blocks, or null for an unsafe template.
 */
function createInnerBlocks( api, template ) {
	if ( ! Array.isArray( template ) ) {
		return [];
	}
	if ( typeof api.createBlocksFromInnerBlocksTemplate !== 'function' ) {
		return null;
	}

	try {
		const blocks = api.createBlocksFromInnerBlocksTemplate( template );
		return Array.isArray( blocks ) ? blocks : null;
	} catch ( _error ) {
		return null;
	}
}

/**
 * Validate every parsed block against its current registration.
 *
 * @param {Object}   api    Gutenberg block API.
 * @param {Object[]} blocks Parsed blocks.
 * @return {{isValid: boolean, warnings: string[]}} Validation result.
 */
function validateTree( api, blocks ) {
	const warnings = [];
	for ( const block of blocks ) {
		const blockType = api.getBlockType( block?.name );
		if ( ! blockType || UNSUPPORTED_BLOCKS.has( block.name ) ) {
			return {
				isValid: false,
				warnings: [ 'Parsed an unsupported block.' ],
			};
		}
		try {
			const result = normalizeValidation(
				api.validateBlock( block, blockType )
			);
			warnings.push( ...result.warnings.filter( Boolean ).slice( 0, 5 ) );
			if ( ! result.isValid ) {
				return { isValid: false, warnings };
			}
		} catch ( error ) {
			return {
				isValid: false,
				warnings: [
					`validateBlock threw: ${ safeString( error?.message ) }`,
				],
			};
		}
		const nested = validateTree( api, block.innerBlocks || [] );
		warnings.push( ...nested.warnings );
		if ( ! nested.isValid ) {
			return { isValid: false, warnings };
		}
	}

	return { isValid: true, warnings: warnings.slice( 0, 10 ) };
}

/**
 * Build one canonical example from a current block registration.
 *
 * @param {Object} api       Gutenberg block API.
 * @param {string} blockName Requested block name.
 * @return {Object} Success or unsupported response entry.
 */
function getCanonicalExample( api, blockName ) {
	const unsupported = ( reason, warnings = [] ) => ( {
		block_name: blockName,
		supported: false,
		reason,
		warnings: warnings.filter( Boolean ).slice( 0, 10 ),
	} );
	if ( UNSUPPORTED_BLOCKS.has( blockName ) ) {
		return unsupported( 'unsupported_block_type' );
	}

	const blockType = api.getBlockType( blockName );
	if ( ! blockType ) {
		return unsupported( 'unregistered_block' );
	}

	const hasRegisteredExample =
		blockType.example && typeof blockType.example === 'object';
	let attributes = {};
	let innerBlocks = [];
	let source = 'default-block';

	if ( hasRegisteredExample ) {
		attributes = blockType.example.attributes || {};
		innerBlocks = createInnerBlocks( api, blockType.example.innerBlocks );
		source = 'registered-example';
		if ( ! innerBlocks ) {
			return unsupported( 'unsafe_registered_example' );
		}
	} else if ( typeof blockType.save !== 'function' ) {
		return unsupported( 'missing_safe_default_save_path' );
	}

	let block;
	try {
		block = api.createBlock( blockName, attributes, innerBlocks );
	} catch ( error ) {
		return unsupported( 'block_creation_failed', [
			safeString( error?.message ),
		] );
	}
	if ( ! block ) {
		return unsupported( 'block_creation_failed' );
	}

	let markup;
	let parsed;
	try {
		markup = api.serialize( [ block ] );
		if ( typeof markup !== 'string' || ! markup ) {
			return unsupported( 'empty_serialization' );
		}
		if ( getByteLength( markup ) > MAX_BLOCK_MARKUP_BYTES ) {
			return unsupported( 'block_markup_limit' );
		}
		parsed = api.parse( markup );
	} catch ( error ) {
		return unsupported( 'round_trip_failed', [
			safeString( error?.message ),
		] );
	}
	if (
		! Array.isArray( parsed ) ||
		parsed.length !== 1 ||
		parsed[ 0 ]?.name !== blockName
	) {
		return unsupported( 'round_trip_block_mismatch' );
	}

	const validation = validateTree( api, parsed );
	if ( ! validation.isValid ) {
		return unsupported( 'invalid_round_trip', validation.warnings );
	}

	let canonicalMarkup;
	try {
		canonicalMarkup = api.serialize( parsed );
	} catch ( error ) {
		return unsupported( 'reserialization_failed', [
			safeString( error?.message ),
		] );
	}
	if (
		typeof canonicalMarkup !== 'string' ||
		! canonicalMarkup ||
		getByteLength( canonicalMarkup ) > MAX_BLOCK_MARKUP_BYTES
	) {
		return unsupported( 'block_markup_limit' );
	}

	return {
		block_name: blockName,
		supported: true,
		source,
		title: safeString( blockType.title ),
		version: safeString( blockType.version || blockType.apiVersion ),
		markup: canonicalMarkup,
		valid: true,
		warnings: validation.warnings,
	};
}

/**
 * Generate bounded canonical examples from current Gutenberg registrations.
 *
 * @param {Object}   args            Ability arguments.
 * @param {string[]} args.blockNames One to twenty unique registered block names.
 * @return {Object} Canonical examples or structured unsupported responses.
 */
export function getCanonicalBlockExamples( args = {} ) {
	const empty = {
		available: false,
		requested: [],
		examples: [],
		warnings: [ 'block_registry_unavailable' ],
	};
	if (
		typeof wp === 'undefined' ||
		! wp.blocks ||
		typeof wp.blocks.getBlockType !== 'function' ||
		typeof wp.blocks.createBlock !== 'function' ||
		typeof wp.blocks.serialize !== 'function' ||
		typeof wp.blocks.parse !== 'function' ||
		typeof wp.blocks.validateBlock !== 'function'
	) {
		return empty;
	}

	const blockNames = args?.blockNames;
	if ( ! Array.isArray( blockNames ) || ! blockNames.length ) {
		return {
			...empty,
			available: true,
			warnings: [ 'block_names_required' ],
		};
	}
	if (
		blockNames.length > MAX_BLOCK_NAMES ||
		new Set( blockNames ).size !== blockNames.length ||
		blockNames.some(
			( name ) =>
				typeof name !== 'string' ||
				! name ||
				name.length > MAX_METADATA_LENGTH
		)
	) {
		return {
			...empty,
			available: true,
			warnings: [ 'block_names_must_contain_one_to_twenty_unique_names' ],
		};
	}

	const api = wp.blocks;
	const response = {
		available: true,
		requested: blockNames,
		examples: [],
		warnings: [],
	};
	for ( const blockName of blockNames ) {
		let example;
		try {
			example = getCanonicalExample( api, blockName );
		} catch ( error ) {
			example = {
				block_name: blockName,
				supported: false,
				reason: 'example_generation_failed',
				warnings: [ safeString( error?.message ) ].filter( Boolean ),
			};
		}
		response.examples.push( example );
		if (
			getByteLength( JSON.stringify( response ) ) > MAX_RESPONSE_BYTES
		) {
			response.examples.pop();
			response.examples.push( {
				block_name: blockName,
				supported: false,
				reason: 'response_size_limit',
				warnings: [],
			} );
			response.warnings.push( 'response_size_limit' );
			break;
		}
	}

	return response;
}

/**
 * Register the read-only canonical block examples ability.
 *
 * @return {Promise<void>} Registration completion.
 */
export async function registerCanonicalBlockExamplesAbility() {
	await registerClientAbility( {
		name: 'sd-ai-agent-js/get-canonical-block-examples',
		label: 'Get Canonical Block Examples',
		description:
			'Generate bounded, validated canonical Gutenberg markup from currently installed block registrations without changing editor state.',
		inputSchema: {
			type: 'object',
			properties: {
				blockNames: {
					type: 'array',
					minItems: 1,
					maxItems: MAX_BLOCK_NAMES,
					uniqueItems: true,
					items: { type: 'string', maxLength: MAX_METADATA_LENGTH },
				},
			},
			required: [ 'blockNames' ],
		},
		outputSchema: {
			type: 'object',
			properties: {
				available: { type: 'boolean' },
				requested: { type: 'array', items: { type: 'string' } },
				examples: { type: 'array', items: { type: 'object' } },
				warnings: { type: 'array', items: { type: 'string' } },
			},
		},
		annotations: { readonly: true },
		callback: getCanonicalBlockExamples,
	} );
}
