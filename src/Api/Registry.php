<?php
/**
 * Public, read-only view of the effective schema registry.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Api;

/**
 * Effective content types, taxonomies, relationships, forms, automations and options pages, in canonical
 * (normalized) form.
 *
 * Read-only: every method returns arrays by value. Get the instance with pegwork_registry().
 */
final class Registry {

	/**
	 * Source label of objects from the `pegwork_schema` option (Pegwork\Registry\Loader::DB_SOURCE).
	 */
	private const DB_SOURCE = 'option:pegwork_schema';

	/**
	 * Creates the registry.
	 *
	 * @param array<string, array<string, mixed>>  $content_types Content types by key.
	 * @param array<string, array<string, mixed>>  $taxonomies    Taxonomies by key.
	 * @param array<string, array<string, mixed>>  $relationships Relationships by key.
	 * @param array<string, array<string, mixed>>  $forms         Forms by key.
	 * @param array<string, array<string, mixed>>  $automations   Automations by key.
	 * @param array<string, array<string, mixed>>  $options_pages Options pages by key.
	 * @param array<string, array<string, string>> $sources       Source of each object: [kind][key] => package id or the DB source label.
	 */
	public function __construct(
		private array $content_types = array(),
		private array $taxonomies = array(),
		private array $relationships = array(),
		private array $forms = array(),
		private array $automations = array(),
		private array $options_pages = array(),
		private array $sources = array()
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

	/**
	 * The `listing` section of a content type: what its directories may filter by.
	 *
	 * @param string $type Content type key.
	 * @return array{search: bool, filters: list<array<string, mixed>>}|null The listing, or null when the type is
	 *         unknown or declares none. Each filter has `key`, `taxonomy` or `field`, `multiple`, and optional `label`.
	 */
	public function listing( string $type ): ?array {
		return $this->content_types[ $type ]['listing'] ?? null;
	}

	/**
	 * All forms.
	 *
	 * @return array<string, array<string, mixed>> Forms by key, in load order.
	 */
	public function forms(): array {
		return $this->forms;
	}

	/**
	 * One form.
	 *
	 * @param string $key Form key.
	 * @return array<string, mixed>|null The form, or null when it is not defined.
	 */
	public function form( string $key ): ?array {
		return $this->forms[ $key ] ?? null;
	}

	/**
	 * Where a form is defined: `file:<package id>` for a file package, `db` for the `pegwork_schema` option.
	 *
	 * File-defined forms are read-only in the builder; `db` forms can be saved and deleted.
	 *
	 * @param string $key Form key.
	 * @return string|null Source, or null when the form is not defined.
	 */
	public function form_source( string $key ): ?string {
		$source = $this->sources['form'][ $key ] ?? null;
		if ( null === $source || ! isset( $this->forms[ $key ] ) ) {
			return null;
		}

		return self::DB_SOURCE === $source ? 'db' : 'file:' . $source;
	}

	/**
	 * All automations.
	 *
	 * @return array<string, array<string, mixed>> Automations by key, in load order.
	 */
	public function automations(): array {
		return $this->automations;
	}

	/**
	 * One automation.
	 *
	 * @param string $key Automation key.
	 * @return array<string, mixed>|null The automation, or null when it is not defined.
	 */
	public function automation( string $key ): ?array {
		return $this->automations[ $key ] ?? null;
	}

	/**
	 * All options pages.
	 *
	 * @return array<string, array<string, mixed>> Options pages by key, in load order.
	 */
	public function options_pages(): array {
		return $this->options_pages;
	}

	/**
	 * One options page. Its key is also the name of the option that stores its values.
	 *
	 * @param string $key Options page key.
	 * @return array<string, mixed>|null The options page, or null when it is not defined.
	 */
	public function options_page( string $key ): ?array {
		return $this->options_pages[ $key ] ?? null;
	}
}
