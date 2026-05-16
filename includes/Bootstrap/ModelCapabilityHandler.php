<?php
/**
 * DI handler that captures `/models` HTTP responses and feeds the parsed
 * `max_output_length` / `context_length` fields into
 * {@see ModelCapabilityRegistry}.
 *
 * Background: OpenAI-compatible providers (Synthetic, OpenRouter, etc.)
 * advertise per-model `max_output_length` in their `/models` payloads.
 * The upstream `ai-provider-for-any-compatible-endpoint` connector
 * strips this field when building its `ModelMetadata` DTO
 * (see `buildModelMetadataMapFromRaw()` in that plugin's
 * `class-model-directory.php`), so by the time the value reaches the
 * SDK it is already gone. We intercept the raw HTTP response at
 * `http_response` priority 9 — one slot ahead of
 * {@see HttpTraceHandler} (priority 10) — so:
 *
 *   1. We see the body before the trace logger snapshots it.
 *   2. The connector's `wp_remote_request()` call returns the same
 *      payload we inspected; we never mutate it.
 *
 * This is the same trick {@see HttpTraceHandler} uses to observe the
 * outgoing prompt body; matching their pattern keeps the registration
 * footprint identical.
 *
 * @package SdAiAgent\Bootstrap
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Bootstrap;

use SdAiAgent\Core\ModelCapabilityRegistry;
use XWP\DI\Decorators\Filter;
use XWP\DI\Decorators\Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures `/models` responses from LLM providers and writes per-model
 * caps into the {@see ModelCapabilityRegistry} transient store.
 */
