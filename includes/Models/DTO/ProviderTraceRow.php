<?php

declare(strict_types=1);
/**
 * Typed DTO for a provider-trace row returned by wpdb::get_row() / wpdb::get_results().
 *
 * @package SdAiAgent\Models\DTO
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Models\DTO;

/**
 * Immutable DTO for the sd_ai_agent_provider_trace table row.
 */
readonly class ProviderTraceRow {

	/**
	 * @param int    $id                    Row ID (auto-increment PK).
	 * @param string $created_at            MySQL datetime string (UTC).
	 * @param string $provider_id           AI provider slug.
	 * @param string $model_id              Model slug.
	 * @param string $url                   Request URL.
	 * @param string $method                HTTP method (GET, POST, etc.).
	 * @param int    $status_code           HTTP response status code.
	 * @param int    $duration_ms           Round-trip duration in milliseconds.
	 * @param int    $cache_creation_tokens Prompt-cache write tokens reported by the
	 *                                      provider (Anthropic / DeepSeek). 0 when
	 *                                      not reported or not applicable.
	 * @param int    $cache_read_tokens     Prompt-cache read (hit) tokens reported by
	 *                                      the provider (Anthropic / OpenAI / DeepSeek
	 *                                      / Google / xAI / etc.). 0 on miss.
	 * @param string $request_headers       JSON-encoded request headers.
	 * @param string $request_body          Raw request body.
	 * @param string $response_headers      JSON-encoded response headers.
	 * @param string $response_body         Raw response body.
	 * @param string $error                 Error message, or empty string.
	 */
	public function __construct(
		public int $id,
		public string $created_at,
		public string $provider_id,
		public string $model_id,
		public string $url,
		public string $method,
		public int $status_code,
		public int $duration_ms,
		public int $cache_creation_tokens,
		public int $cache_read_tokens,
		public string $request_headers,
		public string $request_body,
		public string $response_headers,
		public string $response_body,
		public string $error,
	) {}

	/**
	 * Construct a ProviderTraceRow from the raw stdClass returned by wpdb::get_row() or get_results().
	 *
	 * @param object $row Raw row from wpdb.
	 * @return self
	 */
	public static function from_row( object $row ): self {
		return new self(
			id:                    (int) $row->id,
			created_at:            (string) ( $row->created_at ?? '' ),
			provider_id:           (string) ( $row->provider_id ?? '' ),
			model_id:              (string) ( $row->model_id ?? '' ),
			url:                   (string) ( $row->url ?? '' ),
			method:                (string) ( $row->method ?? 'POST' ),
			status_code:           (int) ( $row->status_code ?? 0 ),
			duration_ms:           (int) ( $row->duration_ms ?? 0 ),
			cache_creation_tokens: (int) ( $row->cache_creation_tokens ?? 0 ),
			cache_read_tokens:     (int) ( $row->cache_read_tokens ?? 0 ),
			request_headers:       (string) ( $row->request_headers ?? '{}' ),
			request_body:          (string) ( $row->request_body ?? '' ),
			response_headers:      (string) ( $row->response_headers ?? '{}' ),
			response_body:         (string) ( $row->response_body ?? '' ),
			error:                 (string) ( $row->error ?? '' ),
		);
	}
}
