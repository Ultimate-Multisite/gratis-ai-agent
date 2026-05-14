<?php

declare(strict_types=1);
/**
 * Abstract Ability base class for SdAiAgent abilities.
 *
 * Extends WP_Ability (WordPress 7.0+ core) with typed abstract methods
 * for defining abilities in a structured, OOP style.
 *
 * This mirrors the WordPress\AI\Abstracts\Abstract_Ability pattern from the
 * WordPress/ai plugin (https://github.com/WordPress/ai), enabling upstream
 * compatibility: when WordPress core ships Abstract_Ability, our abilities
 * can be migrated to extend it directly with minimal changes.
 *
 * Usage:
 *
 *     class MyAbility extends AbstractAbility {
 *         protected function category(): string { return 'sd-ai-agent'; }
 *         protected function input_schema(): array { return [...]; }
 *         protected function output_schema(): array { return [...]; }
 *         protected function execute_callback( $input ) { ... }
 *         protected function permission_callback( $input ) { ... }
 *         protected function meta(): array { return [...]; }
 *     }
 *
 *     // Register via wp_register_ability() with ability_class:
 *     wp_register_ability( 'sd-ai-agent/my-ability', [
 *         'label'         => __( 'My Ability', 'superdav-ai-agent' ),
 *         'description'   => __( 'Does something.', 'superdav-ai-agent' ),
 *         'ability_class' => MyAbility::class,
 *     ] );
 *
 * @package SdAiAgent
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

namespace SdAiAgent\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class for SdAiAgent abilities.
 *
 * Extends WP_Ability (WordPress 7.0+ core). Subclasses must implement the
 * abstract methods: label(), description(), category(), input_schema(),
 * output_schema(), execute_callback(), permission_callback(), and meta().
 *
 * @since 1.0.0
 */
abstract class AbstractAbility extends \WP_Ability {

