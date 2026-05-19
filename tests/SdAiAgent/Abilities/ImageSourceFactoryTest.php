<?php
/**
 * Test case for ImageSourceFactory fallback behavior.
 *
 * @package SdAiAgent
 * @subpackage Tests
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tests\Abilities;

use SdAiAgent\Abilities\ImageSources\ImageSourceFactory;
use SdAiAgent\Abilities\ImageSources\ImageSourceInterface;
use WP_Error;
use WP_UnitTestCase;

/**
 * Verifies free stock image fallback handling.
 */
class ImageSourceFactoryTest extends WP_UnitTestCase {

	/**
	 * Original registered image sources.
	 *
	 * @var array<string, ImageSourceInterface>
	 */
	private array $original_sources = [];

	/**
	 * Preserve the real source registry.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->original_sources = $this->get_sources();
	}

	/**
	 * Restore the real source registry.
	 */
	public function tear_down(): void {
		$this->set_sources( $this->original_sources );

		parent::tear_down();
	}

	/**
	 * Import retries every available free source, and every candidate result per source,
	 * before returning the aggregate stock-image failure.
	 */
	public function test_import_image_retries_all_free_sources_on_download_failure(): void {
		$openverse = new FakeImageSource(
			'openverse',
			'free',
			[
				[ 'id' => 'openverse-1', 'source' => 'openverse' ],
				[ 'id' => 'openverse-2', 'source' => 'openverse' ],
			]
		);
		$pixabay   = new FakeImageSource(
			'pixabay',
			'free',
			[
				[ 'id' => 'pixabay-1', 'source' => 'pixabay' ],
			]
		);
		$generate  = new FakeImageSource( 'generate', 'api', [] );

		$this->set_sources(
			[
				'openverse' => $openverse,
				'pixabay'   => $pixabay,
				'generate'  => $generate,
			]
		);

		$result = ImageSourceFactory::import_image(
			'test query',
			'',
			1200,
			800,
			[ 'no_generate_fallback' => true ]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'all_sources_failed', $result->get_error_code() );
		$this->assertSame( [ 'openverse-1', 'openverse-2' ], $openverse->downloaded_ids );
		$this->assertSame( [ 'pixabay-1' ], $pixabay->downloaded_ids );
		$this->assertSame( [], $generate->downloaded_ids );
		$this->assertStringContainsString( 'openverse', $result->get_error_message() );
		$this->assertStringContainsString( 'pixabay', $result->get_error_message() );
	}

	/**
	 * Get the private source registry.
	 *
	 * @return array<string, ImageSourceInterface>
	 */
	private function get_sources(): array {
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
	 * Replace the private source registry.
	 *
	 * @param array<string, ImageSourceInterface> $sources Sources keyed by ID.
	 */
	private function set_sources( array $sources ): void {
		$property = new \ReflectionProperty( ImageSourceFactory::class, 'sources' );
		$property->setAccessible( true );
		$property->setValue( null, $sources );
	}
}

/**
 * Fake image source for fallback tests.
 */
class FakeImageSource implements ImageSourceInterface {

	/**
	 * Downloaded image IDs.
	 *
	 * @var list<string>
	 */
	public array $downloaded_ids = [];

	/**
	 * Constructor.
	 *
	 * @param string       $id        Source ID.
	 * @param 'free'|'api' $cost_type Source cost type.
	 * @param array<array> $hits      Search hits to return.
	 */
	public function __construct(
		private string $id,
		private string $cost_type,
		private array $hits
	) {}

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return ucfirst( $this->id );
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function search( string $keyword, int $per_page = 10, array $filters = [] ): array|WP_Error {
		return [
			'hits'   => array_slice( $this->hits, 0, $per_page ),
			'total'  => count( $this->hits ),
			'source' => $this->id,
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_image( string $image_id ): array|WP_Error {
		return new WP_Error( 'not_used', 'Not used by this test.' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function download( string $image_id, int $width = 0, int $height = 0 ): string|WP_Error {
		$this->downloaded_ids[] = $image_id;

		return new WP_Error( 'download_error', 'Synthetic download failure.' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_cost_type(): string {
		return $this->cost_type;
	}
}
