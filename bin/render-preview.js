#!/usr/bin/env node
/**
 * bin/render-preview.js — Headless screenshot helper for the Theme Builder.
 *
 * Captures a PNG screenshot of a local HTML file at a given viewport size
 * using Playwright's bundled Chromium. Called by PreviewRenderer.php when
 * server-side rendering is requested.
 *
 * Usage:
 *   node bin/render-preview.js \
 *     --html /abs/path/to/preview.html \
 *     --output /abs/path/to/output.png \
 *     --width 1280 \
 *     --height 800
 *
 * Exits 0 on success, 1 on failure (error written to stderr).
 *
 * Requires @playwright/test (devDependency) or playwright (optional dep).
 * If neither is available, exits with code 1 so PreviewRenderer.php falls
 * back to client-side iframe display.
 */

'use strict';

const path = require( 'path' );

/**
 * Parse a named flag from argv.
 *
 * @param {string[]} args - argv slice.
 * @param {string}   flag - Flag name including leading dashes.
 * @return {string|null} Flag value, or null.
 */
function getArg( args, flag ) {
	const idx = args.indexOf( flag );
	return idx !== -1 ? args[ idx + 1 ] || null : null;
}

async function main() {
	const args = process.argv.slice( 2 );

	const htmlPath = getArg( args, '--html' );
	const outPath  = getArg( args, '--output' );
	const width    = parseInt( getArg( args, '--width' )  || '1280', 10 );
	const height   = parseInt( getArg( args, '--height' ) || '800',  10 );

	if ( ! htmlPath || ! outPath ) {
		process.stderr.write(
			'Usage: render-preview.js --html <path> --output <path> [--width N] [--height N]\n'
		);
		process.exit( 1 );
	}

	// Resolve playwright — try the installed devDep first, then the
	// optional standalone package. Exit cleanly if neither is present.
	let chromium;
	try {
		( { chromium } = require( '@playwright/test' ) );
	} catch {
		try {
			( { chromium } = require( 'playwright' ) );
		} catch {
			process.stderr.write( 'Playwright not available. Install @playwright/test or playwright.\n' );
			process.exit( 1 );
		}
	}

	let browser;
	try {
		browser = await chromium.launch( { headless: true } );
		const context = await browser.newContext();
		const page    = await context.newPage();

		await page.setViewportSize( { width, height } );

		// Use file:// protocol for the local HTML file.
		const fileUrl = 'file://' + path.resolve( htmlPath );
		await page.goto( fileUrl, { waitUntil: 'networkidle', timeout: 30_000 } );

		await page.screenshot( { path: outPath, type: 'png', fullPage: false } );
	} catch ( err ) {
		process.stderr.write( 'Screenshot failed: ' + err.message + '\n' );
		process.exit( 1 );
	} finally {
		if ( browser ) {
			await browser.close();
		}
	}

	process.exit( 0 );
}

main().catch( ( err ) => {
	process.stderr.write( 'Unexpected error: ' + err.message + '\n' );
	process.exit( 1 );
} );
