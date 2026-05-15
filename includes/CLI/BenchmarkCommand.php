<?php

declare(strict_types=1);
/**
 * WP-CLI benchmark command.
 *
 * Drives the full AI agent loop against live WordPress and writes a structured
 * log file for every question run.  No database writes — results live entirely
 * in log files under the uploads directory ({uploads}/sd-ai-agent/benchmark-logs/)
 * by default, or any absolute path passed via --log-dir.
 *
 * Usage:
 *   wp sd-ai-agent benchmark run --suite=functional-v1 --provider=anthropic --model=claude-sonnet-4-6
 *   wp sd-ai-agent benchmark run --question=fn-001 --provider=openai --model=gpt-4o
 *   wp sd-ai-agent benchmark suites
 *   wp sd-ai-agent benchmark questions --suite=functional-v1
 *
 * @package SdAiAgent\CLI
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\CLI;

use SdAiAgent\Benchmark\AssertionEngine;
use SdAiAgent\Benchmark\BenchmarkSuite;
use SdAiAgent\Core\AgentLoop;
use SdAiAgent\Core\ProviderCredentialLoader;
use SdAiAgent\Tools\ToolDiscovery;
use WP_CLI;
use WP_CLI_Command;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run functional AI agent benchmarks against a live WordPress environment.
 *
 * ## EXAMPLES
 *
 *   # Run all questions in the functional-v1 suite with Claude Sonnet
 *   wp sd-ai-agent benchmark run --suite=functional-v1 --provider=ultimate-ai-connector-anthropic-max --model=claude-sonnet-4-6
 *
 *   # Run a single question
 *   wp sd-ai-agent benchmark run --question=fn-001 --provider=ultimate-ai-connector-anthropic-max --model=claude-sonnet-4-6
 *
 *   # List all suites
 *   wp sd-ai-agent benchmark suites
 *
 *   # List questions in a suite
 *   wp sd-ai-agent benchmark questions --suite=functional-v1
 */
class BenchmarkCommand extends WP_CLI_Command {

	/**
	 * Subdirectory inside the WordPress uploads directory where benchmark
	 * log files are written by default. The full default path is therefore
	 * `{uploads_basedir}/sd-ai-agent/benchmark-logs/`, which is outside the
	 * plugin folder (so it survives plugin upgrades) and outside the document
	 * root's reach for direct download (governed by the uploads dir policy).
	 *
	 * Operators can override the absolute path per-run via `--log-dir=<path>`.
	 */
	private const LOG_SUBDIR = 'sd-ai-agent/benchmark-logs';

	/**
	 * System prompt injected for every benchmark question.
	 * Tells the agent it is in a live WordPress environment and should act, not describe.
	 */
	private const SYSTEM_PROMPT = 'You are a WordPress AI Agent operating in a real, live WordPress installation. When given a task you MUST complete it immediately using your tools — do not describe what you would do. Write actual working code, create real files, activate plugins, and make real changes. The environment is yours to modify. After completing a task always confirm what was done and that it worked.';

	// ── Subcommands ───────────────────────────────────────────────────────────

	/**
	 * List available benchmark suites.
	 *
	 * ## EXAMPLES
	 *
	 *   wp sd-ai-agent benchmark suites
	 *
	 * @subcommand suites
	 */
	public function suites(): void {
		$suites = BenchmarkSuite::list_suites();

		$rows = array_map(
			fn( $s ) => array(
				'slug'        => $s['slug'],
				'name'        => $s['name'],
				'questions'   => $s['question_count'],
				'description' => $s['description'],
			),
			$suites
		);

		WP_CLI\Utils\format_items( 'table', $rows, array( 'slug', 'name', 'questions', 'description' ) );
	}

