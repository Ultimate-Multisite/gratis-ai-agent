<?php

declare(strict_types=1);
/**
 * Canonical active-theme Global Styles reads and persistence.
 *
 * @package SdAiAgent\Services
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Services;

use WP_Error;
use WP_Post;
use WP_Theme_JSON;
use WP_Theme_JSON_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the user-level wp_global_styles document for the active stylesheet.
 */
final class GlobalStylesService {

	/**
	 * Whether the active-theme post has already been resolved for this service instance.
	 *
	 * @var bool
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase -- Project property naming guidance requires camelCase.
	private bool $userPostLoaded = false;

	/**
	 * The canonical active-theme Global Styles post, when one exists.
	 *
	 * @var WP_Post|null
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase -- Project property naming guidance requires camelCase.
	private ?WP_Post $userPost = null;

	/**
	 * Get WordPress's resolved active-theme styles view.
	 *
	 * @return array<string,mixed> Core, theme, and user styles merged by WordPress.
	 */
	public function get_resolved_styles(): array {
		// Resolve a safely identifiable pre-service record before asking WordPress
		// for its merged view. New records already use the wp_theme taxonomy.
		$this->get_user_post();

		$styles = wp_get_global_styles();

		return is_array( $styles ) ? self::string_keyed_array( $styles ) : [];
	}

	/**
	 * Get the complete saved user-level theme.json document.
	 *
	 * @return array<string,mixed> Saved document, or an empty array when none exists.
	 */
	public function get_user_document(): array {
		$post = $this->get_user_post();
		if ( ! $post instanceof WP_Post ) {
			return [];
		}

		$document = json_decode( $post->post_content, true );

		return is_array( $document ) ? self::string_keyed_array( $document ) : [];
	}

	/**
	 * Get the active-theme user Global Styles post ID without creating a post.
	 */
	public function get_user_post_id(): ?int {
		$post = $this->get_user_post();

		return $post instanceof WP_Post ? (int) $post->ID : null;
	}

	/**
	 * Deep-merge a partial document into the active theme's user document.
	 *
	 * @param array<string,mixed> $partial Partial settings/styles document.
	 * @return array{post_id:int,document:array<string,mixed>}|WP_Error Saved document details, or an error.
	 */
	public function merge_user_document( array $partial ): array|WP_Error {
		$post     = $this->get_user_post();
		$document = [];

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( $post instanceof WP_Post ) {
			$decoded = json_decode( $post->post_content, true );
			if ( is_array( $decoded ) ) {
				$document = $decoded;
			}
		}

		$document                                = self::deep_merge( $document, $partial );
		$document['version']                     = WP_Theme_JSON::LATEST_SCHEMA;
		$document['isGlobalStylesUserThemeJSON'] = true;
		$json                                    = wp_json_encode(
			$document,
			JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
		);

		if ( false === $json ) {
			return new WP_Error(
				'json_encode_failed',
				__( 'Failed to encode styles as JSON.', 'superdav-ai-agent' )
			);
		}

		$created = false;
		if ( ! $post instanceof WP_Post ) {
			$post = $this->create_user_post();
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			$created = true;
		}

		$updated = wp_update_post(
			[
				'ID'           => $post->ID,
				'post_content' => $json,
			],
			true
		);

		if ( is_wp_error( $updated ) ) {
			if ( $created ) {
				wp_delete_post( $post->ID, true );
				$this->userPost = null;
			}
			return $updated;
		}

		$post->post_content   = $json;
		$this->userPost       = $post;
		$this->userPostLoaded = true;
		wp_clean_theme_json_cache();

		return [
			'post_id'  => (int) $post->ID,
			'document' => $document,
		];
	}

	/**
	 * Delete only the active stylesheet's canonical user document.
	 *
	 * @return bool|WP_Error True when deleted, false when absent, or an error on failure.
	 */
	public function delete_user_document(): bool|WP_Error {
		$post = $this->get_user_post();
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		$deleted = wp_delete_post( $post->ID, true );
		if ( ! $deleted instanceof WP_Post ) {
			return new WP_Error(
				'global_styles_delete_failed',
				__( 'Failed to delete the global styles override.', 'superdav-ai-agent' )
			);
		}

		$this->userPost       = null;
		$this->userPostLoaded = true;
		wp_clean_theme_json_cache();

		return true;
	}

