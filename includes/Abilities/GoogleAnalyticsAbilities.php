<?php

declare(strict_types=1);
/**
 * Google Analytics 4 traffic analysis abilities for the AI agent.
 *
 * Connects to the Google Analytics Data API v1 using a service account JSON
 * key stored in WordPress options. Provides three abilities:
 *
 *   - sd-ai-agent/ga-traffic-summary  — sessions, pageviews, bounce rate,
 *                                           avg session duration for a date range
 *   - sd-ai-agent/ga-top-pages        — top N pages by pageviews
 *   - sd-ai-agent/ga-realtime         — active users right now
 *
 * Authentication: Google service account JSON key (downloaded from Google Cloud
 * Console). The key is stored in a dedicated WordPress option and never exposed
 * through the general GET /settings endpoint.
 *
 * @package SdAiAgent
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Analytics abilities facade.
 *
 * Registers three GA4 Data API abilities and provides static proxy methods
 * for backwards-compatible test access.
 */
class GoogleAnalyticsAbilities {

	/**
	 * WordPress option name for GA credentials.
	 * Stored separately from general settings to avoid credential leakage.
	 */
	const CREDENTIALS_OPTION = 'sd_ai_agent_ga_credentials';

	// ─── Static proxy methods ────────────────────────────────────────────────

	/**
	 * Get traffic summary for a date range.
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function handle_traffic_summary( array $input = [] ) {
		$ability = new GaTrafficSummaryAbility(
			'sd-ai-agent/ga-traffic-summary',
			[
				'label'       => __( 'GA Traffic Summary', 'superdav-ai-agent' ),
				'description' => __( 'Fetch Google Analytics 4 traffic metrics (sessions, pageviews, bounce rate, avg session duration) for a date range.', 'superdav-ai-agent' ),
			]
		);
		// @phpstan-ignore-next-line
		return $ability->run( $input );
	}

	/**
	 * Get top pages by pageviews.
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function handle_top_pages( array $input = [] ) {
		$ability = new GaTopPagesAbility(
			'sd-ai-agent/ga-top-pages',
			[
				'label'       => __( 'GA Top Pages', 'superdav-ai-agent' ),
				'description' => __( 'Fetch the top pages by pageviews from Google Analytics 4 for a date range.', 'superdav-ai-agent' ),
			]
		);
		// @phpstan-ignore-next-line
		return $ability->run( $input );
	}

	/**
	 * Get realtime active users.
	 *
	 * @param array<string,mixed> $input Input args.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function handle_realtime( array $input = [] ) {
		$ability = new GaRealtimeAbility(
			'sd-ai-agent/ga-realtime',
			[
				'label'       => __( 'GA Realtime Users', 'superdav-ai-agent' ),
				'description' => __( 'Fetch the number of active users on the site right now from Google Analytics 4.', 'superdav-ai-agent' ),
			]
		);
		// @phpstan-ignore-next-line
		return $ability->run( $input );
	}

	/**
	 * Register Google Analytics abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/ga-traffic-summary',
			[
				'label'         => __( 'GA Traffic Summary', 'superdav-ai-agent' ),
				'description'   => __( 'Fetch Google Analytics 4 traffic metrics (sessions, pageviews, bounce rate, avg session duration) for a date range.', 'superdav-ai-agent' ),
				'ability_class' => GaTrafficSummaryAbility::class,
				'show_in_rest'  => true,
			]
		);

		wp_register_ability(
			'sd-ai-agent/ga-top-pages',
			[
				'label'         => __( 'GA Top Pages', 'superdav-ai-agent' ),
				'description'   => __( 'Fetch the top pages by pageviews from Google Analytics 4 for a date range.', 'superdav-ai-agent' ),
				'ability_class' => GaTopPagesAbility::class,
				'show_in_rest'  => true,
			]
		);

		wp_register_ability(
			'sd-ai-agent/ga-realtime',
			[
				'label'         => __( 'GA Realtime Users', 'superdav-ai-agent' ),
				'description'   => __( 'Fetch the number of active users on the site right now from Google Analytics 4.', 'superdav-ai-agent' ),
				'ability_class' => GaRealtimeAbility::class,
				'show_in_rest'  => true,
			]
		);
	}

	// ─── Credential helpers ──────────────────────────────────────────────────

	/**
	 * Get stored GA credentials.
	 *
	 * @return array{property_id: string, service_account_json: string}
	 */
	public static function get_credentials(): array {
		$stored = get_option( self::CREDENTIALS_OPTION, [] );
		return [
			// @phpstan-ignore-next-line
			'property_id'          => isset( $stored['property_id'] ) ? (string) $stored['property_id'] : '',
			// @phpstan-ignore-next-line
			'service_account_json' => isset( $stored['service_account_json'] ) ? (string) $stored['service_account_json'] : '',
		];
	}

