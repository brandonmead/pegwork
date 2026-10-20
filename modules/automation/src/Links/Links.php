<?php
/**
 * Signed links: minting, verification and link runs.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Links;

use DateTimeImmutable;
use Pegwork\Api\Records;
use PegworkAutomation\Context;
use PegworkAutomation\Duration;
use PegworkAutomation\Engine;
use PegworkAutomation\Overrides;
use PegworkAutomation\Triggers;

/**
 * A link (`links.<name>` of an automation) is a URL `home_url( '/?pegwork_link=<token>' )` that runs only
 * that link's steps on the one record its token names, as a run with `trigger_type` `link`.
 *
 * Tokens are stateless (see Token): verification needs no table. Each token acts once: its link run carries
 * the idempotency key `link:<sha256 of the token>`, so a second click (or a prefetch after the click) finds
 * that run and shows "already done" instead of repeating the steps.
 */
final class Links {

	/**
	 * Query (and form) variable carrying the token.
	 */
	public const QUERY_VAR = 'pegwork_link';

	/**
	 * Engine.
	 *
	 * @var Engine
	 */
	private Engine $engine;

	/**
	 * Uses the engine's clock, runs and executor.
	 *
	 * @param Engine $engine Engine.
	 */
	public function __construct( Engine $engine ) {
		$this->engine = $engine;
	}

	/**
	 * A signed URL for a link of an automation on a record, expiring the link's `expires` after `$now`.
	 *
	 * @param string            $automation Automation key.
	 * @param string            $link       Link name.
	 * @param int               $record     Record ID.
	 * @param DateTimeImmutable $now        Mint time (engine clock).
	 * @return string|null Null when the automation or link is not defined.
	 */
	public static function url( string $automation, string $link, int $record, DateTimeImmutable $now ): ?string {
		$definition = pegwork_registry()->automation( $automation );
		$expires    = $definition['links'][ $link ]['expires'] ?? null;
		$until      = is_string( $expires ) ? Duration::add( $now, $expires ) : null;
		if ( null === $until || $record <= 0 ) {
			return null;
		}
		$token = Token::mint( Key::get(), $automation, $link, $record, $until->getTimestamp() );
		return add_query_arg( self::QUERY_VAR, $token, home_url( '/' ) );
	}

	/**
	 * Checks a token. States:
	 * - `invalid`: malformed, tampered or signed with another key (nothing in it is trusted);
	 * - `expired`: genuine but past its expiry (`expired_url` is the link's, when set);
	 * - `unavailable`: genuine, but the automation, the link or the record is gone, the automation is
	 *   disabled, or the record is not of the automation's type or is in the trash;
	 * - `done`: this token already started a run that did not fail;
	 * - `failed`: this token already started a run that failed or was cancelled;
	 * - `ready`: the link may run.
	 *
	 * @param string $token Token.
	 * @return array{state: string, key?: string, name?: string, automation?: array<string, mixed>, link?: array<string, mixed>, record?: array<string, mixed>, run?: array<string, mixed>}
	 */
	public function check( string $token ): array {
		$key  = Key::existing();
		$data = null === $key ? null : Token::read( $key, $token );
		if ( null === $data ) {
			return array( 'state' => 'invalid' );
		}

		$definition = pegwork_registry()->automation( $data['automation'] );
		$link       = $definition['links'][ $data['link'] ] ?? null;
		$found      = array(
			'key'  => $data['automation'],
			'name' => $data['link'],
		);
		if ( null !== $definition && is_array( $link ) ) {
			$found += array(
				'automation' => $definition,
				'link'       => $link,
			);
		}

		if ( $data['expires'] < $this->engine->clock()->now()->getTimestamp() ) {
			return array( 'state' => 'expired' ) + $found;
		}
		if ( ! isset( $found['link'] ) || ! Overrides::enabled( $definition ) ) {
			return array( 'state' => 'unavailable' ) + $found;
		}

		$record = Records::get( $data['record'] );
		$type   = self::bound_type( $definition );
		if ( null === $record || 'trash' === $record['status'] || ( null !== $type && $type !== $record['type'] ) ) {
			return array( 'state' => 'unavailable' ) + $found;
		}
		$found['record'] = $record;

		$run = $this->engine->runs()->find_key( $data['automation'], self::key( $token ) );
		if ( null !== $run ) {
			$state = in_array( $run['status'], array( 'failed', 'cancelled' ), true ) ? 'failed' : 'done';
			return array(
				'state' => $state,
				'run'   => $run,
			) + $found;
		}
		return array( 'state' => 'ready' ) + $found;
	}

	/**
	 * Runs a link's steps on its record, in this request, as a logged run (`trigger_type` `link`, depth 0).
	 *
	 * @param string               $token Token (already checked).
	 * @param array<string, mixed> $check A `ready` result of check().
	 * @return array{state: string, run: int} State `completed` (the steps ran, or a condition stopped them),
	 *         `failed`, or `done` (another request with the same token got there first, or the run continues
	 *         later after a retry).
	 */
	public function run( string $token, array $check ): array {
		$definition = $check['automation'];
		$record     = $check['record'];
		$payload    = array(
			'id'     => (int) $record['id'],
			'type'   => (string) $record['type'],
			'link'   => (string) $check['name'],
			'origin' => array(
				'source' => 'link',
				'depth'  => 0,
			),
		);

		$id = $this->engine->runs()->insert(
			array(
				'automation'      => (string) $check['key'],
				'trigger_type'    => 'link',
				'record_id'       => (int) $record['id'],
				'status'          => 'queued',
				'depth'           => 0,
				'idempotency_key' => self::key( $token ),
				'definition_hash' => Triggers::hash( $definition ),
				'context'         => array(
					'link'    => (string) $check['name'],
					'trigger' => $payload,
				),
			)
		);
		if ( 0 === $id ) {
			return array(
				'state' => 'done',
				'run'   => 0,
			);
		}

		$status = $this->engine->executor()->execute( $id );
		$state  = match ( $status ) {
			'completed'           => 'completed',
			'failed', 'cancelled' => 'failed',
			default               => 'done',
		};
		return array(
			'state' => $state,
			'run'   => $id,
		);
	}

	/**
	 * A context for rendering a link's page texts (`button`, `success_message`, URLs) on its record. Pages
	 * render in `log` mode, so a private field (rejected at load time anyway) shows as `[private]` and a
	 * `{{link.*}}` as `[link]`.
	 *
	 * @param array<string, mixed> $check A check() result with a record.
	 * @param int                  $run   Run ID, 0 before the click.
	 */
	public function context( array $check, int $run = 0 ): Context {
		$record = isset( $check['record'] ) ? Records::get( (int) $check['record']['id'] ) : null;
		return new Context( $this->engine->clock(), $record, array(), array(), $run );
	}

	/**
	 * Content type an automation's runs are bound to: the `record.*` event's `content_type` or the sweep's.
	 *
	 * @param array<string, mixed> $definition Canonical automation.
	 */
	public static function bound_type( array $definition ): ?string {
		$trigger = $definition['trigger'];
		$type    = $trigger['content_type'] ?? ( $trigger['for_each']['content_type'] ?? null );
		return is_string( $type ) ? $type : null;
	}

	/**
	 * Idempotency key of a token's link run.
	 *
	 * @param string $token Token.
	 */
	private static function key( string $token ): string {
		return 'link:' . Token::digest( $token );
	}
}
