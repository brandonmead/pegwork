<?php
/**
 * Forms module entry point.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkForms;

use Pegwork\Api\Module as ModuleContract;

/**
 * Installs the entries table, registers the pegwork/form block and its editor script, and handles
 * submissions on template_redirect. A disabled module is never included, so nothing renders or runs.
 */
final class Module implements ModuleContract {

	/**
	 * Block category slug shared by Pegwork blocks.
	 */
	public const CATEGORY = 'pegwork';

	/**
	 * The manifest slug.
	 */
	public function id(): string {
		return 'forms';
	}

	/**
	 * No other module is needed.
	 *
	 * @return string[]
	 */
	public function requires(): array {
		return array();
	}

	/**
	 * Starts the module (on pegwork_loaded, init priority 5). Safe to call more than once.
	 */
	public function boot(): void {
		Schema::maybe_install();
		Block::register();
		Editor::register();
		Submission::register();
		// Entries admin, CSV export, privacy tools and retention (Phase 3 PR 7).
		EntriesAdmin::register();
		EntryActions::register();
		Export::register();
		Privacy::register();
		Cleanup::register();

		if ( ! has_filter( 'block_categories_all', array( self::class, 'block_categories' ) ) ) {
			add_filter( 'block_categories_all', array( self::class, 'block_categories' ) );
		}
	}

	/**
	 * Capability that manages forms and reads entries. Filter `pegwork_forms_capability`; default `manage_options`.
	 */
	public static function capability(): string {
		/**
		 * Filters the capability that manages forms and reads entries.
		 *
		 * @param string $capability Default `manage_options`.
		 */
		$capability = apply_filters( 'pegwork_forms_capability', 'manage_options' );

		return is_string( $capability ) && '' !== $capability ? $capability : 'manage_options';
	}

	/**
	 * Adds the "Pegwork" block category once (the listings module adds the same one).
	 *
	 * @param array<int, array<string, mixed>> $categories Block categories.
	 * @return array<int, array<string, mixed>> Categories with Pegwork's.
	 */
	public static function block_categories( $categories ): array {
		$categories = is_array( $categories ) ? $categories : array();
		if ( in_array( self::CATEGORY, array_column( $categories, 'slug' ), true ) ) {
			return $categories;
		}

		$categories[] = array(
			'slug'  => self::CATEGORY,
			'title' => __( 'Pegwork', 'pegwork' ),
			'icon'  => null,
		);
		return $categories;
	}
}
