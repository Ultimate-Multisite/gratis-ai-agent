<?php
/**
 * Core Image block enricher.
 *
 * Enriches `core/image` blocks with attachment metadata so agents get
 * srcset, sizes, alt text, intrinsic dimensions, and more without a
 * separate round-trip to the attachment endpoint.
 *
 * Inspired by block-mcp's `class-core-image-enricher.php` (GPL-2.0+).
 *
 * @package SdAiAgent\Enrichers
 * @license GPL-2.0-or-later
 * @since   1.13.0
 */

declare(strict_types=1);

namespace SdAiAgent\Enrichers;

use SdAiAgent\Core\BlockEnricherInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enricher for `core/image` blocks.
 *
 * Populates `block.enriched.core_image` with:
 * - attachment_id, url, alt, width, height, aspect_ratio
 * - mime_type, srcset, sizes, filesize_bytes
 * - missing (bool) — true when the attachment is unavailable
 */
final class CoreImageEnricher implements BlockEnricherInterface {

	/**
	 * The block name this enricher targets.
	 */
	private const BLOCK_NAME = 'core/image';

	/**
	 * Enricher identifier used as the key under `enriched.<id>`.
	 */
	private const ENRICHER_ID = 'core_image';

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return self::ENRICHER_ID;
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports( string $block_name ): bool {
		return self::BLOCK_NAME === $block_name;
	}

	/**
	 * {@inheritDoc}
	 */
	public function enrich( array $block, array $context ): array {
		$attrs         = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];
		$attachment_id = isset( $attrs['id'] ) ? (int) $attrs['id'] : 0;

		// No attachment ID in attributes → missing.
		if ( 0 === $attachment_id ) {
			return [ 'missing' => true ];
		}

		// Verify the attachment post exists and is an attachment.
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return [
				'attachment_id' => $attachment_id,
				'missing'       => true,
			];
		}

		// Fetch attachment metadata.
		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( empty( $metadata ) || ! is_array( $metadata ) ) {
			return [
				'attachment_id' => $attachment_id,
				'missing'       => true,
			];
		}

		$width  = isset( $metadata['width'] ) ? (int) $metadata['width'] : 0;
		$height = isset( $metadata['height'] ) ? (int) $metadata['height'] : 0;

		// If the block specifies a sizeSlug, use the intermediate size dimensions.
		$size_slug = isset( $attrs['sizeSlug'] ) ? (string) $attrs['sizeSlug'] : '';
		if ( '' !== $size_slug && isset( $metadata['sizes'][ $size_slug ] ) ) {
			$size = $metadata['sizes'][ $size_slug ];
			if ( isset( $size['width'] ) ) {
				$width = (int) $size['width'];
			}
			if ( isset( $size['height'] ) ) {
				$height = (int) $size['height'];
			}
		}

		// Build the URL.
		$url = (string) wp_get_attachment_url( $attachment_id );

		// Alt text from attachment meta.
		$alt = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		// Compute aspect ratio as a simplified fraction.
		$aspect_ratio = self::compute_aspect_ratio( $width, $height );

		// MIME type.
		$mime_type = (string) $attachment->post_mime_type;

		// Srcset and sizes.
		$srcset = '';
		$sizes  = '';
		if ( $width > 0 && $height > 0 ) {
			$image_src = [ $url, $width, $height, false ];
			$srcset    = (string) wp_calculate_image_srcset( [ $width, $height ], $url, $metadata, $attachment_id );
			$sizes     = (string) wp_calculate_image_sizes( [ $width, $height ], $url, $metadata, $attachment_id );
		}

		// File size from metadata or filesystem.
		$filesize_bytes = isset( $metadata['filesize'] ) ? (int) $metadata['filesize'] : 0;
		if ( 0 === $filesize_bytes ) {
			$file = get_attached_file( $attachment_id );
			if ( is_string( $file ) && file_exists( $file ) ) {
				$filesize_bytes = (int) filesize( $file );
			}
		}

		return [
			'attachment_id'  => $attachment_id,
			'url'            => $url,
			'alt'            => $alt,
			'width'          => $width,
			'height'         => $height,
			'aspect_ratio'   => $aspect_ratio,
			'mime_type'      => $mime_type,
			'srcset'         => $srcset,
			'sizes'          => $sizes,
			'filesize_bytes' => $filesize_bytes,
			'missing'        => false,
		];
	}

	/**
	 * Compute a simplified aspect ratio string (e.g. "3:2", "16:9").
	 *
	 * Returns an empty string when either dimension is zero.
	 *
	 * @param int $width  Image width.
	 * @param int $height Image height.
	 * @return string Simplified aspect ratio or empty string.
	 */
	private static function compute_aspect_ratio( int $width, int $height ): string {
		if ( 0 === $width || 0 === $height ) {
			return '';
		}

		$gcd = self::gcd( $width, $height );

		return ( $width / $gcd ) . ':' . ( $height / $gcd );
	}

	/**
	 * Compute the greatest common divisor of two positive integers.
	 *
	 * @param int $a First integer.
	 * @param int $b Second integer.
	 * @return int GCD.
	 */
	private static function gcd( int $a, int $b ): int {
		$a = abs( $a );
		$b = abs( $b );

		while ( 0 !== $b ) {
			$t = $b;
			$b = $a % $b;
			$a = $t;
		}

		return $a;
	}
}
