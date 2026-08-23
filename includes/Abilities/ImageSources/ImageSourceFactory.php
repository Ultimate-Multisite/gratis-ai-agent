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
	private const FREE_SOURCE_SEARCH_LIMIT = 12;

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
	 * Get IDs for currently available free image sources.
	 *
	 * This powers the stock-image ability schema so provider enums do not advertise
	 * sources that cannot be used in the current site configuration.
	 *
	 * @return list<string> Available free source IDs.
	 */
	public static function get_available_free_source_ids(): array {
		$available = self::get_available();

		return array_values(
			array_map(
				static fn( ImageSourceInterface $source ): string => $source->get_id(),
				array_filter(
					$available,
					static fn( ImageSourceInterface $source ): bool => 'free' === $source->get_cost_type()
				)
			)
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
	 * Search all available free sources for candidate images without importing.
	 *
	 * Returns a flat list of candidate images from all available free sources.
	 * Each candidate includes thumbnail URL, dimensions, licence, attribution,
	 * provider, and provider-specific image ID so the caller can later pass
	 * provider + image_id to import_by_provider_id().
	 *
	 * @param string               $keyword  Search keyword.
	 * @param int                  $limit    Maximum candidates to return (default 5).
	 * @param string               $provider Restrict to a specific provider (empty = all free sources).
	 * @param array<string, mixed> $filters  Filters: orientation, colour, min_width, min_height.
	 * @return array{candidates: list<array<string, mixed>>, total: int}|\WP_Error
	 */
	public static function search_candidates(
		string $keyword,
		int $limit = 5,
		string $provider = '',
		array $filters = []
	): array|\WP_Error {
		if ( empty( self::$sources ) ) {
			self::init();
		}

		$limit        = max( 1, $limit );
		$available    = self::get_available();
		$free_sources = array_filter(
			$available,
			static fn( ImageSourceInterface $source ): bool => 'free' === $source->get_cost_type()
		);

		// If a specific provider is requested, prefer it only when it is currently
		// available. Unavailable free providers (for example an unconfigured Pixabay
		// API key) are omitted from the schema, but older/direct model calls may
		// still send them. In that case, fall back to the available free-source chain
		// rather than returning no imagery.
		if ( '' !== $provider ) {
			$known_source = self::get( $provider );
			if ( ! $known_source || 'free' !== $known_source->get_cost_type() ) {
				return new WP_Error(
					'provider_unavailable',
					sprintf( 'Provider "%s" is not available or is not a free source.', $provider )
				);
			}

			if ( isset( $free_sources[ $provider ] ) ) {
				$free_sources = [ $provider => $free_sources[ $provider ] ];
			}
		}

		if ( empty( $free_sources ) ) {
			return new WP_Error(
				'no_sources_available',
				'No free image sources are available.'
			);
		}

		$candidates = [];

		$search_limit = min( 50, max( 12, $limit * 4 ) );
		foreach ( $free_sources as $source ) {
			$search_result = $source->search( $keyword, $search_limit, $filters );

			if ( is_wp_error( $search_result ) ) {
				continue;
			}

			$hits = $search_result['hits'] ?? [];

			foreach ( $hits as $hit ) {
				$assessment = self::assess_candidate_quality( $hit, (string) ( $filters['usage'] ?? '' ), $filters );
				if ( ! $assessment['eligible'] ) {
					continue;
				}

				$candidates[] = [
					'image_id'        => (string) ( $hit['id'] ?? '' ),
					'provider'        => $source->get_id(),
					'thumbnail'       => $hit['preview'] ?? $hit['medium'] ?? '',
					'width'           => (int) ( $hit['width'] ?? 0 ),
					'height'          => (int) ( $hit['height'] ?? 0 ),
					'licence'         => $hit['license'] ?? '',
					'attribution'     => self::build_attribution_string( $hit, $source->get_id() ),
					'title'           => $hit['title'] ?? $hit['alt'] ?? '',
					'quality_score'   => $assessment['score'],
					'quality_reasons' => $assessment['reasons'],
				];
			}
		}

		usort(
			$candidates,
			static fn( array $first, array $second ): int => (int) $second['quality_score'] <=> (int) $first['quality_score']
		);
		$candidates = array_slice( $candidates, 0, $limit );

		return [
			'candidates' => $candidates,
			'total'      => count( $candidates ),
		];
	}

	/**
	 * Import a specific image by provider and image ID.
	 *
	 * Downloads and sideloads the image, storing attribution metadata.
	 *
	 * @param string               $provider Provider ID (e.g. 'openverse', 'pixabay').
	 * @param string               $image_id Provider-specific image ID.
	 * @param int                  $width    Desired width (0 for original).
	 * @param int                  $height   Desired height (0 for original).
	 * @param array<string, mixed> $options  Options: site_url, post_id, keyword.
	 * @return array<string, mixed>|\WP_Error Result with attachment_id, url, source, attribution, or WP_Error.
	 */
	public static function import_by_provider_id(
		string $provider,
		string $image_id,
		int $width = 0,
		int $height = 0,
		array $options = []
	): array|\WP_Error {
		if ( empty( self::$sources ) ) {
			self::init();
		}

		$source = self::get( $provider );

		if ( ! $source ) {
			return new WP_Error(
				'unknown_image_source',
				sprintf( 'Unknown image source: %s.', $provider )
			);
		}

		if ( ! $source->is_available() ) {
			return new WP_Error(
				'image_source_unavailable',
				sprintf( 'Image source "%s" is not available.', $provider )
			);
		}

		// Fetch metadata for attribution before downloading.
		$image_meta = $source->get_image( $image_id );
		$usage      = (string) ( $options['usage'] ?? '' );
		$hit        = [];

		if ( is_wp_error( $image_meta ) ) {
			if ( '' !== $usage ) {
				return $image_meta;
			}
		} else {
			$hit = [
				'id'          => $image_id,
				'source'      => $image_meta['source'] ?? $provider,
				'author'      => $image_meta['author'] ?? '',
				'author_url'  => $image_meta['author_url'] ?? '',
				'license'     => $image_meta['license'] ?? '',
				'license_url' => $image_meta['license_url'] ?? '',
				'attribution' => $image_meta['attribution'] ?? '',
				'width'       => (int) ( $image_meta['width'] ?? 0 ),
				'height'      => (int) ( $image_meta['height'] ?? 0 ),
				'title'       => (string) ( $image_meta['title'] ?? '' ),
			];
		}

		if ( '' !== $usage ) {
			$assessment = self::assess_candidate_quality( $hit, $usage );
			if ( ! $assessment['eligible'] ) {
				return new WP_Error(
					'image_quality_floor_failed',
					sprintf( 'The selected %1$s image does not meet the required quality floor: %2$s.', $usage, implode( '; ', $assessment['reasons'] ) )
				);
			}
			$hit['quality_score']   = $assessment['score'];
			$hit['quality_reasons'] = $assessment['reasons'];
		}

		$tmp_file = $source->download( $image_id, $width, $height );

		if ( is_wp_error( $tmp_file ) ) {
			return $tmp_file;
		}

		$keyword = (string) ( $options['keyword'] ?? $image_id );

		return self::handle_sideload( $tmp_file, $keyword, $options, $hit );
	}

	/**
	 * Assess whether an image is technically suitable for its intended role.
	 *
	 * This deliberately scores provider metadata rather than pretending that a
	 * filename proves aesthetic quality. It removes objectively unsuitable
	 * assets (small sources, wrong hero aspect, preview/watermark-labelled files)
	 * before the agent performs its visual curation pass.
	 *
	 * @param array<string,mixed> $hit     Provider hit or image metadata.
	 * @param string              $usage   hero, gallery, content, thumbnail, or empty.
	 * @param array<string,mixed> $filters Explicit minimum dimensions.
	 * @return array{eligible:bool,score:int,reasons:list<string>}
	 */
	public static function assess_candidate_quality( array $hit, string $usage = '', array $filters = array() ): array {
		$width  = max( 0, (int) ( $hit['width'] ?? 0 ) );
		$height = max( 0, (int) ( $hit['height'] ?? 0 ) );
		$title  = strtolower( trim( (string) ( $hit['title'] ?? $hit['alt'] ?? '' ) ) );
		$usage  = sanitize_key( $usage );

		$role_minimums = array(
			'hero'      => array( 1024, 576 ),
			'gallery'   => array( 1200, 800 ),
			'content'   => array( 1200, 675 ),
			'thumbnail' => array( 600, 400 ),
		);
		$minimums      = $role_minimums[ $usage ] ?? array( 0, 0 );
		$min_width     = max( (int) $minimums[0], (int) ( $filters['min_width'] ?? 0 ) );
		$min_height    = max( (int) $minimums[1], (int) ( $filters['min_height'] ?? 0 ) );
		$reasons       = array();
		$eligible      = true;

		if ( $width < $min_width ) {
			$eligible  = false;
			$reasons[] = sprintf( 'source width %1$dpx is below %2$dpx', $width, $min_width );
		}
		if ( $height < $min_height ) {
			$eligible  = false;
			$reasons[] = sprintf( 'source height %1$dpx is below %2$dpx', $height, $min_height );
		}
		if ( in_array( $usage, array( 'hero', 'gallery' ), true ) && $height > 0 && $width / $height < 1.3 ) {
			$eligible  = false;
			$reasons[] = sprintf( '%s source is not sufficiently landscape-oriented', $usage );
		}
		if ( '' !== $title && preg_match( '/\b(?:watermark(?:ed)?|preview|thumbnail|sample|screenshot|logo|signature)\b/i', $title ) ) {
			$eligible  = false;
			$reasons[] = 'provider title signals a preview, watermark, logo, or sample asset';
		}

		$megapixels = $width > 0 && $height > 0 ? ( $width * $height ) / 1000000 : 0.0;
		$score      = min( 100, (int) round( 45 + min( 45, $megapixels * 8 ) ) );
		if ( 'hero' === $usage && $width >= 2400 && $height >= 1200 ) {
			$score = min( 100, $score + 10 );
		}
		if ( ! $eligible ) {
			$score = min( $score, 39 );
		} elseif ( empty( $reasons ) ) {
			$reasons[] = sprintf( '%1$dx%2$d source meets the %3$s technical floor', $width, $height, '' !== $usage ? $usage : 'requested' );
		}

		return array(
			'eligible' => $eligible,
			'score'    => $score,
			'reasons'  => $reasons,
		);
	}

	/**
	 * Build a human-readable attribution string from a search hit.
	 *
	 * @param array<string, mixed> $hit      Search hit data.
	 * @param string               $provider Provider ID (fallback label).
	 * @return string Attribution string (empty if not determinable).
	 */
	private static function build_attribution_string( array $hit, string $provider ): string {
		// If the provider returned a pre-built attribution string, use it.
		if ( ! empty( $hit['attribution'] ) ) {
			return (string) $hit['attribution'];
		}

		$author  = (string) ( $hit['author'] ?? '' );
		$source  = (string) ( $hit['source'] ?? ucfirst( $provider ) );
		$license = (string) ( $hit['license'] ?? '' );

		if ( '' === $author ) {
			return sprintf( 'Image from %s%s', $source, '' !== $license ? " ($license)" : '' );
		}

		return sprintf(
			'Photo by %s on %s%s',
			$author,
			$source,
			'' !== $license ? " ($license)" : ''
		);
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
	 *                          - 'filters'              (array)  Search filters (orientation, colour, etc.).
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
		$tried   = [];
		$filters = (array) ( $options['filters'] ?? [] );

		foreach ( $free_sources as $try_source ) {
			$search_result = $try_source->search( $keyword, self::FREE_SOURCE_SEARCH_LIMIT, $filters );

			if ( is_wp_error( $search_result ) ) {
				$tried[ $try_source->get_id() ] = sprintf(
					'search failed: %s',
					$search_result->get_error_message()
				);
				continue;
			}

			$hits  = $search_result['hits'] ?? [];
			$usage = (string) ( $filters['usage'] ?? '' );
			$hits  = array_values(
				array_filter(
					$hits,
					static fn( array $hit ): bool => self::assess_candidate_quality( $hit, $usage, $filters )['eligible']
				)
			);
			usort(
				$hits,
				static fn( array $first, array $second ): int => self::assess_candidate_quality( $second, $usage, $filters )['score'] <=> self::assess_candidate_quality( $first, $usage, $filters )['score']
			);

			if ( empty( $hits ) ) {
				$tried[ $try_source->get_id() ] = 'no results found';
				continue;
			}

			$download_failures = [];

			foreach ( $hits as $hit ) {
				$assessment             = self::assess_candidate_quality( $hit, $usage, $filters );
				$hit['quality_score']   = $assessment['score'];
				$hit['quality_reasons'] = $assessment['reasons'];
				$image_id               = (string) ( $hit['id'] ?? '' );

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
	 * @param string               $tmp_file Temp file path.
	 * @param string               $keyword  Original keyword.
	 * @param array<string, mixed> $options  Options (site_url, post_id).
	 * @param array<string, mixed> $hit      Original hit data with source, attribution, author etc.
	 * @return array<string, mixed>|\WP_Error Result array or error.
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

		// Build and store attribution metadata.
		$source_id   = (string) ( $hit['source'] ?? $hit['provider'] ?? 'unknown' );
		$attribution = self::build_attribution_string( $hit, $source_id );

		if ( '' !== $attribution ) {
			update_post_meta( $attachment_id, '_sd_ai_agent_attribution', $attribution );
		}

		$attachment_url = wp_get_attachment_url( $attachment_id );

		return [
			'attachment_id'   => $attachment_id,
			'url'             => $attachment_url,
			'alt'             => $title,
			'title'           => $title,
			'source'          => $source_id,
			'attribution'     => $attribution,
			'quality_score'   => (int) ( $hit['quality_score'] ?? 0 ),
			'quality_reasons' => is_array( $hit['quality_reasons'] ?? null ) ? $hit['quality_reasons'] : array(),
			'tip'             => 'Use attachment_id as featured_image_id for create-post.',
		];
	}
}

// Initialize is handled by DI container or lazy initialization.
// The get() and get_available() methods call init() automatically if needed.
