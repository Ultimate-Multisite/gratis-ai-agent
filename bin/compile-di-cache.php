<?php

declare(strict_types=1);

/**
 * Compile the production DI container cache for a staged plugin directory.
 *
 * This build-time helper intentionally does not bootstrap WordPress. The
 * compiled container only needs the plugin's PHP-DI definitions, and all
 * install-specific values are runtime-resolved by factories in Plugin::configure().
 *
 * @package SdAiAgent
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This script must be run from the command line.\n" );
	exit( 1 );
}

/**
 * Recursively remove a directory if it exists.
 *
 * @param string $path Directory path.
 */
function sd_ai_agent_build_remove_dir( string $path ): void {
	if ( ! is_dir( $path ) ) {
		return;
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $iterator as $item ) {
		if ( $item->isDir() ) {
			rmdir( $item->getPathname() );
			continue;
		}

		unlink( $item->getPathname() );
	}

	rmdir( $path );
}

/**
 * Write an error message and stop the build.
 *
 * @param string $message Error message.
 */
function sd_ai_agent_build_fail( string $message ): never {
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}

$plugin_dir_arg = $argv[1] ?? '';
$version        = $argv[2] ?? '';

if ( '' === $plugin_dir_arg || '' === $version ) {
	sd_ai_agent_build_fail( 'Usage: php bin/compile-di-cache.php <plugin-dir> <version>' );
}

$plugin_dir = realpath( $plugin_dir_arg );

if ( false === $plugin_dir || ! is_dir( $plugin_dir ) ) {
	sd_ai_agent_build_fail( 'Plugin directory does not exist: ' . $plugin_dir_arg );
}

$autoload = $plugin_dir . '/vendor/autoload.php';
if ( ! is_file( $autoload ) ) {
	sd_ai_agent_build_fail( 'Composer autoload file is missing: ' . $autoload );
}

defined( 'ABSPATH' ) || define( 'ABSPATH', $plugin_dir . '/' );
defined( 'SD_AI_AGENT_VERSION' ) || define( 'SD_AI_AGENT_VERSION', $version );
defined( 'SD_AI_AGENT_DIR' ) || define( 'SD_AI_AGENT_DIR', $plugin_dir );
defined( 'SD_AI_AGENT_URL' ) || define( 'SD_AI_AGENT_URL', '' );

require_once $autoload;

$cache_root   = $plugin_dir . '/build/di-cache';
$compile_dir  = $cache_root . '/' . $version;
$compile_file = $compile_dir . '/CompiledContainerSdAiAgent.php';

sd_ai_agent_build_remove_dir( $cache_root );

if ( ! mkdir( $compile_dir, 0775, true ) && ! is_dir( $compile_dir ) ) {
	sd_ai_agent_build_fail( 'Could not create DI cache directory: ' . $compile_dir );
}

$builder = new DI\ContainerBuilder();
$builder->useAttributes( true );
$builder->useAutowiring( true );
$builder->enableCompilation( $compile_dir, 'CompiledContainerSdAiAgent' );
$builder->addDefinitions( SdAiAgent\Plugin::configure() );
$builder->build();

if ( ! is_file( $compile_file ) ) {
	sd_ai_agent_build_fail( 'Compiled DI cache file was not created: ' . $compile_file );
}

$compiled_contents = file_get_contents( $compile_file );
if ( false === $compiled_contents ) {
	sd_ai_agent_build_fail( 'Could not read compiled DI cache file: ' . $compile_file );
}

if ( str_contains( $compiled_contents, $plugin_dir ) ) {
	sd_ai_agent_build_fail( 'Compiled DI cache contains the staging path and is not install-portable.' );
}

$compiled_contents = preg_replace(
	'/^<\?php\s*/',
	"<?php\ndeclare(strict_types=1);\n\nif ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}\n\n",
	$compiled_contents,
	1
);

if ( ! is_string( $compiled_contents ) ) {
	sd_ai_agent_build_fail( 'Could not add the WordPress direct-access guard to the compiled DI cache.' );
}

if ( false === file_put_contents( $compile_file, $compiled_contents ) ) {
	sd_ai_agent_build_fail( 'Could not write guarded compiled DI cache file: ' . $compile_file );
}

fwrite( STDOUT, 'Compiled DI cache: ' . $compile_file . "\n" );
