<?php

declare(strict_types=1);
/**
 * Tracks browser evidence collected after a site-file mutation.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Core;

/**
 * Prevents rendered-output claims when the current mutation has no later browser evidence.
 */
final class RenderedOutputEvidenceGate {

	/** @var array<string,list<array<string,mixed>>> */
	private array $pending_calls = array();

	private bool $mutation_requires_evidence = false;

	private bool $has_post_mutation_evidence = false;

	private bool $browser_evidence_available;

	private string $last_failure = '';

	/**
	 * @param array<string> $client_ability_names Browser abilities available in this run.
	 */
	public function __construct( array $client_ability_names = array() ) {
		$this->browser_evidence_available = ! empty(
			array_intersect(
				$client_ability_names,
				array(
					'sd-ai-agent-js/capture-screenshot',
					'sd-ai-agent-js/screenshot-url',
					PageCompletionGate::CLIENT_ABILITY,
				)
			)
		);
	}

	/**
	 * Rebuild state from persisted tool activity after a paused client-tool run.
	 *
	 * @param list<array<string,mixed>> $tool_call_log Ordered activity log.
	 */
	public function replay_tool_call_log( array $tool_call_log ): void {
		foreach ( $tool_call_log as $entry ) {
			if ( 'call' === ( $entry['type'] ?? '' ) ) {
				$args = $entry['args'] ?? array();
				$this->record_tool_call( (string) ( $entry['name'] ?? '' ), is_array( $args ) ? $args : array() );
				continue;
			}

			if ( 'response' === ( $entry['type'] ?? '' ) ) {
				$this->record_tool_response( (string) ( $entry['name'] ?? '' ), $entry['response'] ?? array() );
			}
		}
	}

	/**
	 * Record a dispatched call before its response is available.
	 *
	 * @param string              $tool_name Ability name as sent to the provider.
	 * @param array<string,mixed> $args      Normalized tool arguments.
	 */
	public function record_tool_call( string $tool_name, array $args ): void {
		$name = self::normalize_tool_name( $tool_name );
		if ( '' !== $name ) {
			$this->pending_calls[ $name ][] = $args;
		}
	}

	/**
	 * Record a response and establish whether it supports the current rendered output.
	 *
	 * @param string $tool_name Ability name as returned by the provider/client.
	 * @param mixed  $response  Raw response payload.
	 */
	public function record_tool_response( string $tool_name, $response ): void {
		$name      = self::normalize_tool_name( $tool_name );
		$call_args = $this->consume_pending_call( $name );
		$payload   = self::normalize_response( $response );
		if ( '' === $name || ! self::is_successful_response( $payload ) ) {
			return;
		}

		// Meta-tool responses retain the actual target and result. Replay that
		// nested pair so Tier-2 file/theme mutations cannot bypass this gate.
		if ( 'sd-ai-agent/ability-call' === $name && true === ( $payload['success'] ?? false ) ) {
			$target = self::normalize_tool_name( (string) ( $payload['ability'] ?? $call_args['ability'] ?? '' ) );
			$args   = $call_args['arguments'] ?? array();
			$result = $payload['result'] ?? array();
			if ( '' !== $target && 'sd-ai-agent/ability-call' !== $target ) {
				$this->record_tool_call( $target, is_array( $args ) ? $args : array() );
				$this->record_tool_response( $target, $result );
			}
			return;
		}

		if ( self::is_rendered_mutation( $name ) ) {
			$this->mutation_requires_evidence = true;
			$this->has_post_mutation_evidence = false;
			$this->last_failure               = 'The successful rendered-output mutation has no later browser screenshot, DOM inspection, or page-quality result.';
			return;
		}

		// A scheduled refresh is navigation only. It deliberately cannot satisfy
		// rendered-output evidence because no post-refresh document was inspected.
		if ( 'sd-ai-agent-js/refresh-page' === $name ) {
			return;
		}

		if ( $this->mutation_requires_evidence && self::is_browser_evidence( $name, $payload, $call_args ) ) {
			$this->has_post_mutation_evidence = true;
			$this->last_failure               = '';
		}
	}

	/** Whether the reply claims visual/rendered success without current evidence. */
	public function blocks_rendered_claim( string $reply ): bool {
		return $this->mutation_requires_evidence
			&& ! $this->has_post_mutation_evidence
			&& self::contains_rendered_claim( $reply );
	}

