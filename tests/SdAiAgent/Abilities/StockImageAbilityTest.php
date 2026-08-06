<?php

declare(strict_types=1);
/**
 * Tests for StockImageAbility — candidate search, import path, and attribution.
 *
 * Coverage:
 * - Search mode (action=search) returns candidate list with required fields.
 * - Import mode (action=import) with provider + image_id stores attribution.
 * - Auto mode (no action) preserves backward-compatible import behaviour.
 * - Missing keyword validation.
 * - Provider-mock isolation via ImageSourceFactory reflection.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\ImageAbilities\StockImageAbility;
use SdAiAgent\Abilities\ImageSources\ImageSourceFactory;
use SdAiAgent\Abilities\ImageSources\ImageSourceInterface;
use SdAiAgent\Core\ToolPermissionResolver;
use WP_Error;
use WP_UnitTestCase;

/**
 * Tests for StockImageAbility candidate search and import modes.
 *
 * @since 1.7.0
 */
class StockImageAbilityTest extends WP_UnitTestCase {

	/**
	 * Saved source registry.
	 *
	 * @var array<string, ImageSourceInterface>
	 */
	private array $original_sources = [];

	/**
	 * Ability under test.
	 *
	 * @var StockImageAbility
	 */
	private StockImageAbility $ability;

	/**
	 * Set up: preserve factory sources and create ability.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->original_sources = $this->get_factory_sources();
		$this->ability          = new StockImageAbility( 'sd-ai-agent/stock-image' );
	}

	/**
	 * Tear down: restore factory sources.
	 */
	public function tear_down(): void {
		$this->set_factory_sources( $this->original_sources );

		parent::tear_down();
	}

	// ─── missing keyword ─────────────────────────────────────────────────────

