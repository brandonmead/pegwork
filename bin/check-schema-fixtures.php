<?php
/**
 * Checks that schema/registry.schema.json agrees with the PHP validator on every fixture.
 *
 * Valid packages (tests/fixtures/valid/*, examples/*): every file must pass the JSON Schema.
 * Invalid packages (tests/fixtures/invalid/*): each has expected.json. With "schema": true, at least
 * one file must fail the JSON Schema; with "schema": false (a rule that spans objects, which JSON
 * Schema cannot express), every file must pass it. tests/Unit/Registry/FixturesTest.php checks the
 * PHP validator against the same expected.json files.
 *
 * Usage: php bin/check-schema-fixtures.php. Prints failures only; exit code 1 on any.
 *
 * @package Pegwork
 */

'cli' === PHP_SAPI || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI script that runs outside WordPress.

require dirname( __DIR__ ) . '/vendor/autoload.php';

exit(
	( static function ( string $root ): int {
		$schema_id = 'https://github.com/brandonmead/pegwork/schema/registry.schema.json';
		$validator = new Opis\JsonSchema\Validator();
		$validator->resolver()->registerFile( $schema_id, $root . '/schema/registry.schema.json' );

		$defs = array(
			'content-types' => 'content_type',
			'taxonomies'    => 'taxonomy',
			'relationships' => 'relationship',
			'forms'         => 'form',
			'automations'   => 'automation',
			'options-pages' => 'options_page',
		);

		// Returns the files of a package that fail the JSON Schema, with the reason.
		$failures = static function ( string $dir ) use ( $validator, $schema_id, $defs ): array {
			$files = array( 'manifest' => array( $dir . '/manifest.json' ) );
			foreach ( $defs as $subdir => $def ) {
				$files[ $def ] = glob( $dir . '/' . $subdir . '/*.json' );
			}
			$failed = array();
			foreach ( $files as $def => $paths ) {
				foreach ( $paths as $path ) {
					if ( ! is_file( $path ) ) {
						continue;
					}
					$data = json_decode( (string) file_get_contents( $path ) );
					if ( JSON_ERROR_NONE !== json_last_error() ) {
						$failed[ $path ] = 'invalid JSON';
						continue;
					}
					$result = $validator->validate( $data, $schema_id . '#/$defs/' . $def );
					if ( ! $result->isValid() ) {
						$error           = $result->error();
						$failed[ $path ] = $error->keyword() . ' at /' . implode( '/', $error->data()->fullPath() );
					}
				}
			}
			return $failed;
		};

		$problems = array();
		$checked  = 0;

		foreach ( array_merge( glob( $root . '/tests/fixtures/valid/*', GLOB_ONLYDIR ), glob( $root . '/examples/*', GLOB_ONLYDIR ) ) as $dir ) {
			++$checked;
			foreach ( $failures( $dir ) as $path => $reason ) {
				$problems[] = "valid fixture fails JSON Schema: $path ($reason)";
			}
		}

		foreach ( glob( $root . '/tests/fixtures/invalid/*', GLOB_ONLYDIR ) as $dir ) {
			++$checked;
			$expected = json_decode( (string) file_get_contents( $dir . '/expected.json' ), true );
			if ( ! is_array( $expected ) || ! is_bool( $expected['schema'] ?? null ) ) {
				$problems[] = "missing or malformed expected.json: $dir";
				continue;
			}
			$failed = $failures( $dir );
			if ( $expected['schema'] && ! $failed ) {
				$problems[] = "invalid fixture passes JSON Schema: $dir";
			}
			if ( ! $expected['schema'] && $failed ) {
				$problems[] = "PHP-only fixture fails JSON Schema, set \"schema\": true: $dir (" . implode( '; ', $failed ) . ')';
			}
		}

		foreach ( $problems as $problem ) {
			fwrite( STDERR, $problem . PHP_EOL );
		}
		echo $checked . ' fixture packages checked, ' . count( $problems ) . ' problem(s).' . PHP_EOL;

		return $problems ? 1 : 0;
	} )( dirname( __DIR__ ) )
);
