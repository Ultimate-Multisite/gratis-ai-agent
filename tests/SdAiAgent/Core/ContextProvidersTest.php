<?php

declare(strict_types=1);
/**
 * Test case for ContextProviders class.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\ContextProviders;
use WP_UnitTestCase;

/**
 * Test ContextProviders functionality.
 */
class ContextProvidersTest extends WP_UnitTestCase {

	/**
	 * Reset static state before each test.
	 *
	 * ContextProviders uses static $providers and $initialized. We reset them
	 * via reflection so each test starts with a clean registry.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->reset_static_state();
	}

	/**
	 * Reset static state after each test.
	 */
	public function tear_down(): void {
		$this->reset_static_state();
		remove_all_filters( 'locale' );
		parent::tear_down();
	}

	/**
	 * Reset ContextProviders static properties via reflection.
	 */
	private function reset_static_state(): void {
		$ref = new \ReflectionClass( ContextProviders::class );

		$providers = $ref->getProperty( 'providers' );
		$providers->setAccessible( true );
		$providers->setValue( null, [] );

		$initialized = $ref->getProperty( 'initialized' );
		$initialized->setAccessible( true );
		$initialized->setValue( null, false );
	}

	// ── register ─────────────────────────────────────────────────────────────

	/**
	 * register() adds a provider to the registry.
	 */
	public function test_register_adds_provider(): void {
		ContextProviders::register( 'test_provider', fn( $ctx ) => [ 'key' => 'value' ], 5 );

		$ref       = new \ReflectionClass( ContextProviders::class );
		$providers = $ref->getProperty( 'providers' );
		$providers->setAccessible( true );
		$all = $providers->getValue( null );

		$this->assertArrayHasKey( 'test_provider', $all );
		$this->assertSame( 5, $all['test_provider']['priority'] );
	}

	/**
	 * register() overwrites an existing provider with the same name.
	 */
	public function test_register_overwrites_existing_provider(): void {
		ContextProviders::register( 'my_provider', fn( $ctx ) => [ 'v' => '1' ], 10 );
		ContextProviders::register( 'my_provider', fn( $ctx ) => [ 'v' => '2' ], 20 );

		$ref       = new \ReflectionClass( ContextProviders::class );
		$providers = $ref->getProperty( 'providers' );
		$providers->setAccessible( true );
		$all = $providers->getValue( null );

		$this->assertSame( 20, $all['my_provider']['priority'] );
	}

	// ── gather ────────────────────────────────────────────────────────────────

	/**
	 * gather() returns an empty array when no providers return data.
	 */
	public function test_gather_returns_empty_when_no_data(): void {
		// Register a provider that returns empty.
		ContextProviders::register( 'empty_provider', fn( $ctx ) => [] );

		// Mark as initialized to skip built-in providers.
		$ref         = new \ReflectionClass( ContextProviders::class );
		$initialized = $ref->getProperty( 'initialized' );
		$initialized->setAccessible( true );
		$initialized->setValue( null, true );

		$result = ContextProviders::gather();
		$this->assertArrayNotHasKey( 'empty_provider', $result );
	}

	/**
	 * gather() includes data from a registered provider.
	 */
	public function test_gather_includes_provider_data(): void {
		ContextProviders::register( 'custom', fn( $ctx ) => [ 'foo' => 'bar' ] );

		// Mark as initialized to skip built-in providers.
		$ref         = new \ReflectionClass( ContextProviders::class );
		$initialized = $ref->getProperty( 'initialized' );
		$initialized->setAccessible( true );
		$initialized->setValue( null, true );

		$result = ContextProviders::gather();
		$this->assertArrayHasKey( 'custom', $result );
		$this->assertSame( 'bar', $result['custom']['foo'] );
	}

	/**
	 * gather() passes page_context to providers.
	 */
	public function test_gather_passes_page_context_to_providers(): void {
		$received = null;
		ContextProviders::register(
			'ctx_receiver',
			function ( $ctx ) use ( &$received ) {
				$received = $ctx;
				return [ 'got' => 'it' ];
			}
		);

		$ref         = new \ReflectionClass( ContextProviders::class );
		$initialized = $ref->getProperty( 'initialized' );
		$initialized->setAccessible( true );
		$initialized->setValue( null, true );

		ContextProviders::gather( [ 'url' => 'https://example.com' ] );

		$this->assertIsArray( $received );
		$this->assertSame( 'https://example.com', $received['url'] );
	}

