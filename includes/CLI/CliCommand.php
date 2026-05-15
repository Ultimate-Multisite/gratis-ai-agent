<?php

declare(strict_types=1);
/**
 * WP-CLI Command for the AI Agent.
 *
 * Provides a `wp sd-ai-agent` command to send prompts to the AI agent
 * directly from the terminal for development testing and debugging.
 *
 * @package SdAiAgent
 * @since   1.1.0
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\CLI;

use SdAiAgent\Core\AgentLoop;
use SdAiAgent\Core\ConversationSerializer;
use SdAiAgent\Models\Agent;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Send prompts to the AI agent from the command line.
 *
 * ## EXAMPLES
 *
 *     # Simple prompt
 *     wp sd-ai-agent prompt "hello"
 *
 *     # With a specific agent
 *     wp sd-ai-agent prompt "write a blog post intro about WordPress" --agent=content-creator
 *
 *     # With a specific model
 *     wp sd-ai-agent prompt "how many plugins are active?" --model=qwen3.5
 *
 *     # With verbose output showing tool calls and token usage
 *     wp sd-ai-agent prompt "list all plugins" --verbose
 *
 *     # Skip all tool usage
 *     wp sd-ai-agent prompt "what day is it?" --skip-tools
 *
 * @since 1.1.0
 */
class CliCommand extends \WP_CLI_Command {

