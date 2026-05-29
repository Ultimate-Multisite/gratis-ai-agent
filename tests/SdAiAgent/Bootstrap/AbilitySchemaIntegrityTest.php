<?php
/**
 * Regression guard: every sd-ai-agent/* ability schema property must have a 'type'.
 *
 * WP core's rest_validate_value_from_schema() reads $args['type'] on every
 * schema node. A missing 'type' emits "Undefined array key 'type'" notices
 * that pollute logs and fail strict deprecation gates. This test walks every
 * registered sd-ai-agent/* ability's input_schema and output_schema and
 * asserts that every nested property definition includes a 'type' entry.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 * @see https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1790
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Bootstrap;

use SdAiAgent\Abilities\AiImageAbilities;
use SdAiAgent\Abilities\BlockAbilities;
use SdAiAgent\Abilities\ContentAbilities;
use SdAiAgent\Abilities\CustomPostTypeAbilities;
use SdAiAgent\Abilities\CustomTaxonomyAbilities;
use SdAiAgent\Abilities\DatabaseAbilities;
use SdAiAgent\Abilities\DesignSystemAbilities;
use SdAiAgent\Abilities\EditorialAbilities;
use SdAiAgent\Abilities\FeedbackAbilities;
use SdAiAgent\Abilities\FileAbilities;
use SdAiAgent\Abilities\GitAbilities;
use SdAiAgent\Abilities\GlobalStylesAbilities;
use SdAiAgent\Abilities\GoogleAnalyticsAbilities;
use SdAiAgent\Abilities\GscAbilities;
use SdAiAgent\Abilities\ImageAbilities;
use SdAiAgent\Abilities\InternetSearchAbilities;
use SdAiAgent\Abilities\KnowledgeAbilities;
use SdAiAgent\Abilities\MarketingAbilities;
use SdAiAgent\Abilities\MediaAbilities;
use SdAiAgent\Abilities\MemoryAbilities;
use SdAiAgent\Abilities\MenuAbilities;
use SdAiAgent\Abilities\NavigationAbilities;
use SdAiAgent\Abilities\OptionsAbilities;
use SdAiAgent\Abilities\PostAbilities;
use SdAiAgent\Abilities\SeoAbilities;
use SdAiAgent\Abilities\SiteHealthAbilities;
use SdAiAgent\Abilities\SkillAbilities;
use SdAiAgent\Abilities\TaxonomyAbilities;
use SdAiAgent\Abilities\UploadMediaAbility;
use SdAiAgent\Abilities\UrlResolverAbilities;
use SdAiAgent\Abilities\UserAbilities;
use SdAiAgent\Abilities\UserManagementAbilities;
use SdAiAgent\Abilities\WordPressAbilities;
use SdAiAgent\Abilities\WpRestAbilities;
use SdAiAgent\Tools\ToolDiscovery;
use WP_UnitTestCase;

/**
 * Assert every nested property in every sd-ai-agent/* ability schema has 'type'.
 */
class AbilitySchemaIntegrityTest extends WP_UnitTestCase {

	/**
	 * Skip when WP 7.0+ Abilities API is unavailable.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_get_abilities' ) || ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'WP 7.0+ Abilities API (wp_register_ability / wp_get_ability / wp_get_abilities) not available.' );
		}
	}

	/**
	 * Every property in every sd-ai-agent/* ability's input_schema and
	 * output_schema must declare a 'type' key.
	 *
	 * Procedure:
	 * 1. Register all sd-ai-agent/* ability groups if not already registered.
	 * 2. Walk every registered ability's input_schema and output_schema.
	 * 3. Recursively assert every property definition includes 'type'.
	 */
	public function test_every_ability_schema_property_has_type(): void {
		$this->ensure_abilities_registered();

		$violations    = array();
		$checked_count = 0;

		/** @var \WP_Ability $ability */
		foreach ( wp_get_abilities() as $ability ) {
			$name = $ability->get_name();

			if ( ! str_starts_with( $name, 'sd-ai-agent/' ) ) {
				continue;
			}

			++$checked_count;

			foreach ( array( 'input_schema', 'output_schema' ) as $kind ) {
				$method = 'input_schema' === $kind ? 'get_input_schema' : 'get_output_schema';
				$schema = $ability->$method();

				if ( empty( $schema ) ) {
					continue;
				}

				$missing = $this->find_properties_missing_type( $schema, "{$name}.{$kind}" );
				if ( ! empty( $missing ) ) {
					$violations = array_merge( $violations, $missing );
				}
			}
		}

		$this->assertGreaterThan( 0, $checked_count, 'Expected at least one sd-ai-agent/* ability to be registered.' );

		$this->assertSame(
			array(),
			$violations,
			sprintf(
				"Found %d schema propert%s missing 'type':\n  - %s",
				count( $violations ),
				1 === count( $violations ) ? 'y' : 'ies',
				implode( "\n  - ", $violations )
			)
		);
	}

