<?php
/**
 * Test case for OptionsAbilities (secret-option read gating).
 *
 * Verifies the cross-surface contract that the AI agent can never read
 * the value of an authentication key or salt stored in the WordPress
 * options table, even though those names are write-blocked.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\DeleteOptionAbility;
use SdAiAgent\Abilities\GetOptionAbility;
use SdAiAgent\Abilities\ListOptionsAbility;
use SdAiAgent\Abilities\OptionsAbilities;
use SdAiAgent\Abilities\UpdateOptionAbility;
use WP_UnitTestCase;

/**
 * Test secret-option read gating end-to-end.
 */
class OptionsAbilitiesTest extends WP_UnitTestCase {

	/**
	 * Sample auth-key value used in fixtures; never asserted to be present
	 * in any agent-visible output.
	 *
	 * @var string
	 */
	private const FIXTURE_SECRET_VALUE = 'do-not-leak-this-secret-value';

	/**
	 * Clean up any options the tests inserted so they do not pollute the
	 * shared fixture database.
	 */
	public function tear_down(): void {
		foreach ( OptionsAbilities::get_secret_read_blocklist() as $name ) {
			delete_option( $name );
		}
		delete_option( 'sd_ai_agent_test_visible_option' );
		delete_option( 'sd_ai_agent_test_write_option' );
		delete_option( 'third_party_test_option' );
		remove_all_filters( 'sd_ai_agent_options_read_blocklist' );
		remove_all_filters( 'sd_ai_agent_options_blocklist' );
		remove_all_filters( 'sd_ai_agent_options_write_allowlist' );
		remove_all_filters( 'sd_ai_agent_options_write_allowlist_prefixes' );

		parent::tear_down();
	}

	/**
	 * The shipped read blocklist covers every auth key and salt.
	 */
	public function test_default_read_blocklist_covers_auth_keys_and_salts(): void {
		$blocklist = OptionsAbilities::get_secret_read_blocklist();

		$expected = array(
			'auth_key',
			'secure_auth_key',
			'logged_in_key',
			'nonce_key',
			'auth_salt',
			'secure_auth_salt',
			'logged_in_salt',
			'nonce_salt',
		);

		foreach ( $expected as $name ) {
			$this->assertContains(
				$name,
				$blocklist,
				sprintf( '"%s" must be in the secret read blocklist.', $name )
			);
			$this->assertTrue(
				OptionsAbilities::is_secret_option_name( $name ),
				sprintf( 'is_secret_option_name("%s") must return true.', $name )
			);
		}
	}

	/** Plugin integration credential options are never agent-readable. */
	public function test_default_read_blocklist_covers_integration_credentials(): void {
		$expected = [
			'sd_ai_agent_gsc_credentials',
			'sd_ai_agent_google_calendar_credentials',
			'sd_ai_agent_sms_provider',
			'sd_ai_agent_whatsapp_provider',
			'sd_ai_agent_telegram_provider',
		];

		foreach ( $expected as $name ) {
			$this->assertTrue( OptionsAbilities::is_secret_option_name( $name ) );
			$this->assertFalse( OptionsAbilities::is_write_allowed_option( $name ) );
		}
	}

	/**
	 * Non-secret options remain readable.
	 */
	public function test_predicate_returns_false_for_non_secret_names(): void {
		$this->assertFalse( OptionsAbilities::is_secret_option_name( 'blogname' ) );
		$this->assertFalse( OptionsAbilities::is_secret_option_name( 'siteurl' ) );
		$this->assertFalse( OptionsAbilities::is_secret_option_name( '' ) );
	}

	/**
	 * The read blocklist can be extended at runtime by site code.
	 */
	public function test_filter_can_extend_read_blocklist(): void {
		add_filter(
			'sd_ai_agent_options_read_blocklist',
			static function ( array $list ): array {
				$list[] = 'my_third_party_api_token';
				return $list;
			}
		);

		$this->assertTrue( OptionsAbilities::is_secret_option_name( 'my_third_party_api_token' ) );
		$this->assertContains( 'my_third_party_api_token', OptionsAbilities::get_secret_read_blocklist() );
	}

