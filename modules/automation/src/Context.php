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
 * Resolves paths (`record.*`, `trigger.*`, `steps.<id>.*`, `site.*`, `today`, `now`, `run.id`, and
 * `item.*` inside an `{{#each}}` block) for one run. Records are read through `Pegwork\Api\Records`
 * (private fields included: contexts that output values choose what to show).
 *
 * Read outputs are references: a `get_record` stores `found` and `record_id`, a `find_records` stores
 * `count`, `ids` and `sum`. `steps.<id>.record.<key>` and `{{#each}}` items re-read the record by ID
 * through a per-wake-up cache, which write steps invalidate by ID (forget()), so a later step sees written
 * values and a step after a wait sees current ones. The context also counts the records reads return
 * (`context.reads`, at most read_limit() per run).
 */
final class Context {

	/**
	 * Default most records a run may read (IDs returned by reads, plus loop items).
	 */
	public const READ_LIMIT = 500;

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
	 * Automation key, '' when unknown (then `link.*` resolves to null).
	 *
	 * @var string
	 */
	private string $automation;

	/**
	 * Signed link URLs minted so far, by link name: one token per link per context, so the text and HTML
	 * parts of one email carry the same link.
	 *
	 * @var array<string, string|null>
	 */
	private array $links = array();

	/**
	 * Records read by ID in this wake-up (null when the ID is not a registry record), by ID.
	 *
	 * @var array<int, array<string, mixed>|null>
	 */
	private array $cache = array();

	/**
	 * The run's steps (to know the content type a read step reads), or null.
	 *
	 * @var Plan|null
	 */
	private ?Plan $plan = null;

	/**
	 * The current `{{#each}}` item, or null outside a block.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $item = null;

	/**
	 * Records read so far in the run.
	 *
	 * @var int
	 */
	private int $reads = 0;

	/**
	 * Builds a context.
	 *
	 * @param Clock                               $clock      Engine clock.
	 * @param array<string, mixed>|null           $record     Bound record, or null.
	 * @param array<string, mixed>                $trigger    Trigger payload.
	 * @param array<string, array<string, mixed>> $steps      Step outputs.
	 * @param int                                 $run_id     Run ID.
	 * @param string                              $automation Automation key (needed for `link.*`).
	 */
	public function __construct( Clock $clock, ?array $record, array $trigger = array(), array $steps = array(), int $run_id = 0, string $automation = '' ) {
		$this->clock      = $clock;
		$this->record     = $record;
		$this->trigger    = $trigger;
		$this->steps      = $steps;
		$this->run_id     = $run_id;
		$this->automation = $automation;
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
	 * Uses the run's step plan, so read outputs know their content type (privacy of `sum` fields, field
	 * types of a record that was not found).
	 *
	 * @param Plan $plan Plan.
	 */
	public function use_plan( Plan $plan ): void {
		$this->plan = $plan;
	}

