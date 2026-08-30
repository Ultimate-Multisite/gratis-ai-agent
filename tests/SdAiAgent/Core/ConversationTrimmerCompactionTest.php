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

	/** Compacted setup reads retain bounded state and callable ability schemas. */
	public function test_compact_serialized_history_preserves_inspection_receipts(): void {
		$schema_properties = [
			'post_id' => [
				'type'        => 'integer',
				'description' => 'SECRET_SCHEMA_DESCRIPTION',
			],
			'force'   => [
				'type'    => 'boolean',
				'default' => 'SECRET_SCHEMA_DEFAULT',
			],
		];
		for ( $index = 1; $index <= 10; ++$index ) {
			$schema_properties[ 'field_' . $index ] = [ 'type' => 'string' ];
		}

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
									[
										'id'           => 'sd-ai-agent/delete-post',
										'label'        => 'Delete Post',
										'description'  => 'SECRET_DESCRIPTION',
										'input_schema' => [
											'type'       => 'object',
											'required'   => array_keys( $schema_properties ),
											'properties' => $schema_properties,
										],
									],
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
		$text   = (string) $result['messages'][0]['parts'][0]['text'];

		$this->assertStringContainsString( 'Do not repeat an inspection solely', $json );
		$this->assertStringContainsString( 'Use ability-call directly', $json );
		$this->assertStringContainsString( 'scaffold block theme file write', $json );
		$this->assertStringContainsString( 'delete-post', $json );
		$this->assertStringContainsString( 'input_schema', $json );
		$this->assertStringContainsString( 'post_id', $json );
		$this->assertStringContainsString( 'integer', $json );
		$this->assertStringContainsString( 'force', $json );
		$this->assertStringContainsString( 'boolean', $json );
		$this->assertStringContainsString( '"required":["post_id","force","field_1","field_2","field_3","field_4","field_5","field_6","field_7","field_8","field_9","field_10"]', $text );
		$this->assertStringContainsString( 'Sample Page', $json );
		$this->assertStringContainsString( 'provider_api_key', $json );
		$this->assertStringNotContainsString( 'SECRET_DESCRIPTION', $json );
		$this->assertStringNotContainsString( 'SECRET_SCHEMA', $json );
		$this->assertStringNotContainsString( 'SECRET_SCHEMA_DESCRIPTION', $json );
		$this->assertStringNotContainsString( 'SECRET_SCHEMA_DEFAULT', $json );
		$this->assertStringNotContainsString( 'SECRET_CONTENT', $json );
		$this->assertStringNotContainsString( 'SECRET_OPTION_VALUE', $json );
		$this->assertStringNotContainsString( 'private.example', $json );
	}

	/** Re-compacting a deterministic seed preserves its structured receipts. */
	public function test_compact_serialized_history_expands_existing_compact_seed(): void {
		$messages = [];
		for ( $index = 0; $index < 6; ++$index ) {
			$messages[] = [
				'role'  => 'user',
				'parts' => [ [ 'text' => str_repeat( 'Earlier bounded setup context. ', 18 ) ] ],
			];
		}
		$messages[] = [
			'role'  => 'user',
			'parts' => [
				[
					'functionResponse' => [
						'name'     => 'wpab__sd-ai-agent__ability-search',
						'response' => [
							'query'   => 'select:sd-ai-agent/delete-post',
							'results' => [
								[
									'id'           => 'sd-ai-agent/delete-post',
									'label'        => 'Delete Post',
									'input_schema' => [
										'type'       => 'object',
										'properties' => [ 'post_id' => [ 'type' => 'integer' ] ],
										'required'   => [ 'post_id' ],
									],
								],
							],
						],
					],
				],
			],
		];

		$first = ConversationTrimmer::compact_serialized_history( $messages, 8192, 2048 );
		$second_input = array_merge(
			$first['messages'],
			[
				[
					'role'  => 'model',
					'parts' => [ [ 'text' => 'Continue from the retained callable schema.' ] ],
				],
			]
		);
		$second = ConversationTrimmer::compact_serialized_history( $second_input, 8192, 2048 );
		$text   = (string) $second['messages'][0]['parts'][0]['text'];

		$this->assertSame( 8, $second['meta']['source_message_count'] );
		$this->assertSame( 1, substr_count( $text, 'Conversation compacted server-side to avoid provider payload limits.' ) );
		$this->assertStringNotContainsString( 'User: Conversation compacted server-side', $text );
		$this->assertStringContainsString( 'callable_schemas=', $text );
		$this->assertStringContainsString( 'sd-ai-agent\/delete-post', $text );
		$this->assertStringContainsString( '"required":["post_id"]', $text );
		$this->assertStringContainsString( 'Continue from the retained callable schema.', $text );
	}
}
