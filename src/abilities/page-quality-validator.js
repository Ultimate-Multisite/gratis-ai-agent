/**
 * Lightweight registration for rendered page-quality validation.
 *
 * The inspector and screenshot stack are loaded only when the ability executes
 * so the floating chat widget's initial bundle remains within its size budget.
 */

import { registerClientAbility } from './registry';

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
				pages: { type: 'array', items: { type: 'object' } },
				hero_contract: { type: 'object' },
				viewports: { type: 'array', items: { type: 'object' } },
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
				reports: { type: 'array', items: { type: 'object' } },
				violations: { type: 'array', items: { type: 'object' } },
				warnings: { type: 'array', items: { type: 'object' } },
				screenshots: { type: 'array', items: { type: 'object' } },
				minimum_score: { type: 'number' },
			},
		},
		annotations: { readonly: true },
		callback: validatePageQuality,
	} );
}

export default registerPageQualityValidatorAbility;
