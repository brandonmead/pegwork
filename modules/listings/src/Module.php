<?php
/**
 * Listings module entry point.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkListings;

use Pegwork\Api\Module as ModuleContract;

/**
 * Registers the binding source, the conditional block, the block category and the editor script.
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
		return 'listings';
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
		BindingSource::register();
		Conditional::register();
		QueryHooks::register();
		Patterns::register();
		Editor::register();

		if ( ! has_filter( 'block_categories_all', array( self::class, 'block_categories' ) ) ) {
			add_filter( 'block_categories_all', array( self::class, 'block_categories' ) );
		}
	}

	/**
	 * Adds the "Pegwork" block category once.
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
