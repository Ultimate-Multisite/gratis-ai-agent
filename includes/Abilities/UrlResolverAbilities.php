<?php
/**
 * URL Resolver abilities for the AI agent.
 *
 * Provides the sd-ai-agent/resolve-url ability that translates a URL
 * or post slug into structured post metadata (post_id, post_type,
 * post_status, edit_link, permalink, title, matched_via).
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles the sd-ai-agent/resolve-url ability.
 *
 * Resolution order:
 *  1. url_to_postid() — handles absolute URLs (permalink or query-string style)
 *     and relative query-string inputs such as ?p=N or ?page_id=N.
 *  2. get_page_by_path() — fallback for bare slugs or path-like inputs that
 *     url_to_postid() cannot resolve.
 *
 * Cross-host URLs are rejected before any resolution attempt.
 * Draft / private posts are only returned when the current user holds the
 * edit_post capability for that specific post.
 */
class UrlResolverAbilities {

	/**
	 * Register the sd-ai-agent/resolve-url ability.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/resolve-url',
			[
				'label'               => __( 'Resolve URL', 'superdav-ai-agent' ),
				'description'         => __( 'Resolve a URL or post slug to its post ID, type, status, edit link, and permalink. Use before any post-editing ability to look up the post_id without scanning list-posts.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'url' => [
							'type'        => 'string',
							'description' => 'Full URL (e.g. "https://example.com/about/"), relative query string (e.g. "?p=123"), or bare slug (e.g. "about") to resolve.',
						],
					],
					'required'   => [ 'url' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'post_id'     => [ 'type' => 'integer' ],
						'post_type'   => [ 'type' => 'string' ],
						'post_status' => [ 'type' => 'string' ],
						'edit_link'   => [ 'type' => 'string' ],
						'permalink'   => [ 'type' => 'string' ],
						'title'       => [ 'type' => 'string' ],
						'matched_via' => [
							'type' => 'string',
							'enum' => [ 'url_to_postid', 'slug_lookup' ],
						],
					],
				],
				'execute_callback'    => [ __CLASS__, 'handle_resolve_url' ],
				'permission_callback' => static function (): bool {
					return (bool) current_user_can( 'edit_posts' );
				},
				'meta'                => [
					'mcp'         => [ 'public' => true ],
					'annotations' => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
				],
			]
		);
	}

	/**
	 * Execute the resolve-url ability.
	 *
	 * @param array<string, mixed> $params Ability parameters; expects 'url' key.
	 * @return array<string, mixed>|\WP_Error Resolved post data or a WP_Error.
	 */
	public static function handle_resolve_url( array $params ): array|\WP_Error {
		$input = isset( $params['url'] ) ? trim( (string) $params['url'] ) : '';

		if ( '' === $input ) {
			return new \WP_Error(
				'missing_url',
				__( 'The "url" parameter is required.', 'superdav-ai-agent' )
			);
		}

		$attempts    = [];
		$post_id     = 0;
		$matched_via = '';
		$is_absolute = str_contains( $input, '://' );

		// Guard: reject cross-host URLs immediately — never resolve external sites.
		if ( $is_absolute ) {
			$input_host = (string) wp_parse_url( $input, PHP_URL_HOST );
			$site_host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			if ( '' !== $input_host && '' !== $site_host && $input_host !== $site_host ) {
				return new \WP_Error(
					'external_host',
					sprintf(
						/* translators: %s: external hostname */
						__( 'URL host "%s" does not match this site. Cross-host resolution is not supported.', 'superdav-ai-agent' ),
						$input_host
					),
					[
						'host'      => $input_host,
						'site_host' => $site_host,
					]
				);
			}
		}

		// Strategy 1: url_to_postid() — reliable for absolute URLs and relative
		// query-string inputs (e.g. ?p=123, ?page_id=456).
		if ( $is_absolute || str_starts_with( $input, '?' ) || str_starts_with( $input, '/' ) ) {
			$lookup_url  = $is_absolute ? $input : home_url( $input );
			$attempts[]  = 'url_to_postid';
			$resolved_id = url_to_postid( $lookup_url );
			if ( $resolved_id > 0 ) {
				$post_id     = $resolved_id;
				$matched_via = 'url_to_postid';
			}
		}

		// Strategy 2: get_page_by_path() — handles bare slugs and hierarchical
		// slug paths (e.g. "about" or "services/consulting") that url_to_postid()
		// cannot resolve without rewrite rules.
		if ( 0 === $post_id ) {
			if ( $is_absolute ) {
				// Derive slug from the URL path.
				$slug = trim( (string) wp_parse_url( $input, PHP_URL_PATH ), '/' );
			} else {
				// Strip query string and URL fragment; trim slashes.
				$without_query = (string) explode( '?', $input )[0];
				$slug          = trim( (string) explode( '#', $without_query )[0], '/' );
			}

			if ( '' !== $slug ) {
				$attempts[]   = 'slug_lookup';
				$public_types = array_keys( get_post_types( [ 'public' => true ] ) );
				$post_obj     = get_page_by_path( $slug, OBJECT, $public_types );
				if ( $post_obj instanceof \WP_Post ) {
					$post_id     = $post_obj->ID;
					$matched_via = 'slug_lookup';
				}
			}
		}

		if ( 0 === $post_id ) {
			return new \WP_Error(
				'not_found',
				__( 'No post found for the given URL or slug.', 'superdav-ai-agent' ),
				[
					'input'    => $input,
					'attempts' => $attempts,
				]
			);
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error(
				'not_found',
				__( 'No post found for the given URL or slug.', 'superdav-ai-agent' ),
				[
					'input'    => $input,
					'attempts' => $attempts,
				]
			);
		}

		// Visibility gate: drafts and private posts require edit_post capability.
		// Published posts are always returned — no capability check needed.
		if ( 'publish' !== $post->post_status && ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'not_found',
				__( 'No post found for the given URL or slug.', 'superdav-ai-agent' ),
				[
					'input'    => $input,
					'attempts' => $attempts,
				]
			);
		}

		return [
			'post_id'     => $post_id,
			'post_type'   => $post->post_type,
			'post_status' => $post->post_status,
			'edit_link'   => (string) get_edit_post_link( $post_id, 'raw' ),
			'permalink'   => (string) get_permalink( $post_id ),
			'title'       => $post->post_title,
			'matched_via' => $matched_via,
		];
	}
}
