<?php

declare(strict_types=1);
/**
 * Validated lifecycle management for active-theme style variations.
 *
 * @package SdAiAgent\Services
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Services;

use SdAiAgent\Core\ChangeLogger;
use SdAiAgent\Core\Database;
use SdAiAgent\Core\Filesystem\FileModGate;
use SdAiAgent\DesignSystem\ArtifactManifest;
use SdAiAgent\Models\ChangesLog;
use WP_Error;
use WP_Post;
use WP_Theme;
use WP_Theme_JSON;
use WP_Theme_JSON_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns safe style-variation file writes and exact Global Styles rollback state.
 */
final class StyleVariationManager {

	/**
	 * Site-scoped option holding per-stylesheet managed selection provenance.
	 */
	public const STATE_OPTION = 'sd_ai_agent_style_variation_state';

	/**
	 * Stored selection-state format version.
	 */
	private const STATE_VERSION = 1;

	/**
	 * Canonical schema emitted by the design-token compiler.
	 */
	private const SCHEMA_URL = 'https://schemas.wp.org/trunk/theme.json';

	/**
	 * Conservative filename and WordPress stylesheet slug pattern.
	 */
	private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]*$/';

	/**
	 * List active stylesheet and inherited parent style variations.
	 *
	 * Parent records remain visible so agents can explain precedence, but are
	 * explicitly read-only. A child file with the same slug is listed first and
	 * wins when selecting a variation, matching WordPress core's resolver.
	 *
	 * @return array<string,mixed>|WP_Error Variation inventory or an error.
	 */
	public function list(): array|WP_Error {
		$theme = $this->active_theme();
		if ( is_wp_error( $theme ) ) {
			return $theme;
		}

		$state_map = $this->read_state_map();
		if ( is_wp_error( $state_map ) ) {
			return $state_map;
		}
		$state = $this->state_for_stylesheet( $state_map, $theme->get_stylesheet() );
		if ( is_wp_error( $state ) ) {
			return $state;
		}

		$variations = [];
		foreach ( $this->variation_sources( $theme ) as $source ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading one installed theme JSON document for a read-only inventory.
			$content = file_get_contents( $source['absolute_path'] );
			if ( false === $content ) {
				$variations[] = [
					'slug'          => $source['slug'],
					'title'         => $source['slug'],
					'origin'        => $source['origin'],
					'relative_path' => $source['relative_path'],
					'read_only'     => $source['read_only'],
					'hash'          => null,
					'selected'      => false,
					'valid'         => false,
					'error'         => __( 'The style variation could not be read.', 'superdav-ai-agent' ),
				];
				continue;
			}

			$decoded    = json_decode( $content, true );
			$validated  = is_array( $decoded ) ? $this->validate_document( $decoded ) : new WP_Error(
				'sd_ai_agent_style_variation_invalid_json',
				__( 'The style variation does not contain a JSON object.', 'superdav-ai-agent' )
			);
			$raw_hash   = hash( 'sha256', $content );
			$is_valid   = ! is_wp_error( $validated );
			$hash       = $is_valid ? $validated['hash'] : $raw_hash;
			$title      = $is_valid ? $validated['title'] : $source['slug'];
			$is_selected = is_array( $state )
				&& $source['slug'] === $state['selected']['slug']
				&& hash_equals( $state['selected']['source_hash'], $hash );

			$variation = [
				'slug'          => $source['slug'],
				'title'         => $title,
				'origin'        => $source['origin'],
				'relative_path' => $source['relative_path'],
				'read_only'     => $source['read_only'],
				'hash'          => $hash,
				'selected'      => $is_selected,
				'valid'         => $is_valid,
			];
			if ( ! $is_valid ) {
				$variation['error'] = $validated->get_error_message();
			}
			$variations[] = $variation;
		}

		return [
			'stylesheet' => $theme->get_stylesheet(),
			'variations' => $variations,
		];
	}

	/**
	 * Create one validated style variation in the active stylesheet only.
	 *
	 * @param array<string,mixed> $document Complete theme.json v3 variation.
	 * @return array<string,mixed>|WP_Error Write result or an error.
	 */
	public function create( array $document ): array|WP_Error {
		$validated = $this->validate_document( $document );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$theme = $this->active_theme();
		if ( is_wp_error( $theme ) ) {
			return $theme;
		}

		$path = $this->active_variation_path( $theme, $validated['slug'] );
		if ( file_exists( $path ) ) {
			return new WP_Error(
				'sd_ai_agent_style_variation_exists',
				__( 'A style variation with this slug already exists in the active stylesheet.', 'superdav-ai-agent' )
			);
		}

		return $this->write_active_document( $theme, $validated, 'create' );
	}

	/**
	 * Replace one active stylesheet variation after an optimistic hash check.
	 *
	 * @param string              $slug          Variation slug.
	 * @param array<string,mixed> $document      Complete replacement document.
	 * @param string              $expected_hash Canonical hash returned by list or validate.
	 * @return array<string,mixed>|WP_Error Write result or an error.
	 */
	public function update( string $slug, array $document, string $expected_hash ): array|WP_Error {
		$slug_error = $this->validate_slug( $slug );
		if ( is_wp_error( $slug_error ) ) {
			return $slug_error;
		}
		$expected_error = $this->validate_hash( $expected_hash, 'sd_ai_agent_style_variation_expected_hash_required' );
		if ( is_wp_error( $expected_error ) ) {
			return $expected_error;
		}

		$validated = $this->validate_document( $document );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		if ( $slug !== $validated['slug'] ) {
			return new WP_Error(
				'sd_ai_agent_style_variation_slug_mismatch',
				__( 'The replacement document slug must match the requested variation slug.', 'superdav-ai-agent' )
			);
		}

		$theme = $this->active_theme();
		if ( is_wp_error( $theme ) ) {
			return $theme;
		}
		$path = $this->active_variation_path( $theme, $slug );
		if ( ! file_exists( $path ) ) {
			$inherited = $this->find_variation( $theme, $slug );
			if ( is_array( $inherited ) && $inherited['read_only'] ) {
				return new WP_Error(
					'sd_ai_agent_style_variation_read_only',
					__( 'Inherited parent style variations are read-only. Create a child variation with a new slug instead.', 'superdav-ai-agent' )
				);
			}

			return new WP_Error(
				'sd_ai_agent_style_variation_not_found',
				__( 'The requested active stylesheet style variation was not found.', 'superdav-ai-agent' )
			);
		}

		$current = $this->read_document_file( $path );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( ! hash_equals( $expected_hash, $current['hash'] ) ) {
			return new WP_Error(
				'sd_ai_agent_style_variation_stale_hash',
				__( 'The style variation changed since it was read. Fetch it again before updating.', 'superdav-ai-agent' ),
				[ 'current_hash' => $current['hash'] ]
			);
		}

		return $this->write_active_document( $theme, $validated, 'update' );
	}

	/**
	 * Validate a supplied complete style-variation document without writing it.
	 *
	 * The canonical hash is shared with generated-artifact manifests so a caller
	 * can safely use the same concurrency token across lifecycle operations.
	 *
	 * @param array<string,mixed> $document Complete theme.json v3 variation.
	 * @return array<string,mixed>|WP_Error Validation details or an error.
	 */
	public function validate_document( array $document ): array|WP_Error {
		if ( array_is_list( $document ) ) {
			return new WP_Error(
				'sd_ai_agent_style_variation_document_required',
				__( 'A complete style variation document must be a JSON object.', 'superdav-ai-agent' )
			);
		}
		if ( self::SCHEMA_URL !== ( $document['$schema'] ?? null ) ) {
			return new WP_Error(
				'sd_ai_agent_style_variation_schema_required',
				__( 'Style variations must declare the WordPress theme.json v3 schema URL.', 'superdav-ai-agent' )
			);
		}
		if ( WP_Theme_JSON::LATEST_SCHEMA !== ( $document['version'] ?? null ) ) {
			return new WP_Error(
				'sd_ai_agent_style_variation_version_invalid',
				__( 'Style variations must use the supported theme.json schema version.', 'superdav-ai-agent' )
			);
		}

		$slug = isset( $document['slug'] ) ? (string) $document['slug'] : '';
		$slug_error = $this->validate_slug( $slug );
		if ( is_wp_error( $slug_error ) ) {
			return $slug_error;
		}
		$title = isset( $document['title'] ) ? trim( (string) $document['title'] ) : '';
		if ( '' === $title || strlen( $title ) > 200 ) {
			return new WP_Error(
				'sd_ai_agent_style_variation_title_required',
				__( 'Style variations require a concise non-empty title.', 'superdav-ai-agent' )
			);
		}

		foreach ( [ 'settings', 'styles' ] as $section ) {
			if ( ! isset( $document[ $section ] ) || ! is_array( $document[ $section ] ) || array_is_list( $document[ $section ] ) ) {
				return new WP_Error(
					'sd_ai_agent_style_variation_incomplete_document',
					__( 'Style variations require complete settings and styles objects.', 'superdav-ai-agent' ),
					[ 'path' => $section ]
				);
			}
		}
		if ( [] === $document['settings'] && [] === $document['styles'] ) {
			return new WP_Error(
				'sd_ai_agent_style_variation_empty_document',
				__( 'Style variations must change settings or styles.', 'superdav-ai-agent' )
			);
		}

		$semantic_error = $this->validate_compiled_semantics( $document );
		if ( is_wp_error( $semantic_error ) ) {
			return $semantic_error;
		}

		try {
			// Let the installed WordPress 7.0 theme.json parser sanitize and
			// resolve the supplied v3 document before any persistence path runs.
			new WP_Theme_JSON( $document, 'theme' );
		} catch ( \Throwable $exception ) {
			return new WP_Error(
				'sd_ai_agent_style_variation_theme_json_invalid',
				__( 'The style variation is not accepted by the installed WordPress theme.json parser.', 'superdav-ai-agent' )
			);
		}

		$hash = ArtifactManifest::hash_payload( $document );
		if ( is_wp_error( $hash ) ) {
			return new WP_Error(
				'sd_ai_agent_style_variation_hash_failed',
				__( 'The style variation could not be canonically hashed.', 'superdav-ai-agent' )
			);
		}

		return [
			'slug'     => $slug,
			'title'    => $title,
			'hash'     => $hash,
			'document' => $document,
		];
	}

	/**
	 * Validate one on-disk variation resolved with child-over-parent precedence.
	 *
	 * @param string $slug Variation slug.
	 * @return array<string,mixed>|WP_Error Validation details or an error.
	 */
	public function validate_existing( string $slug ): array|WP_Error {
		$slug_error = $this->validate_slug( $slug );
		if ( is_wp_error( $slug_error ) ) {
			return $slug_error;
		}
		$theme = $this->active_theme();
		if ( is_wp_error( $theme ) ) {
			return $theme;
		}
		$source = $this->find_variation( $theme, $slug );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$document = $this->read_document_file( $source['absolute_path'] );
		if ( is_wp_error( $document ) ) {
			return $document;
		}
		$document['origin']        = $source['origin'];
		$document['relative_path'] = $source['relative_path'];
		$document['read_only']     = $source['read_only'];

		return $document;
	}

	/**
	 * Produce an in-memory merged theme.json and stylesheet preview.
	 *
	 * @param array<string,mixed> $document Complete theme.json v3 variation.
	 * @return array<string,mixed>|WP_Error Preview details or an error.
	 */
	public function preview( array $document ): array|WP_Error {
		$validated = $this->validate_document( $document );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		try {
			// WordPress 7.0's merge() mutates its receiver. Cloning the resolved
			// theme layer keeps the preview entirely in memory and excludes the
			// current user override that select/reset guard against separately.
			$base    = WP_Theme_JSON_Resolver::get_merged_data( 'theme' );
			$before  = $base->get_data();
			$preview = clone $base;
			$preview->merge( new WP_Theme_JSON( $validated['document'], 'theme' ) );
			$after = $preview->get_data();
			$css   = $preview->get_stylesheet();
		} catch ( \Throwable $exception ) {
			return new WP_Error(
				'sd_ai_agent_style_variation_preview_failed',
				__( 'WordPress could not build an in-memory style variation preview.', 'superdav-ai-agent' )
			);
		}

		return [
			'slug'          => $validated['slug'],
			'title'         => $validated['title'],
			'hash'          => $validated['hash'],
			'changed_paths' => self::changed_paths( $before, $after ),
			'css'           => $css,
		];
	}

	/**
	 * Select a variation while preserving the exact original user document.
	 *
	 * @param string $slug          Variation slug.
	 * @param string $expected_hash Canonical source hash returned by list or validate.
	 * @return array<string,mixed>|WP_Error Selection result or an error.
	 */
	public function select( string $slug, string $expected_hash ): array|WP_Error {
		$expected_error = $this->validate_hash( $expected_hash, 'sd_ai_agent_style_variation_expected_hash_required' );
		if ( is_wp_error( $expected_error ) ) {
			return $expected_error;
		}
		$variation = $this->validate_existing( $slug );
		if ( is_wp_error( $variation ) ) {
			return $variation;
		}
		if ( ! hash_equals( $expected_hash, $variation['hash'] ) ) {
			return new WP_Error(
				'sd_ai_agent_style_variation_stale_hash',
				__( 'The style variation changed since it was read. Fetch it again before selecting.', 'superdav-ai-agent' ),
				[ 'current_hash' => $variation['hash'] ]
			);
		}

		$theme = $this->active_theme();
		if ( is_wp_error( $theme ) ) {
			return $theme;
		}
		$state_map = $this->read_state_map();
		if ( is_wp_error( $state_map ) ) {
			return $state_map;
		}
		$existing_state = $this->state_for_stylesheet( $state_map, $theme->get_stylesheet() );
		if ( is_wp_error( $existing_state ) ) {
			return $existing_state;
		}
		$current = $this->current_global_styles();
		if ( is_wp_error( $current ) ) {
			return $current;
		}

		if ( is_array( $existing_state ) ) {
			$selection_error = $this->assert_selected_state_matches_current( $existing_state, $current );
			if ( is_wp_error( $selection_error ) ) {
				return $selection_error;
			}
			if (
				$variation['slug'] === $existing_state['selected']['slug']
				&& hash_equals( $variation['hash'], $existing_state['selected']['source_hash'] )
			) {
				return [
					'selected'  => true,
					'unchanged' => true,
					'slug'      => $variation['slug'],
					'hash'      => $variation['hash'],
				];
			}
			$baseline = $existing_state['baseline'];
		} else {
			$baseline = [
				'post_exists' => $current['exists'],
				'post_id'     => $current['post_id'],
				'content'     => $current['content'],
				'content_hash'=> $current['content_hash'],
				'document'    => $current['document'],
			];
		}

		$desired_document = self::apply_variation_to_baseline( $baseline['document'], $variation['document'] );
		$written          = $this->write_global_styles_document( $current, $desired_document );
		if ( is_wp_error( $written ) ) {
			return $written;
		}

		$state_map[ $theme->get_stylesheet() ] = [
			'version'    => self::STATE_VERSION,
			'baseline'   => $baseline,
			'selected'   => [
				'slug'          => $variation['slug'],
				'origin'        => $variation['origin'],
				'relative_path' => $variation['relative_path'],
				'source_hash'   => $variation['hash'],
				'post_id'       => $written['post_id'],
				'content_hash'  => $written['content_hash'],
				'actor_id'      => (int) get_current_user_id(),
				'selected_at'   => gmdate( 'c' ),
			],
		];
		$state_written = $this->write_state_map( $state_map );
		if ( is_wp_error( $state_written ) ) {
			$recovered = $this->restore_current_global_styles( $current, $written );
			if ( is_wp_error( $recovered ) ) {
				return $this->recovery_error( 'selection', $state_written, $recovered );
			}

			return $state_written;
		}

		return [
			'selected'      => true,
			'unchanged'     => false,
			'slug'          => $variation['slug'],
			'hash'          => $variation['hash'],
			'relative_path' => $variation['relative_path'],
		];
	}

	/**
	 * Restore the exact baseline only while the selected state remains intact.
	 *
	 * @return array<string,mixed>|WP_Error Reset result or an error.
	 */
	public function reset(): array|WP_Error {
		$theme = $this->active_theme();
		if ( is_wp_error( $theme ) ) {
			return $theme;
		}
		$state_map = $this->read_state_map();
		if ( is_wp_error( $state_map ) ) {
			return $state_map;
		}
		$state = $this->state_for_stylesheet( $state_map, $theme->get_stylesheet() );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		if ( ! is_array( $state ) ) {
			return new WP_Error(
				'sd_ai_agent_style_variation_not_selected',
				__( 'No plugin-managed style variation is selected for this stylesheet.', 'superdav-ai-agent' )
			);
		}

		$current = $this->current_global_styles();
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$selection_error = $this->assert_selected_state_matches_current( $state, $current );
		if ( is_wp_error( $selection_error ) ) {
			return $selection_error;
		}

		unset( $state_map[ $theme->get_stylesheet() ] );
		$state_removed = $this->write_state_map( $state_map );
		if ( is_wp_error( $state_removed ) ) {
			return $state_removed;
		}

		$restored = $this->restore_baseline( $state['baseline'], $current );
		if ( is_wp_error( $restored ) ) {
			$state_map[ $theme->get_stylesheet() ] = $state;
			$recovered = $this->write_state_map( $state_map );
			if ( is_wp_error( $recovered ) ) {
				return $this->recovery_error( 'reset', $restored, $recovered );
			}

			return $restored;
		}

		return [
			'reset'         => true,
			'slug'          => $state['selected']['slug'],
			'restored_post' => $state['baseline']['post_exists'],
		];
	}

	/**
	 * Resolve one installed active stylesheet theme.
	 *
	 * @return WP_Theme|WP_Error Active theme or a safe error.
	 */
	private function active_theme(): WP_Theme|WP_Error {
		$theme = wp_get_theme();
		if ( ! $theme->exists() || '' === $theme->get_stylesheet_directory() ) {
			return new WP_Error(
				'sd_ai_agent_style_variation_theme_unavailable',
				__( 'The active stylesheet theme is unavailable.', 'superdav-ai-agent' )
			);
		}

		return $theme;
	}

	/**
	 * Return every direct styles/{slug}.json source in child-first precedence.
	 *
	 * @param WP_Theme $theme Active stylesheet theme.
	 * @return list<array{slug:string,origin:string,relative_path:string,absolute_path:string,read_only:bool}>
	 */
	private function variation_sources( WP_Theme $theme ): array {
		$parent  = $theme->parent();
		$sources = [
			[
				'origin'    => $parent instanceof WP_Theme ? 'child' : 'stylesheet',
				'directory' => $theme->get_stylesheet_directory(),
				'read_only' => false,
			],
		];
		if ( $parent instanceof WP_Theme ) {
			$sources[] = [
				'origin'    => 'parent',
				'directory' => $parent->get_stylesheet_directory(),
				'read_only' => true,
			];
		}

		$records = [];
		foreach ( $sources as $source ) {
			$directory = trailingslashit( $source['directory'] ) . 'styles';
			if ( ! is_dir( $directory ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scandir -- Listing a verified installed theme styles directory.
			$entries = scandir( $directory );
			if ( false === $entries ) {
				continue;
			}
			foreach ( $entries as $entry ) {
				if ( 1 !== preg_match( '/^([a-z0-9][a-z0-9-]*)\.json$/', $entry, $matches ) ) {
					continue;
				}
				$absolute_path = trailingslashit( $directory ) . $entry;
				if ( ! is_file( $absolute_path ) ) {
					continue;
				}
				$records[] = [
					'slug'          => $matches[1],
					'origin'        => $source['origin'],
					'relative_path' => 'styles/' . $entry,
					'absolute_path' => $absolute_path,
					'read_only'     => $source['read_only'],
				];
			}
		}

		usort(
			$records,
			static function ( array $left, array $right ): int {
				$slug = strcmp( $left['slug'], $right['slug'] );
				if ( 0 !== $slug ) {
					return $slug;
				}

				return (int) $left['read_only'] <=> (int) $right['read_only'];
			}
		);

		return $records;
	}

	/**
	 * Resolve one source with active stylesheet precedence.
	 *
	 * @param WP_Theme $theme Active stylesheet theme.
	 * @param string   $slug  Variation slug.
	 * @return array<string,mixed>|WP_Error Source details or an error.
	 */
	private function find_variation( WP_Theme $theme, string $slug ): array|WP_Error {
		foreach ( $this->variation_sources( $theme ) as $source ) {
			if ( $slug === $source['slug'] ) {
				return $source;
			}
		}

		return new WP_Error(
			'sd_ai_agent_style_variation_not_found',
			__( 'The requested style variation was not found in the active stylesheet or its parent.', 'superdav-ai-agent' )
		);
	}

	/**
	 * Return the only writable style-variation path for an active stylesheet.
	 */
	private function active_variation_path( WP_Theme $theme, string $slug ): string {
		return trailingslashit( $theme->get_stylesheet_directory() ) . 'styles/' . $slug . '.json';
	}

	/**
	 * Read and validate one variation file without leaking its absolute path.
	 *
	 * @param string $path Absolute internal file path.
	 * @return array<string,mixed>|WP_Error Validated document or a safe error.
	 */
	private function read_document_file( string $path ): array|WP_Error {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading one selected local variation document.
		$content = file_get_contents( $path );
		if ( false === $content ) {
			return new WP_Error(
				'sd_ai_agent_style_variation_read_failed',
				__( 'The requested style variation could not be read.', 'superdav-ai-agent' )
			);
		}
		$document = json_decode( $content, true );
		if ( ! is_array( $document ) || array_is_list( $document ) ) {
			return new WP_Error(
				'sd_ai_agent_style_variation_invalid_json',
				__( 'The requested style variation does not contain a JSON object.', 'superdav-ai-agent' )
			);
		}

		return $this->validate_document( $document );
	}

	/**
	 * Atomically write one active stylesheet variation with the generic file
	 * modification gate and existing Git/change tracking hooks intact.
	 *
	 * @param WP_Theme           $theme     Active stylesheet theme.
	 * @param array<string,mixed> $validated Validated document payload.
	 * @param string             $operation create or update.
	 * @return array<string,mixed>|WP_Error Write result or an error.
	 */
	private function write_active_document( WP_Theme $theme, array $validated, string $operation ): array|WP_Error {
		$relative_path = 'styles/' . $validated['slug'] . '.json';
		$target        = $this->active_variation_path( $theme, $validated['slug'] );
		$directory     = dirname( $target );
		$exists        = file_exists( $target );
		if ( 'create' === $operation && $exists ) {
			return new WP_Error( 'sd_ai_agent_style_variation_exists', __( 'A style variation with this slug already exists in the active stylesheet.', 'superdav-ai-agent' ) );
		}
		if ( 'update' === $operation && ! $exists ) {
			return new WP_Error( 'sd_ai_agent_style_variation_not_found', __( 'The requested active stylesheet style variation was not found.', 'superdav-ai-agent' ) );
		}

		$allowed = FileModGate::assert_allowed( $target );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			return new WP_Error(
				'sd_ai_agent_style_variation_directory_failed',
				__( 'The active stylesheet styles directory could not be created.', 'superdav-ai-agent' )
			);
		}
		if ( ! is_writable( $directory ) ) {
			return new WP_Error(
				'sd_ai_agent_style_variation_directory_unwritable',
				__( 'The active stylesheet styles directory is not writable.', 'superdav-ai-agent' )
			);
		}

		$content = wp_json_encode( $validated['document'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $content ) {
			return new WP_Error(
				'sd_ai_agent_style_variation_encode_failed',
				__( 'The style variation could not be encoded as JSON.', 'superdav-ai-agent' )
			);
		}
		$before_content = '';
		if ( $exists ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Capturing an exact local before-value for the existing change log.
			$before_content = file_get_contents( $target );
			if ( false === $before_content ) {
				return new WP_Error(
					'sd_ai_agent_style_variation_read_failed',
					__( 'The existing style variation could not be read before updating.', 'superdav-ai-agent' )
				);
			}
		}

		$tmp_path = tempnam( $directory, '.sd-ai-agent-style-variation-' );
		if ( false === $tmp_path ) {
			return new WP_Error(
				'sd_ai_agent_style_variation_temp_failed',
				__( 'The style variation could not be staged for an atomic write.', 'superdav-ai-agent' )
			);
		}

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a same-directory temporary file before atomic replacement.
			if ( false === file_put_contents( $tmp_path, $content, LOCK_EX ) ) {
				return new WP_Error(
					'sd_ai_agent_style_variation_write_failed',
					__( 'The style variation could not be staged for writing.', 'superdav-ai-agent' )
				);
			}

			$before_hook = 'create' === $operation ? 'sd_ai_agent_before_file_write' : 'sd_ai_agent_before_file_edit';
			$after_hook  = 'create' === $operation ? 'sd_ai_agent_after_file_write' : 'sd_ai_agent_after_file_edit';
			do_action( $before_hook, $target );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same-directory replacement preserves atomicity for concurrent readers.
			if ( ! rename( $tmp_path, $target ) ) {
				return new WP_Error(
					'sd_ai_agent_style_variation_write_failed',
					__( 'The style variation could not be atomically written.', 'superdav-ai-agent' )
				);
			}
			$tmp_path = '';

			do_action( $after_hook, $target );
			$tracking_path = 'themes/' . $theme->get_stylesheet() . '/' . $relative_path;
			Database::record_modified_file( $tracking_path, 'create' === $operation ? 'write' : 'edit', 0, (int) get_current_user_id() );
			if ( ChangeLogger::is_active() ) {
				ChangesLog::record(
					[
						'session_id'   => ChangeLogger::get_session_id(),
						'object_type'  => 'file',
						'object_id'    => 0,
						'object_title' => basename( $relative_path ),
						'ability_name' => ChangeLogger::get_ability_name() ?: 'style-variation-' . $operation,
						'field_name'   => $target,
						'before_value' => $before_content,
						'after_value'  => $content,
						'revertable'   => true,
					]
				);
			}
		} finally {
			if ( '' !== $tmp_path && file_exists( $tmp_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing only the manager-owned same-directory temporary file after a failed write.
				unlink( $tmp_path );
			}
		}

		return [
			'action'        => 'create' === $operation ? 'created' : 'updated',
			'slug'          => $validated['slug'],
			'title'         => $validated['title'],
			'hash'          => $validated['hash'],
			'relative_path' => $relative_path,
		];
	}

	/**
	 * Verify the optional semantic map emitted by DesignTokenCompiler.
	 *
	 * General WordPress variations remain valid without this extension. When a
	 * document declares the plugin namespace, all required #2249 semantic roles
	 * must be present so malformed compiled output never reaches disk.
	 *
	 * @param array<string,mixed> $document Style variation document.
	 * @return true|WP_Error True when valid or a contract error.
	 */
	private function validate_compiled_semantics( array $document ): true|WP_Error {
		$custom = $document['settings']['custom']['sdAiAgent'] ?? null;
		if ( null === $custom ) {
			return true;
		}
		$colors = is_array( $custom ) ? ( $custom['semantic']['color'] ?? null ) : null;
		if ( ! is_array( $colors ) || array_is_list( $colors ) ) {
			return new WP_Error(
				'sd_ai_agent_style_variation_semantics_invalid',
				__( 'Compiled style variations must include the design-token semantic colour map.', 'superdav-ai-agent' )
			);
		}
		foreach ( DesignTokenContract::REQUIRED_COLOR_ROLES as $role ) {
			if ( ! isset( $colors[ $role ] ) || ! is_string( $colors[ $role ] ) || '' === trim( $colors[ $role ] ) ) {
				return new WP_Error(
					'sd_ai_agent_style_variation_semantics_invalid',
					__( 'Compiled style variations must retain every required semantic colour role.', 'superdav-ai-agent' ),
					[ 'role' => $role ]
				);
			}
		}

		return true;
	}

	/**
	 * Read the active theme's exact persisted Global Styles record.
	 *
	 * @return array<string,mixed>|WP_Error Current record details or an error.
	 */
	private function current_global_styles(): array|WP_Error {
		$service = new GlobalStylesService();
		$post_id = $service->get_user_post_id();
		if ( null === $post_id ) {
			return [
				'exists'       => false,
				'post_id'      => null,
				'content'      => null,
				'content_hash' => null,
				'document'     => [],
				'service'      => $service,
			];
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'wp_global_styles' !== $post->post_type ) {
			return new WP_Error(
				'sd_ai_agent_style_variation_global_styles_missing',
				__( 'The active theme Global Styles record is unavailable.', 'superdav-ai-agent' )
			);
		}
		$document = json_decode( $post->post_content, true );
		if ( ! is_array( $document ) || array_is_list( $document ) ) {
			return new WP_Error(
				'sd_ai_agent_style_variation_global_styles_invalid',
				__( 'The active theme Global Styles record is not valid JSON.', 'superdav-ai-agent' )
			);
		}

		return [
			'exists'       => true,
			'post_id'      => (int) $post->ID,
			'content'      => $post->post_content,
			'content_hash' => hash( 'sha256', $post->post_content ),
			'document'     => $document,
			'service'      => $service,
		];
	}

	/**
	 * Merge only a variation's settings/styles onto the original exact baseline.
	 *
	 * @param array<string,mixed> $baseline  Original user document.
	 * @param array<string,mixed> $variation Validated variation document.
	 * @return array<string,mixed> New complete user Global Styles document.
	 */
	private static function apply_variation_to_baseline( array $baseline, array $variation ): array {
		$partial = [
			'settings' => $variation['settings'],
			'styles'   => $variation['styles'],
		];
		$document = self::deep_merge( $baseline, $partial );
		$document['version']                     = WP_Theme_JSON::LATEST_SCHEMA;
		$document['isGlobalStylesUserThemeJSON'] = true;

		return $document;
	}

	/**
	 * Persist a complete user Global Styles document and return its exact bytes.
	 *
	 * @param array<string,mixed> $current Current record details.
	 * @param array<string,mixed> $document New complete document.
	 * @return array<string,mixed>|WP_Error Write details or an error.
	 */
	private function write_global_styles_document( array $current, array $document ): array|WP_Error {
		$content = wp_json_encode( $document, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP );
		if ( false === $content ) {
			return new WP_Error(
				'sd_ai_agent_style_variation_global_styles_encode_failed',
				__( 'The selected style variation could not be encoded for Global Styles.', 'superdav-ai-agent' )
			);
		}

		if ( ! $current['exists'] ) {
			$created = $current['service']->merge_user_document( $document );
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			$post = get_post( $created['post_id'] );
			if ( ! $post instanceof WP_Post ) {
				return new WP_Error(
					'sd_ai_agent_style_variation_global_styles_missing',
					__( 'The selected Global Styles record could not be reloaded.', 'superdav-ai-agent' )
				);
			}

			return [
				'post_id'      => (int) $post->ID,
				'content'      => $post->post_content,
				'content_hash' => hash( 'sha256', $post->post_content ),
				'created'      => true,
			];
		}

		$updated = wp_update_post(
			[
				'ID'           => $current['post_id'],
				'post_content' => $content,
			],
			true
		);
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
			wp_clean_theme_json_cache();
		}
		$post = get_post( $current['post_id'] );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'sd_ai_agent_style_variation_global_styles_missing',
				__( 'The selected Global Styles record could not be reloaded.', 'superdav-ai-agent' )
			);
		}

		return [
			'post_id'      => (int) $post->ID,
			'content'      => $post->post_content,
			'content_hash' => hash( 'sha256', $post->post_content ),
			'created'      => false,
		];
	}

	/**
	 * Undo a failed selection's Global Styles write before returning an error.
	 *
	 * @param array<string,mixed> $current Original record details.
	 * @param array<string,mixed> $written New record details.
	 * @return true|WP_Error True after recovery or a recovery error.
	 */
	private function restore_current_global_styles( array $current, array $written ): true|WP_Error {
		if ( ! $current['exists'] ) {
			$deleted = wp_delete_post( $written['post_id'], true );
			if ( ! $deleted instanceof WP_Post ) {
				return new WP_Error(
					'sd_ai_agent_style_variation_recovery_failed',
					__( 'The plugin-created Global Styles record could not be removed after selection failed.', 'superdav-ai-agent' )
				);
			}
		} else {
			$updated = wp_update_post(
				[
					'ID'           => $current['post_id'],
					'post_content' => $current['content'],
				],
				true
			);
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}
		if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
			wp_clean_theme_json_cache();
		}

		return true;
	}

	/**
	 * Restore an exact saved baseline after the state record was safely removed.
	 *
	 * @param array<string,mixed> $baseline Original baseline.
	 * @param array<string,mixed> $current  Current managed selection record.
	 * @return true|WP_Error True when restored or an error.
	 */
	private function restore_baseline( array $baseline, array $current ): true|WP_Error {
		if ( $baseline['post_exists'] ) {
			$updated = wp_update_post(
				[
					'ID'           => $current['post_id'],
					'post_content' => $baseline['content'],
				],
				true
			);
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		} else {
			$deleted = wp_delete_post( $current['post_id'], true );
			if ( ! $deleted instanceof WP_Post ) {
				return new WP_Error(
					'sd_ai_agent_style_variation_reset_failed',
					__( 'The plugin-created Global Styles record could not be removed during reset.', 'superdav-ai-agent' )
				);
			}
		}
		if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
			wp_clean_theme_json_cache();
		}

		return true;
	}

	/**
	 * Refuse to overwrite Site Editor changes made after managed selection.
	 *
	 * @param array<string,mixed> $state   Saved managed state.
	 * @param array<string,mixed> $current Current record details.
	 * @return true|WP_Error True when unchanged or a drift error.
	 */
	private function assert_selected_state_matches_current( array $state, array $current ): true|WP_Error {
		if (
			! $current['exists']
			|| $state['selected']['post_id'] !== $current['post_id']
			|| ! hash_equals( $state['selected']['content_hash'], $current['content_hash'] )
		) {
			return new WP_Error(
				'sd_ai_agent_style_variation_global_styles_changed',
				__( 'Global Styles changed after the variation was selected. Refusing to overwrite Site Editor changes.', 'superdav-ai-agent' )
			);
		}

		return true;
	}

	/**
	 * Read the site-scoped state map without silently accepting malformed data.
	 *
	 * @return array<string,mixed>|WP_Error State map or an error.
	 */
	private function read_state_map(): array|WP_Error {
		$state = get_option( self::STATE_OPTION, [] );
		if ( false === $state || [] === $state || null === $state ) {
			return [];
		}
		if ( ! is_array( $state ) || array_is_list( $state ) ) {
			return new WP_Error(
				'sd_ai_agent_style_variation_state_invalid',
				__( 'The saved style variation selection state is invalid and was left unchanged.', 'superdav-ai-agent' )
			);
		}

		return $state;
	}

	/**
	 * Return one validated selection state or null when this stylesheet has none.
	 *
	 * @param array<string,mixed> $state_map  Site state map.
	 * @param string              $stylesheet Active stylesheet slug.
	 * @return array<string,mixed>|null|WP_Error State, no state, or an error.
	 */
	private function state_for_stylesheet( array $state_map, string $stylesheet ): array|null|WP_Error {
		if ( ! isset( $state_map[ $stylesheet ] ) ) {
			return null;
		}
		$state = $state_map[ $stylesheet ];
		if (
			! is_array( $state )
			|| self::STATE_VERSION !== ( $state['version'] ?? null )
			|| ! isset( $state['baseline'], $state['selected'] )
			|| ! is_array( $state['baseline'] )
			|| ! is_array( $state['selected'] )
		) {
			return new WP_Error(
				'sd_ai_agent_style_variation_state_invalid',
				__( 'The saved style variation selection state is invalid and was left unchanged.', 'superdav-ai-agent' )
			);
		}
		$baseline = $state['baseline'];
		$selected = $state['selected'];
		if (
			! isset( $baseline['post_exists'], $baseline['document'], $selected['slug'], $selected['source_hash'], $selected['post_id'], $selected['content_hash'] )
			|| ! is_bool( $baseline['post_exists'] )
			|| ! is_array( $baseline['document'] )
			|| ! is_string( $selected['slug'] )
			|| ! is_int( $selected['post_id'] )
			|| ! is_string( $selected['source_hash'] )
			|| ! is_string( $selected['content_hash'] )
			|| is_wp_error( $this->validate_hash( $selected['source_hash'], 'sd_ai_agent_style_variation_state_invalid' ) )
			|| is_wp_error( $this->validate_hash( $selected['content_hash'], 'sd_ai_agent_style_variation_state_invalid' ) )
		) {
			return new WP_Error(
				'sd_ai_agent_style_variation_state_invalid',
				__( 'The saved style variation selection state is incomplete and was left unchanged.', 'superdav-ai-agent' )
			);
		}
		if ( $baseline['post_exists'] && ( ! isset( $baseline['post_id'], $baseline['content'], $baseline['content_hash'] ) || ! is_int( $baseline['post_id'] ) || ! is_string( $baseline['content'] ) || ! is_string( $baseline['content_hash'] ) ) ) {
			return new WP_Error(
				'sd_ai_agent_style_variation_state_invalid',
				__( 'The saved style variation baseline is incomplete and was left unchanged.', 'superdav-ai-agent' )
			);
		}

		return $state;
	}

	/**
	 * Persist or remove the map, checking WordPress's no-change return semantics.
	 *
	 * @param array<string,mixed> $state_map New site state map.
	 * @return true|WP_Error True when persisted or an error.
	 */
	private function write_state_map( array $state_map ): true|WP_Error {
		if ( [] === $state_map ) {
			$deleted = delete_option( self::STATE_OPTION );
			if ( ! $deleted && false !== get_option( self::STATE_OPTION, false ) ) {
				return new WP_Error(
					'sd_ai_agent_style_variation_state_write_failed',
					__( 'The style variation selection state could not be removed.', 'superdav-ai-agent' )
				);
			}

			return true;
		}

		$updated = update_option( self::STATE_OPTION, $state_map );
		if ( ! $updated && get_option( self::STATE_OPTION, null ) !== $state_map ) {
			return new WP_Error(
				'sd_ai_agent_style_variation_state_write_failed',
				__( 'The style variation selection state could not be saved.', 'superdav-ai-agent' )
			);
		}

		return true;
	}

	/**
	 * Return an error that preserves both a failed operation and failed recovery.
	 */
	private function recovery_error( string $operation, WP_Error $original, WP_Error $recovery ): WP_Error {
		return new WP_Error(
			'sd_ai_agent_style_variation_recovery_failed',
			sprintf(
				/* translators: %s: operation name. */
				__( 'Style variation %s failed and its recovery also failed.', 'superdav-ai-agent' ),
				$operation
			),
			[
				'operation_error' => $original->get_error_code(),
				'recovery_error'  => $recovery->get_error_code(),
			]
		);
	}

	/**
	 * Validate a conservative variation slug.
	 *
	 * @return true|WP_Error True when valid or an error.
	 */
	private function validate_slug( string $slug ): true|WP_Error {
		if ( '' === $slug || 1 !== preg_match( self::SLUG_PATTERN, $slug ) ) {
			return new WP_Error(
				'sd_ai_agent_style_variation_invalid_slug',
				__( 'Style variation slugs must contain only lowercase letters, digits, and hyphens.', 'superdav-ai-agent' )
			);
		}

		return true;
	}

	/**
	 * Validate an SHA-256 concurrency token.
	 *
	 * @return true|WP_Error True when valid or an error.
	 */
	private function validate_hash( string $hash, string $error_code ): true|WP_Error {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
			return new WP_Error(
				$error_code,
				__( 'A current canonical style variation hash is required.', 'superdav-ai-agent' )
			);
		}

		return true;
	}

	/**
	 * Deep merge associative theme.json fragments without replacing sibling state.
	 *
	 * @param array<string,mixed> $base     Base document fragment.
	 * @param array<string,mixed> $override Variation fragment.
	 * @return array<string,mixed> Merged document.
	 */
	private static function deep_merge( array $base, array $override ): array {
		foreach ( $override as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && ! array_is_list( $value ) && ! array_is_list( $base[ $key ] ) ) {
				$base[ $key ] = self::deep_merge( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}

		return $base;
	}

	/**
	 * Build a bounded list of data paths changed by an in-memory preview.
	 *
	 * @param mixed  $before Original value.
	 * @param mixed  $after  Preview value.
	 * @param string $path   Current dotted path.
	 * @return list<string> Changed paths.
	 */
	private static function changed_paths( mixed $before, mixed $after, string $path = '' ): array {
		if ( $before === $after ) {
			return [];
		}
		if ( ! is_array( $before ) || ! is_array( $after ) || array_is_list( $before ) || array_is_list( $after ) ) {
			return [ '' === $path ? '$' : $path ];
		}

		$paths = [];
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $key ) {
			$child_path = '' === $path ? (string) $key : $path . '.' . $key;
			$paths      = array_merge( $paths, self::changed_paths( $before[ $key ] ?? null, $after[ $key ] ?? null, $child_path ) );
			if ( count( $paths ) >= 128 ) {
				return array_slice( $paths, 0, 128 );
			}
		}

		return $paths;
	}
}
