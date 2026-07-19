<?php

declare(strict_types=1);
/**
 * ModelHealthTracker
 *
 * Persistent per-model tool-use telemetry, used to flag models that should
 * be treated as "weak" so the agent loop can adapt its behaviour (extra
 * system-prompt guidance, smaller manifest, parallel_tool_calls=false, etc.).
 *
 * Two signals are combined to produce {@see is_weak()}:
 *
 *   1. **Name heuristics** — small / quantized / known-weak model id
 *      substrings. Used as a zero-shot bootstrap before any usage history
 *      exists. Strong models can still be flagged as weak by this filter
 *      until telemetry overrides it (handled in {@see is_weak()} via the
 *      sample-size gate).
 *
 *   2. **Runtime telemetry** — per-model success / validation_error /
 *      nudge counts. Computes a quality score and flips a model from
 *      strong→weak (or vice versa) once enough samples accumulate.
 *
 * Storage: a single autoload-disabled wp_option containing
 * model_id => { success, validation_error, nudge, last_used }.
 * LRU-pruned at MAX_ENTRIES.
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ModelHealthTracker {

	/**
	 * Option name for the persisted health map.
	 */
	public const OPTION_NAME = 'sd_ai_agent_model_health';

	/**
	 * Maximum number of distinct model ids tracked. Older entries (lowest
	 * `last_used`) are pruned when this is exceeded.
	 */
	public const MAX_ENTRIES = 50;

	/**
	 * Minimum total samples (success + validation_error + nudge) before
	 * telemetry is allowed to override the name heuristic. Below this,
	 * is_weak() falls back to the name heuristic only.
	 */
	public const MIN_SAMPLES_FOR_TELEMETRY = 10;

	/**
	 * Quality score below which a model is considered weak.
	 *
	 * Score = success / (success + validation_error + 5*nudge).
	 * 5x penalty on nudges because each one indicates the model spun on
	 * the same call twice — far worse than a one-off validation error.
	 */
	public const WEAK_SCORE_THRESHOLD = 0.7;

	/**
	 * Substrings in a model id that flag it as weak by name. Conservative
	 * — limited to explicit managed-model overrides, small parameter counts,
	 * and known-weak quantizations.
	 *
	 * @var string[]
	 */
	private const WEAK_NAME_HINTS = array(
		// Managed cloud fast route: bootstrap with stricter schema-following
		// guidance after field reports of empty required-argument tool calls.
		'gpt-5.6-luna',
		// Small parameter counts.
		'-1b',
		'-2b',
		'-3b',
		'-7b',
		'-8b',
		'-9b',
		'-13b',
		'-14b',
		// Common slug variants without dashes.
		'1.5b',
		'phi-2',
		'phi-3-mini',
		'gemma-2',
		'gemma-7',
		'mistral-7',
		'llama-3.2-1b',
		'llama-3.2-3b',
		'tinyllama',
		// Quantized open-weight builds.
		'gguf',
		'-q4_',
		'-q5_',
		'-q6_',
		'-q8_',
	);

	/**
	 * The model id currently in use, set by AgentLoop at the top of each
	 * run. Telemetry recording methods consult this so call sites don't
	 * need to know which model they're targeting.
	 *
	 * @var string
	 */
	private static string $current_model = '';

	// ─── Lifecycle ────────────────────────────────────────────────────

	/**
	 * Set the model id that subsequent record_*() calls should be attributed to.
	 *
	 * @param string $model_id Fully qualified model id (e.g. "claude-sonnet-4-6", "hf:moonshotai/Kimi-K2-Instruct-0905").
	 * @return void
	 */
	public static function set_current_model( string $model_id ): void {
		self::$current_model = $model_id;
	}

	/**
	 * Forget the current model id. Mostly for tests.
	 *
	 * @return void
	 */
	public static function clear_current_model(): void {
		self::$current_model = '';
	}

	/**
	 * Wipe all persisted telemetry. Test-only.
	 *
	 * @return void
	 */
	public static function reset(): void {
		delete_option( self::OPTION_NAME );
		self::$current_model = '';
	}

	// ─── Recording ────────────────────────────────────────────────────

	/**
	 * Record one successful ability invocation against the current model.
	 *
	 * @return void
	 */
	public static function record_success(): void {
		self::bump( 'success' );
	}

	/**
	 * Record one ability_invalid_input failure against the current model.
	 *
	 * @return void
	 */
	public static function record_validation_error(): void {
		self::bump( 'validation_error' );
	}

	/**
	 * Record one IdenticalFailureTracker nudge event against the current model.
	 * Counted separately because nudges are a much stronger signal of weakness
	 * than a single validation error.
	 *
	 * @return void
	 */
	public static function record_nudge(): void {
		self::bump( 'nudge' );
	}

	/**
	 * Record one voluntary `skill-load` tool call against the current model.
	 *
	 * Strong models are expected to call skill-load on their own when the
	 * skill index suggests a guide is relevant. Tracking this count lets
	 * the system detect models that consistently ignore the index hint
	 * (never calling skill-load despite it being present), which is a
	 * signal that they should be routed to the auto-injection path.
	 *
	 * Note: this counter is informational for Phase 2. A future task will
	 * incorporate it into the is_weak() scoring formula to automatically
	 * escalate index-ignoring models to auto-injection.
	 *
	 * @return void
	 */
	public static function record_skill_load(): void {
		self::bump( 'skill_load_count' );
	}

	// ─── Reading ──────────────────────────────────────────────────────

	/**
	 * Get the raw health record for a model id.
	 *
	 * @param string $model_id Model id.
	 * @return array{success:int,validation_error:int,nudge:int,skill_load_count:int,last_used:int}|null
	 */
	public static function get_health( string $model_id ): ?array {
		$map = self::load();
		return $map[ $model_id ] ?? null;
	}

	/**
	 * Compute the quality score for a model id, in [0, 1].
	 *
	 * Returns 1.0 when there's no telemetry yet (no opinion).
	 *
	 * @param string $model_id Model id.
	 * @return float
	 */
	public static function quality_score( string $model_id ): float {
		$health = self::get_health( $model_id );
		if ( ! $health ) {
			return 1.0;
		}

		$success     = (int) $health['success'];
		$invalid     = (int) $health['validation_error'];
		$nudge_count = (int) $health['nudge'];
		$denominator = $success + $invalid + ( 5 * $nudge_count );

		if ( 0 === $denominator ) {
			return 1.0;
		}

		return $success / $denominator;
	}

	/**
	 * Is the given model weak at tool use?
	 *
	 * Resolution order:
	 *   1. Empty model id → not weak (no opinion).
	 *   2. Telemetry has reached MIN_SAMPLES_FOR_TELEMETRY → score decides
	 *      (overriding the name heuristic in either direction).
	 *   3. Otherwise → name-heuristic decides.
	 *
	 * @param string $model_id Model id.
	 * @return bool
	 */
	public static function is_weak( string $model_id ): bool {
		if ( '' === $model_id ) {
			return false;
		}

		$health = self::get_health( $model_id );
		$total  = 0;
		if ( $health ) {
			$total = (int) $health['success'] + (int) $health['validation_error'] + (int) $health['nudge'];
		}

		if ( $total >= self::MIN_SAMPLES_FOR_TELEMETRY ) {
			return self::quality_score( $model_id ) < self::WEAK_SCORE_THRESHOLD;
		}

		return self::matches_weak_name( $model_id );
	}

	/**
	 * Pure name-heuristic check (no telemetry). Public so tests and
	 * cold-start helpers can use it without populating telemetry.
	 *
	 * @param string $model_id Model id.
	 * @return bool
	 */
	public static function matches_weak_name( string $model_id ): bool {
		if ( '' === $model_id ) {
			return false;
		}
		$lower = strtolower( $model_id );
		foreach ( self::WEAK_NAME_HINTS as $hint ) {
			if ( str_contains( $lower, $hint ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build the system-prompt nudge appended for weak models. Kept here so
	 * the wording lives next to the detection logic.
	 *
	 * @return string
	 */
	public static function weak_model_prompt_nudge(): string {
		return "## Tool-Use Discipline (important)\n"
			. "You have weaker tool-use accuracy than top-tier models. Follow these rules strictly:\n"
			. "- ALWAYS read an ability's `input_schema` and `Required:` fields before calling it.\n"
			. "- NEVER call an ability with empty arguments unless its schema lists no required fields.\n"
			. "- If a call returns `ability_invalid_input`, the response includes `example_arguments` — copy that stub, replace every `<placeholder>` with a real value, and call again. Do not retry with the same arguments.\n"
			. "- If you do not know a required value, call a different ability to fetch it (e.g. `customer-get-items` to find a customer id) before retrying.\n"
			. "- Make ONE tool call per turn. Do not parallelise.\n\n"
			. "## Worked Example\n\n"
			. "WRONG (you will fail):\n"
			. "  ability-call({\"ability\": \"sd-ai-agent/create-user\", \"arguments\": {}})\n\n"
			. "RIGHT — Step 1, search to learn the schema:\n"
			. "  ability-search({\"query\": \"select:sd-ai-agent/create-user\"})\n"
			. "  → returns input_schema with required: [\"username\", \"email\"]\n\n"
			. "RIGHT — Step 2, call with every required field filled in:\n"
			. "  ability-call({\n"
			. "    \"ability\": \"sd-ai-agent/create-user\",\n"
			. "    \"arguments\": {\n"
			. "      \"username\": \"alice\",\n"
			. "      \"email\":    \"alice@example.com\",\n"
			. "      \"role\":     \"subscriber\"\n"
			. "    }\n"
			. '  })';
	}

	// ─── Internals ────────────────────────────────────────────────────

	/**
	 * Increment one counter on the current model's record.
	 *
	 * @param string $field One of 'success', 'validation_error', 'nudge', 'skill_load_count'.
	 * @return void
	 */
	private static function bump( string $field ): void {
		if ( '' === self::$current_model ) {
			return;
		}

		$map      = self::load();
		$model_id = self::$current_model;
		$now      = time();

		$record = $map[ $model_id ] ?? array(
			'success'          => 0,
			'validation_error' => 0,
			'nudge'            => 0,
			'skill_load_count' => 0,
			'last_used'        => 0,
		);

		// Reconstruct the record with the targeted counter incremented.
		// Explicit shape reconstruction keeps the PHPStan array type narrow
		// (dynamic key mutation widens the inner array to array<string, int>).
		$map[ $model_id ] = array(
			'success'          => 'success' === $field ? ( $record['success'] + 1 ) : $record['success'],
			'validation_error' => 'validation_error' === $field ? ( $record['validation_error'] + 1 ) : $record['validation_error'],
			'nudge'            => 'nudge' === $field ? ( $record['nudge'] + 1 ) : $record['nudge'],
			'skill_load_count' => 'skill_load_count' === $field ? ( $record['skill_load_count'] + 1 ) : $record['skill_load_count'],
			'last_used'        => $now,
		);

		if ( count( $map ) > self::MAX_ENTRIES ) {
			$map = self::prune_internal( $map, self::MAX_ENTRIES );
		}

		update_option( self::OPTION_NAME, $map, false );
	}

	/**
	 * Load and normalise the persisted map.
	 *
	 * @return array<string, array{success:int,validation_error:int,nudge:int,skill_load_count:int,last_used:int}>
	 */
	private static function load(): array {
		$raw = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $name => $entry ) {
			if ( ! is_string( $name ) || '' === $name || ! is_array( $entry ) ) {
				continue;
			}
			$out[ $name ] = array(
				'success'          => isset( $entry['success'] ) ? (int) $entry['success'] : 0,
				'validation_error' => isset( $entry['validation_error'] ) ? (int) $entry['validation_error'] : 0,
				'nudge'            => isset( $entry['nudge'] ) ? (int) $entry['nudge'] : 0,
				// skill_load_count added in Phase 2 (t217). Default 0 for existing records.
				'skill_load_count' => isset( $entry['skill_load_count'] ) ? (int) $entry['skill_load_count'] : 0,
				'last_used'        => isset( $entry['last_used'] ) ? (int) $entry['last_used'] : 0,
			);
		}
		return $out;
	}

	/**
	 * Drop the least-recently-used entries until the map fits MAX_ENTRIES.
	 *
	 * @param array<string, array{success:int,validation_error:int,nudge:int,skill_load_count:int,last_used:int}> $map The current map.
	 * @param int                                                                                                 $max Maximum number of entries to keep.
	 * @return array<string, array{success:int,validation_error:int,nudge:int,skill_load_count:int,last_used:int}>
	 */
	private static function prune_internal( array $map, int $max ): array {
		if ( count( $map ) <= $max ) {
			return $map;
		}

		uasort(
			$map,
			static function ( $a, $b ) {
				return (int) $b['last_used'] - (int) $a['last_used'];
			}
		);

		return array_slice( $map, 0, $max, true );
	}
}
