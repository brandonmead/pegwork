<?php
/**
 * Unit tests for rules best checked on single objects (the fixtures cover one failure per rule).
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Unit\Registry;

use Pegwork\Registry\Validator;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests Validator.
 */
class ValidatorTest extends TestCase {

	/**
	 * Builds a content type with the given fields.
	 *
	 * @param array $fields Fields.
	 * @param array $extra  Extra top-level properties.
	 * @return array
	 */
	private function content_type( array $fields = array(), array $extra = array() ) {
		return array(
			'format' => 1,
			'key'    => 'thing',
			'labels' => array(
				'singular' => 'Thing',
				'plural'   => 'Things',
			),
			'fields' => $fields,
		) + $extra;
	}

	/**
	 * Error codes for a value.
	 *
	 * @param string $kind Kind.
	 * @param mixed  $data Data.
	 * @return string[]
	 */
	private function codes( $kind, $data ) {
		return array_map( fn( $error ) => $error->code, Validator::validate( $kind, $data, 'test.json' ) );
	}

	/**
	 * Every Phase 1 field type is accepted, with a default of its own type.
	 *
	 * @return array<string, array{string, mixed, mixed}> Type, valid default, invalid default.
	 */
	public function data_field_types() {
		return array(
			'text'      => array( 'text', 'a', 1 ),
			'textarea'  => array( 'textarea', 'a', true ),
			'rich_text' => array( 'rich_text', '<p>a</p>', array() ),
			'url'       => array( 'url', 'https://example.org', 2 ),
			'email'     => array( 'email', 'a@example.org', false ),
			'boolean'   => array( 'boolean', false, 'false' ),
			'date'      => array( 'date', '2026-10-17', '17/10/2026' ),
			'number'    => array( 'number', 1.5, '1.5' ),
			'select'    => array( 'select', 'a', 1 ),
		);
	}

	/**
	 * Each field type accepts a matching default and rejects a mismatched one.
	 *
	 * @dataProvider data_field_types
	 *
	 * @param string $type    Field type.
	 * @param mixed  $valid   Valid default.
	 * @param mixed  $invalid Invalid default.
	 */
	public function test_field_types_and_defaults( $type, $valid, $invalid ) {
		$field = array(
			'key'   => 'value',
			'type'  => $type,
			'label' => 'Value',
		);
		if ( 'select' === $type ) {
			$field['options'] = array(
				array(
					'value' => 'a',
					'label' => 'A',
				),
			);
		}

		$this->assertSame( array(), $this->codes( 'content_type', $this->content_type( array( $field + array( 'default' => $valid ) ) ) ) );
		$this->assertContains( 'invalid_default', $this->codes( 'content_type', $this->content_type( array( $field + array( 'default' => $invalid ) ) ) ) );
	}

	/**
	 * Validation rules apply by field type.
	 */
	public function test_validate_rules_by_type() {
		$text   = array(
			'key'      => 'a',
			'type'     => 'text',
			'label'    => 'A',
			'validate' => array(
				'min_length' => 0,
				'max_length' => 5,
				'min_words'  => 1,
				'max_words'  => 2,
				'pattern'    => '^a#b$',
			),
		);
		$number = array(
			'key'      => 'b',
			'type'     => 'number',
			'label'    => 'B',
			'validate' => array(
				'min' => -1,
				'max' => 2.5,
			),
		);
		$this->assertSame( array(), $this->codes( 'content_type', $this->content_type( array( $text, $number ) ) ) );

		$boolean  = array(
			'key'      => 'c',
			'type'     => 'boolean',
			'label'    => 'C',
			'validate' => array( 'max_length' => 1 ),
		);
		$text_min = array(
			'key'      => 'd',
			'type'     => 'text',
			'label'    => 'D',
			'validate' => array( 'min' => 1 ),
		);
		$this->assertSame( array( 'validate_not_applicable', 'validate_not_applicable' ), $this->codes( 'content_type', $this->content_type( array( $boolean, $text_min ) ) ) );

		$negative = array(
			'key'      => 'e',
			'type'     => 'text',
			'label'    => 'E',
			'validate' => array( 'min_length' => -1 ),
		);
		$this->assertSame( array( 'invalid_type' ), $this->codes( 'content_type', $this->content_type( array( $negative ) ) ) );
	}

	/**
	 * Key length limits sit exactly at 20 (post types) and 32 (taxonomies).
	 */
	public function test_key_length_limits() {
		$this->assertSame( array(), $this->codes( 'content_type', array( 'key' => str_repeat( 'a', 20 ) ) + $this->content_type() ) );
		$this->assertSame( array( 'key_too_long' ), $this->codes( 'content_type', array( 'key' => str_repeat( 'a', 21 ) ) + $this->content_type() ) );

		$taxonomy = array(
			'format' => 1,
			'labels' => array(
				'singular' => 'Topic',
				'plural'   => 'Topics',
			),
		);
		$this->assertSame( array(), $this->codes( 'taxonomy', $taxonomy + array( 'key' => str_repeat( 'a', 32 ) ) ) );
		$this->assertSame( array( 'key_too_long' ), $this->codes( 'taxonomy', $taxonomy + array( 'key' => str_repeat( 'a', 33 ) ) ) );
	}

	/**
	 * Field keys may use reserved words: only content type and taxonomy keys become query vars.
	 */
	public function test_reserved_words_allowed_as_field_keys() {
		$field = array(
			'key'   => 'name',
			'type'  => 'text',
			'label' => 'Name',
		);
		$this->assertSame( array(), $this->codes( 'content_type', $this->content_type( array( $field ) ) ) );
		$this->assertSame( array( 'reserved_key' ), $this->codes( 'content_type', array( 'key' => 'status' ) + $this->content_type() ) );
	}

