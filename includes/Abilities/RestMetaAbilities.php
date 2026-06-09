<?php

declare(strict_types=1);
/**
 * REST meta diagnostics for the AI agent.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only diagnostics for WordPress REST post meta writability.
 */
class RestMetaAbilities {

	/**
	 * Ability ID.
	 */
	public const ABILITY_ID = 'sd-ai-agent/inspect-rest-meta';

	/**
	 * Register REST meta diagnostic ability.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			self::ABILITY_ID,
			[
				'label'               => __( 'Inspect REST Meta', 'superdav-ai-agent' ),
				'description'         => __( 'Diagnose whether WordPress REST post meta writes can persist for a post type and optional meta key. Reports CPT REST visibility, custom-fields support, registered REST-visible meta, and actionable recommendations for common 200 OK-but-dropped meta writes.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'post_type' => [
							'type'        => 'string',
							'description' => 'Post type slug to inspect, e.g. "post", "page", or a custom post type.',
						],
						'meta_key'  => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Input schema field name, not a query argument.
							'type'        => 'string',
							'description' => 'Optional meta key to focus the diagnostic on.',
						],
					],
					'required'             => [ 'post_type' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'post_type'              => [ 'type' => 'string' ],
						'post_type_exists'       => [ 'type' => 'boolean' ],
						'rest_base'              => [ 'type' => [ 'string', 'null' ] ],
						'show_in_rest'           => [ 'type' => 'boolean' ],
						'supports_custom_fields' => [ 'type' => 'boolean' ],
						'rest_meta_writable'     => [ 'type' => 'boolean' ],
						'registered_meta_count'  => [ 'type' => 'integer' ],
						'registered_meta'        => [ 'type' => 'array' ],
						'recommendations'        => [ 'type' => 'array' ],
						'gotcha_note'            => [ 'type' => 'string' ],
					],
				],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
				'execute_callback'    => [ __CLASS__, 'handle_inspect_rest_meta' ],
				'permission_callback' => static function (): bool {
					return ToolCapabilities::current_user_can( self::ABILITY_ID );
				},
			]
		);
	}

	/**
	 * Handle a REST meta diagnostic request.
	 *
	 * @param array<string,mixed> $input Input arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function handle_inspect_rest_meta( array $input = array() ) {
		$post_type = isset( $input['post_type'] ) ? trim( (string) $input['post_type'] ) : '';
		$meta_key  = isset( $input['meta_key'] ) ? trim( (string) $input['meta_key'] ) : '';

		if ( '' === $post_type ) {
			return new WP_Error(
				'sd_ai_agent_rest_meta_missing_post_type',
				__( 'A `post_type` is required for REST meta diagnostics.', 'superdav-ai-agent' ),
				[ 'status' => 400 ]
			);
		}

		$post_type_object       = get_post_type_object( $post_type );
		$post_type_exists       = null !== $post_type_object;
		$show_in_rest           = $post_type_exists ? (bool) $post_type_object->show_in_rest : false;
		$rest_base              = null;
		$supports_custom_fields = $post_type_exists ? post_type_supports( $post_type, 'custom-fields' ) : false;
		$registered_meta        = array();

		if ( $post_type_exists ) {
			$rest_base       = is_string( $post_type_object->rest_base ) && '' !== $post_type_object->rest_base ? $post_type_object->rest_base : $post_type;
			$registered_meta = self::summarize_registered_meta( $post_type, $meta_key );
		}

		$rest_visible_count = 0;
		foreach ( $registered_meta as $entry ) {
			if ( true === ( $entry['show_in_rest'] ?? false ) ) {
				++$rest_visible_count;
			}
		}

		$has_required_meta = '' === $meta_key ? $rest_visible_count > 0 : self::contains_rest_visible_meta_key( $registered_meta, $meta_key );
		$rest_writable     = $post_type_exists && $show_in_rest && $supports_custom_fields && $has_required_meta;
		$recommendations   = self::build_recommendations( $post_type, $meta_key, $post_type_exists, $show_in_rest, $supports_custom_fields, $has_required_meta );

		return [
			'post_type'              => $post_type,
			'post_type_exists'       => $post_type_exists,
			'rest_base'              => $rest_base,
			'show_in_rest'           => $show_in_rest,
			'supports_custom_fields' => $supports_custom_fields,
			'rest_meta_writable'     => $rest_writable,
			'registered_meta_count'  => count( $registered_meta ),
			'registered_meta'        => $registered_meta,
			'recommendations'        => $recommendations,
			'gotcha_note'            => __( 'WordPress REST post meta writes can return 200 OK while dropping meta when the post type is not REST-visible, lacks custom-fields support, or the meta key is not registered with show_in_rest.', 'superdav-ai-agent' ),
		];
	}

	/**
	 * Summarize registered post meta without exposing callbacks or values.
	 *
	 * @param string $post_type Post type slug.
	 * @param string $meta_key  Optional meta key filter.
	 * @return array<int,array<string,mixed>>
	 */
	private static function summarize_registered_meta( string $post_type, string $meta_key = '' ): array {
		$registered = get_registered_meta_keys( 'post', $post_type );
		$entries    = array();

		foreach ( $registered as $key => $args ) {
			if ( '' !== $meta_key && (string) $key !== $meta_key ) {
				continue;
			}

			if ( ! is_array( $args ) ) {
				continue;
			}

			$entries[ (string) $key ] = [
				'key'                   => (string) $key,
				'type'                  => isset( $args['type'] ) ? (string) $args['type'] : 'string',
				'single'                => ! empty( $args['single'] ),
				'show_in_rest'          => ! empty( $args['show_in_rest'] ),
				'has_default'           => array_key_exists( 'default', $args ),
				'has_sanitize_callback' => ! empty( $args['sanitize_callback'] ),
				'has_auth_callback'     => ! empty( $args['auth_callback'] ),
			];
		}

		ksort( $entries );

		return array_values( $entries );
	}

