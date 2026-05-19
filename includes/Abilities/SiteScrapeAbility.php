<?php

declare(strict_types=1);
/**
 * Site scrape ability for Theme Builder onboarding.
 *
 * @package SdAiAgent\Abilities
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Services\SiteScraper;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scrapes an existing public website into structured Theme Builder pre-fill data.
 */
class SiteScrapeAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'Scrape Existing Site', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Fetch and parse an existing website to pre-fill Theme Builder interviews with brand, contact, hours, logo, social links, and page text.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'url'                 => [
					'type'        => 'string',
					'format'      => 'uri',
					'description' => 'Existing public website URL to scrape after the user consents.',
				],
				'max_pages'           => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 25,
					'description' => 'Maximum pages to crawl. Defaults to 10.',
				],
				'target_pages'        => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => 'Optional explicit paths or URLs to fetch, such as /about or /contact.',
				],
				'extract_preferences' => [
					'type'        => 'string',
					'enum'        => [ 'auto', 'structured_only', 'full_text' ],
					'description' => 'Extraction mode. auto uses structured parsers and AI gap-fill when available.',
				],
			],
			'required'   => [ 'url' ],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'brand'   => [ 'type' => 'object' ],
				'contact' => [ 'type' => 'object' ],
				'hours'   => [ 'type' => 'array' ],
				'social'  => [ 'type' => 'object' ],
				'pages'   => [ 'type' => 'array' ],
				'errors'  => [ 'type' => 'array' ],
				'cached'  => [ 'type' => 'boolean' ],
			],
		];
	}

	protected function execute_callback( $input ): array|WP_Error {
		$url         = isset( $input['url'] ) ? (string) $input['url'] : '';
		$max_pages   = isset( $input['max_pages'] ) ? (int) $input['max_pages'] : 10;
		$target      = isset( $input['target_pages'] ) && is_array( $input['target_pages'] ) ? array_values( array_map( 'strval', $input['target_pages'] ) ) : [];
		$preferences = isset( $input['extract_preferences'] ) ? (string) $input['extract_preferences'] : 'auto';

		return ( new SiteScraper() )->scrape( $url, $max_pages, $target, $preferences );
	}

	protected function permission_callback( $input ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	protected function meta(): array {
		$meta                = parent::meta();
		$meta['annotations'] = [
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		];

		return $meta;
	}
}
