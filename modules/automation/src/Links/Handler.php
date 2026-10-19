<?php
/**
 * Public signed link handler.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Links;

use PegworkAutomation\Context;
use PegworkAutomation\Engine;
use PegworkAutomation\Templates;

/**
 * Answers `/?pegwork_link=<token>` (GET) and the confirm form (POST to `/` with `pegwork_link` and a nonce)
 * on `template_redirect`. A query variable rather than a pretty path: no rewrite rule to add and flush when
 * the module is switched on or off, and it works on every permalink setting (including plain).
 *
 * - GET with `confirm: true` (the default) shows a page with one button that POSTs the token, so mail
 *   scanners that prefetch links cannot act; GET with `confirm: false` acts at once. HEAD never acts.
 * - Acting runs the link's steps on its record as a `link` run (Links::run), then redirects to
 *   `success_url` (303) or shows a thank-you page with `success_message`.
 * - Expired (or `expired_url`), not valid, unavailable, already done and failed links get friendly pages
 *   and change nothing. Too many requests from one address get 429.
 * - Pages are standalone, `noindex`, `no-store`, framed by nobody, sent with no referrer, and show no
 *   private field (page texts render in `log` mode, the record title only when its field is public).
 * - The confirm POST carries a WordPress nonce bound to the token. Nonces work for visitors who are not
 *   logged in (user 0) and live 12 to 24 hours; a stale one shows the confirm page again with a notice.
 */
final class Handler {

	/**
	 * Default requests per address per window (filter `pegwork_automation_link_rate_limit`).
	 */
	public const RATE_LIMIT = 30;

	/**
	 * Rate limit window, in seconds.
	 */
	public const RATE_WINDOW = 600;

	/**
	 * Hooks the handler. Safe to call more than once.
	 */
	public static function register(): void {
		if ( ! has_action( 'template_redirect', array( self::class, 'maybe_handle' ) ) ) {
			add_action( 'template_redirect', array( self::class, 'maybe_handle' ), 0 );
		}
	}

