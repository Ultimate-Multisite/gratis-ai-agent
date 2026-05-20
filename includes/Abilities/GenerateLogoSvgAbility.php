<?php

declare(strict_types=1);
/**
 * Generate Logo SVG ability — AI-powered SVG logo candidate generation.
 *
 * Produces sanitised SVG logo candidates (wordmark, monogram, symbol+wordmark,
 * symbol) via the WordPress AI Client SDK, saves them to the media library, and
 * optionally wires the chosen candidate as the active site logo.
 *
 * @package SdAiAgent\Abilities
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates sanitised SVG logo candidates using AI and saves them to the
 * media library. Falls back to a type-only wordmark when all AI attempts
 * fail SVG validation.
 *
 * @since 1.7.0
 */
class GenerateLogoSvgAbility extends AbstractAbility {

	/**
	 * Maximum number of AI generation attempts per candidate before falling back.
	 */
	private const MAX_RETRIES = 3;

	/**
	 * Maximum allowed SVG byte size (500 KB).
	 */
	private const MAX_SVG_BYTES = 512000;

	/**
	 * Elements that must never appear in a sanitised SVG.
	 *
	 * @var list<string>
	 */
	private const FORBIDDEN_ELEMENTS = [ 'script', 'foreignObject' ];

	/**
	 * Register this ability with the WordPress Abilities API.
	 *
	 * Safe to call before the API has loaded — returns silently if
	 * `wp_register_ability` is not yet available.
	 */
	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'sd-ai-agent/generate-logo-svg',
			[
				'label'         => __( 'Generate Logo SVG', 'superdav-ai-agent' ),
				'description'   => __(
					'Generate sanitised SVG logo candidates using AI, save them to the media library, and optionally set one as the active site logo. Falls back to a type-only wordmark when all AI attempts fail validation.',
					'superdav-ai-agent'
				),
				'ability_class' => self::class,
			]
		);
	}

	/**
	 * {@inheritdoc}
	 */
	protected function label(): string {
		return __( 'Generate Logo SVG', 'superdav-ai-agent' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function description(): string {
		return __(
			'Generate sanitised SVG logo candidates using AI, save them to the media library, and optionally set one as the active site logo. Falls back to a type-only wordmark when all AI attempts fail validation.',
			'superdav-ai-agent'
		);
	}

	/**
	 * {@inheritdoc}
	 */
	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'action'            => [
					'type'        => 'string',
					'enum'        => [ 'generate', 'select_candidate' ],
					'description' => 'generate: produce N SVG logo candidates (default). select_candidate: wire an already-generated candidate as the active site logo.',
				],
				'brand_name'        => [
					'type'        => 'string',
					'description' => 'Brand or business name to use in the logo. Required for the generate action.',
				],
				'description'       => [
					'type'        => 'string',
					'description' => 'Brand description or vertical (e.g. "artisan coffee roaster", "SaaS analytics platform"). Required for the generate action.',
				],
				'direction'         => [
					'type'        => 'string',
					'enum'        => [ 'wordmark', 'monogram', 'symbol+wordmark', 'symbol' ],
					'description' => 'Logo style direction. Default: symbol+wordmark.',
				],
				'style_cues'        => [
					'type'        => 'string',
					'description' => 'Optional palette and voice cues (e.g. "warm earth tones, hand-crafted feel").',
				],
				'existing_logo_url' => [
					'type'        => 'string',
					'description' => 'URL of the user\'s existing logo. When provided the ability returns immediately with the existing logo preserved and skips generation.',
				],
				'count'             => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 5,
					'description' => 'Number of SVG candidates to generate. Default: 3.',
				],
				'attachment_id'     => [
					'type'        => 'integer',
					'description' => 'Media attachment ID to activate as the site logo. Required for the select_candidate action.',
				],
			],
			'required'   => [],
		];
	}

	/**
	 * {@inheritdoc}
	 */
	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'candidates'              => [
					'type'        => 'array',
					'description' => 'Generated SVG candidates with attachment_id, url, data_uri, and fallback flag.',
				],
				'selected_attachment_id'  => [ 'type' => 'integer' ],
				'logo_set'                => [ 'type' => 'boolean' ],
				'fallback'                => [
					'type'        => 'boolean',
					'description' => 'True when AI generation failed validation and a type-only wordmark was substituted.',
				],
				'existing_logo_preserved' => [ 'type' => 'boolean' ],
				'message'                 => [ 'type' => 'string' ],
				'error'                   => [ 'type' => 'string' ],
			],
		];
	}

	/**
	 * {@inheritdoc}
	 */
	protected function execute_callback( mixed $input ): array|\WP_Error {
		$action = (string) ( $input['action'] ?? 'generate' );

		if ( 'select_candidate' === $action ) {
			return $this->handle_select_candidate( $input );
		}

		return $this->handle_generate( $input );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function permission_callback( mixed $input = null ): bool {
		return current_user_can( 'upload_files' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function meta(): array {
		return [
			'mcp'          => [ 'public' => true ],
			'annotations'  => [
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			],
			'show_in_rest' => true,
		];
	}

	// ─── Action handlers ──────────────────────────────────────────────────────

	/**
	 * Handle the generate action: produce N SVG logo candidates.
	 *
	 * @param mixed $input Input array.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function handle_generate( mixed $input ): array|\WP_Error {
		$brand_name        = sanitize_text_field( (string) ( $input['brand_name'] ?? '' ) );
		$brand_description = sanitize_textarea_field( (string) ( $input['description'] ?? '' ) );
		$direction         = sanitize_text_field( (string) ( $input['direction'] ?? 'symbol+wordmark' ) );
		$style_cues        = sanitize_textarea_field( (string) ( $input['style_cues'] ?? '' ) );
		$existing_logo_url = esc_url_raw( (string) ( $input['existing_logo_url'] ?? '' ) );
		$count             = max( 1, min( 5, (int) ( $input['count'] ?? 3 ) ) );

		if ( empty( $brand_name ) ) {
			return new WP_Error(
				'missing_brand_name',
				__( 'brand_name is required.', 'superdav-ai-agent' )
			);
		}

		if ( empty( $brand_description ) ) {
			return new WP_Error(
				'missing_description',
				__( 'description is required.', 'superdav-ai-agent' )
			);
		}

		// User already has a logo — preserve it and skip generation.
		if ( ! empty( $existing_logo_url ) ) {
			return [
				'candidates'              => [],
				'existing_logo_preserved' => true,
				'logo_set'                => false,
				'fallback'                => false,
				'message'                 => __( 'User already has a logo. Existing logo preserved; generation skipped.', 'superdav-ai-agent' ),
			];
		}

		$candidates = [];
		$fallback   = false;

		for ( $i = 1; $i <= $count; $i++ ) {
			$svg = $this->generate_one_candidate( $brand_name, $brand_description, $direction, $style_cues, $i, $count );

			if ( is_wp_error( $svg ) ) {
				// All retries exhausted — substitute a type-only wordmark fallback.
				$svg      = $this->generate_fallback_wordmark( $brand_name );
				$fallback = true;
			}

			$attachment_id = $this->save_svg_to_media_library(
				$svg,
				sprintf( '%s-logo-candidate-%d', sanitize_file_name( $brand_name ), $i )
			);

			if ( is_wp_error( $attachment_id ) ) {
				continue;
			}

			$candidates[] = [
				'attachment_id' => $attachment_id,
				'url'           => (string) wp_get_attachment_url( $attachment_id ),
				'data_uri'      => 'data:image/svg+xml;base64,' . base64_encode( $svg ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'fallback'      => $fallback,
			];

			// One fallback is sufficient — stop generating more AI candidates.
			if ( $fallback ) {
				break;
			}
		}

		if ( empty( $candidates ) ) {
			return new WP_Error(
				'generation_failed',
				__( 'Failed to generate or save SVG logo candidates.', 'superdav-ai-agent' )
			);
		}

		$message = $fallback
			? __(
				'AI generation failed SVG validation; a type-only wordmark fallback was generated. Use select_candidate with attachment_id to activate a logo.',
				'superdav-ai-agent'
			)
			: sprintf(
				/* translators: %d: number of SVG candidates generated */
				__( '%d SVG logo candidate(s) generated. Use select_candidate with attachment_id to activate one as the site logo.', 'superdav-ai-agent' ),
				count( $candidates )
			);

		return [
			'candidates' => $candidates,
			'fallback'   => $fallback,
			'logo_set'   => false,
			'message'    => $message,
		];
	}

	/**
	 * Handle the select_candidate action: wire an attachment as the site logo.
	 *
	 * @param mixed $input Input array.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function handle_select_candidate( mixed $input ): array|\WP_Error {
		$attachment_id = (int) ( $input['attachment_id'] ?? 0 );

		if ( $attachment_id <= 0 ) {
			return new WP_Error(
				'missing_attachment_id',
				__( 'attachment_id is required for the select_candidate action.', 'superdav-ai-agent' )
			);
		}

		if ( ! get_post( $attachment_id ) ) {
			return new WP_Error(
				'invalid_attachment_id',
				__( 'Attachment not found.', 'superdav-ai-agent' )
			);
		}

		// Set the custom_logo theme mod — the standard WordPress site-logo mechanism.
		set_theme_mod( 'custom_logo', $attachment_id );

		// Also wire as site icon when the attachment is an SVG (modern browsers support SVG favicons).
		$mime = (string) get_post_mime_type( $attachment_id );
		if ( 'image/svg+xml' === $mime ) {
			update_option( 'site_icon', $attachment_id );
		}

		return [
			'selected_attachment_id' => $attachment_id,
			'logo_set'               => true,
			'message'                => __( 'Site logo updated successfully.', 'superdav-ai-agent' ),
		];
	}

	// ─── SVG generation ───────────────────────────────────────────────────────

	/**
	 * Generate one SVG logo candidate via the AI Client SDK.
	 *
	 * Retries up to MAX_RETRIES times; each attempt extracts, sanitises, and
	 * validates the AI response before accepting it. Returns WP_Error when all
	 * retries are exhausted.
	 *
	 * @param string $brand_name   Brand name.
	 * @param string $description  Brand description / vertical.
	 * @param string $direction    Logo style direction.
	 * @param string $style_cues   Optional style cues.
	 * @param int    $index        1-based candidate index.
	 * @param int    $total        Total candidates requested.
	 * @return string|\WP_Error Sanitised SVG markup or WP_Error on failure.
	 */
	protected function generate_one_candidate(
		string $brand_name,
		string $description,
		string $direction,
		string $style_cues,
		int $index,
		int $total
	): string|\WP_Error {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error(
				'ai_client_unavailable',
				__( 'wp_ai_client_prompt() is not available.', 'superdav-ai-agent' )
			);
		}

		$direction_map = [
			'wordmark'        => 'A wordmark (type-only logo) using only custom typography to spell out the brand name.',
			'monogram'        => 'A monogram logo with the brand initials inside a geometric shape.',
			'symbol+wordmark' => 'A symbol/icon mark alongside the brand name wordmark.',
			'symbol'          => 'An abstract or literal symbol/icon mark without text.',
		];

		$direction_desc = $direction_map[ $direction ] ?? $direction_map['symbol+wordmark'];

		$variety_hint = $total > 1
			? sprintf(
				' This is candidate %d of %d — make it visually distinct from the other candidates.',
				$index,
				$total
			)
			: '';

		$style_context = ! empty( $style_cues ) ? "\nStyle cues: {$style_cues}" : '';

		$system_instruction = <<<'INSTRUCTION'
You are an expert SVG logo designer. Generate clean, professional SVG logo markup.

CRITICAL RULES:
1. Return ONLY valid, complete SVG markup — nothing else. No XML declaration, no markdown, no explanations.
2. The SVG root must have: xmlns="http://www.w3.org/2000/svg", a viewBox attribute (e.g. viewBox="0 0 200 80"), and width/height attributes.
3. NO <script> elements, NO <foreignObject> elements, NO JavaScript event attributes (onclick, onload, etc.).
4. NO external URLs in href, xlink:href, src, or any other attribute. All resources must be inline.
5. NO <image> elements pointing to external URLs.
6. Use only inline SVG elements: <rect>, <circle>, <ellipse>, <path>, <text>, <g>, <defs>, <clipPath>, <linearGradient>, <radialGradient>, <polygon>, <polyline>, <line>.
7. Text must use generic font-family stacks only (sans-serif, serif, monospace) — no web font @import.
8. Keep the file under 20 KB of markup. Prefer geometric shapes over complex paths.
9. Make it professional, scalable, and brand-appropriate.
10. Start your response with "<svg" and end with "</svg>".
INSTRUCTION;

		$prompt = "Create a professional SVG logo for: {$brand_name}\n\nBrand: {$description}\nStyle: {$direction_desc}{$style_context}{$variety_hint}";

		$last_error = null;

		for ( $attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++ ) {
			$raw = wp_ai_client_prompt( $prompt )
				->using_system_instruction( $system_instruction )
				->generate_text();

			if ( is_wp_error( $raw ) ) {
				$last_error = $raw;
				continue;
			}

			$svg = $this->extract_svg( (string) $raw );
			if ( null === $svg ) {
				$last_error = new WP_Error( 'no_svg_found', 'AI response contained no SVG markup.' );
				continue;
			}

			$sanitized = $this->sanitize_svg( $svg );
			if ( is_wp_error( $sanitized ) ) {
				$last_error = $sanitized;
				continue;
			}

			$valid = $this->validate_svg( $sanitized );
			if ( is_wp_error( $valid ) ) {
				$last_error = $valid;
				continue;
			}

			return $sanitized;
		}

		return $last_error instanceof \WP_Error
			? $last_error
			: new WP_Error( 'svg_generation_failed', __( 'Failed to generate a valid SVG after multiple attempts.', 'superdav-ai-agent' ) );
	}

	// ─── SVG helpers ──────────────────────────────────────────────────────────

	/**
	 * Extract SVG markup from a raw AI response string.
	 *
	 * Handles three common formats:
	 *   1. Direct `<svg ...>...</svg>` output.
	 *   2. Markdown code block wrapping (```svg or ```xml).
	 *   3. Mixed prose with embedded SVG.
	 *
	 * @param string $raw Raw AI response.
	 * @return string|null Extracted SVG markup, or null if not found.
	 */
	protected function extract_svg( string $raw ): ?string {
		$raw = trim( $raw );

		// Fast path: response already starts with <svg.
		if ( str_starts_with( $raw, '<svg' ) ) {
			return $raw;
		}

		// Extract from markdown code block.
		if ( preg_match( '/```(?:svg|xml)?\s*(<svg[\s\S]*?<\/svg>)\s*```/i', $raw, $matches ) ) {
			return trim( $matches[1] );
		}

		// Extract embedded SVG from mixed content.
		if ( preg_match( '/(<svg[\s\S]*?<\/svg>)/i', $raw, $matches ) ) {
			return trim( $matches[1] );
		}

		return null;
	}

	/**
	 * Sanitise SVG markup by removing dangerous elements and attributes.
	 *
	 * Validates as XML, strips forbidden elements (script, foreignObject),
	 * removes external image references, and strips all event attributes and
	 * javascript: URIs from every element.
	 *
	 * @param string $svg Raw SVG markup.
	 * @return string|\WP_Error Sanitised SVG string or WP_Error on failure.
	 */
	protected function sanitize_svg( string $svg ): string|\WP_Error {
		if ( strlen( $svg ) > self::MAX_SVG_BYTES ) {
			return new WP_Error(
				'svg_too_large',
				__( 'Generated SVG exceeds the maximum allowed size (500 KB).', 'superdav-ai-agent' )
			);
		}

		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$loaded = $dom->loadXML( $svg, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( false );

		if ( ! $loaded ) {
			return new WP_Error(
				'svg_parse_failed',
				__( 'Generated SVG is not valid XML.', 'superdav-ai-agent' )
			);
		}

		$this->remove_forbidden_nodes( $dom );
		$this->strip_dangerous_attributes( $dom );

		$clean = $dom->saveXML( $dom->documentElement );
		if ( false === $clean || '' === $clean ) {
			return new WP_Error(
				'svg_save_failed',
				__( 'Failed to serialise the sanitised SVG.', 'superdav-ai-agent' )
			);
		}

		return $clean;
	}

	/**
	 * Validate SVG structure after sanitisation.
	 *
	 * Checks that the document is parseable XML, has an <svg> root element with
	 * a viewBox attribute, and contains at least one child node.
	 *
	 * @param string $svg Sanitised SVG markup.
	 * @return true|\WP_Error True on success, WP_Error describing the violation.
	 */
	protected function validate_svg( string $svg ): true|\WP_Error {
		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$loaded = $dom->loadXML( $svg, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( false );

		if ( ! $loaded ) {
			return new WP_Error(
				'svg_invalid',
				__( 'SVG failed final validation parse.', 'superdav-ai-agent' )
			);
		}

		$root = $dom->documentElement;
		if ( ! $root || 'svg' !== strtolower( (string) $root->localName ) ) {
			return new WP_Error(
				'svg_no_root',
				__( 'SVG does not have an <svg> root element.', 'superdav-ai-agent' )
			);
		}

		// Accept either capitalisation variant of viewBox.
		if ( ! $root->hasAttribute( 'viewBox' ) && ! $root->hasAttribute( 'viewbox' ) ) {
			return new WP_Error(
				'svg_no_viewbox',
				__( 'SVG is missing a viewBox attribute.', 'superdav-ai-agent' )
			);
		}

		if ( 0 === $root->childNodes->length ) {
			return new WP_Error(
				'svg_empty',
				__( 'SVG has no content.', 'superdav-ai-agent' )
			);
		}

		return true;
	}

	/**
	 * Generate a simple type-only wordmark SVG as a safe fallback.
	 *
	 * Produces minimal, valid SVG markup that renders the brand name in a
	 * system sans-serif font. Used when AI generation fails validation after
	 * MAX_RETRIES attempts.
	 *
	 * @param string $brand_name Brand name to display.
	 * @return string Valid SVG markup.
	 */
	protected function generate_fallback_wordmark( string $brand_name ): string {
		$safe_name   = htmlspecialchars( $brand_name, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
		$char_count  = mb_strlen( $brand_name );
		$width       = max( 120, min( 400, $char_count * 14 + 40 ) );
		$height      = 60;
		$text_anchor = intdiv( $width, 2 );

		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %2$d" width="%1$d" height="%2$d">'
			. '<text x="%3$d" y="40" font-family="sans-serif" font-size="28" font-weight="600" fill="#1a1a1a" text-anchor="middle">%4$s</text>'
			. '</svg>',
			$width,
			$height,
			$text_anchor,
			$safe_name
		);
	}

	// ─── DOM mutation helpers ─────────────────────────────────────────────────

	/**
	 * Remove forbidden elements (script, foreignObject) and external <image> refs.
	 *
	 * @param \DOMDocument $dom The document to mutate.
	 */
	private function remove_forbidden_nodes( \DOMDocument $dom ): void {
		foreach ( self::FORBIDDEN_ELEMENTS as $tag_name ) {
			$nodes = $dom->getElementsByTagName( $tag_name );
			// Iterate in reverse to avoid live NodeList index shifting.
			for ( $i = $nodes->length - 1; $i >= 0; $i-- ) {
				$node = $nodes->item( $i );
				if ( $node instanceof \DOMNode && $node->parentNode instanceof \DOMNode ) {
					$node->parentNode->removeChild( $node );
				}
			}
		}

		// Strip <image> elements that reference external (non-data) URIs.
		$images = $dom->getElementsByTagName( 'image' );
		for ( $i = $images->length - 1; $i >= 0; $i-- ) {
			$node = $images->item( $i );
			if ( ! ( $node instanceof \DOMElement ) ) {
				continue;
			}
			$href = $node->getAttribute( 'href' )
				?: $node->getAttributeNS( 'http://www.w3.org/1999/xlink', 'href' );
			if ( ! str_starts_with( $href, 'data:' ) ) {
				if ( $node->parentNode instanceof \DOMNode ) {
					$node->parentNode->removeChild( $node );
				}
			}
		}
	}

	/**
	 * Strip dangerous attributes from every element in the document.
	 *
	 * Removes:
	 *  - Event handlers (any attribute starting with "on").
	 *  - href / xlink:href pointing to non-fragment, non-data URIs.
	 *  - Any attribute whose value contains a javascript: URI.
	 *
	 * @param \DOMDocument $dom The document to mutate.
	 */
	private function strip_dangerous_attributes( \DOMDocument $dom ): void {
		$xpath        = new \DOMXPath( $dom );
		$all_elements = $xpath->query( '//*' );

		if ( ! $all_elements instanceof \DOMNodeList ) {
			return;
		}

		foreach ( $all_elements as $element ) {
			if ( ! ( $element instanceof \DOMElement ) ) {
				continue;
			}

			/** @var array<int, \DOMAttr> $attrs_to_remove */
			$attrs_to_remove = [];

			foreach ( $element->attributes as $attr ) {
				if ( ! ( $attr instanceof \DOMAttr ) ) {
					continue;
				}

				$name  = strtolower( $attr->name );
				$value = $attr->value;

				// Remove all event handler attributes.
				if ( str_starts_with( $name, 'on' ) ) {
					$attrs_to_remove[] = $attr;
					continue;
				}

				// Remove external href / xlink:href (keep fragment refs and data URIs).
				if ( 'xlink:href' === $name || 'href' === $name ) {
					if (
						! str_starts_with( $value, '#' )
						&& ! str_starts_with( $value, 'data:' )
						&& '' !== $value
					) {
						$attrs_to_remove[] = $attr;
					}
					continue;
				}

				// Remove any attribute that embeds a javascript: URI.
				if ( str_contains( strtolower( $value ), 'javascript:' ) ) {
					$attrs_to_remove[] = $attr;
				}
			}

			// Use removeAttributeNode() so namespaced attributes (e.g. xlink:href
			// declared via xmlns:xlink) are reliably stripped. The unnamespaced
			// DOMElement::removeAttribute() silently no-ops for attributes
			// attached via a non-default namespace URI.
			foreach ( $attrs_to_remove as $attr ) {
				$element->removeAttributeNode( $attr );
			}
		}
	}

	// ─── Media library ────────────────────────────────────────────────────────

	/**
	 * Save SVG content directly to the WordPress media library.
	 *
	 * Writes the SVG to the current upload directory and creates an attachment
	 * post with MIME type `image/svg+xml`. Bypasses `media_handle_sideload()`
	 * because WordPress's MIME check blocks SVG uploads by default.
	 *
	 * @param string $svg  Sanitised SVG markup.
	 * @param string $slug Filename slug (will be sanitised and made unique).
	 * @return int|\WP_Error Attachment ID or WP_Error on failure.
	 */
	protected function save_svg_to_media_library( string $svg, string $slug ): int|\WP_Error {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return new WP_Error(
				'no_upload_dir',
				__( 'WordPress upload functions are not available.', 'superdav-ai-agent' )
			);
		}

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return new WP_Error( 'upload_dir_error', (string) $upload_dir['error'] );
		}

		$filename = sanitize_file_name( $slug ) . '-' . substr( uniqid( '', false ), -6 ) . '.svg';
		$filepath = trailingslashit( (string) $upload_dir['path'] ) . $filename;
		$url      = trailingslashit( (string) $upload_dir['url'] ) . $filename;

		// Write SVG to disk.
		$written = file_put_contents( $filepath, $svg ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $written ) {
			return new WP_Error(
				'svg_write_failed',
				__( 'Failed to write SVG file to the uploads directory.', 'superdav-ai-agent' )
			);
		}

		if ( ! function_exists( 'wp_insert_attachment' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		$attachment = [
			'post_mime_type' => 'image/svg+xml',
			'post_title'     => sanitize_text_field( $slug ),
			'post_content'   => '',
			'post_status'    => 'inherit',
			'guid'           => $url,
		];

		$attachment_id = wp_insert_attachment( $attachment, $filepath, 0 );

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $filepath );
			return $attachment_id;
		}

		if ( ! is_int( $attachment_id ) || $attachment_id <= 0 ) {
			wp_delete_file( $filepath );
			return new WP_Error(
				'attachment_insert_failed',
				__( 'Failed to insert SVG attachment into the media library.', 'superdav-ai-agent' )
			);
		}

		// Store minimal metadata (SVG has no raster dimensions to introspect).
		if ( function_exists( '_wp_relative_upload_path' ) ) {
			update_post_meta(
				$attachment_id,
				'_wp_attachment_metadata',
				[
					'width'  => 0,
					'height' => 0,
					'file'   => _wp_relative_upload_path( $filepath ),
					'sizes'  => [],
				]
			);
		}

		// Cache the data URI so the UI can render an inline preview without
		// fetching the file from disk.
		update_post_meta(
			$attachment_id,
			'_sd_ai_agent_svg_data_uri',
			'data:image/svg+xml;base64,' . base64_encode( $svg ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		);

		return $attachment_id;
	}
}
