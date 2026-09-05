<?php

declare(strict_types=1);

namespace SdAiAgent\Bootstrap;

use SdAiAgent\Core\PublicChatSecurity;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use XWP\DI\Decorators\Filter;
use XWP\DI\Decorators\Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Adds allowlisted CORS headers to public speech REST responses. */
#[Handler(
	container: 'sd-ai-agent',
	context: Handler::CTX_REST,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class PublicSpeechCorsHandler {

	public function __construct( private PublicChatSecurity $security ) {}

	/** Add CORS headers after WordPress converts public speech errors to responses. */
	#[Filter( tag: 'rest_post_dispatch', priority: 10, args: 3 )]
	public function add_cors_to_speech_response( WP_REST_Response $response, WP_REST_Server $server, WP_REST_Request $request ): WP_REST_Response {
		if ( ! in_array( $request->get_route(), array( '/sd-ai-agent/v1/public-chat/speech/transcriptions', '/sd-ai-agent/v1/public-chat/speech/synthesis' ), true ) ) {
			return $response;
		}

		$config = $this->security->settings();

		return $this->security->add_cors( $response, $this->security->request_origin( $request ), $config['origins'] );
	}
}
