<?php
/**
 * Front-end view script for forms.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkForms;

/**
 * The view script (`assets/src/forms-view/`, built to `assets/build/forms-view.js`) enhances the
 * server-rendered form: one step at a time with Back and Next, `show_when` toggled in place, live word
 * counters, hint checks before Next and submit, and a submitting state. It is a classic script that
 * depends on `wp-i18n`, so `wp_set_script_translations()` loads its strings (script modules have no
 * translation loading in WordPress 6.7). It is enqueued only when a form renders, and reads each form's
 * rules from the form's `data-pegwork-config` attribute. Without it the form works as before.
 */
final class View {

	/**
	 * Script handle.
	 */
	public const HANDLE = 'pegwork-forms-view';

	/**
	 * Validation rules the script checks as hints (the server checks every rule again).
	 */
	private const HINT_RULES = array( 'min_words', 'max_words', 'min_length', 'max_length', 'min', 'max' );

	/**
	 * Registers the script when it is built. Safe to call more than once.
	 */
	public static function register(): void {
		if ( wp_script_is( self::HANDLE, 'registered' ) ) {
			return;
		}
		$asset_file = PEGWORK_DIR . 'assets/build/forms-view.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_register_script(
			self::HANDLE,
			plugins_url( 'assets/build/forms-view.js', PEGWORK_FILE ),
			$asset['dependencies'],
			$asset['version'],
			array( 'in_footer' => true )
		);
		wp_set_script_translations( self::HANDLE, 'pegwork' );
	}

	/**
	 * Enqueues the script for this page (called when a form renders).
	 */
	public static function enqueue(): void {
		self::register();
		if ( wp_script_is( self::HANDLE, 'registered' ) ) {
			wp_enqueue_script( self::HANDLE );
		}
	}

	/**
	 * The rules the script needs for one rendered form: per item its control ID, type, label, required
	 * flags, conditions and hint rules; per step its label and item keys. Only data already on the page.
	 *
	 * @param array<string, mixed> $form Canonical form.
	 * @param string               $base Base ID of this rendering.
	 * @return array{items: array<int, array<string, mixed>>, steps?: array<int, array{label: string, items: string[]}>}
	 */
	public static function config( array $form, string $base ): array {
		$items = array();
		$steps = array();
		foreach ( Definition::sections( $form ) as $section ) {
			$keys = array();
			foreach ( $section['fields'] as $item ) {
				$keys[]  = (string) $item['key'];
				$items[] = self::item( $item, $base );
			}
			if ( $section['step'] ) {
				$steps[] = array(
					'label' => $section['label'],
					'items' => $keys,
				);
			}
		}

		$config = array( 'items' => $items );
		if ( $steps ) {
			$config['steps'] = $steps;
		}
		return $config;
	}

	/**
	 * One item's entry: defaults left out to keep the attribute short.
	 *
	 * @param array<string, mixed> $item Resolved item.
	 * @param string               $base Base ID.
	 * @return array<string, mixed>
	 */
	private static function item( array $item, string $base ): array {
		$entry = array(
			'key'     => (string) $item['key'],
			'type'    => (string) $item['type'],
			'label'   => (string) $item['label'],
			'control' => Renderer::control_id( $base, $item ),
		);
		if ( Renderer::is_group( $item ) ) {
			$entry['group'] = true;
		}
		if ( ! empty( $item['multiple'] ) ) {
			$entry['multiple'] = true;
		}
		if ( ! empty( $item['required'] ) ) {
			$entry['required'] = true;
		}
		if ( isset( $item['bind'] ) ) {
			$entry['bound'] = true;
		}
		foreach ( array( 'show_when', 'required_when' ) as $condition ) {
			if ( is_array( $item[ $condition ] ?? null ) && isset( $item[ $condition ]['field'] ) ) {
				$entry[ $condition ] = array(
					'field'  => (string) $item[ $condition ]['field'],
					'equals' => $item[ $condition ]['equals'] ?? null,
				);
			}
		}
		if ( isset( $entry['show_when'] ) ) {
			// The page without JavaScript states the condition in the help text; the script drops that part.
			$entry['help'] = (string) ( $item['help'] ?? '' );
		}
		$rules = array_intersect_key( is_array( $item['validate'] ?? null ) ? $item['validate'] : array(), array_flip( self::HINT_RULES ) );
		if ( $rules ) {
			$entry['validate'] = $rules;
		}
		return $entry;
	}
}
