<?php

declare(strict_types=1);
/**
 * Validates the compact durable-plan shape returned by a planning-only model turn.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

use SdAiAgent\Models\DurablePlanRepository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DurablePlanDefinitionParser {

	private const MAX_RESPONSE_BYTES = 48000;

	/**
	 * Parse a planning-only model response into the narrow durable-plan definition
	 * accepted by DurablePlanRepository.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function parse( string $response ) {
		$response = trim( $response );
		if ( '' === $response || strlen( $response ) > self::MAX_RESPONSE_BYTES ) {
			return self::invalid_response();
		}

		try {
			$decoded = json_decode( $response, true, 32, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $e ) {
			return self::invalid_response();
		}

		if ( ! is_array( $decoded ) || array_is_list( $decoded ) || ! self::has_only_keys( $decoded, array( 'scope', 'summary', 'steps' ) ) ) {
			return self::invalid_response();
		}

		$scope = self::read_text( $decoded, 'scope', 1200, true );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}
		$summary = self::read_text( $decoded, 'summary', 600, false );
		if ( is_wp_error( $summary ) ) {
			return $summary;
		}

		$raw_steps = $decoded['steps'] ?? null;
		if ( ! is_array( $raw_steps ) || ! array_is_list( $raw_steps ) || empty( $raw_steps ) || count( $raw_steps ) > 20 ) {
			return self::invalid_response();
		}

		$steps = array();
		foreach ( $raw_steps as $raw_step ) {
			if ( ! is_array( $raw_step ) || array_is_list( $raw_step ) || ! self::has_only_keys( $raw_step, array( 'title', 'instruction', 'classification', 'preconditions', 'expected_evidence', 'rollback_guidance' ) ) ) {
				return self::invalid_response();
			}

			$title             = self::read_text( $raw_step, 'title', 255, true );
			$instruction       = self::read_text( $raw_step, 'instruction', 1600, true );
			$classification    = self::read_text( $raw_step, 'classification', 32, true );
			$preconditions     = self::read_text( $raw_step, 'preconditions', 600, false );
			$expected_evidence = self::read_text( $raw_step, 'expected_evidence', 600, false );
			$rollback_guidance = self::read_text( $raw_step, 'rollback_guidance', 600, false );
			if (
				is_wp_error( $title )
				|| is_wp_error( $instruction )
				|| is_wp_error( $classification )
				|| is_wp_error( $preconditions )
				|| is_wp_error( $expected_evidence )
				|| is_wp_error( $rollback_guidance )
				|| ! in_array( $classification, DurablePlanRepository::CLASSIFICATIONS, true )
			) {
				return self::invalid_response();
			}

			$steps[] = array(
				'title'             => $title,
				'instruction'       => $instruction,
				'classification'    => $classification,
				'preconditions'     => $preconditions,
				'expected_evidence' => $expected_evidence,
				'rollback_guidance' => $rollback_guidance,
			);
		}

		return array(
			'scope'   => $scope,
			'summary' => $summary,
			'steps'   => $steps,
		);
	}

	/**
	 * Ensure a JSON object contains no fields outside its narrow accepted schema.
	 *
	 * @param array<string, mixed> $value JSON object.
	 * @param array<string>        $keys  Allowed keys.
	 */
	private static function has_only_keys( array $value, array $keys ): bool {
		return empty( array_diff( array_keys( $value ), $keys ) );
	}

	/**
	 * Read a bounded scalar text field without retaining unknown JSON structures.
	 *
	 * @param array<string, mixed> $value    JSON object.
	 * @param string               $key      Field name.
	 * @param int                  $max_size Maximum byte length.
	 * @param bool                 $required Whether the field is required and non-empty.
	 * @return string|WP_Error
	 */
	private static function read_text( array $value, string $key, int $max_size, bool $required ) {
		if ( ! array_key_exists( $key, $value ) ) {
			return $required ? self::invalid_response() : '';
		}
		if ( ! is_string( $value[ $key ] ) ) {
			return self::invalid_response();
		}

		$text = trim( $value[ $key ] );
		if ( ( $required && '' === $text ) || strlen( $text ) > $max_size ) {
			return self::invalid_response();
		}

		return $text;
	}

	/**
	 * Return a generic error without reflecting untrusted model output.
	 */
	private static function invalid_response(): WP_Error {
		return new WP_Error(
			'sd_ai_agent_durable_plan_invalid_response',
			__( 'The planning response was not a valid compact plan. Please try again.', 'superdav-ai-agent' )
		);
	}
}