	/**
	 * The write policy is default-deny except for plugin-owned options and the
	 * narrow core presentation options the setup agent is expected to manage.
	 */
	public function test_write_allowlist_defaults_to_plugin_owned_options(): void {
		$this->assertTrue( OptionsAbilities::is_write_allowed_option( 'sd_ai_agent_test_write_option' ) );
		$this->assertTrue( OptionsAbilities::is_write_allowed_option( 'blogname' ) );
		$this->assertTrue( OptionsAbilities::is_write_allowed_option( 'blogdescription' ) );
		$this->assertTrue( OptionsAbilities::is_write_allowed_option( 'show_on_front' ) );
		$this->assertTrue( OptionsAbilities::is_write_allowed_option( 'page_on_front' ) );
		$this->assertTrue( OptionsAbilities::is_write_allowed_option( 'woocommerce_coming_soon' ) );
		$this->assertTrue( OptionsAbilities::is_write_allowed_option( 'page_for_posts' ) );
		$this->assertFalse( OptionsAbilities::is_write_allowed_option( 'third_party_test_option' ) );
		$this->assertFalse( OptionsAbilities::is_write_allowed_option( 'siteurl' ) );
		$this->assertFalse( OptionsAbilities::is_write_allowed_option( '' ) );
	}

	/**
	 * Exact and prefix write allowlists can be extended by trusted site code.
	 */
	public function test_write_allowlist_filters_can_extend_safe_names(): void {
		add_filter(
			'sd_ai_agent_options_write_allowlist',
			static function ( array $list ): array {
				$list[] = 'third_party_test_option';
				return $list;
			}
		);

		$this->assertTrue( OptionsAbilities::is_write_allowed_option( 'third_party_test_option' ) );

		add_filter(
			'sd_ai_agent_options_write_allowlist_prefixes',
			static function ( array $prefixes ): array {
				$prefixes[] = 'trusted_prefix_';
				return $prefixes;
			}
		);

		$this->assertTrue( OptionsAbilities::is_write_allowed_option( 'trusted_prefix_setting' ) );
	}

	/**
	 * Blocklisted options stay blocked even if a filter tries to allow them.
	 */
	public function test_write_blocklist_takes_precedence_over_allowlist(): void {
		add_filter(
			'sd_ai_agent_options_write_allowlist',
			static function ( array $list ): array {
				$list[] = 'siteurl';
				return $list;
			}
		);

		$this->assertFalse( OptionsAbilities::is_write_allowed_option( 'siteurl' ) );
	}