	/** Return an honest replacement for an unsupported rendered-success claim. */
	public function get_terminal_notice(): string {
		if ( ! $this->mutation_requires_evidence || $this->has_post_mutation_evidence ) {
			return '';
		}

		$availability = $this->browser_evidence_available
			? 'No post-mutation browser evidence was captured.'
			: 'Browser verification was unavailable in this client.';
		return 'The change was saved, but its rendered result remains unverified. ' . $availability . ' I cannot claim that the rendered page was checked or visually verified.';
	}

	/** @return array<string,mixed> */
	public function get_status(): array {
		return array(
			'required'                   => $this->mutation_requires_evidence,
			'has_post_mutation_evidence' => $this->has_post_mutation_evidence,
			'browser_evidence_available' => $this->browser_evidence_available,
			'last_failure'               => $this->last_failure,
		);
	}

	/** @return array<string,mixed> */
	private function consume_pending_call( string $name ): array {
		if ( '' === $name || empty( $this->pending_calls[ $name ] ) ) {
			return array();
		}

		$args = array_shift( $this->pending_calls[ $name ] );
		if ( empty( $this->pending_calls[ $name ] ) ) {
			unset( $this->pending_calls[ $name ] );
		}

		return is_array( $args ) ? $args : array();
	}

	private static function is_rendered_mutation( string $name ): bool {
		return in_array(
			$name,
			array(
				'sd-ai-agent/file-write',
				'sd-ai-agent/file-edit',
				'sd-ai-agent/file-delete',
				'sd-ai-agent/scaffold-block-theme',
				'sd-ai-agent/update-global-styles',
				'sd-ai-agent/reset-global-styles',
				'sd-ai-agent/create-style-variation',
				'sd-ai-agent/update-style-variation',
				'sd-ai-agent/select-style-variation',
				'sd-ai-agent/reset-style-variation',
			),
			true
		);
	}

	/**
	 * @param string              $name      Normalized ability name.
	 * @param array<string,mixed> $payload   Normalized response.
	 * @param array<string,mixed> $call_args Original call arguments.
	 */
	private static function is_browser_evidence( string $name, array $payload, array $call_args ): bool {
		if ( in_array( $name, array( 'sd-ai-agent-js/capture-screenshot', 'sd-ai-agent-js/screenshot-url' ), true ) ) {
			return true === ( $payload['success'] ?? false )
				&& ( is_string( $payload['image'] ?? null ) || true === ( $payload['attached_to_model'] ?? false ) );
		}

		if ( PageCompletionGate::CLIENT_ABILITY === $name ) {
			return true === ( $payload['success'] ?? false )
				&& true === ( $payload['complete'] ?? false )
				&& true === ( $payload['passed'] ?? false )
				&& ! empty( $call_args );
		}

		return false;
	}

	private static function contains_rendered_claim( string $reply ): bool {
		return 1 === preg_match(
			'/\b(?:checked|check|verified|verify|validated|validate|inspected|inspect)\b[^.]{0,80}\b(?:rendered|visual(?:ly)?|page|output|site)\b|\b(?:rendered|visual(?:ly)?|page|output|site)\b[^.]{0,80}\b(?:checked|verified|validated|inspected)\b/i',
			$reply
		);
	}

	private static function normalize_tool_name( string $tool_name ): string {
		if ( str_starts_with( $tool_name, 'wpab__sd-ai-agent__' ) ) {
			return 'sd-ai-agent/' . substr( $tool_name, strlen( 'wpab__sd-ai-agent__' ) );
		}
		if ( str_starts_with( $tool_name, 'wpab__sd-ai-agent-js__' ) ) {
			return 'sd-ai-agent-js/' . substr( $tool_name, strlen( 'wpab__sd-ai-agent-js__' ) );
		}
		return $tool_name;
	}

	/** @param mixed $response */
	private static function normalize_response( $response ): array {
		if ( is_string( $response ) && '' !== $response ) {
			$decoded  = json_decode( $response, true );
			$response = is_array( $decoded ) ? $decoded : array( 'error' => $response );
		}
		if ( ! is_array( $response ) ) {
			return array();
		}

		return $response;
	}

	/** @param array<string,mixed> $response */
	private static function is_successful_response( array $response ): bool {
		return ! empty( $response )
			&& empty( $response['error'] ?? '' )
			&& false !== ( $response['success'] ?? true )
			&& 'proposal_pending' !== (string) ( $response['status'] ?? '' );
	}
}
