<?php

declare(strict_types=1);
/**
 * Server-side proposal registry for file-write and file-edit abilities.
 *
 * Stores proposals in transients with a 15-minute TTL. Each proposal is
 * single-use: applying or rejecting deletes the record.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ProposalRegistry {

	/**
	 * Transient prefix for proposal storage.
	 */
	const TRANSIENT_PREFIX = 'sd_ai_agent_proposal_';

	/**
	 * Proposal TTL in seconds (15 minutes).
	 */
	const PROPOSAL_TTL = 900;

	/**
	 * Create a new proposal and return its ID.
	 *
	 * @param string $ability_name The ability name (e.g., 'sd-ai-agent/file-write').
	 * @param array<string,mixed> $arguments The original ability arguments.
	 * @param int $user_id The current user ID.
	 * @return string UUID v4 proposal ID.
	 */
	public static function create(
		string $ability_name,
		array $arguments,
		int $user_id
	): string {
		$proposal_id = self::generate_uuid();

		$proposal = array(
			'id'           => $proposal_id,
			'ability_name' => $ability_name,
			'arguments'    => $arguments,
			'user_id'      => $user_id,
			'created_at'   => time(),
		);

		set_transient(
			self::TRANSIENT_PREFIX . $proposal_id,
			$proposal,
			self::PROPOSAL_TTL
		);

		return $proposal_id;
	}

	/**
	 * Retrieve a proposal by ID.
	 *
	 * @param string $proposal_id The proposal ID.
	 * @return array<string,mixed>|WP_Error The proposal data, or WP_Error if not found or expired.
	 */
	public static function get( string $proposal_id ) {
		$proposal = get_transient( self::TRANSIENT_PREFIX . $proposal_id );

		if ( false === $proposal ) {
			return new WP_Error(
				'proposal_not_found',
				__( 'Proposal not found or has expired.', 'superdav-ai-agent' ),
				array( 'status' => 410 )
			);
		}

		return $proposal;
	}

	/**
	 * Delete a proposal (single-use).
	 *
	 * @param string $proposal_id The proposal ID.
	 * @return bool True if deleted, false if not found.
	 */
	public static function delete( string $proposal_id ): bool {
		return delete_transient( self::TRANSIENT_PREFIX . $proposal_id );
	}

	/**
	 * Generate a UUID v4.
	 *
	 * @return string UUID v4 string.
	 */
	private static function generate_uuid(): string {
		// Generate 16 random bytes.
		$bytes = random_bytes( 16 );

		// Set version to 4 (random).
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );

		// Set variant to RFC 4122.
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );

		// Format as UUID string.
		return vsprintf(
			'%s%s-%s-%s-%s-%s%s%s',
			str_split( bin2hex( $bytes ), 4 )
		);
	}
}
