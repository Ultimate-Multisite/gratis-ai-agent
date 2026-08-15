#!/usr/bin/env node
/* eslint-env node */

const { spawnSync } = require( 'child_process' );

const args = process.argv.slice( 2 );

// pnpm forwards its script argument separator to the underlying command.
if ( '--' === args[ 0 ] ) {
	args.shift();
}

const executable = 'win32' === process.platform ? 'pnpm.cmd' : 'pnpm';
const result = spawnSync(
	executable,
	[ 'exec', 'wp-scripts', 'test-unit-js', ...args ],
	{
		stdio: 'inherit',
	}
);

if ( result.error ) {
	process.stderr.write( `${ result.error.message }\n` );
	process.exitCode = 1;
} else {
	process.exitCode = result.status ?? 1;
}
