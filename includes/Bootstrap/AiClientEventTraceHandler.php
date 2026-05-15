<?php
/**
 * DI handler for WP AI Client SDK event-based tracing.
 *
 * Listens on the WP AI Client SDK's PSR-14 event hooks dispatched by
 * WP_AI_Client_Event_Dispatcher to capture structured trace data:
 *
 * - wp_ai_client_before_generate_result — records request metadata
 * - wp_ai_client_after_generate_result — records response and computes duration
 *
 * This handler complements the HTTP-level trace capture in HttpTraceHandler
 * by capturing SDK-level events that may not produce HTTP traffic (e.g.,
 * SDK exceptions, timeouts, malformed responses).
 *
 * @package SdAiAgent\Bootstrap
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Bootstrap;

use SdAiAgent\Core\AiClientEventTraceLogger;
use SdAiAgent\Models\ProviderTrace;
use WordPress\AiClient\Events\AfterGenerateResultEvent;
use WordPress\AiClient\Events\BeforeGenerateResultEvent;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures WP AI Client SDK events and writes structured trace rows.
 *
 * CTX_GLOBAL ensures the action hooks are active in every request context —
 * AI calls can originate from admin (manual runs), REST (webhook triggers),
 * CLI, and cron (scheduled tasks).
 *
 * The trace callbacks are no-ops when WP_DEBUG is not active; tracing is a
 * debug-only feature and must never run on production.
 */
#[Handler(
	container: 'sd-ai-agent',
	context: Handler::CTX_GLOBAL,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class AiClientEventTraceHandler {

	/**
	 * Constructor — register the shutdown hook for watchdog cleanup.
	 */
	public function __construct() {
		// Register shutdown hook to clean up stalled Before events.
		add_action( 'shutdown', [ AiClientEventTraceLogger::class, 'cleanup_stalled_events' ], 10 );
	}

	/**
	 * Capture Before event and record request metadata.
	 *
	 * Stores the event timestamp and request details keyed by a stable
	 * correlation ID (spl_object_id) so the matching After event can
	 * compute duration and write a complete trace row.
	 *
	 * @param BeforeGenerateResultEvent $event The before-generate event.
	 * @return void
	 */
	#[Action( tag: 'wp_ai_client_before_generate_result', priority: 10 )]
	public function on_before_generate_result( BeforeGenerateResultEvent $event ): void {
		if ( ! ProviderTrace::is_debug_mode() ) {
			return;
		}

		AiClientEventTraceLogger::on_before_generate_result( $event );
	}

	/**
	 * Capture After event and write a complete trace row.
	 *
	 * Correlates with the Before event via spl_object_id, computes duration,
	 * and writes a structured trace row with capability, provider, model,
	 * finish reason, token usage, and result metadata.
	 *
	 * @param AfterGenerateResultEvent $event The after-generate event.
	 * @return void
	 */
	#[Action( tag: 'wp_ai_client_after_generate_result', priority: 10 )]
	public function on_after_generate_result( AfterGenerateResultEvent $event ): void {
		if ( ! ProviderTrace::is_debug_mode() ) {
			return;
		}

		AiClientEventTraceLogger::on_after_generate_result( $event );
	}
}
