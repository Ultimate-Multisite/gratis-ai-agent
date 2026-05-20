<?php

declare(strict_types=1);
/**
 * Site scrape ability — pre-fill Theme Builder interviews from existing sites.
 *
 * Registers the `sd-ai-agent/site-scrape` ability which fetches an existing
 * website and returns structured brand/contact/hours data. Agents should offer
 * this at the start of any Theme Builder interview:
 *
 *   "Do you have an existing site? If yes, paste the URL and I'll pre-fill
 *    what I can."
 *
 * The ability delegates all HTTP and parsing work to SiteScraper, which:
 *   - Validates the URL (any valid http/https URL is accepted — no domain
 *     allowlist, because the user explicitly provides their own site's URL)
 *   - Respects robots.txt
 *   - Caches results in transients for 24 hours per URL
 *   - Parses Schema.org JSON-LD, OpenGraph, and heuristic patterns
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
 * Ability: sd-ai-agent/site-scrape
 *
 * @since 1.7.0
 */
class SiteScrapeAbility extends AbstractAbility {

	protected function label(): string {
		return __( 'Scrape Existing Site', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __(
			'Fetch and parse an existing website to extract structured brand and contact data (name, tagline, logo, address, phone, email, opening hours, social links). Use this at the start of a Theme Builder session when the user has an existing site they are rebuilding — it dramatically shortens the interview by pre-filling what can be detected automatically. Always ask for user consent before calling this ability since it makes outbound HTTP requests from the server.',
			'superdav-ai-agent'
		);
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'url'          => [
					'type'        => 'string',
					'description' => 'Absolute URL of the existing site to scrape (e.g. "https://example.com"). Must be http or https.',
				],
				'max_pages'    => [
					'type'        => 'integer',
					'description' => 'Maximum number of pages to crawl. Default: 10.',
				],
				'pages'        => [
					'type'        => 'array',
					'description' => 'Explicit list of path-or-URL strings to crawl instead of the defaults (e.g. ["/", "/about", "/contact"]). Relative paths are resolved against the site origin.',
					'items'       => [ 'type' => 'string' ],
				],
				'bypass_cache' => [
					'type'        => 'boolean',
					'description' => 'When true, ignores any cached result and re-fetches live. Default: false.',
				],
			],
			'required'   => [ 'url' ],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'brand'   => [
					'type'       => 'object',
					'properties' => [
						'name'     => [ 'type' => [ 'string', 'null' ] ],
						'tagline'  => [ 'type' => [ 'string', 'null' ] ],
						'logo_url' => [ 'type' => [ 'string', 'null' ] ],
					],
				],
				'contact' => [
					'type'       => 'object',
					'properties' => [
						'address' => [ 'type' => [ 'string', 'null' ] ],
						'phone'   => [ 'type' => [ 'string', 'null' ] ],
						'email'   => [ 'type' => [ 'string', 'null' ] ],
					],
				],
				'hours'   => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'day'   => [ 'type' => 'string' ],
							'open'  => [ 'type' => 'string' ],
							'close' => [ 'type' => 'string' ],
						],
					],
				],
				'social'  => [
					'type'                 => 'object',
					'additionalProperties' => [ 'type' => 'string' ],
				],
				'pages'   => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'url'      => [ 'type' => 'string' ],
							'title'    => [ 'type' => 'string' ],
							'text'     => [ 'type' => 'string' ],
							'headings' => [
								'type'  => 'array',
								'items' => [ 'type' => 'string' ],
							],
						],
					],
				],
			],
		];
	}

	protected function execute_callback( $input ): array|WP_Error {
		/** @var array<string,mixed> $input */
		$url     = (string) ( $input['url'] ?? '' );
		$scraper = new SiteScraper();

		return $scraper->scrape(
			$url,
			[
				'max_pages'    => isset( $input['max_pages'] ) ? (int) $input['max_pages'] : 10,
				'pages'        => isset( $input['pages'] ) && is_array( $input['pages'] ) ? $input['pages'] : null,
				'bypass_cache' => ! empty( $input['bypass_cache'] ),
			]
		);
	}

	protected function permission_callback( $input ): bool {
		return current_user_can( 'edit_posts' );
	}

	protected function meta(): array {
		return [
			'mcp'          => [ 'public' => true ],
			'annotations'  => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
			'show_in_rest' => true,
		];
	}
}