	/**
	 * Missing keyword returns WP_Error.
	 */
	public function test_missing_keyword_returns_wp_error(): void {
		$result = $this->invoke_execute( [] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_keyword', $result->get_error_code() );
	}

	/**
	 * Empty keyword returns WP_Error.
	 */
	public function test_empty_keyword_returns_wp_error(): void {
		$result = $this->invoke_execute( [ 'keyword' => '' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_keyword', $result->get_error_code() );
	}

	/**
	 * The schema keeps keyword optional so import-by-id calls from a previous
	 * search result can pass provider + image_id without being rejected before
	 * execute_callback() applies action-specific validation.
	 */
	public function test_schema_does_not_require_keyword_for_import_by_id(): void {
		$method = new \ReflectionMethod( StockImageAbility::class, 'input_schema' );
		$method->setAccessible( true );

		/** @var array{required?: list<string>} $schema */
		$schema = $method->invoke( $this->ability );

		$this->assertArrayHasKey( 'required', $schema );
		$this->assertNotContains( 'keyword', $schema['required'] );
	}

	/**
	 * Stock-image imports are safe writes, not destructive operations, so the
	 * default tool policy should not pause for user confirmation.
	 */
	public function test_stock_image_is_non_destructive_for_default_tool_policy(): void {
		$meta = $this->ability->get_meta();

		$this->assertIsArray( $meta['annotations'] ?? null );
		$this->assertFalse( $meta['annotations']['readonly'] );
		$this->assertFalse( $meta['annotations']['destructive'] );
		$this->assertSame( 'write', ToolPermissionResolver::classify_ability( $this->ability ) );
	}

	/**
	 * The provider schema only advertises free sources that are currently usable,
	 * so direct model calls are not guided toward an unconfigured Pixabay source.
	 */
	public function test_schema_provider_enum_excludes_unavailable_pixabay(): void {
		$this->set_factory_sources(
			[
				'openverse' => new FakeStockImageSource( 'openverse', 'free', [], [], true ),
				'pixabay'   => new FakeStockImageSource( 'pixabay', 'free', [], [], false ),
				'generate'  => new FakeStockImageSource( 'generate', 'api', [], [], true ),
			]
		);

		$method = new \ReflectionMethod( StockImageAbility::class, 'input_schema' );
		$method->setAccessible( true );

		/** @var array{properties: array{provider: array{enum: list<string>}}} $schema */
		$schema = $method->invoke( $this->ability );

		$this->assertSame( [ 'openverse' ], $schema['properties']['provider']['enum'] );
		$this->assertNotContains( 'pixabay', $schema['properties']['provider']['enum'] );
	}

	// ─── search mode (action=search) ─────────────────────────────────────────

	/**
	 * Search mode with mock source returns candidates array with required fields.
	 */
	public function test_search_mode_returns_candidates_with_required_fields(): void {
		$fake_source = new FakeStockImageSource(
			'openverse',
			'free',
			[
				[
					'id'          => 'img-001',
					'preview'     => 'https://openverse.example.com/thumb/img-001.jpg',
					'medium'      => 'https://openverse.example.com/medium/img-001.jpg',
					'full'        => 'https://openverse.example.com/full/img-001.jpg',
					'width'       => 1920,
					'height'      => 1080,
					'title'       => 'Mountain Landscape',
					'author'      => 'Jane Doe',
					'author_url'  => 'https://openverse.example.com/users/janedoe',
					'license'     => 'CC0',
					'license_url' => 'https://creativecommons.org/publicdomain/zero/1.0/',
					'source'      => 'openverse',
					'attribution' => 'Photo by Jane Doe on Openverse (CC0)',
				],
			]
		);

		$this->set_factory_sources(
			[
				'openverse' => $fake_source,
				'pixabay'   => new FakeStockImageSource( 'pixabay', 'free', [] ),
				'generate'  => new FakeStockImageSource( 'generate', 'api', [] ),
			]
		);

		$result = $this->invoke_execute(
			[
				'keyword' => 'mountain landscape',
				'action'  => 'search',
				'limit'   => 3,
			]
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'candidates', $result );
		$this->assertIsArray( $result['candidates'] );
		$this->assertCount( 1, $result['candidates'] );

		$candidate = $result['candidates'][0];
		$this->assertArrayHasKey( 'image_id', $candidate, 'candidate must have image_id' );
		$this->assertArrayHasKey( 'provider', $candidate, 'candidate must have provider' );
		$this->assertArrayHasKey( 'thumbnail', $candidate, 'candidate must have thumbnail' );
		$this->assertArrayHasKey( 'width', $candidate, 'candidate must have width' );
		$this->assertArrayHasKey( 'height', $candidate, 'candidate must have height' );
		$this->assertArrayHasKey( 'licence', $candidate, 'candidate must have licence' );
		$this->assertArrayHasKey( 'attribution', $candidate, 'candidate must have attribution' );
		$this->assertArrayHasKey( 'title', $candidate, 'candidate must have title' );

		$this->assertSame( 'img-001', $candidate['image_id'] );
		$this->assertSame( 'openverse', $candidate['provider'] );
		$this->assertSame( 1920, $candidate['width'] );
		$this->assertSame( 1080, $candidate['height'] );
		$this->assertSame( 'CC0', $candidate['licence'] );
		$this->assertSame( 'Photo by Jane Doe on Openverse (CC0)', $candidate['attribution'] );
	}

	/**
	 * Search mode with no free sources returns error key (not WP_Error) for agent readability.
	 */
	public function test_search_mode_no_free_sources_returns_error_in_array(): void {
		$this->set_factory_sources(
			[
				'generate' => new FakeStockImageSource( 'generate', 'api', [] ),
			]
		);

		$result = $this->invoke_execute(
			[
				'keyword' => 'mountain',
				'action'  => 'search',
			]
		);

		// The ability converts factory errors to an array with an 'error' key
		// so the agent loop can surface a human-readable message.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayHasKey( 'candidates', $result );
		$this->assertEmpty( $result['candidates'] );
	}

	/**
	 * Search mode passes orientation and colour filters to the source.
	 */
	public function test_search_mode_passes_filters_to_source(): void {
		$fake_source = new FakeStockImageSource( 'openverse', 'free', [] );

		$this->set_factory_sources(
			[
				'openverse' => $fake_source,
				'pixabay'   => new FakeStockImageSource( 'pixabay', 'free', [] ),
				'generate'  => new FakeStockImageSource( 'generate', 'api', [] ),
			]
		);

		$this->invoke_execute(
			[
				'keyword'     => 'city skyline',
				'action'      => 'search',
				'orientation' => 'landscape',
				'colour'      => 'blue',
				'min_width'   => 1600,
				'min_height'  => 900,
			]
		);

		// Verify that the fake source received the filters.
		$this->assertCount( 1, $fake_source->search_calls, 'search() should have been called once' );
		$filters = $fake_source->search_calls[0]['filters'];
		$this->assertSame( 'landscape', $filters['orientation'] ?? null );
		$this->assertSame( 'blue', $filters['colour'] ?? null );
		$this->assertSame( 1600, $filters['min_width'] ?? 0 );
		$this->assertSame( 900, $filters['min_height'] ?? 0 );
	}

	/**
	 * Direct calls that still send an unavailable free provider fall back to the
	 * available free-source chain instead of failing the stock-image search.
	 */
	public function test_search_mode_falls_back_from_unavailable_pixabay_to_available_provider(): void {
		$openverse = new FakeStockImageSource(
			'openverse',
			'free',
			[
				[
					'id'      => 'ov-ballet-1',
					'preview' => 'https://openverse.example.com/thumb/ov-ballet-1.jpg',
					'width'   => 1200,
					'height'  => 800,
					'title'   => 'Children ballet class',
					'license' => 'CC0',
					'source'  => 'openverse',
				]
			]
		);

		$this->set_factory_sources(
			[
				'openverse' => $openverse,
				'pixabay'   => new FakeStockImageSource( 'pixabay', 'free', [], [], false ),
				'generate'  => new FakeStockImageSource( 'generate', 'api', [] ),
			]
		);

		$result = $this->invoke_execute(
			[
				'keyword'  => 'children ballet class',
				'action'   => 'search',
				'provider' => 'pixabay',
			]
		);

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertCount( 1, $result['candidates'] );
		$this->assertSame( 'openverse', $result['candidates'][0]['provider'] );
		$this->assertCount( 1, $openverse->search_calls );
	}

	// ─── import mode (action=import with provider + image_id) ────────────────

	/**
	 * Import mode stores _sd_ai_agent_attribution post meta.
	 */
	public function test_import_mode_stores_attribution_meta(): void {
		// Use a fake source that returns hit metadata and downloads a valid PNG.
		$fake_source = new FakeStockImageSource(
			'openverse',
			'free',
			[],
			[
				'img-042' => [
					'url'         => '',
					'width'       => 800,
					'height'      => 600,
					'author'      => 'Test Author',
					'author_url'  => 'https://example.com/author',
					'license'     => 'CC0',
					'source'      => 'openverse',
					'attribution' => 'Photo by Test Author on openverse (CC0)',
				],
			]
		);

		$this->set_factory_sources(
			[
				'openverse' => $fake_source,
				'generate'  => new FakeStockImageSource( 'generate', 'api', [] ),
			]
		);

		// Write a minimal 1×1 transparent PNG to a temp file so sideload works.
		$png_data = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- test fixture
		$tmp_file = get_temp_dir() . 'stock-test-' . uniqid() . '.png';
		file_put_contents( $tmp_file, $png_data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture

		$fake_source->tmp_file = $tmp_file;

		$result = $this->invoke_execute(
			[
				'keyword'  => 'mountain landscape',
				'action'   => 'import',
				'provider' => 'openverse',
				'image_id' => 'img-042',
			]
		);

		if ( is_wp_error( $result ) ) {
			// In some test environments media_handle_sideload is unavailable.
			$this->markTestSkipped( 'media_handle_sideload not available: ' . $result->get_error_message() );
			return;
		}

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'attachment_id', $result );
		$this->assertGreaterThan( 0, $result['attachment_id'] );

		// Verify attribution is in the result.
		$this->assertArrayHasKey( 'attribution', $result );
		$this->assertSame( 'Photo by Test Author on openverse (CC0)', $result['attribution'] );

		// Verify _sd_ai_agent_attribution meta was stored.
		$stored = get_post_meta( $result['attachment_id'], '_sd_ai_agent_attribution', true );
		$this->assertSame( 'Photo by Test Author on openverse (CC0)', $stored );

		// Clean up.
		wp_delete_attachment( $result['attachment_id'], true );
	}

	/**
	 * Importing a selected candidate by provider + image_id does not require the
	 * model to repeat the original keyword; the image ID is used as the fallback
	 * title/alt base when keyword is omitted.
	 */
	public function test_import_mode_with_provider_and_image_id_allows_missing_keyword(): void {
		$fake_source = new FakeStockImageSource(
			'openverse',
			'free',
			[],
			[
				'img-no-keyword' => [
					'url'         => '',
					'width'       => 800,
					'height'      => 600,
					'author'      => 'Test Author',
					'author_url'  => 'https://example.com/author',
					'license'     => 'CC0',
					'source'      => 'openverse',
					'attribution' => 'Photo by Test Author on openverse (CC0)',
				],
			]
		);

		$this->set_factory_sources(
			[
				'openverse' => $fake_source,
				'generate'  => new FakeStockImageSource( 'generate', 'api', [] ),
			]
		);

		$png_data = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- test fixture
		$tmp_file = get_temp_dir() . 'stock-test-' . uniqid() . '.png';
		file_put_contents( $tmp_file, $png_data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture

		$fake_source->tmp_file = $tmp_file;

		$result = $this->invoke_execute(
			[
				'action'   => 'import',
				'provider' => 'openverse',
				'image_id' => 'img-no-keyword',
			]
		);

		if ( is_wp_error( $result ) ) {
			$this->markTestSkipped( 'media_handle_sideload not available: ' . $result->get_error_message() );
			return;
		}

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['attachment_id'] );
		$this->assertSame( 'Img No Keyword', $result['title'] );
		$this->assertSame( [ 'img-no-keyword' ], $fake_source->downloaded_ids );

		wp_delete_attachment( $result['attachment_id'], true );
	}

	/**
	 * Import mode with missing image_id falls back to auto-import behaviour.
	 *
	 * When action=import but image_id is empty, the ability falls back to the
	 * original auto-import path (first viable hit) rather than erroring.
	 */
	public function test_import_mode_without_image_id_falls_back_to_auto(): void {
		// Provide no free sources so auto-import fails cleanly.
		$this->set_factory_sources(
			[
				'generate' => new FakeStockImageSource( 'generate', 'api', [] ),
			]
		);

		$result = $this->invoke_execute(
			[
				'keyword' => 'test keyword',
				'action'  => 'import',
				// No image_id or provider supplied.
			]
		);

		// Should fall through to auto-import path, which returns an error array
		// since no free sources are configured.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
	}

	// ─── auto mode (no action) ────────────────────────────────────────────────

	/**
	 * Auto mode (no action) with no free sources returns error array (backward compat).
	 */
	public function test_auto_mode_no_free_sources_returns_error_array(): void {
		$this->set_factory_sources(
			[
				'generate' => new FakeStockImageSource( 'generate', 'api', [] ),
			]
		);

		$result = $this->invoke_execute( [ 'keyword' => 'landscape' ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 0, $result['attachment_id'] );
	}

	/**
	 * Stock import failures must remain explicit failures so a caller does not
	 * mistake an empty URL for a successfully acquired image.
	 */
	public function test_auto_mode_source_failure_returns_recoverable_failure_signal(): void {
		$this->set_factory_sources(
			[
				'openverse' => new FakeStockImageSource( 'openverse', 'free', [] ),
				'generate'  => new FakeStockImageSource( 'generate', 'api', [] ),
			]
		);

		$result = $this->invoke_execute( [ 'keyword' => 'photographer portrait' ] );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 0, $result['attachment_id'] );
		$this->assertSame( '', $result['url'] );
		$this->assertNotEmpty( $result['error'] );
	}

	/**
	 * Imports require both a local attachment ID and URL before succeeding.
	 */
	public function test_import_result_requires_attachment_id_and_url(): void {
		$method = new \ReflectionMethod( StockImageAbility::class, 'has_usable_import_result' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $this->ability, [ 'attachment_id' => 1, 'url' => 'https://example.com/image.jpg' ] ) );
		$this->assertFalse( $method->invoke( $this->ability, [ 'attachment_id' => 0, 'url' => 'https://example.com/image.jpg' ] ) );
		$this->assertFalse( $method->invoke( $this->ability, [ 'attachment_id' => 1, 'url' => '' ] ) );
	}

	// ─── search result candidate structure ───────────────────────────────────

	/**
	 * search_candidates() returns attribution from pre-built hit string when available.
	 */
	public function test_search_candidates_uses_prebuilt_attribution(): void {
		$fake_source = new FakeStockImageSource(
			'openverse',
			'free',
			[
				[
					'id'          => 'abc-123',
					'preview'     => 'https://example.com/thumb.jpg',
					'width'       => 640,
					'height'      => 480,
					'title'       => 'Test Image',
					'author'      => 'Alice',
					'author_url'  => '',
					'license'     => 'CC-BY',
					'source'      => 'flickr',
					'attribution' => '"Test Image" by Alice is licensed CC-BY.',
				],
			]
		);

		$this->set_factory_sources(
			[
				'openverse' => $fake_source,
				'generate'  => new FakeStockImageSource( 'generate', 'api', [] ),
			]
		);

		$result = ImageSourceFactory::search_candidates( 'test', 5 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'candidates', $result );
		$this->assertCount( 1, $result['candidates'] );

		$candidate = $result['candidates'][0];
		$this->assertSame( '"Test Image" by Alice is licensed CC-BY.', $candidate['attribution'] );
	}

	/**
	 * search_candidates() builds attribution from author+source when pre-built string absent.
	 */
	public function test_search_candidates_builds_attribution_from_author(): void {
		$fake_source = new FakeStockImageSource(
			'pixabay',
			'free',
			[
				[
					'id'         => 'px-99',
					'preview'    => 'https://example.com/px-thumb.jpg',
					'width'      => 1280,
					'height'     => 720,
					'title'      => 'Sunset',
					'author'     => 'Bob',
					'author_url' => 'https://pixabay.com/users/bob',
					'license'    => 'CC0',
					'source'     => 'pixabay',
					// No 'attribution' key.
				],
			]
		);

		$this->set_factory_sources(
			[
				'pixabay'  => $fake_source,
				'openverse' => new FakeStockImageSource( 'openverse', 'free', [] ),
				'generate'  => new FakeStockImageSource( 'generate', 'api', [] ),
			]
		);

		$result = ImageSourceFactory::search_candidates( 'sunset', 5, 'pixabay' );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['candidates'] );

		$attribution = $result['candidates'][0]['attribution'];
		$this->assertStringContainsString( 'Bob', $attribution );
		$this->assertStringContainsString( 'pixabay', strtolower( $attribution ) );
	}

	// ─── Setup Assistant tool list ────────────────────────────────────────────

	/**
	 * The unified Setup Assistant includes stock-image and generate-image in its
	 * tier_1_tools so the imagery guidance can be executed.
	 */
	public function test_setup_assistant_tier_1_tools_include_imagery_abilities(): void {
		\SdAiAgent\Models\Agent::reset_defaults();

		$agent = \SdAiAgent\Models\Agent::get_by_slug( \SdAiAgent\Models\Agent::ONBOARDING_AGENT_SLUG );
		$this->assertNotNull( $agent, 'Setup Assistant agent must exist after seed_defaults()' );

		$tools = $agent->tier_1_tools;
		$this->assertIsArray( $tools );

		$this->assertContains(
			'sd-ai-agent/stock-image',
			$tools,
			'tier_1_tools must include sd-ai-agent/stock-image for setup imagery'
		);

		$this->assertContains(
			'sd-ai-agent/generate-image',
			$tools,
			'tier_1_tools must include sd-ai-agent/generate-image as AI imagery fallback'
		);

	}

	/**
	 * The Setup Assistant system prompt references the stock-image imagery workflow.
	 */
	public function test_setup_assistant_system_prompt_references_imagery_workflow(): void {
		\SdAiAgent\Models\Agent::reset_defaults();

		$agent = \SdAiAgent\Models\Agent::get_by_slug( \SdAiAgent\Models\Agent::ONBOARDING_AGENT_SLUG );
		$this->assertNotNull( $agent );

		$prompt_lower = strtolower( $agent->system_prompt );

		$this->assertStringContainsString(
			'action: search',
			$prompt_lower,
			'system_prompt must reference action=search for candidate discovery'
		);
		$this->assertStringContainsString(
			'action: import',
			$prompt_lower,
			'system_prompt must reference action=import for downloading selected images'
		);
		$this->assertStringContainsString(
			'attachment_id',
			$agent->system_prompt,
			'system_prompt must reference attachment_id for use in create-post'
		);

	}

	// ─── Helpers ─────────────────────────────────────────────────────────────

	/**
	 * Invoke execute_callback via the public run() proxy.
	 *
	 * AbstractAbility::run() calls execute_callback() directly, bypassing
	 * permission checks and schema validation — exactly what unit tests need.
	 *
	 * @param array $input Input array.
	 * @return array|\WP_Error
	 */
	private function invoke_execute( array $input ): array|\WP_Error {
		/** @var array|\WP_Error $result */
		$result = $this->ability->run( $input );

		return $result;
	}

	/**
	 * Get the private source registry from ImageSourceFactory.
	 *
	 * @return array<string, ImageSourceInterface>
	 */
	private function get_factory_sources(): array {
		$property = new \ReflectionProperty( ImageSourceFactory::class, 'sources' );
		$property->setAccessible( true );

		$sources = $property->getValue();

		if ( [] === $sources ) {
			ImageSourceFactory::init();
			$sources = $property->getValue();
		}

		return $sources;
	}

	/**
	 * Replace the private source registry in ImageSourceFactory.
	 *
	 * @param array<string, ImageSourceInterface> $sources Sources keyed by ID.
	 */
	private function set_factory_sources( array $sources ): void {
		$property = new \ReflectionProperty( ImageSourceFactory::class, 'sources' );
		$property->setAccessible( true );
		$property->setValue( null, $sources );
	}
}

/**
 * Fake image source for StockImageAbility tests.
 */
class FakeStockImageSource implements ImageSourceInterface {

