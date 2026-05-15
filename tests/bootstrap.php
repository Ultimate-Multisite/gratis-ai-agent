<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

// Load standard Composer autoloader (not Jetpack).
// Jetpack Autoloader requires WordPress functions, so we use the standard autoloader for tests.
$plugin_dir = dirname( __DIR__ );
require_once $plugin_dir . '/vendor/autoload.php';

/*
 * Force XWP_Context to CTX_REST so the x-wp/di container loads REST handlers.
 *
 * Problem: the WP test bootstrap defines WP_ADMIN=true before loading
 * wp-settings.php. XWP_Context::get() uses a match(true) that checks
 * admin() BEFORE rest(), so the context resolves to Admin (2) — silently
 * skipping every CTX_REST (16) handler and producing 404 on all REST route
 * tests. Setting $_SERVER['REQUEST_URI'] to /wp-json/... doesn't help because
 * the admin() branch short-circuits before rest() is evaluated.
 *
 * Fix: pre-set the private static XWP_Context::$current via reflection BEFORE
 * WordPress boots. The ??= assignment in get() preserves our value, so the
 * match expression is never reached. This runs at file-scope before the WP
 * test bootstrap is even loaded — no hook ordering issues.
 *
 * Trade-off: setting CTX_REST globally means handlers gated on CTX_ADMIN,
 * CTX_CLI, CTX_CRON, or CTX_FRONTEND will silently not register during the
 * full test run. In practice, almost all of our tested code paths go through
 * REST routes, so the risk of masking non-REST regressions is low today.
 * Future improvement: introduce a WpContextTestTrait that resets $current
 * per-test, allowing individual test classes to declare their target context
 * without affecting the global bootstrap. Tracked in t197 test-infrastructure
 * improvements.
 */
( static function (): void {
	$refl = new ReflectionProperty( XWP_Context::class, 'current' );
	$refl->setValue( null, XWP_Context::REST );
} )();

$_tests_dir = getenv('WP_TESTS_DIR');
if ( ! $_tests_dir ) {
	// wp-env places the test suite at /wordpress-phpunit.
	if ( file_exists( '/wordpress-phpunit/includes/functions.php' ) ) {
		$_tests_dir = '/wordpress-phpunit';
	} else {
		$_tests_dir = rtrim(sys_get_temp_dir(), '/\\') . '/wordpress-tests-lib';
	}
}

// Forward custom PHPUnit Polyfills configuration to PHPUnit bootstrap file.
// Auto-detect from Composer vendor directory if not set via env var.
$_phpunit_polyfills_path = getenv('WP_TESTS_PHPUNIT_POLYFILLS_PATH');
if ( false === $_phpunit_polyfills_path ) {
	$_phpunit_polyfills_path = $plugin_dir . '/vendor/yoast/phpunit-polyfills';
}
if ( is_dir( $_phpunit_polyfills_path ) ) {
	define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path);
}

if ( ! file_exists("{$_tests_dir}/includes/functions.php") ) {
	echo "Could not find {$_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit(1);
}

// Give access to tests_add_filter() function.
require_once "{$_tests_dir}/includes/functions.php";

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	require dirname(__DIR__) . '/superdav-ai-agent.php';

	// Install database tables (normally done on activation).
	// Database::install() includes KnowledgeDatabase schema.
	SdAiAgent\Core\Database::install();
}

tests_add_filter('muplugins_loaded', '_manually_load_plugin');

/*
 * WP 7.0 Abilities API in-memory polyfill (loaded before WP core boots).
 *
 * WordPress trunk (as of 2026-05) ships wp_register_ability() and related
 * functions as empty stubs. Internally they use did_action() checks that
 * reject registrations made after wp_abilities_api_init has fired in a
 * previous test, so wp_get_ability() always returns null for test-registered
 * abilities.
 *
 * Loading guarded definitions HERE — before WP core's includes/bootstrap.php
 * runs wp-settings.php — lets our simple in-memory implementation take
 * precedence via WP's own function_exists() / class_exists() guards. When WP
 * ships a fully-functional Abilities API those guards will skip this block.
 *
 * Note: WP_UnitTestCase does not reset arbitrary PHP globals between tests.
 * Abilities registered in one test persist into later tests in the same run.
 * ThirdPartyAbilityNoticeHandlerTest::test_namespace_decision_overrides_heuristic
 * (the last test in that class) is the only test that writes to the registry,
 * so cross-class contamination risk is low for the current suite. If it
 * becomes an issue, add `$_wp_ability_registry = [];` to the affected
 * tear_down() method.
 */
// Start up the WP testing environment.
require "{$_tests_dir}/includes/bootstrap.php";

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
/*
 * WP 7.0 Abilities API in-memory polyfill (loaded AFTER WP core boots).
 *
 * WordPress trunk (as of 2026-05) ships wp_register_ability() and related
 * functions as empty stubs. Internally they use did_action() checks that
 * reject registrations made after wp_abilities_api_init has fired in a
 * previous test, so wp_get_ability() always returns null for test-registered
 * abilities.
 *
 * Loading guarded definitions HERE — after WP core's includes/bootstrap.php
 * runs wp-settings.php — lets our simple in-memory implementation take
 * precedence via WP's own function_exists() / class_exists() guards. When WP
 * ships a fully-functional Abilities API those guards will skip this block.
 *
 * Note: WP_UnitTestCase does not reset arbitrary PHP globals between tests.
 * Abilities registered in one test persist into later tests in the same run.
 * ThirdPartyAbilityNoticeHandlerTest::test_namespace_decision_overrides_heuristic
 * (the last test in that class) is the only test that writes to the registry,
 * so cross-class contamination risk is low for the current suite. If it
 * becomes an issue, add `$_wp_ability_registry = [];` to the affected
 * tear_down() method.
 */