	/**
	 * Persist GA credentials.
	 *
	 * @param string $property_id          GA4 property ID (e.g. "123456789").
	 * @param string $service_account_json Service account JSON key contents.
	 * @return bool True on success.
	 */
	public static function set_credentials( string $property_id, string $service_account_json ): bool {
		return (bool) update_option(
			self::CREDENTIALS_OPTION,
			[
				'property_id'          => $property_id,
				'service_account_json' => $service_account_json,
			]
		);
	}

	/**
	 * Clear stored GA credentials.
	 *
	 * @return bool True on success.
	 */
	public static function clear_credentials(): bool {
		return (bool) delete_option( self::CREDENTIALS_OPTION );
	}
}

// ─── Shared GA API client trait ───────────────────────────────────────────────

/**
 * Shared Google Analytics Data API v1 HTTP client.
 *
 * Handles JWT-based service account authentication and signed API requests
 * using only WordPress HTTP functions (no external SDK required).
 *
 * @since 1.0.0
 */
trait GaApiClient {

	/**
	 * Obtain a short-lived OAuth 2.0 access token from a service account JSON key.
	 *
	 * The token is cached in a transient for 55 minutes (tokens expire at 60 min).
	 *
	 * @param array<string,mixed> $sa Service account JSON decoded as array.
	 * @return string|WP_Error Access token string or WP_Error on failure.
	 */
	private function get_access_token( array $sa ) {
		// @phpstan-ignore-next-line
		$cache_key = 'sd_ga_token_' . substr( md5( $sa['client_email'] ?? '' ), 0, 8 );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		// Build JWT.
		$now    = time();
		$header = $this->base64url_encode(
			(string) wp_json_encode(
				[
					'alg' => 'RS256',
					'typ' => 'JWT',
				]
			)
		);
		$claim  = $this->base64url_encode(
			(string) wp_json_encode(
				[
					'iss'   => $sa['client_email'] ?? '',
					'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
					'aud'   => 'https://oauth2.googleapis.com/token',
					'exp'   => $now + 3600,
					'iat'   => $now,
				]
			)
		);

		$signing_input = $header . '.' . $claim;
		$private_key   = $sa['private_key'] ?? '';

		if ( empty( $private_key ) ) {
			return new WP_Error( 'ga_no_private_key', __( 'Service account JSON is missing private_key.', 'superdav-ai-agent' ) );
		}

		// @phpstan-ignore-next-line

		// @phpstan-ignore-next-line
		if ( ! function_exists( 'openssl_sign' ) ) {
			// @phpstan-ignore-next-line
			return new WP_Error( 'ga_no_openssl', __( 'OpenSSL extension is required for Google Analytics authentication.', 'superdav-ai-agent' ) );
			// @phpstan-ignore-next-line
		}

		// @phpstan-ignore-next-line

		// @phpstan-ignore-next-line
		$pkey = openssl_pkey_get_private( $private_key );
		if ( false === $pkey ) {
			return new WP_Error( 'ga_invalid_key', __( 'Could not load service account private key. Verify the JSON is correct.', 'superdav-ai-agent' ) );
		}

		$signature = '';
		$signed    = openssl_sign( $signing_input, $signature, $pkey, OPENSSL_ALGO_SHA256 );
		if ( ! $signed ) {
			return new WP_Error( 'ga_sign_failed', __( 'Failed to sign JWT for Google Analytics authentication.', 'superdav-ai-agent' ) );
		}

		$jwt = $signing_input . '.' . $this->base64url_encode( $signature );

		// Exchange JWT for access token.
		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			[
				'timeout' => 15,
				'body'    => [
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				],
			]
		);

