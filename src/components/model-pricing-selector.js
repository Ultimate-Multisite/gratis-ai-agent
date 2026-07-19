/**
 * Model pricing selector with tier grouping and cost hints.
 *
 * Mirrors the pricing data in CostCalculator.php. When the PHP-side pricing
 * changes, update MODEL_CATALOG below to match.
 *
 * Average session token assumptions (used for estimated session cost):
 *   - Input:  8,000 tokens  (system prompt + context + user messages)
 *   - Output: 2,000 tokens  (assistant replies)
 */

/**
 * WordPress dependencies
 */
import { SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Pricing per million tokens [input, output] in USD.
 * Keep in sync with CostCalculator::PRICING.
 *
 * @type {Object.<string, [number, number]>}
 */
const PRICING = {
	// Superdav managed models.
	'gpt-5.6-luna': [ 0.25, 1.0 ],
	'gpt-5.6-terra': [ 1.5, 6.0 ],
	'gpt-5.6-sol': [ 1.5, 6.0 ],
	// Claude models.
	'claude-haiku-4': [ 0.8, 4.0 ],
	'claude-sonnet-4': [ 3.0, 15.0 ],
	'claude-opus-4': [ 15.0, 75.0 ],
	'claude-3-5-haiku-20241022': [ 0.8, 4.0 ],
	// GPT-4o models.
	'gpt-4o-mini': [ 0.15, 0.6 ],
	'gpt-4o': [ 2.5, 10.0 ],
	// GPT-4.1 models.
	'gpt-4.1-nano': [ 0.1, 0.4 ],
	'gpt-4.1-mini': [ 0.4, 1.6 ],
	'gpt-4.1': [ 2.0, 8.0 ],
	// o-series models.
	'o3-mini': [ 1.1, 4.4 ],
	'o4-mini': [ 1.1, 4.4 ],
	o3: [ 10.0, 40.0 ],
	// Gemini models (OpenRouter IDs use google/ prefix).
	'google/gemini-2.5-flash-preview': [ 0.3, 2.5 ],
	'google/gemini-2.5-flash-lite-preview': [ 0.1, 0.4 ],
	'gemini-2.0-flash': [ 0.1, 0.4 ],
	'gemini-2.0-flash-lite': [ 0.075, 0.3 ],
	'gemini-2.5-pro-preview-05-06': [ 1.25, 10.0 ],
	'gemini-1.5-pro': [ 1.25, 5.0 ],
	'gemini-1.5-flash': [ 0.075, 0.3 ],
};

/**
 * Average tokens per session (input + output) used for cost estimates.
 */
const AVG_SESSION_INPUT_TOKENS = 8000;
const AVG_SESSION_OUTPUT_TOKENS = 2000;

/**
 * Tier definitions: Budget / Standard / Premium.
 * Threshold is the maximum input price per million tokens for that tier.
 */
const TIERS = [
	{ id: 'budget', label: __( 'Budget', 'sd-ai-agent' ), maxInput: 0.5 },
	{
		id: 'standard',
		label: __( 'Standard', 'sd-ai-agent' ),
		maxInput: 3.0,
	},
	{
		id: 'premium',
		label: __( 'Premium', 'sd-ai-agent' ),
		maxInput: Infinity,
	},
];

/**
 * Canonical model catalogue with display metadata.
 * Only models listed here appear in the grouped selector.
 * Models returned by the REST API but not in this list fall back to the
 * plain SelectControl label (no pricing hint).
 *
 * @type {Array<{id: string, provider: string, name: string, note: string}>}
 */
const MODEL_CATALOG = [
	// Superdav
	{
		id: 'gpt-5.6-luna',
		provider: 'sd-ai-agent-cloud',
		name: 'GPT-5.6 Luna',
		note: __( 'fast', 'superdav-ai-agent' ),
	},
	{
		id: 'gpt-5.6-terra',
		provider: 'sd-ai-agent-cloud',
		name: 'GPT-5.6 Terra',
		note: __( 'standard', 'superdav-ai-agent' ),
	},
	{
		id: 'gpt-5.6-sol',
		provider: 'sd-ai-agent-cloud',
		name: 'GPT-5.6 Sol',
		note: __( 'strong', 'superdav-ai-agent' ),
	},
	// Anthropic
	{
		id: 'claude-haiku-4',
		provider: 'anthropic',
		name: 'Claude Haiku 4',
		note: __( 'fastest', 'sd-ai-agent' ),
	},
	{
		id: 'claude-3-5-haiku-20241022',
		provider: 'anthropic',
		name: 'Claude 3.5 Haiku',
		note: __( 'budget', 'sd-ai-agent' ),
	},
	{
		id: 'claude-sonnet-4',
		provider: 'anthropic',
		name: 'Claude Sonnet 4',
		note: __( 'balanced', 'sd-ai-agent' ),
	},
	{
		id: 'claude-opus-4',
		provider: 'anthropic',
		name: 'Claude Opus 4',
		note: __( 'most capable', 'sd-ai-agent' ),
	},
	// OpenAI GPT-4.1
	{
		id: 'gpt-4.1-nano',
		provider: 'openai',
		name: 'GPT-4.1 Nano',
		note: __( 'best value', 'sd-ai-agent' ),
	},
	{
		id: 'gpt-4.1-mini',
		provider: 'openai',
		name: 'GPT-4.1 Mini',
		note: __( 'fast & affordable', 'sd-ai-agent' ),
	},
	{
		id: 'gpt-4.1',
		provider: 'openai',
		name: 'GPT-4.1',
		note: __( 'high quality', 'sd-ai-agent' ),
	},
	// OpenAI GPT-4o
	{
		id: 'gpt-4o-mini',
		provider: 'openai',
		name: 'GPT-4o Mini',
		note: __( 'affordable', 'sd-ai-agent' ),
	},
	{
		id: 'gpt-4o',
		provider: 'openai',
		name: 'GPT-4o',
		note: __( 'multimodal', 'sd-ai-agent' ),
	},
	// OpenAI o-series
	{
		id: 'o4-mini',
		provider: 'openai',
		name: 'o4-mini',
		note: __( 'reasoning', 'sd-ai-agent' ),
	},
	{
		id: 'o3-mini',
		provider: 'openai',
		name: 'o3-mini',
		note: __( 'reasoning', 'sd-ai-agent' ),
	},
	{
		id: 'o3',
		provider: 'openai',
		name: 'o3',
		note: __( 'advanced reasoning', 'sd-ai-agent' ),
	},
	// Google Gemini
	{
		id: 'gemini-2.0-flash-lite',
		provider: 'google',
		name: 'Gemini 2.0 Flash Lite',
		note: __( 'best value', 'sd-ai-agent' ),
	},
	{
		id: 'google/gemini-2.5-flash-lite-preview',
		provider: 'google',
		name: 'Gemini 2.5 Flash Lite',
		note: __( 'budget', 'sd-ai-agent' ),
	},
	{
		id: 'gemini-2.0-flash',
		provider: 'google',
		name: 'Gemini 2.0 Flash',
		note: __( 'fast & affordable', 'sd-ai-agent' ),
	},
	{
		id: 'google/gemini-2.5-flash-preview',
		provider: 'google',
		name: 'Gemini 2.5 Flash',
		note: __( 'fast & capable', 'sd-ai-agent' ),
	},
	{
		id: 'gemini-1.5-flash',
		provider: 'google',
		name: 'Gemini 1.5 Flash',
		note: __( 'affordable', 'sd-ai-agent' ),
	},
	{
		id: 'gemini-1.5-pro',
		provider: 'google',
		name: 'Gemini 1.5 Pro',
		note: __( 'high quality', 'sd-ai-agent' ),
	},
	{
		id: 'gemini-2.5-pro-preview-05-06',
		provider: 'google',
		name: 'Gemini 2.5 Pro',
		note: __( 'most capable', 'sd-ai-agent' ),
	},
];

/**
 * Determine the tier for a model based on its input price per million tokens.
 *
 * @param {string} modelId - Model identifier.
 * @return {Object} Tier object from TIERS.
 */
function getTier( modelId ) {
	const pricing = PRICING[ modelId ];
	if ( ! pricing ) {
		return TIERS[ 1 ]; // Default to Standard when unknown.
	}
	const inputPrice = pricing[ 0 ];
	return TIERS.find( ( t ) => inputPrice <= t.maxInput ) || TIERS[ 2 ];
}

/**
 * Format a price per million tokens as a short human-readable string.
 *
 * @param {number} price - Price per million tokens in USD.
 * @return {string} Formatted string, e.g. "$0.10/M" or "$3.00/M".
 */
function formatPrice( price ) {
	if ( price < 1 ) {
		return `$${ price.toFixed( 2 ) }/M`;
	}
	return `$${ price.toFixed( 2 ) }/M`;
}

/**
 * Estimate the cost of an average session for a given model.
 *
 * @param {string} modelId - Model identifier.
 * @return {string|null} Formatted cost string, e.g. "~$0.01/session", or null.
 */
function estimateSessionCost( modelId ) {
	const pricing = PRICING[ modelId ];
	if ( ! pricing ) {
		return null;
	}
	const [ inputPricePerM, outputPricePerM ] = pricing;
	const cost =
		( AVG_SESSION_INPUT_TOKENS / 1_000_000 ) * inputPricePerM +
		( AVG_SESSION_OUTPUT_TOKENS / 1_000_000 ) * outputPricePerM;

	if ( cost < 0.001 ) {
		return `~$${ ( cost * 1000 ).toFixed( 2 ) }m/session`; // millicents
	}
	if ( cost < 0.01 ) {
		return `~$${ cost.toFixed( 4 ) }/session`;
	}
	return `~$${ cost.toFixed( 3 ) }/session`;
}

/**
 * Build the option label for a model including pricing hint.
 *
 * @param {Object} catalogEntry - Entry from MODEL_CATALOG.
 * @return {string} Label string for the SelectControl option.
 */
function buildModelLabel( catalogEntry ) {
	const { id, name, note } = catalogEntry;
	const pricing = PRICING[ id ];

	if ( ! pricing ) {
		return note ? `${ name } — ${ note }` : name;
	}

	const [ inputPrice, outputPrice ] = pricing;
	const sessionCost = estimateSessionCost( id );
	const priceHint = `${ formatPrice( inputPrice ) } in / ${ formatPrice(
		outputPrice
	) } out`;

	const parts = [ name ];
	if ( note ) {
		parts.push( note );
	}
	parts.push( priceHint );
	if ( sessionCost ) {
		parts.push( sessionCost );
	}

	return parts.join( ' — ' );
}

/**
 * Build grouped SelectControl options from the available model list.
 *
 * Groups models by provider, then by tier (Budget / Standard / Premium).
 * Models not in MODEL_CATALOG are appended at the end under their provider
 * with no pricing hint.
 *
 * @param {Array}  models         - Models from the REST API for the selected provider.
 * @param {string} providerName   - Display name of the selected provider.
 * @param {string} [defaultLabel] - Label for the empty/default option.
 * @return {Array} Array of option objects for SelectControl.
 */
export function buildPricedModelOptions( models, providerName, defaultLabel ) {
	const options = [
		{
			label: defaultLabel || __( '(default)', 'sd-ai-agent' ),
			value: '',
		},
	];

	if ( ! models || ! models.length ) {
		return options;
	}

	// Index catalog entries by model ID for fast lookup.
	const catalogById = {};
	MODEL_CATALOG.forEach( ( entry ) => {
		catalogById[ entry.id ] = entry;
	} );

	// Separate models into catalogued (with pricing) and uncatalogued.
	const catalogued = [];
	const uncatalogued = [];

	models.forEach( ( m ) => {
		if ( catalogById[ m.id ] ) {
			catalogued.push( { model: m, entry: catalogById[ m.id ] } );
		} else {
			uncatalogued.push( m );
		}
	} );

	// Group catalogued models by tier.
	const byTier = {};
	TIERS.forEach( ( t ) => {
		byTier[ t.id ] = [];
	} );

	catalogued.forEach( ( { model, entry } ) => {
		const tier = getTier( entry.id );
		byTier[ tier.id ].push( { model, entry } );
	} );

	// Emit options grouped by tier.
	TIERS.forEach( ( tier ) => {
		const tierModels = byTier[ tier.id ];
		if ( ! tierModels.length ) {
			return;
		}

		// Group header as a disabled option (visual separator).
		options.push( {
			label: `── ${ tier.label } ──`,
			value: `__tier_${ tier.id }__`,
			disabled: true,
		} );

		tierModels.forEach( ( { model, entry } ) => {
			options.push( {
				label: buildModelLabel( entry ),
				value: model.id,
			} );
		} );
	} );

	// Append uncatalogued models under a separator.
	if ( uncatalogued.length ) {
		options.push( {
			label: `── ${ providerName || __( 'Other', 'sd-ai-agent' ) } ──`,
			value: '__tier_other__',
			disabled: true,
		} );
		uncatalogued.forEach( ( m ) => {
			options.push( {
				label: m.name || m.id,
				value: m.id,
			} );
		} );
	}

	return options;
}

/**
 * Pricing hint badge shown below the model selector.
 *
 * Displays the tier label, per-million token prices, and estimated session
 * cost for the currently selected model.
 *
 * @param {Object} props         - Component props.
 * @param {string} props.modelId - Currently selected model ID.
 * @return {JSX.Element|null} Pricing hint element, or null when no data.
 */
export function ModelPricingHint( { modelId } ) {
	if ( ! modelId ) {
		return null;
	}

	const pricing = PRICING[ modelId ];
	if ( ! pricing ) {
		return null;
	}

	const [ inputPrice, outputPrice ] = pricing;
	const tier = getTier( modelId );
	const sessionCost = estimateSessionCost( modelId );

	return (
		<p className="sdaa-model-pricing-hint">
			<span
				className={ `sdaa-model-pricing-hint__tier sdaa-model-pricing-hint__tier--${ tier.id }` }
			>
				{ tier.label }
			</span>{ ' ' }
			<span className="sdaa-model-pricing-hint__prices">
				{ formatPrice( inputPrice ) } { __( 'input', 'sd-ai-agent' ) }
				{ ' / ' }
				{ formatPrice( outputPrice ) } { __( 'output', 'sd-ai-agent' ) }
			</span>
			{ sessionCost && (
				<>
					{ ' · ' }
					<span className="sdaa-model-pricing-hint__session">
						{ sessionCost }{ ' ' }
						{ __( '(avg. session estimate)', 'sd-ai-agent' ) }
					</span>
				</>
			) }
		</p>
	);
}

/**
 * Model selector with pricing hints and tier grouping.
 *
 * Drop-in replacement for a plain SelectControl when selecting a model in
 * the Settings General tab. Shows pricing hints inline in the option labels
 * and renders a pricing badge below the selector for the selected model.
 *
 * @param {Object}   props              - Component props.
 * @param {string}   props.id           - ID forwarded to the underlying SelectControl.
 * @param {string}   props.label        - SelectControl label.
 * @param {string}   props.value        - Currently selected model ID.
 * @param {Array}    props.models       - Models from the REST API.
 * @param {string}   props.providerName - Display name of the selected provider.
 * @param {Function} props.onChange     - Change handler.
 * @return {JSX.Element} Model selector with pricing hints.
 */
export default function ModelPricingSelector( {
	id,
	label,
	value,
	models,
	providerName,
	onChange,
} ) {
	const options = buildPricedModelOptions(
		models,
		providerName,
		__( '(default)', 'sd-ai-agent' )
	);

	const handleChange = ( newValue ) => {
		// Ignore clicks on disabled group-header options.
		if ( newValue.startsWith( '__tier_' ) ) {
			return;
		}
		onChange( newValue );
	};

	return (
		<div className="sdaa-model-pricing-selector">
			<SelectControl
				id={ id }
				label={ label }
				value={ value }
				options={ options }
				onChange={ handleChange }
				__nextHasNoMarginBottom
			/>
			<ModelPricingHint modelId={ value } />
		</div>
	);
}
