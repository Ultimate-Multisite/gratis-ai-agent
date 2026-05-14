<?php

declare(strict_types=1);
/**
 * Google Search Console abilities for the AI agent.
 *
 * Provides SEO insights (top queries, impressions, clicks, position data)
 * by querying the Google Search Console API using a stored service-account
 * JSON key or OAuth2 access token.
 *
 * Authentication options (in priority order):
 *   1. Service-account JSON key stored via Settings::set_gsc_credentials().
 *   2. OAuth2 access token stored via Settings::set_gsc_credentials().
 *
 * The GSC Search Analytics API endpoint used:
 *   POST https://searchconsole.googleapis.com/webmasters/v3/sites/{siteUrl}/searchAnalytics/query
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Core\Settings;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GscAbilities {

	/**
	 * GSC Search Analytics API base URL.
	 */
	const GSC_API_BASE = 'https://searchconsole.googleapis.com/webmasters/v3/sites/';

	/**
	 * Google OAuth2 token endpoint.
	 */
	const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';

	/**
	 * Register GSC abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/gsc-top-queries',
			[
				'label'               => __( 'GSC Top Queries', 'superdav-ai-agent' ),
				'description'         => __( 'Fetch top search queries from Google Search Console with impressions, clicks, CTR, and average position for a given date range.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'site_url'   => [
							'type'        => 'string',
							'description' => 'The property URL in Google Search Console (e.g. "https://example.com/" or "sc-domain:example.com"). Defaults to the WordPress site URL.',
						],
						'start_date' => [
							'type'        => 'string',
							'description' => 'Start date in YYYY-MM-DD format. Defaults to 28 days ago.',
						],
						'end_date'   => [
							'type'        => 'string',
							'description' => 'End date in YYYY-MM-DD format. Defaults to yesterday.',
						],
						'limit'      => [
							'type'        => 'integer',
							'description' => 'Maximum number of queries to return (1-25, default 10).',
						],
						'page'       => [
							'type'        => 'string',
							'description' => 'Optional: filter results to a specific page URL.',
						],
						'country'    => [
							'type'        => 'string',
							'description' => 'Optional: filter by country code (e.g. "gbr", "usa").',
						],
						'device'     => [
							'type'        => 'string',
							'description' => 'Optional: filter by device type — "DESKTOP", "MOBILE", or "TABLET".',
						],
					],
					'required'   => [],
				],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
				'execute_callback'    => [ __CLASS__, 'handle_top_queries' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/gsc-page-performance',
			[
				'label'               => __( 'GSC Page Performance', 'superdav-ai-agent' ),
				'description'         => __( 'Fetch page-level performance data from Google Search Console: which pages get the most impressions, clicks, and their average position.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'site_url'   => [
							'type'        => 'string',
							'description' => 'The property URL in Google Search Console. Defaults to the WordPress site URL.',
						],
						'start_date' => [
							'type'        => 'string',
							'description' => 'Start date in YYYY-MM-DD format. Defaults to 28 days ago.',
						],
						'end_date'   => [
							'type'        => 'string',
							'description' => 'End date in YYYY-MM-DD format. Defaults to yesterday.',
						],
						'limit'      => [
							'type'        => 'integer',
							'description' => 'Maximum number of pages to return (1-25, default 10).',
						],
						'query'      => [
							'type'        => 'string',
							'description' => 'Optional: filter results to pages ranking for a specific query.',
						],
						'country'    => [
							'type'        => 'string',
							'description' => 'Optional: filter by country code (e.g. "gbr", "usa").',
						],
						'device'     => [
							'type'        => 'string',
							'description' => 'Optional: filter by device type — "DESKTOP", "MOBILE", or "TABLET".',
						],
					],
					'required'   => [],
				],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
				'execute_callback'    => [ __CLASS__, 'handle_page_performance' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/gsc-query-details',
			[
				'label'               => __( 'GSC Query Details', 'superdav-ai-agent' ),
				'description'         => __( 'Get detailed performance data for a specific search query from Google Search Console, including which pages rank for it and their metrics.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'query'      => [
							'type'        => 'string',
							'description' => 'The search query to analyse.',
						],
						'site_url'   => [
							'type'        => 'string',
							'description' => 'The property URL in Google Search Console. Defaults to the WordPress site URL.',
						],
						'start_date' => [
							'type'        => 'string',
							'description' => 'Start date in YYYY-MM-DD format. Defaults to 28 days ago.',
						],
						'end_date'   => [
							'type'        => 'string',
							'description' => 'End date in YYYY-MM-DD format. Defaults to yesterday.',
						],
					],
					'required'   => [ 'query' ],
				],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
				'execute_callback'    => [ __CLASS__, 'handle_query_details' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/gsc-site-summary',
			[
				'label'               => __( 'GSC Site Summary', 'superdav-ai-agent' ),
				'description'         => __( 'Get an overall SEO performance summary from Google Search Console: total clicks, impressions, average CTR, and average position for the site over a date range.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'site_url'         => [
							'type'        => 'string',
							'description' => 'The property URL in Google Search Console. Defaults to the WordPress site URL.',
						],
						'start_date'       => [
							'type'        => 'string',
							'description' => 'Start date in YYYY-MM-DD format. Defaults to 28 days ago.',
						],
						'end_date'         => [
							'type'        => 'string',
							'description' => 'End date in YYYY-MM-DD format. Defaults to yesterday.',
						],
						'compare_previous' => [
							'type'        => 'boolean',
							'description' => 'If true, also fetch the previous equivalent period for comparison. Default false.',
						],
					],
					'required'   => [],
				],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
				'execute_callback'    => [ __CLASS__, 'handle_site_summary' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			]
		);
	}

	// -------------------------------------------------------------------------
	// Ability handlers
	// -------------------------------------------------------------------------

	/**
	 * Handle gsc-top-queries ability.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_top_queries( array $input ): array|WP_Error {
		$token = self::get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		[ $start, $end ] = self::resolve_date_range( $input );
		$site_url        = self::resolve_site_url( $input );
		// @phpstan-ignore-next-line
		$limit = min( 25, max( 1, (int) ( $input['limit'] ?? 10 ) ) );

		$body = [
			'startDate'  => $start,
			'endDate'    => $end,
			'dimensions' => [ 'query' ],
			'rowLimit'   => $limit,
			'startRow'   => 0,
		];

		$body = self::apply_dimension_filters( $body, $input, [ 'page', 'country', 'device' ] );

		$rows = self::query_search_analytics( $token, $site_url, $body );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$queries = [];
		foreach ( $rows as $row ) {
			$queries[] = [
				// @phpstan-ignore-next-line
				'query'       => $row['keys'][0] ?? '',
				// @phpstan-ignore-next-line
				'clicks'      => (int) ( $row['clicks'] ?? 0 ),
				// @phpstan-ignore-next-line
				'impressions' => (int) ( $row['impressions'] ?? 0 ),
				// @phpstan-ignore-next-line
				'ctr'         => round( (float) ( $row['ctr'] ?? 0 ) * 100, 2 ),
				// @phpstan-ignore-next-line
				'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
			];
		}

		return [
			'site_url'   => $site_url,
			'start_date' => $start,
			'end_date'   => $end,
			'total'      => count( $queries ),
			'queries'    => $queries,
		];
	}

	/**
	 * Handle gsc-page-performance ability.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_page_performance( array $input ): array|WP_Error {
		$token = self::get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		[ $start, $end ] = self::resolve_date_range( $input );
		$site_url        = self::resolve_site_url( $input );
		// @phpstan-ignore-next-line
		$limit = min( 25, max( 1, (int) ( $input['limit'] ?? 10 ) ) );

		$body = [
			'startDate'  => $start,
			'endDate'    => $end,
			'dimensions' => [ 'page' ],
			'rowLimit'   => $limit,
			'startRow'   => 0,
		];

		$body = self::apply_dimension_filters( $body, $input, [ 'query', 'country', 'device' ] );

		$rows = self::query_search_analytics( $token, $site_url, $body );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$pages = [];
		foreach ( $rows as $row ) {
			$pages[] = [
				// @phpstan-ignore-next-line
				'page'        => $row['keys'][0] ?? '',
				// @phpstan-ignore-next-line
				'clicks'      => (int) ( $row['clicks'] ?? 0 ),
				// @phpstan-ignore-next-line
				'impressions' => (int) ( $row['impressions'] ?? 0 ),
				// @phpstan-ignore-next-line
				'ctr'         => round( (float) ( $row['ctr'] ?? 0 ) * 100, 2 ),
				// @phpstan-ignore-next-line
				'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
			];
		}

		return [
			'site_url'   => $site_url,
			'start_date' => $start,
			'end_date'   => $end,
			'total'      => count( $pages ),
			'pages'      => $pages,
		];
	}

	/**
	 * Handle gsc-query-details ability.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_query_details( array $input ): array|WP_Error {
		// @phpstan-ignore-next-line
		$query = sanitize_text_field( $input['query'] ?? '' );
		if ( empty( $query ) ) {
			return new WP_Error( 'missing_param', __( 'query is required.', 'superdav-ai-agent' ) );
		}

		$token = self::get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		[ $start, $end ] = self::resolve_date_range( $input );
		$site_url        = self::resolve_site_url( $input );

		// Fetch overall metrics for this query.
		$summary_body = [
			'startDate'             => $start,
			'endDate'               => $end,
			'dimensions'            => [ 'query' ],
			'dimensionFilterGroups' => [
				[
					'filters' => [
						[
							'dimension'  => 'query',
							'operator'   => 'equals',
							'expression' => $query,
						],
					],
				],
			],
			'rowLimit'              => 1,
		];

		$summary_rows = self::query_search_analytics( $token, $site_url, $summary_body );
		if ( is_wp_error( $summary_rows ) ) {
			return $summary_rows;
		}

		$summary = [];
		if ( ! empty( $summary_rows[0] ) ) {
			$r       = $summary_rows[0];
			$summary = [
				// @phpstan-ignore-next-line
				'clicks'      => (int) ( $r['clicks'] ?? 0 ),
				// @phpstan-ignore-next-line
				'impressions' => (int) ( $r['impressions'] ?? 0 ),
				// @phpstan-ignore-next-line
				'ctr'         => round( (float) ( $r['ctr'] ?? 0 ) * 100, 2 ),
				// @phpstan-ignore-next-line
				'position'    => round( (float) ( $r['position'] ?? 0 ), 1 ),
			];
		}

		// Fetch pages ranking for this query.
		$pages_body = [
			'startDate'             => $start,
			'endDate'               => $end,
			'dimensions'            => [ 'page' ],
			'dimensionFilterGroups' => [
				[
					'filters' => [
						[
							'dimension'  => 'query',
							'operator'   => 'equals',
							'expression' => $query,
						],
					],
				],
			],
			'rowLimit'              => 10,
		];

		$page_rows = self::query_search_analytics( $token, $site_url, $pages_body );
		if ( is_wp_error( $page_rows ) ) {
			return $page_rows;
		}

		$pages = [];
		foreach ( $page_rows as $row ) {
			$pages[] = [
				// @phpstan-ignore-next-line
				'page'        => $row['keys'][0] ?? '',
				// @phpstan-ignore-next-line
				'clicks'      => (int) ( $row['clicks'] ?? 0 ),
				// @phpstan-ignore-next-line
				'impressions' => (int) ( $row['impressions'] ?? 0 ),
				// @phpstan-ignore-next-line
				'ctr'         => round( (float) ( $row['ctr'] ?? 0 ) * 100, 2 ),
				// @phpstan-ignore-next-line
				'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
			];
		}

		return [
			'query'      => $query,
			'site_url'   => $site_url,
			'start_date' => $start,
			'end_date'   => $end,
			'summary'    => $summary,
			'pages'      => $pages,
		];
	}

	/**
	 * Handle gsc-site-summary ability.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle_site_summary( array $input ): array|WP_Error {
		$token = self::get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		[ $start, $end ] = self::resolve_date_range( $input );
		$site_url        = self::resolve_site_url( $input );
		$compare         = (bool) ( $input['compare_previous'] ?? false );

		$body = [
			'startDate'  => $start,
			'endDate'    => $end,
			'dimensions' => [],
			'rowLimit'   => 1,
		];

		$rows = self::query_search_analytics( $token, $site_url, $body );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$current = [];
		if ( ! empty( $rows[0] ) ) {
			$r       = $rows[0];
			$current = [
				// @phpstan-ignore-next-line
				'clicks'      => (int) ( $r['clicks'] ?? 0 ),
				// @phpstan-ignore-next-line
				'impressions' => (int) ( $r['impressions'] ?? 0 ),
				// @phpstan-ignore-next-line
				'ctr'         => round( (float) ( $r['ctr'] ?? 0 ) * 100, 2 ),
				// @phpstan-ignore-next-line
				'position'    => round( (float) ( $r['position'] ?? 0 ), 1 ),
			];
		}

		$result = [
			'site_url'   => $site_url,
			'start_date' => $start,
			'end_date'   => $end,
			'current'    => $current,
		];

		if ( $compare ) {
			[ $prev_start, $prev_end ] = self::previous_period( $start, $end );

			$prev_body = [
				'startDate'  => $prev_start,
				'endDate'    => $prev_end,
				'dimensions' => [],
				'rowLimit'   => 1,
			];

			$prev_rows = self::query_search_analytics( $token, $site_url, $prev_body );
			if ( ! is_wp_error( $prev_rows ) && ! empty( $prev_rows[0] ) ) {
				$r                  = $prev_rows[0];
				$result['previous'] = [
					'start_date'  => $prev_start,
					'end_date'    => $prev_end,
					// @phpstan-ignore-next-line
					'clicks'      => (int) ( $r['clicks'] ?? 0 ),
					// @phpstan-ignore-next-line
					'impressions' => (int) ( $r['impressions'] ?? 0 ),
					// @phpstan-ignore-next-line
					'ctr'         => round( (float) ( $r['ctr'] ?? 0 ) * 100, 2 ),
					// @phpstan-ignore-next-line
					'position'    => round( (float) ( $r['position'] ?? 0 ), 1 ),
				];

				// Compute deltas.
				if ( ! empty( $current ) ) {
					$prev             = $result['previous'];
					$result['change'] = [
						'clicks'      => $current['clicks'] - $prev['clicks'],
						'impressions' => $current['impressions'] - $prev['impressions'],
						'ctr'         => round( $current['ctr'] - $prev['ctr'], 2 ),
						'position'    => round( $current['position'] - $prev['position'], 1 ),
					];
				}
			}
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Resolve the GSC access token.
	 *
	 * Supports two credential types stored via Settings::set_gsc_credentials():
	 *   - 'service_account': JSON key → exchange for a short-lived access token.
	 *   - 'access_token': a pre-obtained OAuth2 access token (user-managed refresh).
	 *
	 * @return string|WP_Error Access token string or WP_Error.
	 */
	private static function get_access_token(): string|WP_Error {
		$creds = Settings::instance()->get_gsc_credentials();

		if ( empty( $creds ) || empty( $creds['type'] ) ) {
			return new WP_Error(
				'gsc_not_configured',
				__( 'Google Search Console credentials are not configured. Go to Superdav AI Agent Settings and add your GSC credentials.', 'superdav-ai-agent' )
			);
		}

		if ( 'service_account' === $creds['type'] ) {
			return self::exchange_service_account_token( $creds );
		}

		if ( 'access_token' === $creds['type'] ) {
			$token = $creds['access_token'] ?? '';
			if ( empty( $token ) ) {
				return new WP_Error( 'gsc_missing_token', __( 'GSC access token is empty.', 'superdav-ai-agent' ) );
			}
			// @phpstan-ignore-next-line
			return $token;
		}

		return new WP_Error( 'gsc_unknown_type', __( 'Unknown GSC credential type.', 'superdav-ai-agent' ) );
	}

	/**
	 * Exchange a service-account JSON key for a short-lived access token via JWT.
	 *
	 * @param array<string, mixed> $creds Credential array with service-account fields.
	 * @return string|WP_Error
	 */
	private static function exchange_service_account_token( array $creds ): string|WP_Error {
		$private_key  = $creds['private_key'] ?? '';
		$client_email = $creds['client_email'] ?? '';

		if ( empty( $private_key ) || empty( $client_email ) ) {
			return new WP_Error(
				'gsc_invalid_sa',
				__( 'Service account credentials are missing private_key or client_email.', 'superdav-ai-agent' )
			);
		}

		// Check transient cache first (tokens are valid for 1 hour).
		// @phpstan-ignore-next-line
		$cache_key    = 'sd_gsc_token_' . md5( $client_email );
		$cached_token = get_transient( $cache_key );
		if ( is_string( $cached_token ) && ! empty( $cached_token ) ) {
			return $cached_token;
		}

		// Build JWT.
		$now = time();
		$jwt = self::build_jwt(
			// @phpstan-ignore-next-line
			$client_email,
			// @phpstan-ignore-next-line
			$private_key,
			$now,
			$now + 3600,
			'https://www.googleapis.com/auth/webmasters.readonly'
		);

		if ( is_wp_error( $jwt ) ) {
			return $jwt;
		}

		// Exchange JWT for access token.
		$response = wp_remote_post(
			self::GOOGLE_TOKEN_URL,
			[
				'timeout' => 15,
				'body'    => [
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'gsc_token_request_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to request GSC access token: %s', 'superdav-ai-agent' ),
					$response->get_error_message()
				)
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = wp_remote_retrieve_response_code( $response );

		// @phpstan-ignore-next-line
		if ( 200 !== $code || empty( $body['access_token'] ) ) {
			// @phpstan-ignore-next-line
			$error_desc = $body['error_description'] ?? $body['error'] ?? 'Unknown error';
			return new WP_Error(
				'gsc_token_error',
				sprintf(
					/* translators: %s: error description from Google */
					__( 'Google token exchange failed: %s', 'superdav-ai-agent' ),
					// @phpstan-ignore-next-line
					$error_desc
				)
			);
		}

		// @phpstan-ignore-next-line
		$access_token = (string) $body['access_token'];
		// @phpstan-ignore-next-line
		$expires_in = (int) ( $body['expires_in'] ?? 3600 );

		// Cache with a 5-minute safety margin.
		set_transient( $cache_key, $access_token, max( 60, $expires_in - 300 ) );

		return $access_token;
	}

	/**
	 * Build a signed JWT for Google service-account authentication.
	 *
	 * @param string $client_email Service account email.
	 * @param string $private_key  PEM-encoded RSA private key.
	 * @param int    $iat          Issued-at timestamp.
	 * @param int    $exp          Expiry timestamp.
	 * @param string $scope        OAuth2 scope.
	 * @return string|WP_Error Signed JWT or WP_Error.
	 */
	private static function build_jwt(
		string $client_email,
		string $private_key,
		int $iat,
		int $exp,
		string $scope
	): string|WP_Error {
		if ( ! function_exists( 'openssl_sign' ) ) {
			return new WP_Error(
				'gsc_openssl_missing',
				__( 'OpenSSL extension is required for service-account authentication.', 'superdav-ai-agent' )
			);
		}

		$header  = self::base64url_encode(
			(string) wp_json_encode(
				[
					'alg' => 'RS256',
					'typ' => 'JWT',
				]
			)
		);
		$payload = self::base64url_encode(
			(string) wp_json_encode(
				[
					'iss'   => $client_email,
					'scope' => $scope,
					'aud'   => self::GOOGLE_TOKEN_URL,
					'exp'   => $exp,
					'iat'   => $iat,
				]
			)
		);

		$signing_input = $header . '.' . $payload;
		$signature     = '';

		$key_resource = openssl_pkey_get_private( $private_key );
		if ( false === $key_resource ) {
			return new WP_Error(
				'gsc_invalid_key',
				__( 'Failed to load the service-account private key. Ensure it is a valid PEM-encoded RSA key.', 'superdav-ai-agent' )
			);
		}

		$signed = openssl_sign( $signing_input, $signature, $key_resource, OPENSSL_ALGO_SHA256 );

		if ( ! $signed ) {
			return new WP_Error( 'gsc_sign_failed', __( 'Failed to sign the JWT.', 'superdav-ai-agent' ) );
		}

		return $signing_input . '.' . self::base64url_encode( $signature );
	}

	/**
	 * Base64url-encode a string (RFC 4648 §5, no padding).
	 *
	 * @param string $data Raw bytes.
	 * @return string
	 */
	private static function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Used for JWT base64url encoding (RFC 4648 §5), not obfuscation.
	}

	/**
	 * Execute a Search Analytics query against the GSC API.
	 *
	 * @param string               $access_token Valid OAuth2 access token.
	 * @param string               $site_url     Encoded GSC property URL.
	 * @param array<string, mixed> $body         Request body.
	 * @return array<int, array<string, mixed>>|WP_Error Rows array or WP_Error.
	 */
	private static function query_search_analytics(
		string $access_token,
		string $site_url,
		array $body
	): array|WP_Error {
		$encoded_site = rawurlencode( $site_url );
		$url          = self::GSC_API_BASE . $encoded_site . '/searchAnalytics/query';

		$response = wp_remote_post(
			$url,
			[
				'timeout' => 20,
				'headers' => [
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				],
				'body'    => (string) wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'gsc_api_request_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'GSC API request failed: %s', 'superdav-ai-agent' ),
					$response->get_error_message()
				)
			);
		}

		$code          = wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			// @phpstan-ignore-next-line
			$error_msg = $response_body['error']['message'] ?? 'Unknown GSC API error';
			// @phpstan-ignore-next-line
			$error_status = $response_body['error']['status'] ?? (string) $code;

			if ( 403 === $code ) {
				return new WP_Error(
					'gsc_forbidden',
					sprintf(
						/* translators: %s: site URL */
						__( 'Access denied to GSC property "%s". Ensure the service account or token has access to this property in Google Search Console.', 'superdav-ai-agent' ),
						$site_url
					)
				);
			}

			if ( 404 === $code ) {
				return new WP_Error(
					'gsc_not_found',
					sprintf(
						/* translators: %s: site URL */
						__( 'GSC property "%s" not found. Check the site URL matches exactly what is registered in Google Search Console.', 'superdav-ai-agent' ),
						$site_url
					)
				);
			}

			return new WP_Error(
				'gsc_api_error',
				sprintf(
					/* translators: 1: HTTP status, 2: error message */
					__( 'GSC API error (%1$s): %2$s', 'superdav-ai-agent' ),
					// @phpstan-ignore-next-line
					$error_status,
					// @phpstan-ignore-next-line
					$error_msg
				)
			);
		}

		// @phpstan-ignore-next-line
		return $response_body['rows'] ?? [];
	}

	/**
	 * Resolve the GSC site URL from input or fall back to the WordPress site URL.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return string
	 */
	private static function resolve_site_url( array $input ): string {
		// @phpstan-ignore-next-line
		$site_url = sanitize_text_field( $input['site_url'] ?? '' );
		if ( ! empty( $site_url ) ) {
			return $site_url;
		}

		// Check stored default GSC site URL.
		$creds = Settings::instance()->get_gsc_credentials();
		if ( ! empty( $creds['default_site_url'] ) ) {
			// @phpstan-ignore-next-line
			return (string) $creds['default_site_url'];
		}

		return get_site_url();
	}

	/**
	 * Resolve start/end dates from input with sensible defaults.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array{0: string, 1: string} [start_date, end_date] in YYYY-MM-DD.
	 */
	private static function resolve_date_range( array $input ): array {
		// @phpstan-ignore-next-line
		$end = sanitize_text_field( $input['end_date'] ?? '' );
		// @phpstan-ignore-next-line
		$start = sanitize_text_field( $input['start_date'] ?? '' );

		if ( empty( $end ) ) {
			$end = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
		}

		if ( empty( $start ) ) {
			$start = gmdate( 'Y-m-d', strtotime( '-28 days' ) );
		}

		return [ $start, $end ];
	}

	/**
	 * Compute the previous equivalent period for comparison.
	 *
	 * @param string $start Start date YYYY-MM-DD.
	 * @param string $end   End date YYYY-MM-DD.
	 * @return array{0: string, 1: string} [prev_start, prev_end].
	 */
	private static function previous_period( string $start, string $end ): array {
		$start_ts = strtotime( $start );
		$end_ts   = strtotime( $end );
		$duration = $end_ts - $start_ts;

		$prev_end   = gmdate( 'Y-m-d', $start_ts - 86400 );
		$prev_start = gmdate( 'Y-m-d', $start_ts - $duration - 86400 );

		return [ $prev_start, $prev_end ];
	}

	/**
	 * Apply optional dimension filters to a Search Analytics request body.
	 *
	 * @param array<string, mixed> $body       Existing request body.
	 * @param array<string, mixed> $input      Ability input.
	 * @param string[]             $dimensions Dimension names to check in $input.
	 * @return array<string, mixed> Updated body.
	 */
	private static function apply_dimension_filters(
		array $body,
		array $input,
		array $dimensions
	): array {
		$filters = [];

		foreach ( $dimensions as $dim ) {
			// @phpstan-ignore-next-line
			$value = sanitize_text_field( $input[ $dim ] ?? '' );
			if ( empty( $value ) ) {
				continue;
			}

			// 'device' values must be uppercase.
			if ( 'device' === $dim ) {
				$value = strtoupper( $value );
				if ( ! in_array( $value, [ 'DESKTOP', 'MOBILE', 'TABLET' ], true ) ) {
					continue;
				}
			}

			$filters[] = [
				'dimension'  => $dim,
				'operator'   => 'equals',
				'expression' => $value,
			];
		}

		if ( ! empty( $filters ) ) {
			$body['dimensionFilterGroups'] = [
				[ 'filters' => $filters ],
			];
		}

		return $body;
	}
}