	/**
	 * gather() sorts providers by priority (lower runs first).
	 */
	public function test_gather_sorts_by_priority(): void {
		$order = [];

		ContextProviders::register( 'high_priority', function ( $ctx ) use ( &$order ) {
			$order[] = 'high';
			return [ 'x' => '1' ];
		}, 5 );

		ContextProviders::register( 'low_priority', function ( $ctx ) use ( &$order ) {
			$order[] = 'low';
			return [ 'y' => '2' ];
		}, 20 );

		$ref         = new \ReflectionClass( ContextProviders::class );
		$initialized = $ref->getProperty( 'initialized' );
		$initialized->setAccessible( true );
		$initialized->setValue( null, true );

		ContextProviders::gather();

		$this->assertSame( [ 'high', 'low' ], $order );
	}

	/**
	 * gather() silently skips providers that throw exceptions.
	 */
	public function test_gather_skips_throwing_providers(): void {
		ContextProviders::register( 'throws', function ( $ctx ) {
			throw new \RuntimeException( 'Provider error' );
		} );
		ContextProviders::register( 'ok', fn( $ctx ) => [ 'key' => 'value' ] );

		$ref         = new \ReflectionClass( ContextProviders::class );
		$initialized = $ref->getProperty( 'initialized' );
		$initialized->setAccessible( true );
		$initialized->setValue( null, true );

		$result = ContextProviders::gather();

		$this->assertArrayNotHasKey( 'throws', $result );
		$this->assertArrayHasKey( 'ok', $result );
	}

	// ── format_for_prompt ─────────────────────────────────────────────────────

	/**
	 * format_for_prompt() returns empty string for empty context.
	 */
	public function test_format_for_prompt_returns_empty_for_empty_context(): void {
		$this->assertSame( '', ContextProviders::format_for_prompt( [] ) );
	}

	/**
	 * format_for_prompt() returns a markdown-formatted string.
	 */
	public function test_format_for_prompt_returns_markdown(): void {
		$context = [
			'site_info' => [
				'Site Name' => 'My Site',
				'WP Version' => '7.0',
			],
		];

		$output = ContextProviders::format_for_prompt( $context );

		$this->assertStringContainsString( '## Current Context', $output );
		$this->assertStringContainsString( '### Site Info', $output );
		$this->assertStringContainsString( '**Site Name**', $output );
		$this->assertStringContainsString( 'My Site', $output );
	}

	/**
	 * format_for_prompt() skips empty sections.
	 */
	public function test_format_for_prompt_skips_empty_sections(): void {
		$context = [
			'empty_section' => [],
			'real_section'  => [ 'key' => 'value' ],
		];

		$output = ContextProviders::format_for_prompt( $context );

		$this->assertStringNotContainsString( 'Empty Section', $output );
		$this->assertStringContainsString( 'Real Section', $output );
	}

	/**
	 * format_for_prompt() handles array values by joining with comma.
	 */
	public function test_format_for_prompt_joins_array_values(): void {
		$context = [
			'post_info' => [
				'Categories' => [ 'News', 'Tech', 'Science' ],
			],
		];

		$output = ContextProviders::format_for_prompt( $context );

		$this->assertStringContainsString( 'News, Tech, Science', $output );
	}

	/**
	 * format_for_prompt() handles scalar (non-array) section data.
	 */
	public function test_format_for_prompt_handles_scalar_section_data(): void {
		$context = [
			'raw_text' => 'Some plain text context',
		];

		$output = ContextProviders::format_for_prompt( $context );

		$this->assertStringContainsString( 'Some plain text context', $output );
	}

	// ── provide_page_context ─────────────────────────────────────────────────

	/**
	 * provide_page_context() returns empty array for empty input.
	 */
	public function test_provide_page_context_returns_empty_for_empty_input(): void {
		$result = ContextProviders::provide_page_context( [] );
		$this->assertSame( [], $result );
	}