	/**
	 * List questions in a suite.
	 *
	 * ## OPTIONS
	 *
	 * [--suite=<slug>]
	 * : Suite slug. Default: functional-v1
	 *
	 * ## EXAMPLES
	 *
	 *   wp sd-ai-agent benchmark questions --suite=functional-v1
	 *
	 * @subcommand questions
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function questions( array $args, array $assoc_args ): void {
		$suite_slug = (string) ( $assoc_args['suite'] ?? 'functional-v1' );
		$questions  = BenchmarkSuite::get_questions( $suite_slug );

		if ( empty( $questions ) ) {
			WP_CLI::error( "Suite '{$suite_slug}' not found." );
		}

		$rows = array_map(
			fn( $q ) => array(
				'id'         => $q['id'],
				'category'   => $q['category'],
				'max_turns'  => $q['max_turns'],
				'assertions' => count( $q['assertions'] ),
				'prompt'     => substr( $q['prompt'], 0, 80 ) . '…',
			),
			$questions
		);

		WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'category', 'max_turns', 'assertions', 'prompt' ) );
	}

	/**
	 * Run benchmark questions against the AI agent.
	 *
	 * Runs each question through the full AgentLoop with all tools available.
	 * A log file is written for every question under
	 * `{uploads_basedir}/sd-ai-agent/benchmark-logs/` (typically
	 * `wp-content/uploads/sd-ai-agent/benchmark-logs/`). Plugins created
	 * during functional tests are cleaned up after each question.
	 *
	 * ## OPTIONS
	 *
	 * [--suite=<slug>]
	 * : Suite slug to run. Default: functional-v1
	 *
	 * [--question=<id>]
	 * : Run a single question by ID (e.g. fn-001). Overrides --suite.
	 *
	 * [--provider=<id>]
	 * : Provider ID as registered in the WP AI SDK (e.g. ultimate-ai-connector-anthropic-max).
	 *
	 * [--model=<id>]
	 * : Model ID (e.g. claude-sonnet-4-6, gpt-4o).
	 *
	 * [--log-dir=<path>]
	 * : Absolute path for log output. Defaults to
	 * `{uploads_basedir}/sd-ai-agent/benchmark-logs/`.
	 *
	 * [--keep-plugins]
	 * : Keep generated sandbox plugins on disk after the run for debugging.
	 *
	 * ## EXAMPLES
	 *
	 *   wp sd-ai-agent benchmark run --suite=functional-v1 --provider=ultimate-ai-connector-anthropic-max --model=claude-sonnet-4-6
	 *   wp sd-ai-agent benchmark run --question=fn-003 --provider=openai --model=gpt-4o
	 *
	 * @subcommand run
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function run( array $args, array $assoc_args ): void {
		$suite_slug  = (string) ( $assoc_args['suite'] ?? 'functional-v1' );
		$question_id = (string) ( $assoc_args['question'] ?? '' );
		$provider_id = (string) ( $assoc_args['provider'] ?? '' );
		$model_id    = (string) ( $assoc_args['model'] ?? '' );
		$log_dir     = (string) ( $assoc_args['log-dir'] ?? '' );
		$no_cleanup  = isset( $assoc_args['keep-plugins'] );

		// Resolve questions to run.
		if ( '' !== $question_id ) {
			$all       = BenchmarkSuite::get_questions( $suite_slug );
			$questions = array_values( array_filter( $all, fn( $q ) => $q['id'] === $question_id ) );
			if ( empty( $questions ) ) {
				// Try all suites.
				foreach ( BenchmarkSuite::list_suites() as $suite ) {
					$all       = BenchmarkSuite::get_questions( $suite['slug'] );
					$questions = array_values( array_filter( $all, fn( $q ) => $q['id'] === $question_id ) );
					if ( ! empty( $questions ) ) {
						break;
					}
				}
			}
			if ( empty( $questions ) ) {
				WP_CLI::error( "Question '{$question_id}' not found in any suite." );
			}
		} else {
			$questions = BenchmarkSuite::get_questions( $suite_slug );
			if ( empty( $questions ) ) {
				WP_CLI::error( "Suite '{$suite_slug}' not found or has no questions." );
			}
		}

		// Resolve log directory.
		//
		// The default lives inside the WordPress uploads directory, NOT inside
		// the plugin folder, so logs survive plugin upgrades (which delete the
		// plugin directory) and are compatible with multisite/uploads-relocation
		// configurations. Operators can pass --log-dir=<abs-path> to override.
		if ( '' === $log_dir ) {
			$uploads = wp_upload_dir( null, false );
			if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
				WP_CLI::error(
					sprintf(
						'Could not resolve the WordPress uploads directory for benchmark logs: %s',
						(string) ( $uploads['error'] ?? 'unknown error' )
					)
				);
			}
			$log_dir = trailingslashit( $uploads['basedir'] ) . self::LOG_SUBDIR;
		}

		$run_id  = gmdate( 'Y-m-d_His' ) . '_' . ( $model_id ?: 'default' );
		$run_dir = $log_dir . '/' . $run_id;

		if ( ! wp_mkdir_p( $run_dir ) ) {
			WP_CLI::error( "Could not create log directory: {$run_dir}" );
		}

		// Ensure credentials are loaded for the AI SDK.
		ProviderCredentialLoader::load();

		// Set the current user to an administrator so abilities that gate on
		// current_user_can( 'edit_posts' )/'manage_options' work in CLI context.
		// Without this every ability returns ability_invalid_permissions.
		$admin_id = 0;
		if ( is_multisite() ) {
			$super_admins = get_super_admins();
			if ( ! empty( $super_admins ) ) {
				$user = get_user_by( 'login', $super_admins[0] );
				if ( $user ) {
					$admin_id = (int) $user->ID;
				}
			}
		}
		if ( 0 === $admin_id ) {
			$admins = get_users(
				array(
					'role'    => 'administrator',
					'number'  => 1,
					'orderby' => 'ID',
				)
			);
			if ( ! empty( $admins ) ) {
				$admin_id = (int) $admins[0]->ID;
			}
		}
		if ( $admin_id > 0 ) {
			wp_set_current_user( $admin_id );
		} else {
			WP_CLI::warning( 'No administrator user found — abilities may fail with permission errors.' );
		}

		WP_CLI::log( '' );
		WP_CLI::log( '╔══════════════════════════════════════════════════════════════╗' );
		WP_CLI::log( '║  Superdav AI Agent — Functional Benchmark                    ║' );
		WP_CLI::log( '╚══════════════════════════════════════════════════════════════╝' );
		WP_CLI::log( sprintf( '  Provider : %s', $provider_id ?: 'default' ) );
		WP_CLI::log( sprintf( '  Model    : %s', $model_id ?: 'default' ) );
		WP_CLI::log( sprintf( '  Questions: %d', count( $questions ) ) );
		WP_CLI::log( sprintf( '  Logs     : %s', $run_dir ) );
		WP_CLI::log( '' );

		$totals = array(
			'passed'    => 0,
			'failed'    => 0,
			'questions' => 0,
			'errors'    => 0,
		);

		foreach ( $questions as $question ) {
			$q_id = (string) $question['id'];
			WP_CLI::log( "┌─ [{$q_id}] " . substr( $question['prompt'], 0, 70 ) . '…' );

			$result            = $this->run_question( $question, $provider_id, $model_id, $no_cleanup );
			$log_file          = $run_dir . "/{$q_id}.json";
			$result['run_id']  = $run_id;
			$result['log_dir'] = $run_dir;

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- CLI-only log writing; WP_Filesystem not initialised in CLI context.
			file_put_contents( $log_file, wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

			// Print summary.
			if ( ! empty( $result['agent_error'] ) ) {
				WP_CLI::log( '│  ✗ Agent error: ' . $result['agent_error'] );
				++$totals['errors'];
			} else {
				$turns = $result['turns_used'] ?? 0;
				WP_CLI::log( "│  Agent finished in {$turns} turn(s)" );
			}

			$assertions = $result['assertions'] ?? array();
			if ( ! empty( $assertions['results'] ) ) {
				foreach ( $assertions['results'] as $ar ) {
					$icon = $ar['pass'] ? '✓' : '✗';
					$line = "│  {$icon} {$ar['description']}: {$ar['actual']}";
					if ( $ar['pass'] ) {
						WP_CLI::log( $line );
					} else {
						WP_CLI::warning( $line );
					}
				}
				$p = $assertions['passed'];
				$t = $assertions['total'];
				WP_CLI::log( "│  Score: {$p}/{$t} assertions passed" );
				$totals['passed'] += $p;
				$totals['failed'] += $assertions['failed'];
			}

			WP_CLI::log( '└─ Log: ' . $log_file );
			WP_CLI::log( '' );
			++$totals['questions'];
		}

		// Summary.
		$total_assertions = $totals['passed'] + $totals['failed'];
		WP_CLI::log( '══════════════════════════════════════════════════════════════' );
		WP_CLI::log( sprintf( '  Questions run : %d', $totals['questions'] ) );
		WP_CLI::log( sprintf( '  Agent errors  : %d', $totals['errors'] ) );
		WP_CLI::log( sprintf( '  Assertions    : %d/%d passed', $totals['passed'], $total_assertions ) );
		if ( $total_assertions > 0 ) {
			$pct = round( ( $totals['passed'] / $total_assertions ) * 100 );
			WP_CLI::log( sprintf( '  Score         : %d%%', $pct ) );
		}
		WP_CLI::log( '' );

		// Exit with non-zero if any assertions failed (useful for CI).
		if ( $totals['failed'] > 0 || $totals['errors'] > 0 ) {
			WP_CLI::halt( 1 );
		}
	}

	// ── Question execution ────────────────────────────────────────────────────

	/**
	 * Run a single benchmark question through the full agent loop.
	 *
	 * Creates a uniquely-named sandbox plugin directory, passes it to the
	 * agent as context, runs the loop, then runs assertions and cleans up.
	 *
	 * @param array<string, mixed> $question    Question definition.
	 * @param string               $provider_id Provider ID.
	 * @param string               $model_id    Model ID.
	 * @return array<string, mixed> Full result including agent output, tool log, and assertions.
	 */
	private function run_question( array $question, string $provider_id, string $model_id, bool $no_cleanup = false ): array {
		$q_id       = (string) $question['id'];
		$start_time = microtime( true );
		$log        = array(
			'question_id' => $q_id,
			'category'    => $question['category'],
			'prompt'      => $question['prompt'],
			'provider_id' => $provider_id,
			'model_id'    => $model_id,
			'started_at'  => gmdate( 'c' ),
		);

		// For plugin-building questions, create an isolated sandbox plugin slug.
		$plugin_slug   = '';
		$needs_plugin  = $this->question_needs_plugin( $question );
		$assertion_ctx = array();

		if ( $needs_plugin ) {
			// Derive expected slug from the prompt (agent is told this name).
			$plugin_slug                  = $this->extract_plugin_slug( $question['prompt'] );
			$assertion_ctx['plugin_slug'] = $plugin_slug;
			$log['plugin_slug']           = $plugin_slug;
		}

		// Build the prompt — include plugin name guidance for plugin questions.
		$prompt = $question['prompt'];
		if ( $needs_plugin && '' !== $plugin_slug ) {
			$prompt .= "\n\nIMPORTANT: The plugin slug and directory name must be exactly \"{$plugin_slug}\". The main plugin file must be \"{$plugin_slug}/{$plugin_slug}.php\".";
		}

		try {
			// Run the agent loop.
			$agent_result = $this->run_agent_loop( $prompt, $provider_id, $model_id, (int) ( $question['max_turns'] ?? 20 ) );

			$log['turns_used']    = $agent_result['turns_used'] ?? 0;
			$log['token_usage']   = $agent_result['token_usage'] ?? array();
			$log['tool_call_log'] = $agent_result['tool_call_log'] ?? array();
			$log['agent_reply']   = $agent_result['reply'] ?? '';
			$log['elapsed_ms']    = (int) ( ( microtime( true ) - $start_time ) * 1000 );

			if ( ! empty( $agent_result['error'] ) ) {
				$log['agent_error']  = $agent_result['error'];
				$log['assertions']   = array(
					'passed'  => 0,
					'failed'  => 0,
					'total'   => 0,
					'results' => array(),
				);
				$log['completed_at'] = gmdate( 'c' );
				return $log;
			}

			// Run assertions against live WordPress state. `rest_api_init` is
			// a WP core hook — not a plugin hook needing a prefix.
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP core hook, not a custom plugin hook.
			do_action( 'rest_api_init' );
			$assertion_ctx['tool_call_log'] = $log['tool_call_log'] ?? array();
			$log['assertions']              = AssertionEngine::run( $question['assertions'], $assertion_ctx );

			$log['completed_at'] = gmdate( 'c' );
			return $log;
		} finally {
			// Always deactivate and remove the sandbox plugin if we created one,
			// even on agent error or thrown exception, so deterministic slugs
			// (e.g. event-manager) don't leak into later questions.
			if ( $needs_plugin && '' !== $plugin_slug && ! $no_cleanup ) {
				$this->cleanup_plugin( $plugin_slug );
			}
		}
	}

