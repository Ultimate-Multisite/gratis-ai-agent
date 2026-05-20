<?php
/**
 * WP AI Client bridge polyfill: WP_AI_Client_Cache class
 *
 * Provides WordPress-specific PSR-16 cache adapter for the AI Client
 * on WordPress < 7.0 where this class is not available in core.
 *
 * On WordPress 7.0+, this file is a no-op — core's definition wins.
 *
 * Source: wp-includes/ai-client/adapters/class-wp-ai-client-cache.php (WP 7.0)
 *
 * @package SdAiAgent\Compat
 * @license GPL-2.0-or-later
 * @since   1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'WP_AI_Client_Cache' ) ) {
	return;
}

use WordPress\AiClientDependencies\Psr\SimpleCache\CacheInterface;

/**
 * WordPress-specific PSR-16 cache adapter for the AI Client.
 *
 * Bridges PSR-16 cache operations to WordPress object cache functions,
 * enabling the AI client to leverage WordPress caching infrastructure.
 *
 * @since 7.0.0
 * @internal Intended only to wire up the PHP AI Client SDK to WordPress's caching system.
 * @access private
 */
class WP_AI_Client_Cache implements CacheInterface {

	/**
	 * Cache group used for all cache operations.
	 *
	 * @since 7.0.0
	 * @var string
	 */
	private const CACHE_GROUP = 'wp_ai_client';

	/**
	 * Fetches a value from the cache.
	 *
	 * Backed by transients so the value survives across PHP requests. When a
	 * persistent object cache (Redis, Memcached, etc.) is configured, the
	 * transient API automatically routes through it; otherwise transients
	 * fall through to the options table.
	 *
	 * @since 7.0.0
	 *
	 * @param string $key           The unique key of this item in the cache.
	 * @param mixed  $default_value Default value to return if the key does not exist.
	 * @return mixed The value of the item from the cache, or $default_value in case of cache miss.
	 */
	public function get( $key, $default_value = null ) {
		$value = get_transient( $this->transient_key( $key ) );

		if ( false === $value ) {
			return $default_value;
		}

		return $value;
	}

	/**
	 * Persists data in the cache, uniquely referenced by a key with an optional expiration TTL time.
	 *
	 * @since 7.0.0
	 *
	 * @param string                $key   The key of the item to store.
	 * @param mixed                 $value The value of the item to store, must be serializable.
	 * @param null|int|DateInterval $ttl   Optional. The TTL value of this item.
	 * @return bool True on success and false on failure.
	 */
	public function set( $key, $value, $ttl = null ): bool {
		$expire = $this->ttl_to_seconds( $ttl );

		return set_transient( $this->transient_key( $key ), $value, $expire );
	}

	/**
	 * Delete an item from the cache by its unique key.
	 *
	 * @since 7.0.0
	 *
	 * @param string $key The unique cache key of the item to delete.
	 * @return bool True if the item was successfully removed. False if there was an error.
	 */
	public function delete( $key ): bool {
		return delete_transient( $this->transient_key( $key ) );
	}

	/**
	 * Wipes clean the entire cache's keys.
	 *
	 * Deletes every transient created by this adapter by matching the
	 * `_transient_{prefix}` and `_transient_timeout_{prefix}` option names.
	 *
	 * @since 7.0.0
	 *
	 * @return bool True on success and false on failure.
	 */
	public function clear(): bool {
		global $wpdb;

		$prefix = self::CACHE_GROUP . '_';
		$like   = $wpdb->esc_like( '_transient_' . $prefix ) . '%';
		$tlike  = $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$like,
				$tlike
			)
		);

		return false !== $deleted;
	}

	/**
	 * Obtains multiple cache items by their unique keys.
	 *
	 * @since 7.0.0
	 *
	 * @param iterable<string> $keys          A list of keys that can be obtained in a single operation.
	 * @param mixed            $default_value Default value to return for keys that do not exist.
	 * @return array<string, mixed> A list of key => value pairs.
	 */
	public function getMultiple( $keys, $default_value = null ) {
		/**
		 * Keys array.
		 *
		 * @var array<string> $keys_array
		 */
		$keys_array = $this->iterable_to_array( $keys );
		$result     = array();

		foreach ( $keys_array as $key ) {
			$result[ $key ] = $this->get( $key, $default_value );
		}

		return $result;
	}

	/**
	 * Persists a set of key => value pairs in the cache, with an optional TTL.
	 *
	 * @since 7.0.0
	 *
	 * @param iterable<string, mixed> $values A list of key => value pairs for a multiple-set operation.
	 * @param null|int|DateInterval   $ttl    Optional. The TTL value of this item.
	 * @return bool True on success and false on failure.
	 */
	public function setMultiple( $values, $ttl = null ): bool {
		$values_array = $this->iterable_to_array( $values );
		$expire       = $this->ttl_to_seconds( $ttl );
		$ok           = true;

		foreach ( $values_array as $key => $value ) {
			if ( ! set_transient( $this->transient_key( (string) $key ), $value, $expire ) ) {
				$ok = false;
			}
		}

		return $ok;
	}

	/**
	 * Deletes multiple cache items in a single operation.
	 *
	 * @since 7.0.0
	 *
	 * @param iterable<string> $keys A list of string-based keys to be deleted.
	 * @return bool True if the items were successfully removed. False if there was an error.
	 */
	public function deleteMultiple( $keys ): bool {
		$keys_array = $this->iterable_to_array( $keys );
		$ok         = true;

		foreach ( $keys_array as $key ) {
			if ( ! delete_transient( $this->transient_key( (string) $key ) ) ) {
				$ok = false;
			}
		}

		return $ok;
	}

	/**
	 * Determines whether an item is present in the cache.
	 *
	 * @since 7.0.0
	 *
	 * @param string $key The cache item key.
	 * @return bool True if the item exists in the cache, false otherwise.
	 */
	public function has( $key ): bool {
		return false !== get_transient( $this->transient_key( $key ) );
	}

	/**
	 * Builds the transient option name for a cache key.
	 *
	 * Prefixed with the cache group so {@see clear()} can target only this
	 * adapter's transients without touching unrelated options. Transient
	 * names are limited to 172 characters by WordPress core; SDK cache
	 * keys are short hashes so this prefix is comfortably within budget.
	 *
	 * @since 7.0.0
	 *
	 * @param string $key The PSR-16 cache key.
	 * @return string The fully-prefixed transient name.
	 */
	private function transient_key( string $key ): string {
		return self::CACHE_GROUP . '_' . $key;
	}

	/**
	 * Converts a PSR-16 TTL value to seconds for WordPress cache functions.
	 *
	 * @since 7.0.0
	 *
	 * @param null|int|DateInterval $ttl The TTL value.
	 * @return int The TTL in seconds, or 0 for no expiration.
	 */
	private function ttl_to_seconds( $ttl ): int {
		if ( null === $ttl ) {
			return 0;
		}

		if ( $ttl instanceof DateInterval ) {
			$now = new DateTime();
			$end = ( clone $now )->add( $ttl );

			return $end->getTimestamp() - $now->getTimestamp();
		}

		return max( 0, (int) $ttl );
	}

	/**
	 * Converts an iterable to an array.
	 *
	 * @since 7.0.0
	 *
	 * @param iterable<mixed> $items The iterable to convert.
	 * @return array<mixed> The array.
	 */
	private function iterable_to_array( $items ): array {
		if ( is_array( $items ) ) {
			return $items;
		}

		return iterator_to_array( $items );
	}
}
