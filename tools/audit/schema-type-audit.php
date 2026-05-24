<?php
/**
 * Audit ability schema properties for missing 'type' entries.
 *
 * Tokenizes PHP files under includes/Abilities/ and includes/REST/,
 * locates 'properties' => [] or array() blocks, and reports any
 * child property that is missing a 'type' key.
 *
 * Handles both [] and array() syntax.
 *
 * Usage: php tools/audit/schema-type-audit.php [plugin-root]
 *
 * @package SdAiAgent
 * @license GPL-2.0-or-later
 * @see https://github.com/Ultimate-Multisite/superdav-ai-agent/issues/1790
 */

// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited

declare(strict_types=1);

$base = $argv[1] ?? '.';
chdir( $base );

/**
 * Find schema properties missing 'type' in a PHP file.
 *
 * @param string $file Path to the PHP file to scan.
 * @return array<int, array{file: string, propLine: int, childName: string, childLine: int}>
 */
function find_missing_types( string $file ): array {
	$tokens   = token_get_all( file_get_contents( $file ) );
	$findings = [];
	$count    = count( $tokens );

	for ( $i = 0; $i < $count; $i++ ) {
		if ( ! is_array( $tokens[ $i ] ) ) {
			continue;
		}
		if ( $tokens[ $i ][0] !== T_CONSTANT_ENCAPSED_STRING ) {
			continue;
		}

		$val = trim( $tokens[ $i ][1], "'\"" );
		if ( 'properties' !== $val ) {
			continue;
		}

		$prop_line = $tokens[ $i ][2];

		// Find => after 'properties'.
		$j = $i + 1;
		while ( $j < $count && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
			++$j;
		}
		if ( $j >= $count || ! is_array( $tokens[ $j ] ) || $tokens[ $j ][0] !== T_DOUBLE_ARROW ) {
			continue;
		}
		++$j;

		// Skip whitespace.
		while ( $j < $count && is_array( $tokens[ $j ] ) && $tokens[ $j ][0] === T_WHITESPACE ) {
			++$j;
		}
		if ( $j >= $count ) {
			continue;
		}

		// Check for (object) cast before [].
		$has_object_cast = false;
		if ( is_array( $tokens[ $j ] ) && $tokens[ $j ][0] === T_OBJECT_CAST ) {
			$has_object_cast = true;
			++$j;
			while ( $j < $count && is_array( $tokens[ $j ] ) && $tokens[ $j ][0] === T_WHITESPACE ) {
				++$j;
			}
		}

		// Determine opener type: [ or array(.
		$is_short_array = ! is_array( $tokens[ $j ] ) && '[' === $tokens[ $j ];
		$is_long_array  = is_array( $tokens[ $j ] ) && $tokens[ $j ][0] === T_ARRAY;

		if ( $is_long_array ) {
			// Skip to (.
			++$j;
			while ( $j < $count && is_array( $tokens[ $j ] ) && $tokens[ $j ][0] === T_WHITESPACE ) {
				++$j;
			}
			if ( $j >= $count || '(' !== $tokens[ $j ] ) {
				continue;
			}
		} elseif ( ! $is_short_array ) {
			continue;
		}

		// $j now points to the opening [ or (.
		$depth              = 0;
		$current_child_name = null;
		$current_child_line = 0;
		$child_has_type     = false;
		$children           = [];

		$k = $j;
		while ( $k < $count ) {
			$tok     = $tokens[ $k ];
			$tok_str = is_array( $tok ) ? $tok[1] : $tok;

			// Track depth.
			if ( '[' === $tok_str || '(' === $tok_str ) {
				++$depth;
			}
			if ( ']' === $tok_str || ')' === $tok_str ) {
				--$depth;
				if ( 0 === $depth ) {
					if ( null !== $current_child_name ) {
						$children[ $current_child_name ] = [
							'line'    => $current_child_line,
							'hasType' => $child_has_type,
						];
					}
					break;
				}
			}

			// At depth 1: property key names.
			if ( 1 === $depth && is_array( $tok ) && $tok[0] === T_CONSTANT_ENCAPSED_STRING ) {
				$child_key = trim( $tok[1], "'\"" );
				$peek      = $k + 1;
				while ( $peek < $count && is_array( $tokens[ $peek ] ) && $tokens[ $peek ][0] === T_WHITESPACE ) {
					++$peek;
				}
				if ( $peek < $count && is_array( $tokens[ $peek ] ) && $tokens[ $peek ][0] === T_DOUBLE_ARROW ) {
					if ( null !== $current_child_name ) {
						$children[ $current_child_name ] = [
							'line'    => $current_child_line,
							'hasType' => $child_has_type,
						];
					}
					$current_child_name = $child_key;
					$current_child_line = $tok[2];
					$child_has_type     = false;
				}
			}

			// At depth 2: check for 'type' key inside a child property.
			if ( 2 === $depth && null !== $current_child_name && is_array( $tok ) && $tok[0] === T_CONSTANT_ENCAPSED_STRING ) {
				$key_val = trim( $tok[1], "'\"" );
				if ( 'type' === $key_val ) {
					$peek = $k + 1;
					while ( $peek < $count && is_array( $tokens[ $peek ] ) && $tokens[ $peek ][0] === T_WHITESPACE ) {
						++$peek;
					}
					if ( $peek < $count && is_array( $tokens[ $peek ] ) && $tokens[ $peek ][0] === T_DOUBLE_ARROW ) {
						$child_has_type = true;
					}
				}
			}

			++$k;
		}

		// Skip empty or (object) [] blocks.
		if ( $has_object_cast && empty( $children ) ) {
			continue;
		}
		if ( empty( $children ) ) {
			continue;
		}

		foreach ( $children as $name => $info ) {
			if ( ! $info['hasType'] ) {
				$findings[] = [
					'file'      => $file,
					'propLine'  => $prop_line,
					'childName' => $name,
					'childLine' => $info['line'],
				];
			}
		}
	}

	return $findings;
}

$dirs         = [ 'includes/Abilities', 'includes/REST' ];
$all_findings = [];

foreach ( $dirs as $dir ) {
	if ( ! is_dir( $dir ) ) {
		continue;
	}
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir ) );
	foreach ( $iterator as $file_info ) {
		if ( 'php' !== $file_info->getExtension() ) {
			continue;
		}
		$path     = $file_info->getPathname();
		$findings = find_missing_types( $path );
		foreach ( $findings as $f ) {
			$all_findings[] = $f;
		}
	}
}

echo "=== Schema Audit: Properties Missing 'type' ===\n\n";
echo 'Found ' . count( $all_findings ) . " properties missing 'type':\n\n";

if ( empty( $all_findings ) ) {
	exit( 0 );
}

// Group by file.
$by_file = [];
foreach ( $all_findings as $f ) {
	$rel               = str_replace( './', '', $f['file'] );
	$by_file[ $rel ][] = $f;
}

ksort( $by_file );
foreach ( $by_file as $file => $findings ) {
	echo "--- {$file} ---\n";
	foreach ( $findings as $f ) {
		printf(
			"  Line %-4d: '%s' (in 'properties' block at line %d)\n",
			$f['childLine'],
			$f['childName'],
			$f['propLine']
		);
	}
	echo "\n";
}

exit( 1 );
