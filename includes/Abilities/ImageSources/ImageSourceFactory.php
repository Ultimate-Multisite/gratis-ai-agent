<?php

declare(strict_types=1);
/**
 * Image source factory for unified image retrieval.
 *
 * Provides a single entry point for multiple image sources:
 * - openverse: Free CC0 images from WordPress.org (no key required)
 * - pixabay: Free images (API key required, free commercial)
 * - generate: AI-generated via DALL-E (API required, paid)
 *
 * The agent chooses the best source based on availability and cost preferences.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities\ImageSources;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Image source factory and unified ability.
 *
 * @since 1.5.0
 */
class ImageSourceFactory {

	/**
	 * Number of candidate results to request from each free source before falling
	 * through to the next provider in the stock-image fallback chain.
	 */
	private const FREE_SOURCE_SEARCH_LIMIT = 3;

	/**
	 * Registered sources.
	 *
	 * @var array<string, ImageSourceInterface>
	 */
	private static array $sources = [];

	/**
	 * Initialize and register all sources.
	 */
	public static function init(): void {
		// Register sources in priority order.
		self::$sources = [
			'openverse' => new OpenverseImageSource(),
			'pixabay'   => new PixabayImageSource(),
			'generate'  => new AiGenerateSource(),
		];
	}

	/**
	 * Get a source by ID.
	 *
	 * @param string $source_id Source ID.
	 * @return ImageSourceInterface|null Source or null.
	 */
	public static function get( string $source_id ): ?ImageSourceInterface {
		if ( empty( self::$sources ) ) {
			self::init();
		}

		return self::$sources[ $source_id ] ?? null;
	}

	/**
	 * Get all available sources.
	 *
	 * @return array<string, ImageSourceInterface> Available sources.
	 */
	public static function get_available(): array {
		if ( empty( self::$sources ) ) {
			self::init();
		}

		return array_filter(
			self::$sources,
			static fn( ImageSourceInterface $source ): bool => $source->is_available()
		);
	}

	/**
	 * Get source info for agent selection.
	 *
	 * @return array Source info for the agent.
	 */
	public static function get_source_info(): array {
		$sources = self::get_available();

		return array_map(
			static function ( ImageSourceInterface $source ): array {
				return [
					'id'        => $source->get_id(),
					'name'      => $source->get_name(),
					'cost'      => $source->get_cost_type(),
					'available' => $source->is_available(),
				];
			},
			$sources
		);
	}

	/**
	 * Smart source selection for a keyword.
	 *
	 * Chooses the best available source based on preference hierarchy:
	 * 1. User explicitly requested 'generate' → use AI generation
	 * 2. User has paid API config → prefer free sources first, generate as fallback
	 * 3. Free sources only
	 *
	 * @param string $preferred Preferred source ID (optional).
	 * @return ImageSourceInterface|\WP_Error Selected source or error if explicitly requested source is unavailable.
	 */
	public static function select_source( string $preferred = '' ): ImageSourceInterface|\WP_Error {
		// If user explicitly requested a source, use it if available.
		if ( ! empty( $preferred ) ) {
			$source = self::get( $preferred );
			if ( ! $source ) {
				return new WP_Error(
					'unknown_image_source',
					sprintf( 'Unknown image source: %s.', $preferred )
				);
			}
			if ( ! $source->is_available() ) {
				return new WP_Error(
					'image_source_unavailable',
					sprintf( 'Image source "%s" is not available.', $preferred )
				);
			}
			return $source;
		}

		// Use priority: openverse (free) → pixabay (free) → generate (paid).
		$available = self::get_available();

		// Prefer free sources first.
		foreach ( $available as $source ) {
			if ( 'free' === $source->get_cost_type() ) {
				return $source;
			}
		}

		// Fall back to AI generation if available.
		foreach ( $available as $source ) {
			if ( 'api' === $source->get_cost_type() ) {
				return $source;
			}
		}

		// Fall back to first available.
		$first = array_values( $available );
		return $first[0] ?? self::$sources['openverse'];
	}