if ( ! class_exists( 'WP_Ability' ) ) {
	/**
	 * WP 7.0 Ability class polyfill.
	 *
	 * @since 7.0.0
	 */
	class WP_Ability {
		/** @var string */
		public string $name = '';
		/** @var array<string, mixed> */
		private array $args = array();
		/**
		 * @param string               $name Ability name.
		 * @param array<string, mixed> $args Ability args.
		 */
		public function __construct( string $name, array $args = array() ) {
			$this->name = $name;
			$this->args = $args;
		}
		/** @return string */
		public function get_name(): string { return $this->name; }
		/** @return string */
		public function get_label(): string { return (string) ( $this->args['label'] ?? '' ); }
		/** @return string */
		public function get_description(): string { return (string) ( $this->args['description'] ?? '' ); }
		/** @return array<string, mixed> */
		public function get_params(): array { return (array) ( $this->args['params'] ?? array() ); }
		/** @return array<string, mixed> */
		public function get_input_schema(): array { return (array) ( $this->args['input_schema'] ?? array() ); }
		/** @return array<string, mixed> */
		public function get_output_schema(): array { return (array) ( $this->args['output_schema'] ?? array() ); }
		/** @return string */
		public function get_category(): string { return (string) ( $this->args['category'] ?? '' ); }
		/** @return array<string, mixed> */
		public function get_meta(): array { return (array) ( $this->args['meta'] ?? array() ); }
		/** @return mixed */
		public function call( array $params ): mixed { return null; }
		/** @return mixed|\WP_Error */
		public function execute( ?array $args ): mixed { return null; }
		/** @return true|\WP_Error */
		public function validate_input( mixed $input ): true|\WP_Error { return true; }
	}
}

global $_wp_ability_registry;
if ( ! isset( $_wp_ability_registry ) ) {
	$_wp_ability_registry = array();
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	/**
	 * @param string               $name
	 * @param array<string, mixed> $args
	 * @return WP_Ability|null
	 */
	function wp_register_ability( string $name, array $args ): ?WP_Ability {
		global $_wp_ability_registry;
		$ability                       = new WP_Ability( $name, $args );
		$_wp_ability_registry[ $name ] = $ability;
		return $ability;
	}
}

if ( ! function_exists( 'wp_unregister_ability' ) ) {
	/**
	 * @param string $name
	 * @return WP_Ability|null
	 */
	function wp_unregister_ability( string $name ): ?WP_Ability {
		global $_wp_ability_registry;
		$ability = $_wp_ability_registry[ $name ] ?? null;
		unset( $_wp_ability_registry[ $name ] );
		return $ability;
	}
}

if ( ! function_exists( 'wp_get_ability' ) ) {
	/**
	 * @param string $name
	 * @return WP_Ability|null
	 */
	function wp_get_ability( string $name ): ?WP_Ability {
		global $_wp_ability_registry;
		return $_wp_ability_registry[ $name ] ?? null;
	}
}

if ( ! function_exists( 'wp_get_abilities' ) ) {
	/**
	 * @return WP_Ability[]
	 */
	function wp_get_abilities(): array {
		global $_wp_ability_registry;
		return array_values( $_wp_ability_registry );
	}
}

if ( ! function_exists( 'wp_register_ability_category' ) ) {
	/**
	 * @param string               $slug
	 * @param array<string, mixed> $args
	 * @return mixed
	 */
	function wp_register_ability_category( string $slug, array $args ): mixed {
		return null;
	}
}
// phpcs:enable

/*
 * Prime the DI framework's REST route hooks into $wp_filter BEFORE any test
 * runs, so the one-time WP_UnitTestCase::_backup_hooks() snapshot captures
 * them. Without this, the backup is taken by the first test class (often an
 * Ability test that never fires rest_api_init), leaving the snapshot without
 * the 'xwp_di_hooks_loaded_*' and 'sd-ai-agent/v1/basename' callbacks.
 * Every subsequent _restore_hooks() then silently removes those callbacks,
 * making all REST routes return 404 in tests 2+ of any class that follows.
 *
 * By firing rest_api_init here (after WordPress has fully loaded and the
 * plugin's DI container has been initialised on plugins_loaded), the queue
 * handler closures run once: the DI handlers initialise (loaded=true),
 * on_initialize() adds the xwp_di_hooks_loaded_* callbacks, and
 * load_hook() adds the namespace/basename action callbacks. All of these
 * land in $wp_filter before _backup_hooks() is ever called, so they survive
 * the backup/restore cycle intact for every subsequent test.
 *
 * The temporary WP_REST_Server is needed so that register_rest_route() —
 * called inside each controller's register_routes() — does not no-op. Routes
 * registered here are discarded when the server is nulled, but the hooks that
 * were wired into $wp_filter (the real goal) persist.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standard WordPress test global.
global $wp_rest_server;
$wp_rest_server = new WP_REST_Server();
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Standard WordPress core hook.
do_action( 'rest_api_init' );
$wp_rest_server = null;
