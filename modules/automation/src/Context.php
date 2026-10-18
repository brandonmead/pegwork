<?php
/**
 * Run context: what conditions and templates can read.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation;

use Pegwork\Api\Records;
use PegworkAutomation\Clock\Clock;

/**
 * Resolves paths (`record.*`, `trigger.*`, `steps.<id>.*`, `site.*`, `today`, `now`, `run.id`) for one
 * run. The record is read through `Pegwork\Api\Records` (private fields included: contexts that output
 * values choose what to show).
 */
final class Context {

	/**
	 * Engine clock.
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Bound record (Records::get shape), or null.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $record;

	/**
	 * Trigger payload.
	 *
	 * @var array<string, mixed>
	 */
	private array $trigger;

	/**
	 * Step outputs by step ID.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $steps;

	/**
	 * Run ID, 0 before the run exists.
	 *
	 * @var int
	 */
	private int $run_id;

	/**
	 * Builds a context.
	 *
	 * @param Clock                               $clock   Engine clock.
	 * @param array<string, mixed>|null           $record  Bound record, or null.
	 * @param array<string, mixed>                $trigger Trigger payload.
	 * @param array<string, array<string, mixed>> $steps   Step outputs.
	 * @param int                                 $run_id  Run ID.
	 */
	public function __construct( Clock $clock, ?array $record, array $trigger = array(), array $steps = array(), int $run_id = 0 ) {
		$this->clock   = $clock;
		$this->record  = $record;
		$this->trigger = $trigger;
		$this->steps   = $steps;
		$this->run_id  = $run_id;
	}

	/**
	 * The bound record, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public function record(): ?array {
		return $this->record;
	}

	/**
	 * Re-reads the bound record (after a step wrote it).
	 */
	public function refresh_record(): void {
		if ( null !== $this->record ) {
			$this->record = Records::get( (int) $this->record['id'] );
		}
	}

	/**
	 * Run ID.
	 */
	public function run_id(): int {
		return $this->run_id;
	}

	/**
	 * Engine clock.
	 */
	public function clock(): Clock {
		return $this->clock;
	}

	/**
	 * Stores a step's output for `steps.<id>.<key>` paths.
	 *
	 * @param string               $step_id Step ID.
	 * @param array<string, mixed> $output  Output values.
	 */
	public function set_output( string $step_id, array $output ): void {
		$this->steps[ $step_id ] = $output;
	}

	/**
	 * Step outputs, for storage.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function outputs(): array {
		return $this->steps;
	}

	/**
	 * Today in site time, `Y-m-d`.
	 */
	public function today(): string {
		return $this->clock->now()->setTimezone( wp_timezone() )->format( 'Y-m-d' );
	}

	/**
	 * Now in site time, ISO 8601.
	 */
	public function now(): string {
		return $this->clock->now()->setTimezone( wp_timezone() )->format( 'Y-m-d\TH:i:sP' );
	}

	/**
	 * Value at a path, or null when it does not exist.
	 *
	 * @param string $path Dot path, such as record.last_verified or trigger.old.status.
	 * @return mixed
	 */
	public function resolve( string $path ): mixed {
		$parts = explode( '.', $path );
		$root  = array_shift( $parts );

		switch ( $root ) {
			case 'today':
				return array() === $parts ? $this->today() : null;
			case 'now':
				return array() === $parts ? $this->now() : null;
			case 'run':
				return array( 'id' ) === $parts ? $this->run_id : null;
			case 'site':
				$site = array(
					'name' => get_bloginfo( 'name' ),
					'url'  => home_url( '/' ),
				);
				return self::dig( $site, $parts );
			case 'trigger':
				return self::dig( $this->trigger, $parts );
			case 'steps':
				return self::dig( $this->steps, $parts );
			case 'record':
				return $this->record_value( $parts );
			case 'link':
				// Signed links arrive with the link handler (Phase 4 PR 5).
				return null;
		}
		return null;
	}

	/**
	 * Whether a path reads a private field: `record.<key>` or `trigger.old.<key>` of a field with
	 * visibility `private` (contexts that show values outside the email use this to hide them).
	 *
	 * @param string $path Dot path.
	 */
	public function is_private( string $path ): bool {
		return 'private' === ( $this->field( $path )['visibility'] ?? null );
	}

	/**
	 * Field type of a `record.<key>` or `trigger.old.<key>` path, or null when it is not a field.
	 *
	 * @param string $path Dot path.
	 */
	public function field_type( string $path ): ?string {
		$field = $this->field( $path );
		return null === $field ? null : (string) $field['type'];
	}

	/**
	 * Taxonomy name when a `record.<key>` or `trigger.old.<key>` path is a taxonomy of the record's type.
	 *
	 * @param string $path Dot path.
	 */
	public function taxonomy( string $path ): ?string {
		[ $type, $key ] = $this->path_key( $path );
		if ( null === $type ) {
			return null;
		}
		$definition = pegwork_registry()->content_type( $type );
		$keys       = array_column( $definition['taxonomies'] ?? array(), 'key' );
		return in_array( $key, $keys, true ) ? $key : null;
	}

	/**
	 * Registry field behind a `record.<key>` or `trigger.old.<key>` path.
	 *
	 * @param string $path Dot path.
	 * @return array<string, mixed>|null
	 */
	private function field( string $path ): ?array {
		[ $type, $key ] = $this->path_key( $path );
		return null === $type ? null : ( pegwork_registry()->fields( $type )[ $key ] ?? null );
	}

	/**
	 * Content type and key a path reads: `record.<key>` (bound record) or `trigger.old.<key>` (payload type).
	 *
	 * @param string $path Dot path.
	 * @return array{0: string|null, 1: string}
	 */
	private function path_key( string $path ): array {
		$parts = explode( '.', $path );
		if ( 2 === count( $parts ) && 'record' === $parts[0] && null !== $this->record ) {
			return array( (string) $this->record['type'], $parts[1] );
		}
		if ( 3 === count( $parts ) && 'trigger' === $parts[0] && 'old' === $parts[1] ) {
			$type = $this->trigger['type'] ?? ( $this->record['type'] ?? null );
			return array( is_string( $type ) ? $type : null, $parts[2] );
		}
		return array( null, '' );
	}

	/**
	 * A `record.*` value.
	 *
	 * @param string[] $parts Path after `record`.
	 * @return mixed
	 */
	private function record_value( array $parts ): mixed {
		if ( null === $this->record || 1 !== count( $parts ) ) {
			return null;
		}
		$key = $parts[0];
		switch ( $key ) {
			case 'id':
				return (int) $this->record['id'];
			case 'status':
			case 'type':
			case 'permalink':
				return $this->record[ $key ];
			case 'edit_url':
				// Not get_edit_post_link(): runs have no current user, and that returns null without one.
				return admin_url( 'post.php?post=' . (int) $this->record['id'] . '&action=edit' );
		}
		return $this->record['values'][ $key ] ?? null;
	}

	/**
	 * Walks nested arrays.
	 *
	 * @param array<string|int, mixed> $data  Data.
	 * @param string[]                 $parts Keys.
	 * @return mixed
	 */
	private static function dig( array $data, array $parts ): mixed {
		$value = $data;
		foreach ( $parts as $part ) {
			if ( ! is_array( $value ) || ! array_key_exists( $part, $value ) ) {
				return null;
			}
			$value = $value[ $part ];
		}
		return $value;
	}
}