	/**
	 * Import an image from any source.
	 *
	 * On download failure or empty results, the method automatically retries
	 * all remaining available free sources before falling back to AI generation.
	 *
	 * @param string $keyword   Search keyword or generation prompt.
	 * @param string $source_id Source ID (auto-selected if empty). Use 'generate' for AI.
	 * @param int    $width     Desired width (0 for original).
	 * @param int    $height    Desired height (0 for original).
	 * @param array  $options   Additional options:
	 *                          - 'site_url'             (string) Multisite subsite URL.
	 *                          - 'post_id'              (int)    Attach to this post.
	 *                          - 'no_generate_fallback' (bool)   Skip AI generation fallback.
	 * @return array{\attachment_id: int, url: string, alt: string, title: string, source: string}|\WP_Error
	 */
	public static function import_image(
		string $keyword,
		string $source_id = '',
		int $width = 1200,
		int $height = 800,
		array $options = []
	): array|\WP_Error {

		// Explicit AI generation request — bypass the free-source chain entirely.
		if ( 'generate' === $source_id ) {
			$generate = self::get( 'generate' );
			if ( ! $generate || ! $generate->is_available() ) {
				return new WP_Error(
					'image_source_unavailable',
					'AI image generation is not available.'
				);
			}

			$tmp_file = $generate->download( $keyword, $width, $height );

			if ( is_wp_error( $tmp_file ) ) {
				return $tmp_file;
			}

			return self::handle_sideload( $tmp_file, $keyword, $options );
		}

		// If a specific free source was explicitly requested, validate it up-front.
		if ( ! empty( $source_id ) ) {
			$requested = self::get( $source_id );

			if ( ! $requested ) {
				return new WP_Error(
					'unknown_image_source',
					sprintf( 'Unknown image source: %s.', $source_id )
				);
			}

			if ( ! $requested->is_available() ) {
				return new WP_Error(
					'image_source_unavailable',
					sprintf( 'Image source "%s" is not available.', $source_id )
				);
			}

			// Reject non-free sources here rather than silently dropping them
			// from the free-source chain below. Paid sources that are not AI
			// generation have no defined path in import_image(); rejecting early
			// prevents the call from silently falling through to other sources
			// in a way the caller did not intend.
			if ( 'free' !== $requested->get_cost_type() ) {
				return new WP_Error(
					'non_free_source_not_supported',
					sprintf(
						'Image source "%s" is not a free source. Use source_id="generate" for AI generation.',
						$source_id
					)
				);
			}
		}

		// Build an ordered fallback chain of all available free sources.
		// The explicitly requested source (if any) goes first; the rest follow
		// in their registered priority order (openverse → pixabay).
		$available    = self::get_available();
		$free_sources = array_filter(
			$available,
			static fn( ImageSourceInterface $source ): bool => 'free' === $source->get_cost_type()
		);

		if ( ! empty( $source_id ) ) {
			// Reorder: put the requested source first, then the remaining ones.
			$ordered = [];
			if ( isset( $free_sources[ $source_id ] ) ) {
				$ordered[ $source_id ] = $free_sources[ $source_id ];
			}
			foreach ( $free_sources as $id => $s ) {
				if ( $id !== $source_id ) {
					$ordered[ $id ] = $s;
				}
			}
			$free_sources = $ordered;
		}

		// Try each free source in order, recording the failure reason for each.
		/** @var array<string, string> $tried */
		$tried = [];

		foreach ( $free_sources as $try_source ) {
			$search_result = $try_source->search( $keyword, self::FREE_SOURCE_SEARCH_LIMIT );

			if ( is_wp_error( $search_result ) ) {
				$tried[ $try_source->get_id() ] = sprintf(
					'search failed: %s',
					$search_result->get_error_message()
				);
				continue;
			}

			$hits = $search_result['hits'] ?? [];

			if ( empty( $hits ) ) {
				$tried[ $try_source->get_id() ] = 'no results found';
				continue;
			}

			$download_failures = [];

			foreach ( $hits as $hit ) {
				$image_id = (string) ( $hit['id'] ?? '' );

				if ( '' === $image_id ) {
					$download_failures[] = 'missing image ID';
					continue;
				}

				$tmp_file = $try_source->download( $image_id, $width, $height );

				if ( is_wp_error( $tmp_file ) ) {
					$download_failures[] = sprintf(
						'%s: %s',
						$image_id,
						$tmp_file->get_error_message()
					);
					continue;
				}

				// Success — sideload and return.
				return self::handle_sideload( $tmp_file, $keyword, $options, $hit );
			}

			$tried[ $try_source->get_id() ] = sprintf(
				'download failed: %s',
				implode( '; ', $download_failures )
			);
		}

		// All free sources failed (or none were available).
		// Fall back to AI generation unless the caller opted out.
		$no_generate = (bool) ( $options['no_generate_fallback'] ?? false );
		if ( ! $no_generate ) {
			$generate = self::get( 'generate' );
			if ( $generate && $generate->is_available() ) {
				$ai_result = self::import_image( $keyword, 'generate', $width, $height, $options );

				if ( ! is_wp_error( $ai_result ) ) {
					return $ai_result;
				}

				// Record the AI failure so the final error lists all sources tried.
				$tried['generate'] = sprintf( 'AI generation failed: %s', $ai_result->get_error_message() );
			}
		}

		// Return a descriptive error listing every source that was attempted.
		if ( empty( $tried ) ) {
			return new WP_Error(
				'no_sources_available',
				sprintf( 'No free image sources are available for "%s".', $keyword )
			);
		}

		$tried_parts = [];
		foreach ( $tried as $src_id => $reason ) {
			$tried_parts[] = sprintf( '%s (%s)', $src_id, $reason );
		}

		return new WP_Error(
			'all_sources_failed',
			sprintf(
				'All free image sources failed for "%s". Tried: %s.',
				$keyword,
				implode( ', ', $tried_parts )
			)
		);
	}

