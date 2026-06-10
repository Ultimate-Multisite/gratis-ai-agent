/**
 * Unit tests for chat-redesign/ToolCard.js
 *
 * Tests cover resilient rendering of structured tool response metadata so
 * object-shaped summaries do not crash React while jobs are running.
 */

import { createElement } from '@wordpress/element';
import { renderToStaticMarkup } from 'react-dom/server.node';
import ToolCard from '../ToolCard';

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

jest.mock( '../../design-preview-gallery', () => () => null );

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
} );
