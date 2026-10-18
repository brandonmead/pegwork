<?php
/**
 * Unit tests: pegwork/field output formats and escaping.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Unit\Listings;

use PegworkListings\Formatter;
use WP_UnitTestCase;

/**
 * Formatter::format() by field type and attribute.
 */
class FormatterTest extends WP_UnitTestCase {

	/**
	 * Loads the listings module classes.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 3 ) . '/modules/listings/module.php';
	}

	/**
	 * A field definition.
	 *
	 * @param string $type  Field type.
	 * @param array  $extra Extra properties.
	 */
	private function field( $type, $extra = array() ) {
		return array_merge(
			array(
				'key'        => 'f',
				'type'       => $type,
				'label'      => 'Needs volunteers',
				'visibility' => 'public',
			),
			$extra
		);
	}

	/**
	 * Text, textarea and number are HTML-escaped; markup renders as text.
	 */
	public function test_text_is_escaped() {
		$this->assertSame( '&lt;script&gt;alert(1)&lt;/script&gt;', Formatter::format( $this->field( 'text' ), '<script>alert(1)</script>', array(), 'content' ) );
		$this->assertSame( 'Tom &amp; Jerry', Formatter::format( $this->field( 'textarea' ), 'Tom & Jerry', array(), 'content' ) );
		$this->assertSame( '42', Formatter::format( $this->field( 'number' ), 42, array(), 'content' ) );
		$this->assertSame( '0', Formatter::format( $this->field( 'number' ), 0, array(), 'content' ) );
		$this->assertNull( Formatter::format( $this->field( 'text' ), '', array(), 'content' ) );
		$this->assertNull( Formatter::format( $this->field( 'text' ), '   ', array(), 'content' ) );
		$this->assertNull( Formatter::format( $this->field( 'number' ), null, array(), 'content' ) );
	}

	/**
	 * Prefix is escaped and only added to non-empty values.
	 */
	public function test_prefix() {
		$this->assertSame( 'For: people', Formatter::format( $this->field( 'text' ), 'people', array( 'prefix' => 'For: ' ), 'content' ) );
		$this->assertSame( '&lt;b&gt;x', Formatter::format( $this->field( 'text' ), 'x', array( 'prefix' => '<b>' ), 'content' ) );
		$this->assertNull( Formatter::format( $this->field( 'text' ), '', array( 'prefix' => 'For: ' ), 'content' ) );
	}

	/**
	 * Dates: wp_date() with the format arg or the site format; prefix; invalid dates are empty.
	 */
	public function test_dates() {
		update_option( 'date_format', 'F j, Y' );
		$date = $this->field( 'date' );

		$this->assertSame( 'October 5, 2026', Formatter::format( $date, '2026-10-05', array(), 'content' ) );
		$card = array(
			'format' => 'M Y',
			'prefix' => 'Verified ',
		);
		$this->assertSame( 'Verified Oct 2026', Formatter::format( $date, '2026-10-05', $card, 'content' ) );
		$this->assertNull( Formatter::format( $date, '2026-02-30', array(), 'content' ) );
		$this->assertNull( Formatter::format( $date, 'yesterday', array(), 'content' ) );
		$this->assertNull( Formatter::format( $date, '', array( 'prefix' => 'Verified ' ), 'content' ) );
	}

	/**
	 * The calendar date never shifts with the site timezone.
	 */
	public function test_dates_ignore_timezone_offset() {
		foreach ( array( 'Pacific/Honolulu', 'Asia/Tokyo', 'UTC' ) as $zone ) {
			update_option( 'timezone_string', $zone );
			$this->assertSame( '2026-10-01', Formatter::format( $this->field( 'date' ), '2026-10-01', array( 'format' => 'Y-m-d' ), 'content' ), $zone );
		}
		update_option( 'timezone_string', '' );
	}

	/**
	 * Booleans: the label arg or field label when true; empty when false.
	 */
	public function test_booleans() {
		$field = $this->field( 'boolean' );
		$this->assertSame( 'Needs volunteers', Formatter::format( $field, true, array(), 'content' ) );
		$this->assertSame( 'Volunteers wanted', Formatter::format( $field, true, array( 'label' => 'Volunteers wanted' ), 'content' ) );
		$this->assertNull( Formatter::format( $field, false, array( 'label' => 'Volunteers wanted' ), 'content' ) );
	}

	/**
	 * Select fields show the option label, escaped; unknown values are empty.
	 */
	public function test_select_labels() {
		$field = $this->field(
			'select',
			array(
				'options' => array(
					array(
						'value' => 'in_person',
						'label' => 'In person & online',
					),
				),
			)
		);
		$this->assertSame( 'In person &amp; online', Formatter::format( $field, 'in_person', array(), 'content' ) );
		$this->assertNull( Formatter::format( $field, 'other', array(), 'content' ) );
	}

	/**
	 * Rich text goes through wp_kses_post().
	 */
	public function test_rich_text_is_kses_filtered() {
		$this->assertSame( '<p>Hi <strong>there</strong>alert(1)</p>', Formatter::format( $this->field( 'rich_text' ), '<p>Hi <strong>there</strong><script>alert(1)</script></p>', array(), 'content' ) );
	}

	/**
	 * URL attribute: http, https and mailto only; javascript: and data: are refused; email becomes mailto;
	 * other field types are refused; no prefix.
	 */
	public function test_urls() {
		$url = $this->field( 'url' );
		$this->assertSame( 'https://example.org/a?b=1&#038;c=2', Formatter::format( $url, 'https://example.org/a?b=1&c=2', array( 'prefix' => 'x' ), 'url' ) );
		$this->assertSame( 'http://example.org', Formatter::format( $url, 'http://example.org', array(), 'url' ) );
		$this->assertSame( 'mailto:a@example.org', Formatter::format( $url, 'mailto:a@example.org', array(), 'url' ) );
		$this->assertNull( Formatter::format( $url, 'javascript:alert(1)', array(), 'url' ) );
		$this->assertNull( Formatter::format( $url, 'JavaScript:alert(1)', array(), 'url' ) );
		$this->assertNull( Formatter::format( $url, 'data:text/html,<script>alert(1)</script>', array(), 'url' ) );
		$this->assertNull( Formatter::format( $url, 'ftp://example.org', array(), 'url' ) );
		$this->assertNull( Formatter::format( $url, '', array(), 'url' ) );

		$this->assertSame( 'mailto:hi@example.org', Formatter::format( $this->field( 'email' ), 'hi@example.org', array(), 'url' ) );
		$this->assertNull( Formatter::format( $this->field( 'text' ), 'https://example.org', array(), 'url' ) );
		$this->assertNull( Formatter::format( $this->field( 'boolean' ), true, array(), 'url' ) );
	}
}
