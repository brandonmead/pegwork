<?php
/**
 * Registry status: what is registered, from where, and what was shadowed or rejected.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Registry;

use Pegwork\Content\Meta;
use Pegwork\Plugin;

/**
 * Flat, display-ready rows describing a registry load.
 *
 * The admin Overview and `wp pegwork schema list|status` both read these rows, so the screen and the
 * CLI cannot disagree. Values are raw (unescaped); callers escape for their output.
 */
final class Status {

	/**
	 * Creates the status view of one load.
	 *
	 * @param Result   $result   Outcome of the registry load.
	 * @param string[] $packages Package directories passed to the load, in registration order.
	 */
	public function __construct(
		private readonly Result $result,
		private readonly array $packages = array()
	) {
	}

	/**
	 * Status of the registry built on the current request's init.
	 */
	public static function current(): self {
		return new self( Plugin::result(), Plugin::packages() );
	}

	/**
	 * Effective content types.
	 *
	 * @return list<array{key: string, label: string, rest_base: string, taxonomies: string[], source: string, fields: list<array{key: string, type: string, label: string, required: bool, visibility: string, stored_as: string}>}>
	 *         `stored_as` is the post meta key, or the post column for fields with `wp.storage`.
	 */
	public function content_types(): array {
		$rows = array();
		foreach ( $this->result->registry->content_types() as $key => $type ) {
			$fields = array();
			foreach ( $type['fields'] as $field ) {
				$fields[] = array(
					'key'        => $field['key'],
					'type'       => $field['type'],
					'label'      => $field['label'],
					'required'   => (bool) $field['required'],
					'visibility' => $field['visibility'],
					'stored_as'  => Meta::is_meta( $field ) ? Meta::key( $key, $field ) : $field['wp']['storage'],
				);
			}
			$rows[] = array(
				'key'        => $key,
				'label'      => $type['labels']['plural'],
				'rest_base'  => $type['wp']['rest_base'] ?? $key,
				'taxonomies' => array_column( $type['taxonomies'], 'key' ),
				'source'     => $this->source( 'content_type', $key ),
				'fields'     => $fields,
			);
		}
		return $rows;
	}

	/**
	 * Effective taxonomies.
	 *
	 * @return list<array{key: string, label: string, terms: int, locked: bool, source: string}>
	 */
	public function taxonomies(): array {
		$rows = array();
		foreach ( $this->result->registry->taxonomies() as $key => $taxonomy ) {
			$rows[] = array(
				'key'    => $key,
				'label'  => $taxonomy['labels']['plural'],
				'terms'  => count( $taxonomy['terms'] ),
				'locked' => (bool) $taxonomy['locked_terms'],
				'source' => $this->source( 'taxonomy', $key ),
			);
		}
		return $rows;
	}

	/**
	 * Effective relationships.
	 *
	 * @return list<array{key: string, from: string, to: string, cardinality: string, storage: string, required: bool, source: string}>
	 */
	public function relationships(): array {
		$rows = array();
		foreach ( $this->result->registry->relationships() as $key => $relationship ) {
			$rows[] = array(
				'key'         => $key,
				'from'        => $relationship['from'],
				'to'          => $relationship['to'],
				'cardinality' => $relationship['cardinality'],
				'storage'     => $relationship['storage'],
				'required'    => ! empty( $relationship['required'] ),
				'source'      => $this->source( 'relationship', $key ),
			);
		}
		return $rows;
	}

	/**
	 * Effective options pages.
	 *
	 * @return list<array{key: string, title: string, menu_title: string, capability: string, fields: int, private: int, source: string}>
	 *         `fields` counts every field, `private` the private ones.
	 */
	public function options_pages(): array {
		$rows = array();
		foreach ( $this->result->registry->options_pages() as $key => $page ) {
			$rows[] = array(
				'key'        => $key,
				'title'      => $page['title'],
				'menu_title' => $page['menu_title'] ?? $page['title'],
				'capability' => $page['capability'],
				'fields'     => count( $page['fields'] ),
				'private'    => count( array_filter( $page['fields'], fn( array $field ) => 'private' === $field['visibility'] ) ),
				'source'     => $this->source( 'options_page', $key ),
			);
		}
		return $rows;
	}