	/**
	 * Handles the request when it carries a token, then exits. Hooked to template_redirect.
	 */
	public static function maybe_handle(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		// phpcs:disable WordPress.Security.NonceVerification -- The token is the authority; the confirm POST's nonce is verified in respond().
		$source = 'POST' === $method ? $_POST : $_GET;
		if ( ! isset( $source[ Links::QUERY_VAR ] ) || ! is_string( $source[ Links::QUERY_VAR ] ) ) {
			return;
		}
		$token = sanitize_text_field( wp_unslash( $source[ Links::QUERY_VAR ] ) );
		$nonce = isset( $_POST['_wpnonce'] ) && is_string( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		self::send( self::respond( $method, $token, $nonce, $ip ) );
		exit;
	}

	/**
	 * The response to a link request, without sending it (tests call this directly).
	 *
	 * @param string $method HTTP method.
	 * @param string $token  Token.
	 * @param string $nonce  Nonce from the confirm form (POST).
	 * @param string $ip     Client address for the rate limit (REMOTE_ADDR; proxies' headers are not trusted).
	 * @return array{status: int, body?: string, location?: string, headers: array<string, string>}
	 */
	public static function respond( string $method, string $token, string $nonce = '', string $ip = '' ): array {
		if ( ! self::allow( $ip ) ) {
			$page                           = Page::response( 429, __( 'Too many requests', 'pegwork' ), __( 'Please wait a few minutes, then try the link again.', 'pegwork' ) );
			$page['headers']['Retry-After'] = (string) self::RATE_WINDOW;
			return $page;
		}
		if ( ! in_array( $method, array( 'GET', 'HEAD', 'POST' ), true ) ) {
			$page                     = Page::response( 405, __( 'This link does not work', 'pegwork' ), __( 'Open the link from the email in your browser.', 'pegwork' ) );
			$page['headers']['Allow'] = 'GET, POST';
			return $page;
		}

		$links = Engine::instance()->links();
		$check = $links->check( $token );

		switch ( $check['state'] ) {
			case 'invalid':
				return Page::response( 403, __( 'This link does not work', 'pegwork' ), __( 'The link may be incomplete or changed. Copy the whole link from the email into your browser, or contact the site team.', 'pegwork' ) );
			case 'expired':
				$to = self::target( (string) ( $check['link']['expired_url'] ?? '' ), $links->context( array() ) );
				if ( null !== $to ) {
					return self::redirect( $to );
				}
				return Page::response( 410, __( 'This link has expired', 'pegwork' ), __( 'For your security, links in our emails work for a limited time, so nothing was changed. Look for a newer email from us, or contact the site team.', 'pegwork' ) );
			case 'unavailable':
				return Page::response( 404, __( 'This link is no longer available', 'pegwork' ), __( 'Nothing was changed. If you need help, contact the site team.', 'pegwork' ) );
			case 'done':
				return self::done();
			case 'failed':
				return self::failed();
		}

		$link    = $check['link'];
		$confirm = (bool) ( $link['confirm'] ?? true );
		$action  = 'pegwork_link_' . Token::digest( $token );
		$posted  = 'POST' === $method;
		$acts    = $posted || ( 'GET' === $method && ! $confirm );
		$stale   = $confirm && $posted && false === wp_verify_nonce( $nonce, $action );

		if ( ! $acts || $stale ) {
			$context = $links->context( $check );
			$button  = trim( Templates::render( (string) ( $link['button'] ?? '' ), $context, Templates::LOG ) );
			$title   = self::title( $check );
			$extra   = array(
				'context' => $title,
				'form'    => array(
					'token'  => $token,
					'nonce'  => wp_create_nonce( $action ),
					'button' => '' !== $button ? $button : __( 'Confirm', 'pegwork' ),
				),
			);
			if ( $stale ) {
				$extra['notice'] = __( 'This page was open for a long time. Press the button again to confirm.', 'pegwork' );
			}
			return Page::response( $stale ? 403 : 200, __( 'Please confirm', 'pegwork' ), __( 'Press the button below to confirm. Nothing changes until you do.', 'pegwork' ), $extra );
		}

		$result = $links->run( $token, $check );
		if ( 'failed' === $result['state'] ) {
			return self::failed( 500 );
		}
		if ( 'done' === $result['state'] ) {
			return self::done();
		}

		$context = $links->context( $check, $result['run'] );
		$to      = self::target( (string) ( $link['success_url'] ?? '' ), $context );
		if ( null !== $to ) {
			return self::redirect( $to );
		}
		$message = trim( Templates::render( (string) ( $link['success_message'] ?? '' ), $context, Templates::LOG ) );
		return Page::response( 200, __( 'Thank you', 'pegwork' ), '' !== $message ? $message : __( 'Your response has been recorded.', 'pegwork' ), array( 'context' => self::title( $check ) ) );
	}

	/**
	 * Sends a response: security and cache headers, then the page or the redirect.
	 *
	 * @param array{status: int, body?: string, location?: string, headers: array<string, string>} $response Response.
	 */
	private static function send( array $response ): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The page cache plugins' shared convention: skip this request.
		}
		nocache_headers();
		$headers = $response['headers'] + array(
			'X-Robots-Tag'            => 'noindex, nofollow',
			'Referrer-Policy'         => 'no-referrer',
			'X-Frame-Options'         => 'DENY',
			'X-Content-Type-Options'  => 'nosniff',
			'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
		);
		foreach ( $headers as $name => $value ) {
			header( $name . ': ' . $value );
		}
		if ( isset( $response['location'] ) ) {
			wp_safe_redirect( $response['location'], $response['status'] );
			return;
		}
		status_header( $response['status'] );
		header( 'Content-Type: text/html; charset=UTF-8' );
		echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by Page, every value escaped there.
	}

	/**
	 * The "already done" page.
	 */
	private static function done(): array {
		return Page::response( 200, __( 'Already done', 'pegwork' ), __( 'This link has already been used, so there is nothing more to do. Thank you.', 'pegwork' ) );
	}

	/**
	 * The "something went wrong" page.
	 *
	 * @param int $status HTTP status.
	 */
	private static function failed( int $status = 200 ): array {
		return Page::response( $status, __( 'Something went wrong', 'pegwork' ), __( 'We could not complete this request. Please contact the site team, who can see what happened.', 'pegwork' ) );
	}

	/**
	 * A 303 redirect.
	 *
	 * @param string $url Target.
	 */
	private static function redirect( string $url ): array {
		return array(
			'status'   => 303,
			'location' => $url,
			'headers'  => array(),
		);
	}

	/**
	 * Where a `success_url` or `expired_url` points: a site path (`/thanks/`) under the home URL, or an
	 * absolute URL allowed by wp_validate_redirect(); null when empty or not allowed (the page is shown).
	 *
	 * @param string  $template URL template.
	 * @param Context $context  Page context.
	 */
	private static function target( string $template, Context $context ): ?string {
		$url = trim( Templates::render( $template, $context, Templates::LOG ) );
		if ( '' === $url ) {
			return null;
		}
		if ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) {
			return home_url( $url );
		}
		$valid = wp_validate_redirect( $url, '' );
		return '' === $valid ? null : $valid;
	}

	/**
	 * The record's title for the page, when its field is public (else '').
	 *
	 * @param array<string, mixed> $check A check() result with a record.
	 */
	private static function title( array $check ): string {
		$record = $check['record'] ?? null;
		if ( ! is_array( $record ) ) {
			return '';
		}
		foreach ( pegwork_registry()->fields( (string) $record['type'] ) as $key => $field ) {
			if ( 'post_title' === ( $field['wp']['storage'] ?? null ) ) {
				if ( 'private' === ( $field['visibility'] ?? 'public' ) ) {
					return '';
				}
				$title = $record['values'][ $key ] ?? '';
				return is_scalar( $title ) ? wp_specialchars_decode( (string) $title, ENT_QUOTES ) : '';
			}
		}
		return '';
	}

	/**
	 * Counts a request against the address's budget; false once it is used up.
	 *
	 * @param string $ip Client address.
	 */
	private static function allow( string $ip ): bool {
		/**
		 * Filters how many link requests one address may make per 10 minutes (0 turns the limit off).
		 * Behind a proxy every visitor may share one address; raise the limit there.
		 *
		 * @param int $limit Default 30.
		 */
		$limit = (int) apply_filters( 'pegwork_automation_link_rate_limit', self::RATE_LIMIT );
		if ( $limit <= 0 ) {
			return true;
		}
		$key   = 'pegwork_link_rate_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $key, $count + 1, self::RATE_WINDOW );
		return true;
	}
}