	/**
	 * Recursively walk a schema and return paths of properties missing 'type'.
	 *
	 * @param mixed  $schema Schema node (array, stdClass, or scalar).
	 * @param string $path   Dot-path for error messages (e.g. "sd-ai-agent/edit-block-tree.input_schema.properties.position").
	 * @return string[] List of dot-paths where 'type' is missing.
	 */
	private function find_properties_missing_type( mixed $schema, string $path ): array {
		$missing = array();

		if ( ! is_array( $schema ) ) {
			return $missing;
		}

		// Walk properties — each child must have 'type'.
		if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) && ! empty( $schema['properties'] ) ) {
			foreach ( $schema['properties'] as $prop_name => $prop_def ) {
				$prop_path = "{$path}.properties.{$prop_name}";

				if ( ! is_array( $prop_def ) ) {
					// Scalar or stdClass — skip (non-standard but harmless).
					continue;
				}

				if ( empty( $prop_def ) ) {
					// Empty array means no type defined.
					$missing[] = $prop_path;
					continue;
				}

				if ( ! array_key_exists( 'type', $prop_def ) ) {
					$missing[] = $prop_path;
				}

				// Recurse into nested properties and items.
				$nested = $this->find_properties_missing_type( $prop_def, $prop_path );
				$missing = array_merge( $missing, $nested );
			}
		}

		// Recurse into items (for array schemas).
		if ( isset( $schema['items'] ) && is_array( $schema['items'] ) && ! empty( $schema['items'] ) ) {
			$nested = $this->find_properties_missing_type( $schema['items'], "{$path}.items" );
			$missing = array_merge( $missing, $nested );
		}

		// Recurse into combiners (anyOf, oneOf, allOf).
		foreach ( array( 'anyOf', 'oneOf', 'allOf' ) as $combiner ) {
			if ( isset( $schema[ $combiner ] ) && is_array( $schema[ $combiner ] ) ) {
				foreach ( $schema[ $combiner ] as $idx => $sub ) {
					$nested = $this->find_properties_missing_type( $sub, "{$path}.{$combiner}[{$idx}]" );
					$missing = array_merge( $missing, $nested );
				}
			}
		}

		return $missing;
	}

	/**
	 * Register all sd-ai-agent/* ability groups if not already registered.
	 *
	 * Uses the same inline registration pattern as SdAiAgentPublicFlagTest
	 * to avoid depending on the DI container bootstrap ordering.
	 */
	private function ensure_abilities_registered(): void {
		// If abilities are already registered (full-suite run), skip.
		if ( null !== wp_get_ability( 'sd-ai-agent/memory-save' ) ) {
			return;
		}

		// Push the hook onto $wp_current_filter so wp_register_ability()
		// accepts calls outside the normal hook callback context.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standard WordPress test global.
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init';

		MemoryAbilities::register_abilities();
		SkillAbilities::register_abilities();
		KnowledgeAbilities::register_abilities();
		PostAbilities::register_abilities();
		BlockAbilities::register_abilities();
		ContentAbilities::register_abilities();
		FileAbilities::register_abilities();
		MediaAbilities::register_abilities();
		UserAbilities::register_abilities();
		UserManagementAbilities::register_abilities();
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
		TaxonomyAbilities::register_abilities();
		UploadMediaAbility::register_abilities();
		UrlResolverAbilities::register_abilities();
		WpRestAbilities::register_abilities();

		// Meta-tools.
		ToolDiscovery::register_abilities();

		// Also register image abilities via their static register() methods.
		if ( class_exists( \SdAiAgent\Abilities\ImageAbilities\StockImageAbility::class ) ) {
			\SdAiAgent\Abilities\ImageAbilities\StockImageAbility::register();
		}
		if ( class_exists( AiImageAbilities::class ) ) {
			AiImageAbilities::register_abilities();
		}

		array_pop( $wp_current_filter );
	}
}
