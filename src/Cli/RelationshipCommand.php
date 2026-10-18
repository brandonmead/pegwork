<?php
/**
 * WP-CLI: wp pegwork relationship.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Cli;

use Pegwork\Content\RecordValidator;
use Pegwork\Content\Relationships;
use Pegwork\Plugin;
use WP_CLI;
use WP_Post;

use function WP_CLI\Utils\format_items;

/**
 * Reads and writes relationship links, with the same rules as the editor and REST.
 */
final class RelationshipCommand {

	/**
	 * Lists the records at the other end of a relationship. Works from either end.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Relationship key.
	 *
	 * <post>
	 * : Post ID of a "from" or "to" record.
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
	 *   - ids
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp pegwork relationship get story_projects 31
	 *     wp pegwork relationship get story_projects 24 --format=ids
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	public function get( array $args, array $assoc_args ): void {
		[ $key, $post ] = $this->resolve( $args[0], $args[1] );
		$ids            = Relationships::related_ids( Plugin::registry(), $post, $key );
		$format         = $assoc_args['format'] ?? 'table';

		if ( 'ids' === $format ) {
			WP_CLI::line( implode( ' ', $ids ) );
			return;
		}

		$items = array();
		foreach ( $ids as $id ) {
			$related = get_post( $id );
			$items[] = array(
				'ID'          => $id,
				'post_title'  => $related ? $related->post_title : '',
				'post_type'   => $related ? $related->post_type : '',
				'post_status' => $related ? $related->post_status : '',
			);
		}

		format_items( $format, $items, array( 'ID', 'post_title', 'post_type', 'post_status' ) );
	}

	/**
	 * Replaces the links a "from" record holds. Same rules as a REST save; an empty list clears.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Relationship key.
	 *
	 * <post>
	 * : Post ID of the "from" record.
	 *
	 * [<id>...]
	 * : Post IDs of the "to" records to link. None clears the links.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pegwork relationship set story_projects 31 24 25
	 *     wp pegwork relationship set story_projects 31
	 *
	 * @param string[] $args Positional arguments.
	 */
	public function set( array $args ): void {
		[ $key, $post ] = $this->resolve( (string) array_shift( $args ), (string) array_shift( $args ) );
		$relationship   = Plugin::registry()->relationships()[ $key ];
		if ( $relationship['from'] !== $post->post_type ) {
			WP_CLI::error( sprintf( 'Post %d is not a "%s" record; set links from the "from" end.', $post->ID, $relationship['from'] ) );
		}

		foreach ( $args as $arg ) {
			if ( ! ctype_digit( $arg ) || 0 === (int) $arg ) {
				WP_CLI::error( sprintf( 'Not a post ID: "%s".', $arg ) );
			}
		}

		$complete = in_array( $post->post_status, RecordValidator::COMPLETE_STATUSES, true );
		$message  = RecordValidator::check_relationship( Plugin::registry(), $key, $args, $complete, $post->ID );
		if ( null !== $message ) {
			WP_CLI::error( $message );
		}

		$ids      = Relationships::ids( $args );
		$current  = Relationships::stored_ids( $post->ID, $key );
		$meta_key = Relationships::meta_key( $key );
		foreach ( array_diff( $current, $ids ) as $gone ) {
			delete_post_meta( $post->ID, $meta_key, $gone );
		}
		foreach ( array_diff( $ids, $current ) as $added ) {
			add_post_meta( $post->ID, $meta_key, $added );
		}

		WP_CLI::success( sprintf( 'Post %d now links %d record(s) through "%s".', $post->ID, count( $ids ), $key ) );
	}

	/**
	 * Resolves the relationship key and post arguments, exiting with an error when either is unknown.
	 *
	 * @param string $key  Relationship key.
	 * @param string $post Post ID.
	 * @return array{0: string, 1: WP_Post}
	 */
	private function resolve( string $key, string $post ): array {
		$relationship = Plugin::registry()->relationships()[ $key ] ?? null;
		if ( null === $relationship ) {
			WP_CLI::error( sprintf( 'Unknown relationship "%s".', $key ) );
		}

		$record = ctype_digit( $post ) ? get_post( (int) $post ) : null;
		if ( ! $record instanceof WP_Post || ! in_array( $record->post_type, array( $relationship['from'], $relationship['to'] ), true ) ) {
			WP_CLI::error( sprintf( 'Post %s is not a "%s" or "%s" record.', $post, $relationship['from'], $relationship['to'] ) );
		}

		return array( $key, $record );
	}
}
