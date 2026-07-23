<?php

declare(strict_types=1);

namespace SdAiAgent\Core;

use SdAiAgent\Infrastructure\AiClient\Superdav\SuperdavAiProvider;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Performs bounded, scrubbed provider model metadata discovery.
 */
final class ProviderModelDiscovery {

	/** One short retry delay prevents immediate cache/thundering-herd repeats. */
	private const RETRY_BACKOFF_MICROSECONDS = 100000;

	/**
	 * List model metadata and recover managed Superdav discovery failures once.
	 *
	 * @param string $provider_id Provider ID.
	 * @param string $class       Provider class name.
	 * @return array{metadata:array<int, object>,failure:array<string, bool|int|string>|null} Discovery result.
	 */
	public static function discover( string $provider_id, string $class ): array {
		$started_at = microtime( true );

		try {
			return self::success( self::list_metadata( $class ) );
		} catch ( \Throwable $error ) {
			$status_code = ProviderErrorClassifier::extract_status_code( $error );

			if ( SuperdavAiProvider::PROVIDER_ID === $provider_id && ProviderErrorClassifier::is_unauthorized( $error, $status_code ) ) {
				return self::recover_unauthorized_discovery( $provider_id, $class, $started_at );
			}

			if ( SuperdavAiProvider::PROVIDER_ID === $provider_id && ProviderErrorClassifier::is_retryable( $error, $status_code ) ) {
				return self::retry_after_cache_invalidation( $provider_id, $class, $started_at );
			}

			return self::failure( $provider_id, $error, $status_code, 1, $started_at );
		}
	}

	/**
	 * Recover the existing managed token-refresh path after a 401 model listing.
	 *
	 * @param string $provider_id Provider ID.
	 * @param string $class       Provider class name.
	 * @param float  $started_at  Discovery start timestamp.
	 * @return array{metadata:array<int, object>,failure:array<string, bool|int|string>|null} Discovery result.
	 */
	private static function recover_unauthorized_discovery( string $provider_id, string $class, float $started_at ): array {
		$status = ( new SuperdavSiteConnectionService() )->provision_site_token();
		if ( $status instanceof WP_Error ) {
			return self::failure( $provider_id, $status, ProviderErrorClassifier::extract_status_code( $status ), 1, $started_at );
		}

		ProviderCredentialLoader::load();

		try {
			return self::success( self::list_metadata_after_cache_invalidation( $class ) );
		} catch ( \Throwable $error ) {
			return self::failure(
				$provider_id,
				$error,
				ProviderErrorClassifier::extract_status_code( $error ),
				2,
				$started_at
			);
		}
	}

	/**
	 * Retry one explicitly retryable managed discovery failure after cache invalidation.
	 *
	 * @param string $provider_id Provider ID.
	 * @param string $class       Provider class name.
	 * @param float  $started_at  Discovery start timestamp.
	 * @return array{metadata:array<int, object>,failure:array<string, bool|int|string>|null} Discovery result.
	 */
	private static function retry_after_cache_invalidation( string $provider_id, string $class, float $started_at ): array {
		usleep( self::RETRY_BACKOFF_MICROSECONDS );

		try {
			return self::success( self::list_metadata_after_cache_invalidation( $class ) );
		} catch ( \Throwable $error ) {
			return self::failure(
				$provider_id,
				$error,
				ProviderErrorClassifier::extract_status_code( $error ),
				2,
				$started_at
			);
		}
	}

	/**
	 * List metadata after invalidating a directory cache when the installed SDK supports it.
	 *
	 * @param string $class Provider class name.
	 * @return array<int, object> Model metadata DTOs.
	 */
	private static function list_metadata_after_cache_invalidation( string $class ): array {
		$directory = self::get_directory( $class );
		if ( method_exists( $directory, 'invalidateCaches' ) ) {
			$directory->invalidateCaches();
		}

		return self::list_metadata_from_directory( $directory );
	}

	/**
	 * List model metadata from a provider class.
	 *
	 * @param string $class Provider class name.
	 * @return array<int, object> Model metadata DTOs.
	 */
	private static function list_metadata( string $class ): array {
		return self::list_metadata_from_directory( self::get_directory( $class ) );
	}

	/**
	 * Get a usable model metadata directory from a provider class.
	 *
	 * @param string $class Provider class name.
	 * @return object Model metadata directory.
	 */
	private static function get_directory( string $class ): object {
		$factory_candidate = array( $class, 'modelMetadataDirectory' );
		if ( ! is_callable( $factory_candidate ) ) {
			throw new \UnexpectedValueException( 'Provider model metadata directory is unavailable.' );
		}

		/** @var callable(): mixed $factory */
		$factory   = $factory_candidate;
		$directory = $factory();
		if ( ! is_object( $directory ) || ! method_exists( $directory, 'listModelMetadata' ) ) {
			throw new \UnexpectedValueException( 'Provider model metadata directory is unavailable.' );
		}

		return $directory;
	}

	/**
	 * Read metadata from a directory-like SDK object.
	 *
	 * @param object $directory Model metadata directory.
	 * @return array<int, object> Model metadata DTOs.
	 */
	private static function list_metadata_from_directory( object $directory ): array {
		$list_metadata_candidate = array( $directory, 'listModelMetadata' );
		if ( ! is_callable( $list_metadata_candidate ) ) {
			throw new \UnexpectedValueException( 'Provider model metadata directory is unavailable.' );
		}

		/** @var callable(): mixed $list_metadata */
		$list_metadata  = $list_metadata_candidate;
		$model_metadata = $list_metadata();
		if ( ! is_array( $model_metadata ) ) {
			throw new \UnexpectedValueException( 'Provider model metadata response is invalid.' );
		}

		return array_values( array_filter( $model_metadata, 'is_object' ) );
	}

	/**
	 * Build a successful discovery result.
	 *
	 * @param array<int, object> $metadata Model metadata DTOs.
	 * @return array{metadata:array<int, object>,failure:null} Discovery result.
	 */
	private static function success( array $metadata ): array {
		return array(
			'metadata' => $metadata,
			'failure'  => null,
		);
	}

	/**
	 * Build and record a scrubbed failure response.
	 *
	 * @param string                   $provider_id Provider ID.
	 * @param WP_Error|\Throwable|null $error       Provider error.
	 * @param int                      $status_code HTTP status code, or 0 when unavailable.
	 * @param int                      $attempts    Number of model listing attempts.
	 * @param float                    $started_at  Discovery start timestamp.
	 * @return array{metadata:array<int, object>,failure:array<string, bool|int|string>} Discovery result.
	 */
	private static function failure( string $provider_id, $error, int $status_code, int $attempts, float $started_at ): array {
		$retryable   = ProviderErrorClassifier::is_retryable( $error, $status_code );
		$category    = ProviderErrorClassifier::get_failure_category( $error, $status_code );
		$duration_ms = max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) );
		$failure     = array(
			'state'     => $retryable ? 'retryable_unavailable' : 'unavailable',
			'code'      => 'model_discovery_' . $category,
			'retryable' => $retryable,
			'attempts'  => max( 1, $attempts ),
		);

		if ( $status_code > 0 ) {
			$failure['status'] = $status_code;
		}

		ProviderTraceLogger::record_model_discovery_failure(
			$provider_id,
			$category,
			$status_code,
			$attempts,
			$duration_ms
		);

		return array(
			'metadata' => array(),
			'failure'  => $failure,
		);
	}
}
