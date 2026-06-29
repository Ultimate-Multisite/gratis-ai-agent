/* eslint-disable no-console */

const { execFileSync } = require( 'child_process' );
const { copyFileSync, existsSync } = require( 'fs' );
const path = require( 'path' );
const pkg = require( '../package.json' );

const rootDir = path.resolve( __dirname, '..' );
const artifacts = [
	{
		versioned: `superdav-ai-agent-${ pkg.version }.zip`,
		alias: 'superdav-ai-agent.zip',
	},
	{
		versioned: `superdav-ai-agent-advanced-${ pkg.version }.zip`,
		alias: 'superdav-ai-agent-advanced.zip',
	},
];

try {
	execFileSync( 'bash', [ 'bin/build.sh', '--target=both' ], {
		cwd: rootDir,
		stdio: 'inherit',
	} );

	artifacts.forEach( ( { versioned, alias } ) => {
		const versionedPath = path.join( rootDir, versioned );
		const aliasPath = path.join( rootDir, alias );

		if ( ! existsSync( versionedPath ) ) {
			throw new Error(
				`Expected archive was not created: ${ versioned }`
			);
		}

		copyFileSync( versionedPath, aliasPath );
		console.log( `Archive created: ${ versioned }` );
		console.log( `Archive alias updated: ${ alias }` );
	} );
} catch ( error ) {
	console.error( 'Failed to create archive:', error.message );
	process.exit( 1 );
}
