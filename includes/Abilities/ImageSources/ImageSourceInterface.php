<?php

declare(strict_types=1);
/**
 * Image source interface for flexible stock image retrieval.
 *
 * Provides a unified interface for multiple image providers:
 * - Openverse (WordPress, CC0 images)
 * - Pixabay (free commercial)
 * - Generate (DALL-E AI)
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
 * Interface for image source providers.
 *
 * @since 1.5.0
 */
interface ImageSourceInterface {

	/**
	 * Get the source identifier.
	 *
	 * @return string Source ID (e.g., 'openverse', 'pixabay', 'generate').
	 */
	public function get_id(): string;

	/**
	 * Get display name for the source.
	 *
	 * @return string Human-readable name.
	 */
	public function get_name(): string;

	/**
	 * Check if this source is available/configured.
	 *
	 * @return bool True if source can be used.
	 */
	public function is_available(): bool;

	/**
	 * Search for images by keyword.
	 *
	 * @param string               $keyword  Search term.
	 * @param int                  $per_page Number of results to return.
	 * @param array<string, mixed> $filters  Optional filters:
	 *                                       - 'orientation' (string) 'landscape'|'portrait'|'squarish'.
	 *                                       - 'colour'      (string) Colour name (provider-specific).
	 *                                       - 'min_width'   (int)    Minimum image width in pixels.
	 *                                       - 'min_height'  (int)    Minimum image height in pixels.
	 * @return array{hits: array<int, array<string, mixed>>, total: int, source: string}|\WP_Error Array with hits or error.
	 */
	public function search( string $keyword, int $per_page = 10, array $filters = [] ): array|\WP_Error;

	/**
	 * Get a single image by ID.
	 *
	 * @param string $image_id Provider image ID.
	 * @return array{url: string, width: int, height: int, author: string, author_url: string, license: string, source: string}|\WP_Error
	 */
	public function get_image( string $image_id ): array|\WP_Error;

	/**
	 * Download an image to a temp file.
	 *
	 * @param string $image_id Provider image ID.
	 * @param int    $width   Desired width (0 for original).
	 * @param int    $height  Desired height (0 for original).
	 * @return string|\WP_Error Temp file path or error.
	 */
	public function download( string $image_id, int $width = 0, int $height = 0 ): string|\WP_Error;

	/**
	 * Get the cost type for this source.
	 *
	 * @return 'free'|'paid'|'api' Cost model.
	 */
	public function get_cost_type(): string;
}
