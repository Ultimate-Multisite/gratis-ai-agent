<?php

declare(strict_types=1);
/**
 * Feedback report HTTP sender.
 *
 * Forwards the sanitized payload to the hardcoded feedback endpoint using
 * wp_remote_post(). Errors are returned as WP_Error objects; callers should
 * not crash on send failures (a broken feedback channel must never interrupt
 * normal plugin operation).
 *
 * The endpoint is fixed — no API key is required. User consent is collected
 * through the manual feedback modal or the completed-job failure prompt. The
 * prompt can also retain a user-scoped choice to report future failures.
 *
 * @package SdAiAgent\Feedback
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Feedback;

use WP_Error;

class ReportSender {

	/**
	 * Hardcoded feedback endpoint URL.
	 *
	 * Reports are always sent here. No configuration or API key is required.
	 */
	const ENDPOINT_URL = 'https://ultimateagentwp.ai/wp-json/gratis-ai-server/v1/reports';

	/**
	 * Send a sanitized report payload to the feedback endpoint.
	 *
	 * @param array<string, mixed> $payload Sanitized payload from ReportSanitizer::sanitize().
	 * @return true|WP_Error True on success (2xx response), WP_Error on failure.
	 */
	public static function send( array $payload ): true|WP_Error {
		$body = wp_json_encode( $payload );
		if ( false === $body ) {
			return new WP_Error( 'feedback_encode_error', 'Failed to JSON-encode the report payload.' );
		}

		$response = wp_remote_post(
			self::ENDPOINT_URL,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => $body,
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$body    = wp_remote_retrieve_body( $response );
			$message = sprintf(
				'Feedback endpoint returned HTTP %d: %s',
				$status_code,
				wp_strip_all_tags( $body )
			);
			return new WP_Error( 'feedback_http_error', $message, array( 'status' => $status_code ) );
		}

		return true;
	}
}
