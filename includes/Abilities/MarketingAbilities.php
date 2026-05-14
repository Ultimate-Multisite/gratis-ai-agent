<?php

declare(strict_types=1);
/**
 * Marketing and competitive analysis abilities for the AI agent.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MarketingAbilities {

	/**
	 * Register marketing abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/fetch-url',
			[
				'label'               => __( 'Fetch URL', 'superdav-ai-agent' ),
				'description'         => __( 'Fetch a URL and return HTTP status, headers, page title, meta description, and head content. Useful for competitive analysis and tech stack discovery.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'url' => [
							'type'        => 'string',
							'description' => 'The URL to fetch.',
						],
					],
					'required'   => [ 'url' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'url'              => [ 'type' => 'string' ],
						'status_code'      => [ 'type' => 'integer' ],
						'headers'          => [ 'type' => 'object' ],
						'title'            => [ 'type' => 'string' ],
						'meta_description' => [ 'type' => 'string' ],
						'generator'        => [ 'type' => 'string' ],
						'head_content'     => [ 'type' => 'string' ],
						'error'            => [ 'type' => 'string' ],
					],
				],
				'meta'                => [
					'mcp'         => [ 'public' => true ],
					'annotations' => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
				],
				'execute_callback'    => [ __CLASS__, 'handle_fetch_url' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/analyze-headers',
			[
				'label'               => __( 'Analyze HTTP Headers', 'superdav-ai-agent' ),
				'description'         => __( 'Analyze a URL\'s HTTP security and performance headers: HSTS, CSP, X-Frame-Options, caching, CDN indicators.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'url' => [
							'type'        => 'string',
							'description' => 'The URL to analyze headers for.',
						],
					],
					'required'   => [ 'url' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'url'         => [ 'type' => 'string' ],
						'status_code' => [ 'type' => 'integer' ],
						'security'    => [ 'type' => 'array' ],
						'performance' => [ 'type' => 'array' ],
						'cdn'         => [ 'type' => 'array' ],
						'error'       => [ 'type' => 'string' ],
					],
				],
				'meta'                => [
					'mcp'         => [ 'public' => true ],
					'annotations' => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
				],
				'execute_callback'    => [ __CLASS__, 'handle_analyze_headers' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			]
		);
	}

	/**
	 * Handle the fetch-url ability call.
	 *
	 * @param array<string,mixed> $input Input with url.
	 * @return array<string,mixed>|\WP_Error Fetch results.
	 */
	public static function handle_fetch_url( array $input ) {
		// @phpstan-ignore-next-line
		$url = esc_url_raw( $input['url'] ?? '' );

		if ( empty( $url ) ) {
			return new \WP_Error( 'missing_url', 'url is required.' );
		}

		$response = wp_remote_get(
			$url,
			[
				'timeout'     => 15,
				'user-agent'  => 'AI-Agent/1.0',
				'redirection' => 5,
			]
		);

		if ( is_wp_error( $response ) ) {
			return [ 'error' => 'Failed to fetch URL: ' . $response->get_error_message() ];
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$headers     = wp_remote_retrieve_headers( $response );
		$body        = wp_remote_retrieve_body( $response );

		// Extract interesting headers.
		$header_data = [];
		$interesting = [ 'content-type', 'server', 'x-powered-by', 'x-generator', 'cache-control', 'x-cache', 'cf-ray', 'x-cdn', 'via' ];
		foreach ( $interesting as $key ) {
			$val = $headers[ $key ] ?? null;
			if ( $val ) {
				$header_data[ $key ] = is_array( $val ) ? implode( ', ', $val ) : (string) $val;
			}
		}

		// Parse head content (limit to first 10KB to avoid huge payloads).
		$head_content = '';
		$title        = '';
		$meta_desc    = '';

		if ( ! empty( $body ) ) {
			if ( preg_match( '/<head[^>]*>(.*?)<\/head>/is', $body, $head_match ) ) {
				$head_content = mb_substr( $head_match[1], 0, 10240 );
			}

			if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $body, $title_match ) ) {
				$title = trim( $title_match[1] );
			}

			if ( preg_match( '/<meta[^>]*name=["\']description["\'][^>]*content=["\']([^"\']*)["\'][^>]*>/i', $body, $desc_match ) ) {
				$meta_desc = $desc_match[1];
			} elseif ( preg_match( '/<meta[^>]*content=["\']([^"\']*)["\'][^>]*name=["\']description["\'][^>]*>/i', $body, $desc_match ) ) {
				$meta_desc = $desc_match[1];
			}
		}

		// Detect generator.
		$generator = '';
		if ( preg_match( '/<meta[^>]*name=["\']generator["\'][^>]*content=["\']([^"\']*)["\'][^>]*>/i', $body, $gen_match ) ) {
			$generator = $gen_match[1];
		}

		return [
			'url'              => $url,
			'status_code'      => $status_code,
			'headers'          => $header_data,
			'title'            => $title,
			'meta_description' => $meta_desc,
			'generator'        => $generator,
			'head_content'     => $head_content,
		];
	}

	/**
	 * Handle the analyze-headers ability call.
	 *
	 * @param array<string,mixed> $input Input with url.
	 * @return array<string,mixed>|\WP_Error Header analysis results.
	 */
	public static function handle_analyze_headers( array $input ) {
		// @phpstan-ignore-next-line
		$url = esc_url_raw( $input['url'] ?? '' );

		if ( empty( $url ) ) {
			return new \WP_Error( 'missing_url', 'url is required.' );
		}

		$response = wp_remote_head(
			$url,
			[
				'timeout'     => 15,
				'user-agent'  => 'AI-Agent/1.0',
				'redirection' => 5,
			]
		);

		if ( is_wp_error( $response ) ) {
			return [ 'error' => 'Failed to fetch headers: ' . $response->get_error_message() ];
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$headers     = wp_remote_retrieve_headers( $response );

		// Security headers.
		$security = self::check_security_headers( $headers );

		// Performance headers.
		$performance = self::check_performance_headers( $headers );

		// CDN indicators.
		$cdn = self::detect_cdn( $headers );

		return [
			'url'         => $url,
			'status_code' => $status_code,
			'security'    => $security,
			'performance' => $performance,
			'cdn'         => $cdn,
		];
	}

	/**
	 * Check security-related headers.
	 *
	 * @param \WpOrg\Requests\Utility\CaseInsensitiveDictionary|array $headers Response headers.
	 * @return array<int,mixed> Security header analysis.
	 */
	private static function check_security_headers( $headers ): array {
		$checks = [
			'strict-transport-security' => [
				'label'  => 'HSTS (Strict-Transport-Security)',
				'impact' => 'Ensures browsers only connect via HTTPS.',
			],
			'x-content-type-options'    => [
				'label'  => 'X-Content-Type-Options',
				'impact' => 'Prevents MIME-type sniffing attacks.',
			],
			'x-frame-options'           => [
				'label'  => 'X-Frame-Options',
				'impact' => 'Prevents clickjacking by controlling iframe embedding.',
			],
			'content-security-policy'   => [
				'label'  => 'Content-Security-Policy',
				'impact' => 'Controls resource loading to prevent XSS and data injection.',
			],
			'referrer-policy'           => [
				'label'  => 'Referrer-Policy',
				'impact' => 'Controls how much referrer information is sent.',
			],
			'permissions-policy'        => [
				'label'  => 'Permissions-Policy',
				'impact' => 'Controls which browser features can be used.',
			],
		];

		$results = [];
		foreach ( $checks as $header => $info ) {
			$value     = $headers[ $header ] ?? null;
			$results[] = [
				'header' => $info['label'],
				'status' => $value ? 'present' : 'missing',
				// @phpstan-ignore-next-line
				'value'  => $value ? ( is_array( $value ) ? implode( ', ', $value ) : (string) $value ) : null,
				'impact' => $info['impact'],
			];
		}

		return $results;
	}

	/**
	 * Check performance-related headers.
	 *
	 * @param \WpOrg\Requests\Utility\CaseInsensitiveDictionary|array $headers Response headers.
	 * @return array<int,mixed> Performance header analysis.
	 */
	private static function check_performance_headers( $headers ): array {
		$results = [];

		$cache_control = $headers['cache-control'] ?? null;
		$results[]     = [
			'header' => 'Cache-Control',
			'status' => $cache_control ? 'present' : 'missing',
			// @phpstan-ignore-next-line
			'value'  => $cache_control ? ( is_array( $cache_control ) ? implode( ', ', $cache_control ) : (string) $cache_control ) : null,
		];

		$etag      = $headers['etag'] ?? null;
		$results[] = [
			'header' => 'ETag',
			'status' => $etag ? 'present' : 'missing',
			// @phpstan-ignore-next-line
			'value'  => $etag ? ( is_array( $etag ) ? implode( ', ', $etag ) : (string) $etag ) : null,
		];

		$vary      = $headers['vary'] ?? null;
		$results[] = [
			'header' => 'Vary',
			'status' => $vary ? 'present' : 'missing',
			// @phpstan-ignore-next-line
			'value'  => $vary ? ( is_array( $vary ) ? implode( ', ', $vary ) : (string) $vary ) : null,
		];

		return $results;
	}

	/**
	 * Detect CDN indicators from headers.
	 *
	 * @param \WpOrg\Requests\Utility\CaseInsensitiveDictionary|array $headers Response headers.
	 * @return array<int,mixed> CDN detection results.
	 */
	private static function detect_cdn( $headers ): array {
		$indicators = [
			'cf-ray'       => 'Cloudflare',
			'x-cache'      => 'CDN Cache',
			'x-cdn'        => 'CDN',
			'x-amz-cf-id'  => 'Amazon CloudFront',
			'x-served-by'  => 'Fastly / Varnish',
			'x-vercel-id'  => 'Vercel',
			'x-netlify-id' => 'Netlify',
			'via'          => 'Proxy / CDN',
			'server'       => 'Server',
		];

		$detected = [];
		foreach ( $indicators as $header => $provider ) {
			$value = $headers[ $header ] ?? null;
			if ( $value ) {
				$detected[] = [
					'indicator' => $header,
					'provider'  => $provider,
					// @phpstan-ignore-next-line
					'value'     => is_array( $value ) ? implode( ', ', $value ) : (string) $value,
				];
			}
		}

		return $detected;
	}
}
