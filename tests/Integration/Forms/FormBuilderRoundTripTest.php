<?php
/**
 * Integration tests: definition to builder blocks to definition.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Forms;

use Pegwork\Registry\Normalizer;
use PegworkForms\Builder;
use PegworkForms\FormPost;
use WP_UnitTestCase;

require_once dirname( __DIR__, 3 ) . '/modules/forms/module.php';

/**
 * Every valid form fixture survives Builder::generate() then Builder::compile() byte-identical after
 * normalization, and the generated markup is stable.
 */
class FormBuilderRoundTripTest extends WP_UnitTestCase {

	/**
	 * Every valid form definition in the repository: fixtures, the e2e package and examples/sifg.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function valid_forms() {
		$root  = dirname( __DIR__, 3 );
		$files = array_merge(
			glob( $root . '/tests/fixtures/valid/*/forms/*.json' ),
			glob( $root . '/tests/fixtures/forms-records/forms/*.json' ),
			glob( $root . '/tests/e2e/packages/*/forms/*.json' ),
			glob( $root . '/examples/*/forms/*.json' )
		);
		$cases = array();
		foreach ( $files as $file ) {
			$cases[ substr( $file, strlen( $root ) + 1 ) ] = array( $file );
		}
		return $cases;
	}

	/**
	 * The fixture list is not empty (a moved directory would silently test nothing).
	 */
	public function test_fixtures_found() {
		$this->assertGreaterThanOrEqual( 12, count( self::valid_forms() ) );
	}

	/**
	 * Definition to blocks to definition is lossless.
	 *
	 * @dataProvider valid_forms
	 *
	 * @param string $file Fixture path.
	 */
	public function test_round_trip( $file ) {
		$canonical = Normalizer::normalize( 'form', json_decode( file_get_contents( $file ), true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
		$generated = Builder::generate( $canonical );

		$compiled = Builder::compile( $generated['title'], $generated['content'], FormPost::settings_json( $generated['settings'] ) );
		$this->assertSame( array(), $compiled['errors'] );

		$this->assertSame(
			wp_json_encode( $canonical, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			wp_json_encode( Normalizer::normalize( 'form', $compiled['definition'] ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);

		// The markup itself is stable: generating from the compiled definition gives the same blocks.
		$this->assertSame( $generated['content'], Builder::generate( Normalizer::normalize( 'form', $compiled['definition'] ) )['content'] );
	}

	/**
	 * A form as written (not normalized) also round-trips to its own normalization.
	 */
	public function test_written_form_round_trips() {
		$form     = json_decode( file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/valid/sections/forms/submit_talk.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
		$out      = Builder::generate( $form );
		$compiled = Builder::compile( $out['title'], $out['content'], $out['settings'] );

		$this->assertSame( Normalizer::normalize( 'form', $form ), Normalizer::normalize( 'form', $compiled['definition'] ) );
	}

	/**
	 * Markup shape: self-closing field blocks, steps wrapping them, attributes in block.json order.
	 */
	public function test_markup_shape() {
		$out = Builder::generate(
			array(
				'format' => 1,
				'key'    => 'tiny',
				'label'  => 'Tiny',
				'steps'  => array(
					array(
						'key'    => 'one',
						'label'  => 'One',
						'fields' => array(
							array(
								'label'    => 'Name',
								'type'     => 'text',
								'key'      => 'name',
								'required' => true,
							),
							array( 'bind' => array( 'field' => 'website' ) ),
						),
					),
				),
			)
		);

		$this->assertSame(
			"<!-- wp:pegwork/form-step {\"key\":\"one\",\"label\":\"One\"} -->\n"
			. "<!-- wp:pegwork/form-field {\"key\":\"name\",\"type\":\"text\",\"label\":\"Name\",\"required\":true} /-->\n\n"
			. "<!-- wp:pegwork/form-field {\"bind\":{\"field\":\"website\"}} /-->\n"
			. '<!-- /wp:pegwork/form-step -->',
			$out['content']
		);
		$this->assertSame( 'Tiny', $out['title'] );
		$this->assertSame(
			array(
				'key' => 'tiny',
			),
			$out['settings']
		);
	}

	/**
	 * Compile fills keys from labels, takes the key from the title, and keeps a locked key.
	 */
	public function test_compile_fills_keys() {
		$content = '<!-- wp:pegwork/form-field {"type":"email","label":"Your email","lock":{"move":true}} /-->';

		$compiled = Builder::compile( 'Get in touch!', $content, '' );
		$this->assertSame( array(), $compiled['errors'] );
		$this->assertSame( 'get_in_touch', $compiled['definition']['key'] );
		$this->assertSame(
			array(
				array(
					'key'   => 'your_email',
					'type'  => 'email',
					'label' => 'Your email',
				),
			),
			$compiled['definition']['fields']
		);

		$this->assertSame( 'contact', Builder::compile( 'Other title', $content, '{"key":"ignored"}', 'contact' )['definition']['key'] );
		$this->assertSame( 'chosen', Builder::compile( 'Other title', $content, '{"key":"chosen"}' )['definition']['key'] );
		$this->assertSame( 'f_2026_survey', Builder::slug( '2026 Survey' ) );
		$this->assertSame( 'cafe_creme', Builder::slug( 'Café crème' ) );
	}

	/**
	 * Compile reports what the editor must fix, by pointer.
	 */
	public function test_compile_errors() {
		$this->assertArrayHasKey( '/fields', Builder::compile( 'Empty', '', '' )['errors'] );
		$this->assertArrayHasKey( '/label', Builder::compile( '', '<!-- wp:pegwork/form-field {"type":"text","label":"A"} /-->', '' )['errors'] );

		$mixed = '<!-- wp:pegwork/form-step {"label":"One"} --><!-- /wp:pegwork/form-step -->'
			. '<!-- wp:pegwork/form-field {"type":"text","label":"A"} /-->';
		$this->assertArrayHasKey( '/steps', Builder::compile( 'Mixed', $mixed, '' )['errors'] );

		$other = '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->';
		$this->assertArrayHasKey( '/', Builder::compile( 'Other', $other, '' )['errors'] );

		$unlabeled = '<!-- wp:pegwork/form-step {"label":"One"} -->'
			. '<!-- wp:pegwork/form-field {"type":"text"} /-->'
			. '<!-- /wp:pegwork/form-step -->';
		$compiled  = Builder::compile( 'Unlabeled', $unlabeled, '' );
		$this->assertArrayHasKey( '/steps/0/fields/0/label', $compiled['errors'] );
		$this->assertSame( 'one', $compiled['definition']['steps'][0]['key'] );

		$this->assertArrayHasKey( '/', Builder::compile( 'Bad settings', $other, '[1,2]' )['errors'] );
	}
}
