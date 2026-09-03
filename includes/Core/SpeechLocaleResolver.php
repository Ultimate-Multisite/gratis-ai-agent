<?php

declare(strict_types=1);

namespace SdAiAgent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves WordPress speech-language hints without treating locale as authority.
 */
final class SpeechLocaleResolver {

	/**
	 * Resolve the current user's and site's locale hints.
	 *
	 * @return array{user_locale:string,site_locale:string,initial_locale:string}
	 */
	public function resolve(): array {
		$user        = wp_get_current_user();
		$user_value  = $user->exists() && is_string( $user->locale ) ? $user->locale : '';
		$user_locale = $this->normalize_wordpress_locale( $user_value ) ?? '';
		$site_locale = $this->normalize_wordpress_locale( get_locale() ) ?? '';

		return array(
			'user_locale'    => $user_locale,
			'site_locale'    => $site_locale,
			'initial_locale' => '' !== $user_locale ? $user_locale : $site_locale,
		);
	}

	/**
	 * Normalize a trusted WordPress locale to a conservative BCP-47 tag.
	 */
	public function normalize_wordpress_locale( string $locale ): ?string {
		$locale = str_replace( array( '_', '@' ), '-', trim( $locale ) );

		return $this->normalize_bcp47( $locale );
	}

	/**
	 * Validate and normalize an untrusted client-supplied BCP-47 tag.
	 */
	public function normalize_client_locale( string $locale ): ?string {
		$locale = trim( $locale );
		if ( str_contains( $locale, '_' ) || str_contains( $locale, '@' ) ) {
			return null;
		}

		return $this->normalize_bcp47( $locale );
	}

	/**
	 * Apply conservative BCP-47 syntax and conventional casing.
	 */
	private function normalize_bcp47( string $locale ): ?string {
		if ( '' === $locale || strlen( $locale ) > 63 ) {
			return null;
		}

		$parts    = explode( '-', $locale );
		$language = array_shift( $parts );
		if ( ! is_string( $language ) || 1 !== preg_match( '/^[A-Za-z]{2,3}$/', $language ) ) {
			return null;
		}

		$normalised = array( strtolower( $language ) );
		if ( isset( $parts[0] ) && 1 === preg_match( '/^[A-Za-z]{4}$/', $parts[0] ) ) {
			$normalised[] = ucfirst( strtolower( array_shift( $parts ) ) );
		}

		if ( isset( $parts[0] ) && 1 === preg_match( '/^(?:[A-Za-z]{2}|[0-9]{3})$/', $parts[0] ) ) {
			$normalised[] = strtoupper( array_shift( $parts ) );
		}

		foreach ( $parts as $part ) {
			if ( 1 !== preg_match( '/^(?:[A-Za-z0-9]{5,8}|[0-9][A-Za-z0-9]{3})$/', $part ) ) {
				return null;
			}

			$normalised[] = strtolower( $part );
		}

		return implode( '-', $normalised );
	}
}
