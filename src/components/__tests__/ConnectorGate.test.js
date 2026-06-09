/**
 * Unit tests for components/connector-gate.js
 *
 * Tests cover:
 * - Renders the gate wrapper
 * - Renders the title
 * - Renders the description
 * - Renders the managed Superdav AI CTA
 * - Renders the secondary link pointing to official Connectors page
 * - Uses custom connectorsUrl from window.sdAiAgentData when available
 * - Falls back to official Connectors page URL when connectorsUrl is not set
 * - Shows Gutenberg install prompt when connectorsAvailable is false
 */

import { createElement } from '@wordpress/element';
import { renderToStaticMarkup } from 'react-dom/server.node';
import ConnectorGate from '../connector-gate';

// ─── Mock @wordpress/i18n ─────────────────────────────────────────────────────

jest.mock( '@wordpress/i18n', () => ( {
	__: ( str ) => str,
} ) );

// ─── Mock @wordpress/components ──────────────────────────────────────────────

jest.mock( '@wordpress/components', () => {
	const React = require( 'react' );
	return {
		Button: ( { children, href, className, variant } ) =>
			React.createElement(
				'a',
				{ href, className, 'data-variant': variant },
				children
			),
		Notice: ( { children, status, isDismissible } ) =>
			React.createElement(
				'div',
				{
					'data-testid': 'notice',
					'data-status': status,
					'data-dismissible': isDismissible,
				},
				children
			),
	};
} );

// ─── Tests ────────────────────────────────────────────────────────────────────

describe( 'ConnectorGate', () => {
	afterEach( () => {
		// Restore window.sdAiAgentData between tests.
		delete window.sdAiAgentData;
	} );

	test( 'renders the gate wrapper', () => {
		const html = renderToStaticMarkup( createElement( ConnectorGate, {} ) );
		expect( html ).toContain( 'sdaa-connector-gate' );
	} );

	test( 'renders the title', () => {
		const html = renderToStaticMarkup( createElement( ConnectorGate, {} ) );
		expect( html ).toContain( 'Connect Superdav AI' );
	} );

	test( 'renders descriptive text about connectors', () => {
		const html = renderToStaticMarkup( createElement( ConnectorGate, {} ) );
		expect( html ).toContain( 'recommended managed connection' );
	} );

	test( 'renders managed Superdav AI CTA button when connectors available', () => {
		window.sdAiAgentData = { connectorsAvailable: '1' };
		const html = renderToStaticMarkup( createElement( ConnectorGate, {} ) );
		expect( html ).toContain( 'Connect Superdav AI' );
	} );

	test( 'renders secondary connector choice when connectors available', () => {
		window.sdAiAgentData = { connectorsAvailable: '1' };
		const html = renderToStaticMarkup( createElement( ConnectorGate, {} ) );
		expect( html ).toContain( 'Choose another connector' );
	} );

	test( 'CTA button links to official Connectors page when available', () => {
		window.sdAiAgentData = { connectorsAvailable: '1' };
		const html = renderToStaticMarkup( createElement( ConnectorGate, {} ) );
		expect( html ).toContain(
			'options-general.php?page=options-connectors-wp-admin'
		);
	} );

	test( 'uses connectorsUrl from window.sdAiAgentData when available', () => {
		window.sdAiAgentData = {
			connectorsAvailable: '1',
			connectorsUrl: 'admin.php?page=custom-connectors',
		};
		const html = renderToStaticMarkup( createElement( ConnectorGate, {} ) );
		expect( html ).toContain( 'admin.php?page=custom-connectors' );
	} );

	test( 'renders info notice when connectors available', () => {
		window.sdAiAgentData = { connectorsAvailable: '1' };
		const html = renderToStaticMarkup( createElement( ConnectorGate, {} ) );
		const notice = html.match( /data-status="info"/ );
		expect( notice ).not.toBeNull();
	} );

	test( 'shows Gutenberg install button when connectorsAvailable is falsy', () => {
		window.sdAiAgentData = { connectorsAvailable: '' };
		const html = renderToStaticMarkup( createElement( ConnectorGate, {} ) );
		expect( html ).toContain( 'Install &amp; Activate Gutenberg' );
	} );

	test( 'shows Gutenberg install button when connectorsAvailable not set', () => {
		const html = renderToStaticMarkup( createElement( ConnectorGate, {} ) );
		expect( html ).toContain( 'Install &amp; Activate Gutenberg' );
	} );

	test( 'shows warning notice when connectorsAvailable is falsy', () => {
		window.sdAiAgentData = { connectorsAvailable: '' };
		const html = renderToStaticMarkup( createElement( ConnectorGate, {} ) );
		expect( html ).toContain( 'data-status="warning"' );
		expect( html ).toContain( 'Gutenberg plugin' );
	} );
} );
