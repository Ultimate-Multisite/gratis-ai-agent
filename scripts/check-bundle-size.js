#!/usr/bin/env node
/* eslint-env node */

const fs = require( 'fs' );
const path = require( 'path' );
const zlib = require( 'zlib' );

const KIB = 1024;
const BUDGETS = [
	{
		name: 'floating-widget',
		file: 'build/floating-widget.js',
		minifiedBudgetKiB: 87,
		gzipBudgetKiB: 28,
	},
	{
		name: 'widget-panel',
		file: 'build/widget-panel.js',
		minifiedBudgetKiB: 220,
		gzipBudgetKiB: 70,
	},
];

function formatKiB( bytes ) {
	return ( bytes / KIB ).toFixed( 1 );
}

function checkBundle( budget ) {
	const filePath = path.resolve( process.cwd(), budget.file );

	if ( ! fs.existsSync( filePath ) ) {
		throw new Error( `${ budget.name }: missing bundle ${ budget.file }` );
	}

	const source = fs.readFileSync( filePath );
	const minifiedKiB = source.length / KIB;
	const gzipKiB = zlib.gzipSync( source ).length / KIB;
	const failures = [];

	if ( minifiedKiB > budget.minifiedBudgetKiB ) {
		failures.push(
			`minified ${ formatKiB( source.length ) } KiB exceeds ${ budget.minifiedBudgetKiB } KiB`
		);
	}

	if ( gzipKiB > budget.gzipBudgetKiB ) {
		failures.push(
			`gzip ${ gzipKiB.toFixed( 1 ) } KiB exceeds ${ budget.gzipBudgetKiB } KiB`
		);
	}

	return {
		...budget,
		minifiedKiB,
		gzipKiB,
		failures,
	};
}

function main() {
	const results = BUDGETS.map( checkBundle );
	let failed = false;

	for ( const result of results ) {
		const status = result.failures.length > 0 ? 'FAIL' : 'OK';
		console.log(
			`${ status } ${ result.name }: ${ result.minifiedKiB.toFixed( 1 ) } KiB minified (budget ${ result.minifiedBudgetKiB } KiB), ${ result.gzipKiB.toFixed( 1 ) } KiB gzip (budget ${ result.gzipBudgetKiB } KiB)`
		);

		if ( result.failures.length > 0 ) {
			failed = true;
			for ( const failure of result.failures ) {
				console.error( `  - ${ failure }` );
			}
		}
	}

	if ( failed ) {
		process.exitCode = 1;
	}
}

main();
