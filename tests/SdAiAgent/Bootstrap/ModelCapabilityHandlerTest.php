<?php
/**
 * Tests for SdAiAgent\Bootstrap\ModelCapabilityHandler — sd-ai-2zf.
 *
 * Covers URL gating, payload ingestion (OpenAI-compatible + Ollama shapes),
 * and the read-only contract of the `http_response` filter.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Bootstrap;

use SdAiAgent\Bootstrap\ModelCapabilityHandler;
use SdAiAgent\Core\ModelCapabilityRegistry;
use WP_UnitTestCase;

/**
 * Capture-and-ingest behaviour for provider /models responses.
 */
class ModelCapabilityHandlerTest extends WP_UnitTestCase {

	private const TEST_MODELS = array(
		'hf:moonshotai/Kimi-K2.6',
		'hf:zai-org/GLM-5.1',
		'kimi-k2.6',
		'gpt-5',
		'should-not-be-cached',
	);

	/**
	 * Clear any registry entries written by individual test cases.
	 */
	public function tear_down(): void {
		parent::tear_down();
		foreach ( self::TEST_MODELS as $model_id ) {
			ModelCapabilityRegistry::forget( $model_id );
		}
	}

	/**
	 * Synthetic-shaped payload populates the registry with the advertised
	 * max_output_length and context_length for each model entry.
	 */
	public function test_ingest_models_payload_writes_entries(): void {
		$payload = array(
			'data' => array(
				array(
					'id'                => 'hf:moonshotai/Kimi-K2.6',
					'max_output_length' => 131072,
					'context_length'    => 200000,
				),
				array(
					'id'                => 'hf:zai-org/GLM-5.1',
					'max_output_length' => 65536,
					'context_length'    => 128000,
				),
			),
		);

		$written = ModelCapabilityHandler::ingest_models_payload( $payload );

		$this->assertSame( 2, $written );

		$kimi = ModelCapabilityRegistry::get( 'hf:moonshotai/Kimi-K2.6' );
		$this->assertSame( 131072, $kimi['max_output_tokens'] );
		$this->assertSame( 200000, $kimi['context_length'] );
		$this->assertSame( ModelCapabilityRegistry::SOURCE_PROVIDER, $kimi['source'] );

		$glm = ModelCapabilityRegistry::get( 'hf:zai-org/GLM-5.1' );
		$this->assertSame( 65536, $glm['max_output_tokens'] );
		$this->assertSame( 128000, $glm['context_length'] );
	}

	/**
	 * Ollama-style payload (`{models: [...]}`) is accepted as a fallback so
	 * the handler stays aligned with the connector's own parser.
	 */
	public function test_ingest_models_payload_accepts_ollama_envelope(): void {
		$payload = array(
			'models' => array(
				array(
					'id'                  => 'kimi-k2.6',
					'max_output_tokens'   => 65536,
					'context_window'      => 200000,
				),
			),
		);

		$this->assertSame( 1, ModelCapabilityHandler::ingest_models_payload( $payload ) );

		$entry = ModelCapabilityRegistry::get( 'kimi-k2.6' );
		$this->assertSame( 65536, $entry['max_output_tokens'] );
		$this->assertSame( 200000, $entry['context_length'] );
	}

	/**
	 * Entries without an id or without a positive max-output value are
	 * skipped — no zero-token rows poison the registry.
	 */
	public function test_ingest_models_payload_skips_invalid_entries(): void {
		$payload = array(
			'data' => array(
				array( 'id' => '', 'max_output_length' => 1024 ),
				array( 'id' => 'should-not-be-cached', 'max_output_length' => 0 ),
				array( 'max_output_length' => 1024 ), // missing id
				array( 'id' => 'gpt-5', 'max_output_length' => 128000 ),
			),
		);

		$this->assertSame( 1, ModelCapabilityHandler::ingest_models_payload( $payload ) );
		$this->assertSame( 128000, ModelCapabilityRegistry::get_max_output_tokens( 'gpt-5' ) );

		// Confirm the bad entry was not written.
		$entry = ModelCapabilityRegistry::get( 'should-not-be-cached' );
		$this->assertSame( ModelCapabilityRegistry::SOURCE_FALLBACK, $entry['source'] );
	}

	/**
	 * Empty / non-list payloads return zero writes and do not error.
	 */
	public function test_ingest_models_payload_handles_empty_payloads(): void {
		$this->assertSame( 0, ModelCapabilityHandler::ingest_models_payload( array() ) );
		$this->assertSame( 0, ModelCapabilityHandler::ingest_models_payload( array( 'data' => array() ) ) );
		$this->assertSame( 0, ModelCapabilityHandler::ingest_models_payload( array( 'data' => 'not-an-array' ) ) );
	}

