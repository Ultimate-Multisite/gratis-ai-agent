<?php
/**
 * Handler: register the `sd-ai-agent` ability category.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Infrastructure\WordPress\Abilities;

use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the top-level "SD AI Agent" ability category with the core
 * Abilities API so our memory / knowledge / skill abilities group together
 * in the UI and in ability enumeration results.
 *
 * Hooks into `wp_abilities_api_categories_init` — the dedicated pre-registration
 * lifecycle event published by the Abilities API, which guarantees
 * {@see wp_register_ability_category()} is defined when we call it.
 */
#[Handler(
	container: 'sd-ai-agent',
	context: Handler::CTX_GLOBAL,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class AbilityCategoryRegistrar {

	/**
	 * Register the plugin's own ability category.
	 */
	#[Action( tag: 'wp_abilities_api_categories_init', priority: 10 )]
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			'sd-ai-agent',
			array(
				'label'       => __( 'SD AI Agent', 'superdav-ai-agent' ),
				'description' => __( 'SD AI Agent memory and skill abilities.', 'superdav-ai-agent' ),
			)
		);
	}
}
