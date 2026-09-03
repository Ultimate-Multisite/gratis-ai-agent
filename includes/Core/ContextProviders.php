<?php

declare(strict_types=1);
/**
 * Context provider registry.
 *
 * Gathers structured context from various WordPress sources for
 * injection into the agent's system prompt.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

class ContextProviders {

	/**
	 * Registered providers: name => ['callback' => callable, 'priority' => int].
	 *
	 * @var array<string, array{callback: callable, priority: int}>
	 */
	private static array $providers = [];

	/**
	 * Whether built-in providers have been registered.
	 *
	 * @var bool
	 */
	private static bool $initialized = false;

	/**
	 * Register a context provider.
	 *
	 * @param string   $name     Provider name.
	 * @param callable $callback Receives page_context array, returns array of context data.
	 * @param int      $priority Lower runs first.
	 */
	public static function register( string $name, callable $callback, int $priority = 10 ): void {
		self::$providers[ $name ] = [
			'callback' => $callback,
			'priority' => $priority,
		];
	}

	/**
	 * Gather context from all registered providers.
	 *
	 * @param array<int|string, mixed> $page_context Page context from the widget JS (URL, admin page, post ID, etc.).
	 * @return array<string, mixed> Keyed array of context sections.
	 */
	public static function gather( array $page_context = [] ): array {
		self::ensure_initialized();

		// Sort providers by priority.
		uasort(
			self::$providers,
			function ( $a, $b ) {
				return $a['priority'] <=> $b['priority'];
			}
		);

		$context = [];

		foreach ( self::$providers as $name => $provider ) {
			try {
				$data = call_user_func( $provider['callback'], $page_context );
				if ( ! empty( $data ) ) {
					$context[ $name ] = $data;
				}
			} catch ( \Throwable $e ) {
				// Context gathering is best-effort.
				continue;
			}
		}

		return $context;
	}

	/**
	 * Format gathered context for inclusion in a system prompt.
	 *
	 * @param array<string, mixed> $context The gathered context data.
	 * @return string Markdown-formatted context string.
	 */
	public static function format_for_prompt( array $context ): string {
		if ( empty( $context ) ) {
			return '';
		}

		$sections = [];

		foreach ( $context as $name => $data ) {
			if ( empty( $data ) ) {
				continue;
			}

			$label = ucwords( str_replace( '_', ' ', $name ) );
			$lines = [];

			if ( is_array( $data ) ) {
				foreach ( $data as $key => $value ) {
					if ( is_array( $value ) ) {
						$value = implode( ', ', $value );
					}
					// @phpstan-ignore-next-line
					$lines[] = "- **{$key}**: {$value}";
				}
			} else {
				// @phpstan-ignore-next-line
				$lines[] = (string) $data;
			}

			if ( ! empty( $lines ) ) {
				$sections[] = "### {$label}\n" . implode( "\n", $lines );
			}
		}

		if ( empty( $sections ) ) {
			return '';
		}

		return "## Current Context\n\n" . implode( "\n\n", $sections );
	}

	/**
	 * Register built-in context providers.
	 */
	private static function ensure_initialized(): void {
		if ( self::$initialized ) {
			return;
		}

		self::$initialized = true;

		// Page context — pass through from widget JS.
		self::register( 'page_context', [ __CLASS__, 'provide_page_context' ], 5 );

		// User context.
		self::register( 'user_context', [ __CLASS__, 'provide_user_context' ], 10 );

		// Site context.
		self::register( 'site_context', [ __CLASS__, 'provide_site_context' ], 15 );

		// Post context — if on a post edit screen.
		self::register( 'post_context', [ __CLASS__, 'provide_post_context' ], 20 );

		// System context.
		self::register( 'system_context', [ __CLASS__, 'provide_system_context' ], 25 );

		// SEO context.
		self::register( 'seo_context', [ __CLASS__, 'provide_seo_context' ], 30 );

		// Block editor context.
		self::register( 'block_editor_context', [ __CLASS__, 'provide_block_editor_context' ], 35 );

		// WooCommerce store context — only when WooCommerce is active.
		if ( class_exists( 'WooCommerce' ) ) {
			self::register( 'woocommerce_context', [ __CLASS__, 'provide_woocommerce_context' ], 40 );
		}
	}

	/**
	 * Provide page context from the widget.
	 *
	 * @param array<string, mixed> $page_context Raw page context from JS.
	 * @return array<string, mixed>
	 */
	public static function provide_page_context( array $page_context ): array {
		$data = [];

		// String context from screen-meta (wrapped in { summary: "..." } by the JS store).
		if ( ! empty( $page_context['summary'] ) ) {
			$data['Page Context'] = $page_context['summary'];
		}

		if ( ! empty( $page_context['url'] ) ) {
			$data['Current URL'] = $page_context['url'];
		}

		if ( ! empty( $page_context['surface'] ) ) {
			$data['Surface'] = $page_context['surface'];
		}

		if ( ! empty( $page_context['is_frontend'] ) ) {
			$data['User Is Viewing Frontend'] = 'Yes';
		}

		if ( ! empty( $page_context['page_title'] ) ) {
			$data['Visible Page Title'] = $page_context['page_title'];
		}

		if ( ! empty( $page_context['post_id'] ) ) {
			$data['Visible Post ID'] = (string) (int) $page_context['post_id'];
		}

		if ( ! empty( $page_context['post_type'] ) ) {
			$data['Visible Post Type'] = $page_context['post_type'];
		}

		if ( ! empty( $page_context['live_preview'] ) && is_array( $page_context['live_preview'] ) ) {
			$live_preview = $page_context['live_preview'];
			if ( ! empty( $live_preview['affected_descriptor_supported'] ) ) {
				$data['Live Preview Affected Descriptor Supported'] = 'Yes';
			}
			if ( ! empty( $live_preview['requires_refresh_when_missing_affected'] ) ) {
				$data['Refresh Required When Affected Missing'] = 'Yes';
			}
			if ( ! empty( $live_preview['refresh_tool'] ) ) {
				$data['Refresh Tool'] = $live_preview['refresh_tool'];
			}
		}

		if ( ! empty( $page_context['admin_page'] ) ) {
			$data['Admin Page'] = $page_context['admin_page'];
		}

		if ( ! empty( $page_context['screen_id'] ) ) {
			$data['Screen ID'] = $page_context['screen_id'];
		}

		return $data;
	}

	/**
	 * Provide current user context.
	 *
	 * @param array<string, mixed> $page_context Unused.
	 * @return array<string, mixed>
	 */
	public static function provide_user_context( array $page_context ): array {
		$user    = wp_get_current_user();
		$locales = ( new SpeechLocaleResolver() )->resolve();

		if ( ! $user || ! $user->exists() ) {
			return [];
		}

		$data = [
			'Name'  => $user->display_name,
			'Login' => $user->user_login,
			'Email' => $user->user_email,
			'Roles' => implode( ', ', $user->roles ),
		];
		if ( '' !== $locales['user_locale'] ) {
			$data['Preferred User Language'] = $locales['user_locale'];
			$data['Language Guidance']       = 'Treat the preferred language as an initial hint. Follow the language the user actually uses when it differs.';
		}

		return $data;
	}

	/**
	 * Provide site context.
	 *
	 * @param array<string, mixed> $page_context Unused.
	 * @return array<string, mixed>
	 */
	public static function provide_site_context( array $page_context ): array {
		global $wp_version;

		$theme   = wp_get_theme();
		$locales = ( new SpeechLocaleResolver() )->resolve();
		// @phpstan-ignore-next-line
		$plugin_count = count( get_option( 'active_plugins', [] ) );

		$data = [
			'Site Name'      => get_bloginfo( 'name' ),
			'Site URL'       => get_site_url(),
			'WP Version'     => $wp_version,
			'Theme'          => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
			'Active Plugins' => (string) $plugin_count,
		];
		if ( '' !== $locales['site_locale'] ) {
			$data['Site Language'] = $locales['site_locale'];
		}

		if ( is_multisite() ) {
			$is_subdomain      = function_exists( 'is_subdomain_install' ) && is_subdomain_install();
			$data['Multisite'] = $is_subdomain ? 'Yes (subdomain install)' : 'Yes (subdirectory install)';

			// Surface the network root domain so the agent can compose
			// subsite hostnames without guessing.
			$network = function_exists( 'get_current_site' ) ? get_current_site() : null;
			if ( $network && ! empty( $network->domain ) ) {
				$data['Network Domain'] = (string) $network->domain;
			}

			if ( $is_subdomain ) {
				$data['New Subsite Convention'] = 'Use a subdomain like "<slug>.' . ( $network->domain ?? 'example.com' ) . '". For multisite-ultimate/site-create-item, just pass the slug as `path` and wu_create_site() will convert it to the correct subdomain automatically.';
			} else {
				$data['New Subsite Convention'] = 'Use a subdirectory like "/<slug>/". For multisite-ultimate/site-create-item, pass `path: "/<slug>/"`.';
			}
		}

		return $data;
	}

	/**
	 * Provide post context if on a post edit screen.
	 *
	 * @param array<string, mixed> $page_context Page context from widget.
	 * @return array<string, mixed>
	 */
	public static function provide_post_context( array $page_context ): array {
		$post_id = $page_context['post_id'] ?? 0;

		if ( ! $post_id ) {
			return [];
		}

		// @phpstan-ignore-next-line
		$post = get_post( (int) $post_id );

		if ( ! $post ) {
			return [];
		}

		$data = [
			'Post ID' => (string) $post->ID,
			'Title'   => $post->post_title,
			'Type'    => $post->post_type,
			'Status'  => $post->post_status,
			'Author'  => get_the_author_meta( 'display_name', (int) $post->post_author ),
		];

		$categories = wp_get_post_categories( $post->ID, [ 'fields' => 'names' ] );
		if ( ! is_wp_error( $categories ) && ! empty( $categories ) ) {
			$data['Categories'] = $categories;
		}

		$tags = wp_get_post_tags( $post->ID, [ 'fields' => 'names' ] );
		if ( ! is_wp_error( $tags ) && ! empty( $tags ) ) {
			$data['Tags'] = $tags;
		}

		return $data;
	}

	/**
	 * Provide SEO context — active SEO plugin, sitemap URL, permalink structure.
	 *
	 * @param array<string, mixed> $page_context Page context from widget.
	 * @return array<string, mixed>
	 */
	public static function provide_seo_context( array $page_context ): array {
		$data = [];

		// Detect active SEO plugin.
		$seo_plugins = [
			'wordpress-seo/wp-seo.php'                    => 'Yoast SEO',
			'wordpress-seo-premium/wp-seo-premium.php'    => 'Yoast SEO Premium',
			'seo-by-rank-math/rank-math.php'              => 'Rank Math',
			'all-in-one-seo-pack/all_in_one_seo_pack.php' => 'All in One SEO',
			'wp-seopress/seopress.php'                    => 'SEOPress',
			'autodescription/autodescription.php'         => 'The SEO Framework',
		];

		$active_plugins = get_option( 'active_plugins', [] );
		$seo_plugin     = 'None detected';

		foreach ( $seo_plugins as $file => $name ) {
			// @phpstan-ignore-next-line
			if ( in_array( $file, $active_plugins, true ) ) {
				$seo_plugin = $name;
				break;
			}
		}

		$data['SEO Plugin']          = $seo_plugin;
		$data['Permalink Structure'] = get_option( 'permalink_structure' ) ?: 'Plain (default)';

		// Sitemap URL guess based on SEO plugin.
		$site_url = get_site_url();
		if ( str_contains( $seo_plugin, 'Yoast' ) ) {
			$data['Sitemap URL'] = $site_url . '/sitemap_index.xml';
		} elseif ( str_contains( $seo_plugin, 'Rank Math' ) ) {
			$data['Sitemap URL'] = $site_url . '/sitemap_index.xml';
		} else {
			$data['Sitemap URL'] = $site_url . '/sitemap.xml';
		}

		// Post-specific SEO meta if on a post edit screen.
		$post_id = $page_context['post_id'] ?? 0;
		if ( $post_id ) {
			// @phpstan-ignore-next-line
			$focus_kw = get_post_meta( (int) $post_id, '_yoast_wpseo_focuskw', true );
			// @phpstan-ignore-next-line
			$meta_desc = get_post_meta( (int) $post_id, '_yoast_wpseo_metadesc', true );

			if ( empty( $focus_kw ) ) {
				// @phpstan-ignore-next-line
				$focus_kw = get_post_meta( (int) $post_id, 'rank_math_focus_keyword', true );
			}
			if ( empty( $meta_desc ) ) {
				// @phpstan-ignore-next-line
				$meta_desc = get_post_meta( (int) $post_id, 'rank_math_description', true );
			}

			if ( $focus_kw ) {
				$data['Focus Keyword'] = $focus_kw;
			}
			if ( $meta_desc ) {
				$data['SEO Meta Description'] = $meta_desc;
			}
		}

		return $data;
	}

	/**
	 * Provide block editor context — theme type, registered blocks, patterns.
	 *
	 * @param array<string, mixed> $page_context Unused.
	 * @return array<string, mixed>
	 */
	public static function provide_block_editor_context( array $page_context ): array {
		$data = [];

		if ( function_exists( 'wp_is_block_theme' ) ) {
			$data['Block Theme'] = wp_is_block_theme()
				? 'Yes (Full Site Editing)'
				: 'No (Classic theme)';
		}

		if ( class_exists( 'WP_Block_Type_Registry' ) ) {
			$data['Registered Blocks'] = (string) count(
				\WP_Block_Type_Registry::get_instance()->get_all_registered()
			);
		}

		if ( class_exists( 'WP_Block_Patterns_Registry' ) ) {
			$data['Block Patterns'] = (string) count(
				\WP_Block_Patterns_Registry::get_instance()->get_all_registered()
			);
		}

		return $data;
	}

	/**
	 * Provide system context.
	 *
	 * @param array<string, mixed> $page_context Unused.
	 * @return array<string, mixed>
	 */
	public static function provide_system_context( array $page_context ): array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$data = [
			'PHP Version'  => PHP_VERSION,
			'Memory Limit' => ini_get( 'memory_limit' ),
		];

		if ( method_exists( $wpdb, 'db_server_info' ) ) {
			$data['MySQL Version'] = $wpdb->db_server_info();
		} elseif ( method_exists( $wpdb, 'db_version' ) ) {
			$data['MySQL Version'] = $wpdb->db_version();
		}

		if ( ! empty( $_SERVER['SERVER_SOFTWARE'] ) ) {
			// @phpstan-ignore-next-line
			$data['Server'] = sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) );
		}

		return $data;
	}

	/**
	 * Provide WooCommerce store context when WooCommerce is active.
	 *
	 * Surfaces store type, product count, order counts, and currency so the
	 * agent is aware it is operating in an e-commerce context and can offer
	 * relevant assistance (product creation, order management, etc.).
	 *
	 * @param array<string, mixed> $page_context Unused.
	 * @return array<string, mixed>
	 */
	public static function provide_woocommerce_context( array $page_context ): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return [];
		}

		$data = [
			'WooCommerce Active'  => 'Yes',
			'WooCommerce Version' => defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown',
		];

		// Currency.
		if ( function_exists( 'get_woocommerce_currency' ) ) {
			$data['Store Currency'] = get_woocommerce_currency();
		}

		// Product counts.
		$product_counts = wp_count_posts( 'product' );
		if ( $product_counts ) {
			$data['Published Products'] = (string) ( $product_counts->publish ?? 0 );
			$draft_count                = (int) ( $product_counts->draft ?? 0 );
			if ( $draft_count > 0 ) {
				$data['Draft Products'] = (string) $draft_count;
			}
		}

		// Order counts.
		if ( function_exists( 'wc_orders_count' ) ) {
			$processing = (int) wc_orders_count( 'processing' );
			$pending    = (int) wc_orders_count( 'pending' );
			if ( $processing > 0 ) {
				$data['Processing Orders'] = (string) $processing;
			}
			if ( $pending > 0 ) {
				$data['Pending Orders'] = (string) $pending;
			}
		}

		// Shop page URL.
		$shop_page_id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'shop' ) : 0;
		if ( $shop_page_id > 0 ) {
			$data['Shop URL'] = get_permalink( $shop_page_id ) ?: '';
		}

		return $data;
	}
}
