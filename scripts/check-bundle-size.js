#!/usr/bin/env node
/* eslint-env node */
/* eslint-disable no-console */

const fs = require( 'fs' );
const path = require( 'path' );
const zlib = require( 'zlib' );

const KIB = 1024;
const BUILD_DIR = 'build';
const ENTRYPOINT_JAVASCRIPT = new Set( [
	'admin-page.js',
	'block-validator.js',
	'embed-widget.js',
	'floating-widget.js',
	'superdav-connector-card.js',
	'unified-admin.js',
] );
const BUDGETS = [
	{
		name: 'floating-widget',
		file: 'build/floating-widget.js',
		minifiedBudgetKiB: 100,
		gzipBudgetKiB: 28,
	},
	{
		name: 'widget-panel',
		file: 'build/widget-panel.js',
		minifiedBudgetKiB: 100,
		gzipBudgetKiB: 28,
	},
];
const DEFERRED_JAVASCRIPT_BUDGET = {
	name: 'deferred-javascript',
	minifiedBudgetKiB: 1700,
	gzipBudgetKiB: 525,
	largestMinifiedBudgetKiB: 270,
	largestGzipBudgetKiB: 90,
};

/**
 * @param {number} bytes Byte count.
 * @return {string} Kibibytes formatted to one decimal place.
 */
function formatKiB( bytes ) {
	return ( bytes / KIB ).toFixed( 1 );
}

/**
 * @param {Object} budget Named entrypoint budget.
 * @return {Object} Measured bundle and failures.
 */
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
			[
				'minified',
				`${ formatKiB( source.length ) } KiB exceeds`,
				`${ budget.minifiedBudgetKiB } KiB`,
			].join( ' ' )
		);
	}

	if ( gzipKiB > budget.gzipBudgetKiB ) {
		failures.push(
			[
				'gzip',
				`${ gzipKiB.toFixed( 1 ) } KiB exceeds`,
				`${ budget.gzipBudgetKiB } KiB`,
			].join( ' ' )
		);
	}

	return {
		...budget,
		minifiedKiB,
		gzipKiB,
		failures,
	};
}

/**
 * Guard the complete deferred JavaScript graph, including numeric webpack
 * chunks whose filenames are not stable across dependency changes.
 *
 * Aggregate gzip is the sum of individually compressed assets because browsers
 * download chunks as separate responses. Largest minified and gzip assets are
 * tracked independently because they need not be the same file.
 *
 * @param {Object} budget Deferred graph budget.
 * @return {Object} Measured deferred graph and failures.
 */
