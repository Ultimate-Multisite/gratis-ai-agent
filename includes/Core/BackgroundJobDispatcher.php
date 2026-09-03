<?php

declare(strict_types=1);

namespace SdAiAgent\Core;

use SdAiAgent\REST\RestController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Dispatch long-running agent jobs outside the browser-facing PHP request. */
final class BackgroundJobDispatcher {

	public const HOOK   = 'sd_ai_agent_process_background_job';
	private const GROUP = 'sd-ai-agent';

	/**
	 * Queue a job on the main site's out-of-process worker.
	 */
	public static function dispatch( string $job_id, string $token ): void {
		$target_blog_id = get_current_blog_id();
		$main_blog_id   = get_main_site_id();
		$switched       = $target_blog_id !== $main_blog_id;
		if ( $switched ) {
			switch_to_blog( $main_blog_id );
		}

		try {
			$args      = array( $target_blog_id, $job_id );
			$scheduled = self::schedule_action( $args );
			if ( ! $scheduled ) {
				$scheduled = false !== wp_next_scheduled( self::HOOK, $args ) || wp_schedule_single_event( time(), self::HOOK, $args, true );
			}
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
		if ( true === $scheduled ) {
			return;
		}

		wp_remote_post(
			rest_url( RestController::NAMESPACE . '/process' ),
			array(
				'timeout'  => 5.0,
				'blocking' => false,
				'body'     => (string) wp_json_encode(
					array(
						'job_id' => $job_id,
						'token'  => $token,
					)
				),
				'headers'  => array( 'Content-Type' => 'application/json' ),
			)
		);
	}

	/**
	 * Prefer Action Scheduler because the queue worker gives it an independent
	 * execution lane that cannot be starved by an unrelated WP-Cron backlog.
	 *
	 * @param array{0:int,1:string} $args Callback arguments.
	 */
	private static function schedule_action( array $args ): bool {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return false;
		}

		if ( as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
			return true;
		}

		return as_enqueue_async_action( self::HOOK, $args, self::GROUP, false, 0 ) > 0;
	}
}