	/**
	 * Calls to search() with captured args.
	 *
	 * @var list<array{keyword: string, per_page: int, filters: array}>
	 */
	public array $search_calls = [];

	/**
	 * Downloaded image IDs.
	 *
	 * @var list<string>
	 */
	public array $downloaded_ids = [];

	/**
	 * Temp file path to return from download() (null = return error).
	 *
	 * @var string|null
	 */
	public ?string $tmp_file = null;

	/**
	 * Constructor.
	 *
	 * @param string       $id        Source ID.
	 * @param 'free'|'api' $cost_type Source cost type.
	 * @param array<array> $hits      Search hits to return.
	 * @param array<array> $images    Image metadata keyed by image_id for get_image().
	 */
	public function __construct(
		private string $id,
		private string $cost_type,
		private array $hits,
		private array $images = [],
		private bool $available = true
	) {}

	/** {@inheritdoc} */
	public function get_id(): string {
		return $this->id;
	}

	/** {@inheritdoc} */
	public function get_name(): string {
		return ucfirst( $this->id );
	}

	/** {@inheritdoc} */
	public function is_available(): bool {
		return $this->available;
	}

	/** {@inheritdoc} */
	public function search( string $keyword, int $per_page = 10, array $filters = [] ): array|WP_Error {
		$this->search_calls[] = [
			'keyword'  => $keyword,
			'per_page' => $per_page,
			'filters'  => $filters,
		];

		return [
			'hits'   => array_slice( $this->hits, 0, $per_page ),
			'total'  => count( $this->hits ),
			'source' => $this->id,
		];
	}

	/** {@inheritdoc} */
	public function get_image( string $image_id ): array|WP_Error {
		if ( isset( $this->images[ $image_id ] ) ) {
			return $this->images[ $image_id ];
		}

		return new WP_Error( 'not_found', 'Image not found in fake source.' );
	}

	/** {@inheritdoc} */
	public function download( string $image_id, int $width = 0, int $height = 0 ): string|WP_Error {
		$this->downloaded_ids[] = $image_id;

		if ( null !== $this->tmp_file ) {
			return $this->tmp_file;
		}

		return new WP_Error( 'download_error', 'Synthetic download failure.' );
	}

	/** {@inheritdoc} */
	public function get_cost_type(): string {
		return $this->cost_type;
	}
}
