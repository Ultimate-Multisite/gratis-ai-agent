<?php

declare(strict_types=1);
/**
 * REST API controller for the generic chat banner extension point.
 *
 * Exposes a single read-only endpoint:
 *
 *   GET /sd-ai-agent/v1/chat-banners
 *
 * which returns whatever banner objects other plugins have contributed
 * via the `sd_ai_agent_chat_banners` filter. The AI Agent itself never
 * adds banners; the endpoint exists purely so companion plugins (usage
 * caps, billing, compliance, onboarding nudges, etc.) have a single
 * place to surface UI without each having to ship its own React mount.
 *
 * Producers are expected to return an array of objects with this shape:
 *
 *   [
 *     'id'        => 'unique-banner-id',
 *     'severity'  => 'info' | 'warning' | 'error',
 *     'message'   => 'Plain text body — no HTML.',
 *     'cta_label' => 'Optional call-to-action label',
 *     'cta_url'   => 'https://example.test/...',
 *   ]
 *
 * Unknown keys are passed through; the React renderer ignores them.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use XWP\DI\Decorators\REST_Handler;
use XWP\DI\Decorators\REST_Route;
use XWP_REST_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generic chat-banner extension point.
 *
 * Companion plugins should hook into the `sd_ai_agent_chat_banners`
 * filter rather than mounting their own React components into the chat
 * panel. The contract is defined in this class' docblock above.
 */
#[REST_Handler(
	namespace: RestController::NAMESPACE,
	basename: 'chat-banners',
	container: 'sd-ai-agent',
)]
final class BannerController extends XWP_REST_Controller {

	use PermissionTrait;

	/**
	 * Handle GET /chat-banners — return banners contributed by other plugins.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	#[REST_Route(
		route: '',
		methods: WP_REST_Server::READABLE,
		guard: 'check_chat_permission',
	)]
	public function handle_get( WP_REST_Request $request ): WP_REST_Response {
		$context = array(
			'user_id' => get_current_user_id(),
			'site_id' => function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0,
		);

		/**
		 * Filter the list of banners to render at the top of the chat panel.
		 *
		 * Hook into this filter to surface a banner above the AI Agent chat
		 * UI without having to ship your own React mount. Each entry must
		 * have at minimum `severity` (`info`, `warning`, or `error`) and
		 * `message` (plain text — no HTML). `id`, `cta_label`, and `cta_url`
		 * are optional.
		 *
		 * @since 1.11.0
		 *
		 * @param array<int, array<string, mixed>> $banners Banners to render. Default empty.
		 * @param array<string, mixed>             $context Request context: user_id, site_id.
		 */
		$banners = apply_filters( 'sd_ai_agent_chat_banners', array(), $context );

		if ( ! is_array( $banners ) ) {
			$banners = array();
		}

		// Coerce each entry to a plain associative array so non-array
		// producers can't poison the response shape. The renderer drops
		// entries missing severity/message client-side.
		$normalised = array();
		foreach ( $banners as $banner ) {
			if ( is_array( $banner ) ) {
				$normalised[] = $banner;
			}
		}

		return new WP_REST_Response( array( 'banners' => $normalised ), 200 );
	}
}
