const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		'admin-page': path.resolve(
			process.cwd(),
			'src/admin-page',
			'index.js'
		),
		'floating-widget': path.resolve(
			process.cwd(),
			'src/floating-widget',
			'index.js'
		),
		'unified-admin': path.resolve(
			process.cwd(),
			'src/unified-admin',
			'index.js'
		),
		'block-validator': path.resolve(
			process.cwd(),
			'src/block-validator',
			'index.js'
		),
	},
};