	/**
	 * The URL gate only accepts known LLM provider hosts whose path ends
	 * with `/models`.
	 */
	public function test_is_models_endpoint_url_gate(): void {
		// Allowed.
		$this->assertTrue( ModelCapabilityHandler::is_models_endpoint( 'https://api.synthetic.new/openai/v1/models' ) );
		$this->assertTrue( ModelCapabilityHandler::is_models_endpoint( 'https://api.synthetic.new/openai/v1/models/' ) );
		$this->assertTrue( ModelCapabilityHandler::is_models_endpoint( 'https://api.openai.com/v1/models' ) );
		$this->assertTrue( ModelCapabilityHandler::is_models_endpoint( 'https://openrouter.ai/api/v1/models' ) );
		$this->assertTrue( ModelCapabilityHandler::is_models_endpoint( 'https://generativelanguage.googleapis.com/v1beta/models' ) );

		// Wrong host.
		$this->assertFalse( ModelCapabilityHandler::is_models_endpoint( 'https://example.com/v1/models' ) );

		// Right host, wrong path (no /models suffix).
		$this->assertFalse( ModelCapabilityHandler::is_models_endpoint( 'https://api.synthetic.new/openai/v1/chat/completions' ) );

		// Garbage.
		$this->assertFalse( ModelCapabilityHandler::is_models_endpoint( '' ) );
		$this->assertFalse( ModelCapabilityHandler::is_models_endpoint( 'not-a-url' ) );
	}

	/**
	 * capture_models_response() is read-only — it returns the input response
	 * verbatim whether or not it ingests the body, and even when the URL
	 * matches.
	 */
	public function test_capture_models_response_is_read_only(): void {
		$handler = new ModelCapabilityHandler();

		$body     = wp_json_encode(
			array(
				'data' => array(
					array(
						'id'                => 'hf:moonshotai/Kimi-K2.6',
						'max_output_length' => 131072,
						'context_length'    => 200000,
					),
				),
			)
		);
		$response = array(
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'body'     => $body,
			'headers'  => array(),
		);

		$returned = $handler->capture_models_response(
			$response,
			array(),
			'https://api.synthetic.new/openai/v1/models'
		);

		$this->assertSame( $response, $returned );

		// Side effect did fire.
		$this->assertSame(
			131072,
			ModelCapabilityRegistry::get_max_output_tokens( 'hf:moonshotai/Kimi-K2.6' )
		);
	}

	/**
	 * Non-2xx responses are ignored — a 500 from a provider must never
	 * overwrite a known-good cap.
	 */
	public function test_capture_models_response_ignores_error_status(): void {
		ModelCapabilityRegistry::set( 'hf:moonshotai/Kimi-K2.6', 131072, 200000 );

		$handler = new ModelCapabilityHandler();

		$response = array(
			'response' => array( 'code' => 500, 'message' => 'Server Error' ),
			'body'     => wp_json_encode(
				array(
					'data' => array(
						array(
							'id'                => 'hf:moonshotai/Kimi-K2.6',
							'max_output_length' => 0, // would be invalid anyway
						),
					),
				)
			),
		);

		$handler->capture_models_response(
			$response,
			array(),
			'https://api.synthetic.new/openai/v1/models'
		);

		// Original cached value is preserved.
		$this->assertSame(
			131072,
			ModelCapabilityRegistry::get_max_output_tokens( 'hf:moonshotai/Kimi-K2.6' )
		);
	}

	/**
	 * Responses for non-/models URLs (e.g. /chat/completions) are skipped
	 * even when they happen to carry a `data` array — the URL gate prevents
	 * other provider endpoints from leaking into the registry.
	 */
	public function test_capture_models_response_ignores_non_models_url(): void {
		$handler = new ModelCapabilityHandler();

		$response = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'data' => array(
						array(
							'id'                => 'should-not-be-cached',
							'max_output_length' => 999999,
						),
					),
				)
			),
		);

		$handler->capture_models_response(
			$response,
			array(),
			'https://api.synthetic.new/openai/v1/chat/completions'
		);

		$entry = ModelCapabilityRegistry::get( 'should-not-be-cached' );
		$this->assertSame( ModelCapabilityRegistry::SOURCE_FALLBACK, $entry['source'] );
	}

	/**
	 * WP_Error responses pass through untouched.
	 */
	public function test_capture_models_response_passes_wp_error(): void {
		$handler = new ModelCapabilityHandler();

		$wp_error = new \WP_Error( 'http_request_failed', 'cURL error 28' );

		$returned = $handler->capture_models_response(
			$wp_error,
			array(),
			'https://api.synthetic.new/openai/v1/models'
		);

		$this->assertSame( $wp_error, $returned );
	}
}