#[Handler(
	container: 'sd-ai-agent',
	context: Handler::CTX_GLOBAL,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class ModelCapabilityHandler {

	/**
	 * Allowed host suffixes for `/models` capture.
	 *
	 * Restricts ingestion to LLM provider hosts so unrelated WordPress
	 * HTTP traffic that happens to use a `/models` path (some REST APIs,
	 * geo/mapping services) cannot poison the registry.
	 *
	 * Match is case-insensitive substring on the parsed host. Add new
	 * providers here when a connector starts surfacing models data.
	 */
	private const ALLOWED_HOST_SUFFIXES = array(
		'api.synthetic.new',
		'api.openai.com',
		'api.anthropic.com',
		'openrouter.ai',
		'api.deepinfra.com',
		'api.together.xyz',
		'api.fireworks.ai',
		'api.groq.com',
		'api.mistral.ai',
		'generativelanguage.googleapis.com',
	);

	/**
	 * Capture `/models` responses and persist per-model caps.
	 *
	 * Runs at priority 9 — strictly before {@see HttpTraceHandler}
	 * (priority 10) so the trace log sees the same body we did. We do
	 * not mutate `$response` under any condition.
	 *
	 * The full WordPress HTTP-API signature is (response, args, url) per
	 * `WP_Http::request()`. We declare four positional parameters to
	 * accommodate other plugins that have wrapped the filter; XWP/DI
	 * forwards every argument WordPress passes through.
	 *
	 * @param mixed        $response Raw response from the HTTP transport.
	 *                               Typically `array{response: array, body: string, ...}`
	 *                               on success, `WP_Error` on transport failure.
	 * @param array<mixed> $args    Request args. Unused — kept for signature parity.
	 * @param string       $url      Endpoint URL.
	 * @return mixed Unchanged response — this filter is read-only.
	 */
	#[Filter( tag: 'http_response', priority: 9, args: 3 )]
	public function capture_models_response( $response, $args, $url ) {
		unset( $args ); // Reserved for future per-request gating.

		if ( ! is_array( $response ) || ! isset( $response['body'] ) ) {
			return $response;
		}

		if ( ! is_string( $url ) || '' === $url ) {
			return $response;
		}

		if ( ! self::is_models_endpoint( $url ) ) {
			return $response;
		}

		$status = isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
		if ( $status < 200 || $status >= 300 ) {
			return $response;
		}

		$body = $response['body'];
		if ( ! is_string( $body ) || '' === $body ) {
			return $response;
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return $response;
		}

		self::ingest_models_payload( $decoded );

		return $response;
	}

	/**
	 * Decide whether a URL points at an LLM provider `/models` endpoint.
	 *
	 * Allow list is gated on host suffix; the path must end with `/models`
	 * (with an optional trailing slash or query string). Public so tests
	 * and CLI commands can reuse the gate when validating manual seeds.
	 *
	 * @param string $url Full request URL.
	 * @return bool True when the URL is an allowed provider's models listing.
	 */
	public static function is_models_endpoint( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['path'] ) ) {
			return false;
		}

		$host = strtolower( (string) $parts['host'] );
		$path = (string) $parts['path'];

		$host_ok = false;
		foreach ( self::ALLOWED_HOST_SUFFIXES as $suffix ) {
			// Match exact host or host.suffix (dot-boundary) to prevent
			// api.synthetic.new.evil.com from matching api.synthetic.new.
			if ( $host === $suffix || str_ends_with( $host, '.' . $suffix ) ) {
				$host_ok = true;
				break;
			}
		}
		if ( ! $host_ok ) {
			return false;
		}

		// Accept `/models` and `/v1/models` etc., with optional trailing slash.
		$path = rtrim( $path, '/' );
		return str_ends_with( $path, '/models' );
	}

	/**
	 * Parse a decoded `/models` payload and write each entry to the registry.
	 *
	 * Accepts both the OpenAI-compatible `{data: [{id, max_output_length, context_length}]}`
	 * shape and the Ollama-style `{models: [...]}` fallback (matching the
	 * connector's own parser). Entries without an `id` are skipped.
	 *
	 * Public so the CLI manual-refresh command can reuse the same parser
	 * after issuing its own `wp_remote_get()`.
	 *
	 * @param array<mixed> $payload Decoded JSON body.
	 * @return int Number of model entries written.
	 */
	public static function ingest_models_payload( array $payload ): int {
		$entries = array();
		if ( isset( $payload['data'] ) && is_array( $payload['data'] ) ) {
			$entries = $payload['data'];
		} elseif ( isset( $payload['models'] ) && is_array( $payload['models'] ) ) {
			$entries = $payload['models'];
		}

		if ( empty( $entries ) ) {
			return 0;
		}

		$written = 0;
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$model_id = isset( $entry['id'] ) ? trim( (string) $entry['id'] ) : '';
			if ( '' === $model_id ) {
				continue;
			}

			// Provider field names vary slightly between hosts; check the
			// canonical OpenAI-compatible names first, then the Ollama
			// fallbacks the connector also accepts upstream.
			$max_output = self::pick_int(
				$entry,
				array( 'max_output_length', 'max_output_tokens', 'max_completion_tokens' )
			);
			$context    = self::pick_int(
				$entry,
				array( 'context_length', 'context_window', 'context_size' )
			);

			if ( $max_output <= 0 ) {
				// Without a max-output value we have nothing to cache — the
				// catalog/fallback path already handles unknown models.
				continue;
			}

			if ( ModelCapabilityRegistry::set( $model_id, $max_output, $context ) ) {
				++$written;
			}
		}

		return $written;
	}

	/**
	 * Pick the first positive integer found under any of the candidate keys.
	 *
	 * @param array<string, mixed> $entry Decoded model entry.
	 * @param array<int, string>   $keys  Candidate field names in priority order.
	 * @return int Resolved value, or 0 when no candidate has a positive int.
	 */
	private static function pick_int( array $entry, array $keys ): int {
		foreach ( $keys as $key ) {
			if ( ! isset( $entry[ $key ] ) ) {
				continue;
			}
			$value = (int) $entry[ $key ];
			if ( $value > 0 ) {
				return $value;
			}
		}
		return 0;
	}
}
