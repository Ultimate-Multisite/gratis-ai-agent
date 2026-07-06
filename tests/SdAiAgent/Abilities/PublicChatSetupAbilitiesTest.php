<?php
/**
 * Tests for public chat setup abilities.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\PublicChatSetupAbilities;
use SdAiAgent\Core\Settings;
use SdAiAgent\Knowledge\KnowledgeDatabase;
use WP_UnitTestCase;

/**
 * @covers \SdAiAgent\Abilities\PublicChatSetupAbilities
 */
class PublicChatSetupAbilitiesTest extends WP_UnitTestCase {

	/** @var string */
	private string $collection_slug = '';

	/** Set up test fixtures. */
	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION_NAME );

		$this->collection_slug = 'public-docs-' . strtolower( wp_generate_password( 6, false, false ) );
		KnowledgeDatabase::create_collection(
			array(
				'name'          => 'Public Docs Test',
				'slug'          => $this->collection_slug,
				'description'   => 'Public docs setup test collection.',
				'auto_index'    => false,
				'source_config' => array(),
			)
		);
	}

	/** Reset options after each test. */
	public function tear_down(): void {
		delete_option( Settings::OPTION_NAME );
		parent::tear_down();
	}

	/** Status reports defaults without exposing non-public settings. */
	public function test_status_reports_incomplete_defaults(): void {
		$result = PublicChatSetupAbilities::handle_public_chat_setup(
			array(
				'action' => 'status',
			)
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['configured'] );
		$this->assertFalse( $result['settings']['public_chat_enabled'] );
		$this->assertSame( array( 'sd-ai-agent/knowledge-search' ), $result['settings']['public_chat_allowed_abilities'] );
		$this->assertArrayNotHasKey( 'gsc_credentials', $result['settings'] );
	}

	/** Configure validates that selected knowledge collections exist. */
	public function test_configure_rejects_missing_collection(): void {
		$result = PublicChatSetupAbilities::handle_public_chat_setup(
			array(
				'action'           => 'configure',
				'collection_slugs' => array( 'missing-docs' ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_public_chat_setup_missing_collection', $result->get_error_code() );
		$this->assertFalse( (bool) Settings::instance()->get( 'public_chat_enabled' ) );
	}

	/** Configure saves public chat settings and forces the safe tool allowlist. */
	public function test_configure_saves_safe_public_chat_settings(): void {
		$result = PublicChatSetupAbilities::handle_public_chat_setup(
			array(
				'action'             => 'configure',
				'origins'            => array( 'HTTPS://Docs.Example.com/path' ),
				'collection_slugs'   => array( $this->collection_slug ),
				'provider_id'        => 'sd-ai-agent-cloud',
				'model_id'           => 'superdav-chat-pro',
				'embed_id'           => 'docs',
				'rate_limit_per_min' => 99,
				'message_max_length' => 9000,
				'max_iterations'     => 20,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['configured'] );
		$this->assertSame( array( 'https://docs.example.com' ), $result['settings']['public_chat_allowed_origins'] );
		$this->assertSame( array( $this->collection_slug ), $result['settings']['public_chat_collection_ids'] );
		$this->assertSame( array( 'sd-ai-agent/knowledge-search' ), $result['settings']['public_chat_allowed_abilities'] );
		$this->assertSame( 60, $result['settings']['public_chat_rate_limit_per_min'] );
		$this->assertSame( 8000, $result['settings']['public_chat_message_max_length'] );
		$this->assertSame( 8, $result['settings']['public_chat_max_iterations'] );
		$this->assertStringContainsString( 'data-api-base=', $result['embed_snippet'] );
		$this->assertStringContainsString( 'data-collection="' . $this->collection_slug . '"', $result['embed_snippet'] );
	}

	/** Invalid origins are rejected before settings mutate. */
	public function test_configure_rejects_invalid_origin(): void {
		$result = PublicChatSetupAbilities::handle_public_chat_setup(
			array(
				'action'           => 'configure',
				'origins'          => array( 'javascript:alert(1)' ),
				'collection_slugs' => array( $this->collection_slug ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'sd_ai_agent_public_chat_setup_invalid_origin', $result->get_error_code() );
	}

	/** Disable turns public chat off but preserves other setup details. */
	public function test_disable_turns_public_chat_off(): void {
		PublicChatSetupAbilities::handle_public_chat_setup(
			array(
				'action'           => 'configure',
				'collection_slugs' => array( $this->collection_slug ),
				'provider_id'      => 'sd-ai-agent-cloud',
				'model_id'         => 'superdav-chat-pro',
			)
		);

		$result = PublicChatSetupAbilities::handle_public_chat_setup(
			array(
				'action' => 'disable',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'disabled', $result['status'] );
		$this->assertFalse( $result['settings']['public_chat_enabled'] );
		$this->assertSame( array( $this->collection_slug ), $result['settings']['public_chat_collection_ids'] );
	}
}