	/**
	 * The reserved list covers every WordPress query var that a key could collide with.
	 */
	public function test_reserved_keys_cover_wordpress_query_vars() {
		global $wp;

		$query_vars = array_filter(
			array_merge( $wp->public_query_vars, $wp->private_query_vars ),
			fn( $query_var ) => (bool) preg_match( Validator::KEY_PATTERN, $query_var )
		);

		$this->assertSame( array(), array_values( array_diff( $query_vars, Validator::RESERVED_KEYS ) ) );
	}

	/**
	 * PHP objects (such as closures from a DB option) are rejected anywhere.
	 */
	public function test_objects_rejected_anywhere() {
		$field = array(
			'key'     => 'a',
			'type'    => 'text',
			'label'   => 'A',
			'default' => static fn() => 'x',
		);

		$errors = Validator::validate( 'content_type', $this->content_type( array( $field ) ), 'option:pegwork_schema', '/content_types/3' );

		$this->assertSame( 'callable_not_allowed', $errors[0]->code );
		$this->assertSame( '/content_types/3/fields/0/default', $errors[0]->pointer );
		$this->assertSame( 'option:pegwork_schema#/content_types/3/fields/0/default: ' . $errors[0]->message, (string) $errors[0] );
	}

	/**
	 * Pointers escape "~" and "/" (RFC 6901).
	 */
	public function test_pointer_escaping() {
		$this->assertSame( '/a/b~1c~0d', Validator::pointer( '/a', 'b/c~d' ) );
		$this->assertSame( '/a/0', Validator::pointer( '/a', 0 ) );
	}

	/**
	 * Wrong top-level types are reported, not fatal.
	 */
	public function test_non_object_documents() {
		$this->assertSame( array( 'invalid_type' ), $this->codes( 'manifest', array( 1, 2 ) ) );
		$this->assertSame( array( 'invalid_type' ), $this->codes( 'content_type', 'thing' ) );
		$this->assertSame( array( 'invalid_type' ), $this->codes( 'taxonomy', null ) );
		$this->assertSame( array( 'invalid_type' ), $this->codes( 'relationship', 1 ) );
	}

	/**
	 * References resolve against the known keys.
	 */
	public function test_references() {
		$type = $this->content_type( array(), array( 'taxonomies' => array( array( 'key' => 'topic' ) ) ) );

		$this->assertSame( array(), Validator::references( 'content_type', $type, 'f', array( 'taxonomy' => array( 'topic' => true ) ) ) );
		$this->assertSame( 'unknown_taxonomy', Validator::references( 'content_type', $type, 'f', array() )[0]->code );

		$relationship = array(
			'from' => 'thing',
			'to'   => 'other',
		);
		$errors       = Validator::references( 'relationship', $relationship, 'f', array( 'content_type' => array( 'thing' => true ) ) );
		$this->assertCount( 1, $errors );
		$this->assertSame( '/to', $errors[0]->pointer );
	}

	/**
	 * Relationship properties: label and required are optional and typed; meta is the only storage; ends differ.
	 */
	public function test_relationship_rules() {
		$relationship = array(
			'format'      => 1,
			'key'         => 'thing_other',
			'from'        => 'thing',
			'to'          => 'other',
			'cardinality' => 'one_to_many',
		);

		$this->assertSame( array(), $this->codes( 'relationship', $relationship ) );
		$this->assertSame(
			array(),
			$this->codes(
				'relationship',
				$relationship + array(
					'label'    => 'Others',
					'required' => true,
					'storage'  => 'meta',
				)
			)
		);
		$this->assertSame( array( 'invalid_type' ), $this->codes( 'relationship', $relationship + array( 'label' => '' ) ) );
		$this->assertSame( array( 'invalid_type' ), $this->codes( 'relationship', $relationship + array( 'required' => 1 ) ) );
		$this->assertSame( array( 'storage_not_supported' ), $this->codes( 'relationship', $relationship + array( 'storage' => 'table' ) ) );
		$this->assertSame( array( 'invalid_value' ), $this->codes( 'relationship', $relationship + array( 'storage' => 'custom' ) ) );
		$this->assertSame( array( 'self_relationship' ), $this->codes( 'relationship', array( 'to' => 'thing' ) + $relationship ) );
	}

	/**
	 * `_rel_{key}` may not be a field meta key on the "from" type; other types' fields and column fields do not count.
	 */
	public function test_relationship_meta_key_conflict() {
		$relationship = array(
			'key'  => 'thing_owner',
			'from' => 'thing',
			'to'   => 'other',
		);
		$known        = fn( array $fields ) => array(
			'content_type' => array(
				'thing' => $this->content_type( $fields ),
				'other' => true,
			),
		);
		$field        = fn( array $wp ) => array(
			'key'   => 'owner',
			'type'  => 'text',
			'label' => 'Owner',
			'wp'    => $wp,
		);

		$errors = Validator::references( 'relationship', $relationship, 'f', $known( array( $field( array( 'meta_key' => '_rel_thing_owner' ) ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Fixture, not a query.
		$this->assertSame( array( 'meta_key_conflict' ), array_map( fn( $e ) => $e->code, $errors ) );
		$this->assertSame( '/key', $errors[0]->pointer );

		$this->assertSame( array(), Validator::references( 'relationship', $relationship, 'f', $known( array( $field( array( 'meta_key' => '_thing_owner' ) ) ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Fixture, not a query.
		$this->assertSame( array(), Validator::references( 'relationship', $relationship, 'f', $known( array( $field( array( 'storage' => 'post_title' ) ) ) ) ) );
	}
}
