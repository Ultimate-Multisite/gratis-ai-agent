<?php

declare(strict_types=1);
/**
 * Tool Result Truncator.
 *
 * Truncates large tool results before they are added to conversation history.
 * This prevents context bloat in multi-step agentic workflows where tool
 * results (plugin lists, content analyses, database queries) can consume
 * thousands of tokens that crowd out the user's intent.
 *
 * Strategy:
 * - Small results (< threshold): pass through unchanged.
 * - Large results: summarize arrays by keeping first N items + count,
 *   truncate long string values, and strip verbose metadata.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ToolResultTruncator {

	/**
	 * Maximum JSON-encoded size (in bytes) before truncation kicks in.
	 * ~4KB is roughly 1000 tokens — a reasonable budget per tool result.
	 */
	const MAX_RESULT_BYTES = 4096;

	/**
	 * Maximum number of items to keep in array results.
	 */
	const MAX_ARRAY_ITEMS = 10;

	/**
	 * Maximum length for individual string values within results.
	 */
	const MAX_STRING_LENGTH = 500;

	/**
	 * Truncate a tool result if it exceeds the size threshold.
	 *
	 * @param mixed  $result    The raw tool result (array, string, or scalar).
	 * @param string $tool_name The tool name (for tool-specific strategies).
	 * @return mixed The possibly-truncated result.
	 */
	public static function truncate( $result, string $tool_name = '' ) {
		if ( is_string( $result ) && self::is_woocommerce_order_tool( $tool_name ) ) {
			$decoded = json_decode( $result, true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				$redacted = self::apply_tool_strategy( $decoded, $tool_name );
				$encoded  = wp_json_encode( $redacted );

				return is_string( $encoded ) ? $encoded : $result;
			}
		}

		if ( ! is_array( $result ) ) {
			return $result;
		}

		if ( self::is_woocommerce_order_tool( $tool_name ) ) {
			return self::apply_tool_strategy( $result, $tool_name );
		}

		// Check if the result is small enough to pass through.
		$encoded = wp_json_encode( $result );
		if ( false === $encoded || strlen( $encoded ) <= self::MAX_RESULT_BYTES ) {
			return $result;
		}

		// Apply tool-specific truncation strategies.
		// @phpstan-ignore-next-line
		$result = self::apply_tool_strategy( $result, $tool_name );

		// Generic truncation: truncate arrays and long strings.
		$result = self::truncate_recursive( $result, 0 );

		return $result;
	}

	/**
	 * Apply tool-specific truncation strategies.
	 *
	 * @param array<string|int, mixed> $result    The tool result.
	 * @param string                   $tool_name The tool name.
	 * @return array<string|int, mixed> The truncated result.
	 */
	private static function apply_tool_strategy( array $result, string $tool_name ): array {
		switch ( $tool_name ) {
			case 'woocommerce/orders-list':
			case 'woocommerce/orders-get':
				$result = self::scrub_woocommerce_order_result( $result );
				break;

			// Plugin list: keep name + active status + file, drop description/version details.
			case 'sd-ai-agent/get-plugins':
				if ( isset( $result['plugins'] ) && is_array( $result['plugins'] ) ) {
					$total                = count( $result['plugins'] );
					$result['plugins']    = array_map(
						function ( $plugin ) {
							return [
								// @phpstan-ignore-next-line
								'name'   => $plugin['name'] ?? '',
								// @phpstan-ignore-next-line
								'active' => $plugin['active'] ?? false,
								// @phpstan-ignore-next-line
								'file'   => $plugin['file'] ?? '',
							];
						},
						array_slice( $result['plugins'], 0, self::MAX_ARRAY_ITEMS )
					);
					$result['total']      = $total;
					$result['_truncated'] = $total > self::MAX_ARRAY_ITEMS;
				}
				break;

			// Content analysis: keep summary stats, truncate per-post details.
			case 'sd-ai-agent/content-analyze':
				if ( isset( $result['posts_without_featured_image'] ) && is_array( $result['posts_without_featured_image'] ) ) {
					$count                                  = count( $result['posts_without_featured_image'] );
					$result['posts_without_featured_image'] = array_slice( $result['posts_without_featured_image'], 0, 5 );
					if ( $count > 5 ) {
						$result['posts_without_featured_image_total'] = $count;
					}
				}
				if ( isset( $result['posts_without_meta_description'] ) && is_array( $result['posts_without_meta_description'] ) ) {
					$count                                    = count( $result['posts_without_meta_description'] );
					$result['posts_without_meta_description'] = array_slice( $result['posts_without_meta_description'], 0, 5 );
					if ( $count > 5 ) {
						$result['posts_without_meta_description_total'] = $count;
					}
				}
				break;

			// Database queries: truncate row data.
			case 'sd-ai-agent/db-query':
				if ( isset( $result['rows'] ) && is_array( $result['rows'] ) ) {
					$total             = count( $result['rows'] );
					$result['rows']    = array_slice( $result['rows'], 0, self::MAX_ARRAY_ITEMS );
					$result['total']   = $total;
					$result['showing'] = min( $total, self::MAX_ARRAY_ITEMS );
				}
				break;

			// Block type listings: keep names only.
			case 'sd-ai-agent/list-block-types':
				if ( isset( $result['block_types'] ) && is_array( $result['block_types'] ) ) {
					$total                 = count( $result['block_types'] );
					$result['block_types'] = array_map(
						function ( $bt ) {
							return [
								// @phpstan-ignore-next-line
								'name'     => $bt['name'] ?? '',
								// @phpstan-ignore-next-line
								'title'    => $bt['title'] ?? '',
								// @phpstan-ignore-next-line
								'category' => $bt['category'] ?? '',
							];
						},
						array_slice( $result['block_types'], 0, self::MAX_ARRAY_ITEMS )
					);
					$result['_truncated']  = $total > self::MAX_ARRAY_ITEMS;
				}
				break;

			// Navigation / page HTML: truncate the HTML body.
			case 'sd-ai-agent/navigate':
			case 'sd-ai-agent/get-page-html':
				if ( isset( $result['html'] ) && is_string( $result['html'] ) && strlen( $result['html'] ) > self::MAX_STRING_LENGTH ) {
					$result['html']       = substr( $result['html'], 0, self::MAX_STRING_LENGTH ) . '... [truncated]';
					$result['_truncated'] = true;
				}
				if ( isset( $result['content'] ) && is_string( $result['content'] ) && strlen( $result['content'] ) > self::MAX_STRING_LENGTH ) {
					$result['content']    = substr( $result['content'], 0, self::MAX_STRING_LENGTH ) . '... [truncated]';
					$result['_truncated'] = true;
				}
				break;
		}

		return $result;
	}

	/**
	 * Check whether a tool returns WooCommerce order data with customer PII.
	 *
	 * @param string $tool_name Ability name.
	 * @return bool
	 */
	private static function is_woocommerce_order_tool( string $tool_name ): bool {
		return in_array( $tool_name, array( 'woocommerce/orders-list', 'woocommerce/orders-get' ), true );
	}

	/**
	 * Scrub WooCommerce order payloads down to AI-safe audit fields.
	 *
	 * @param array<string|int, mixed> $result WooCommerce order result.
	 * @return array<string|int, mixed> Redacted result.
	 */
	private static function scrub_woocommerce_order_result( array $result ): array {
		if ( self::looks_like_woocommerce_order( $result ) ) {
			return self::summarize_woocommerce_order( $result );
		}

		$scrubbed = array();
		foreach ( $result as $key => $value ) {
			if ( is_string( $key ) && self::is_sensitive_woocommerce_order_key( $key ) ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$scrubbed[ $key ] = self::scrub_woocommerce_order_result( $value );
			} else {
				$scrubbed[ $key ] = $value;
			}
		}

		return $scrubbed;
	}

	/**
	 * Detect a WooCommerce order object/array.
	 *
	 * @param array<string|int, mixed> $value Candidate value.
	 * @return bool
	 */
	private static function looks_like_woocommerce_order( array $value ): bool {
		return isset( $value['id'] ) && ( isset( $value['status'] ) || isset( $value['billing'] ) || isset( $value['shipping'] ) || isset( $value['line_items'] ) );
	}

	/**
	 * Reduce a WooCommerce order to fields needed for non-PII summaries.
	 *
	 * @param array<string|int, mixed> $order Raw WooCommerce order.
	 * @return array<string, mixed> Minimized order.
	 */
	private static function summarize_woocommerce_order( array $order ): array {
		$allowed_order_keys = array(
			'id',
			'number',
			'parent_id',
			'status',
			'currency',
			'currency_symbol',
			'date_created',
			'date_created_gmt',
			'date_modified',
			'date_modified_gmt',
			'date_completed',
			'date_completed_gmt',
			'date_paid',
			'date_paid_gmt',
			'total',
			'total_tax',
			'discount_total',
			'discount_tax',
			'shipping_total',
			'shipping_tax',
			'cart_tax',
			'customer_id',
			'created_via',
			'prices_include_tax',
			'line_items',
			'tax_lines',
			'shipping_lines',
			'fee_lines',
			'coupon_lines',
			'refunds',
		);

		$summary = array();
		foreach ( $allowed_order_keys as $key ) {
			if ( array_key_exists( $key, $order ) ) {
				$summary[ $key ] = self::summarize_woocommerce_order_field( $key, $order[ $key ] );
			}
		}

		$summary['_redacted'] = true;

		return $summary;
	}

	/**
	 * Summarize nested WooCommerce order collections.
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Field value.
	 * @return mixed Minimized value.
	 */
	private static function summarize_woocommerce_order_field( string $key, $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( 'line_items' === $key ) {
			return self::summarize_woocommerce_line_items( $value );
		}

		if ( in_array( $key, array( 'tax_lines', 'shipping_lines', 'fee_lines', 'coupon_lines', 'refunds' ), true ) ) {
			return self::scrub_woocommerce_order_result( $value );
		}

		return $value;
	}

	/**
	 * Keep only product summary data from WooCommerce order line items.
	 *
	 * @param array<string|int, mixed> $items Raw line items.
	 * @return array<string|int, mixed> Minimized line items.
	 */
	private static function summarize_woocommerce_line_items( array $items ): array {
		$allowed_line_item_keys = array( 'id', 'name', 'product_id', 'variation_id', 'quantity', 'sku', 'subtotal', 'subtotal_tax', 'total', 'total_tax' );
		$summary                = array();

		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) ) {
				$summary[ $index ] = $item;
				continue;
			}

			$line = array();
			foreach ( $allowed_line_item_keys as $key ) {
				if ( array_key_exists( $key, $item ) ) {
					$line[ $key ] = $item[ $key ];
				}
			}

			$summary[ $index ] = $line;
		}

		return $summary;
	}

	/**
	 * Check for WooCommerce order keys that can expose customer/contact secrets.
	 *
	 * @param string $key Result key.
	 * @return bool
	 */
	private static function is_sensitive_woocommerce_order_key( string $key ): bool {
		$sensitive_keys = array(
			'billing',
			'shipping',
			'email',
			'phone',
			'address_1',
			'address_2',
			'city',
			'state',
			'postcode',
			'country',
			'first_name',
			'last_name',
			'company',
			'customer_ip_address',
			'customer_user_agent',
			'transaction_id',
			'order_key',
			'cart_hash',
			'payment_url',
		);

		return in_array( strtolower( $key ), $sensitive_keys, true );
	}

	/**
	 * Recursively truncate arrays and long strings.
	 *
	 * @param mixed $value The value to truncate.
	 * @param int   $depth Current recursion depth.
	 * @return mixed The truncated value.
	 */
	private static function truncate_recursive( $value, int $depth ) {
		// Don't recurse too deep.
		if ( $depth > 5 ) {
			return is_string( $value ) ? self::truncate_string( $value ) : $value;
		}

		if ( is_string( $value ) ) {
			return self::truncate_string( $value );
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		// Truncate sequential arrays (lists).
		if ( array_is_list( $value ) && count( $value ) > self::MAX_ARRAY_ITEMS ) {
			$total   = count( $value );
			$value   = array_slice( $value, 0, self::MAX_ARRAY_ITEMS );
			$value[] = sprintf( '... and %d more items', $total - self::MAX_ARRAY_ITEMS );
		}

		// Recurse into each element.
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::truncate_recursive( $item, $depth + 1 );
		}

		return $value;
	}

	/**
	 * Truncate a string if it exceeds the max length.
	 *
	 * @param string $str The string to truncate.
	 * @return string The possibly-truncated string.
	 */
	private static function truncate_string( string $str ): string {
		if ( strlen( $str ) <= self::MAX_STRING_LENGTH ) {
			return $str;
		}

		return substr( $str, 0, self::MAX_STRING_LENGTH ) . '... [truncated]';
	}
}