	/**
	 * Handle WordPress sideload of a temp file.
	 *
	 * @param string $tmp_file Temp file path.
	 * @param string $keyword Original keyword.
	 * @param array  $options Options (site_url, post_id).
	 * @param array  $hit     Original hit data.
	 * @return array|\WP_Error Result array or error.
	 */
	private static function handle_sideload(
		string $tmp_file,
		string $keyword,
		array $options = [],
		array $hit = []
	): array|\WP_Error {

		$site_url = $options['site_url'] ?? '';
		$post_id  = $options['post_id'] ?? 0;

		// Switch to subsite if requested.
		$switched = false;
		if ( ! empty( $site_url ) && is_multisite() ) {
			$blog_id = get_blog_id_from_url(
				wp_parse_url( $site_url, PHP_URL_HOST ),
				wp_parse_url( $site_url, PHP_URL_PATH ) ?: '/'
			);

			// Reject unknown sites.
			if ( ! $blog_id ) {
				return new WP_Error(
					'unknown_site',
					sprintf( 'Could not find a site matching URL: %s.', $site_url )
				);
			}

			if ( (int) $blog_id !== get_current_blog_id() ) {
				switch_to_blog( $blog_id );
				$switched = true;
			}
		}

		// Detect real file extension from the temp file.
		$extension = 'jpg';
		if ( file_exists( $tmp_file ) ) {
			$finfo         = new \finfo( FILEINFO_MIME_TYPE );
			$mime_type     = $finfo->file( $tmp_file );
			$extension_map = [
				'image/jpeg' => 'jpg',
				'image/png'  => 'png',
				'image/gif'  => 'gif',
				'image/webp' => 'webp',
			];
			$extension     = $extension_map[ $mime_type ] ?? 'jpg';
		}

		// Build filename.
		$safe_keyword = sanitize_file_name( $keyword );
		$filename     = $safe_keyword . '-' . time() . '.' . $extension;

		$file_array = [
			'name'     => $filename,
			'tmp_name' => $tmp_file,
		];

		$title = ucwords( str_replace( [ '-', '_' ], ' ', $keyword ) );

		// Require media functions.
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_id = media_handle_sideload( $file_array, $post_id, $title );

		if ( $switched ) {
			restore_current_blog();
		}

		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $tmp_file ) ) {
				unlink( $tmp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}

			return $attachment_id;
		}

		// Set alt text from keyword.
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $title );

		$attachment_url = wp_get_attachment_url( $attachment_id );

		return [
			'attachment_id' => $attachment_id,
			'url'           => $attachment_url,
			'alt'           => $title,
			'title'         => $title,
			'source'        => $hit['source'] ?? 'unknown',
			'tip'           => 'Use attachment_id as featured_image_id for create-post.',
		];
	}
}

// Initialize is handled by DI container or lazy initialization.
// The get() and get_available() methods call init() automatically if needed.