function checkDeferredJavaScript( budget ) {
	const buildPath = path.resolve( process.cwd(), BUILD_DIR );
	if ( ! fs.existsSync( buildPath ) ) {
		throw new Error( `${ budget.name }: missing build directory` );
	}

	const files = fs
		.readdirSync( buildPath )
		.filter(
			( file ) =>
				file.endsWith( '.js' ) && ! ENTRYPOINT_JAVASCRIPT.has( file )
		)
		.sort();
	if ( files.length === 0 ) {
		throw new Error(
			`${ budget.name }: no deferred JavaScript assets found`
		);
	}

	const assets = files.map( ( file ) => {
		const source = fs.readFileSync( path.join( buildPath, file ) );
		return {
			file,
			minifiedBytes: source.length,
			gzipBytes: zlib.gzipSync( source ).length,
		};
	} );
	const minifiedBytes = assets.reduce(
		( total, asset ) => total + asset.minifiedBytes,
		0
	);
	const gzipBytes = assets.reduce(
		( total, asset ) => total + asset.gzipBytes,
		0
	);
	const largestMinified = assets.reduce( ( largest, asset ) =>
		asset.minifiedBytes > largest.minifiedBytes ? asset : largest
	);
	const largestGzip = assets.reduce( ( largest, asset ) =>
		asset.gzipBytes > largest.gzipBytes ? asset : largest
	);
	const failures = [];

	if ( minifiedBytes / KIB > budget.minifiedBudgetKiB ) {
		failures.push(
			[
				'aggregate minified',
				`${ formatKiB( minifiedBytes ) } KiB exceeds`,
				`${ budget.minifiedBudgetKiB } KiB`,
			].join( ' ' )
		);
	}
	if ( gzipBytes / KIB > budget.gzipBudgetKiB ) {
		failures.push(
			[
				'aggregate gzip',
				`${ formatKiB( gzipBytes ) } KiB exceeds`,
				`${ budget.gzipBudgetKiB } KiB`,
			].join( ' ' )
		);
	}
	if (
		largestMinified.minifiedBytes / KIB >
		budget.largestMinifiedBudgetKiB
	) {
		failures.push(
			[
				`largest minified asset ${ largestMinified.file } is`,
				`${ formatKiB(
					largestMinified.minifiedBytes
				) } KiB, exceeding`,
				`${ budget.largestMinifiedBudgetKiB } KiB`,
			].join( ' ' )
		);
	}
	if ( largestGzip.gzipBytes / KIB > budget.largestGzipBudgetKiB ) {
		failures.push(
			[
				`largest gzip asset ${ largestGzip.file } is`,
				`${ formatKiB( largestGzip.gzipBytes ) } KiB, exceeding`,
				`${ budget.largestGzipBudgetKiB } KiB`,
			].join( ' ' )
		);
	}

	return {
		...budget,
		assetCount: assets.length,
		minifiedKiB: minifiedBytes / KIB,
		gzipKiB: gzipBytes / KIB,
		largestMinified,
		largestGzip,
		failures,
	};
}

/**
 * Print all bundle measurements and set a failing process exit code when a
 * budget is exceeded.
 */
function main() {
	const results = BUDGETS.map( checkBundle );
	const deferredResult = checkDeferredJavaScript(
		DEFERRED_JAVASCRIPT_BUDGET
	);
	let failed = false;

	for ( const result of results ) {
		const status = result.failures.length > 0 ? 'FAIL' : 'OK';
		const summary = [
			`${ status } ${ result.name }:`,
			`${ result.minifiedKiB.toFixed( 1 ) } KiB minified`,
			`(budget ${ result.minifiedBudgetKiB } KiB),`,
			`${ result.gzipKiB.toFixed( 1 ) } KiB gzip`,
			`(budget ${ result.gzipBudgetKiB } KiB)`,
		].join( ' ' );
		console.log( summary );

		if ( result.failures.length > 0 ) {
			failed = true;
			for ( const failure of result.failures ) {
				console.error( `  - ${ failure }` );
			}
		}
	}

	const deferredStatus = deferredResult.failures.length > 0 ? 'FAIL' : 'OK';
	const deferredSummary = [
		`${ deferredStatus } ${ deferredResult.name }:`,
		`${ deferredResult.assetCount } assets,`,
		`${ deferredResult.minifiedKiB.toFixed( 1 ) } KiB aggregate minified`,
		`(budget ${ deferredResult.minifiedBudgetKiB } KiB),`,
		`${ deferredResult.gzipKiB.toFixed( 1 ) } KiB aggregate gzip`,
		`(budget ${ deferredResult.gzipBudgetKiB } KiB)`,
	].join( ' ' );
	const largestSummary = [
		`  largest minified: ${ deferredResult.largestMinified.file }`,
		`${ formatKiB(
			deferredResult.largestMinified.minifiedBytes
		) } KiB (budget ${ deferredResult.largestMinifiedBudgetKiB } KiB);`,
		`largest gzip: ${ deferredResult.largestGzip.file }`,
		`${ formatKiB( deferredResult.largestGzip.gzipBytes ) } KiB`,
		`(budget ${ deferredResult.largestGzipBudgetKiB } KiB)`,
	].join( ' ' );
	console.log( deferredSummary );
	console.log( largestSummary );
	if ( deferredResult.failures.length > 0 ) {
		failed = true;
		for ( const failure of deferredResult.failures ) {
			console.error( `  - ${ failure }` );
		}
	}

	if ( failed ) {
		process.exitCode = 1;
	}
}

main();
