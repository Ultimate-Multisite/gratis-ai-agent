<?php
/**
 * Plugin Name: Superdav AI Agent Advanced
 * Plugin URI:  https://github.com/Ultimate-Multisite/superdav-ai-agent
 * Description: Advanced companion plugin for Superdav AI Agent with self-hosted code, filesystem, database, WP-CLI, REST dispatcher, and plugin-builder tools.
 * Version:     1.18.0
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
define( 'SD_AI_AGENT_ADVANCED_VERSION', '1.18.0' );
define( 'SD_AI_AGENT_ADVANCED_DIR', __DIR__ );
define( 'SD_AI_AGENT_ADVANCED_URL', plugin_dir_url( __FILE__ ) );

require_once SD_AI_AGENT_ADVANCED_DIR . '/includes/Autoloader.php';

\SdAiAgentAdvanced\Autoloader::register( SD_AI_AGENT_ADVANCED_DIR );

if ( ! defined( 'SD_AI_AGENT_VERSION' ) ) {
	add_action(
		'admin_notices',
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
	return;
}

add_filter(
	'xwp_extend_import_sd-ai-agent',
	static function ( array $imports ): array {
		if ( ! in_array( \SdAiAgentAdvanced\Plugin::class, $imports, true ) ) {
			$imports[] = \SdAiAgentAdvanced\Plugin::class;
		}

		return $imports;
	}
);
