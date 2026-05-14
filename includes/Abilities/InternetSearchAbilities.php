<?php

declare(strict_types=1);
/**
 * Internet search abilities for the AI agent.
 *
 * Provides web search capabilities so the agent can research topics and
 * produce well-sourced content (e.g. blog posts, product descriptions).
 *
 * Search provider priority (first configured provider wins):
 *   1. Tavily Search API  — best results for AI agents, purpose-built for LLMs.
 *   2. Brave Search API   — rich results (full snippets, news, videos).
 *   3. DuckDuckGo Instant Answer API — free, no API key required, always available.
 *
 * Configuration:
 *   - Tavily API key: Settings page → "Tavily API Key" field, or paste
 *     it into the chat and ask the agent to save it.
 *     Get a free key at https://app.tavily.com/
 *   - Brave API key: Settings page → "Brave Search API Key" field.
 *     Get a free key at https://brave.com/search/api/
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use SdAiAgent\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class InternetSearchAbilities {

	/**
	 * Tavily Search API endpoint.
	 */
	const TAVILY_SEARCH_URL = 'https://api.tavily.com/search';

	/**
	 * Brave Search API endpoint.
	 */
	const BRAVE_SEARCH_URL = 'https://api.search.brave.com/res/v1/web/search';

	/**
	 * DuckDuckGo Instant Answer API endpoint.
	 */
	const DDG_API_URL = 'https://api.duckduckgo.com/';

	/**
	 * Option name for the Brave Search API key.
	 * Stored separately from general settings to avoid credential leakage.
	 */
	const BRAVE_KEY_OPTION = 'sd_ai_agent_brave_search_key';

	/**
	 * Option name for the Tavily API key.
	 * Stored separately from general settings to avoid credential leakage.
	 */
	const TAVILY_KEY_OPTION = 'sd_ai_agent_tavily_api_key';

	/**
	 * Supported search providers with metadata.
	 * Used by the configure-search-provider ability and settings UI.
	 */
	const SEARCH_PROVIDERS = [
		'tavily'     => [
			'name'   => 'Tavily',
			'url'    => 'https://app.tavily.com/',
			'help'   => 'Purpose-built search API for AI agents. Free tier includes 1,000 searches/month.',
			'prefix' => 'tvly-',
		],
		'brave'      => [
			'name'   => 'Brave Search',
			'url'    => 'https://brave.com/search/api/',
			'help'   => 'Rich web search results with snippets, news, and videos. Free tier includes 2,000 queries/month.',
			'prefix' => 'BSA',
		],
		'duckduckgo' => [
			'name'   => 'DuckDuckGo',
			'url'    => '',
			'help'   => 'Free instant answers — no API key required. Limited to instant answers, not full web search.',
			'prefix' => '',
		],
	];

	/**
	 * Register the internet-search ability and the configure-search-provider ability.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/internet-search',
			[
				'label'               => __( 'Internet Search', 'superdav-ai-agent' ),
				'description'         => __( 'Search the internet for current information. Returns a list of relevant results with titles, URLs, and snippets. Use this to research topics before writing blog posts or answering questions about recent events. Provider priority: Tavily (if configured) > Brave (if configured) > DuckDuckGo (free fallback).', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'query'     => [
							'type'        => 'string',
							'description' => 'The search query (e.g. "best WordPress SEO plugins 2025", "how to make sourdough bread")',
						],
						'count'     => [
							'type'        => 'integer',
							'description' => 'Number of results to return (1–20, default: 10)',
						],
						'freshness' => [
							'type'        => 'string',
							'description' => 'Filter results by age: "pd" (past day), "pw" (past week), "pm" (past month), "py" (past year). Omit for all time.',
							'enum'        => [ 'pd', 'pw', 'pm', 'py' ],
						],
					],
					'required'   => [ 'query' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'results'  => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'title'   => [ 'type' => 'string' ],
									'url'     => [ 'type' => 'string' ],
									'snippet' => [ 'type' => 'string' ],
								],
							],
						],
						'provider' => [ 'type' => 'string' ],
						'query'    => [ 'type' => 'string' ],
						'answer'   => [
							'type'        => 'string',
							'description' => 'AI-generated answer summarising the search results (Tavily only; omitted when not available).',
						],
						'error'    => [ 'type' => 'string' ],
					],
				],
				'meta'                => [
					'mcp'         => [ 'public' => true ],
					'annotations' => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
				],
				'execute_callback'    => [ __CLASS__, 'handle_search' ],
				'permission_callback' => [ __CLASS__, 'check_permission' ],
			]
		);

		wp_register_ability(
			'sd-ai-agent/configure-search-provider',
			[
				'label'               => __( 'Configure Search Provider', 'superdav-ai-agent' ),
				'description'         => __( 'Save or remove an API key for an internet search provider (Tavily or Brave). Use this when the user provides an API key in the chat so they do not need to visit the settings page. Also use this to check which providers are currently configured.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'action'   => [
							'type'        => 'string',
							'description' => 'The action to perform: "save" to store a key, "remove" to clear a key, "status" to check configured providers.',
							'enum'        => [ 'save', 'remove', 'status' ],
						],
						'provider' => [
							'type'        => 'string',
							'description' => 'The search provider: "tavily" or "brave". Not required for "status" action.',
							'enum'        => [ 'tavily', 'brave' ],
						],
						'api_key'  => [
							'type'        => 'string',
							'description' => 'The API key to save. Required for "save" action.',
						],
					],
					'required'   => [ 'action' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'status'    => [ 'type' => 'string' ],
						'message'   => [ 'type' => 'string' ],
						'providers' => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'id'         => [ 'type' => 'string' ],
									'name'       => [ 'type' => 'string' ],
									'configured' => [ 'type' => 'boolean' ],
									'active'     => [ 'type' => 'boolean' ],
									'signup_url' => [ 'type' => 'string' ],
								],
							],
						],
					],
				],
				'meta'                => [
					'mcp'         => [ 'public' => true ],
					'annotations' => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					],
				],
				'execute_callback'    => [ __CLASS__, 'handle_configure_provider' ],
				'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			]
		);
	}

	/**
	 * Permission callback: any user who can edit posts may search the internet.
	 *
	 * @param mixed $input Unused.
	 * @return bool
	 */
	public static function check_permission( $input ): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Permission callback: only administrators may configure search providers.
	 *
	 * @param mixed $input Unused.
	 * @return bool
	 */
	public static function check_admin_permission( $input ): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Handle the internet-search ability call.
	 *
	 * Routes to the first configured provider: Tavily → Brave → DuckDuckGo.
	 *
	 * @param array<string,mixed> $input Input with query, optional count and freshness.
	 * @return array<string,mixed> Result with results array, provider, and query.
	 */
	public static function handle_search( array $input ): array {
		// @phpstan-ignore-next-line
		$query = sanitize_text_field( (string) ( $input['query'] ?? '' ) );
		// @phpstan-ignore-next-line
		$count = (int) ( $input['count'] ?? 10 );
		// @phpstan-ignore-next-line
		$freshness = isset( $input['freshness'] ) ? sanitize_key( (string) $input['freshness'] ) : '';

		if ( '' === $query ) {
			return [
				'results'  => [],
				'provider' => '',
				'query'    => '',
				'error'    => 'query is required.',
			];
		}

		$count = max( 1, min( 20, $count ) );

		// Provider priority: Tavily → Brave → DuckDuckGo.
		$tavily_key = self::get_tavily_api_key();
		if ( '' !== $tavily_key ) {
			return self::search_tavily( $query, $count, $freshness, $tavily_key );
		}

		$brave_key = self::get_brave_api_key();
		if ( '' !== $brave_key ) {
			return self::search_brave( $query, $count, $freshness, $brave_key );
		}

		return self::search_duckduckgo( $query, $count );
	}

	/**
	 * Handle the configure-search-provider ability call.
	 *
	 * @param array<string,mixed> $input Input with action, provider, and optional api_key.
	 * @return array<string,mixed> Status response.
	 */
	public static function handle_configure_provider( array $input ): array {
		$action   = sanitize_key( (string) ( $input['action'] ?? '' ) );
		$provider = sanitize_key( (string) ( $input['provider'] ?? '' ) );
		$api_key  = (string) ( $input['api_key'] ?? '' );

		if ( 'status' === $action ) {
			return self::get_provider_status();
		}

		if ( '' === $provider || ! in_array( $provider, [ 'tavily', 'brave' ], true ) ) {
			return [
				'status'  => 'error',
				'message' => 'provider must be "tavily" or "brave".',
			];
		}

		if ( 'save' === $action ) {
			$api_key = trim( $api_key );
			if ( '' === $api_key ) {
				$meta = self::SEARCH_PROVIDERS[ $provider ];
				return [
					'status'  => 'error',
					'message' => sprintf(
						'Please provide your %s API key. You can get one at %s',
						$meta['name'],
						$meta['url']
					),
				];
			}

			$success = 'tavily' === $provider
				? self::set_tavily_api_key( $api_key )
				: self::set_brave_api_key( $api_key );

			if ( ! $success ) {
				return [
					'status'  => 'error',
					'message' => 'Failed to save the API key.',
				];
			}

			$status = self::get_provider_status();
			return array_merge(
				$status,
				[
					'status'  => 'saved',
					'message' => sprintf(
						'%s API key saved successfully. It is now the active search provider.',
						self::SEARCH_PROVIDERS[ $provider ]['name']
					),
				]
				);
		}

		if ( 'remove' === $action ) {
			$success = 'tavily' === $provider
				? self::set_tavily_api_key( '' )
				: self::set_brave_api_key( '' );

			$status = self::get_provider_status();
			return array_merge(
				$status,
				[
					'status'  => 'removed',
					'message' => sprintf(
						'%s API key removed.',
						self::SEARCH_PROVIDERS[ $provider ]['name']
					),
				]
				);
		}

		return [
			'status'  => 'error',
			'message' => 'action must be "save", "remove", or "status".',
		];
	}

	/**
	 * Get the status of all search providers.
	 *
	 * @return array<string,mixed> Provider status including which is active.
	 */
	private static function get_provider_status(): array {
		$tavily_configured = '' !== self::get_tavily_api_key();
		$brave_configured  = '' !== self::get_brave_api_key();

		// Determine which provider is active (first configured wins).
		$active_provider = 'duckduckgo';
		if ( $tavily_configured ) {
			$active_provider = 'tavily';
		} elseif ( $brave_configured ) {
			$active_provider = 'brave';
		}

		$providers = [];
		foreach ( self::SEARCH_PROVIDERS as $id => $meta ) {
			$configured = false;
			if ( 'tavily' === $id ) {
				$configured = $tavily_configured;
			} elseif ( 'brave' === $id ) {
				$configured = $brave_configured;
			} else {
				$configured = true; // DuckDuckGo is always available.
			}

			$providers[] = [
				'id'         => $id,
				'name'       => $meta['name'],
				'configured' => $configured,
				'active'     => $id === $active_provider,
				'signup_url' => $meta['url'],
			];
		}

		return [
			'status'    => 'ok',
			'providers' => $providers,
		];
	}

	/**
	 * Search using the Tavily Search API.
	 *
	 * @param string $query     Search query.
	 * @param int    $count     Number of results.
	 * @param string $freshness Optional freshness filter (pd/pw/pm/py).
	 * @param string $api_key   Tavily API key.
	 * @return array<string,mixed> Results array.
	 */
	private static function search_tavily( string $query, int $count, string $freshness, string $api_key ): array {
		$body = [
			'query'       => $query,
			'max_results' => $count,
		];

		// Map freshness codes to Tavily time_range values.
		$freshness_map = [
			'pd' => 'day',
			'pw' => 'week',
			'pm' => 'month',
			'py' => 'year',
		];
		if ( '' !== $freshness && isset( $freshness_map[ $freshness ] ) ) {
			$body['time_range'] = $freshness_map[ $freshness ];
		}

		$response = wp_remote_post(
			self::TAVILY_SEARCH_URL,
			[
				'timeout' => 15,
				'headers' => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				],
				'body'    => (string) wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			// Fall through to next provider on network error.
			$brave_key = self::get_brave_api_key();
			if ( '' !== $brave_key ) {
				return self::search_brave( $query, $count, $freshness, $brave_key );
			}
			return self::search_duckduckgo( $query, $count );
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $status ) {
			// Fall through to next provider on API error (e.g. invalid key, quota exceeded).
			$brave_key = self::get_brave_api_key();
			if ( '' !== $brave_key ) {
				return self::search_brave( $query, $count, $freshness, $brave_key );
			}
			return self::search_duckduckgo( $query, $count );
		}

		$response_body = wp_remote_retrieve_body( $response );
		$data          = json_decode( $response_body, true );

		if ( ! is_array( $data ) ) {
			$brave_key = self::get_brave_api_key();
			if ( '' !== $brave_key ) {
				return self::search_brave( $query, $count, $freshness, $brave_key );
			}
			return self::search_duckduckgo( $query, $count );
		}

		$results = [];

		// Extract search results from Tavily response.
		$tavily_results = $data['results'] ?? [];
		if ( is_array( $tavily_results ) ) {
			foreach ( $tavily_results as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$results[] = [
					'title'   => (string) ( $item['title'] ?? '' ),
					'url'     => (string) ( $item['url'] ?? '' ),
					'snippet' => (string) ( $item['content'] ?? '' ),
				];
			}
		}

		$output = [
			'results'  => $results,
			'provider' => 'tavily',
			'query'    => $query,
		];

		// Include the AI-generated answer if present.
		$answer = (string) ( $data['answer'] ?? '' );
		if ( '' !== $answer ) {
			$output['answer'] = $answer;
		}

		return $output;
	}

	/**
	 * Search using the Brave Search API.
	 *
	 * @param string $query     Search query.
	 * @param int    $count     Number of results.
	 * @param string $freshness Optional freshness filter (pd/pw/pm/py).
	 * @param string $api_key   Brave Search API key.
	 * @return array<string,mixed> Results array.
	 */
	private static function search_brave( string $query, int $count, string $freshness, string $api_key ): array {
		$params = [
			'q'     => $query,
			'count' => $count,
		];

		if ( '' !== $freshness ) {
			$params['freshness'] = $freshness;
		}

		$url = add_query_arg( $params, self::BRAVE_SEARCH_URL );

		$response = wp_remote_get(
			$url,
			[
				'timeout' => 15,
				'headers' => [
					'Accept'               => 'application/json',
					'Accept-Encoding'      => 'gzip',
					'X-Subscription-Token' => $api_key,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			// Fall back to DuckDuckGo on network error.
			return self::search_duckduckgo( $query, $count );
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $status ) {
			// Fall back to DuckDuckGo on API error (e.g. invalid key, quota exceeded).
			return self::search_duckduckgo( $query, $count );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return self::search_duckduckgo( $query, $count );
		}

		$results = [];

		// Extract web results.
		$web_results = $data['web']['results'] ?? [];
		if ( is_array( $web_results ) ) {
			foreach ( $web_results as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$results[] = [
					'title'   => (string) ( $item['title'] ?? '' ),
					'url'     => (string) ( $item['url'] ?? '' ),
					'snippet' => (string) ( $item['description'] ?? '' ),
				];
			}
		}

		return [
			'results'  => $results,
			'provider' => 'brave',
			'query'    => $query,
		];
	}

	/**
	 * Search using the DuckDuckGo Instant Answer API.
	 *
	 * DuckDuckGo's API returns instant answers and related topics. It does not
	 * return a traditional list of web results, so we extract RelatedTopics
	 * and the AbstractText to build a useful result set.
	 *
	 * Note: DuckDuckGo's API is designed for instant answers, not full web
	 * search. For comprehensive research, configure a Tavily or Brave Search API key.
	 *
	 * @param string $query  Search query.
	 * @param int    $count  Maximum number of results to return.
	 * @return array<string,mixed> Results array.
	 */
	private static function search_duckduckgo( string $query, int $count ): array {
		$url = add_query_arg(
			[
				'q'             => $query,
				'format'        => 'json',
				'no_html'       => '1',
				'skip_disambig' => '1',
				'no_redirect'   => '1',
			],
			self::DDG_API_URL
		);

		$response = wp_remote_get(
			$url,
			[
				'timeout'    => 15,
				'user-agent' => 'SdAiAgent/1.0 (WordPress plugin; +https://wordpress.org/plugins/sd-ai-agent)',
			]
		);

		if ( is_wp_error( $response ) ) {
			return [
				'results'  => [],
				'provider' => 'duckduckgo',
				'query'    => $query,
				'error'    => 'Search request failed: ' . $response->get_error_message(),
			];
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return [
				'results'  => [],
				'provider' => 'duckduckgo',
				'query'    => $query,
				'error'    => 'Invalid response from search provider.',
			];
		}

		$results = [];

		// Include the abstract (top answer) if available.
		$abstract_text = (string) ( $data['AbstractText'] ?? '' );
		$abstract_url  = (string) ( $data['AbstractURL'] ?? '' );
		$abstract_src  = (string) ( $data['AbstractSource'] ?? '' );

		if ( '' !== $abstract_text && '' !== $abstract_url ) {
			$results[] = [
				'title'   => '' !== $abstract_src ? $abstract_src : $query,
				'url'     => $abstract_url,
				'snippet' => $abstract_text,
			];
		}

		// Extract related topics.
		$related_topics = $data['RelatedTopics'] ?? [];
		if ( is_array( $related_topics ) ) {
			foreach ( $related_topics as $topic ) {
				if ( count( $results ) >= $count ) {
					break;
				}

				if ( ! is_array( $topic ) ) {
					continue;
				}

				// Skip topic groups (they have a 'Topics' key instead of 'Text').
				if ( isset( $topic['Topics'] ) ) {
					// Flatten one level of topic groups.
					foreach ( $topic['Topics'] as $sub_topic ) {
						if ( count( $results ) >= $count ) {
							break;
						}
						if ( ! is_array( $sub_topic ) ) {
							continue;
						}
						$result = self::extract_ddg_topic( $sub_topic );
						if ( null !== $result ) {
							$results[] = $result;
						}
					}
					continue;
				}

				$result = self::extract_ddg_topic( $topic );
				if ( null !== $result ) {
					$results[] = $result;
				}
			}
		}

		// If DuckDuckGo returned no useful results, provide a helpful message.
		if ( empty( $results ) ) {
			return [
				'results'  => [],
				'provider' => 'duckduckgo',
				'query'    => $query,
				'tip'      => 'DuckDuckGo returned no instant answers for this query. For comprehensive web search results, configure a Tavily or Brave Search API key in settings (or paste your API key here and ask me to save it).',
			];
		}

		return [
			'results'  => $results,
			'provider' => 'duckduckgo',
			'query'    => $query,
		];
	}

	/**
	 * Extract a result entry from a DuckDuckGo RelatedTopics item.
	 *
	 * @param array<string,mixed> $topic DuckDuckGo topic array.
	 * @return array<string,string>|null Result array or null if the topic is unusable.
	 */
	private static function extract_ddg_topic( array $topic ): ?array {
		$text      = (string) ( $topic['Text'] ?? '' );
		$first_url = (string) ( $topic['FirstURL'] ?? '' );

		if ( '' === $text || '' === $first_url ) {
			return null;
		}

		// Extract a title from the text (first sentence or up to 80 chars).
		$title = $text;
		$dot   = strpos( $text, '. ' );
		if ( false !== $dot && $dot < 80 ) {
			$title = substr( $text, 0, $dot );
		} elseif ( strlen( $text ) > 80 ) {
			$title = substr( $text, 0, 77 ) . '...';
		}

		return [
			'title'   => $title,
			'url'     => $first_url,
			'snippet' => $text,
		];
	}

	/**
	 * Get the configured Tavily API key.
	 *
	 * @return string Empty string when not configured.
	 */
	public static function get_tavily_api_key(): string {
		$key = get_option( self::TAVILY_KEY_OPTION, '' );
		return is_string( $key ) ? trim( $key ) : '';
	}

	/**
	 * Persist the Tavily API key.
	 *
	 * Pass an empty string to clear the key.
	 *
	 * @param string $api_key The Tavily API key.
	 * @return bool True on success.
	 */
	public static function set_tavily_api_key( string $api_key ): bool {
		if ( '' === $api_key ) {
			return delete_option( self::TAVILY_KEY_OPTION );
		}
		return update_option( self::TAVILY_KEY_OPTION, $api_key );
	}

	/**
	 * Get the configured Brave Search API key.
	 *
	 * @return string Empty string when not configured.
	 */
	public static function get_brave_api_key(): string {
		$key = get_option( self::BRAVE_KEY_OPTION, '' );
		return is_string( $key ) ? trim( $key ) : '';
	}

	/**
	 * Persist the Brave Search API key.
	 *
	 * Pass an empty string to clear the key.
	 *
	 * @param string $api_key The Brave Search API key.
	 * @return bool True on success.
	 */
	public static function set_brave_api_key( string $api_key ): bool {
		if ( '' === $api_key ) {
			return delete_option( self::BRAVE_KEY_OPTION );
		}
		return update_option( self::BRAVE_KEY_OPTION, $api_key );
	}
}
