<?php

declare(strict_types=1);

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\ConversationTrimmer;
use WP_UnitTestCase;

/** Tests serialized-history compaction without requiring the AI Client SDK. */
class ConversationTrimmerSerializedTest extends WP_UnitTestCase {

	/** Compaction retains reusable IDs from ability-call resource envelopes. */
	public function test_preserves_resource_receipts(): void {
		$messages = array(
			array(
				'role'  => 'model',
				'parts' => array(
					array(
						'functionCall' => array(
							'name' => 'wpab__sd-ai-agent__ability-call',
							'args' => array(
								'ability'   => 'sd-ai-agent/stock-image',
								'arguments' => array( 'keyword' => 'newlywed couple', 'usage' => 'hero' ),
							),
						),
					),
				),
			),
			array(
				'role'  => 'user',
				'parts' => array(
					array(
						'functionResponse' => array(
							'name'     => 'wpab__sd-ai-agent__ability-call',
							'response' => array(
								'ability' => 'sd-ai-agent/stock-image',
								'success' => true,
								'result'  => array(
									'attachment_id' => 49,
									'title'         => 'Wedding portrait',
									'source'        => 'openverse',
									'attribution'   => 'Licensed under CC BY 2.0',
									'url'           => 'https://private.example/uploads/image.jpg',
									'secret'        => 'SECRET_RESOURCE_VALUE',
								),
							),
						),
					),
				),
			),
		);

		$result = ConversationTrimmer::compact_serialized_history( $messages, 2048, 512 );
		$text   = (string) $result['messages'][0]['parts'][0]['text'];

		$this->assertStringContainsString( 'sd-ai-agent/stock-image', $text );
		$this->assertStringContainsString( 'newlywed couple', $text );
		$this->assertStringContainsString( '"attachment_id":49', $text );
		$this->assertStringContainsString( 'Licensed under CC BY 2.0', $text );
		$this->assertStringNotContainsString( 'SECRET_RESOURCE_VALUE', $text );
		$this->assertStringNotContainsString( 'private.example', $text );
	}
}