	/**
	 * Send a prompt to the AI agent.
	 *
	 * Runs the Agent_Loop synchronously and prints the final reply.
	 * Tool calls requiring confirmation are auto-approved in CLI mode.
	 *
	 * ## OPTIONS
	 *
	 * <prompt>
	 * : The prompt to send to the AI agent.
	 *
	 * [--agent=<slug>]
	 * : Agent slug to use (e.g. general, content-creator, seo-specialist).
	 *   Loads the agent's system prompt, tier 1 tools, and model overrides.
	 *   CLI --model / --provider / --max-iterations flags take precedence.
	 *
	 * [--model=<model>]
	 * : Model ID to use (e.g. qwen3.5, claude-sonnet-4). Overrides agent default.
	 *
	 * [--provider=<provider>]
	 * : Provider ID to use. Overrides agent default.
	 *
	 * [--max-iterations=<n>]
	 * : Maximum tool-calling loop iterations. Overrides agent default.
	 *
	 * [--skip-tools]
	 * : Disable all tool/ability usage.
	 *
	 * [--verbose]
	 * : Show agent info, tool calls, results, and token usage details.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sd-ai-agent prompt "how many plugins are active?"
	 *     wp sd-ai-agent prompt "write a blog post intro" --agent=content-creator
	 *     wp sd-ai-agent prompt "audit my SEO" --agent=seo-specialist --verbose
	 *     wp sd-ai-agent prompt "list products" --agent=e-commerce
	 *     wp sd-ai-agent prompt "what day is it?" --skip-tools
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$prompt     = $args[0];
		$agent_slug = \WP_CLI\Utils\get_flag_value( $assoc_args, 'agent', '' );
		$no_tools   = \WP_CLI\Utils\get_flag_value( $assoc_args, 'skip-tools', false );
		$verbose    = \WP_CLI\Utils\get_flag_value( $assoc_args, 'verbose', false );

		// Ensure a user is set so ability permission checks pass.
		// WP-CLI doesn't set a current user unless --user is passed.
		if ( ! get_current_user_id() ) {
			$admins = get_super_admins();
			if ( ! empty( $admins ) ) {
				$admin = get_user_by( 'login', $admins[0] );
				if ( $admin ) {
					wp_set_current_user( $admin->ID );
				}
			}
		}

		// ── Agent resolution ─────────────────────────────────────────────────
		// Start with agent loop options (system prompt, model, tier-1 tools,
		// temperature, max_iterations) if --agent is provided.
		// CLI flags layered on top override any agent defaults.
		$options = [];

		if ( $agent_slug ) {
			$agent = Agent::get_by_slug( $agent_slug );

			if ( ! $agent ) {
				$available = array_map(
					static fn( $a ) => $a->slug,
					Agent::get_all()
				);
				WP_CLI::error(
					"Agent '{$agent_slug}' not found. Available slugs: " . implode( ', ', $available )
				);
				return;
			}

			if ( ! $agent->enabled ) {
				WP_CLI::error( "Agent '{$agent_slug}' is disabled." );
				return;
			}

			// Merge agent-level options first; explicit CLI flags below override.
			$options = Agent::get_loop_options( $agent->id );

			if ( $verbose ) {
				$tool_count = count( $agent->tier_1_tools ?? [] );
				WP_CLI::log( "Agent:       {$agent->name} ({$agent->slug})" );
				if ( $agent->description ) {
					WP_CLI::log( "Description: {$agent->description}" );
				}
				WP_CLI::log( "Tier 1 tools: {$tool_count}" );
				if ( $agent->system_prompt ) {
					$preview = substr( $agent->system_prompt, 0, 200 );
					if ( strlen( $agent->system_prompt ) > 200 ) {
						$preview .= '...';
					}
					WP_CLI::log( 'System prompt preview:' );
					WP_CLI::log( "  {$preview}" );
				}
				WP_CLI::log( '' );
			}
		}

		// ── CLI flag overrides ────────────────────────────────────────────────
		// Explicit flags always win over agent defaults.
		$model    = \WP_CLI\Utils\get_flag_value( $assoc_args, 'model', '' );
		$provider = \WP_CLI\Utils\get_flag_value( $assoc_args, 'provider', '' );

		// Default max_iterations from agent (or 25 global default) unless CLI overrides.
		$agent_max      = $options['max_iterations'] ?? 25;
		$cli_max        = isset( $assoc_args['max-iterations'] )
			? (int) $assoc_args['max-iterations']
			: null;
		$max_iterations = $cli_max ?? $agent_max;

		// Only write max_iterations when a CLI flag was supplied or the key was
		// already present in options (from agent settings). Without this guard
		// the hard-coded fallback of 25 would overwrite AgentLoop's own default
		// whenever no agent or flag is provided.
		if ( isset( $assoc_args['max-iterations'] ) || array_key_exists( 'max_iterations', $options ) ) {
			$options['max_iterations'] = $max_iterations;
		}

		if ( $model ) {
			$options['model_id'] = $model;
		}
		if ( $provider ) {
			$options['provider_id'] = $provider;
		}

		// In CLI mode, enable YOLO mode so write tools auto-execute without
		// pausing for confirmation.
		$options['yolo_mode'] = true;

		if ( $verbose ) {
			$effective_model = $options['model_id'] ?? '(global default)';
			WP_CLI::log( "Model:          {$effective_model}" );
			WP_CLI::log( "Max iterations: {$max_iterations}" );
			WP_CLI::log( '' );
		}

		// If --skip-tools, pass a bogus ability name so nothing resolves.
		$abilities = $no_tools ? [ '__none__' ] : [];

		$start_time = microtime( true );

		WP_CLI::log( 'Sending prompt to AI agent...' );

		$loop   = new AgentLoop( $prompt, $abilities, [], $options );
		$result = $loop->run();

		// Handle awaiting_confirmation by auto-approving in a loop.
		while ( ! is_wp_error( $result ) && ! empty( $result['awaiting_confirmation'] ) ) {
			$pending = $result['pending_tools'] ?? [];

			if ( $verbose ) {
				WP_CLI::log( '' );
				WP_CLI::log( '--- Tools requiring confirmation (auto-approving) ---' );
				foreach ( $pending as $tool ) {
					$tool_name = $tool['name'] ?? 'unknown';
					$tool_args = isset( $tool['args'] ) ? wp_json_encode( $tool['args'] ) : '{}';
					if ( strlen( $tool_args ) > 200 ) {
						$tool_args = substr( $tool_args, 0, 200 ) . '...';
					}
					WP_CLI::log( "  {$tool_name}({$tool_args})" );
				}
			} else {
				$count = count( $pending );
				WP_CLI::log( "Auto-approving {$count} tool call(s)..." );
			}

			// Resume the loop with the serialized history.
			/** @var list<array<string, mixed>> $result_history */
			$result_history = $result['history'] ?? [];
			$history        = ConversationSerializer::deserialize( array_values( $result_history ) );
			$remaining      = $result['iterations_remaining'] ?? $max_iterations;

			$resume_options                  = $options;
			$resume_options['tool_call_log'] = $result['tool_call_log'] ?? [];
			$resume_options['token_usage']   = $result['token_usage'] ?? [
				'prompt'     => 0,
				'completion' => 0,
			];

