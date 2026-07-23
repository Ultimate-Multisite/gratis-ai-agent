#!/usr/bin/env node

/**
 * Capture real, scrubbed WordPress.org listing screenshots from a local site.
 *
 * Usage:
 * WP_BASE_URL=https://example.test node scripts/capture-wporg-screenshots.js
 *
 * The shared E2E login helper reads WP_ADMIN_USER and WP_ADMIN_PASSWORD when
 * supplied. Its local test defaults are used when those variables are absent.
 */

const path = require( 'path' );
const { chromium } = require( '@playwright/test' );
const {
	loginToWordPress,
	goToAgentPage,
	goToSettingsPage,
} = require( '../tests/e2e/utils/wp-admin' );

const baseURL = process.env.WP_BASE_URL;
const outputDirectory = path.resolve( __dirname, '../.wordpress-org/assets' );

if ( ! baseURL ) {
	throw new Error( 'Set WP_BASE_URL to the local WordPress test site URL.' );
}

/**
 * Log in to the configured local site and write the listing screenshots.
 *
 * @return {Promise<void>} Resolves after the browser closes.
 */
async function capture() {
	const browser = await chromium.launch( { headless: true } );
	const page = await browser.newPage( {
		viewport: { width: 1440, height: 960 },
		baseURL,
	} );

	try {
		await loginToWordPress( page );

		await goToAgentPage( page );
		await page.screenshot( {
			path: path.join( outputDirectory, 'screenshot-1.png' ),
			fullPage: false,
		} );

		await goToSettingsPage( page, 'tools' );
		await page.screenshot( {
			path: path.join( outputDirectory, 'screenshot-2.png' ),
			fullPage: false,
		} );

		await goToSettingsPage( page, 'general' );
		await page.screenshot( {
			path: path.join( outputDirectory, 'screenshot-3.png' ),
			fullPage: false,
		} );
	} finally {
		await browser.close();
	}
}

capture().catch( ( error ) => {
	process.stderr.write( `${ error.stack || error.message }\n` );
	process.exitCode = 1;
} );
