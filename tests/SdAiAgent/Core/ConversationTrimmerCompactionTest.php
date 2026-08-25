<?php

declare(strict_types=1);
/**
 * Focused tests for deterministic conversation compaction.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\ConversationTrimmer;
use WP_UnitTestCase;

class ConversationTrimmerCompactionTest extends WP_UnitTestCase {

	/** Compacted setup reads retain bounded state without copying raw values. */
	public function test_compact_serialized_history_preserves_inspection_receipts(): void {
		$messages = [
			[
				'role'  => 'model',
				'parts' => [
					[
						'functionCall' => [
							'name' => 'wpab__sd-ai-agent__ability-search',
							'args' => [ 'query' => 'scaffold block theme file write' ],
						],
					],
				],
			],
			[
				'role'  => 'user',
				'parts' => [
					[
						'functionResponse' => [
							'name'     => 'wpab__sd-ai-agent__ability-search',
							'response' => [
								'query'   => 'scaffold block theme file write',
								'total'   => 2,
								'count'   => 2,
								'results' => [
									[ 'id' => 'sd-ai-agent/delete-post', 'label' => 'Delete Post', 'description' => 'SECRET_DESCRIPTION' ],
									[ 'id' => 'sd-ai-agent/list-media', 'label' => 'List Media', 'input_schema' => 'SECRET_SCHEMA' ],
								],
							],
						],
					],
				],
			],
			[
				'role'  => 'user',
				'parts' => [
					[
						'functionResponse' => [
							'name'     => 'wpab__sd-ai-agent__list-posts',
							'response' => [
								'total' => 2,
								'posts' => [
									[ 'id' => 2, 'title' => 'Sample Page', 'status' => 'publish', 'post_type' => 'page', 'permalink' => 'https://private.example/sample/' ],
									[ 'id' => 1, 'title' => 'Hello world!', 'status' => 'publish', 'post_type' => 'post', 'content' => 'SECRET_CONTENT' ],
								],
							],
						],
					],
				],
			],
			[
				'role'  => 'user',
				'parts' => [
					[
						'functionResponse' => [
							'name'     => 'wpab__sd-ai-agent__list-options',
							'response' => [
								'total'   => 1,
								'options' => [
									[ 'option_name' => 'provider_api_key', 'option_value' => 'SECRET_OPTION_VALUE' ],
								],
							],
						],
					],
				],
			],
		];

		$result = ConversationTrimmer::compact_serialized_history( $messages, 4096, 1024 );
		$json   = (string) wp_json_encode( $result['messages'] );

		$this->assertStringContainsString( 'Do not repeat an inspection solely', $json );
		$this->assertStringContainsString( 'scaffold block theme file write', $json );
		$this->assertStringContainsString( 'delete-post', $json );
		$this->assertStringContainsString( 'Sample Page', $json );
		$this->assertStringContainsString( 'provider_api_key', $json );
		$this->assertStringNotContainsString( 'SECRET_DESCRIPTION', $json );
		$this->assertStringNotContainsString( 'SECRET_SCHEMA', $json );
		$this->assertStringNotContainsString( 'SECRET_CONTENT', $json );
		$this->assertStringNotContainsString( 'SECRET_OPTION_VALUE', $json );
		$this->assertStringNotContainsString( 'private.example', $json );
	}
}
