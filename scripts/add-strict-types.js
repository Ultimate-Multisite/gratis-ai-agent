/**
 * Post-build script: inject PHP header protections into generated .asset.php files.
 *
 * wp-scripts generates .asset.php manifests that open with `<?php return array(...)`.
 * These committed files must comply with the project-wide PHP coding standards:
 *   1. `declare(strict_types=1);` after `<?php`
 *   2. Direct file access protection check
 *
 * Run automatically via the `postbuild` package script.
 */

const fs = require( 'fs' );
const path = require( 'path' );

const BUILD_DIR = path.join( __dirname, '..', 'build' );

/**
 * Inject protections into a generated .asset.php file.
 *
 * @param {string} filePath Absolute path to the .asset.php file.
 */
function addProtections( filePath ) {
	const content = fs.readFileSync( filePath, 'utf8' );

	// Already compliant — skip to avoid double declaration.
	if ( content.includes( 'declare(strict_types=1);' ) ) {
		return;
	}

	if ( ! content.startsWith( '<?php' ) ) {
		console.warn( `Skipping ${ filePath }: does not start with <?php` );
		return;
	}

	// Insert both: strict_types and ABSPATH check.
	// The raw wp-scripts output is: <?php return array(...)
	// We need to preserve that on the same line after the closing brace.
	const updated = content.replace(
		/^<\?php\s*?return array/,
		`<?php
declare(strict_types=1);

/**
 * Prevents direct access to the file.
 */
if ( ! defined( 'ABSPATH' ) ) {
\texit();
}
return array`
	);
	fs.writeFileSync( filePath, updated, 'utf8' );
	console.log( `Updated: ${ path.relative( process.cwd(), filePath ) }` );
}

// Process all .asset.php files in the build directory.
const assetFiles = fs
	.readdirSync( BUILD_DIR )
	.filter( ( f ) => f.endsWith( '.asset.php' ) )
	.map( ( f ) => path.join( BUILD_DIR, f ) );

if ( assetFiles.length === 0 ) {
	console.warn( 'No .asset.php files found in build/ — skipping.' );
	process.exit( 0 );
}

assetFiles.forEach( addProtections );
console.log( `Processed ${ assetFiles.length } .asset.php file(s).` );
