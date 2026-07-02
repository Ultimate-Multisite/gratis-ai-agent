# x-wp/di — Dependency Injection Reference

> **Package:** [`x-wp/di`](https://github.com/x-wp/di) ^1.9  
> **Docs source:** Learned through the 6-PR migration (PRs #983–#986, #990).  
> **Load this skill** when working on DI container configuration, adding new handlers, converting legacy `add_action` wiring, or debugging handler loading issues.

## Architecture

```
sd-ai-agent.php
  └─ xwp_load_app()              # schedules container on plugins_loaded:PHP_INT_MIN
       └─ xwp_create_app()       # builds PHP-DI container, processes Module
            └─ Plugin.php        # #[Module] — lists all handlers
                 ├─ AbilitySchemaFilter        # #[Handler] — filter hooks
                 ├─ MemoryController           # #[REST_Handler] — single-basename REST
                 ├─ SessionController          # #[Handler] + #[Action] — multi-basename REST
                 ├─ AbilitiesHandler           # #[Handler] + #[Action] — abilities
                 ├─ AdminHandler               # #[Handler] CTX_ADMIN — admin hooks
                 ├─ ChangeLoggingHandler       # #[Handler] CTX_GLOBAL — change-log hooks
                 ├─ HttpTraceHandler           # #[Handler] CTX_GLOBAL — provider trace filters
                 ├─ KnowledgeHooksHandler      # #[Handler] CTX_GLOBAL — knowledge sync hooks
                 ├─ ToolDiscoveryHandler       # #[Handler] — tool discovery abilities
                 ├─ AutomationsHandler         # #[Handler] CTX_GLOBAL — cron + event hooks
                 ├─ GitTrackingHandler         # #[Handler] CTX_GLOBAL — file-change hooks
                 ├─ OnboardingHandler          # #[Handler] CTX_GLOBAL — onboarding hooks
                 ├─ FreshInstallHandler        # #[Handler] CTX_GLOBAL — cache-clear hooks
                 ├─ FrontendAssetsHandler      # #[Handler] CTX_FRONTEND — public assets
                 └─ ... (31 handlers total)
```

## Core Concepts

### Container Bootstrap

```php
// sd-ai-agent.php — the ONLY thing needed in the plugin file
xwp_load_app([
    'id'            => 'sd-ai-agent',
    'module'        => Plugin::class,
    'autowiring'    => true,
    'compile'       => 'production' === wp_get_environment_type(),
    'compile_class' => 'CompiledContainerSdAiAgent', // REQUIRED — hyphens in ID break class names
    'compile_dir'   => SD_AI_AGENT_DIR . '/build/di-cache/' . SD_AI_AGENT_VERSION,
]);
```

### Module (Plugin.php)

```php
#[Module(
    container: 'sd-ai-agent',
    hook: 'plugins_loaded',
    priority: 1,              // Must be > PHP_INT_MIN (xwp_load_app's default)
    imports: [],
    handlers: [
        MyHandler::class,     // Listed here = registered by the DI system
    ],
    extendable: true,         // Allows companion plugins to add handlers
)]
final class Plugin {
    public static function configure(): array {
        return [
            'plugin.version' => \DI\value( SD_AI_AGENT_VERSION ),
        ];
    }
}
```

### Context Constants

| Constant | Value | Detected by |
|----------|-------|-------------|
| `CTX_FRONTEND` | 1 | `!is_admin() && !cron && !rest && !cli` |
| `CTX_ADMIN` | 2 | `is_admin() && !DOING_AJAX` |
| `CTX_AJAX` | 4 | `DOING_AJAX` constant |
| `CTX_CRON` | 8 | `DOING_CRON` constant |
| `CTX_REST` | 16 | `$_SERVER['REQUEST_URI']` contains `wp-json/` |
| `CTX_CLI` | 32 | `WP_CLI` constant |
| `CTX_GLOBAL` | 63 | Always matches (all bits set) |

Context is cached in `XWP_Context::$current` on first call to `get()`. The match evaluates **in order**: admin → ajax → cron → rest → cli → frontend. If `is_admin()` is true, rest is never checked.

### Handler Strategies

| Strategy | When hooks attach | Use case |
|----------|-------------------|----------|
| `INIT_DEFFERED` (default) | When `tag` fires | REST_Handler default — hooks attach on `rest_api_init` |
| `INIT_IMMEDIATELY` | During Module processing on `plugins_loaded` | Most custom handlers — hooks attach immediately |
| `INIT_ON_DEMAND` | When a dependent hook fires | Lazy loading |
| `INIT_JUST_IN_TIME` | Same as ON_DEMAND | Lazy loading variant |
| `INIT_DYNAMICALY` | Already loaded at construction | Pre-instantiated handlers |

## Two REST Patterns

### Pattern 1: `#[REST_Handler]` — Single-basename controllers

Use when a controller has ONE REST basename (e.g., `/memory`, `/skills`).

```php
use XWP\DI\Decorators\REST_Handler;
use XWP\DI\Decorators\REST_Route;

#[REST_Handler(
    namespace: 'sd-ai-agent/v1',
    basename: 'memory',
    container: 'sd-ai-agent',
)]
final class MemoryController extends XWP_REST_Controller {

    use PermissionTrait;

    #[REST_Route(
        route: '',                              // = /memory
        methods: WP_REST_Server::READABLE,
        vars: 'get_list_args',                  // method name returning args array
        guard: 'check_manage_options',          // PermissionTrait method
    )]
    public function handle_list( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        // ...
    }

    #[REST_Route(
        route: '(?P<id>\d+)',                   // = /memory/123
        methods: WP_REST_Server::DELETABLE,
        guard: 'check_manage_options',
    )]
    public function handle_delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        // ...
    }

    public function get_list_args(): array {
        return [ 'per_page' => [ 'type' => 'integer', 'default' => 20 ] ];
    }
}
```

**Key rules:**
- Class MUST extend `XWP_REST_Controller` (which extends `WP_REST_Controller`)
- `REST_Handler` hardcodes `context: CTX_REST` — cannot override
- `REST_Route::vars` is either an inline array OR a string naming a method
- `REST_Route::guard` names a permission_callback method (use `PermissionTrait`)
- `REST_Route::invoke()` calls `register_rest_route()` directly — no context re-check

### Pattern 2: `#[Handler]` + `#[Action]` — Multi-basename controllers

Use when a controller has MULTIPLE REST basenames (e.g., `/abilities` + `/custom-tools`).

```php
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

#[Handler(
    container: 'sd-ai-agent',
    context: Handler::CTX_REST,
    strategy: Handler::INIT_IMMEDIATELY,
)]
final class ToolController {

    #[Action( tag: 'rest_api_init', priority: 10 )]
    public function register_routes(): void {
        register_rest_route( RestController::NAMESPACE, '/abilities', [ /* ... */ ] );
        register_rest_route( RestController::NAMESPACE, '/custom-tools', [ /* ... */ ] );
    }
}
```

**Conversion pattern** (mechanical, from legacy static `register_routes()`):
1. Add `use XWP\DI\Decorators\{Action, Handler}` imports
2. Add ABSPATH guard
3. Add `#[Handler(container, context: CTX_REST, strategy: INIT_IMMEDIATELY)]`
4. Make class `final`
5. Change `public static function register_routes()` → `public function register_routes()`
6. Add `#[Action(tag: 'rest_api_init', priority: 10)]` on it
7. Replace `self::NAMESPACE` with `RestController::NAMESPACE`
8. Replace `$instance` with `$this`, remove `$instance = new self()` line
9. Remove `const NAMESPACE` (shared via `RestController::NAMESPACE`)

## Non-REST Handler Patterns

### Action/Filter hooks

```php
#[Handler(container: 'sd-ai-agent', strategy: Handler::INIT_IMMEDIATELY)]
final class AbilitySchemaFilter {

    #[Filter( tag: 'wp_register_ability_args', priority: 10 )]
    public function normalize_schema( array $args ): array {
        // ...
        return $args;
    }
}
```

### on_initialize() delegation (legacy pattern)

`on_initialize()` is called automatically by the DI system during handler loading and can be used to call static `register()` methods that internally add many hooks:

```php
#[Handler(container: 'sd-ai-agent', strategy: Handler::INIT_IMMEDIATELY)]
final class SomeHandler {

    public function on_initialize(): void {
        SomeService::register();  // adds hooks internally
    }
}
```

This pattern is valid but opaque — the DI container doesn't know which hooks each service registers, making it harder to audit hook wiring at a glance. **Prefer the `#[Action]` / `#[Filter]` attribute pattern** (see above) so every hook is declared explicitly on the handler method that owns it.

```php
// Preferred — hooks are explicit and auditable.
#[Handler(container: 'sd-ai-agent', context: Handler::CTX_GLOBAL, strategy: Handler::INIT_IMMEDIATELY)]
final class ChangeLoggingHandler {

    #[Action( tag: 'post_updated', priority: 10 )]
    public function on_post_updated( int $post_id, \WP_Post $post_after, \WP_Post $post_before ): void {
        ChangeLogger::on_post_updated( $post_id, $post_after, $post_before );
    }
    // ...
}
```

### Context-scoped handlers

```php
// Only loads on admin pages — zero overhead on frontend/REST/CLI
#[Handler(
    container: 'sd-ai-agent',
    context: Handler::CTX_ADMIN,
    strategy: Handler::INIT_IMMEDIATELY,
)]
final class AdminHandler {
    #[Action( tag: 'admin_menu', priority: 10 )]
    public function register_menus(): void { /* ... */ }

    #[Action( tag: 'admin_init', priority: 10 )]
    public function on_admin_init(): void { /* ... */ }
}
```

## Gotchas & Hard-Won Lessons

### 1. `compile_class` is REQUIRED when container ID has hyphens

The default `compile_class` is `CompiledContainer` + uppercased ID. For `sd-ai-agent`, this produces `CompiledContainerSd-ai-agent` — an invalid PHP class name. Always set `compile_class` explicitly.

### 2. Module priority must differ from `xwp_load_app()` priority

`xwp_load_app()` defaults to `plugins_loaded:PHP_INT_MIN`. If the `#[Module]` also uses `plugins_loaded:PHP_INT_MIN`, PHP's foreach snapshot-iteration means the Module callback queued by `xwp_create_app()` never fires. Use `priority: 1` (or any value > PHP_INT_MIN) on the Module.

### 3. `INIT_IMMEDIATELY` handlers with `plugins_loaded` tag hit the same snapshot issue

If a handler has `tag: 'plugins_loaded'` and `priority: 1` (same as the Module), it may be queued during the Module's processing but miss the current iteration. Fix: use `strategy: INIT_IMMEDIATELY` which bypasses deferred queuing entirely.

### 4. `REST_Handler` supports only ONE basename per class

The `#[REST_Handler(namespace, basename, container)]` decorator maps to a single `rest_base`. For controllers with multiple basenames (e.g., `/abilities` + `/custom-tools`), use `#[Handler(context: CTX_REST, strategy: INIT_IMMEDIATELY)]` + `#[Action(tag: 'rest_api_init')]` instead.

### 5. `CTX_REST` handlers don't load in PHPUnit

`XWP_Context::get()` checks `admin()` BEFORE `rest()`. The WP test bootstrap defines `WP_ADMIN=true`, so context resolves to Admin even when `$_SERVER['REQUEST_URI']` contains `/wp-json/`. The context is cached — setting REQUEST_URI alone doesn't help.

**Current workaround:** Reflection override in `tests/bootstrap.php`:
```php
$refl = new ReflectionProperty( XWP_Context::class, 'current' );
$refl->setValue( null, XWP_Context::REST );
```

**Outstanding issue:** Even with correct context, deferred handlers mark hooks as `$loaded` after first fire. When test `setUp()` creates a fresh `WP_REST_Server` and re-fires `rest_api_init`, routes don't re-register on the new server. Tracked in GitHub issues.

### 5a. `CTX_REST` handlers don't load with plain permalinks (wp-env / CI)

`XWP_Context::rest()` only detects REST context when `$_SERVER['REQUEST_URI']` contains the `wp-json/` prefix. When WordPress uses plain permalinks (the default in fresh wp-env installs), REST requests use `?rest_route=/...` in the query string. `REQUEST_URI` is then `/?rest_route=...` which does **not** contain `wp-json/`, so `XWP_Context::rest()` returns false, context is misdetected as `CTX_FRONTEND`, and all `CTX_REST` handlers fail to load — every REST endpoint returns 404.

**Fix (applied in `sd-ai-agent.php`):** Before calling `xwp_load_app()`, the plugin normalises `REQUEST_URI` for plain-permalink REST requests:

```php
if ( ! empty( $_GET['rest_route'] ) ) {
    $prefix = rest_get_url_prefix(); // 'wp-json'
    if ( false === strpos( $_SERVER['REQUEST_URI'] ?? '', $prefix ) ) {
        $_SERVER['REQUEST_URI'] = '/' . $prefix . $_GET['rest_route'];
    }
}
```

This runs before `xwp_load_app()` queues the container build at `plugins_loaded:PHP_INT_MIN`, so `XWP_Context::get()` caches the correct `CTX_REST` value when the container initialises.

### 6. PHPStan cache corruption on PHP 8.4

Occasionally `/tmp/phpstan/cache/` fatals with class-not-found errors. Fix: `rm -rf /tmp/phpstan && composer install` before re-running.

### 7. Making classes `final` tightens PHPStan return-type analysis

When converting controllers to `final`, PHPStan may flag return types that were previously acceptable. E.g., a method declared `WP_REST_Response|WP_Error` that always returns `WP_REST_Response` — PHPStan now knows the `WP_Error` branch is unreachable.

### 8. DI cache must be cleared after handler changes

```bash
rm -rf build/di-cache/
```

The compiled container caches handler metadata. Stale cache = handlers not found or wrong hooks.

### 9. Verifying REST routes in non-test environments

`CTX_REST` handlers don't load during CLI or admin. Use HTTP requests to verify:

```bash
# 401 = route exists + auth required, 404 = route missing
curl -s -o /dev/null -w "%{http_code}" "http://wordpress.local:8080/wp-json/sd-ai-agent/v1/memory"
```

### 10. Shipping compiled containers in distributions

When distributing the plugin (e.g., towordpress.org), the compiled PHP-DI container must work on any installation with different paths. The problem: `compile_dir` is an absolute path during compilation, and `plugin.dir/url` values are baked into the generated class.

**Solution:** Use `\DI\factory()` instead of `\DI\value()` for runtime-resolved values:

```php
// Plugin.php - BAD: values baked at compile-time
'plugin.dir' => \DI\value( GRAT_IS_AI_AGENT_DIR ),
'plugin.url' => \DI\value( GRAT_IS_AI_AGENT_URL ),

// Plugin.php - GOOD: resolves at runtime
'plugin.dir' => \DI\factory( static fn(): string => defined( 'SD_AI_AGENT_DIR' ) ? constant( 'SD_AI_AGENT_DIR' ) : '' ),
'plugin.url' => \DI\factory( static fn(): string => defined( 'SD_AI_AGENT_URL' ) ? constant( 'SD_AI_AGENT_URL' ) : '' ),
```

This generates a factory closure in the compiled container instead of hardcoded strings:

```php
// Before (baked):
protected function get4() { return '/home/dave/Git/sd-ai-agent'; }

// After (runtime-resolved):
protected function get4() {
    return $this->resolveFactory(static fn(): string => ..., 'plugin.dir');
}
```

`bin/build.sh` compiles `build/di-cache/{SD_AI_AGENT_VERSION}/CompiledContainerSdAiAgent.php` inside the staged release directory and packages it in the WordPress.org ZIP. That keeps installs checksum-stable because PHP-DI sees the versioned compiled file on first load instead of creating it after activation. The build helper validates that the compiled file does not contain the staging path; keep install-specific definitions as `\DI\factory()` values so the packaged cache remains portable.

### 11. Pre-commit vendor dance

The pre-commit hook runs `composer install --no-dev` before staging `vendor/`, then `composer install` afterwards. This ensures only production deps are committed. Don't manually `git add vendor/` — let the hook handle it.

## File Map

```
includes/
├── Plugin.php                          # #[Module] — handler registry (31 handlers)
├── Bootstrap/
│   ├── LifecycleHandler.php            # Activation/deactivation (pre-DI, static)
│   ├── CliHandler.php                  # WP-CLI context handler
│   ├── AbilitiesHandler.php            # 35 ability registrations
│   ├── AdminHandler.php                # Admin menus, capabilities, admin assets
│   ├── ChangeLoggingHandler.php        # AI change-log hooks (CTX_GLOBAL)
│   ├── HttpTraceHandler.php            # LLM provider HTTP trace filters (CTX_GLOBAL)
│   ├── KnowledgeHooksHandler.php       # Knowledge-base content sync (CTX_GLOBAL)
│   ├── ToolDiscoveryHandler.php        # Tool discovery + custom tool executor abilities
│   ├── AutomationsHandler.php          # Automation cron + event-trigger hooks (CTX_GLOBAL)
│   ├── GitTrackingHandler.php          # File-change git tracking hooks (CTX_GLOBAL)
│   ├── OnboardingHandler.php           # Smart onboarding + site scanner (CTX_GLOBAL)
│   ├── FreshInstallHandler.php         # Fresh-install cache invalidation (CTX_GLOBAL)
│   └── FrontendAssetsHandler.php       # Frontend widget assets
├── Infrastructure/
│   ├── AiClient/
│   │   └── RequestTimeoutFilter.php    # AI client timeout filter
│   ├── Schema/
│   │   └── SchemaNormalizer.php        # Pure class (no DI decorator)
│   └── WordPress/Abilities/
│       ├── AbilitySchemaFilter.php     # Schema normalization filter
│       ├── AbilityCategoryRegistrar.php # Category registration action
│       └── UsageInstructionsFilter.php  # Usage instructions filter
└── REST/
    ├── MemoryController.php            # #[REST_Handler] — single basename
    ├── SkillController.php             # #[REST_Handler] — single basename
    ├── SessionController.php           # #[Handler] — multi basename
    ├── SettingsController.php          # #[Handler] — multi basename
    ├── RestController.php              # #[Handler] — shared NAMESPACE constant
    └── ... (16 controllers total)
```

## Adding a New Handler

1. Create class in appropriate directory (`Bootstrap/`, `Infrastructure/`, etc.)
2. Add `declare(strict_types=1)`, namespace, ABSPATH guard
3. Decorate with `#[Handler(container: 'sd-ai-agent', ...)]`
4. Add `#[Action]` / `#[Filter]` on methods, or use `on_initialize()`
5. Add the class to `Plugin.php`'s `handlers` array
6. Run `composer dump-autoload`
7. Clear DI cache: `rm -rf build/di-cache/`
8. Verify: `curl` for REST, admin page load for admin handlers