	/**
	 * Resolve the active stylesheet's canonical post without creating one.
	 *
	 * @return WP_Post|WP_Error|null Canonical post, adoption error, or no post.
	 */
	private function get_user_post(): WP_Post|WP_Error|null {
		if ( $this->userPostLoaded ) {
			return $this->userPost;
		}

		$this->userPostLoaded = true;

		$post = $this->get_canonical_user_post();
		if ( $post instanceof WP_Post ) {
			$this->userPost = $post;

			return $this->userPost;
		}

		$legacy_post = $this->adopt_safe_legacy_post();
		if ( is_wp_error( $legacy_post ) ) {
			// Keep a transient adoption failure retryable on this service instance.
			$this->userPostLoaded = false;

			return $legacy_post;
		}

		$this->userPost = $legacy_post;

		return $this->userPost;
	}

	/**
	 * Resolve the active stylesheet's canonical post directly from WordPress.
	 */
	private function get_canonical_user_post(): ?WP_Post {
		$user_data = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme() );
		$post_id   = isset( $user_data['ID'] ) ? (int) $user_data['ID'] : 0;

		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( $post instanceof WP_Post && 'wp_global_styles' === $post->post_type ) {
				return $post;
			}
		}

		return null;
	}

	/**
	 * Create a post with WordPress's canonical Global Styles fields.
	 *
	 * @return WP_Post|WP_Error Created canonical post or an error.
	 */
	private function create_user_post(): WP_Post|WP_Error {
		$existing = $this->get_canonical_user_post();
		if ( $existing instanceof WP_Post ) {
			$this->userPost       = $existing;
			$this->userPostLoaded = true;

			return $existing;
		}

		$stylesheet = get_stylesheet();
		$post_id    = wp_insert_post(
			[
				'post_content' => '{"version": ' . WP_Theme_JSON::LATEST_SCHEMA . ', "isGlobalStylesUserThemeJSON": true }',
				'post_status'  => 'publish',
				// Do not make string translatable, see https://core.trac.wordpress.org/ticket/54518.
				'post_title'   => 'Custom Styles',
				'post_type'    => 'wp_global_styles',
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode -- Mirrors WordPress core's canonical Global Styles post name.
				'post_name'    => sprintf( 'wp-global-styles-%s', urlencode( $stylesheet ) ),
				'tax_input'    => [
					'wp_theme' => [ $stylesheet ],
				],
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'wp_global_styles' !== $post->post_type ) {
			return new WP_Error(
				'global_styles_create_failed',
				__( 'Failed to create the global styles override.', 'superdav-ai-agent' )
			);
		}

		$assigned = $this->ensure_active_theme_assignment( $post );
		if ( is_wp_error( $assigned ) ) {
			wp_delete_post( $post->ID, true );

			return $assigned;
		}

		$canonical = $this->get_canonical_user_post();
		if ( $canonical instanceof WP_Post && $canonical->ID !== $post->ID ) {
			wp_delete_post( $post->ID, true );
			$this->userPost       = $canonical;
			$this->userPostLoaded = true;

			return $canonical;
		}

		$this->userPost       = $post;
		$this->userPostLoaded = true;

		return $post;
	}

	/**
	 * Adopt one historical record only when its active-theme identity is unambiguous.
	 *
	 * Earlier ability implementations used the exact post name and `link` meta
	 * instead of WordPress's `wp_theme` taxonomy. An on-access adoption preserves
	 * those active-theme documents without selecting the old unrestricted latest
	 * post fallback. Ambiguous records and records assigned to another theme are
	 * left untouched.
	 */
	private function adopt_safe_legacy_post(): WP_Post|WP_Error|null {
		$stylesheet = get_stylesheet();
		$identity   = 'wp-global-styles-' . $stylesheet;
		$candidates = [];

		$name_posts = get_posts(
			[
				'post_type'      => 'wp_global_styles',
				'post_status'    => 'publish',
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode -- Mirrors WordPress core's canonical Global Styles post name.
				'name'           => sprintf( 'wp-global-styles-%s', urlencode( $stylesheet ) ),
				'posts_per_page' => 2,
				'no_found_rows'  => true,
			]
		);

		foreach ( $name_posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$candidates[ $post->ID ] = $post;
			}
		}

		$meta_posts = get_posts(
			[
				'post_type'      => 'wp_global_styles',
				'post_status'    => 'publish',
				'posts_per_page' => 2,
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One-time compatibility lookup scoped to an exact active-theme identity.
					[
						'key'   => 'link',
						'value' => $identity,
					],
				],
				'no_found_rows'  => true,
			]
		);

		foreach ( $meta_posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$candidates[ $post->ID ] = $post;
			}
		}

		if ( 1 !== count( $candidates ) ) {
			return null;
		}

		$post = reset( $candidates );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		$original_content = $post->post_content;
		$document         = json_decode( $original_content, true );
		if ( ! is_array( $document ) ) {
			return new WP_Error(
				'global_styles_legacy_document_invalid',
				__( 'The legacy global styles override contains invalid JSON.', 'superdav-ai-agent' )
			);
		}

		$terms = wp_get_object_terms( $post->ID, 'wp_theme', [ 'fields' => 'names' ] );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		if ( ! empty( $terms ) && ! in_array( $stylesheet, $terms, true ) ) {
			return null;
		}

		$document['version']                     = $document['version'] ?? WP_Theme_JSON::LATEST_SCHEMA;
		$document['isGlobalStylesUserThemeJSON'] = true;
		$json                                    = wp_json_encode(
			$document,
			JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
		);

		if ( false === $json ) {
			return new WP_Error(
				'global_styles_adoption_encode_failed',
				__( 'Failed to encode the legacy global styles override.', 'superdav-ai-agent' )
			);
		}

		$content_updated = false;
		if ( $json !== $post->post_content ) {
			$updated = wp_update_post(
				[
					'ID'           => $post->ID,
					'post_content' => $json,
				],
				true
			);
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
			$content_updated = true;
		}

		$assigned = $this->ensure_active_theme_assignment( $post );
		if ( is_wp_error( $assigned ) ) {
			$rollback_error = $this->rollback_legacy_adoption(
				$post,
				$original_content,
				$terms,
				$content_updated
			);

			if ( is_wp_error( $rollback_error ) ) {
				return new WP_Error(
					'global_styles_adoption_rollback_failed',
					__( 'Failed to restore the legacy global styles override after adoption failed.', 'superdav-ai-agent' ),
					[
						'adoption_error' => $assigned->get_error_code(),
						'rollback_error' => $rollback_error->get_error_code(),
					]
				);
			}

			return $assigned;
		}

		$post->post_content = $json;
		wp_clean_theme_json_cache();

		return $post;
	}

	/**
	 * Restore a legacy post after the second half of adoption fails.
	 *
	 * @param WP_Post           $post             Legacy post being restored.
	 * @param string            $original_content Content before normalization.
	 * @param array<int,string> $original_terms   Theme terms before assignment.
	 * @param bool              $restore_content  Whether normalization was persisted.
	 * @return WP_Error|null Rollback error, when restoration cannot complete.
	 */
	private function rollback_legacy_adoption(
		WP_Post $post,
		string $original_content,
		array $original_terms,
		bool $restore_content
	): ?WP_Error {
		$rollback_error = null;

		if ( $restore_content ) {
			$restored = wp_update_post(
				[
					'ID'           => $post->ID,
					'post_content' => $original_content,
				],
				true
			);
			if ( is_wp_error( $restored ) ) {
				$rollback_error = $restored;
			}
		}

		$restored_terms = wp_set_object_terms( $post->ID, $original_terms, 'wp_theme' );
		if ( is_wp_error( $restored_terms ) && ! is_wp_error( $rollback_error ) ) {
			$rollback_error = $restored_terms;
		}

		return $rollback_error;
	}

	/**
	 * Ensure a new or safely adopted post belongs only to the active stylesheet.
	 *
	 * @return true|WP_Error True on success or an assignment error.
	 */
	private function ensure_active_theme_assignment( WP_Post $post ): true|WP_Error {
		$stylesheet = get_stylesheet();
		$terms      = wp_get_object_terms( $post->ID, 'wp_theme', [ 'fields' => 'names' ] );

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		if ( in_array( $stylesheet, $terms, true ) ) {
			return true;
		}

		if ( ! empty( $terms ) ) {
			return new WP_Error(
				'global_styles_theme_mismatch',
				__( 'The global styles override belongs to another theme.', 'superdav-ai-agent' )
			);
		}

		$assigned = wp_set_object_terms( $post->ID, $stylesheet, 'wp_theme' );

		return is_wp_error( $assigned ) ? $assigned : true;
	}

	/**
	 * Recursively merge arrays, replacing scalar values with the override.
	 *
	 * @param array<string,mixed> $base     Existing document fragment.
	 * @param array<string,mixed> $override New document fragment.
	 * @return array<string,mixed> Merged fragment.
	 */
	private static function deep_merge( array $base, array $override ): array {
		foreach ( $override as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ) {
				$base[ $key ] = self::deep_merge( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}

		return $base;
	}

	/**
	 * Retain the string-keyed top-level shape required by theme.json documents.
	 *
	 * @param array<mixed> $data Untrusted decoded or WordPress-returned data.
	 * @return array<string,mixed> String-keyed document data.
	 */
	private static function string_keyed_array( array $data ): array {
		$result = [];

		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) ) {
				$result[ $key ] = $value;
			}
		}

		return $result;
	}
}
