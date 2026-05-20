#!/usr/bin/env php
<?php
/**
 * Run WordPress PHPUnit without requiring wp-env.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

$plugin_dir = dirname(__DIR__);

/**
 * Read an environment variable, returning null for unset or empty values.
 *
 * @param string $name Environment variable name.
 * @return string|null
 */
function sd_ai_agent_env(string $name): ?string
{
	$value = getenv($name);

	if (false === $value || '' === $value) {
		return null;
	}

	return $value;
}

/**
 * Return the shared WordPress PHPUnit cache root.
 *
 * @return string
 */
function sd_ai_agent_phpunit_cache_root(): string
{
	$explicit = sd_ai_agent_env('WP_PHPUNIT_CACHE_DIR');
	if (null !== $explicit) {
		return rtrim($explicit, '/');
	}

	$xdg_cache_home = sd_ai_agent_env('XDG_CACHE_HOME');
	if (null !== $xdg_cache_home) {
		return rtrim($xdg_cache_home, '/') . '/wordpress-phpunit';
	}

	$home = sd_ai_agent_env('HOME');
	if (null !== $home) {
		return rtrim($home, '/') . '/.cache/wordpress-phpunit';
	}

	return rtrim(sys_get_temp_dir(), '/') . '/wordpress-phpunit';
}

/**
 * Convert a version string into a filesystem-safe path fragment.
 *
 * @param string $version WordPress version, branch, or tag.
 * @return string
 */
function sd_ai_agent_version_slug(string $version): string
{
	$slug = preg_replace('/[^A-Za-z0-9._-]+/', '-', $version);

	return trim((string) $slug, '-') ?: 'trunk';
}

/**
 * Resolve the configured PHPUnit binary.
 *
 * @param string $plugin_dir Plugin root directory.
 * @return string
 */
function sd_ai_agent_phpunit_binary(string $plugin_dir): string
{
	$explicit = sd_ai_agent_env('PHPUNIT_BIN');
	if (null !== $explicit) {
		return $explicit;
	}

	$project_phpunit = $plugin_dir . '/vendor/bin/phpunit';
	if (is_file($project_phpunit)) {
		return $project_phpunit;
	}

	return 'phpunit';
}

/**
 * Build a shell-safe command string from command parts.
 *
 * @param list<string> $parts Command arguments.
 * @return string
 */
function sd_ai_agent_shell_command(array $parts): string
{
	return implode(' ', array_map('escapeshellarg', $parts));
}

$wp_version  = sd_ai_agent_env('WP_VERSION') ?? 'trunk';
$version_key = sd_ai_agent_version_slug($wp_version);
$cache_root  = sd_ai_agent_phpunit_cache_root();
$tests_dir   = sd_ai_agent_env('WP_TESTS_DIR') ?? $cache_root . '/wordpress-tests-lib-' . $version_key;
$core_dir    = sd_ai_agent_env('WP_CORE_DIR') ?? $cache_root . '/wordpress-' . $version_key;
$polyfills   = sd_ai_agent_env('WP_TESTS_PHPUNIT_POLYFILLS_PATH') ?? $plugin_dir . '/vendor/yoast/phpunit-polyfills';

putenv('WP_TESTS_DIR=' . $tests_dir);
putenv('WP_CORE_DIR=' . $core_dir);

if (is_dir($polyfills)) {
	putenv('WP_TESTS_PHPUNIT_POLYFILLS_PATH=' . $polyfills);
}

if (! is_file($tests_dir . '/includes/functions.php')) {
	fwrite(
		STDERR,
		"WordPress test library not found at {$tests_dir}/includes/functions.php." . PHP_EOL
		. 'Run `npm run test:php:setup`, or set WP_TESTS_DIR to an existing wordpress-tests-lib checkout.' . PHP_EOL
		. 'Shared cache root: ' . $cache_root . PHP_EOL
	);
	exit(1);
}

if (! is_file($tests_dir . '/wp-tests-config.php')) {
	fwrite(
		STDERR,
		"WordPress test config not found at {$tests_dir}/wp-tests-config.php." . PHP_EOL
		. 'Run `npm run test:php:setup`, or create wp-tests-config.php for your local test database.' . PHP_EOL
	);
	exit(1);
}

$phpunit_bin = sd_ai_agent_phpunit_binary($plugin_dir);
$php_args    = preg_split('/\s+/', sd_ai_agent_env('PHPUNIT_PHP_ARGS') ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: array();
$phpunit_args = array_slice($argv, 1);

if (str_starts_with($phpunit_bin, $plugin_dir . '/vendor/bin/')) {
	$command = sd_ai_agent_shell_command(array_merge(array(PHP_BINARY), $php_args, array($phpunit_bin), $phpunit_args));
} else {
	$command = sd_ai_agent_shell_command(array_merge(array($phpunit_bin), $phpunit_args));
}

passthru($command, $exit_code);
exit((int) $exit_code);
