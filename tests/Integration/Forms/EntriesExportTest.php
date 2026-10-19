<?php
/**
 * Integration tests: CSV export of entries.
 *
 * @package Pegwork
 */

// phpcs:disable WordPress.Security.NonceVerification -- The tests set request globals for the handler.

namespace Pegwork\Tests\Integration\Forms;

use Pegwork\Tests\Support\EntriesTestCase;
use PegworkForms\Export;

require_once dirname( __DIR__, 2 ) . '/Support/EntriesTestCase.php';

/**
 * BOM, columns from the definition plus extra keys, display text, the current view, formula escaping.
 */
class EntriesExportTest extends EntriesTestCase {

	/**
	 * Writes the CSV for a form and view to memory.
	 *
	 * @param string $form   Form key.
	 * @param string $status View.
	 * @param string $search Email search.
	 * @return string CSV.
	 */
	private function csv( $form = 'fr_contact', $status = 'all', $search = '' ) {
		$out = fopen( 'php://memory', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Test stream.
		Export::write( $out, $form, $status, $search );
		rewind( $out );
		$csv = stream_get_contents( $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Test stream.
		return $csv;
	}

	/**
	 * Parses CSV text (after the BOM) into rows.
	 *
	 * @param string $csv CSV.
	 * @return array<int, string[]>
	 */
	private function rows( $csv ) {
		$lines = array();
		$out   = fopen( 'php://memory', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Test stream.
		fwrite( $out, substr( $csv, 3 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Test stream.
		rewind( $out );
		while ( false !== ( $row = fgetcsv( $out, null, ',', '"', '' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Read loop.
			$lines[] = $row;
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Test stream.
		return $lines;
	}

	/**
	 * Cells starting with a formula trigger get a leading quote; others are unchanged.
	 */
	public function test_cell_escapes_formula_triggers() {
		foreach ( array( '=SUM(A1:A2)', '+1', '-2', '@cmd', "\tx", "\rx", '=HYPERLINK("http://x","y")' ) as $value ) {
			$this->assertSame( "'" . $value, Export::cell( $value ), wp_json_encode( $value ) );
		}
		foreach ( array( '', 'Ada', '1+1', ' =x', 'a@b.org', "x\n=y" ) as $value ) {
			$this->assertSame( $value, Export::cell( $value ), wp_json_encode( $value ) );
		}
	}

	/**
	 * Header and rows: BOM, fixed columns, labels in form order, extra keys, display text, escaped cells, oldest first.
	 */
	public function test_csv_columns_values_and_escaping() {
		$first  = $this->entry(
			array(
				'created_at' => '2026-01-01 09:00:00',
				'values'     => array(
					'name'    => '=HYPERLINK("http://evil.example","click")',
					'email'   => 'ada@example.org',
					'topic'   => 'talks',
					'message' => "@SUM(1)\nsecond line",
					'consent' => true,
				),
			)
		);
		$second = $this->entry(
			array(
				'created_at' => '2026-01-02 09:00:00',
				'email'      => 'grace@example.org',
				'values'     => array(
					'name'   => '-Grace',
					'legacy' => '+1 555',
				),
			)
		);

		$csv = $this->csv();
		$this->assertStringStartsWith( "\xEF\xBB\xBF", $csv );

		$rows = $this->rows( $csv );
		$this->assertSame(
			array( 'Entry ID', 'Submitted (UTC)', 'Status', 'Email', 'Post ID', 'Name', 'Email', 'Topic', 'Website', 'Send me the newsletter', 'How often', 'Message', 'I agree to be contacted', 'legacy' ),
			$rows[0]
		);
		$this->assertCount( 3, $rows );
		$this->assertSame( (string) $first, $rows[1][0] );
		$this->assertSame( '2026-01-01 09:00:00', $rows[1][1] );
		$this->assertSame( 'received', $rows[1][2] );
		$this->assertSame( '\'=HYPERLINK("http://evil.example","click")', $rows[1][5] );
		$this->assertSame( 'Talks', $rows[1][7] );
		$this->assertSame( "'@SUM(1)\nsecond line", $rows[1][11] );
		$this->assertSame( 'Yes', $rows[1][12] );
		$this->assertSame( '', $rows[1][13] );
		$this->assertSame( (string) $second, $rows[2][0] );
		$this->assertSame( "'-Grace", $rows[2][5] );
		$this->assertSame( "'+1 555", $rows[2][13] );
	}

	/**
	 * Only the chosen form and view are exported: All leaves out spam; search narrows by email.
	 */
	public function test_csv_follows_the_current_view() {
		$this->entry( array( 'email' => 'kept@example.org' ) );
		$this->entry(
			array(
				'email'  => 'spam@example.org',
				'status' => 'spam',
			)
		);
		$this->entry(
			array(
				'email'    => 'other@example.org',
				'form_key' => 'fe_story',
			)
		);

		$all = $this->csv();
		$this->assertStringContainsString( 'kept@example.org', $all );
		$this->assertStringNotContainsString( 'spam@example.org', $all );
		$this->assertStringNotContainsString( 'other@example.org', $all );

		$spam = $this->csv( 'fr_contact', 'spam' );
		$this->assertStringContainsString( 'spam@example.org', $spam );
		$this->assertStringNotContainsString( 'kept@example.org', $spam );

		$this->assertStringNotContainsString( 'kept@example.org', $this->csv( 'fr_contact', 'all', 'nobody' ) );
		$this->assertCount( 1, $this->rows( $this->csv( 'fr_contact', 'all', 'nobody' ) ), 'Header only.' );
	}

	/**
	 * The download URL carries the nonce, form and view; without a form the handler refuses with 400.
	 */
	public function test_url_and_missing_form() {
		$url = Export::url( 'fr_contact', 'spam', 'ada' );
		$this->assertStringContainsString( 'action=' . Export::ACTION, $url );
		$this->assertStringContainsString( 'form=fr_contact', $url );
		$this->assertStringContainsString( 'status=spam', $url );
		$this->assertStringContainsString( 's=ada', $url );
		$this->assertStringContainsString( '_wpnonce=', $url );

		$_GET     = array( '_wpnonce' => wp_create_nonce( Export::ACTION ) );
		$_REQUEST = $_GET;
		$this->assertSame( 400, $this->die_code( array( Export::class, 'handle' ) ) );
	}
}
