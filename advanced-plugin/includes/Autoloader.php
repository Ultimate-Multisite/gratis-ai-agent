<?php

declare(strict_types=1);

namespace SdAiAgentAdvanced;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight source-checkout autoloader for the advanced companion plugin.
 *
 * The packaged advanced plugin may ship a Composer autoloader, but repository
 * checkouts should work without a second composer install. This autoloader maps
 * the advanced namespace and the moved advanced SdAiAgent classes to the
 * advanced-plugin/includes tree.
 */
final class Autoloader {

	/**
	 * Register the advanced plugin autoloader.
	 *
	 * @param string $plugin_dir Advanced plugin directory.
	 */
	public static function register( string $plugin_dir ): void {
		$classmap = array(
			'SdAiAgent\\Abilities\\DatabaseQueryAbility'  => $plugin_dir . '/includes/Abilities/DatabaseAbilities.php',
			'SdAiAgent\\Abilities\\ListModifiedPluginsAbility' => $plugin_dir . '/includes/Abilities/PluginDownloadAbilities.php',
			'SdAiAgent\\Abilities\\GetPluginDownloadUrlAbility' => $plugin_dir . '/includes/Abilities/PluginDownloadAbilities.php',
			'SdAiAgent\\Abilities\\FileWriteAbility'      => $plugin_dir . '/includes/Abilities/FileMutationAbilities.php',
			'SdAiAgent\\Abilities\\FileEditAbility'       => $plugin_dir . '/includes/Abilities/FileMutationAbilities.php',
			'SdAiAgent\\Abilities\\FileDeleteAbility'     => $plugin_dir . '/includes/Abilities/FileMutationAbilities.php',
			'SdAiAgent\\Abilities\\UpdatePluginAbility'   => $plugin_dir . '/includes/Abilities/WordPressAdvancedAbilities.php',
			'SdAiAgent\\Abilities\\InstallPluginFromUrlAbility' => $plugin_dir . '/includes/Abilities/WordPressAdvancedAbilities.php',
			'SdAiAgent\\Abilities\\ActivatePluginAbility' => $plugin_dir . '/includes/Abilities/WordPressAdvancedAbilities.php',
			'SdAiAgent\\Abilities\\DeactivatePluginAbility' => $plugin_dir . '/includes/Abilities/WordPressAdvancedAbilities.php',
			'SdAiAgent\\Abilities\\DeletePluginAbility'   => $plugin_dir . '/includes/Abilities/WordPressAdvancedAbilities.php',
			'SdAiAgent\\Abilities\\SwitchPluginAbility'   => $plugin_dir . '/includes/Abilities/WordPressAdvancedAbilities.php',
		);

		$prefixes = array(
			'SdAiAgentAdvanced\\' => $plugin_dir . '/includes/',
			'SdAiAgent\\'         => $plugin_dir . '/includes/',
		);

		spl_autoload_register(
			static function ( string $class ) use ( $classmap, $prefixes ): void {
				if ( isset( $classmap[ $class ] ) ) {
					$file = $classmap[ $class ];
					if ( is_readable( $file ) ) {
						require_once $file;
					}

					return;
				}

				foreach ( $prefixes as $prefix => $base_dir ) {
					$length = strlen( $prefix );
					if ( 0 !== strncmp( $prefix, $class, $length ) ) {
						continue;
					}

					$relative = substr( $class, $length );
					$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

					if ( is_readable( $file ) ) {
						require_once $file;
					}

					return;
				}
			}
		);
	}
}
