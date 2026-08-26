#!/usr/bin/env php
<?php
/**
 * Provision a shared WordPress PHPUnit test library.
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
function sd_ai_agent_setup_env(string $name): ?string
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
function sd_ai_agent_setup_cache_root(): string
{
	$explicit = sd_ai_agent_setup_env('WP_PHPUNIT_CACHE_DIR');
	if (null !== $explicit) {
		return rtrim($explicit, '/');
	}

	$xdg_cache_home = sd_ai_agent_setup_env('XDG_CACHE_HOME');
	if (null !== $xdg_cache_home) {
		return rtrim($xdg_cache_home, '/') . '/wordpress-phpunit';
	}

	$home = sd_ai_agent_setup_env('HOME');
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
function sd_ai_agent_setup_version_slug(string $version): string
{
	$slug = preg_replace('/[^A-Za-z0-9._-]+/', '-', $version);

	return trim((string) $slug, '-') ?: 'trunk';
}

/**
 * Build a shell-safe environment prefix.
 *
 * @param array<string, string> $env Environment variables.
 * @return string
 */
function sd_ai_agent_setup_env_prefix(array $env): string
{
	$parts = array();

	foreach ($env as $name => $value) {
		$parts[] = $name . '=' . escapeshellarg($value);
	}

	return implode(' ', $parts);
}

/**
 * Build MySQL client arguments without forcing an empty password.
 *
 * @param string $db_user Database user.
 * @param string $db_pass Database password.
 * @param string $db_host Database host, optionally with port.
 * @return list<string>
 */
function sd_ai_agent_setup_mysql_args(string $db_user, string $db_pass, string $db_host): array
{
	$args = array('--user=' . $db_user);

	if ('' !== $db_pass) {
		$args[] = '--password=' . $db_pass;
	}

	if ('' !== $db_host) {
		$host_parts = explode(':', $db_host, 2);
		$args[]     = '--host=' . $host_parts[0];

		if (isset($host_parts[1]) && ctype_digit($host_parts[1])) {
			$args[] = '--port=' . $host_parts[1];
			$args[] = '--protocol=tcp';
		} elseif (isset($host_parts[1]) && '' !== $host_parts[1]) {
			$args[] = '--socket=' . $host_parts[1];
		}
	}

	return $args;
}

/**
 * Build a shell-safe command string from command parts.
 *
 * @param list<string> $parts Command arguments.
 * @return string
 */
function sd_ai_agent_setup_shell_command(array $parts): string
{
	return implode(' ', array_map('escapeshellarg', $parts));
}

/**
 * Read database connection values from an existing wp-tests-config.php file.
 *
 * The test installer writes this configuration once and leaves it in place on
 * later setup runs. Reusing every database setting keeps mysqladmin and the
 * WP-CLI-compatible core config aligned with the parent PHPUnit bootstrap.
 *
 * @param string $tests_dir WordPress test library directory.
 * @return array<string, string> Connection values keyed by DB constant name.
 */
function sd_ai_agent_setup_read_config_database(string $tests_dir): array
{
	$config_file = rtrim($tests_dir, '/') . '/wp-tests-config.php';
	if (! is_file($config_file)) {
		return array();
	}

	$content = file_get_contents($config_file);
	if (false === $content) {
		return array();
	}

	$database = array();
	foreach (array('DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST') as $constant) {
		$pattern = "/define\s*\(\s*'" . $constant . "'\s*,\s*'((?:\\\\.|[^'])*)'\s*\)/";
		if (preg_match($pattern, $content, $matches)) {
			$database[$constant] = str_replace(array("\\'", "\\\\"), array("'", "\\"), $matches[1]);
		}
	}

	return $database;
}

/**
 * Recreate the configured test database.
 *
 * @param string $db_name Database name.
 * @param string $db_user Database user.
 * @param string $db_pass Database password.
 * @param string $db_host Database host, optionally with port.
 * @return int Exit code.
 */
function sd_ai_agent_setup_database(string $db_name, string $db_user, string $db_pass, string $db_host): int
{
	$mysql_args = sd_ai_agent_setup_mysql_args($db_user, $db_pass, $db_host);
	$drop       = sd_ai_agent_setup_shell_command(array_merge(array('mysqladmin'), $mysql_args, array('drop', $db_name, '--force')));
	$create     = sd_ai_agent_setup_shell_command(array_merge(array('mysqladmin'), $mysql_args, array('create', $db_name)));

	passthru($drop, $drop_exit_code);
	passthru($create, $create_exit_code);

	return 0 === (int) $create_exit_code ? 0 : 1;
}

/**
 * Write a standard wp-config.php that WP-CLI can parse in the shared core tree.
 *
 * The WordPress test installer only provisions core files. This setup command
 * adds a config that directly loads wp-settings.php so WP-CLI can run tests
 * that exercise subprocess isolation through `wp eval-file`.
 *
 * @param string $core_dir WordPress core directory.
 * @param string $db_name  Database name.
 * @param string $db_user  Database user.
 * @param string $db_pass  Database password.
 * @param string $db_host  Database host, optionally with port.
 * @return bool
 */