		// @phpstan-ignore-next-line

		if ( is_wp_error( $response ) ) {
			// @phpstan-ignore-next-line
			// @phpstan-ignore-next-line
			return new WP_Error( 'ga_token_request_failed', $response->get_error_message() );
		}

		// @phpstan-ignore-next-line

		// @phpstan-ignore-next-line

		// @phpstan-ignore-next-line

		// @phpstan-ignore-next-line
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		// @phpstan-ignore-next-line
		// @phpstan-ignore-next-line
		// @phpstan-ignore-next-line
		if ( ! is_array( $body ) || empty( $body['access_token'] ) ) {
			// @phpstan-ignore-next-line
			// @phpstan-ignore-next-line
			$err     = $body['error_description'] ?? $body['error'] ?? 'Unknown error';
			$err_str = is_scalar( $err ) ? (string) $err : 'Unknown error';
			// translators: %s: OAuth error message returned by Google.
			$error_message = sprintf( __( 'Google OAuth error: %s', 'superdav-ai-agent' ), $err_str );
			return new WP_Error( 'ga_token_error', $error_message );
		}

		// @phpstan-ignore-next-line
		$token = (string) $body['access_token'];
		set_transient( $cache_key, $token, 55 * MINUTE_IN_SECONDS );
		return $token;
	}

	/**
	 * Make an authenticated POST request to the GA Data API v1.
	 *
	 * @param string              $endpoint Full API URL.
	 * @param array<string,mixed> $body     Request body.
	 * @param string              $token    Bearer token.
	 * @return array<string,mixed>|WP_Error Decoded response body or WP_Error.
	 */
	private function ga_api_post( string $endpoint, array $body, string $token ) {
		$response = wp_remote_post(
			$endpoint,
			[
				'timeout' => 20,
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				],
				'body'    => (string) wp_json_encode( $body ),
				// @phpstan-ignore-next-line
			]
		);

		// @phpstan-ignore-next-line

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ga_api_request_failed', $response->get_error_message() );
			// @phpstan-ignore-next-line
		}

		// @phpstan-ignore-next-line

		$code = wp_remote_retrieve_response_code( $response );
		// @phpstan-ignore-next-line
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		// @phpstan-ignore-next-line
		if ( ! is_array( $data ) ) {
			// @phpstan-ignore-next-line
			return new WP_Error( 'ga_api_invalid_response', __( 'Google Analytics API returned an invalid response.', 'superdav-ai-agent' ) );
			// @phpstan-ignore-next-line
			// @phpstan-ignore-next-line
		}

		// @phpstan-ignore-next-line
		// @phpstan-ignore-next-line
		if ( $code >= 400 ) {
			// @phpstan-ignore-next-line
			$msg      = $data['error']['message'] ?? __( 'Unknown API error.', 'superdav-ai-agent' );
			$code_int = is_numeric( $code ) ? (int) $code : 0;
			$msg_str  = is_scalar( $msg ) ? (string) $msg : 'Unknown API error.';
			// translators: %1$d: HTTP status code, %2$s: error message from Google Analytics API.
			$error_message = sprintf( __( 'Google Analytics API error (%1$d): %2$s', 'superdav-ai-agent' ), $code_int, $msg_str );
			return new WP_Error( 'ga_api_error', $error_message );
		}

		// @phpstan-ignore-next-line
		return $data;
	}

	/**
	 * Make an authenticated GET request to the GA Data API v1.
	 *
	 * @param string $endpoint Full API URL.
	 * @param string $token    Bearer token.
	 * @return array<string,mixed>|WP_Error Decoded response body or WP_Error.
	 */
	private function ga_api_get( string $endpoint, string $token ) {
		$response = wp_remote_get(
			// @phpstan-ignore-next-line
			$endpoint,
			[
				// @phpstan-ignore-next-line
				'timeout' => 20,
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					// @phpstan-ignore-next-line
					'Content-Type'  => 'application/json',
				],
			]
		);

		// @phpstan-ignore-next-line
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ga_api_request_failed', $response->get_error_message() );
			// @phpstan-ignore-next-line
		}

		// @phpstan-ignore-next-line
		$code = wp_remote_retrieve_response_code( $response );
		// @phpstan-ignore-next-line
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		// @phpstan-ignore-next-line

		if ( ! is_array( $data ) ) {
			// @phpstan-ignore-next-line
			return new WP_Error( 'ga_api_invalid_response', __( 'Google Analytics API returned an invalid response.', 'superdav-ai-agent' ) );
			// @phpstan-ignore-next-line
		}

		// @phpstan-ignore-next-line

		// @phpstan-ignore-next-line
		if ( $code >= 400 ) {
			// @phpstan-ignore-next-line
			// @phpstan-ignore-next-line
			$msg      = $data['error']['message'] ?? __( 'Unknown API error.', 'superdav-ai-agent' );
			$code_int = is_numeric( $code ) ? (int) $code : 0;
			$msg_str  = is_scalar( $msg ) ? (string) $msg : 'Unknown API error.';
			// translators: %1$d: HTTP status code, %2$s: error message from Google Analytics API.
			$error_message = sprintf( __( 'Google Analytics API error (%1$d): %2$s', 'superdav-ai-agent' ), $code_int, $msg_str );
			return new WP_Error( 'ga_api_error', $error_message );
		}

		// @phpstan-ignore-next-line
		return $data;
	}

	/**
	 * Load and validate GA credentials, returning token + property_id.
	 *
	 * @return array{token: string, property_id: string}|WP_Error
	 */
	private function load_credentials() {
		$creds = GoogleAnalyticsAbilities::get_credentials();

		if ( empty( $creds['property_id'] ) ) {
			return new WP_Error(
				'ga_no_property_id',
				__( 'Google Analytics property ID is not configured. Go to Settings > Superdav AI Agent Settings > Integrations to add your GA4 property ID and service account key.', 'superdav-ai-agent' )
			);
		}

		if ( empty( $creds['service_account_json'] ) ) {
			return new WP_Error(
				'ga_no_credentials',
				__( 'Google Analytics service account JSON is not configured. Go to Settings > Superdav AI Agent Settings > Integrations to add your service account key.', 'superdav-ai-agent' )
			);
		}

		$sa = json_decode( $creds['service_account_json'], true );
		if ( ! is_array( $sa ) || empty( $sa['client_email'] ) || empty( $sa['private_key'] ) ) {
			return new WP_Error(
				'ga_invalid_credentials',
				__( 'Google Analytics service account JSON is invalid. It must contain client_email and private_key fields.', 'superdav-ai-agent' )
			);
		}

		$token = $this->get_access_token( $sa );
		// @phpstan-ignore-next-line
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		return [
			'token'       => $token,
			'property_id' => $creds['property_id'],
		];
	}

	/**
	 * URL-safe base64 encode (no padding).
	 *
	 * @param string $data Raw bytes.
	 * @return string Base64url-encoded string.
	 */
	private function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for JWT signing per RFC 7515; not used for obfuscation.
	}

	/**
	 * Extract a metric value from a GA API row.
	 *
	 * @param array<string,mixed> $row         Row from GA API response.
	 * @param int                 $metric_index Zero-based index into metricValues.
	 * @return string Raw value string.
	 */
	private function extract_metric( array $row, int $metric_index ): string {
		// @phpstan-ignore-next-line
		return (string) ( $row['metricValues'][ $metric_index ]['value'] ?? '0' );
	}

	/**
	 * Extract a dimension value from a GA API row.
	 *
	 * @param array<string,mixed> $row            Row from GA API response.
	 * @param int                 $dimension_index Zero-based index into dimensionValues.
	// @phpstan-ignore-next-line
	 * @return string Raw value string.
	// @phpstan-ignore-next-line
	 */
	// @phpstan-ignore-next-line
	private function extract_dimension( array $row, int $dimension_index ): string {
		// @phpstan-ignore-next-line
		// @phpstan-ignore-next-line
		return (string) ( $row['dimensionValues'][ $dimension_index ]['value'] ?? '' );
	}
}

