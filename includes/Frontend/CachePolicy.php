<?php
/**
 * Frontend cache policy for live-preview reflector page fetches.
 *
 * @package SdAiAgent\Frontend
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Frontend;

use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends no-store headers for reflector-initiated public page refreshes.
 */
#[Handler(
	container: 'sd-ai-agent',
	context: Handler::CTX_FRONTEND,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class CachePolicy {

	/**
	 * Request header used by the frontend reflector to identify fresh-page fetches.
	 */
	public const REFLECTOR_REQUEST_HEADER = 'HTTP_X_SD_AI_AGENT_REFLECTOR';

	/**
	 * Response header exposed for smoke tests and cache-layer diagnostics.
	 */
	public const RESPONSE_HEADER = 'X-Sd-Ai-Agent-Cache-Policy';

	/**
	 * Cache-Control value for reflector HTML refreshes.
	 */
	private const CACHE_CONTROL = 'no-store, no-cache, must-revalidate';

	/**
	 * Apply cache-bypass headers before page-cache plugins usually act.
	 */
	#[Action( tag: 'template_redirect', priority: 1 )]
	public function send_reflector_headers(): void {
		if ( ! self::is_reflector_request() || headers_sent() ) {
			return;
		}

		foreach ( self::headers() as $name => $value ) {
			header( $name . ': ' . $value, true );
		}
	}

	/**
	 * Detect a frontend reflector fresh-page request.
	 */
	public static function is_reflector_request(): bool {
		$value = isset( $_SERVER[ self::REFLECTOR_REQUEST_HEADER ] )
			? sanitize_text_field( wp_unslash( $_SERVER[ self::REFLECTOR_REQUEST_HEADER ] ) )
			: '';

		return '' !== trim( $value );
	}

	/**
	 * Headers sent for reflector fresh-page requests.
	 *
	 * @return array<string, string>
	 */
	public static function headers(): array {
		return [
			'Cache-Control'       => self::CACHE_CONTROL,
			'Pragma'              => 'no-cache',
			'Expires'             => '0',
			self::RESPONSE_HEADER => 'no-store',
		];
	}
}
