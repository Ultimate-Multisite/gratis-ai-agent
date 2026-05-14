<?php

declare(strict_types=1);
/**
 * SEO analysis abilities for the AI agent.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SeoAbilities {

	/**
	 * Register SEO abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/seo-audit-url',
			[
				'label'               => __( 'SEO Audit URL', 'superdav-ai-agent' ),
				'description'         => __( 'Fetch a URL and analyze its SEO elements: title, meta description, headings, images, Open Graph, structured data, and common issues.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'url'      => [
							'type'        => 'string',
							'description' => 'The URL to audit (e.g. "https://example.com/page").',
						],
						'site_url' => [
							'type'        => 'string',
							'description' => 'Subsite URL context for multisite. Omit for the main site.',
						],
					],
					'required'   => [ 'url' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'url'                     => [ 'type' => 'string' ],
						'status_code'             => [ 'type' => 'integer' ],
						'title'                   => [ 'type' => 'string' ],
						'title_length'            => [ 'type' => 'integer' ],
						'meta_description'        => [ 'type' => 'string' ],
						'meta_description_length' => [ 'type' => 'integer' ],
						'meta_robots'             => [ 'type' => 'string' ],
						'canonical'               => [ 'type' => 'string' ],
						'h1_count'                => [ 'type' => 'integer' ],
						'h1_texts'                => [ 'type' => 'array' ],
						'h2_count'                => [ 'type' => 'integer' ],
						'total_images'            => [ 'type' => 'integer' ],
						'images_without_alt'      => [ 'type' => 'integer' ],
						'open_graph'              => [ 'type' => 'object' ],
						'structured_data_types'   => [ 'type' => 'array' ],
						'issues'                  => [ 'type' => 'array' ],
						'issue_count'             => [ 'type' => 'integer' ],
						'error'                   => [ 'type' => 'string' ],
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
				'execute_callback'    => [ __CLASS__, 'handle_audit_url' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			]
		);

		wp_register_ability(
			'sd-ai-agent/seo-analyze-content',
			[
				'label'               => __( 'SEO Analyze Content', 'superdav-ai-agent' ),
				'description'         => __( 'Analyze a post\'s content for SEO quality: keyword density, title length, heading structure, links, readability, and meta description.', 'superdav-ai-agent' ),
				'category'            => 'sd-ai-agent',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'post_id'       => [
							'type'        => 'integer',
							'description' => 'The WordPress post ID to analyze.',
						],
						'focus_keyword' => [
							'type'        => 'string',
							'description' => 'Optional focus keyword to check density and placement.',
						],
						'site_url'      => [
							'type'        => 'string',
							'description' => 'Subsite URL for multisite. Omit for the main site.',
						],
					],
					'required'   => [ 'post_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'post_id'                    => [ 'type' => 'integer' ],
						'title'                      => [ 'type' => 'string' ],
						'status'                     => [ 'type' => 'string' ],
						'word_count'                 => [ 'type' => 'integer' ],
						'title_length'               => [ 'type' => 'integer' ],
						'meta_description'           => [ 'type' => 'string' ],
						'meta_description_length'    => [ 'type' => 'integer' ],
						'heading_structure'          => [ 'type' => 'object' ],
						'internal_links'             => [ 'type' => 'integer' ],
						'external_links'             => [ 'type' => 'integer' ],
						'avg_sentence_length'        => [ 'type' => 'number' ],
						'has_featured_image'         => [ 'type' => 'boolean' ],
						'focus_keyword'              => [ 'type' => 'string' ],
						'keyword_count'              => [ 'type' => 'integer' ],
						'keyword_density'            => [ 'type' => 'number' ],
						'keyword_in_title'           => [ 'type' => 'boolean' ],
						'keyword_in_first_paragraph' => [ 'type' => 'boolean' ],
						'recommendations'            => [ 'type' => 'array' ],
						'recommendation_count'       => [ 'type' => 'integer' ],
						'error'                      => [ 'type' => 'string' ],
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
				'execute_callback'    => [ __CLASS__, 'handle_analyze_content' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			]
		);
	}

	/**
	 * Handle the seo-audit-url ability call.
	 *
	 * @param array<string,mixed> $input Input with url and optional site_url.
	 * @return array<string,mixed>|\WP_Error Audit results.
	 */
	public static function handle_audit_url( array $input ) {
		// @phpstan-ignore-next-line
		$url = esc_url_raw( (string) ( $input['url'] ?? '' ) );

		if ( empty( $url ) || ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'missing_url', 'url is required.' );
		}

		$response = wp_safe_remote_get(
			$url,
			[
				'timeout'    => 15,
				'user-agent' => 'AI-Agent-SEO-Audit/1.0',
			]
		);

		if ( is_wp_error( $response ) ) {
			return [ 'error' => 'Failed to fetch URL: ' . $response->get_error_message() ];
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( empty( $body ) ) {
			return [
				'url'         => $url,
				'status_code' => $status_code,
				'error'       => 'Empty response body.',
			];
		}

		return self::parse_seo_elements( $url, (int) $status_code, $body );
	}

	/**
	 * Parse SEO elements from HTML.
	 *
	 * @param string $url         The audited URL.
	 * @param int    $status_code HTTP status code.
	 * @param string $html        Raw HTML.
	 * @return array<string,mixed> Structured SEO data.
	 */
	private static function parse_seo_elements( string $url, int $status_code, string $html ): array {
		$result = [
			'url'         => $url,
			'status_code' => $status_code,
		];

		$issues = [];

		$prev = libxml_use_internal_errors( true );
		$doc  = new \DOMDocument();
		try {
			$doc->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ), LIBXML_NOERROR );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $prev );
		}

		$xpath = new \DOMXPath( $doc );

		// Title.
		$title_nodes = $doc->getElementsByTagName( 'title' );
		if ( $title_nodes->length > 0 ) {
			$title_node             = $title_nodes->item( 0 );
			$title                  = $title_node instanceof \DOMElement ? trim( $title_node->textContent ) : '';
			$result['title']        = $title;
			$result['title_length'] = mb_strlen( $title );

			if ( mb_strlen( $title ) < 30 ) {
				$issues[] = 'Title is too short (under 30 characters).';
			} elseif ( mb_strlen( $title ) > 60 ) {
				$issues[] = 'Title is too long (over 60 characters).';
			}
		} else {
			$result['title']        = '';
			$result['title_length'] = 0;
			$issues[]               = 'Missing <title> tag.';
		}

		// Meta description.
		$meta_desc                  = self::get_meta_content( $xpath, 'description' ) ?? '';
		$result['meta_description'] = $meta_desc;
		if ( empty( $meta_desc ) ) {
			$result['meta_description_length'] = 0;
			$issues[]                          = 'Missing meta description.';
		} else {
			$desc_len                          = mb_strlen( $meta_desc );
			$result['meta_description_length'] = $desc_len;
			if ( $desc_len < 120 ) {
				$issues[] = 'Meta description is too short (under 120 characters).';
			} elseif ( $desc_len > 160 ) {
				$issues[] = 'Meta description is too long (over 160 characters).';
			}
		}

		// Meta robots.
		$result['meta_robots'] = self::get_meta_content( $xpath, 'robots' ) ?? '';

		// Canonical.
		$canonical_nodes     = $xpath->query( '//link[@rel="canonical"]' );
		$canonical_node      = ( $canonical_nodes && $canonical_nodes->length > 0 ) ? $canonical_nodes->item( 0 ) : null;
		$result['canonical'] = ( $canonical_node instanceof \DOMElement )
			? $canonical_node->getAttribute( 'href' )
			: '';
		if ( empty( $result['canonical'] ) ) {
			$issues[] = 'Missing canonical URL.';
		}

		// Headings.
		$h1_nodes           = $doc->getElementsByTagName( 'h1' );
		$result['h1_count'] = $h1_nodes->length;
		$result['h1_texts'] = [];
		foreach ( $h1_nodes as $h1 ) {
			$result['h1_texts'][] = trim( $h1->textContent );
		}
		if ( $h1_nodes->length === 0 ) {
			$issues[] = 'No H1 heading found.';
		} elseif ( $h1_nodes->length > 1 ) {
			$issues[] = 'Multiple H1 headings found (' . $h1_nodes->length . ').';
		}

		$result['h2_count'] = $doc->getElementsByTagName( 'h2' )->length;

		// Images without alt.
		$images             = $doc->getElementsByTagName( 'img' );
		$images_without_alt = 0;
		$total_images       = $images->length;
		foreach ( $images as $img ) {
			$alt = $img->getAttribute( 'alt' );
			if ( $alt === '' || $alt === null ) {
				++$images_without_alt;
			}
		}
		$result['total_images']       = $total_images;
		$result['images_without_alt'] = $images_without_alt;
		if ( $images_without_alt > 0 ) {
			$issues[] = "{$images_without_alt} image(s) missing alt text.";
		}

		// Open Graph.
		$og       = [];
		$og_nodes = $xpath->query( '//meta[starts-with(@property, "og:")]' );
		foreach ( $og_nodes ?: [] as $node ) {
			if ( $node instanceof \DOMElement ) {
				$og[ $node->getAttribute( 'property' ) ] = $node->getAttribute( 'content' );
			}
		}
		$result['open_graph'] = $og;
		if ( empty( $og ) ) {
			$issues[] = 'No Open Graph tags found.';
		}

		// Structured data (JSON-LD).
		$jsonld       = [];
		$script_nodes = $xpath->query( '//script[@type="application/ld+json"]' );
		foreach ( $script_nodes ?: [] as $script ) {
			/** @phpstan-ignore-next-line */
			$decoded = json_decode( $script->textContent, true );
			if ( $decoded ) {
				// @phpstan-ignore-next-line
				$jsonld[] = $decoded['@type'] ?? 'Unknown';
			}
		}
		$result['structured_data_types'] = $jsonld;

		$result['issues']      = $issues;
		$result['issue_count'] = count( $issues );

		return $result;
	}

	/**
	 * Get meta tag content by name.
	 *
	 * @param \DOMXPath $xpath XPath instance.
	 * @param string    $name  Meta name attribute.
	 * @return string|null
	 */
	private static function get_meta_content( \DOMXPath $xpath, string $name ): ?string {
		$nodes = $xpath->query( '//meta[@name="' . $name . '"]' );
		if ( $nodes && $nodes->length > 0 ) {
			$node = $nodes->item( 0 );
			return ( $node instanceof \DOMElement ) ? $node->getAttribute( 'content' ) : null;
		}
		return null;
	}

	/**
	 * Handle the seo-analyze-content ability call.
	 *
	 * @param array<string,mixed> $input Input with post_id, optional focus_keyword, site_url.
	 * @return array<string,mixed>|\WP_Error Analysis results.
	 */
	public static function handle_analyze_content( array $input ) {
		// @phpstan-ignore-next-line
		$post_id = (int) ( $input['post_id'] ?? 0 );
		// @phpstan-ignore-next-line
		$focus_keyword = sanitize_text_field( $input['focus_keyword'] ?? '' );
		$site_url      = $input['site_url'] ?? '';

		if ( ! $post_id ) {
			return new \WP_Error( 'missing_post_id', 'post_id is required.' );
		}

		$switched = false;

		if ( ! empty( $site_url ) && is_multisite() ) {
			$blog_id = get_blog_id_from_url(
				// @phpstan-ignore-next-line
				(string) ( wp_parse_url( $site_url, PHP_URL_HOST ) ?? '' ),
				// @phpstan-ignore-next-line
				(string) ( wp_parse_url( $site_url, PHP_URL_PATH ) ?: '/' )
			);

			if ( $blog_id && $blog_id !== get_current_blog_id() ) {
				switch_to_blog( $blog_id );
				$switched = true;
			}
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			if ( $switched ) {
				restore_current_blog();
			}
			return new \WP_Error( 'post_not_found', "Post {$post_id} not found." );
		}

		$result = self::analyze_post_seo( $post, $focus_keyword );

		if ( $switched ) {
			restore_current_blog();
		}

		return $result;
	}

	/**
	 * Analyze a post for SEO quality.
	 *
	 * @param \WP_Post $post          The post to analyze.
	 * @param string   $focus_keyword Optional focus keyword.
	 * @return array<string,mixed> Analysis data.
	 */
	private static function analyze_post_seo( \WP_Post $post, string $focus_keyword ): array {
		$content    = $post->post_content;
		$title      = $post->post_title;
		$plain      = wp_strip_all_tags( $content );
		$word_count = str_word_count( $plain );

		$result = [
			'post_id'    => $post->ID,
			'title'      => $title,
			'status'     => $post->post_status,
			'word_count' => $word_count,
		];

		$recommendations = [];

		// Title length.
		$title_len              = mb_strlen( $title );
		$result['title_length'] = $title_len;
		if ( $title_len < 30 ) {
			$recommendations[] = 'Title is too short. Aim for 50-60 characters.';
		} elseif ( $title_len > 60 ) {
			$recommendations[] = 'Title is too long. Keep under 60 characters for search results.';
		}

		// Word count.
		if ( $word_count < 300 ) {
			$recommendations[] = 'Content is thin (under 300 words). Aim for at least 300 words.';
		}

		// Meta description from SEO plugins.
		$meta_desc = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
		if ( empty( $meta_desc ) ) {
			$meta_desc = get_post_meta( $post->ID, 'rank_math_description', true );
		}
		$meta_desc                  = is_string( $meta_desc ) ? $meta_desc : '';
		$result['meta_description'] = $meta_desc;
		if ( empty( $meta_desc ) ) {
			$result['meta_description_length'] = 0;
			$recommendations[]                 = 'No meta description set. Add one for better click-through rates.';
		} else {
			$desc_len                          = mb_strlen( $meta_desc );
			$result['meta_description_length'] = $desc_len;
			if ( $desc_len > 160 ) {
				$recommendations[] = 'Meta description exceeds 160 characters.';
			}
		}

		// Heading structure.
		preg_match_all( '/<h([1-6])[^>]*>/i', $content, $heading_matches );
		$heading_counts              = array_count_values( $heading_matches[1] ?? [] );
		$result['heading_structure'] = [];
		for ( $i = 1; $i <= 6; $i++ ) {
			$count = $heading_counts[ (string) $i ] ?? 0;
			if ( $count > 0 ) {
				$result['heading_structure'][ "h{$i}" ] = $count;
			}
		}
		if ( empty( $heading_counts ) ) {
			$recommendations[] = 'No headings found in content. Use H2/H3 to structure your content.';
		}

		// Links.
		preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\']/i', $content, $link_matches );
		$internal_links = 0;
		$external_links = 0;
		$site_host      = wp_parse_url( get_site_url(), PHP_URL_HOST );
		foreach ( $link_matches[1] ?? [] as $href ) {
			$link_host = wp_parse_url( $href, PHP_URL_HOST );
			if ( $link_host && $link_host !== $site_host ) {
				++$external_links;
			} else {
				++$internal_links;
			}
		}
		$result['internal_links'] = $internal_links;
		$result['external_links'] = $external_links;
		if ( $internal_links === 0 ) {
			$recommendations[] = 'No internal links found. Add links to related content.';
		}

		// Readability (average sentence length).
		$sentences      = preg_split( '/[.!?]+/', $plain, -1, PREG_SPLIT_NO_EMPTY );
		$sentence_count = is_array( $sentences ) ? count( $sentences ) : 0;
		if ( $sentence_count > 0 ) {
			$avg_sentence_len              = $word_count / $sentence_count;
			$result['avg_sentence_length'] = round( $avg_sentence_len, 1 );
			if ( $avg_sentence_len > 25 ) {
				$recommendations[] = 'Average sentence length is high (' . round( $avg_sentence_len, 1 ) . ' words). Consider shorter sentences.';
			}
		} else {
			$result['avg_sentence_length'] = 0.0;
		}

		// Focus keyword analysis.
		if ( ! empty( $focus_keyword ) ) {
			$keyword_lower = mb_strtolower( $focus_keyword );
			$plain_lower   = mb_strtolower( $plain );
			$title_lower   = mb_strtolower( $title );
			$keyword_count = mb_substr_count( $plain_lower, $keyword_lower );

			$result['focus_keyword']    = $focus_keyword;
			$result['keyword_count']    = $keyword_count;
			$result['keyword_density']  = $word_count > 0
				? round( ( $keyword_count / $word_count ) * 100, 2 )
				: 0;
			$result['keyword_in_title'] = mb_strpos( $title_lower, $keyword_lower ) !== false;

			// Check first paragraph.
			$first_para = '';
			if ( preg_match( '/<p[^>]*>(.*?)<\/p>/is', $content, $para_match ) ) {
				$first_para = mb_strtolower( wp_strip_all_tags( $para_match[1] ) );
			}
			$result['keyword_in_first_paragraph'] = ! empty( $first_para ) && mb_strpos( $first_para, $keyword_lower ) !== false;

			if ( ! $result['keyword_in_title'] ) {
				$recommendations[] = 'Focus keyword not found in title.';
			}
			if ( ! $result['keyword_in_first_paragraph'] ) {
				$recommendations[] = 'Focus keyword not found in the first paragraph.';
			}
			if ( $result['keyword_density'] < 0.5 ) {
				$recommendations[] = 'Keyword density is low (' . $result['keyword_density'] . '%). Aim for 0.5-2.5%.';
			} elseif ( $result['keyword_density'] > 2.5 ) {
				$recommendations[] = 'Keyword density is too high (' . $result['keyword_density'] . '%). This may appear as keyword stuffing.';
			}
		}

		// Featured image.
		$result['has_featured_image'] = has_post_thumbnail( $post->ID );
		if ( ! $result['has_featured_image'] ) {
			$recommendations[] = 'No featured image set.';
		}

		$result['recommendations']      = $recommendations;
		$result['recommendation_count'] = count( $recommendations );

		return $result;
	}
}