	/**
	 * Constructor.
	 *
	 * Builds the WP_Ability args array from the abstract method implementations
	 * and delegates to the parent constructor.
	 *
	 * When registered via wp_register_ability() with 'ability_class', the registry
	 * passes the registration 'label' and 'description' as $properties, which take
	 * precedence over the abstract method return values. When instantiated directly
	 * (e.g. in tests), the abstract methods provide the values.
	 *
	 * @since 1.0.0
	 *
	 * @param string              $name       The namespaced ability name (e.g. 'sd-ai-agent/memory-save').
	 * @param array<string,mixed> $properties Optional overrides. Supports 'label' and 'description'.
	 */
	public function __construct( string $name, array $properties = array() ) {
		$input_schema = \SdAiAgent\Infrastructure\Schema\SchemaNormalizer::normalize( $this->input_schema() );

		parent::__construct(
			$name,
			array(
				'label'               => ! empty( $properties['label'] ) ? $properties['label'] : $this->label(),
				'description'         => ! empty( $properties['description'] ) ? $properties['description'] : $this->description(),
				'category'            => $this->category(),
				'input_schema'        => $input_schema,
				'output_schema'       => $this->output_schema(),
				'execute_callback'    => array( $this, 'execute_callback' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'meta'                => $this->meta(),
			)
		);
	}

	/**
	 * Prepares and validates the ability properties.
	 *
	 * Overrides WP_Ability::prepare_properties() to inject label and description
	 * from the abstract methods when they are not provided via $args.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $args The ability args array.
	 * @return array<string,mixed> The validated and prepared properties.
	 */
	protected function prepare_properties( array $args ): array {
		if ( empty( $args['label'] ) ) {
			$args['label'] = $this->label();
		}
		if ( empty( $args['description'] ) ) {
			$args['description'] = $this->description();
		}
		return parent::prepare_properties( $args );
	}

	/**
	 * Returns the human-readable label for this ability.
	 *
	 * @since 1.0.0
	 *
	 * @return string Non-empty human-readable label.
	 */
	abstract protected function label(): string;

	/**
	 * Returns the description for this ability.
	 *
	 * @since 1.0.0
	 *
	 * @return string Non-empty description string.
	 */
	abstract protected function description(): string;

	/**
	 * Returns the category slug for this ability.
	 *
	 * @since 1.0.0
	 *
	 * @return string The category slug (must be registered via wp_register_ability_category()).
	 */
	protected function category(): string {
		return 'sd-ai-agent';
	}

	/**
	 * Returns the JSON Schema definition for the ability's input.
	 *
	 * Return an empty array if the ability accepts no input.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed> JSON Schema array.
	 */
	abstract protected function input_schema(): array;

	/**
	 * Returns the JSON Schema definition for the ability's output.
	 *
	 * Return an empty array if the output is unstructured.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed> JSON Schema array.
	 */
	abstract protected function output_schema(): array;

	/**
	 * Executes the ability with the given input.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $input The validated input data, or null if no input schema is defined.
	 * @return mixed|\WP_Error The result of the ability execution, or WP_Error on failure.
	 */
	abstract protected function execute_callback( $input );

	/**
	 * Checks whether the current user has permission to execute this ability.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $input The input data (same as passed to execute_callback).
	 * @return bool|\WP_Error True if permitted, false or WP_Error otherwise.
	 */
	abstract protected function permission_callback( $input );

	/**
	 * Returns the meta array for this ability.
	 *
	 * Should include 'annotations' (readonly, destructive, idempotent) and
	 * optionally 'show_in_rest'.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed> Meta array.
	 */
	protected function meta(): array {
		return array(
			'mcp'          => [ 'public' => true ],
			'annotations'  => array(
				'readonly'    => null,
				'destructive' => null,
				'idempotent'  => null,
			),
			'show_in_rest' => false,
		);
	}

	/**
	 * Executes the ability callback after normalizing stdClass input to arrays.
	 *
	 * AI providers may decode JSON function-call arguments as stdClass objects,
	 * but all execute_callback() implementations expect associative arrays.
	 * This override intercepts the input before it reaches the callback and
	 * recursively casts any stdClass values to arrays.
	 *
	 * Unlike parent::do_execute() which delegates to invoke_callback(), this
	 * method calls execute_callback() directly. WP core's invoke_callback()
	 * catches all Throwable exceptions and wraps them in a bare WP_Error that
	 * strips file/line/trace — making it impossible for the user (or AI) to
	 * know WHERE the error occurred. By calling the callback directly, we can
	 * preserve the full exception context in the WP_Error's error_data.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $input Optional. The input data for the ability.
	 * @return mixed|\WP_Error The result of the ability execution.
	 */
	protected function do_execute( $input = null ) {
		if ( $input instanceof \stdClass ) {
			$input = self::stdclass_to_array( $input );
		} elseif ( is_array( $input ) ) {
			$input = self::stdclass_to_array( $input );
		}

		// Call execute_callback() directly instead of parent::do_execute()
		// to preserve exception context that WP core's invoke_callback() strips.
		// Always pass $input — concrete implementations either use it or
		// declare $input = null; WP_Ability::execute() normalises to null
		// when no input_schema is defined.
		try {
			return $this->execute_callback( $input );
		} catch ( \Throwable $e ) {
			// Log the full trace — this is the ONLY place it's available
			// before WP core would strip it in invoke_callback().
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[Superdav AI Agent] Ability "%s" exception: %s in %s:%d',
					$this->get_name(),
					$e->getMessage(),
					$e->getFile(),
					$e->getLine()
				) . "\n" . $e->getTraceAsString()
			);

			$trace_frames = array();
			foreach ( array_slice( $e->getTrace(), 0, 10 ) as $frame ) {
				$trace_frames[] = ( $frame['file'] ?? '?' )
					. ':' . ( $frame['line'] ?? '?' )
					. ' ' . ( $frame['class'] ?? '' )
					. ( $frame['type'] ?? '' )
					. ( $frame['function'] ?? '' ) . '()';
			}

			return new \WP_Error(
				'ability_callback_exception',
				sprintf(
					/* translators: 1: Ability name, 2: Exception message. */
					__( 'Ability "%1$s" threw an error: %2$s', 'superdav-ai-agent' ),
					$this->get_name(),
					$e->getMessage()
				),
				array(
					'exception_file'  => $e->getFile(),
					'exception_line'  => $e->getLine(),
					'exception_trace' => $trace_frames,
				)
			);
		}
	}

	/**
	 * Recursively convert stdClass objects to associative arrays.
	 *
	 * @param mixed $data The data to normalize.
	 * @return mixed The normalized data.
	 */
	private static function stdclass_to_array( $data ) {
		if ( $data instanceof \stdClass ) {
			$data = (array) $data;
		}

		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				if ( $value instanceof \stdClass || is_array( $value ) ) {
					$data[ $key ] = self::stdclass_to_array( $value );
				}
			}
		}

		return $data;
	}

	/**
	 * Returns the configured model ID from plugin settings, or empty string if none.
	 *
	 * Used by AI-powered abilities to pass a model preference to wp_ai_client_prompt().
	 *
	 * @since 1.0.0
	 *
	 * @return string Model ID or empty string.
	 */
	protected function get_configured_model(): string {
		if ( class_exists( \SdAiAgent\Core\Settings::class ) ) {
			$model = \SdAiAgent\Core\Settings::instance()->get( 'default_model' );
			return is_string( $model ) ? $model : '';
		}
		return '';
	}

	/**
	 * Executes the ability callback directly, bypassing permission checks.
	 *
	 * Used by static proxy methods for backwards-compatible test access.
	 * Unlike execute(), this method does not check permissions, validate input
	 * against the schema, or fire WordPress hooks. It calls execute_callback()
	 * directly, matching the behaviour of the old static method approach.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $input The input data.
	 * @return mixed|\WP_Error The result of the ability execution, or WP_Error on failure.
	 */
	public function run( $input = null ) {
		return $this->execute_callback( $input );
	}
}
