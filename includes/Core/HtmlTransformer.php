<?php

declare(strict_types=1);
/**
 * Block attribute ↔ innerHTML auto-synchronization.
 *
 * Pure-function transforms: when an `update-attrs` op changes an attribute
 * that has a structural HTML counterpart, this class rewrites the block's
 * `innerHTML` (and `innerContent`) to stay consistent — preventing the
 * "comment marker says `level: 2` but inner tag is `<h3>`" desync that
 * breaks the block editor on reopen.
 *
 * Adapted from ~/Git/block-mcp/wordpress-plugin/gk-block-api/includes/class-html-transformer.php
 * (GPL-2.0-or-later — compatible). Namespace and method signatures adjusted per AGENTS.md.
 *
 * @package SdAiAgent\Core
 * @license GPL-2.0-or-later
 * @see     https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1711
 */

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HTML transformer for block attribute ↔ innerHTML synchronization.
 *
 * All entry points are static. The class holds no per-instance state.
 */
class HtmlTransformer {

	/**
	 * Block types that have known attribute → innerHTML transforms.
	 *
	 * Used by the mutator to decide whether to call apply() and whether
	 * to emit a `static_block_attrs_changed` warning for uncovered blocks.
	 *
	 * @var string[]
	 */
	const SUPPORTED_BLOCKS = [
		'core/heading',
		'core/list',
		'core/group',
		'core/button',
		'core/image',
		'core/spacer',
		'core/details',
		'core/quote',
		'core/audio',
		'core/video',
		'core/code',
		'core/preformatted',
		'core/paragraph',
	];

	/**
	 * Apply attribute → innerHTML transforms for a block.
	 *
	 * Returns the block with updated `innerHTML` and `innerContent` if any
	 * transforms applied, or the block unchanged if none did. All output
	 * is run through `wp_kses_post()`.
	 *
	 * @param array<string,mixed> $block         Parsed block array.
	 * @param array<string,mixed> $changed_attrs The attributes that were changed.
	 * @return array<string,mixed> Block with synchronized innerHTML.
	 */
	public static function apply( array $block, array $changed_attrs ): array {
		$block_name   = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
		$current_html = isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $block['innerHTML'] : '';

		if ( '' === $block_name || '' === $current_html ) {
			return $block;
		}

		$new_html = self::transform_html( $block_name, $changed_attrs, $current_html );

		if ( null === $new_html ) {
			return $block;
		}

		// Sanitize final output.
		$new_html = wp_kses_post( $new_html );

		$block['innerHTML'] = $new_html;

		// Rebuild innerContent to match the updated HTML.
		$block['innerContent'] = self::rebuild_inner_content( $block, $new_html );

		return $block;
	}

	/**
	 * Check whether a block type has known transforms.
	 *
	 * @param string $block_name Block type name.
	 * @return bool
	 */
	public static function is_supported( string $block_name ): bool {
		return in_array( $block_name, self::SUPPORTED_BLOCKS, true );
	}

	// ── Transform engine ─────────────────────────────────────────────────

	/**
	 * Compute the transformed HTML for a block, or null if no transform applies.
	 *
	 * Categories:
	 * 1. Tag name swaps (regex — WP_HTML_Tag_Processor can't change tag names).
	 * 2. HTML attribute transforms (WP_HTML_Tag_Processor).
	 * 3. CSS inline style transforms (WP_HTML_Tag_Processor).
	 * 4. Text content transforms (regex for inner text replacement).
	 *
	 * @param string              $block_name    Block type name.
	 * @param array<string,mixed> $changed_attrs Attributes being set.
	 * @param string              $current_html  Current innerHTML.
	 * @return string|null Transformed HTML, or null if no transform applies.
	 */
	private static function transform_html( string $block_name, array $changed_attrs, string $current_html ): ?string {
		$html = $current_html;

		// ── 1. Tag name swaps ────────────────────────────────────────────

		$html = self::transform_heading_level( $block_name, $changed_attrs, $html );
		$html = self::transform_list_ordered( $block_name, $changed_attrs, $html );
		$html = self::transform_group_tag( $block_name, $changed_attrs, $html );

		// ── 2. HTML attribute transforms ─────────────────────────────────

		$html = self::transform_button_url( $block_name, $changed_attrs, $html );
		$html = self::transform_image_attrs( $block_name, $changed_attrs, $html );
		$html = self::transform_details_open( $block_name, $changed_attrs, $html );
		$html = self::transform_media_booleans( $block_name, $changed_attrs, $html );

		// ── 3. CSS inline style transforms ───────────────────────────────

		$html = self::transform_spacer_styles( $block_name, $changed_attrs, $html );

		// ── 4. Text content transforms ───────────────────────────────────

		$html = self::transform_content_text( $block_name, $changed_attrs, $html );
		$html = self::transform_button_text( $block_name, $changed_attrs, $html );
		$html = self::transform_quote_citation( $block_name, $changed_attrs, $html );

		return $html !== $current_html ? $html : null;
	}