	/**
	 * provide_page_context() extracts known fields.
	 */
	public function test_provide_page_context_extracts_known_fields(): void {
		$page_context = [
			'url'          => 'https://example.com/wp-admin/',
			'surface'      => 'frontend',
			'is_frontend'  => true,
			'post_id'      => 35,
			'post_type'    => 'page',
			'page_title'   => 'Home',
			'admin_page'   => 'dashboard',
			'screen_id'    => 'dashboard',
			'summary'      => 'WordPress Dashboard',
			'live_preview' => [
				'affected_descriptor_supported'        => true,
				'requires_refresh_when_missing_affected' => true,
				'refresh_tool'                         => 'sd-ai-agent-js/refresh-page',
			],
		];

		$result = ContextProviders::provide_page_context( $page_context );

		$this->assertArrayHasKey( 'Current URL', $result );
		$this->assertArrayHasKey( 'Surface', $result );
		$this->assertArrayHasKey( 'User Is Viewing Frontend', $result );
		$this->assertArrayHasKey( 'Visible Page Title', $result );
		$this->assertArrayHasKey( 'Visible Post ID', $result );
		$this->assertArrayHasKey( 'Visible Post Type', $result );
		$this->assertArrayHasKey( 'Live Preview Affected Descriptor Supported', $result );
		$this->assertArrayHasKey( 'Refresh Required When Affected Missing', $result );
		$this->assertArrayHasKey( 'Refresh Tool', $result );
		$this->assertArrayHasKey( 'Admin Page', $result );
		$this->assertArrayHasKey( 'Screen ID', $result );
		$this->assertArrayHasKey( 'Page Context', $result );
		$this->assertSame( 'https://example.com/wp-admin/', $result['Current URL'] );
		$this->assertSame( '35', $result['Visible Post ID'] );
		$this->assertSame( 'sd-ai-agent-js/refresh-page', $result['Refresh Tool'] );
	}

	// ── provide_user_context ─────────────────────────────────────────────────

	/**
	 * provide_user_context() returns empty array when no user is logged in.
	 */
	public function test_provide_user_context_returns_empty_when_not_logged_in(): void {
		wp_set_current_user( 0 );
		$result = ContextProviders::provide_user_context( [] );
		$this->assertSame( [], $result );
	}

	/**
	 * provide_user_context() returns user data when logged in.
	 */
	public function test_provide_user_context_returns_user_data_when_logged_in(): void {
		$user_id = self::factory()->user->create(
			[
				'display_name' => 'Test User',
				'user_login'   => 'testuser',
				'user_email'   => 'test@example.com',
				'role'         => 'administrator',
			]
		);
		update_user_meta( $user_id, 'locale', 'fr_FR' );
		wp_set_current_user( $user_id );

		$result = ContextProviders::provide_user_context( [] );

		$this->assertArrayHasKey( 'Name', $result );
		$this->assertArrayHasKey( 'Login', $result );
		$this->assertArrayHasKey( 'Email', $result );
		$this->assertArrayHasKey( 'Roles', $result );
		$this->assertSame( 'Test User', $result['Name'] );
		$this->assertSame( 'fr-FR', $result['Preferred User Language'] );
		$this->assertStringContainsString( 'language the user actually uses', $result['Language Guidance'] );
	}

	/** Site context exposes only the normalized site-language hint. */
	public function test_provide_site_context_includes_site_language(): void {
		add_filter( 'locale', static fn(): string => 'pt_BR' );

		$result = ContextProviders::provide_site_context( array() );

		$this->assertSame( 'pt-BR', $result['Site Language'] );
	}

	// ── provide_post_context ─────────────────────────────────────────────────

	/**
	 * provide_post_context() returns empty array when no post_id in context.
	 */
	public function test_provide_post_context_returns_empty_without_post_id(): void {
		$result = ContextProviders::provide_post_context( [] );
		$this->assertSame( [], $result );
	}

	/**
	 * provide_post_context() returns post data when post_id is provided.
	 */
	public function test_provide_post_context_returns_post_data(): void {
		$post_id = self::factory()->post->create(
			[
				'post_title'  => 'Test Post',
				'post_status' => 'publish',
				'post_type'   => 'post',
			]
		);

		$result = ContextProviders::provide_post_context( [ 'post_id' => $post_id ] );

		$this->assertArrayHasKey( 'Post ID', $result );
		$this->assertArrayHasKey( 'Title', $result );
		$this->assertArrayHasKey( 'Type', $result );
		$this->assertArrayHasKey( 'Status', $result );
		$this->assertSame( 'Test Post', $result['Title'] );
		$this->assertSame( 'publish', $result['Status'] );
	}

	/**
	 * provide_post_context() returns empty array for non-existent post.
	 */
	public function test_provide_post_context_returns_empty_for_nonexistent_post(): void {
		$result = ContextProviders::provide_post_context( [ 'post_id' => 999999 ] );
		$this->assertSame( [], $result );
	}
}
