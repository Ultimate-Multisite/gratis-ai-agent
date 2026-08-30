/**
 * Read-only Gutenberg editor capability manifest.
 *
 * Collects a bounded, allowlisted snapshot of the active editor without
 * retaining settings or exposing raw third-party registration objects.
 */

import { registerClientAbility } from './registry';

export const MAX_SUMMARY_BLOCKS = 100;
export const MAX_DETAILED_BLOCKS = 20;
export const MAX_RESPONSE_BYTES = 128 * 1024;

const MAX_STRING_LENGTH = 200;
const SUPPORT_KEYS = [
	'align',
	'anchor',
	'ariaLabel',
	'background',
	'border',
	'color',
	'dimensions',
	'filter',
	'html',
	'inserter',
	'layout',
	'position',
	'shadow',
	'spacing',
	'typography',
];
const ATTRIBUTE_KEYS = [
	'align',
	'anchor',
	'backgroundColor',
	'content',
	'fontSize',
	'gradient',
	'layout',
	'style',
	'textColor',
];

/**
 * Return a bounded string safe for a JSON response.
 *
 * @param {*} value Candidate value.
 * @return {string} Bounded string, or an empty string for non-strings.
 */
function safeString( value ) {
	return typeof value === 'string' ? value.slice( 0, MAX_STRING_LENGTH ) : '';
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
 * Normalize known boolean/object support flags without copying raw supports.
 *
 * @param {*} supports Block support configuration.
 * @return {Object} Allowlisted capability flags.
 */
function normalizeSupports( supports ) {
	if ( ! supports || typeof supports !== 'object' ) {
		return {};
	}

	return SUPPORT_KEYS.reduce( ( normalized, key ) => {
		if ( typeof supports[ key ] === 'boolean' ) {
			normalized[ key ] = supports[ key ];
		} else if ( supports[ key ] && typeof supports[ key ] === 'object' ) {
			normalized[ key ] = Object.keys( supports[ key ] )
				.filter(
					( childKey ) =>
						typeof supports[ key ][ childKey ] === 'boolean'
				)
				.slice( 0, 10 )
				.map( safeString );
		}
		return normalized;
	}, {} );
}

/**
 * Return an allowlisted subset of attributes useful when composing a block.
 *
 * @param {*} attributes Block attribute schema.
 * @return {Object[]} Safe attribute summaries.
 */
function normalizeAttributes( attributes ) {
	if ( ! attributes || typeof attributes !== 'object' ) {
		return [];
	}

	return ATTRIBUTE_KEYS.filter( ( name ) => attributes[ name ] ).map(
		( name ) => ( {
			name,
			type: safeString( attributes[ name ].type ),
		} )
	);
}

/**
 * Normalize style or variation labels without returning arbitrary objects.
 *
 * @param {*} values Candidate style array.
 * @return {Object[]} Bounded style metadata.
 */
function normalizeStyleMetadata( values ) {
	if ( ! Array.isArray( values ) ) {
		return [];
	}

	return values.slice( 0, 20 ).map( ( value ) => ( {
		name: safeString( value?.name ),
		label: safeString( value?.label || value?.title ),
	} ) );
}

/**
 * Normalize named theme presets to their generation-relevant public values.
 *
 * @param {*}      presets  Candidate preset array.
 * @param {string} valueKey Value key to include.
 * @return {Object[]} Bounded preset list.
 */
function normalizePresets( presets, valueKey ) {
	if ( ! Array.isArray( presets ) ) {
		return [];
	}

	return presets.slice( 0, 20 ).map( ( preset ) => ( {
		name: safeString( preset?.name ),
		slug: safeString( preset?.slug ),
		[ valueKey ]: safeString( preset?.[ valueKey ] ),
	} ) );
}

/**
 * Resolve an editor setting from supported block-editor or editor selectors.
 *
 * @return {{settings: Object|null, unavailable: boolean}} Editor settings result.
 */
function getEditorSettings() {
	if ( typeof wp === 'undefined' || ! wp.data?.select ) {
		return { settings: null, unavailable: true };
	}

	for ( const storeName of [ 'core/block-editor', 'core/editor' ] ) {
		try {
			const selector = wp.data.select( storeName );
			if ( typeof selector?.getSettings === 'function' ) {
				const settings = selector.getSettings();
				if ( settings && typeof settings === 'object' ) {
					return { settings, unavailable: false };
				}
			}
		} catch ( _error ) {
			// Continue to the next selector: Gutenberg store availability varies.
		}
	}

	return { settings: null, unavailable: true };
}

/**
 * Determine whether a block is allowed by the current editor context.
 *
 * @param {string}      name     Block name.
 * @param {Object}      selector Block editor selector.
 * @param {Object|null} settings Editor settings.
 * @return {boolean|null} Allowed state, or null when unavailable.
 */
function getAllowedStatus( name, selector, settings ) {
	try {
		if ( typeof selector?.canInsertBlockType === 'function' ) {
			return !! selector.canInsertBlockType( name );
		}
	} catch ( _error ) {
		return null;
	}

	if ( Array.isArray( settings?.allowedBlockTypes ) ) {
		return settings.allowedBlockTypes.includes( name );
	}
	if ( typeof settings?.allowedBlockTypes === 'boolean' ) {
		return settings.allowedBlockTypes;
	}

	return null;
}

/**
 * Normalize a registered block type into a serializable manifest entry.
 *
 * @param {Object}      block    Registered block type.
 * @param {Object}      selector Block editor selector.
 * @param {Object|null} settings Editor settings.
 * @return {Object} Safe block capability entry.
 */
function normalizeBlock( block, selector, settings ) {
	const name = safeString( block?.name );
	const getVariations = wp.blocks?.getBlockVariations;
	const getStyles = wp.blocks?.getBlockStyles;
	let variations = [];
	let styles = [];

	try {
		variations =
			typeof getVariations === 'function'
				? normalizeStyleMetadata( getVariations( name ) )
				: [];
	} catch ( _error ) {
		// Keep the remaining editor snapshot when a third-party registry fails.
	}

	try {
		styles =
			typeof getStyles === 'function'
				? normalizeStyleMetadata( getStyles( name ) )
				: [];
	} catch ( _error ) {
		// Keep the remaining editor snapshot when a third-party registry fails.
	}

	return {
		name,
		title: safeString( block?.title ),
		category: safeString( block?.category ),
		allowed: getAllowedStatus( name, selector, settings ),
		supports: normalizeSupports( block?.supports ),
		attributes: normalizeAttributes( block?.attributes ),
		variations,
		styles,
	};
}

/**
 * Return normalized global editor and theme capabilities.
 *
 * @param {Object|null} settings Editor settings.
 * @return {Object} Bounded global capability data.
 */
function getGlobalCapabilities( settings ) {
	return {
		colors: normalizePresets( settings?.colors, 'color' ),
		gradients: normalizePresets( settings?.gradients, 'gradient' ),
		duotone: normalizePresets( settings?.duotone, 'slug' ),
		fontSizes: normalizePresets( settings?.fontSizes, 'size' ),
		spacingSizes: normalizePresets( settings?.spacingSizes, 'size' ),
		layout: {
			contentSize: safeString( settings?.layout?.contentSize ),
			wideSize: safeString( settings?.layout?.wideSize ),
			supportsLayout: !! settings?.supportsLayout,
			appearanceTools: !! settings?.appearanceTools,
		},
		style: {
			stylesheet: safeString( settings?.stylesheet ),
			variation: safeString( settings?.styleVariation ),
		},
	};
}

/**
 * Check whether a response fits within the ability output limit.
 *
 * @param {Object} response Candidate ability response.
 * @return {boolean} Whether the serialized response fits the limit.
 */
function responseFits( response ) {
	return getByteLength( JSON.stringify( response ) ) <= MAX_RESPONSE_BYTES;
}

/**
 * Remove optional response data until the serialized response is bounded.
 *
 * @param {Object} response   Candidate ability response.
 * @param {number} blockCount Total registered block count.
 * @return {Object} Response that fits the output limit.
 */
function limitResponseSize( response, blockCount ) {
	if ( responseFits( response ) ) {
		return response;
	}

	response.truncated = true;
	response.unavailable_sources.push( 'response_size_limit' );

	for ( const key of [
		'block_details',
		'requested',
		'unregistered',
		'disallowed',
		'block_names',
	] ) {
		if ( responseFits( response ) ) {
			return response;
		}
		response[ key ].length = 0;
	}
	response.omitted_blocks = blockCount;

	if ( responseFits( response ) ) {
		return response;
	}

	response.global = {};

	if ( responseFits( response ) ) {
		return response;
	}

	return response;
}

/**
 * Return a bounded runtime capability manifest for the active editor.
 *
 * @param {Object}   args              Ability arguments.
 * @param {string[]} [args.blockNames] Optional installed block names for details.
 * @return {Object} JSON-serializable capability manifest.
 */
export function getEditorCapabilities( args = {} ) {
	const unavailableSources = [];
	const empty = {
		available: false,
		block_names: [],
		block_details: [],
		global: getGlobalCapabilities( null ),
		requested: [],
		unregistered: [],
		disallowed: [],
		omitted_blocks: 0,
		truncated: false,
		unavailable_sources: [ 'block_registry', 'editor_settings' ],
	};

	if ( typeof wp === 'undefined' || ! wp.blocks ) {
		return empty;
	}

	try {
		if ( typeof wp.blocks.getBlockTypes !== 'function' ) {
			return empty;
		}

		const blocks = wp.blocks
			.getBlockTypes()
			.filter( ( block ) => safeString( block?.name ) );
		const { settings, unavailable } = getEditorSettings();
		if ( unavailable ) {
			unavailableSources.push( 'editor_settings' );
		}
		let selector = null;
		try {
			selector = wp.data?.select?.( 'core/block-editor' );
		} catch ( _error ) {
			unavailableSources.push( 'block_editor_selector' );
		}
		if ( ! selector?.canInsertBlockType && ! settings?.allowedBlockTypes ) {
			unavailableSources.push( 'allowed_block_constraints' );
		}
		if (
			typeof wp.blocks.getBlockVariations !== 'function' ||
			typeof wp.blocks.getBlockStyles !== 'function'
		) {
			unavailableSources.push( 'block_style_metadata' );
		}

		const requested = Array.isArray( args.blockNames )
			? [
					...new Set(
						args.blockNames
							.filter( ( name ) => typeof name === 'string' )
							.map( safeString )
							.filter( Boolean )
					),
			  ].slice( 0, MAX_DETAILED_BLOCKS )
			: [];
		const blocksByName = new Map(
			blocks.map( ( block ) => [ safeString( block.name ), block ] )
		);
		const detailedBlocks = requested
			.map( ( name ) => blocksByName.get( name ) )
			.filter( Boolean )
			.map( ( block ) => normalizeBlock( block, selector, settings ) );
		const unregistered = requested.filter(
			( name ) => ! blocksByName.has( name )
		);
		const disallowed = detailedBlocks
			.filter( ( block ) => block.allowed === false )
			.map( ( block ) => block.name );
		const summary = [
			...new Set( blocks.map( ( block ) => safeString( block.name ) ) ),
		]
			.filter( Boolean )
			.slice( 0, MAX_SUMMARY_BLOCKS );
		const result = {
			available: true,
			block_names: summary,
			block_details: detailedBlocks,
			global: getGlobalCapabilities( settings ),
			requested,
			unregistered,
			disallowed,
			omitted_blocks: Math.max( 0, blocks.length - summary.length ),
			truncated:
				blocks.length > summary.length ||
				( Array.isArray( args.blockNames ) &&
					args.blockNames.length > requested.length ),
			unavailable_sources: unavailableSources,
		};

		return limitResponseSize( result, blocks.length );
	} catch ( _error ) {
		return empty;
	}
}

/**
 * Register the read-only editor capability ability.
 *
 * @return {Promise<void>} Registration completion.
 */
export async function registerEditorCapabilitiesAbility() {
	await registerClientAbility( {
		name: 'sd-ai-agent-js/get-editor-capabilities',
		label: 'Get Editor Capabilities',
		description:
			'Return a bounded, current manifest of installed Gutenberg blocks and active editor/theme capabilities without changing editor state.',
		inputSchema: {
			type: 'object',
			properties: {
				blockNames: {
					type: 'array',
					items: { type: 'string' },
				},
			},
		},
		outputSchema: {
			type: 'object',
			properties: {
				available: { type: 'boolean' },
				block_names: { type: 'array', items: { type: 'string' } },
				block_details: { type: 'array', items: { type: 'object' } },
				global: { type: 'object' },
				requested: { type: 'array', items: { type: 'string' } },
				unregistered: { type: 'array', items: { type: 'string' } },
				disallowed: { type: 'array', items: { type: 'string' } },
				omitted_blocks: { type: 'integer' },
				truncated: { type: 'boolean' },
				unavailable_sources: {
					type: 'array',
					items: { type: 'string' },
				},
			},
		},
		annotations: { readonly: true },
		callback: getEditorCapabilities,
	} );
}