	/**
	 * Run the AgentLoop synchronously and collect the full tool call log.
	 *
	 * @param string $prompt      User message.
	 * @param string $provider_id Provider ID.
	 * @param string $model_id    Model ID.
	 * @param int    $max_turns   Maximum agent iterations.
	 * @return array<string, mixed>
	 */
	private function run_agent_loop( string $prompt, string $provider_id, string $model_id, int $max_turns ): array {
		$tool_call_log = array();

		// Track how many log entries we've seen so the progress callback
		// can print only the newly-added entry each time it fires.
		$last_log_count = 0;

		$options = array(
			'provider_id'         => $provider_id,
			'model_id'            => $model_id,
			'max_iterations'      => $max_turns,
			'yolo_mode'           => true,  // No confirmation prompts in benchmark.
			'agent_system_prompt' => self::SYSTEM_PROMPT,
			'progress_callback'   => function ( array $full_log ) use ( &$tool_call_log, &$last_log_count ): void {
				// The callback receives the full log on every fire — diff to get new entries.
				$new_entries = array_slice( $full_log, $last_log_count );
				$last_log_count = count( $full_log );

				foreach ( $new_entries as $entry ) {
					$type = $entry['type'] ?? '';
					if ( 'call' !== $type && '' !== $type ) {
						// Skip 'response' rows — we only log calls so the
						// matcher can introspect what the agent invoked.
						continue;
					}
					$name = $entry['name'] ?? ( $entry['tool'] ?? '?' );
					$tool_call_log[] = array(
						'tool'      => $name,
						'input'     => $entry['args'] ?? $entry['input'] ?? $entry['arguments'] ?? array(),
						'output'    => $entry['output'] ?? $entry['result'] ?? '',
						'timestamp' => gmdate( 'c' ),
					);
					WP_CLI::log( '│  → ' . $name );
				}
			},
		);

		// Load all server-side abilities (same as a normal agent run).
		$abilities = ToolDiscovery::tier_1_for_run();

		$loop   = new AgentLoop( $prompt, $abilities, array(), $options );
		$result = $loop->run();

		if ( is_wp_error( $result ) ) {
			return array( 'error' => $result->get_error_message() );
		}

		return array(
			'reply'         => (string) ( $result['reply'] ?? '' ),
			'turns_used'    => (int) ( $result['iterations_used'] ?? 0 ),
			'token_usage'   => $result['token_usage'] ?? array(),
			'tool_call_log' => $tool_call_log,
			'exit_reason'   => $result['exit_reason'] ?? '',
		);
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Determine whether a question requires a sandbox plugin to be created.
	 *
	 * @param array<string, mixed> $question Question definition.
	 * @return bool
	 */
	private function question_needs_plugin( array $question ): bool {
		$plugin_assertion_types = array(
			'plugin_activates',
			'plugin_no_php_errors',
			'file_exists_in_plugin',
			'file_contains',
		);

		foreach ( (array) $question['assertions'] as $assertion ) {
			if ( in_array( $assertion['type'] ?? '', $plugin_assertion_types, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extract the expected plugin slug from the prompt text.
	 *
	 * Looks for patterns like: called "event-manager" or called 'event-manager'
	 *
	 * @param string $prompt Prompt text.
	 * @return string Plugin slug, or a generated fallback.
	 */
	private function extract_plugin_slug( string $prompt ): string {
		if ( preg_match( '/called\s+["\']([a-z0-9-]+)["\']/', $prompt, $matches ) ) {
			return $matches[1];
		}

		// Fallback: derive from first words of prompt.
		return 'bench-' . substr( md5( $prompt ), 0, 8 );
	}

	/**
	 * Deactivate and delete the sandbox plugin created during a test.
	 *
	 * @param string $plugin_slug Plugin slug.
	 */
	private function cleanup_plugin( string $plugin_slug ): void {
		$plugin_file = "{$plugin_slug}/{$plugin_slug}.php";
		$plugin_dir  = WP_PLUGIN_DIR . '/' . $plugin_slug;

		// Deactivate silently.
		if ( is_plugin_active( $plugin_file ) ) {
			deactivate_plugins( $plugin_file, true );
		}

		// Remove the directory.
		if ( is_dir( $plugin_dir ) ) {
			$this->rmdir_recursive( $plugin_dir );
		}
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory path.
	 */
	private function rmdir_recursive( string $dir ): void {
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink -- CLI-only sandbox cleanup.
			$item->isDir() ? rmdir( $item->getRealPath() ) : unlink( $item->getRealPath() );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- CLI-only sandbox cleanup.
		rmdir( $dir );
	}
}
