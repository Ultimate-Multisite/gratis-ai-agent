<?php

declare(strict_types=1);
/**
 * SchemaExampleBuilder
 *
 * Helpers for turning a JSON-Schema input definition + a validation error
 * message into copy-paste-friendly hints that weak models can act on.
 *
 * Two operations:
 *
 *   • {@see build_example()} — walk an input_schema and produce a stub
 *     `example_arguments` object containing every required field, with
 *     `<type — description>` placeholder values that the model substitutes.
 *
 *   • {@see extract_missing_required()} — pull the names of the missing
 *     required fields out of a `WP_Ability::validate_input()` error
 *     message such as "username is a required property of input." so the
 *     model gets the most specific signal first.
 *
 * Used by AbilityFunctionResolver and ToolDiscovery::handle_ability_call to
 * enrich `ability_invalid_input` responses.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SchemaExampleBuilder {

	/**
	 * Walk an input_schema and produce a stub arguments object containing
	 * every required field with a placeholder value of the form
	 * `<{type} — {description}>`. Nested required object/array fields are
	 * expanded so nudges for complex tools show a usable argument shape instead
	 * of a top-level `<object>` placeholder. Optional top-level fields are
	 * omitted.
	 *
	 * Returns an empty array when the schema has no required fields, no
	 * properties, or is malformed.
	 *
	 * @param mixed $schema The ability input_schema (assoc array).
	 * @return array<string, mixed>
	 */
	public static function build_example( $schema ): array {
		if ( ! is_array( $schema ) ) {
			return array();
		}

		$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
		$required   = self::get_required_fields( $schema );

		if ( empty( $required ) ) {
			return array();
		}

		$example = array();
		foreach ( $required as $field ) {
			if ( ! is_string( $field ) || '' === $field ) {
				continue;
			}
			$prop              = isset( $properties[ $field ] ) && is_array( $properties[ $field ] ) ? $properties[ $field ] : array();
			$example[ $field ] = self::build_value( $prop );
		}

		return $example;
	}

	/**
	 * Return the fields required by an input schema.
	 *
	 * @param mixed $schema The ability input_schema (assoc array).
	 * @return string[]
	 */
	public static function get_required_fields( $schema ): array {
		if ( ! is_array( $schema ) ) {
			return array();
		}

		return self::filter_required_fields( $schema['required'] ?? array() );
	}

	/**
	 * Keep only usable required field names from a schema value.
	 *
	 * @param mixed $fields Schema required value.
	 * @return string[]
	 */
	private static function filter_required_fields( $fields ): array {
		if ( ! is_array( $fields ) ) {
			return array();
		}

		return array_values( array_filter( $fields, static fn( $field ): bool => is_string( $field ) && '' !== $field ) );
	}

	/**
	 * Build an example value for a single schema property.
	 *
	 * @param array<string, mixed> $prop  Property schema.
	 * @param int                  $depth Current recursion depth.
	 * @return mixed Example value.
	 */
	private static function build_value( array $prop, int $depth = 0 ): mixed {
		$type_raw = isset( $prop['type'] ) ? $prop['type'] : 'value';
		$type     = is_array( $type_raw )
			? implode( '|', array_map( static fn( $value ): string => is_scalar( $value ) || null === $value ? (string) $value : gettype( $value ), $type_raw ) )
			: (string) $type_raw;

		if ( $depth < 4 && str_contains( $type, 'object' ) && isset( $prop['properties'] ) && is_array( $prop['properties'] ) && ! empty( $prop['properties'] ) ) {
			$fields = isset( $prop['required'] ) && is_array( $prop['required'] ) && ! empty( $prop['required'] )
				? array_values( array_filter( $prop['required'], 'is_string' ) )
				: array_keys( $prop['properties'] );

			$value = array();
			foreach ( $fields as $field ) {
				if ( ! is_string( $field ) || ! isset( $prop['properties'][ $field ] ) || ! is_array( $prop['properties'][ $field ] ) ) {
					continue;
				}
				$value[ $field ] = self::build_value( $prop['properties'][ $field ], $depth + 1 );
			}

			if ( ! empty( $value ) ) {
				return $value;
			}
		}

		if ( $depth < 4 && str_contains( $type, 'array' ) && isset( $prop['items'] ) && is_array( $prop['items'] ) && ! empty( $prop['items'] ) ) {
			return array( self::build_value( $prop['items'], $depth + 1 ) );
		}

		return self::placeholder( $type, $prop );
	}

	/**
	 * Build a scalar placeholder for a schema property.
	 *
	 * @param string               $type Property type label.
	 * @param array<string, mixed> $prop Property schema.
	 * @return string Placeholder string.
	 */
	private static function placeholder( string $type, array $prop ): string {
		$desc = isset( $prop['description'] ) ? trim( (string) $prop['description'] ) : '';
		if ( strlen( $desc ) > 80 ) {
			$desc = substr( $desc, 0, 77 ) . '...';
		}

		// If the schema lists an enum, hint with the allowed values
		// instead of the description — much more actionable.
		if ( isset( $prop['enum'] ) && is_array( $prop['enum'] ) && ! empty( $prop['enum'] ) ) {
			$enum_summary = implode( '|', array_map( static fn( $v ) => (string) $v, array_slice( $prop['enum'], 0, 5 ) ) );
			$desc         = "one of: {$enum_summary}";
		}

		return '' !== $desc
			? "<{$type} — {$desc}>"
			: "<{$type}>";
	}

	/**
	 * Extract the names of missing required fields from a validation
	 * error message produced by `WP_Ability::validate_input()`.
	 *
	 * Handles two phrasings the WP REST validator emits:
	 *   - "{field} is a required property of input."
	 *   - "{field} is a required property of {param}."
	 *
	 * Returns an empty array when no field names can be extracted.
	 *
	 * @param string $error_message The validation error message.
	 * @return string[]
	 */
	public static function extract_missing_required( string $error_message ): array {
		if ( '' === $error_message ) {
			return array();
		}

		$matches = array();
		// Match patterns like `xxx is a required property` (the "of input"
		// suffix is optional and depends on the validator path).
		if ( preg_match_all( '/`?([\w_-]+)`?\s+is\s+a\s+required\s+property/i', $error_message, $matches ) ) {
			if ( ! empty( $matches[1] ) ) {
				return array_values( array_unique( $matches[1] ) );
			}
		}

		return array();
	}
}
