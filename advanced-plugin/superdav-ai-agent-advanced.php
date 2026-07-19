<?php
/**
 * Plugin Name: Superdav AI Agent Advanced
 * Plugin URI:  https://github.com/Ultimate-Multisite/superdav-ai-agent
 * Description: Advanced companion plugin for Superdav AI Agent with self-hosted code, filesystem, database, WP-CLI, REST dispatcher, and plugin-builder tools.
 * Version:     1.19.0
 * Author:      superdav42
 * Author URI:  https://github.com/superdav42
 * License:     GPL-2.0-or-later
 * Requires at least: 7.0
 * Requires PHP: 8.2
 * Requires Plugins: superdav-ai-agent
 * Text Domain: superdav-ai-agent-advanced
 *
 * @package SdAiAgentAdvanced
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'SD_AI_AGENT_ADVANCED_LOADED' ) ) {
	return;
}

define( 'SD_AI_AGENT_ADVANCED_LOADED', true );
define( 'SD_AI_AGENT_ADVANCED_VERSION', '1.19.0' );
define( 'SD_AI_AGENT_ADVANCED_DIR', __DIR__ );
define( 'SD_AI_AGENT_ADVANCED_URL', plugin_dir_url( __FILE__ ) );

require_once SD_AI_AGENT_ADVANCED_DIR . '/includes/Autoloader.php';

\SdAiAgentAdvanced\Autoloader::register( SD_AI_AGENT_ADVANCED_DIR );

// When both plugins are network active, WordPress can load the advanced plugin
// before the core plugin. Defer the dependency notice until all normal plugins
// have had a chance to define SD_AI_AGENT_VERSION, but register the container
// extension filter immediately so the core plugin can still discover the
// advanced module when it boots later in the same request.
add_action(
	'plugins_loaded',
	static function (): void {
		if ( defined( 'SD_AI_AGENT_VERSION' ) ) {
			return;
		}

		$notice_hook = function_exists( 'is_network_admin' ) && is_network_admin()
			? 'network_admin_notices'
			: 'admin_notices';

		add_action(
			$notice_hook,
			static function (): void {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html__(
						'Superdav AI Agent Advanced requires the core Superdav AI Agent plugin to be installed and active.',
						'superdav-ai-agent'
					)
				);
			}
		);
	},
	PHP_INT_MAX
);

add_filter(
	'xwp_extend_import_sd-ai-agent',
	static function ( array $imports ): array {
		if ( ! in_array( \SdAiAgentAdvanced\Plugin::class, $imports, true ) ) {
			$imports[] = \SdAiAgentAdvanced\Plugin::class;
		}

		return $imports;
	}
);