	/**
	 * Rows for the optional sections: content type listings, forms, automations and options pages.
	 *
	 * @return list<array{kind: string, key: string, label: string, details: string, source: string}>
	 *         `kind` is listing, form, automation or options_page; a listing row's key is its content type.
	 */
	public function sections(): array {
		$registry = $this->result->registry;
		$rows     = array();
		$size     = fn( array $form ) => isset( $form['steps'] ) ? count( $form['steps'] ) . ' step(s)' : count( $form['fields'] ) . ' field(s)';

		foreach ( $registry->content_types() as $key => $type ) {
			$listing = $registry->listing( $key );
			if ( null !== $listing ) {
				$rows[] = array(
					'kind'    => 'listing',
					'key'     => $key,
					'label'   => $type['labels']['plural'],
					'details' => sprintf( '%d filter(s)%s', count( $listing['filters'] ), $listing['search'] ? ', search' : '' ),
					'source'  => $this->source( 'content_type', $key ),
				);
			}
		}
		foreach ( $registry->forms() as $key => $form ) {
			$rows[] = array(
				'kind'    => 'form',
				'key'     => $key,
				'label'   => $form['label'],
				'details' => isset( $form['target'] )
					? sprintf( '%s %s, %s', $form['target']['action'], $form['target']['content_type'], $size( $form ) )
					: sprintf( 'entries only, %s', $size( $form ) ),
				'source'  => $this->source( 'form', $key ),
			);
		}
		foreach ( $registry->automations() as $key => $automation ) {
			$trigger = $automation['trigger'];
			$rows[]  = array(
				'kind'    => 'automation',
				'key'     => $key,
				'label'   => $automation['label'],
				'details' => sprintf(
					'%s, %d step(s)%s',
					match ( $trigger['type'] ) {
						'event'    => 'on ' . $trigger['event'],
						'schedule' => 'every ' . $trigger['every'],
						default    => 'webhook',
					},
					count( $automation['steps'] ),
					$automation['enabled'] ? '' : ', disabled'
				),
				'source'  => $this->source( 'automation', $key ),
			);
		}
		foreach ( $registry->options_pages() as $key => $page ) {
			$rows[] = array(
				'kind'    => 'options_page',
				'key'     => $key,
				'label'   => $page['title'],
				'details' => sprintf( '%d field(s), %s', count( $page['fields'] ), $page['capability'] ),
				'source'  => $this->source( 'options_page', $key ),
			);
		}
		return $rows;
	}

	/**
	 * DB entries hidden by a file package with the same key.
	 *
	 * @return list<array{kind: string, key: string, by: string}>
	 */
	public function shadowed(): array {
		return array_values( $this->result->shadowed );
	}

	/**
	 * Rejected sources with their errors: one row per package directory, then one for the DB source.
	 *
	 * An error belongs to the package directory that contains its file (the deepest one when directories
	 * nest); errors in no package directory belong to the DB source.
	 *
	 * @return list<array{source: string, errors: list<array{code: string, file: string, pointer: string, message: string}>}>
	 */
	public function rejected(): array {
		$groups = array();
		foreach ( $this->result->errors as $error ) {
			$source              = $this->owner( $error->file );
			$groups[ $source ][] = array(
				'code'    => $error->code,
				'file'    => $error->file,
				'pointer' => $error->pointer,
				'message' => $error->message,
			);
		}

		$order = array_merge( array_map( 'strval', $this->packages ), array( Loader::DB_SOURCE ) );
		$rows  = array();
		foreach ( $order as $source ) {
			if ( isset( $groups[ $source ] ) ) {
				$rows[] = array(
					'source' => $source,
					'errors' => $groups[ $source ],
				);
				unset( $groups[ $source ] );
			}
		}
		return $rows;
	}

	/**
	 * Package id (or DB source label) that supplied an object.
	 *
	 * @param string $kind Object kind.
	 * @param string $key  Object key.
	 */
	private function source( string $kind, string $key ): string {
		return $this->result->sources[ $kind ][ $key ] ?? '';
	}

	/**
	 * Package directory (as registered) that holds a file, or the DB source label.
	 *
	 * @param string $file Error file path.
	 */
	private function owner( string $file ): string {
		$owner = Loader::DB_SOURCE;
		$depth = -1;
		foreach ( $this->packages as $dir ) {
			$dir  = (string) $dir;
			$real = realpath( $dir );
			foreach ( array_unique( array( rtrim( $dir, '/\\' ), false === $real ? '' : $real ) ) as $prefix ) {
				if ( '' === $prefix ) {
					continue;
				}
				$inside = $file === $prefix || str_starts_with( $file, $prefix . '/' ) || str_starts_with( $file, $prefix . '\\' );
				if ( $inside && strlen( $prefix ) > $depth ) {
					$owner = $dir;
					$depth = strlen( $prefix );
				}
			}
		}
		return $owner;
	}
}
