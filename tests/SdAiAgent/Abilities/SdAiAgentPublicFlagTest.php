<?php
/**
 * Test that every sd-ai-agent/* ability declares meta.mcp.public = true.
 *
 * Post-bootstrap registry scan: boots the ability registrar and asserts
 * that every `sd-ai-agent/*` ability has `meta.mcp.public = true` unless
 * it is explicitly hidden via `meta.ai_hidden = true`.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\MemoryAbilities;
use SdAiAgent\Abilities\SkillAbilities;
use SdAiAgent\Abilities\KnowledgeAbilities;
use SdAiAgent\Abilities\PostAbilities;
use SdAiAgent\Abilities\BlockAbilities;
use SdAiAgent\Abilities\ContentAbilities;
use SdAiAgent\Abilities\FileAbilities;
use SdAiAgent\Abilities\MediaAbilities;
use SdAiAgent\Abilities\UserAbilities;
use SdAiAgent\Abilities\OptionsAbilities;
use SdAiAgent\Abilities\MenuAbilities;
use SdAiAgent\Abilities\SiteHealthAbilities;
use SdAiAgent\Abilities\EditorialAbilities;
use SdAiAgent\Abilities\SeoAbilities;
use SdAiAgent\Abilities\MarketingAbilities;
use SdAiAgent\Abilities\FeedbackAbilities;
use SdAiAgent\Abilities\NavigationAbilities;
use SdAiAgent\Abilities\CustomPostTypeAbilities;
use SdAiAgent\Abilities\CustomTaxonomyAbilities;
use SdAiAgent\Abilities\DatabaseAbilities;
use SdAiAgent\Abilities\DesignSystemAbilities;
use SdAiAgent\Abilities\GlobalStylesAbilities;
use SdAiAgent\Abilities\GoogleAnalyticsAbilities;
use SdAiAgent\Abilities\GscAbilities;
use SdAiAgent\Abilities\InternetSearchAbilities;
use SdAiAgent\Abilities\ImageAbilities;
use SdAiAgent\Abilities\WordPressAbilities;
use SdAiAgent\Abilities\GitAbilities;
use SdAiAgent\Tools\ToolDiscovery;
use WP_UnitTestCase;

/**
 * Assert every sd-ai-agent/* ability has meta.mcp.public = true.
 *
 * Registers all sd-ai-agent/* abilities inline (bypassing the DI handler
 * so the test does not depend on the container bootstrap ordering) and
 * then scans the WP_Ability registry to verify the flag is set.
 */
class SdAiAgentPublicFlagTest extends WP_UnitTestCase {

	/**
	 * Skip when WP 7.0+ Abilities API is unavailable.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'WP 7.0+ Abilities API (wp_register_ability / wp_get_abilities) not available.' );
		}
	}

	/**
	 * Every sd-ai-agent/* ability must declare meta.mcp.public = true unless
	 * it is explicitly hidden (meta.ai_hidden = true).
	 *
	 * Procedure:
	 * 1. Temporarily push 'wp_abilities_api_init' onto $wp_current_filter so
	 *    wp_register_ability() does not reject the calls.
	 * 2. Call each abilities class register_abilities() / register() method
	 *    directly (no DI container, no hook timing issues).
	 * 3. Scan all registered abilities with a 'sd-ai-agent/' prefix.
	 * 4. For each, assert meta.mcp.public === true (unless ai_hidden).
	 */
	public function test_all_sd_ai_agent_abilities_have_mcp_public_flag(): void {
		// Push the hook onto $wp_current_filter so wp_register_ability()
		// accepts calls made outside the normal hook callback context.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standard WordPress test global.
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init';

		// Register every sd-ai-agent/* ability group.
		MemoryAbilities::register_abilities();
		SkillAbilities::register_abilities();
		KnowledgeAbilities::register_abilities();
		PostAbilities::register_abilities();
		BlockAbilities::register_abilities();
		ContentAbilities::register_abilities();
		FileAbilities::register_abilities();
		MediaAbilities::register_abilities();
		UserAbilities::register_abilities();
		OptionsAbilities::register_abilities();
		MenuAbilities::register_abilities();
		SiteHealthAbilities::register_abilities();
		EditorialAbilities::register_abilities();
		SeoAbilities::register_abilities();
		MarketingAbilities::register_abilities();
		FeedbackAbilities::register_abilities();
		NavigationAbilities::register_abilities();
		CustomPostTypeAbilities::register_abilities();
		CustomTaxonomyAbilities::register_abilities();
		DatabaseAbilities::register_abilities();
		DesignSystemAbilities::register_abilities();
		GlobalStylesAbilities::register_abilities();
		GoogleAnalyticsAbilities::register_abilities();
		GscAbilities::register_abilities();
		InternetSearchAbilities::register_abilities();
		ImageAbilities::register_abilities();
		WordPressAbilities::register_abilities();
		GitAbilities::register_abilities();

		// Register the two meta-tools (ability-search and ability-call).
		ToolDiscovery::register_abilities();

		// Also register image abilities via their static register() methods.
		if ( class_exists( \SdAiAgent\Abilities\ImageAbilities\StockImageAbility::class ) ) {
			\SdAiAgent\Abilities\ImageAbilities\StockImageAbility::register();
		}
		if ( class_exists( \SdAiAgent\Abilities\AiImageAbilities::class ) ) {
			\SdAiAgent\Abilities\AiImageAbilities::register_abilities();
		}

		// Pop the hook filter entry we pushed.
		array_pop( $wp_current_filter );

		// Collect all registered sd-ai-agent/* abilities.
		$missing_flag  = array();
		$checked_count = 0;

		/** @var \WP_Ability $ability */
		foreach ( wp_get_abilities() as $ability ) {
			$name = $ability->get_name();

			// Only check sd-ai-agent/* abilities (not sd-ai-agent-js/* or wp-cli/* etc.).
			if ( ! str_starts_with( $name, 'sd-ai-agent/' ) ) {
				continue;
			}

			++$checked_count;

			// @phpstan-ignore-next-line — get_meta() exists on WP_Ability at runtime (WP 7.0+).
			$meta = $ability->get_meta();

			if ( ! is_array( $meta ) ) {
				$meta = array();
			}

			// Skip explicitly hidden abilities — they are intentionally private.
			if ( ! empty( $meta['ai_hidden'] ) && true === $meta['ai_hidden'] ) {
				--$checked_count; // Don't count hidden abilities.
				continue;
			}

			// Check meta.mcp.public === true (canonical nested form).
			$mcp_public = isset( $meta['mcp'] )
				&& is_array( $meta['mcp'] )
				&& array_key_exists( 'public', $meta['mcp'] )
				&& true === $meta['mcp']['public'];

			if ( ! $mcp_public ) {
				$missing_flag[] = $name;
			}
		}

		$this->assertGreaterThan(
			0,
			$checked_count,
			'No sd-ai-agent/* abilities were found in the registry. ' .
			'Check that wp_get_abilities() is functional in the test environment.'
		);

		$this->assertEmpty(
			$missing_flag,
			sprintf(
				'%d sd-ai-agent/* abilit%s missing meta.mcp.public = true: %s',
				count( $missing_flag ),
				count( $missing_flag ) === 1 ? 'y is' : 'ies are',
				implode( ', ', $missing_flag )
			)
		);
	}
}