	/**
	 * Check whether filtered entries contain a REST-visible meta key.
	 *
	 * @param array<int,array<string,mixed>> $registered_meta Registered meta summaries.
	 * @param string                         $meta_key        Requested key.
	 */
	private static function contains_rest_visible_meta_key( array $registered_meta, string $meta_key ): bool {
		foreach ( $registered_meta as $entry ) {
			if ( $meta_key === ( $entry['key'] ?? '' ) && true === ( $entry['show_in_rest'] ?? false ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build actionable recommendations.
	 *
	 * @param string $post_type              Post type slug.
	 * @param string $meta_key               Optional meta key.
	 * @param bool   $post_type_exists       Whether the post type exists.
	 * @param bool   $show_in_rest           Whether the post type is REST-visible.
	 * @param bool   $supports_custom_fields Whether the post type supports custom fields.
	 * @param bool   $has_required_meta      Whether requested/any meta is REST-visible.
	 * @return string[]
	 */
	private static function build_recommendations( string $post_type, string $meta_key, bool $post_type_exists, bool $show_in_rest, bool $supports_custom_fields, bool $has_required_meta ): array {
		$recommendations = array();

		if ( ! $post_type_exists ) {
			return [ sprintf( 'Register the `%s` post type before attempting REST meta writes.', $post_type ) ];
		}

		if ( ! $show_in_rest ) {
			$recommendations[] = sprintf( 'Register `%s` with `show_in_rest => true` so /wp/v2 routes accept the post type.', $post_type );
		}

		if ( ! $supports_custom_fields ) {
			$recommendations[] = sprintf( 'Add `custom-fields` support for `%s` before writing REST meta.', $post_type );
		}

		if ( ! $has_required_meta ) {
			if ( '' === $meta_key ) {
				$recommendations[] = sprintf( 'Register each writable key with `register_post_meta( "%s", $key, [ "show_in_rest" => true, ... ] )`.', $post_type );
			} else {
				$recommendations[] = sprintf( 'Register meta key `%s` for `%s` with `show_in_rest => true`, or use a different write path for non-REST meta.', $meta_key, $post_type );
			}
		}

		if ( array() === $recommendations ) {
			$recommendations[] = __( 'REST meta writes should persist for the inspected key(s). If writes still fail, inspect auth_callback behaviour and the REST request body.', 'superdav-ai-agent' );
		}

		return $recommendations;
	}
}