	// ── Tag name swaps ───────────────────────────────────────────────────

	/**
	 * Transform core/heading level attribute to matching <hN> tag.
	 *
	 * @param string              $block_name    Block type.
	 * @param array<string,mixed> $changed_attrs Changed attributes.
	 * @param string              $html          Current HTML.
	 * @return string Updated HTML.
	 */
	private static function transform_heading_level( string $block_name, array $changed_attrs, string $html ): string {
		if ( 'core/heading' !== $block_name || ! array_key_exists( 'level', $changed_attrs ) ) {
			return $html;
		}

		$new_level = (int) $changed_attrs['level'];

		if ( $new_level < 1 || $new_level > 6 ) {
			return $html;
		}

		$html = (string) preg_replace( '/<h[1-6](\s|>)/i', '<h' . $new_level . '$1', $html );
		$html = (string) preg_replace( '/<\/h[1-6]>/i', '</h' . $new_level . '>', $html );

		return $html;
	}

	/**
	 * Transform core/list ordered attribute to <ul>/<ol> tag swap.
	 *
	 * Only swaps the FIRST opening and LAST closing tag to preserve nested lists.
	 *
	 * @param string              $block_name    Block type.
	 * @param array<string,mixed> $changed_attrs Changed attributes.
	 * @param string              $html          Current HTML.
	 * @return string Updated HTML.
	 */
	private static function transform_list_ordered( string $block_name, array $changed_attrs, string $html ): string {
		if ( 'core/list' !== $block_name || ! array_key_exists( 'ordered', $changed_attrs ) ) {
			return $html;
		}

		if ( $changed_attrs['ordered'] ) {
			$html = (string) preg_replace( '/<ul(\s|>)/i', '<ol$1', $html, 1 );
			$html = (string) preg_replace( '/<\/ul>(?!.*<\/ul>)/is', '</ol>', $html );
		} else {
			$html = (string) preg_replace( '/<ol(\s|>)/i', '<ul$1', $html, 1 );
			$html = (string) preg_replace( '/<\/ol>(?!.*<\/ol>)/is', '</ul>', $html );
		}

		return $html;
	}

	/**
	 * Transform core/group tagName attribute to wrapper tag swap.
	 *
	 * @param string              $block_name    Block type.
	 * @param array<string,mixed> $changed_attrs Changed attributes.
	 * @param string              $html          Current HTML.
	 * @return string Updated HTML.
	 */
	private static function transform_group_tag( string $block_name, array $changed_attrs, string $html ): string {
		if ( 'core/group' !== $block_name || ! array_key_exists( 'tagName', $changed_attrs ) ) {
			return $html;
		}

		$container_tags = [ 'div', 'section', 'aside', 'main', 'header', 'footer', 'article', 'nav' ];
		$new_tag        = sanitize_key( (string) $changed_attrs['tagName'] );

		if ( ! in_array( $new_tag, $container_tags, true ) ) {
			return $html;
		}

		$tag_pattern = implode( '|', $container_tags );

		$html = (string) preg_replace(
			'/^(\s*)<(' . $tag_pattern . ')(\s|>)/i',
			'$1<' . $new_tag . '$3',
			$html
		);
		$html = (string) preg_replace(
			'/<\/(' . $tag_pattern . ')>(\s*)$/i',
			'</' . $new_tag . '>$2',
			$html
		);

		return $html;
	}

