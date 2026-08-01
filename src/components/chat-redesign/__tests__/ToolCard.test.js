/**
 * Unit tests for chat-redesign/ToolCard.js
 *
 * Tests cover resilient rendering of structured tool response metadata so
 * object-shaped summaries do not crash React while jobs are running.
 */

import { createElement } from '@wordpress/element';
import { renderToStaticMarkup } from 'react-dom/server.node';
import ToolCard, { ToolResultHighlights } from '../ToolCard';

jest.mock( '@wordpress/i18n', () => ( {
	__: ( str ) => str,
} ) );

jest.mock( '@wordpress/icons', () => ( {
	Icon: ( { icon } ) => icon || null,
	check: 'check',
	chevronDown: 'chevronDown',
	undo: 'undo',
	caution: 'caution',
} ) );

jest.mock(
	'../../design-preview-gallery',
	() => () => 'Design preview gallery'
);

describe( 'ToolCard', () => {
	test( 'stringifies object-shaped response summaries before rendering', () => {
		const html = renderToStaticMarkup(
			createElement( ToolCard, {
				call: {
					id: 'tool-1',
					name: 'sd-ai-agent/get-page-blocks',
					args: { summary_only: true },
				},
				response: {
					id: 'tool-1',
					response: {
						summary: {
							block_counts: { 'core/paragraph': 2 },
							headings: [],
							section_markers: [],
							max_depth: 1,
						},
					},
				},
			} )
		);

		expect( html ).toContain( 'block_counts' );
		expect( html ).toContain( 'core/paragraph' );
	} );

	test( 'renders itemized validation errors and recovery hints', () => {
		const html = renderToStaticMarkup(
			createElement( ToolCard, {
				call: {
					id: 'tool-2',
					name: 'sd-ai-agent/update-blocks',
					args: {},
				},
				response: {
					id: 'tool-2',
					response: {
						error: 'One or more updates failed pre-flight validation.',
						details: {
							errors: [
								{
									index: 2,
									code: 'block_not_found',
									message: 'No block matched ref blk_stale.',
								},
							],
							recovery_hints: [
								'Re-run get-page-blocks before retrying.',
							],
						},
					},
				},
				defaultOpen: true,
			} )
		);

		expect( html ).toContain( 'Validation errors' );
		expect( html ).toContain( '#2 block_not_found:' );
		expect( html ).toContain( 'No block matched ref blk_stale.' );
		expect( html ).toContain( 'Re-run get-page-blocks before retrying.' );
	} );

	test( 'renders recovery hints without itemized validation errors', () => {
		const html = renderToStaticMarkup(
			createElement( ToolCard, {
				call: {
					id: 'tool-3',
					name: 'sd-ai-agent/update-blocks',
					args: {},
				},
				response: {
					id: 'tool-3',
					response: {
						error: 'The post has changed since it was read.',
						details: {
							recovery_hints: [
								'Re-run get-page-blocks with the current revision.',
							],
						},
					},
				},
				defaultOpen: true,
			} )
		);

		expect( html ).toContain( 'Recovery hints' );
		expect( html ).toContain(
			'Re-run get-page-blocks with the current revision.'
		);
		expect( html ).not.toContain( 'Validation errors' );
	} );

	test( 'renders design previews as standalone result highlights', () => {
		const html = renderToStaticMarkup(
			createElement( ToolResultHighlights, {
				call: {
					id: 'tool-4',
					name: 'sd-ai-agent/render-design-previews',
					args: {},
				},
				response: {
					id: 'tool-4',
					response: {
						design_previews: [
							{
								name: 'Design 1',
								html_url: 'https://example.test/design-1.html',
							},
						],
					},
				},
			} )
		);

		expect( html ).toContain( 'Design preview gallery' );
	} );

	test( 'renders a visible fallback link for completed navigate ability calls', () => {
		const html = renderToStaticMarkup(
			createElement( ToolResultHighlights, {
				call: {
					id: 'tool-5',
					name: 'sd-ai-agent/ability-call',
					args: { ability: 'sd-ai-agent/navigate' },
				},
				response: {
					id: 'tool-5',
					response: {
						url: 'https://example.test/',
					},
				},
			} )
		);

		expect( html ).toContain( 'href="https://example.test/"' );
		expect( html ).toContain( 'Open page' );
	} );
} );
