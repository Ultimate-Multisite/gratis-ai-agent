<?php

declare(strict_types=1);
/**
 * Service class for ability exploration domain logic.
 *
 * Extracted from ToolController to separate domain concerns from HTTP handling.
 * Contains the logic for building ability listings with truncation, parameter
 * counting, configuration status checks, and sorting.
 *
 * @package SdAiAgent\Services
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Services;

use SdAiAgent\Abilities\Js\JsAbilityCatalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds ability listings for REST responses.
 */
final class AbilityExplorerService {

	/**
	 * Maximum description length for the settings UI ability list.
	 *
	 * Descriptions longer than this are truncated with an ellipsis.
	 *
	 * @var int
	 */
	private const DESCRIPTION_MAX_LENGTH = 200;

	/**
	 * Get a simple flat list of abilities for the settings UI.
	 *
	 * Returns only the fields needed for the basic abilities list:
	 * name, label, truncated description, and category.
	 * Descriptions are capped at {@see self::DESCRIPTION_MAX_LENGTH} characters.
	 *
	 * @return array<int, array<string, string>> Flat list of ability data.
	 */
	public static function get_abilities_list(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		$abilities = wp_get_abilities();
		$list      = array();

		foreach ( $abilities as $ability ) {
			// @phpstan-ignore-next-line
			$description = $ability->get_description();

			$list[] = array(
				// @phpstan-ignore-next-line
				'name'        => $ability->get_name(),
				// @phpstan-ignore-next-line
				'label'       => $ability->get_label(),
				'description' => self::truncate_description( $description ),
				// @phpstan-ignore-next-line
				'category'    => $ability->get_category(),
			);
		}

		return $list;
	}

	/**
	 * Get a rich ability list for the Abilities Explorer admin page.
	 *
	 * Includes full descriptions, parameter counts, configuration status,
	 * annotation flags, and JS-defined abilities. Sorted by category then label.
	 *
	 * @return array<int, array<string, mixed>> Rich ability data sorted for display.
	 */
	public static function get_explorer_list(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		$abilities = wp_get_abilities();
		$list      = array();

		foreach ( $abilities as $ability ) {
			$list[] = self::format_ability_for_explorer( $ability );
		}

		// Append client-side (JS) abilities from the catalog.
		foreach ( JsAbilityCatalog::get_descriptors() as $descriptor ) {
			$list[] = self::format_js_ability_for_explorer( $descriptor );
		}

		// Sort by category then label for consistent display.
		usort( $list, array( self::class, 'compare_by_category_then_label' ) );

		return $list;
	}

	/**
	 * Truncate a description to the maximum allowed length.
	 *
	 * @param string $description Raw description text.
	 * @return string Truncated description with ellipsis if over the limit.
	 */
	private static function truncate_description( string $description ): string {
		if ( strlen( $description ) > self::DESCRIPTION_MAX_LENGTH ) {
			return substr( $description, 0, self::DESCRIPTION_MAX_LENGTH - 3 ) . '...';
		}
		return $description;
	}

	/**
	 * Format a PHP ability object for the explorer endpoint.
	 *
	 * @param object $ability Ability object from wp_get_abilities().
	 * @return array<string, mixed> Formatted ability data.
	 */
	private static function format_ability_for_explorer( object $ability ): array {
		// @phpstan-ignore-next-line
		$input_schema = $ability->get_input_schema();
		// @phpstan-ignore-next-line
		$meta        = $ability->get_meta();
		$annotations = $meta['annotations'] ?? array();

		$required_params = array();
		if ( ! empty( $input_schema['required'] ) && is_array( $input_schema['required'] ) ) {
			$required_params = $input_schema['required'];
		}

		$param_count = 0;
		if ( ! empty( $input_schema['properties'] ) && is_array( $input_schema['properties'] ) ) {
			$param_count = count( $input_schema['properties'] );
		}

		// @phpstan-ignore-next-line
		$ability_name = $ability->get_name();

		return array(
			'name'            => $ability_name,
			// @phpstan-ignore-next-line
			'label'           => $ability->get_label(),
			// @phpstan-ignore-next-line
			'description'     => $ability->get_description(),
			// @phpstan-ignore-next-line
			'category'        => $ability->get_category(),
			'param_count'     => $param_count,
			'required_params' => $required_params,
			'annotations'     => self::normalise_annotations( $annotations ),
			// @phpstan-ignore-next-line
			'output_schema'   => $ability->get_output_schema(),
			'show_in_rest'    => (bool) ( $meta['show_in_rest'] ?? false ),
		);
	}

