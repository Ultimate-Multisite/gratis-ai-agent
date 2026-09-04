<?php

declare(strict_types=1);

namespace SdAiAgent\Bootstrap;

use SdAiAgent\Infrastructure\AiClient\Superdav\SuperdavAiProvider;
use XWP\DI\Decorators\Action;
use XWP\DI\Decorators\Filter;
use XWP\DI\Decorators\Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the first-party Superdav AI provider with the SDK registry.
 */
#[Handler(
	container: 'sd-ai-agent',
	context: Handler::CTX_GLOBAL,
	strategy: Handler::INIT_IMMEDIATELY,
)]
final class SuperdavAiProviderHandler {

	/**
	 * Register the provider if the WordPress AI Client SDK is available.
	 *
	 * The SDK may be loaded by another plugin during `plugins_loaded`, so register
	 * on early `init` after all `plugins_loaded` callbacks have had a chance to
	 * expose their SDK classes and before the default connector registry runs.
	 */
	#[Action( tag: 'init', priority: 5 )]
	public function register_provider(): void {
		if ( ! class_exists( '\WordPress\AiClient\AiClient' ) ) {
			return;
		}

		try {
			$registry = \WordPress\AiClient\AiClient::defaultRegistry();
			if ( ! $registry->hasProvider( SuperdavAiProvider::PROVIDER_ID ) ) {
				$registry->registerProvider( SuperdavAiProvider::class );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Allow the explicitly configured loopback edge through WordPress safe HTTP validation.
	 *
	 * The AI Client SDK uses `wp_safe_remote_request()`, which rejects loopback
	 * hosts by default. Local service development is an intentional exception,
	 * but only for requests beneath the exact configured cloud base URL.
	 *
	 * @param bool   $is_external Whether WordPress already considers the host external.
	 * @param string $host        Request host.
	 * @param string $url         Full request URL.
	 * @return bool Whether the host is allowed.
	 */
	#[Filter( tag: 'http_request_host_is_external', priority: 10, args: 3 )]
	public function allow_configured_loopback_host( bool $is_external, string $host, string $url ): bool {
		if ( $is_external ) {
			return true;
		}

		return self::is_configured_loopback_url( $host, $url );
	}

	/**
	 * Allow the configured loopback edge port through safe HTTP validation.
	 *
	 * @param int[]  $ports Allowed safe ports.
	 * @param string $host  Request host.
	 * @param string $url   Full request URL.
	 * @return int[] Allowed safe ports.
	 */
	#[Filter( tag: 'http_allowed_safe_ports', priority: 10, args: 3 )]
	public function allow_configured_loopback_port( array $ports, string $host, string $url ): array {
		if ( ! self::is_configured_loopback_url( $host, $url ) ) {
			return $ports;
		}

		$port = wp_parse_url( $url, PHP_URL_PORT );
		if ( is_int( $port ) && ! in_array( $port, $ports, true ) ) {
			$ports[] = $port;
		}

		return $ports;
	}

	/** Determine whether a request belongs to the exact configured loopback edge. */
	private static function is_configured_loopback_url( string $host, string $url ): bool {
		$base_parts    = wp_parse_url( SuperdavAiProvider::configured_base_url() );
		$request_parts = wp_parse_url( $url );
		if ( ! is_array( $base_parts ) || ! is_array( $request_parts ) ) {
			return false;
		}

		$base_host    = strtolower( (string) ( $base_parts['host'] ?? '' ) );
		$request_host = strtolower( (string) ( $request_parts['host'] ?? '' ) );
		$filter_host  = strtolower( trim( $host, '.' ) );
		// Production routes api.sdaiagent.com to the local managed edge (127.0.0.1).
		// WordPress therefore classifies the public hostname as local and rejects it
		// before the SDK can send the request. The configured exact host is safe to
		// allow here; the path and scheme checks below still constrain the exception.
		if ( $request_host !== $base_host
			|| $filter_host !== $base_host
		) {
			return false;
		}

		$base_scheme    = strtolower( (string) ( $base_parts['scheme'] ?? '' ) );
		$request_scheme = strtolower( (string) ( $request_parts['scheme'] ?? '' ) );
		if ( $request_scheme !== $base_scheme
			|| (int) ( $request_parts['port'] ?? 0 ) !== (int) ( $base_parts['port'] ?? 0 )
		) {
			return false;
		}

		$base_path    = '/' . trim( (string) ( $base_parts['path'] ?? '' ), '/' );
		$request_path = '/' . trim( (string) ( $request_parts['path'] ?? '' ), '/' );
		if ( 1 === preg_match( '#(?:^|/)\.{1,2}(?:/|$)#', rawurldecode( $request_path ) ) ) {
			return false;
		}

		return $request_path === $base_path || str_starts_with( $request_path, rtrim( $base_path, '/' ) . '/' );
	}
}