	/**
	 * UpdateOptionAbility rejects arbitrary unallowlisted option names.
	 */
	public function test_update_option_ability_blocks_unallowlisted_option(): void {
		$ability = new UpdateOptionAbility( 'sd-ai-agent/update-option' );
		$result  = $ability->run(
			array(
				'option_name'  => 'third_party_test_option',
				'option_value' => 'unsafe-write',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_option_not_allowed', $result->get_error_code() );
		$this->assertFalse( get_option( 'third_party_test_option', false ) );
	}

	/**
	 * UpdateOptionAbility still permits plugin-owned options.
	 */
	public function test_update_option_ability_allows_plugin_owned_option(): void {
		$ability = new UpdateOptionAbility( 'sd-ai-agent/update-option' );
		$result  = $ability->run(
			array(
				'option_name'  => 'sd_ai_agent_test_write_option',
				'option_value' => 'safe-write',
				'autoload'     => 'no',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'updated', $result['status'] );
		$this->assertSame( 'safe-write', get_option( 'sd_ai_agent_test_write_option' ) );
	}

	/**
	 * DeleteOptionAbility rejects arbitrary unallowlisted option names.
	 */
	public function test_delete_option_ability_blocks_unallowlisted_option(): void {
		update_option( 'third_party_test_option', 'must-remain' );

		$ability = new DeleteOptionAbility( 'sd-ai-agent/delete-option' );
		$result  = $ability->run( array( 'option_name' => 'third_party_test_option' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_option_not_allowed', $result->get_error_code() );
		$this->assertSame( 'must-remain', get_option( 'third_party_test_option' ) );
	}

	/**
	 * UpdateOptionAbility rejects blocklisted options before allowlist checks.
	 */
	public function test_update_option_ability_blocks_protected_option_even_when_allowlisted(): void {
		add_filter(
			'sd_ai_agent_options_write_allowlist',
			static function ( array $list ): array {
				$list[] = 'siteurl';
				return $list;
			}
		);

		$ability = new UpdateOptionAbility( 'sd-ai-agent/update-option' );
		$result  = $ability->run(
			array(
				'option_name'  => 'siteurl',
				'option_value' => 'https://example.invalid',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_option_blocked', $result->get_error_code() );
	}

	/**
	 * GetOptionAbility refuses to read auth_key even when it is stored as
	 * an option.
	 */
	public function test_get_option_ability_blocks_auth_key(): void {
		update_option( 'auth_key', self::FIXTURE_SECRET_VALUE );

		$ability = new GetOptionAbility( 'sd-ai-agent/get-option' );
		$result  = $ability->run( array( 'option_name' => 'auth_key' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_option_secret_redacted', $result->get_error_code() );
		$this->assertStringNotContainsString(
			self::FIXTURE_SECRET_VALUE,
			(string) $result->get_error_message(),
			'Error message must not echo the secret value.'
		);
	}

	/**
	 * Every shipped secret name is rejected by GetOptionAbility, not just
	 * the first one in the list.
	 */
	public function test_get_option_ability_blocks_every_shipped_secret(): void {
		$ability = new GetOptionAbility( 'sd-ai-agent/get-option' );

		foreach ( OptionsAbilities::get_secret_read_blocklist() as $name ) {
			update_option( $name, self::FIXTURE_SECRET_VALUE );
			$result = $ability->run( array( 'option_name' => $name ) );

			$this->assertInstanceOf( \WP_Error::class, $result, "Reading $name must return WP_Error" );
			$this->assertSame(
				'sd_ai_agent_option_secret_redacted',
				$result->get_error_code(),
				"Reading $name must return the secret-redacted error code"
			);
		}
	}

	/**
	 * Non-secret options continue to read normally.
	 */
	public function test_get_option_ability_still_reads_safe_options(): void {
		update_option( 'sd_ai_agent_test_visible_option', 'hello' );

		$ability = new GetOptionAbility( 'sd-ai-agent/get-option' );
		$result  = $ability->run( array( 'option_name' => 'sd_ai_agent_test_visible_option' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'hello', $result['value'] );
		$this->assertTrue( $result['exists'] );
	}

	/**
	 * Filter-added names are also blocked by GetOptionAbility.
	 */
	public function test_get_option_ability_honours_filter_added_secret(): void {
		add_filter(
			'sd_ai_agent_options_read_blocklist',
			static function ( array $list ): array {
				$list[] = 'sd_ai_agent_test_visible_option';
				return $list;
			}
		);
		update_option( 'sd_ai_agent_test_visible_option', 'hello' );

		$ability = new GetOptionAbility( 'sd-ai-agent/get-option' );
		$result  = $ability->run( array( 'option_name' => 'sd_ai_agent_test_visible_option' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sd_ai_agent_option_secret_redacted', $result->get_error_code() );
	}

	/**
	 * ListOptionsAbility omits secret rows and counts them so the agent
	 * knows redaction occurred without seeing the values.
	 */
	public function test_list_options_ability_omits_secret_rows(): void {
		update_option( 'auth_key', self::FIXTURE_SECRET_VALUE );
		update_option( 'nonce_salt', self::FIXTURE_SECRET_VALUE );

		$ability = new ListOptionsAbility( 'sd-ai-agent/list-options' );
		$result  = $ability->run( array( 'limit' => 200 ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'options', $result );
		$this->assertArrayHasKey( 'redacted_count', $result );
		$this->assertGreaterThanOrEqual( 2, $result['redacted_count'] );

		$encoded = wp_json_encode( $result );
		$this->assertIsString( $encoded );
		$this->assertStringNotContainsString(
			self::FIXTURE_SECRET_VALUE,
			$encoded,
			'Secret value must never appear in the list-options response.'
		);

		foreach ( $result['options'] as $row ) {
			$this->assertFalse(
				OptionsAbilities::is_secret_option_name( (string) $row['option_name'] ),
				sprintf( 'Secret option "%s" leaked into the response.', $row['option_name'] )
			);
		}
	}

	/**
	 * The placeholder constant is opaque enough to be greppable across the
	 * codebase, and stable enough for tests to assert on.
	 */
	public function test_secret_redacted_placeholder_is_stable(): void {
		$this->assertSame(
			'[redacted: secret option]',
			OptionsAbilities::SECRET_REDACTED_PLACEHOLDER
		);
	}
}
