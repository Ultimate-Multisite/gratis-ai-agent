<?php

declare(strict_types=1);

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\RunLocalReadonlyToolCache;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WP_UnitTestCase;

/** @group agent-loop */
class RunLocalReadonlyToolCacheTest extends WP_UnitTestCase {

	public function test_reuses_normalized_file_read_arguments_within_one_run(): void {
		if ( ! class_exists( ModelMessage::class ) ) {
			$this->markTestSkipped( 'WP AI Client message classes are not available.' );
		}

		$cache = new RunLocalReadonlyToolCache();
		$first = new ModelMessage(
			array(
				new MessagePart(
					new FunctionCall(
						'first',
						'wpab__sd-ai-agent__file-read',
						array( 'path' => 'theme/style.css', 'options' => array( 'b' => 2, 'a' => 1 ) )
					)
				),
			)
		);
		$cache->record( $first );

		$second = new ModelMessage(
			array(
				new MessagePart(
					new FunctionCall(
						'second',
						'wpab__sd-ai-agent__file-read',
						array( 'options' => array( 'a' => 1, 'b' => 2 ), 'path' => 'theme/style.css' )
					)
				),
			)
		);
		$plan = $cache->reuse( $second );

		$this->assertSame( 1, $plan['count'] );
		$this->assertNotNull( $plan['reused'] );
		$this->assertCount( 0, $plan['execute']->getParts() );
		$this->assertSame( 1, $cache->get_diagnostics()['reused_readonly_calls'] );
	}

	public function test_mutation_invalidates_cached_file_read(): void {
		if ( ! class_exists( ModelMessage::class ) ) {
			$this->markTestSkipped( 'WP AI Client message classes are not available.' );
		}

		$cache = new RunLocalReadonlyToolCache();
		$read  = new ModelMessage(
			array( new MessagePart( new FunctionCall( 'read', 'wpab__sd-ai-agent__file-read', array( 'path' => 'theme/style.css' ) ) ) )
		);
		$cache->record( $read );
		$write = new ModelMessage(
			array( new MessagePart( new FunctionCall( 'write', 'wpab__sd-ai-agent__file-write', array( 'path' => 'theme/style.css', 'content' => 'changed' ) ) ) )
		);
		$cache->reuse( $write );

		$plan = $cache->reuse( $read );
		$this->assertSame( 0, $plan['count'] );
		$this->assertCount( 1, $plan['execute']->getParts() );
	}

	public function test_reuses_overlapping_readonly_batch_when_one_call_changes(): void {
		if ( ! class_exists( ModelMessage::class ) ) {
			$this->markTestSkipped( 'WP AI Client message classes are not available.' );
		}

		$cache = new RunLocalReadonlyToolCache();
		$first = new ModelMessage(
			array(
				new MessagePart( new FunctionCall( 'first-a', 'wpab__sd-ai-agent__file-read', array( 'path' => 'theme/a.css' ) ) ),
				new MessagePart( new FunctionCall( 'first-b', 'wpab__sd-ai-agent__file-read', array( 'path' => 'theme/b.css' ) ) ),
			)
		);
		$cache->record( $first );

		$second = new ModelMessage(
			array(
				new MessagePart( new FunctionCall( 'second-a', 'wpab__sd-ai-agent__file-read', array( 'path' => 'theme/a.css' ) ) ),
				new MessagePart( new FunctionCall( 'second-c', 'wpab__sd-ai-agent__file-read', array( 'path' => 'theme/c.css' ) ) ),
			)
		);
		$plan = $cache->reuse( $second );

		$this->assertSame( 1, $plan['count'] );
		$this->assertCount( 1, $plan['execute']->getParts() );
	}

	public function test_does_not_cache_volatile_client_tool(): void {
		if ( ! class_exists( ModelMessage::class ) ) {
			$this->markTestSkipped( 'WP AI Client message classes are not available.' );
		}

		$cache      = new RunLocalReadonlyToolCache();
		$screenshot = new ModelMessage(
			array( new MessagePart( new FunctionCall( 'shot', 'wpab__sd-ai-agent-js__capture-screenshot', array( 'url' => '/' ) ) ) )
		);
		$cache->record( $screenshot );
		$plan = $cache->reuse( $screenshot );

		$this->assertSame( 0, $plan['count'] );
		$this->assertCount( 1, $plan['execute']->getParts() );
	}
}