// ─── GA Traffic Summary Ability ───────────────────────────────────────────────

/**
 * Fetch GA4 traffic summary metrics for a date range.
 *
 * Returns sessions, pageviews, bounce rate, and average session duration.
 *
 * @since 1.0.0
 */
class GaTrafficSummaryAbility extends AbstractAbility {

	use GaApiClient;

	protected function label(): string {
		return __( 'GA Traffic Summary', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Fetch Google Analytics 4 traffic metrics (sessions, pageviews, bounce rate, avg session duration) for a date range.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'start_date' => [
					'type'        => 'string',
					'description' => 'Start date in YYYY-MM-DD format, or a relative value like "7daysAgo", "30daysAgo", "yesterday". Defaults to "30daysAgo".',
				],
				'end_date'   => [
					'type'        => 'string',
					'description' => 'End date in YYYY-MM-DD format, or "today" or "yesterday". Defaults to "today".',
				],
			],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'property_id'            => [ 'type' => 'string' ],
				'start_date'             => [ 'type' => 'string' ],
				'end_date'               => [ 'type' => 'string' ],
				'sessions'               => [ 'type' => 'integer' ],
				'pageviews'              => [ 'type' => 'integer' ],
				'bounce_rate'            => [
					'type'        => 'number',
					'description' => 'Bounce rate as a percentage (0-100).',
				],
				'avg_session_duration_s' => [
					'type'        => 'number',
					'description' => 'Average session duration in seconds.',
				],
				'new_users'              => [ 'type' => 'integer' ],
				'total_users'            => [ 'type' => 'integer' ],
			],
		];
	}

	protected function execute_callback( $input ) {
		/** @var array<string, mixed> $input */
		// @phpstan-ignore-next-line
		$start_date = isset( $input['start_date'] ) ? (string) $input['start_date'] : '30daysAgo';
		// @phpstan-ignore-next-line
		$end_date = isset( $input['end_date'] ) ? (string) $input['end_date'] : 'today';

		$creds = $this->load_credentials();
		if ( is_wp_error( $creds ) ) {
			return $creds;
		}

		$property_id = $creds['property_id'];
		$token       = $creds['token'];

		$endpoint = sprintf(
			'https://analyticsdata.googleapis.com/v1beta/properties/%s:runReport',
			rawurlencode( $property_id )
		);

		$body = [
			'dateRanges' => [
				[
					'startDate' => $start_date,
					'endDate'   => $end_date,
				],
			],
			'metrics'    => [
				[ 'name' => 'sessions' ],
				[ 'name' => 'screenPageViews' ],
				[ 'name' => 'bounceRate' ],
				[ 'name' => 'averageSessionDuration' ],
				[ 'name' => 'newUsers' ],
				[ 'name' => 'totalUsers' ],
			],
		];

		$data = $this->ga_api_post( $endpoint, $body, $token );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// @phpstan-ignore-next-line
		$row = $data['rows'][0] ?? null;
		if ( null === $row ) {
			return [
				'property_id'            => $property_id,
				'start_date'             => $start_date,
				'end_date'               => $end_date,
				'sessions'               => 0,
				'pageviews'              => 0,
				'bounce_rate'            => 0.0,
				'avg_session_duration_s' => 0.0,
				'new_users'              => 0,
				'total_users'            => 0,
				'note'                   => __( 'No data found for the specified date range.', 'superdav-ai-agent' ),
			];
		}

		// @phpstan-ignore-next-line
		$bounce_rate = (float) $this->extract_metric( $row, 2 );

		return [
			'property_id'            => $property_id,
			'start_date'             => $start_date,
			'end_date'               => $end_date,
			// @phpstan-ignore-next-line
			'sessions'               => (int) $this->extract_metric( $row, 0 ),
			// @phpstan-ignore-next-line
			'pageviews'              => (int) $this->extract_metric( $row, 1 ),
			'bounce_rate'            => round( $bounce_rate * 100, 2 ),
			// @phpstan-ignore-next-line
			'avg_session_duration_s' => round( (float) $this->extract_metric( $row, 3 ), 2 ),
			// @phpstan-ignore-next-line
			'new_users'              => (int) $this->extract_metric( $row, 4 ),
			// @phpstan-ignore-next-line
			'total_users'            => (int) $this->extract_metric( $row, 5 ),
		];
	}

	protected function permission_callback( $input ): bool {
		return ToolCapabilities::current_user_can( $this->name );
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

// ─── GA Top Pages Ability ─────────────────────────────────────────────────────

/**
 * Fetch top pages by pageviews from GA4.
 *
 * @since 1.0.0
 */
class GaTopPagesAbility extends AbstractAbility {

	use GaApiClient;

	protected function label(): string {
		return __( 'GA Top Pages', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Fetch the top pages by pageviews from Google Analytics 4 for a date range.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'start_date' => [
					'type'        => 'string',
					'description' => 'Start date in YYYY-MM-DD format, or a relative value like "7daysAgo", "30daysAgo", "yesterday". Defaults to "30daysAgo".',
				],
				'end_date'   => [
					'type'        => 'string',
					'description' => 'End date in YYYY-MM-DD format, or "today" or "yesterday". Defaults to "today".',
				],
				'limit'      => [
					'type'        => 'integer',
					'description' => 'Number of top pages to return. Defaults to 10, max 50.',
					'minimum'     => 1,
					'maximum'     => 50,
				],
			],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'property_id' => [ 'type' => 'string' ],
				'start_date'  => [ 'type' => 'string' ],
				'end_date'    => [ 'type' => 'string' ],
				'pages'       => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'page_path'   => [ 'type' => 'string' ],
							'page_title'  => [ 'type' => 'string' ],
							'pageviews'   => [ 'type' => 'integer' ],
							'sessions'    => [ 'type' => 'integer' ],
							'bounce_rate' => [ 'type' => 'number' ],
						],
					],
				],
			],
		];
	}

	protected function execute_callback( $input ) {
		/** @var array<string, mixed> $input */
		// @phpstan-ignore-next-line
		$start_date = isset( $input['start_date'] ) ? (string) $input['start_date'] : '30daysAgo';
		// @phpstan-ignore-next-line
		$end_date = isset( $input['end_date'] ) ? (string) $input['end_date'] : 'today';
		// @phpstan-ignore-next-line
		$limit = isset( $input['limit'] ) ? min( 50, max( 1, (int) $input['limit'] ) ) : 10;

		$creds = $this->load_credentials();
		if ( is_wp_error( $creds ) ) {
			return $creds;
		}

		$property_id = $creds['property_id'];
		$token       = $creds['token'];

		$endpoint = sprintf(
			'https://analyticsdata.googleapis.com/v1beta/properties/%s:runReport',
			rawurlencode( $property_id )
		);

		$body = [
			'dateRanges' => [
				[
					'startDate' => $start_date,
					'endDate'   => $end_date,
				],
			],
			'dimensions' => [
				[ 'name' => 'pagePath' ],
				[ 'name' => 'pageTitle' ],
			],
			'metrics'    => [
				[ 'name' => 'screenPageViews' ],
				[ 'name' => 'sessions' ],
				[ 'name' => 'bounceRate' ],
			],
			'orderBys'   => [
				[
					'metric' => [ 'metricName' => 'screenPageViews' ],
					'desc'   => true,
				],
			],
			'limit'      => $limit,
		];

		$data = $this->ga_api_post( $endpoint, $body, $token );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$pages = [];
		// @phpstan-ignore-next-line
		foreach ( $data['rows'] ?? [] as $row ) {
			// @phpstan-ignore-next-line
			$bounce_rate = (float) $this->extract_metric( $row, 2 );
			$pages[]     = [
				// @phpstan-ignore-next-line
				'page_path'   => $this->extract_dimension( $row, 0 ),
				// @phpstan-ignore-next-line
				'page_title'  => $this->extract_dimension( $row, 1 ),
				// @phpstan-ignore-next-line
				'pageviews'   => (int) $this->extract_metric( $row, 0 ),
				// @phpstan-ignore-next-line
				'sessions'    => (int) $this->extract_metric( $row, 1 ),
				'bounce_rate' => round( $bounce_rate * 100, 2 ),
			];
		}

		return [
			'property_id' => $property_id,
			'start_date'  => $start_date,
			'end_date'    => $end_date,
			'pages'       => $pages,
		];
	}

	protected function permission_callback( $input ): bool {
		return ToolCapabilities::current_user_can( $this->name );
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

// ─── GA Realtime Ability ──────────────────────────────────────────────────────

/**
 * Fetch realtime active users from GA4.
 *
 * @since 1.0.0
 */
class GaRealtimeAbility extends AbstractAbility {

	use GaApiClient;

	protected function label(): string {
		return __( 'GA Realtime Users', 'superdav-ai-agent' );
	}

	protected function description(): string {
		return __( 'Fetch the number of active users on the site right now from Google Analytics 4.', 'superdav-ai-agent' );
	}

	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'minutes_ago' => [
					'type'        => 'integer',
					'description' => 'Look back window in minutes (1-60). Defaults to 30.',
					'minimum'     => 1,
					'maximum'     => 60,
				],
			],
		];
	}

	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'property_id'  => [ 'type' => 'string' ],
				'active_users' => [
					'type'        => 'integer',
					'description' => 'Number of active users in the last N minutes.',
				],
				'minutes_ago'  => [ 'type' => 'integer' ],
				'top_pages'    => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'page_path'    => [ 'type' => 'string' ],
							'active_users' => [ 'type' => 'integer' ],
						],
					],
				],
			],
		];
	}

	protected function execute_callback( $input ) {
		/** @var array<string, mixed> $input */
		// @phpstan-ignore-next-line
		$minutes_ago = isset( $input['minutes_ago'] ) ? min( 60, max( 1, (int) $input['minutes_ago'] ) ) : 30;

		$creds = $this->load_credentials();
		if ( is_wp_error( $creds ) ) {
			return $creds;
		}

		$property_id = $creds['property_id'];
		$token       = $creds['token'];

		$endpoint = sprintf(
			'https://analyticsdata.googleapis.com/v1beta/properties/%s:runRealtimeReport',
			rawurlencode( $property_id )
		);

		$body = [
			'dimensions'   => [
				[ 'name' => 'pagePath' ],
			],
			'metrics'      => [
				[ 'name' => 'activeUsers' ],
			],
			'minuteRanges' => [
				[
					'name'            => 'last_n_minutes',
					'startMinutesAgo' => $minutes_ago,
					'endMinutesAgo'   => 0,
				],
			],
			'orderBys'     => [
				[
					'metric' => [ 'metricName' => 'activeUsers' ],
					'desc'   => true,
				],
			],
			'limit'        => 10,
		];

		$data = $this->ga_api_post( $endpoint, $body, $token );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// Sum active users across all pages.
		$total_active = 0;
		$top_pages    = [];
		// @phpstan-ignore-next-line
		foreach ( $data['rows'] ?? [] as $row ) {
			// @phpstan-ignore-next-line
			$users         = (int) $this->extract_metric( $row, 0 );
			$total_active += $users;
			$top_pages[]   = [
				// @phpstan-ignore-next-line
				'page_path'    => $this->extract_dimension( $row, 0 ),
				'active_users' => $users,
			];
		}

		// Fallback: use totals from response if rows are empty.
		if ( 0 === $total_active && ! empty( $data['totals'] ) ) {
			// @phpstan-ignore-next-line
			$total_active = (int) ( $data['totals'][0]['metricValues'][0]['value'] ?? 0 );
		}

		return [
			'property_id'  => $property_id,
			'active_users' => $total_active,
			'minutes_ago'  => $minutes_ago,
			'top_pages'    => $top_pages,
		];
	}

	protected function permission_callback( $input ): bool {
		return ToolCapabilities::current_user_can( $this->name );
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
