<?php
/**
 * Unit tests for rules best checked on single objects (the fixtures cover one failure per rule).
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Unit\Registry;

use Pegwork\Registry\Loader;
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

	/**
	 * One object from the sections fixture.
	 *
	 * @param string $path Path inside the fixture, such as "forms/contact.json".
	 * @return array
	 */
	private function sections( $path ) {
		return json_decode( file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/valid/sections/' . $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
	}

	/**
	 * Known objects of the sections fixture, in normalized form, for reference checks.
	 *
	 * @return array
	 */
	private function sections_known() {
		$registry = Loader::load( array( dirname( __DIR__, 2 ) . '/fixtures/valid/sections' ) )->registry;
		$known    = array();
		foreach ( Loader::SECTIONS as $kind => $section ) {
			$known[ $kind ] = $registry->$section();
		}
		return $known;
	}

	/**
	 * Codes and pointers from per-file and reference checks together.
	 *
	 * @param string $kind Kind.
	 * @param array  $data Object.
	 * @return string[]
	 */
	private function all_codes( $kind, array $data ) {
		$errors = Validator::validate( $kind, $data, 'f' );
		if ( ! $errors ) {
			$errors = Validator::references( $kind, $data, 'f', $this->sections_known() );
		}
		return array_map( fn( $e ) => $e->code . ' ' . $e->pointer, $errors );
	}

	/**
	 * Content type fields may not be keyed id, status or type; options page fields may.
	 */
	public function test_reserved_field_keys() {
		foreach ( Validator::RESERVED_FIELD_KEYS as $key ) {
			$field = array(
				'key'   => $key,
				'type'  => 'text',
				'label' => 'X',
			);
			$this->assertSame( array( 'reserved_field_key' ), $this->codes( 'content_type', $this->content_type( array( $field ) ) ), $key );

			$page             = $this->sections( 'options-pages/talk_settings.json' );
			$page['fields'][] = $field;
			$this->assertSame( array(), $this->codes( 'options_page', $page ), $key );
		}
	}

	/**
	 * `post` fields exist only on options pages and `file` fields only on forms.
	 */
	public function test_field_types_by_context() {
		$this->assertContains( 'post', Validator::field_types( 'options_page' ) );
		$this->assertNotContains( 'file', Validator::field_types( 'options_page' ) );
		$this->assertContains( 'file', Validator::field_types( 'form' ) );
		$this->assertNotContains( 'post', Validator::field_types( 'form' ) );
		$this->assertNotContains( 'post', Validator::field_types( 'content_type' ) );

		$file = array(
			'key'    => 'upload',
			'type'   => 'file',
			'label'  => 'Upload',
			'accept' => array( 'pdf' ),
		);
		$this->assertContains( 'invalid_value', $this->codes( 'content_type', $this->content_type( array( $file ) ) ) );

		$form             = $this->sections( 'forms/contact.json' );
		$form['fields'][] = array(
			'key'   => 'pick',
			'type'  => 'post',
			'label' => 'Pick',
		);
		$this->assertSame( array( 'post_field_not_allowed' ), $this->codes( 'form', $form ) );
	}

	/**
	 * Unbound select fields on forms: radio and select fit one value, checkboxes do not.
	 */
	public function test_unbound_form_display() {
		$form = $this->sections( 'forms/contact.json' );
		$this->assertSame( array(), $this->codes( 'form', $form ) );

		$form['fields'][2]['display'] = 'radio';
		$this->assertSame( array(), $this->codes( 'form', $form ) );

		$form['fields'][2]['display'] = 'checkboxes';
		$this->assertSame( array( 'form_display' ), $this->codes( 'form', $form ) );
	}

	/**
	 * Form recipients: double-brace option tags get a hint; access is "anyone" or a capability object.
	 */
	public function test_form_recipients_and_access() {
		$form                           = $this->sections( 'forms/contact.json' );
		$form['notifications'][0]['to'] = '{{option:talk_settings.organizer_email}}';
		$errors                         = Validator::validate( 'form', $form, 'f' );
		$this->assertSame( 'invalid_recipient', $errors[0]->code );
		$this->assertStringContainsString( '{option:talk_settings.organizer_email}', $errors[0]->message );

		$form['notifications'][0]['to'] = '{option:talk_settings.organizer_email}';
		$this->assertSame( array(), $this->all_codes( 'form', $form ) );

		$form['notifications'][0]['to'] = '{option:talk_settings.intro}';
		$this->assertSame( array( 'form_recipient_not_email /notifications/0/to' ), $this->all_codes( 'form', $form ) );

		$form['access'] = 'anyone';
		$this->assertSame( array(), $this->codes( 'form', $form ) );
		$form['access'] = 'everyone';
		$this->assertSame( array( 'invalid_value' ), $this->codes( 'form', $form ) );
	}

	/**
	 * Durations are ISO 8601 between one hour and two years.
	 */
	public function test_duration_bounds() {
		$automation = $this->sections( 'automations/talk_followup.json' );
		foreach ( array( 'PT1H', 'P2Y', 'P1Y11M30D', 'P1W' ) as $ok ) {
			$automation['steps'][1]['for'] = $ok;
			$this->assertSame( array(), $this->codes( 'automation', $automation ), $ok );
		}
		foreach ( array( 'PT59M', 'P2YT1S', 'P3Y' ) as $out ) {
			$automation['steps'][1]['for'] = $out;
			$this->assertSame( array( 'duration_out_of_range' ), $this->codes( 'automation', $automation ), $out );
		}
		$automation['steps'][1]['for'] = 'P';
		$this->assertSame( array( 'invalid_duration' ), $this->codes( 'automation', $automation ) );
	}

	/**
	 * Step ids are unique within one step list; a link's steps are their own list.
	 */
	public function test_step_ids_unique_per_list() {
		$automation = $this->sections( 'automations/talk_followup.json' );

		$automation['links']['uploaded']['steps'][0]['id'] = 'ask';
		$this->assertSame( array(), $this->codes( 'automation', $automation ) );

		$automation['steps'][1]['id'] = 'ask';
		$this->assertSame( array( 'duplicate_key' ), $this->codes( 'automation', $automation ) );
	}

	/**
	 * `changed` needs a record.updated trigger; condition literals must fit the field type.
	 */
	public function test_automation_condition_types() {
		$this->assertSame( array(), $this->all_codes( 'automation', $this->sections( 'automations/talk_updated.json' ) ) );

		$published = $this->sections( 'automations/talk_published.json' );
		$this->assertSame( array(), $this->all_codes( 'automation', $published ) );

		$published['trigger']['when'] = array(
			'var' => 'record.recorded',
			'op'  => 'changed',
		);
		$this->assertSame( array( 'condition_type_mismatch /trigger/when/op' ), $this->all_codes( 'automation', $published ) );

		$published['trigger']['when'] = array(
			'var'   => 'record.held_on',
			'op'    => 'lt',
			'value' => 'soon',
		);
		$this->assertSame( array( 'condition_type_mismatch /trigger/when' ), $this->all_codes( 'automation', $published ) );

		$published['trigger']['when']['value'] = '2026-10-18';
		$this->assertSame( array(), $this->all_codes( 'automation', $published ) );
	}

	/**
	 * An automation of the branching fixture.
	 *
	 * @param string $key Automation key.
	 * @return array
	 */
	private function branching( $key ) {
		return json_decode( file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/valid/branching/automations/' . $key . '.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
	}

	/**
	 * Codes and pointers from per-file and reference checks against the branching fixture.
	 *
	 * @param array $data  Automation.
	 * @param array $forms Forms to replace in the known objects, by key.
	 * @return string[]
	 */
	private function branching_codes( array $data, array $forms = array() ) {
		$registry = Loader::load( array( dirname( __DIR__, 2 ) . '/fixtures/valid/branching' ) )->registry;
		$known    = array();
		foreach ( Loader::SECTIONS as $kind => $section ) {
			$known[ $kind ] = $registry->$section();
		}
		$known['form'] = array_merge( $known['form'], $forms );

		$errors = Validator::validate( 'automation', $data, 'f' );
		if ( ! $errors ) {
			$errors = Validator::references( 'automation', $data, 'f', $known );
		}
		return array_map( fn( $e ) => $e->code . ' ' . $e->pointer, $errors );
	}

	/**
	 * Decisions: step ids are unique across the tree; outputs are in scope only on paths that ran the step;
	 * branches and otherwise may be empty; the automation's own list may not.
	 */
	public function test_decision_ids_and_output_scope() {
		$deep = $this->branching( 'br_deep' );
		$this->assertSame( array(), $this->branching_codes( $deep ) );

		// An earlier sibling of an enclosing list is in scope inside the branch.
		$outer = $deep;
		array_unshift(
			$outer['steps'],
			array(
				'id'           => 'first_task',
				'type'         => 'create_record',
				'content_type' => 'help_task',
				'values'       => array( 'name' => 'First' ),
			)
		);
		$outer['steps'][1]['branches'][0]['steps'][0]['branches'][0]['steps'][0]['otherwise'][0]['values']['notes'] = 'after {{steps.first_task.record_id}}';
		$this->assertSame( array(), $this->branching_codes( $outer ) );

		// The otherwise list cannot read an output of the branch beside it, nor can a later top-level step.
		$sibling = $deep;
		$sibling['steps'][0]['branches'][0]['steps'][0]['branches'][0]['steps'][0]['otherwise'][0]['values']['notes'] = '{{steps.make_task.record_id}}';
		$sibling['steps'][1]['text'] = 'Task {{steps.make_task.record_id}} and {{steps.later.record_id}}';
		$this->assertSame(
			array(
				'invalid_var /steps/0/branches/0/steps/0/branches/0/steps/0/otherwise/0/values/notes',
				'invalid_var /steps/1/text',
				'invalid_var /steps/1/text',
			),
			$this->branching_codes( $sibling )
		);
		$messages = array_map( fn( $e ) => $e->message, Validator::validate( 'automation', $sibling, 'f' ) );
		$this->assertStringContainsString( 'step "make_task" runs only in another branch', $messages[1] );
		$this->assertStringContainsString( 'no step "later" runs before this point', $messages[2] );

		// Ids are unique across branches and otherwise; empty branch lists are fine, an empty automation is not.
		$ids = $deep;
		$ids['steps'][0]['branches'][0]['steps'][0]['branches'][0]['steps'][0]['otherwise'][0]['id'] = 'deep_done';
		$this->assertSame( array( 'duplicate_key /steps/1/id' ), $this->branching_codes( $ids ) );

		$empty                                     = $deep;
		$empty['steps'][0]['branches'][0]['steps'] = array();
		$empty['steps'][0]['otherwise']            = array();
		$this->assertSame( array(), $this->branching_codes( $empty ) );
		$empty['steps'] = array();
		$this->assertSame( array( 'invalid_value' ), $this->codes( 'automation', $empty ) );

		// Branch shape: label, when and steps are all required; when uses the condition grammar.
		$shape = $this->branching( 'br_paths' );
		unset( $shape['steps'][0]['branches'][1]['steps'] );
		$shape['steps'][0]['branches'][0]['when']  = array( 'var' => 'record.priority' );
		$shape['steps'][0]['branches'][0]['extra'] = true;
		$this->assertSame(
			array( 'missing_property /steps/0/branches/0/when', 'unknown_property /steps/0/branches/0/extra', 'missing_property /steps/0/branches/1' ),
			$this->branching_codes( $shape )
		);
	}

	/**
	 * Form events bind the record a form with a target wrote: form.confirmed, or form.submitted without
	 * confirm. Record paths then check against the target type; other form triggers bind nothing.
	 */
	public function test_form_binding() {
		$signup = $this->branching( 'br_signup' );
		$this->assertSame( array(), $this->branching_codes( $signup ) );
		$this->assertSame( array(), $this->branching_codes( $this->branching( 'br_confirmed' ) ) );

		// Record keys check against the form's target type.
		$typo                   = $signup;
		$typo['steps'][1]['to'] = '{{record.coordinater}}';
		$this->assertSame( array( 'unknown_field /steps/1/to' ), $this->branching_codes( $typo ) );

		// The same steps on a confirm form's form.submitted, or on an entry-only form, bind nothing.
		$held                    = $signup;
		$held['trigger']['form'] = 'help_confirm';
		$this->assertContains( 'automation_no_record /steps/0/branches/0/when/var', $this->branching_codes( $held ) );
		$this->assertContains( 'automation_no_record /steps/0/branches/0/steps/0', $this->branching_codes( $held ) );
		$held['trigger']['event'] = 'form.confirmed';
		$this->assertSame( array( 'unknown_field /steps/1/text' ), $this->branching_codes( $held ), 'Bound on confirmation; help_confirm has no priority item.' );

		$note                    = $signup;
		$note['trigger']['form'] = 'help_note';
		$this->assertContains( 'automation_no_record /steps/1/to', $this->branching_codes( $note ) );

		// Form contents unknown (only the key is known): record keys are not checked.
		$this->assertSame( array(), $this->branching_codes( $typo, array( 'help_signup' => true ) ) );
	}

	/**
	 * Every forms, automations and options pages object in the sections fixture passes both checks.
	 */
	public function test_sections_fixture_objects() {
		$files = glob( dirname( __DIR__, 2 ) . '/fixtures/valid/sections/{forms,automations,options-pages}/*.json', GLOB_BRACE );
		$kinds = array_flip( Loader::DIRS );
		$this->assertCount( 8, $files );
		foreach ( $files as $file ) {
			$kind = $kinds[ basename( dirname( $file ) ) ];
			$this->assertSame( array(), $this->all_codes( $kind, json_decode( file_get_contents( $file ), true ) ), $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
		}
	}
}