	/**
	 * Normalise an ability's MCP annotation set to a strict tri-state shape.
	 *
	 * The MCP specification (and {@see \SdAiAgent\Abilities\AbstractAbility::meta()})
	 * defines `readonly`, `destructive`, and `idempotent` as a tri-state where
	 * `null` means "the ability author did not declare this hint, treat as
	 * unknown". Coercing those nulls to `false` silently mis-classifies
	 * unknown-destructive abilities as safe.
	 *
	 * This helper:
	 *   - Returns `true`/`false` only when the source value is a real boolean.
	 *   - Returns `null` for any other value (missing key, null, junk).
	 *
	 * Downstream consumers (e.g. the abilities-explorer React component) MUST
	 * render the `null` state distinctly from `false` and treat it as
	 * "assume destructive" for any safety decision.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $annotations Raw annotations slot from `WP_Ability::get_meta()`.
	 * @return array{readonly: bool|null, destructive: bool|null, idempotent: bool|null}
	 */
	private static function normalise_annotations( $annotations ): array {
		if ( ! is_array( $annotations ) ) {
			$annotations = array();
		}

		return array(
			'readonly'    => self::read_tristate( $annotations, 'readonly' ),
			'destructive' => self::read_tristate( $annotations, 'destructive' ),
			'idempotent'  => self::read_tristate( $annotations, 'idempotent' ),
		);
	}

	/**
	 * Read a single tri-state annotation slot.
	 *
	 * @param array<string,mixed> $annotations Annotations array.
	 * @param string              $key         Slot to read.
	 * @return bool|null
	 */
	private static function read_tristate( array $annotations, string $key ): ?bool {
		$value = $annotations[ $key ] ?? null;
		return is_bool( $value ) ? $value : null;
	}

	/**
	 * Format a JS ability descriptor for the explorer endpoint.
	 *
	 * @param array<string, mixed> $descriptor JS ability descriptor from JsAbilityCatalog.
	 * @return array<string, mixed> Formatted ability data.
	 */
	private static function format_js_ability_for_explorer( array $descriptor ): array {
		$input_schema    = $descriptor['input_schema'] ?? array();
		$required_params = $input_schema['required'] ?? array();
		$param_count     = isset( $input_schema['properties'] ) ? count( $input_schema['properties'] ) : 0;
		$annotations     = $descriptor['annotations'] ?? array();

		return array(
			'name'            => $descriptor['name'],
			'label'           => $descriptor['label'],
			'description'     => $descriptor['description'],
			'category'        => $descriptor['category'],
			'param_count'     => $param_count,
			'required_params' => $required_params,
			'annotations'     => self::normalise_annotations( $annotations ),
			'output_schema'   => $descriptor['output_schema'] ?? array(),
			'show_in_rest'    => false,
		);
	}

	/**
	 * Comparator for sorting abilities by category then label.
	 *
	 * @param array<string, mixed> $a First ability.
	 * @param array<string, mixed> $b Second ability.
	 * @return int Comparison result.
	 */
	private static function compare_by_category_then_label( array $a, array $b ): int {
		$cat_cmp = strcmp( (string) $a['category'], (string) $b['category'] );
		if ( 0 !== $cat_cmp ) {
			return $cat_cmp;
		}
		return strcmp( (string) $a['label'], (string) $b['label'] );
	}
}
