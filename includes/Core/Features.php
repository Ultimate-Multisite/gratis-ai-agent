<?php

declare(strict_types=1);
/**
 * Feature-flag registry.
 *
 * Each feature is backed by a PHP constant that site owners (or resellers)
 * can define in wp-config.php before the plugin loads. The constants default
 * to `true` so stock installations retain all functionality; setting one to
 * `false` disables the corresponding UI and REST surface.
 *
 * Defined constants (all default true):
 *  - SD_AI_AGENT_FEATURE_BRANDING            — White-label / branding settings:
 *    agent name, brand colours, logo URL, greeting message.
 *  - SD_AI_AGENT_FEATURE_ACCESS_CONTROL      — Role-based access control:
 *    the Role Permissions manager and its /role-permissions REST routes.
 *  - SD_AI_AGENT_FEATURE_PLUGIN_BUILDER      — AI plugin generation, sandboxed
 *    activation, sandboxed updates, and hook scanning. Disabled in the
 *    WordPress.org distribution because WP.org Guideline 4 prohibits
 *    plugins that "process custom CSS/JS/PHP" or "allow arbitrary
 *    script insertion".
 *  - SD_AI_AGENT_FEATURE_CUSTOM_TOOLS_CLI    — The WP-CLI custom tool type,
 *    which executes shell commands via PHP `exec()`. Disabled in the
 *    WordPress.org distribution for the same reason as above.
 *  - SD_AI_AGENT_FEATURE_PLUGIN_STATE_CHANGES — Abilities that change the
 *    active plugin set without explicit per-action user intervention:
 *    activate-plugin, deactivate-plugin, delete-plugin, switch-plugin,
 *    and update-plugin. Disabled in the WordPress.org distribution
 *    because the WP.org "Changing Active Plugins" guideline forbids
 *    plugins from activating or deactivating other plugins
 *    autonomously, even with capability checks.
 *  - SD_AI_AGENT_FEATURE_PLUGIN_INSTALL_FROM_URL — The
 *    install-plugin-from-url ability, which fetches and installs a
 *    plugin from any direct ZIP URL (e.g. GitHub release assets).
 *    Disabled in the WordPress.org distribution because the
 *    "Changing Active Plugins" guideline only exempts WP.org-directory
 *    installs from the no-autonomous-state-change rule.
 *  - SD_AI_AGENT_FEATURE_FILE_WRITE — Arbitrary filesystem writes
 *    inside wp-content (file-write, file-edit, file-delete, plus the
 *    git-restore and git-revert-package abilities that revert tracked
 *    files via $wp_filesystem->put_contents). Disabled in the
 *    WordPress.org distribution because writes can target
 *    wp-content/plugins/ and wp-content/themes/, which is the same
 *    arbitrary-code-modification risk covered by the WP.org "Changing
 *    Active Plugins" guideline. Read-only file/git abilities remain
 *    available.
 *  - SD_AI_AGENT_FEATURE_SCAFFOLD_BLOCK_THEME — The block-theme
 *    scaffolder ability (`sd-ai-agent/scaffold-block-theme`) that
 *    writes theme.json, style.css, functions.php, and a starter
 *    templates/index.html into `wp-content/themes/{slug}/`. Disabled
 *    in the WordPress.org distribution because writing executable
 *    theme code is the same arbitrary-theme-code modification risk
 *    covered by the WP.org "Changing Active Plugins" guideline. The
 *    remaining Theme Builder abilities (activate-theme,
 *    render-design-previews, generate-menu-page,
 *    validate-palette-contrast, generate-logo-svg) stay registered.
 *  - SD_AI_AGENT_FEATURE_WP_REST_DISPATCHER — The `wp-rest/discover`,
 *    `wp-rest/inspect`, and `wp-rest/execute` abilities, which let the
 *    agent enumerate and invoke arbitrary WordPress REST endpoints via
 *    the internal dispatcher. Even though every dispatched call still
 *    goes through the target endpoint's own `permission_callback`, the
 *    enumeration + execute surface is a generic low-level dispatcher
 *    and the same class of arbitrary-script-dispatch risk as run-php.
 *    Disabled in the WordPress.org distribution build and the
 *    `includes/Abilities/WpRestAbilities.php` source file is physically
 *    stripped from the shipped zip.
 *  - SD_AI_AGENT_FEATURE_WP_CLI_DISPATCHER — The `wp-cli/execute`
 *    ability, which shells out to the `wp` binary via PHP `exec()` to
 *    run arbitrary WP-CLI commands. Same class of arbitrary-command
 *    risk as run-php and CUSTOM_TOOLS_CLI. Disabled in the
 *    WordPress.org distribution build and the
 *    `includes/Abilities/WpCliAbilities.php` source file is physically
 *    stripped from the shipped zip.
 *  - SD_AI_AGENT_FEATURE_BENCHMARK — The `wp sd-ai-agent benchmark …`
 *    WP-CLI subcommand, which runs the full agent loop and writes a
 *    JSON log per question. Disabled in the WordPress.org distribution
 *    because the command's `--log-dir=<abs-path>` override lets an
 *    administrator point log writes at an arbitrary absolute filesystem
 *    path, which the WP.org reviewer flagged as out-of-scope for a
 *    public-directory plugin.
 *
 * Usage example (wp-config.php):
 *   define( 'SD_AI_AGENT_FEATURE_BRANDING', false );
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Features {

	/**
	 * Feature: white-label branding (agent name, colours, logo).
	 * Constant: SD_AI_AGENT_FEATURE_BRANDING
	 */
	const BRANDING = 'branding';

	/**
	 * Feature: role-based access control (Role Permissions manager).
	 * Constant: SD_AI_AGENT_FEATURE_ACCESS_CONTROL
	 */
	const ACCESS_CONTROL = 'access_control';

	/**
	 * Feature: AI plugin builder (generate / sandbox / activate / update).
	 *
	 * Gates registration of the `sd-ai-agent/generate-plugin`,
	 * `sd-ai-agent/sandbox-test-plugin`, `sd-ai-agent/sandbox-activate-plugin`,
	 * `sd-ai-agent/update-plugin-sandboxed`, `sd-ai-agent/scan-plugin-hooks`,
	 * and `sd-ai-agent/scan-theme-hooks` abilities, plus the
	 * `auto_deactivate_fatal_plugins` init hook.
	 *
	 * Disabled in the WordPress.org distribution build (`bin/build.sh
	 * --target=wporg`) because the WP.org plugin guidelines prohibit
	 * plugins that allow arbitrary PHP insertion. The full feature set
	 * remains available in the GitHub release zip.
	 *
	 * Constant: SD_AI_AGENT_FEATURE_PLUGIN_BUILDER
	 */
	const PLUGIN_BUILDER = 'plugin_builder';

	/**
	 * Feature: WP-CLI custom-tool type.
	 *
	 * Gates registration and execution of custom tools whose `type` is
	 * `cli` — these tools shell out to `wp` via PHP `exec()`. Disabled in
	 * the WordPress.org distribution for the same arbitrary-code-execution
	 * reason as PLUGIN_BUILDER. HTTP and Action tool types remain
	 * available in both builds.
	 *
	 * Constant: SD_AI_AGENT_FEATURE_CUSTOM_TOOLS_CLI
	 */
	const CUSTOM_TOOLS_CLI = 'custom_tools_cli';

	/**
	 * Feature: autonomous changes to the active plugin set.
	 *
	 * Gates the `sd-ai-agent/activate-plugin`,
	 * `sd-ai-agent/deactivate-plugin`, `sd-ai-agent/delete-plugin`,
	 * `sd-ai-agent/switch-plugin`, and `sd-ai-agent/update-plugin`
	 * abilities. With this disabled the agent can still list, recommend,
	 * and search plugins, and can install from the WordPress.org
	 * directory (the WP.org-only exception); it cannot change which
	 * plugins are active without the user clicking through the standard
	 * WP admin Plugins screen.
	 *
	 * Disabled in the WordPress.org distribution build to comply with
	 * the WP.org "Changing Active Plugins" guideline.
	 *
	 * Constant: SD_AI_AGENT_FEATURE_PLUGIN_STATE_CHANGES
	 */
	const PLUGIN_STATE_CHANGES = 'plugin_state_changes';

	/**
	 * Feature: install plugins from arbitrary ZIP URLs / GitHub.
	 *
	 * Gates the `sd-ai-agent/install-plugin-from-url` ability. With this
	 * disabled the agent can still install plugins from the official
	 * WordPress.org directory by slug (`sd-ai-agent/install-plugin`),
	 * but cannot fetch a ZIP from any third-party URL.
	 *
	 * Disabled in the WordPress.org distribution build because the
	 * "Changing Active Plugins" guideline restricts plugin-installation
	 * automation to the WP.org-directory channel. The full GitHub
	 * release zip retains the broader URL-install ability for
	 * self-hosted users.
	 *
	 * Constant: SD_AI_AGENT_FEATURE_PLUGIN_INSTALL_FROM_URL
	 */
	const PLUGIN_INSTALL_FROM_URL = 'plugin_install_from_url';

	/**
	 * Feature: arbitrary filesystem writes inside wp-content.
	 *
	 * Gates the `sd-ai-agent/file-write`, `sd-ai-agent/file-edit`,
	 * `sd-ai-agent/file-delete`, `sd-ai-agent/git-restore`, and
	 * `sd-ai-agent/git-revert-package` abilities. Read-only file/git
	 * abilities (file-read, file-list, file-search, content-search,
	 * git-list, git-diff, git-package-summary, git-snapshot) remain
	 * available because they cannot mutate plugin/theme source.
	 *
	 * Disabled in the WordPress.org distribution build because writes
	 * resolve under `WP_CONTENT_DIR`, which includes `plugins/` and
	 * `themes/` — direct edits there are the same class of arbitrary
	 * third-party-code modification covered by the WP.org "Changing
	 * Active Plugins" guideline.
	 *
	 * Constant: SD_AI_AGENT_FEATURE_FILE_WRITE
	 */
	const FILE_WRITE = 'file_write';

	/**
	 * Feature: block-theme scaffolder ability.
	 *
	 * Gates the `sd-ai-agent/scaffold-block-theme` ability that writes
	 * theme.json, style.css, functions.php, and a starter
	 * templates/index.html into `wp-content/themes/{slug}/`. The
	 * remaining Theme Builder abilities (activate-theme,
	 * render-design-previews, generate-menu-page,
	 * validate-palette-contrast, generate-logo-svg) are not gated by
	 * this flag because they do not write executable theme code to
	 * disk.
	 *
	 * Disabled in the WordPress.org distribution build because
	 * writing executable PHP/CSS into the active themes directory is
	 * the same class of arbitrary third-party-code modification
	 * covered by the WP.org "Changing Active Plugins" guideline. The
	 * full GitHub release zip leaves it enabled.
	 *
	 * Constant: SD_AI_AGENT_FEATURE_SCAFFOLD_BLOCK_THEME
	 */
	const SCAFFOLD_BLOCK_THEME = 'scaffold_block_theme';

	/**
	 * Feature: WP REST dispatcher abilities.
	 *
	 * Gates registration of the `wp-rest/discover`, `wp-rest/inspect`,
	 * and `wp-rest/execute` abilities, plus their shared `wp-rest`
	 * ability category. With this disabled the agent cannot enumerate
	 * or invoke arbitrary WordPress REST endpoints through the
	 * internal dispatcher.
	 *
	 * Disabled in the WordPress.org distribution build because the
	 * dispatcher is a generic low-level surface that, once enabled,
	 * exposes every registered REST endpoint to whatever caller has
	 * the agent's permission set — the same class of low-level
	 * dispatch risk as `run-php`. The
	 * `includes/Abilities/WpRestAbilities.php` source file is
	 * physically stripped from the shipped zip.
	 *
	 * Constant: SD_AI_AGENT_FEATURE_WP_REST_DISPATCHER
	 */
	const WP_REST_DISPATCHER = 'wp_rest_dispatcher';

	/**
	 * Feature: WP-CLI dispatcher ability.
	 *
	 * Gates registration of the `wp-cli/execute` ability and its
	 * shared `wp-cli` ability category. The ability shells out to the
	 * `wp` binary via PHP `exec()` and runs arbitrary WP-CLI
	 * subcommands.
	 *
	 * Disabled in the WordPress.org distribution build for the same
	 * arbitrary-command-execution reason as PLUGIN_BUILDER and
	 * CUSTOM_TOOLS_CLI. The
	 * `includes/Abilities/WpCliAbilities.php` source file is
	 * physically stripped from the shipped zip.
	 *
	 * Constant: SD_AI_AGENT_FEATURE_WP_CLI_DISPATCHER
	 */
	const WP_CLI_DISPATCHER = 'wp_cli_dispatcher';

	/**
	 * Feature: WP-CLI functional benchmark suite.
	 *
	 * Gates registration of the `wp sd-ai-agent benchmark …` subcommand
	 * (suites / questions / run). With this disabled the WP-CLI command
	 * is not advertised under any of the plugin's CLI namespaces and the
	 * `BenchmarkCommand` class is never instantiated.
	 *
	 * Disabled in the WordPress.org distribution build because the
	 * command's `--log-dir=<abs-path>` flag lets an administrator write
	 * benchmark logs to an arbitrary absolute filesystem path, which the
	 * WP.org reviewer flagged as out-of-scope for a public-directory
	 * plugin (data should land in the database, the uploads dir, or the
	 * media uploader). The full GitHub release zip retains the command
	 * for self-hosted model-evaluation use.
	 *
	 * Constant: SD_AI_AGENT_FEATURE_BENCHMARK
	 */
	const BENCHMARK = 'benchmark';

	/**
	 * Map of feature name → backing constant name.
	 *
	 * @var array<string, string>
	 */
	private const CONSTANT_MAP = array(
		self::BRANDING                => 'SD_AI_AGENT_FEATURE_BRANDING',
		self::ACCESS_CONTROL          => 'SD_AI_AGENT_FEATURE_ACCESS_CONTROL',
		self::PLUGIN_BUILDER          => 'SD_AI_AGENT_FEATURE_PLUGIN_BUILDER',
		self::CUSTOM_TOOLS_CLI        => 'SD_AI_AGENT_FEATURE_CUSTOM_TOOLS_CLI',
		self::PLUGIN_STATE_CHANGES    => 'SD_AI_AGENT_FEATURE_PLUGIN_STATE_CHANGES',
		self::PLUGIN_INSTALL_FROM_URL => 'SD_AI_AGENT_FEATURE_PLUGIN_INSTALL_FROM_URL',
		self::FILE_WRITE              => 'SD_AI_AGENT_FEATURE_FILE_WRITE',
		self::SCAFFOLD_BLOCK_THEME    => 'SD_AI_AGENT_FEATURE_SCAFFOLD_BLOCK_THEME',
		self::WP_REST_DISPATCHER      => 'SD_AI_AGENT_FEATURE_WP_REST_DISPATCHER',
		self::WP_CLI_DISPATCHER       => 'SD_AI_AGENT_FEATURE_WP_CLI_DISPATCHER',
		self::BENCHMARK               => 'SD_AI_AGENT_FEATURE_BENCHMARK',
	);

	/**
	 * Check whether a feature is enabled.
	 *
	 * Returns `true` when the backing constant is not defined (default-on).
	 * Returns `(bool) CONSTANT_VALUE` when the constant is defined.
	 *
	 * @param string $feature One of the Features::* class constants.
	 * @return bool
	 */
	public static function is_enabled( string $feature ): bool {
		$constant = self::CONSTANT_MAP[ $feature ] ?? null;

		if ( null === $constant ) {
			// Unknown feature — fail open (enabled) to avoid breaking valid calls.
			return true;
		}

		if ( ! defined( $constant ) ) {
			// Constant not set by the site owner → default enabled.
			return true;
		}

		return (bool) constant( $constant );
	}

	/**
	 * Return a map of all features and their current enabled state.
	 *
	 * Suitable for serialising into REST responses or wp_localize_script data.
	 *
	 * @return array<string, bool>
	 */
	public static function all(): array {
		$result = array();
		foreach ( array_keys( self::CONSTANT_MAP ) as $feature ) {
			$result[ $feature ] = self::is_enabled( $feature );
		}
		return $result;
	}
}
