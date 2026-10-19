<?php
/**
 * Unit tests: signed link tokens.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Unit\Automation;

use PegworkAutomation\Links\Token;
use WP_UnitTestCase;

/**
 * Mint and read round trip; every kind of tampering, a foreign key and malformed input read as null.
 */
class TokenTest extends WP_UnitTestCase {

	/**
	 * HMAC key for the tests.
	 */
	private const KEY = 'unit-test-key-0123456789abcdef0123456789';

	/**
	 * Loads the module classes.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 3 ) . '/modules/automation/module.php';
	}

	/**
	 * A minted token reads back to its automation, link, record and expiry, and is URL safe.
	 */
	public function test_round_trip() {
		$token = Token::mint( self::KEY, 'sifg_staleness_check', 'still_active', 42, 1893456000 );

		$this->assertMatchesRegularExpression( '/^v1\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]{43}$/', $token );
		$this->assertSame(
			array(
				'automation' => 'sifg_staleness_check',
				'link'       => 'still_active',
				'record'     => 42,
				'expires'    => 1893456000,
			),
			Token::read( self::KEY, $token )
		);
		$this->assertSame( $token, Token::mint( self::KEY, 'sifg_staleness_check', 'still_active', 42, 1893456000 ), 'Deterministic.' );
		$this->assertSame( 64, strlen( Token::digest( $token ) ) );
	}

	/**
	 * A changed payload, signature or version, another key and malformed tokens are all rejected.
	 */
	public function test_tampering_is_rejected() {
		$token                             = Token::mint( self::KEY, 'auto', 'go', 7, 1893456000 );
		[ $version, $payload, $signature ] = explode( '.', $token );
		// A forged payload from values for a, l, r and e (in that order; fewer leaves keys out).
		$encode = function ( array $values ) {
			$data = array_combine( array_slice( array( 'a', 'l', 'r', 'e' ), 0, count( $values ) ), $values );
			return rtrim( strtr( base64_encode( (string) wp_json_encode( $data ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Forging payloads.
		};

		$bad = array(
			'other record'     => "$version." . $encode( array( 'auto', 'go', 8, 1893456000 ) ) . ".$signature",
			'later expiry'     => "$version." . $encode( array( 'auto', 'go', 7, 1993456000 ) ) . ".$signature",
			'flipped sig'      => "$version.$payload." . strrev( $signature ),
			'short sig'        => "$version.$payload." . substr( $signature, 0, -1 ),
			'no sig'           => "$version.$payload.",
			'other version'    => "v2.$payload.$signature",
			'extra part'       => "$token.x",
			'two parts'        => "$version.$payload",
			'empty'            => '',
			'garbage'          => 'not-a-token',
			'too long'         => $token . str_repeat( 'a', Token::MAX_LENGTH ),
			'padding chars'    => "$version.$payload==.$signature",
			'whitespace'       => " $token",
			'foreign key'      => Token::mint( 'another-key-0123456789abcdef0123456789', 'auto', 'go', 7, 1893456000 ),
			'record zero'      => Token::mint( self::KEY, 'auto', 'go', 0, 1893456000 ),
			'bad link name'    => Token::mint( self::KEY, 'auto', 'Go!', 7, 1893456000 ),
			'bad automation'   => Token::mint( self::KEY, '../x', 'go', 7, 1893456000 ),
			'missing expiry'   => "$version." . $encode( array( 'auto', 'go', 7 ) ) . ".$signature",
			'string record id' => "$version." . $encode( array( 'auto', 'go', '7', 1893456000 ) ) . ".$signature",
		);
		foreach ( $bad as $label => $candidate ) {
			$this->assertNull( Token::read( self::KEY, $candidate ), $label );
		}
		$this->assertNull( Token::read( '', $token ), 'An empty key verifies nothing.' );
		$this->assertNotNull( Token::read( self::KEY, $token ) );
	}
}
