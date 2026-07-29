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
	getMessageInput,
	getSendButton,
} = require( '../tests/e2e/utils/wp-admin' );

const baseURL = process.env.WP_BASE_URL;
const outputDirectory = path.resolve( __dirname, '../.wordpress-org/assets' );
const capturePrompt =
	process.env.WP_ORG_CAPTURE_PROMPT ||
	"Review this site's health and list the first three safe improvements you would make.";

if ( ! baseURL ) {
	throw new Error( 'Set WP_BASE_URL to the local WordPress test site URL.' );
}

/**
 * Log in to the configured local site and write the listing screenshots.
 *
 * @return {Promise<void>} Resolves after the browser closes.
 */
async function capture() {
	let browser;

	try {
		browser = await chromium.launch( { headless: true } );
		const page = await browser.newPage( {
			viewport: { width: 1440, height: 960 },
			baseURL,
		} );

		await loginToWordPress( page );

		await goToAgentPage( page );
		await getMessageInput( page ).fill( capturePrompt );
		await getSendButton( page ).click();
		await page.waitForTimeout( 15_000 );
		await page.locator( '#sdaa-root' ).screenshot( {
			path: path.join( outputDirectory, 'screenshot-1.png' ),
		} );

		await goToSettingsPage( page, 'tools' );
		await page.locator( '.sdaa-route-settings' ).screenshot( {
			path: path.join( outputDirectory, 'screenshot-2.png' ),
		} );

		await goToSettingsPage( page, 'general' );
		await page.locator( '.sdaa-route-settings' ).evaluate( ( settings ) => {
			settings.style.height = '720px';
			settings.style.overflow = 'hidden';
		} );
		await page.locator( '.sdaa-route-settings' ).screenshot( {
			path: path.join( outputDirectory, 'screenshot-3.png' ),
		} );
	} finally {
		if ( browser ) {
			await browser.close();
		}
	}
}

capture().catch( ( error ) => {
	process.stderr.write( `${ error.stack || error.message }\n` );
	process.exitCode = 1;
} );