	// ── HTML attribute transforms ────────────────────────────────────────

	/**
	 * Transform core/button url attribute to <a href> update.
	 *
	 * Preserves class, target, and rel attributes on the anchor tag.
	 *
	 * @param string              $block_name    Block type.
	 * @param array<string,mixed> $changed_attrs Changed attributes.
	 * @param string              $html          Current HTML.
	 * @return string Updated HTML.
	 */
	private static function transform_button_url( string $block_name, array $changed_attrs, string $html ): string {
		if ( 'core/button' !== $block_name || ! array_key_exists( 'url', $changed_attrs ) ) {
			return $html;
		}

		$processor = new \WP_HTML_Tag_Processor( $html );

		while ( $processor->next_tag() ) {
			$tag = $processor->get_tag();

			if ( null !== $tag && 'A' === strtoupper( $tag ) ) {
				$processor->set_attribute( 'href', (string) $changed_attrs['url'] );
				break;
			}
		}

		return $processor->get_updated_html();
	}

	/**
	 * Transform core/image url, alt, and id attributes.
	 *
	 * - `url` → <img src>
	 * - `alt` → <img alt>
	 * - `id`  → <img class> rewriting `wp-image-{old}` to `wp-image-{new}`
	 *
	 * @param string              $block_name    Block type.
	 * @param array<string,mixed> $changed_attrs Changed attributes.
	 * @param string              $html          Current HTML.
	 * @return string Updated HTML.
	 */
	private static function transform_image_attrs( string $block_name, array $changed_attrs, string $html ): string {
		if ( 'core/image' !== $block_name ) {
			return $html;
		}

		$has_url = array_key_exists( 'url', $changed_attrs );
		$has_alt = array_key_exists( 'alt', $changed_attrs );
		$has_id  = array_key_exists( 'id', $changed_attrs );

		if ( ! $has_url && ! $has_alt && ! $has_id ) {
			return $html;
		}

		$processor = new \WP_HTML_Tag_Processor( $html );

		while ( $processor->next_tag() ) {
			$tag = $processor->get_tag();

			if ( null === $tag || 'IMG' !== strtoupper( $tag ) ) {
				continue;
			}

			if ( $has_url ) {
				$processor->set_attribute( 'src', (string) $changed_attrs['url'] );
			}

			if ( $has_alt ) {
				$processor->set_attribute( 'alt', (string) $changed_attrs['alt'] );
			}

			if ( $has_id ) {
				$new_id    = (int) $changed_attrs['id'];
				$old_class = $processor->get_attribute( 'class' );

				if ( is_string( $old_class ) ) {
					$new_class = (string) preg_replace(
						'/wp-image-\d+/',
						'wp-image-' . $new_id,
						$old_class
					);
					$processor->set_attribute( 'class', $new_class );
				}
			}

			break; // First <img> only.
		}

		return $processor->get_updated_html();
	}

	/**
	 * Transform core/details showContent attribute to <details open> toggle.
	 *
	 * @param string              $block_name    Block type.
	 * @param array<string,mixed> $changed_attrs Changed attributes.
	 * @param string              $html          Current HTML.
	 * @return string Updated HTML.
	 */
	private static function transform_details_open( string $block_name, array $changed_attrs, string $html ): string {
		if ( 'core/details' !== $block_name || ! array_key_exists( 'showContent', $changed_attrs ) ) {
			return $html;
		}

		$processor = new \WP_HTML_Tag_Processor( $html );

		if ( $processor->next_tag( [ 'tag_name' => 'details' ] ) ) {
			if ( filter_var( $changed_attrs['showContent'], FILTER_VALIDATE_BOOLEAN ) ) {
				$processor->set_attribute( 'open', true );
			} else {
				$processor->remove_attribute( 'open' );
			}
		}

		return $processor->get_updated_html();
	}