			$loop   = new AgentLoop( '', $abilities, $history, $resume_options );
			$result = $loop->resume_after_confirmation( true, $remaining );
		}

		// Extract data — either from a successful result or from the WP_Error data.
		$is_error   = is_wp_error( $result );
		$tool_calls = [];
		$usage      = [];
		$iterations = 0;
		$model_used = '';
		$reply      = '';

		if ( $is_error ) {
			$error_data = $result->get_error_data();
			if ( is_array( $error_data ) ) {
				$tool_calls = $error_data['tool_calls'] ?? [];
				$usage      = $error_data['token_usage'] ?? [];
				$iterations = $error_data['iterations_used'] ?? 0;
				$model_used = $error_data['model_id'] ?? '';
			}
		} else {
			$tool_calls = $result['tool_calls'] ?? [];
			$usage      = $result['token_usage'] ?? [];
			$iterations = $result['iterations_used'] ?? 0;
			$model_used = $result['model_id'] ?? '';
			$reply      = $result['reply'] ?? '';
		}

		// Print tool call log — always shown, detail level depends on --verbose.
		if ( ! empty( $tool_calls ) ) {
			WP_CLI::log( '' );

			$iteration = 0;

			foreach ( $tool_calls as $entry ) {
				if ( 'call' === $entry['type'] ) {
					++$iteration;

					if ( $verbose ) {
						WP_CLI::log( '' );
						WP_CLI::log( "  [{$iteration}] CALL  {$entry['name']}" );

						$args_str = isset( $entry['args'] ) ? wp_json_encode( $entry['args'], JSON_PRETTY_PRINT ) : '{}';
						if ( strlen( $args_str ) > 500 ) {
							$args_str = substr( $args_str, 0, 500 ) . '...';
						}
						foreach ( explode( "\n", $args_str ) as $line ) {
							WP_CLI::log( "        {$line}" );
						}
					} else {
						// Compact: one-liner with inline args.
						$args_str = isset( $entry['args'] ) ? wp_json_encode( $entry['args'] ) : '{}';
						if ( strlen( $args_str ) > 120 ) {
							$args_str = substr( $args_str, 0, 120 ) . '...';
						}
						WP_CLI::log( "  [{$iteration}] {$entry['name']}({$args_str})" );
					}
				} elseif ( 'response' === $entry['type'] ) {
					$resp_str = '';
					if ( isset( $entry['response'] ) ) {
						$resp_str = is_string( $entry['response'] )
							? $entry['response']
							: wp_json_encode( $entry['response'] );
					}

					if ( $verbose ) {
						// Pretty-print with generous limit.
						$resp_pretty = is_string( $entry['response'] ?? null )
							? $entry['response']
							: wp_json_encode( $entry['response'], JSON_PRETTY_PRINT );
						if ( strlen( $resp_pretty ) > 1000 ) {
							$resp_pretty = substr( $resp_pretty, 0, 1000 ) . "\n... [truncated]";
						}
						WP_CLI::log( '        RESULT:' );
						foreach ( explode( "\n", $resp_pretty ) as $line ) {
							WP_CLI::log( "        {$line}" );
						}
					} else {
						// Compact: short one-liner result.
						$has_error = false;
						if ( is_array( $entry['response'] ?? null ) && isset( $entry['response']['error'] ) ) {
							$resp_str  = $entry['response']['error'];
							$has_error = true;
						}
						if ( strlen( $resp_str ) > 120 ) {
							$resp_str = substr( $resp_str, 0, 120 ) . '...';
						}
						if ( $has_error ) {
							WP_CLI::log( "       ERROR: {$resp_str}" );
						} else {
							WP_CLI::log( "       => {$resp_str}" );
						}
					}
				}
			}
		}

		// Print the reply or error.
		WP_CLI::log( '' );

		if ( $is_error ) {
			WP_CLI::warning( $result->get_error_message() );
		} elseif ( empty( $reply ) ) {
			WP_CLI::warning( 'Agent returned an empty reply.' );
		} else {
			WP_CLI::log( $reply );
		}

		// Footer: token usage and timing.
		if ( $verbose ) {
			WP_CLI::log( '' );

			if ( ! empty( $usage ) ) {
				$prompt_tokens     = $usage['prompt'] ?? 0;
				$completion_tokens = $usage['completion'] ?? 0;
				WP_CLI::log( "Tokens — prompt: {$prompt_tokens}, completion: {$completion_tokens}, total: " . ( $prompt_tokens + $completion_tokens ) );
			}

			WP_CLI::log( "Iterations used: {$iterations}/{$max_iterations}" );

			if ( $model_used ) {
				WP_CLI::log( "Model: {$model_used}" );
			}

			$elapsed = round( microtime( true ) - $start_time, 2 );
			WP_CLI::log( "Total time: {$elapsed}s" );
		}
	}
}
