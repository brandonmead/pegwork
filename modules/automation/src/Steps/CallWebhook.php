<?php
/**
 * `call_webhook` step.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Steps;

use PegworkAutomation\Context;
use PegworkAutomation\Templates;
use PegworkAutomation\Webhooks\Secrets;
use PegworkAutomation\Webhooks\Signature;
use WP_Error;

/**
 * POSTs a signed JSON delivery with wp_safe_remote_post() (no private IPs or unusual ports, no redirects).
 * It runs as a step of a background run, so the save that triggered it never waits for the receiver.
 *
 * Body: `{ event, automation, run_id, delivered_at, record: { id, type, status, permalink, fields } }`
 * (`record` is null when the run has no record). `fields` holds the step's `fields` keys, or every public
 * field and taxonomy of the type when it has none; private fields are never sent (the registry rejects them
 * in `fields`, and they are skipped here as well). Headers: `X-Pegwork-Delivery: <run id>-<step id>`
 * (stable across retries, for receiver dedupe), `X-Pegwork-Timestamp` (Unix time of the attempt) and
 * `X-Pegwork-Signature: sha256=<HMAC-SHA256 of "<timestamp>.<body>">` with the automation's secret.
 *
 * A 2xx response succeeds. Any other status or a transport error fails the attempt, retryable (default
 * policy: 3 retries after 1 minute, 10 minutes and 1 hour), then the run fails. The summary holds the
 * host, the HTTP status and the delivery ID; never the secret, the signature or a body.
 */
final class CallWebhook implements Step {

	/**
	 * Default timeout, seconds.
	 */
	public const TIMEOUT = 10;

	/**
	 * Runs the step.
	 *
	 * @param array<string, mixed> $step    Step definition.
	 * @param Context              $context Run context.
	 * @param array<string, mixed> $run     Run row.
	 */
	public function run( array $step, Context $context, array $run ): StepResult {
		$delivery = (int) $run['id'] . '-' . (string) $step['id'];
		$url      = trim( Templates::render( (string) ( $step['url'] ?? '' ), $context ) );
		$host     = (string) wp_parse_url( $url, PHP_URL_HOST );
		$summary  = array(
			'host'     => $host,
			'delivery' => $delivery,
		);

		if ( ! preg_match( '#^https?://\S+$#i', $url ) || '' === $host ) {
			return StepResult::fail( 'invalid_url', 'The webhook URL did not render to an http:// or https:// URL.', $summary, false );
		}

		$secret = Secrets::get( (string) $run['automation'] );
		if ( null === $secret ) {
			return StepResult::fail( 'no_secret', 'The webhook secret could not be stored.', $summary );
		}

		$now  = $context->clock()->now();
		$body = (string) wp_json_encode(
			array(
				'event'        => (string) ( $run['context']['event'] ?? $run['trigger_type'] ),
				'automation'   => (string) $run['automation'],
				'run_id'       => (int) $run['id'],
				'delivered_at' => $now->format( 'Y-m-d\TH:i:s\Z' ),
				'record'       => self::record( $step, $context ),
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		$timestamp = $now->getTimestamp();
		$response  = wp_safe_remote_post(
			$url,
			array(
				'timeout'     => (float) min( 15, max( 1, (float) ( $step['timeout'] ?? self::TIMEOUT ) ) ),
				'redirection' => 0,
				'user-agent'  => 'Pegwork/' . ( defined( 'PEGWORK_VERSION' ) ? PEGWORK_VERSION : '0' ) . '; ' . home_url( '/' ),
				'headers'     => array(
					'Content-Type'       => 'application/json; charset=utf-8',
					Signature::DELIVERY  => $delivery,
					Signature::TIMESTAMP => (string) $timestamp,
					Signature::SIGNATURE => Signature::sign( $secret, $timestamp, $body ),
				),
				'body'        => $body,
			)
		);

		if ( $response instanceof WP_Error ) {
			return StepResult::fail( 'webhook_failed', sprintf( 'Could not reach %1$s: %2$s', $host, self::clean( $response->get_error_message(), $secret ) ), $summary );
		}

		$status            = (int) wp_remote_retrieve_response_code( $response );
		$summary['status'] = $status;
		if ( $status < 200 || $status > 299 ) {
			return StepResult::fail( 'webhook_failed', sprintf( '%1$s answered HTTP %2$d.', $host, $status ), $summary );
		}
		return StepResult::ok( array( 'status' => $status ), $summary );
	}

	/**
	 * The `record` part of the body: public values only.
	 *
	 * @param array<string, mixed> $step    Step definition.
	 * @param Context              $context Run context.
	 * @return array<string, mixed>|null
	 */
	public static function record( array $step, Context $context ): ?array {
		$record = $context->record();
		if ( null === $record ) {
			return null;
		}

		$type     = (string) $record['type'];
		$registry = pegwork_registry();
		$public   = array_keys( $registry->fields( $type, 'public' ) );
		$public   = array_merge( $public, array_column( $registry->content_type( $type )['taxonomies'] ?? array(), 'key' ) );
		$keys     = isset( $step['fields'] ) ? array_values( array_intersect( (array) $step['fields'], $public ) ) : $public;

		$fields = array();
		foreach ( $keys as $key ) {
			$fields[ $key ] = $record['values'][ $key ] ?? null;
		}

		return array(
			'id'        => (int) $record['id'],
			'type'      => $type,
			'status'    => (string) $record['status'],
			'permalink' => (string) $record['permalink'],
			'fields'    => (object) $fields,
		);
	}

	/**
	 * A transport error message, one line, without the secret (defence in depth: it is never sent in a URL).
	 *
	 * @param string $message Message.
	 * @param string $secret  Secret.
	 */
	private static function clean( string $message, string $secret ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', str_replace( $secret, '[secret]', $message ) ) );
	}
}