	/**
	 * Transform core/audio and core/video boolean attributes.
	 *
	 * Handles `loop`, `autoplay`, and `controls`.
	 *
	 * @param string              $block_name    Block type.
	 * @param array<string,mixed> $changed_attrs Changed attributes.
	 * @param string              $html          Current HTML.
	 * @return string Updated HTML.
	 */
	private static function transform_media_booleans( string $block_name, array $changed_attrs, string $html ): string {
		if ( ! in_array( $block_name, [ 'core/audio', 'core/video' ], true ) ) {
			return $html;
		}

		$bool_attrs = [ 'loop', 'autoplay', 'controls' ];
		$media_tags = [ 'audio', 'video' ];

		foreach ( $bool_attrs as $attr ) {
			if ( ! array_key_exists( $attr, $changed_attrs ) ) {
				continue;
			}

			$enable    = filter_var( $changed_attrs[ $attr ], FILTER_VALIDATE_BOOLEAN );
			$processor = new \WP_HTML_Tag_Processor( $html );

			while ( $processor->next_tag() ) {
				$raw_tag = $processor->get_tag();

				if ( null === $raw_tag ) {
					continue;
				}

				$tag = strtolower( $raw_tag );

				if ( ! in_array( $tag, $media_tags, true ) ) {
					continue;
				}

				if ( $enable ) {
					$processor->set_attribute( $attr, true );
				} else {
					$processor->remove_attribute( $attr );
				}

				break; // First match only.
			}

			$html = $processor->get_updated_html();
		}

		return $html;
	}

	// ── CSS inline style transforms ──────────────────────────────────────

	/**
	 * Transform core/spacer height and width attributes to inline styles.
	 *
	 * @param string              $block_name    Block type.
	 * @param array<string,mixed> $changed_attrs Changed attributes.
	 * @param string              $html          Current HTML.
	 * @return string Updated HTML.
	 */
	private static function transform_spacer_styles( string $block_name, array $changed_attrs, string $html ): string {
		if ( 'core/spacer' !== $block_name ) {
			return $html;
		}

		$css_map = [
			'height' => 'height',
			'width'  => 'width',
		];

		foreach ( $css_map as $block_attr => $css_prop ) {
			if ( ! array_key_exists( $block_attr, $changed_attrs ) ) {
				continue;
			}

			$new_val   = sanitize_text_field( (string) $changed_attrs[ $block_attr ] );
			$processor = new \WP_HTML_Tag_Processor( $html );

			if ( ! $processor->next_tag() ) {
				continue;
			}

			$style = $processor->get_attribute( 'style' );

			if ( ! is_string( $style ) || false === strpos( $style, $css_prop ) ) {
				continue;
			}

			$new_style = (string) preg_replace_callback(
				'/(?<![-\w])' . preg_quote( $css_prop, '/' ) . '\s*:\s*[^;"]+(;?)/',
				static function ( array $matches ) use ( $css_prop, $new_val ): string {
					return $css_prop . ':' . $new_val . $matches[1];
				},
				$style
			);

			$processor->set_attribute( 'style', $new_style );
			$html = $processor->get_updated_html();
		}

		return $html;
	}

	// ── Text content transforms ──────────────────────────────────────────

	/**
	 * Transform content attribute on text blocks.
	 *
	 * Applies to core/code, core/preformatted, core/paragraph, core/heading.
	 *
	 * @param string              $block_name    Block type.
	 * @param array<string,mixed> $changed_attrs Changed attributes.
	 * @param string              $html          Current HTML.
	 * @return string Updated HTML.
	 */
	private static function transform_content_text( string $block_name, array $changed_attrs, string $html ): string {
		$content_blocks = [
			'core/heading',
			'core/paragraph',
			'core/code',
			'core/preformatted',
		];

		if ( ! in_array( $block_name, $content_blocks, true ) || ! array_key_exists( 'content', $changed_attrs ) ) {
			return $html;
		}

		$new_content = wp_kses_post( (string) $changed_attrs['content'] );

		$result = preg_replace_callback(
			'/^(\s*<[^>]+>)(.*?)(<\/[^>]+>\s*)$/is',
			static function ( array $matches ) use ( $new_content ): string {
				return $matches[1] . $new_content . $matches[3];
			},
			$html
		);

		return is_string( $result ) ? $result : $html;
	}