function sd_ai_agent_setup_wp_config(string $core_dir, string $db_name, string $db_user, string $db_pass, string $db_host): bool
{
	$config = <<<'PHP'
<?php
/**
 * Local wp-config.php for shared WordPress PHPUnit runs.
 */

PHP;

	$config .= "define( 'DB_NAME', " . var_export($db_name, true) . " );\n";
	$config .= "define( 'DB_USER', " . var_export($db_user, true) . " );\n";
	$config .= "define( 'DB_PASSWORD', " . var_export($db_pass, true) . " );\n";
	$config .= "define( 'DB_HOST', " . var_export($db_host, true) . " );\n";
	$config .= "define( 'DB_CHARSET', 'utf8' );\n";
	$config .= "define( 'DB_COLLATE', '' );\n";
	$config .= "define( 'WP_DEBUG', true );\n";
	$config .= "\n\$table_prefix = 'wptests_';\n\n";
	$config .= "if ( ! defined( 'ABSPATH' ) ) {\n\tdefine( 'ABSPATH', __DIR__ . '/' );\n}\n\n";
	$config .= "require_once ABSPATH . 'wp-settings.php';\n";

	return false !== file_put_contents(rtrim($core_dir, '/') . '/wp-config.php', $config);
}

/**
 * Confirm that the shared file cache contains the minimum WordPress PHPUnit
 * contract before database setup begins.
 *
 * @param string $core_dir  WordPress core directory.
 * @param string $tests_dir WordPress test library directory.
 * @return bool
 */
function sd_ai_agent_setup_has_valid_file_cache(string $core_dir, string $tests_dir): bool
{
	return is_file(rtrim($core_dir, '/') . '/wp-settings.php')
		&& is_file(rtrim($tests_dir, '/') . '/includes/functions.php');
}

$wp_version  = sd_ai_agent_setup_env('WP_VERSION') ?? 'trunk';
$version_key = sd_ai_agent_setup_version_slug($wp_version);
$cache_root  = sd_ai_agent_setup_cache_root();
$tests_dir   = sd_ai_agent_setup_env('WP_TESTS_DIR') ?? $cache_root . '/wordpress-tests-lib-' . $version_key;
$core_dir    = sd_ai_agent_setup_env('WP_CORE_DIR') ?? $cache_root . '/wordpress-' . $version_key;

$test_database = sd_ai_agent_setup_read_config_database($tests_dir);
$db_name       = sd_ai_agent_setup_env('WP_TESTS_DB_NAME') ?? $test_database['DB_NAME'] ?? 'sd_ai_agent_tests';
$db_user       = sd_ai_agent_setup_env('WP_TESTS_DB_USER') ?? $test_database['DB_USER'] ?? 'root';
$db_pass       = sd_ai_agent_setup_env('WP_TESTS_DB_PASS') ?? $test_database['DB_PASSWORD'] ?? '';
$db_host       = sd_ai_agent_setup_env('WP_TESTS_DB_HOST') ?? $test_database['DB_HOST'] ?? 'localhost';
$skip_db = 'true' === strtolower(sd_ai_agent_setup_env('WP_TESTS_SKIP_DB_CREATE') ?? 'false');

$installer = $plugin_dir . '/bin/install-wp-tests.sh';
if (! is_file($installer)) {
	fwrite(STDERR, "Missing installer: {$installer}" . PHP_EOL);
	exit(1);
}

if (! is_dir($cache_root) && ! mkdir($cache_root, 0777, true) && ! is_dir($cache_root)) {
	fwrite(STDERR, "Could not create cache directory: {$cache_root}" . PHP_EOL);
	exit(1);
}

$env = array(
	'WP_PHPUNIT_CACHE_DIR' => $cache_root,
	'WP_TESTS_DIR'         => $tests_dir,
	'WP_CORE_DIR'          => $core_dir,
);

$command = sd_ai_agent_setup_env_prefix($env)
	. ' bash ' . escapeshellarg($installer)
	. ' ' . escapeshellarg($db_name)
	. ' ' . escapeshellarg($db_user)
	. ' ' . escapeshellarg($db_pass)
	. ' ' . escapeshellarg($db_host)
	. ' ' . escapeshellarg($wp_version)
	. ' ' . escapeshellarg('true');

fwrite(STDOUT, 'Provisioning shared WordPress PHPUnit files:' . PHP_EOL);
fwrite(STDOUT, '- WordPress core: ' . $core_dir . PHP_EOL);
fwrite(STDOUT, '- Test library:   ' . $tests_dir . PHP_EOL);
fwrite(STDOUT, '- Test database:  ' . $db_name . ' @ ' . $db_host . PHP_EOL);

passthru($command, $exit_code);
if (0 !== (int) $exit_code) {
	exit((int) $exit_code);
}

if (! sd_ai_agent_setup_has_valid_file_cache($core_dir, $tests_dir)) {
	fwrite(
		STDERR,
		'WordPress PHPUnit cache is incomplete after provisioning. Expected wp-settings.php and includes/functions.php; rerun `pnpm run test:php:setup` to rebuild it.' . PHP_EOL
	);
	exit(1);
}

if (! sd_ai_agent_setup_wp_config($core_dir, $db_name, $db_user, $db_pass, $db_host)) {
	fwrite(STDERR, 'Could not write WP-CLI-compatible wp-config.php in the shared WordPress core directory.' . PHP_EOL);
	exit(1);
}

if (! $skip_db) {
	$database_exit_code = sd_ai_agent_setup_database($db_name, $db_user, $db_pass, $db_host);
	if (0 !== $database_exit_code) {
		fwrite(
			STDERR,
			'Could not create the PHPUnit test database. Set WP_TESTS_DB_* variables or run with WP_TESTS_SKIP_DB_CREATE=true after creating the database manually.' . PHP_EOL
		);
		exit($database_exit_code);
	}
}

exit(0);
