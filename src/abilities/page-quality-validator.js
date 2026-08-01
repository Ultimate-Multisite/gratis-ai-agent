/**
 * Lightweight registration for rendered page-quality validation.
 *
 * The inspector and screenshot stack are loaded only when the ability executes
 * so the floating chat widget's initial bundle remains within its size budget.
 */

import { registerClientAbility } from './registry';

// Client-ability schemas are also exposed to model providers. Keep every
// nested object concrete: an unconstrained `{ type: 'object' }` is normalized
// to an empty required-property list by some provider adapters, causing the
// model to emit `{}` (serialized as `[]` by PHP) for pages and viewports.
const viewportSchema = {
	type: 'object',
	properties: {
		label: { type: 'string', enum: [ 'mobile', 'tablet', 'desktop' ] },
		width: { type: 'integer' },
		height: { type: 'integer' },
	},
	required: [ 'label', 'width', 'height' ],
};

const pageSchema = {
	type: 'object',
	properties: {
		post_id: { type: 'integer' },
		revision_id: { type: 'integer' },
		url: { type: 'string' },
		fields: { type: 'array', items: { type: 'string' } },
		role: { type: 'string', enum: [ 'homepage', 'page' ] },
	},
	required: [ 'post_id', 'revision_id', 'url', 'fields', 'role' ],
};

const heroContractSchema = {
	type: 'object',
	properties: {
		strategy: {
			type: 'string',
			enum: [
				'balanced',
				'immersive-media',
				'split-media',
				'editorial-feature',
				'product-focus',
			],
		},
		media_role: { type: 'string' },
		desktop_media_min_viewport_ratio: { type: 'number' },
		desktop_min_height_vh: { type: 'integer' },
		primary_cta_above_fold: { type: 'boolean' },
	},
	required: [
		'strategy',
		'media_role',
		'desktop_media_min_viewport_ratio',
		'desktop_min_height_vh',
		'primary_cta_above_fold',
	],
};

const findingSchema = {
	type: 'object',
	properties: {
		code: { type: 'string' },
		url: { type: 'string' },
		viewport: viewportSchema,
		selector: { type: 'string' },
		evidence: { type: 'string' },
		severity: { type: 'string' },
		remediation: { type: 'string' },
	},
	required: [
		'code',
		'url',
		'selector',
		'evidence',
		'severity',
		'remediation',
	],
};

const reportSchema = {
	type: 'object',
	properties: {
		post_id: { type: 'integer' },
		revision_id: { type: 'integer' },
		requested_url: { type: 'string' },
		final_url: { type: 'string' },
		role: { type: 'string' },
		is_homepage: { type: 'boolean' },
		viewport: viewportSchema,
		success: { type: 'boolean' },
		violations: { type: 'array', items: findingSchema },
		warnings: { type: 'array', items: findingSchema },
		checks: {
			type: 'object',
			properties: { composition_score: { type: 'number' } },
		},
		score: { type: 'number' },
		active_stylesheet: { type: 'string' },
	},
	required: [
		'post_id',
		'revision_id',
		'requested_url',
		'final_url',
		'role',
		'viewport',
		'success',
		'violations',
		'warnings',
	],
};

const screenshotSchema = {
	type: 'object',
	properties: {
		post_id: { type: 'integer' },
		url: { type: 'string' },
		viewport: viewportSchema,
		success: { type: 'boolean' },
		image: { type: 'string' },
		width: { type: 'integer' },
		height: { type: 'integer' },
		error: { type: 'string' },
	},
	required: [
		'post_id',
		'url',
		'viewport',
		'success',
		'image',
		'width',
		'height',
		'error',
	],
};

/**
 * Load and run the page-quality inspector on demand.
 *
 * @param {Object} args Current mutation token and validation surface.
 * @return {Promise<Object>} Structured page-quality report.
 */
export async function validatePageQuality( args ) {
	const { validatePageQuality: validate } = await import(
		'./page-quality-validation-core'
	);
	return validate( args );
}

/** Register the rendered page-quality client ability. */
export async function registerPageQualityValidatorAbility() {
	await registerClientAbility( {
		name: 'sd-ai-agent-js/validate-page-quality',
		label: 'Validate Rendered Page Quality',
		description:
			'Validate current affected published pages at the agent profile’s required viewports. Setup performs strict first-impression, composition, branding, media, accessibility, and responsive checks; General performs focused regression-safe page checks. A passing report is bound to the current page mutation token.',
		inputSchema: {
			type: 'object',
			properties: {
				profile: { type: 'string', enum: [ 'setup', 'incremental' ] },
				quality_token: { type: 'string' },
				pages: { type: 'array', items: pageSchema },
				hero_contract: heroContractSchema,
				viewports: { type: 'array', items: viewportSchema },
			},
			required: [
				'profile',
				'quality_token',
				'pages',
				'hero_contract',
				'viewports',
			],
		},
		outputSchema: {
			type: 'object',
			properties: {
				success: { type: 'boolean' },
				complete: { type: 'boolean' },
				passed: { type: 'boolean' },
				profile: { type: 'string' },
				quality_token: { type: 'string' },
				viewports: { type: 'array', items: viewportSchema },
				reports: { type: 'array', items: reportSchema },
				violations: { type: 'array', items: findingSchema },
				warnings: { type: 'array', items: findingSchema },
				screenshots: { type: 'array', items: screenshotSchema },
				minimum_score: { type: 'number' },
			},
		},
		annotations: { readonly: true },
		callback: validatePageQuality,
	} );
}

export default registerPageQualityValidatorAbility;