	/**
	 * Transform core/button text attribute to <a> inner text.
	 *
	 * @param string              $block_name    Block type.
	 * @param array<string,mixed> $changed_attrs Changed attributes.
	 * @param string              $html          Current HTML.
	 * @return string Updated HTML.
	 */
	private static function transform_button_text( string $block_name, array $changed_attrs, string $html ): string {
		if ( 'core/button' !== $block_name || ! array_key_exists( 'text', $changed_attrs ) ) {
			return $html;
		}

		$new_text = wp_kses_post( (string) $changed_attrs['text'] );

		$result = preg_replace_callback(
			'/(<a[^>]*>)(.*?)(<\/a>)/is',
			static function ( array $matches ) use ( $new_text ): string {
				return $matches[1] . $new_text . $matches[3];
			},
			$html
		);

		return is_string( $result ) ? $result : $html;
	}

	/**
	 * Transform core/quote citation attribute to <cite> element.
	 *
	 * If a <cite> element exists, replaces its content.
	 * If no <cite> exists and citation is non-empty, appends one before </blockquote>.
	 *
	 * @param string              $block_name    Block type.
	 * @param array<string,mixed> $changed_attrs Changed attributes.
	 * @param string              $html          Current HTML.
	 * @return string Updated HTML.
	 */
	private static function transform_quote_citation( string $block_name, array $changed_attrs, string $html ): string {
		if ( 'core/quote' !== $block_name || ! array_key_exists( 'citation', $changed_attrs ) ) {
			return $html;
		}

		$new_citation = wp_kses_post( (string) $changed_attrs['citation'] );

		if ( preg_match( '/<cite[^>]*>.*?<\/cite>/is', $html ) ) {
			$result = preg_replace_callback(
				'/(<cite[^>]*>).*?(<\/cite>)/is',
				static function ( array $matches ) use ( $new_citation ): string {
					return $matches[1] . $new_citation . $matches[2];
				},
				$html
			);

			return is_string( $result ) ? $result : $html;
		}

		if ( '' !== $new_citation ) {
			$result = preg_replace_callback(
				'/(<\/blockquote>\s*$)/i',
				static function ( array $matches ) use ( $new_citation ): string {
					return '<cite>' . $new_citation . '</cite>' . $matches[1];
				},
				$html
			);

			return is_string( $result ) ? $result : $html;
		}

		return $html;
	}

	// ── innerContent rebuild ─────────────────────────────────────────────

	/**
	 * Rebuild innerContent array to match new innerHTML.
	 *
	 * Preserves null placeholders for innerBlocks.
	 *
	 * @param array<string,mixed> $block    Block array.
	 * @param string              $new_html New innerHTML.
	 * @return list<string|null> Updated innerContent.
	 */
	private static function rebuild_inner_content( array $block, string $new_html ): array {
		$inner_blocks = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];
		$icont        = isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ? $block['innerContent'] : [];

		if ( empty( $inner_blocks ) ) {
			return [ $new_html ];
		}

		// Count existing null placeholders.
		$null_count = 0;

		foreach ( $icont as $piece ) {
			if ( null === $piece ) {
				++$null_count;
			}
		}

		if ( 0 === $null_count ) {
			return [ $new_html ];
		}

		// For container blocks, split HTML into opening wrapper + closing wrapper
		// and place nulls between them (one per child).
		$first_close = strpos( $new_html, '>' );

		if ( false !== $first_close ) {
			$opening = substr( $new_html, 0, $first_close + 1 );
			$closing = substr( $new_html, $first_close + 1 );

			$result = [ $opening ];

			for ( $i = 0; $i < $null_count; $i++ ) {
				$result[] = null;
			}

			$result[] = $closing;

			return $result;
		}

		// Fallback: preserve structure, replace string chunks.
		return array_values(
			array_map(
				static function ( $piece ) use ( $new_html ) {
					return null === $piece ? null : $new_html;
				},
				$icont
			)
		);
	}
}
