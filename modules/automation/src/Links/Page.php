<?php
/**
 * Link pages.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Links;

/**
 * Standalone HTML for the link pages (confirm, thank-you, already done, expired, not valid, unavailable,
 * failed, too many requests). No theme, scripts or external resources: the page works with JavaScript off
 * and leaks nothing (the token is in the URL) to third parties. Every value is escaped here; callers pass
 * plain text.
 */
final class Page {

	/**
	 * A response with a page.
	 *
	 * @param int                  $status  HTTP status.
	 * @param string               $heading Heading (plain text).
	 * @param string               $message Message (plain text).
	 * @param array<string, mixed> $extra   Optional `form` (`token`, `nonce`, `button`), `notice` (plain text),
	 *                                      `context` (plain text shown above the heading).
	 * @return array{status: int, body: string, headers: array<string, string>}
	 */
	public static function response( int $status, string $heading, string $message, array $extra = array() ): array {
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$main = '';

		if ( isset( $extra['notice'] ) ) {
			$main .= '<p class="notice" role="alert">' . esc_html( (string) $extra['notice'] ) . '</p>';
		}
		if ( isset( $extra['context'] ) && '' !== $extra['context'] ) {
			$main .= '<p class="context">' . esc_html( (string) $extra['context'] ) . '</p>';
		}
		$main .= '<h1>' . esc_html( $heading ) . '</h1>';
		$main .= '<p>' . esc_html( $message ) . '</p>';

		if ( isset( $extra['form'] ) ) {
			$form  = $extra['form'];
			$main .= '<form method="post" action="' . esc_url( home_url( '/' ) ) . '">'
				. '<input type="hidden" name="' . esc_attr( Links::QUERY_VAR ) . '" value="' . esc_attr( (string) $form['token'] ) . '">'
				. ( '' !== (string) $form['nonce'] ? '<input type="hidden" name="_wpnonce" value="' . esc_attr( (string) $form['nonce'] ) . '">' : '' )
				. '<button type="submit">' . esc_html( (string) $form['button'] ) . '</button>'
				. '</form>';
		} else {
			/* translators: %s: site name. */
			$main .= '<p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( sprintf( __( 'Go to %s', 'pegwork' ), $site ) ) . '</a></p>';
		}

		$language = (string) get_bloginfo( 'language' );
		$title    = $heading . ' | ' . $site;
		$body     = '<!DOCTYPE html>' . "\n"
			. '<html lang="' . esc_attr( '' !== $language ? $language : 'en' ) . '"' . ( is_rtl() ? ' dir="rtl"' : '' ) . '>'
			. '<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="robots" content="noindex, nofollow"><title>' . esc_html( $title ) . '</title>'
			. '<style>' . self::CSS . '</style></head>'
			. '<body><header><p class="site">' . esc_html( $site ) . '</p></header>'
			. '<main>' . $main . '</main></body></html>';

		return array(
			'status'  => $status,
			'body'    => $body,
			'headers' => array(),
		);
	}

	/**
	 * Page styles: system fonts, readable width, AA contrast, a visible focus ring, large touch target.
	 */
	private const CSS = 'body{margin:0;font:1.0625rem/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#1e1e1e;background:#f6f7f7}'
		. 'header,main{max-width:36rem;margin:0 auto;padding:0 1rem}header{padding-top:2rem}'
		. '.site{margin:0;font-weight:600;color:#3c434a}main{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:1.5rem;margin-top:1rem}'
		. 'h1{font-size:1.5rem;line-height:1.25;margin:0 0 .75rem}.context{margin:0 0 .5rem;color:#3c434a}'
		. '.notice{border-left:4px solid #b32d2e;background:#fcf0f1;padding:.5rem .75rem}'
		. 'button{font:inherit;font-weight:600;color:#fff;background:#1d4ed8;border:0;border-radius:6px;padding:.75rem 1.25rem;min-height:44px;cursor:pointer}'
		. 'button:hover{background:#1e40af}button:focus-visible,a:focus-visible{outline:3px solid #1e1e1e;outline-offset:2px}'
		. 'a{color:#1d4ed8}';
}
