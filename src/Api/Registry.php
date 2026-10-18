<?php
/**
 * Public, read-only view of the effective schema registry.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Api;

/**
 * Effective content types, taxonomies and relationships, in canonical (normalized) form.
 *
 * Read-only: every method returns arrays by value. Get the instance with pegwork_registry().
 */
final class Registry {

	/**
	 * Creates the registry.
	 *
	 * @param array<string, array<string, mixed>> $content_types Content types by key.
	 * @param array<string, array<string, mixed>> $taxonomies    Taxonomies by key.
	 * @param array<string, array<string, mixed>> $relationships Relationships by key.
	 */
	public function __construct(
		private array $content_types = array(),
		private array $taxonomies = array(),
		private array $relationships = array()
	) {
	}

	/**
	 * All content types.
	 *
	 * @return array<string, array<string, mixed>> Content types by key, in load order.
	 */
	public function content_types(): array {
		return $this->content_types;
	}

	/**
	 * One content type.
	 *
	 * @param string $key Content type key.
	 * @return array<string, mixed>|null The content type, or null when it is not defined.
	 */
	public function content_type( string $key ): ?array {
		return $this->content_types[ $key ] ?? null;
	}

	/**
	 * Fields of a content type, optionally filtered by visibility.
	 *
	 * @param string      $type       Content type key.
	 * @param string|null $visibility `public`, `private`, or null for all fields.
	 * @return array<string, array<string, mixed>> Fields by key, in definition order. Empty for an unknown type.
	 */
	public function fields( string $type, ?string $visibility = null ): array {
		$fields = array();
		foreach ( $this->content_types[ $type ]['fields'] ?? array() as $field ) {
			if ( null === $visibility || $visibility === $field['visibility'] ) {
				$fields[ $field['key'] ] = $field;
			}
		}

		return $fields;
	}

	/**
	 * All taxonomies.
	 *
	 * @return array<string, array<string, mixed>> Taxonomies by key, in load order.
	 */
	public function taxonomies(): array {
		return $this->taxonomies;
	}

	/**
	 * All relationships.
	 *
	 * @return array<string, array<string, mixed>> Relationships by key, in load order.
	 */
	public function relationships(): array {
		return $this->relationships;
	}

	/**
	 * Relationships with the given content type at either end.
	 *
	 * @param string $type Content type key.
	 * @return array<string, array<string, mixed>> Relationships by key, in load order, where `from` or `to` is the type.
	 */
	public function relationships_for( string $type ): array {
		return array_filter(
			$this->relationships,
			fn( array $relationship ) => $type === $relationship['from'] || $type === $relationship['to']
		);
	}
}