	/**
	 * A record by ID through the wake-up cache (the bound record for its own ID), or null when the ID is
	 * not a registry record or the record is in the trash.
	 *
	 * @param int $id Record ID.
	 * @return array<string, mixed>|null
	 */
	public function record_by_id( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}
		if ( null !== $this->record && (int) $this->record['id'] === $id ) {
			return $this->record;
		}
		if ( ! array_key_exists( $id, $this->cache ) ) {
			$record             = Records::get( $id );
			$this->cache[ $id ] = null !== $record && 'trash' === $record['status'] ? null : $record; // Trash is never read.
		}
		return $this->cache[ $id ];
	}

	/**
	 * Drops a record from the cache after a step wrote it, and re-reads the bound record when it is that one.
	 *
	 * @param int $id Record ID.
	 */
	public function forget( int $id ): void {
		unset( $this->cache[ $id ] );
		if ( null !== $this->record && (int) $this->record['id'] === $id ) {
			$this->refresh_record();
		}
	}

	/**
	 * Sets the current `{{#each}}` item (null after the block).
	 *
	 * @param array<string, mixed>|null $record Record.
	 */
	public function set_item( ?array $record ): void {
		$this->item = $record;
	}

	/**
	 * Whether a step has stored outputs (it ran in this run, or was read in this dry run).
	 *
	 * @param string $step_id Step ID.
	 */
	public function has_output( string $step_id ): bool {
		return isset( $this->steps[ $step_id ] );
	}

	/**
	 * The record IDs of a collection path (`steps.<id>.records`), or null when that step has no output.
	 *
	 * @param string $path Path.
	 * @return int[]|null
	 */
	public function collection( string $path ): ?array {
		$parts = explode( '.', $path );
		if ( 3 !== count( $parts ) || 'steps' !== $parts[0] || 'records' !== $parts[2] || ! isset( $this->steps[ $parts[1] ]['ids'] ) ) {
			return null;
		}
		return array_values( array_map( 'intval', (array) $this->steps[ $parts[1] ]['ids'] ) );
	}

	/**
	 * Records read so far in the run.
	 */
	public function reads(): int {
		return $this->reads;
	}

	/**
	 * Restores the run's stored read count (`context.reads`).
	 *
	 * @param int $reads Count.
	 */
	public function restore_reads( int $reads ): void {
		$this->reads = max( 0, $reads );
	}

	/**
	 * Counts the records a read returned; false, counting nothing, when that would pass the read limit.
	 *
	 * @param int $count Records returned.
	 */
	public function count_reads( int $count ): bool {
		if ( $this->reads + $count > self::read_limit() ) {
			return false;
		}
		$this->reads += $count;
		return true;
	}

	/**
	 * Most records one run may read.
	 */
	public static function read_limit(): int {
		/**
		 * Filters the most records one run may read: IDs returned by get_record and find_records, plus loop
		 * items. A read past it fails with `read_limit` (no retry; `on_error` applies).
		 *
		 * @param int $limit Default 500.
		 */
		return max( 0, (int) apply_filters( 'pegwork_automation_read_limit', self::READ_LIMIT ) );
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
		if ( str_starts_with( $path, 'option:' ) ) {
			$parts = explode( '.', substr( $path, 7 ) );
			// Templates only (the registry allows option paths nowhere else); null without options pages.
			return 2 === count( $parts ) && function_exists( 'pegwork_get_option' ) ? call_user_func( 'pegwork_get_option', $parts[0], $parts[1] ) : null;
		}
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
				return $this->step_value( $parts );
			case 'record':
				return self::record_value( $this->record, $parts );
			case 'item':
				return self::record_value( $this->item, $parts );
			case 'link':
				return 1 === count( $parts ) ? $this->link( $parts[0] ) : null;
		}
		return null;
	}

	/**
	 * A `steps.<id>.*` value: `steps.<id>.record.<key>` reads the record a get_record found, `records` is a
	 * collection (not a value), everything else is the stored output.
	 *
	 * @param string[] $parts Path after `steps`.
	 * @return mixed
	 */
	private function step_value( array $parts ): mixed {
		$output = $this->steps[ $parts[0] ?? '' ] ?? null;
		if ( ! is_array( $output ) ) {
			return null;
		}
		$name = $parts[1] ?? null;
		if ( 'record' === $name && array_key_exists( 'found', $output ) ) {
			return self::record_value( $this->record_by_id( (int) ( $output['record_id'] ?? 0 ) ), array_slice( $parts, 2 ) );
		}
		if ( 'records' === $name && array_key_exists( 'ids', $output ) ) {
			return null; // Consumed only by {{#each}} and loops, through collection().
		}
		return self::dig( $output, array_slice( $parts, 1 ) );
	}

	/**
	 * A signed URL for one of the automation's links on the bound record, valid for the link's `expires`
	 * from now (each send gets the full lifetime). Null without a record, automation or such a link.
	 *
	 * @param string $name Link name.
	 */
	private function link( string $name ): ?string {
		if ( null === $this->record || '' === $this->automation ) {
			return null;
		}
		if ( ! array_key_exists( $name, $this->links ) ) {
			$this->links[ $name ] = Links\Links::url( $this->automation, $name, (int) $this->record['id'], $this->clock->now() );
		}
		return $this->links[ $name ];
	}

	/**
	 * Whether a path reads a private field: `record.<key>`, `trigger.old.<key>`, a read record's
	 * `steps.<id>.record.<key>`, an `{{#each}}` `item.<key>`, a `steps.<id>.sum.<field>` over a private
	 * field, or (form events) `trigger.values.<key>`, of a field with visibility `private` (contexts that
	 * show values outside the email use this to hide them).
	 *
	 * @param string $path Dot path.
	 */
	public function is_private( string $path ): bool {
		return 'private' === ( ( $this->field( $path ) ?? $this->form_field( $path ) )['visibility'] ?? null );
	}

	/**
	 * Registry field a `trigger.values.<key>` path of a form event reads, when that form item is bound to a
	 * field of the form's target type (so a private field stays private when it arrives through a form).
	 *
	 * @param string $path Dot path.
	 * @return array<string, mixed>|null
	 */
	private function form_field( string $path ): ?array {
		$parts = explode( '.', $path );
		if ( 3 !== count( $parts ) || 'trigger' !== $parts[0] || 'values' !== $parts[1] || ! is_string( $this->trigger['form'] ?? null ) ) {
			return null;
		}
		$form = pegwork_registry()->form( $this->trigger['form'] );
		$type = $form['target']['content_type'] ?? null;
		if ( ! is_string( $type ) ) {
			return null;
		}
		$lists = isset( $form['steps'] ) ? array_column( (array) $form['steps'], 'fields' ) : array( $form['fields'] ?? array() );
		foreach ( $lists as $items ) {
			foreach ( (array) $items as $item ) {
				if ( ( $item['bind']['field'] ?? null ) === $parts[2] ) {
					return pegwork_registry()->fields( $type )[ $parts[2] ] ?? null;
				}
			}
		}
		return null;
	}

	/**
	 * Field type of a record field path (see path_key()), or null when it is not a field.
	 *
	 * @param string $path Dot path.
	 */
	public function field_type( string $path ): ?string {
		$field = $this->field( $path );
		return null === $field ? null : (string) $field['type'];
	}

	/**
	 * Label of the field a record field path (see path_key()) names, or null when it is not a field.
	 *
	 * @param string $path Dot path.
	 */
	public function field_label( string $path ): ?string {
		$field = $this->field( $path );
		return null === $field ? null : (string) ( $field['label'] ?? $field['key'] );
	}

	/**
	 * Taxonomy name when a record field path (see path_key()) is a taxonomy of the record's type.
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
	 * Registry field behind a record field path or an options page path.
	 *
	 * @param string $path Dot path.
	 * @return array<string, mixed>|null
	 */
	private function field( string $path ): ?array {
		if ( str_starts_with( $path, 'option:' ) ) {
			$parts = explode( '.', substr( $path, 7 ) );
			$page  = 2 === count( $parts ) ? pegwork_registry()->options_page( $parts[0] ) : null;
			return is_array( $page ) ? ( array_column( $page['fields'] ?? array(), null, 'key' )[ $parts[1] ] ?? null ) : null;
		}
		[ $type, $key ] = $this->path_key( $path );
		return null === $type ? null : ( pegwork_registry()->fields( $type )[ $key ] ?? null );
	}

	/**
	 * Content type and key a path reads: `record.<key>` (bound record), `trigger.old.<key>` (payload type),
	 * `item.<key>` (the `{{#each}}` item), `steps.<id>.record.<key>` and `steps.<id>.sum.<field>` (the
	 * content type of that read step).
	 *
	 * @param string $path Dot path.
	 * @return array{0: string|null, 1: string}
	 */
	private function path_key( string $path ): array {
		$parts = explode( '.', $path );
		if ( 2 === count( $parts ) && 'record' === $parts[0] && null !== $this->record ) {
			return array( (string) $this->record['type'], $parts[1] );
		}
		if ( 2 === count( $parts ) && 'item' === $parts[0] && null !== $this->item ) {
			return array( (string) $this->item['type'], $parts[1] );
		}
		if ( 3 === count( $parts ) && 'trigger' === $parts[0] && 'old' === $parts[1] ) {
			$type = $this->trigger['type'] ?? ( $this->record['type'] ?? null );
			return array( is_string( $type ) ? $type : null, $parts[2] );
		}
		if ( 4 === count( $parts ) && 'steps' === $parts[0] && in_array( $parts[2], array( 'record', 'sum' ), true ) ) {
			$type = $this->plan?->step( $parts[1] )['content_type'] ?? null;
			if ( ! is_string( $type ) && 'record' === $parts[2] ) {
				$type = $this->record_by_id( (int) ( $this->steps[ $parts[1] ]['record_id'] ?? 0 ) )['type'] ?? null;
			}
			return array( is_string( $type ) ? $type : null, $parts[3] );
		}
		return array( null, '' );
	}

	/**
	 * A value of a record: `id`, `status`, `type`, `permalink`, `edit_url`, or a field or taxonomy key.
	 *
	 * @param array<string, mixed>|null $record Record, or null.
	 * @param string[]                  $parts  Path after the record.
	 * @return mixed
	 */
	private static function record_value( ?array $record, array $parts ): mixed {
		if ( null === $record || 1 !== count( $parts ) ) {
			return null;
		}
		$key = $parts[0];
		switch ( $key ) {
			case 'id':
				return (int) $record['id'];
			case 'status':
			case 'type':
			case 'permalink':
				return $record[ $key ];
			case 'edit_url':
				// Not get_edit_post_link(): runs have no current user, and that returns null without one.
				return admin_url( 'post.php?post=' . (int) $record['id'] . '&action=edit' );
		}
		return $record['values'][ $key ] ?? null;
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
