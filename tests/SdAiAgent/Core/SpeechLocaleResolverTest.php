<?php
/**
 * Tests for the canonical speech locale resolver.
 *
 * @package SdAiAgent\Tests\Core
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SdAiAgent\Tests\Core;

use SdAiAgent\Core\SpeechLocaleResolver;
use WP_UnitTestCase;

/** Covers trusted WordPress locale normalization and untrusted client input. */
final class SpeechLocaleResolverTest extends WP_UnitTestCase {

	private SpeechLocaleResolver $resolver;

	public function set_up(): void {
		parent::set_up();
		$this->resolver = new SpeechLocaleResolver();
	}

	public function tear_down(): void {
		remove_all_filters( 'locale' );
		parent::tear_down();
	}

	/** WordPress underscore locales become conventionally-cased BCP-47 hints. */
	public function test_normalizes_wordpress_locales(): void {
		$this->assertSame( 'pt-BR', $this->resolver->normalize_wordpress_locale( 'pt_BR' ) );
		$this->assertSame( 'zh-Hant-TW', $this->resolver->normalize_wordpress_locale( 'zh_Hant_TW' ) );
		$this->assertSame( 'de-DE-formal', $this->resolver->normalize_wordpress_locale( 'de_DE_formal' ) );
	}

	/** Client locales must already use bounded BCP-47 syntax. */
	public function test_rejects_malformed_client_locales(): void {
		$this->assertNull( $this->resolver->normalize_client_locale( 'pt_BR' ) );
		$this->assertNull( $this->resolver->normalize_client_locale( '../en-US' ) );
		$this->assertNull( $this->resolver->normalize_client_locale( 'en-12' ) );
		$this->assertNull( $this->resolver->normalize_client_locale( 'en-US-abcd' ) );
		$this->assertNull( $this->resolver->normalize_client_locale( 'en-US<script>' ) );
		$this->assertNull( $this->resolver->normalize_client_locale( str_repeat( 'a', 100 ) ) );
	}

	/** Valid client tags are normalized without adding subtags. */
	public function test_normalizes_valid_client_locale_casing(): void {
		$this->assertSame( 'en-US', $this->resolver->normalize_client_locale( 'EN-us' ) );
		$this->assertSame( 'sr-Latn-RS', $this->resolver->normalize_client_locale( 'sr-latn-rs' ) );
		$this->assertSame( 'sl-rozaj-biske-1994', $this->resolver->normalize_client_locale( 'SL-ROZAJ-BISKE-1994' ) );
	}

	/** User and site hints remain separate while the user hint wins initially. */
	public function test_resolves_user_and_site_locale_precedence(): void {
		add_filter( 'locale', static fn(): string => 'pt_BR' );
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		update_user_meta( $user_id, 'locale', 'fr_FR' );
		wp_set_current_user( $user_id );

		$this->assertSame(
			array(
				'user_locale'    => 'fr-FR',
				'site_locale'    => 'pt-BR',
				'initial_locale' => 'fr-FR',
			),
			$this->resolver->resolve()
		);
	}

	/** The site fallback is not mislabeled as an explicit user preference. */
	public function test_resolves_site_locale_when_user_has_no_locale_override(): void {
		add_filter( 'locale', static fn(): string => 'pt_BR' );
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$this->assertSame(
			array(
				'user_locale'    => '',
				'site_locale'    => 'pt-BR',
				'initial_locale' => 'pt-BR',
			),
			$this->resolver->resolve()
		);
	}
}
