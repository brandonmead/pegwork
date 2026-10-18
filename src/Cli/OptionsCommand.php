<?php
/**
 * WP-CLI: wp pegwork options.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Cli;

use Pegwork\Options\Pages;
use Pegwork\Options\Values;
use Pegwork\Plugin;
use Pegwork\Registry\Status;
use WP_CLI;

use function WP_CLI\Utils\format_items;

/**
 * Lists options pages and reads and writes their values, with the same rules as the settings screen.
 */
final class OptionsCommand {

	/**
	 * Lists the options pages.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp pegwork options list
	 *
	 * @subcommand list
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	public function list_( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WP-CLI signature.
		format_items(
			$assoc_args['format'] ?? 'table',
			Status::current()->options_pages(),
			array( 'key', 'title', 'menu_title', 'capability', 'fields', 'private', 'source' )
		);
	}

	/**
	 * Prints the values of an options page, or one value. Private values are included.
	 *
	 * ## OPTIONS
	 *
	 * <page>
	 * : Options page key.
	 *
	 * [<field>]
	 * : Field key. Prints only that value (JSON with --format=json).
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp pegwork options get sifg_settings
	 *     wp pegwork options get sifg_settings contact_email
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	public function get( array $args, array $assoc_args ): void {
		$page   = $this->page( $args[0] );
		$format = $assoc_args['format'] ?? 'table';
		$values = Values::get( Plugin::registry(), $args[0] );

		if ( isset( $args[1] ) ) {
			$field = $this->field( $page, $args[1] );
			$value = $values[ $field['key'] ];
			if ( 'json' === $format ) {
				WP_CLI::line( (string) wp_json_encode( $value ) );
			} else {
				WP_CLI::line( $this->text( $value ) );
			}
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( (object) $values ) );
			return;
		}

		$items = array();
		foreach ( $page['fields'] as $field ) {
			$items[] = array(
				'field'      => $field['key'],
				'type'       => $field['type'],
				'visibility' => $field['visibility'],
				'value'      => $this->text( $values[ $field['key'] ] ),
			);
		}
		format_items( $format, $items, array( 'field', 'type', 'visibility', 'value' ) );
	}

	/**
	 * Sets one value, with the same checks as the settings screen. Exits 1 when the value is rejected.
	 *
	 * ## OPTIONS
	 *
	 * <page>
	 * : Options page key.
	 *
	 * <field>
	 * : Field key.
	 *
	 * <value>
	 * : New value. Booleans take 1/0 or true/false; post fields a post ID; '' clears.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pegwork options set sifg_settings contact_email hello@example.org
	 *     wp pegwork options set sifg_settings featured_project 24
	 *
	 * @param string[] $args Positional arguments.
	 */
	public function set( array $args ): void {
		[ $key, $name, $value ] = $args;

		$page   = $this->page( $key );
		$field  = $this->field( $page, $name );
		$result = Values::sanitize( $page, array( $field['key'] => $value ), Values::stored( $key ) );
		if ( isset( $result['errors'][ $field['key'] ] ) ) {
			WP_CLI::error( $result['errors'][ $field['key'] ] );
		}

		update_option( $key, $result['values'], true );
		WP_CLI::success( sprintf( 'Set %s.%s.', $key, $field['key'] ) );
	}

	/**
	 * A registered options page, exiting with an error when unknown.
	 *
	 * @param string $key Options page key.
	 * @return array<string, mixed>
	 */
	private function page( string $key ): array {
		$page = Pages::page( $key );
		if ( null === $page ) {
			WP_CLI::error( sprintf( 'Unknown options page "%s".', $key ) );
		}

		return $page;
	}

	/**
	 * A field of a page, exiting with an error when unknown.
	 *
	 * @param array<string, mixed> $page Options page.
	 * @param string               $key  Field key.
	 * @return array<string, mixed>
	 */
	private function field( array $page, string $key ): array {
		foreach ( $page['fields'] as $field ) {
			if ( $key === $field['key'] ) {
				return $field;
			}
		}
		WP_CLI::error( sprintf( 'Options page "%s" has no field "%s".', $page['key'], $key ) );
	}

	/**
	 * A typed value as one line of text.
	 *
	 * @param mixed $value Typed value.
	 */
	private function text( mixed $value ): string {
		return match ( true ) {
			is_bool( $value ) => $value ? 'true' : 'false',
			null === $value   => '',
			default           => (string) $value,
		};
	}
}
