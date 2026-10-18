<?php
/**
 * The pegwork/filters block.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkFilters;

use WP_Block_Type_Registry;

/**
 * Search and filter controls for a directory, generated from the type's registry `listing` section.
 *
 * A GET form, so everything works without JavaScript; the view script module (Interactivity API store
 * `pegwork/filters`) updates only the directory's Query Loop region with the router. The URL is the only
 * state. `render()` is pure (no WP_Block), so a shortcode or page builder module can wrap it:
 *
 *     echo FiltersBlock::render( $attributes, FiltersBlock::state( $attributes, 'class="my-filters"' ) );
 */
final class FiltersBlock {

	/**
	 * Block name.
	 */
	public const NAME = 'pegwork/filters';

	/**
	 * Interactivity API store namespace.
	 */
	public const STORE = 'pegwork/filters';

	/**
	 * View script module ID.
	 */
	public const MODULE = '@pegwork/filters';

	/**
	 * Editor script handle.
	 */
	public const EDITOR_HANDLE = 'pegwork-filters-editor';

	/**
	 * Block instances rendered in this request (unique control IDs).
	 *
	 * @var int
	 */
	private static int $instances = 0;

	/**
	 * Registers the view module, the editor script and the block type. Safe to call more than once.
	 */
	public static function register(): void {
		self::register_assets();

		if ( ! has_action( 'enqueue_block_editor_assets', array( self::class, 'editor_config' ) ) ) {
			add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_config' ) );
		}

		if ( WP_Block_Type_Registry::get_instance()->is_registered( self::NAME ) ) {
			return;
		}
		register_block_type(
			PEGWORK_FILTERS_DIR . 'blocks/filters',
			array( 'render_callback' => array( self::class, 'render_block' ) )
		);
	}

	/**
	 * Registers the built view module and editor script, when built.
	 */
	private static function register_assets(): void {
		$view = PEGWORK_DIR . 'assets/build/filters-view.asset.php';
		if ( is_readable( $view ) && function_exists( 'wp_register_script_module' ) ) {
			$asset = require $view;
			wp_register_script_module(
				self::MODULE,
				plugins_url( 'assets/build/filters-view.js', PEGWORK_FILE ),
				$asset['dependencies'] ?? array(),
				$asset['version'] ?? false
			);
		}

		$editor = PEGWORK_DIR . 'assets/build/filters-editor.asset.php';
		if ( is_readable( $editor ) && ! wp_script_is( self::EDITOR_HANDLE, 'registered' ) ) {
			$asset = require $editor;
			wp_register_script(
				self::EDITOR_HANDLE,
				plugins_url( 'assets/build/filters-editor.js', PEGWORK_FILE ),
				$asset['dependencies'] ?? array(),
				$asset['version'] ?? false,
				true
			);
			wp_set_script_translations( self::EDITOR_HANDLE, 'pegwork' );
		}
	}

	/**
	 * Adds the editor config (listing types, their filters and labels) before the editor script.
	 * Hooked to enqueue_block_editor_assets.
	 */
	public static function editor_config(): void {
		if ( ! wp_script_is( self::EDITOR_HANDLE, 'registered' ) ) {
			return;
		}
		$types = array();
		foreach ( array_keys( pegwork_registry()->content_types() ) as $type ) {
			$definition = Definition::for_type( (string) $type );
			if ( null === $definition ) {
				continue;
			}
			$types[] = array(
				'key'     => $definition['type'],
				'label'   => $definition['plural'],
				'search'  => $definition['search'],
				'filters' => array_map(
					static fn( array $filter ): array => array(
						'key'   => $filter['key'],
						'label' => $filter['label'],
					),
					$definition['filters']
				),
			);
		}
		wp_add_inline_script( self::EDITOR_HANDLE, 'window.pegworkFilters = ' . wp_json_encode( array( 'types' => $types ) ) . ';', 'before' );
	}

	/**
	 * Block render callback.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string Markup, or '' when no content type with a `listing` applies.
	 */
	public static function render_block( $attributes ): string {
		$attributes = is_array( $attributes ) ? $attributes : array();
		$state      = self::state( $attributes, get_block_wrapper_attributes() );
		if ( null === $state ) {
			return '';
		}

		wp_interactivity_state(
			self::STORE,
			array(
				'strings'     => self::strings(),
				'directories' => array(
					$state['type'] => array(
						'count'     => $state['count'],
						'countText' => self::count_text( $state['count'] ),
					),
				),
			)
		);
		return self::render( $attributes, $state );
	}

	/**
	 * Everything render() needs for the current request.
	 *
	 * @param array<string, mixed> $attributes Block attributes (`postType`, `filters`, `showSearch`).
	 * @param string               $wrapper    Wrapper attributes markup (get_block_wrapper_attributes() in a block).
	 * @return array<string, mixed>|null State, or null when no content type with a `listing` applies.
	 */
	public static function state( array $attributes, string $wrapper = '' ): ?array {
		$type = self::type( $attributes );
		if ( null === $type ) {
			return null;
		}
		$definition = Definition::for_type( $type );
		if ( null === $definition ) {
			return null;
		}

		$url = UrlState::parse( $definition, Hooks::request() );

		$chosen = isset( $attributes['filters'] ) && is_array( $attributes['filters'] ) ? array_values( array_filter( $attributes['filters'], 'is_string' ) ) : array();
		if ( array() !== $chosen ) {
			$by_key                = array_column( $definition['filters'], null, 'key' );
			$definition['filters'] = array_values( array_filter( array_map( static fn( string $key ) => $by_key[ $key ] ?? null, $chosen ) ) );
		}
		if ( isset( $attributes['showSearch'] ) && false === $attributes['showSearch'] ) {
			$definition['search'] = false;
		}

		return array(
			'type'       => $type,
			'definition' => $definition,
			'url'        => $url,
			'base'       => self::base_url( $type ),
			'count'      => Count::for_type( $type ),
			'id'         => 'pegwork-filters-' . ( ++self::$instances ),
			'wrapper'    => '' !== $wrapper ? $wrapper : 'class="wp-block-pegwork-filters"',
		);
	}

	/**
	 * The content type of a block: its `postType` attribute when that type has a `listing`, else the queried
	 * archive's type, else the only type with a `listing`.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @return string|null Type key.
	 */
	public static function type( array $attributes ): ?string {
		$registry = pegwork_registry();
		$type     = isset( $attributes['postType'] ) && is_string( $attributes['postType'] ) ? $attributes['postType'] : '';
		if ( '' !== $type ) {
			return null === $registry->listing( $type ) ? null : $type;
		}

		$queried = is_post_type_archive() ? get_query_var( 'post_type' ) : '';
		if ( is_array( $queried ) && 1 === count( $queried ) ) {
			$queried = reset( $queried );
		}
		if ( is_string( $queried ) && '' !== $queried && null !== $registry->listing( $queried ) ) {
			return $queried;
		}

		$listed = array_values( array_filter( array_map( 'strval', array_keys( $registry->content_types() ) ), static fn( string $key ): bool => null !== $registry->listing( $key ) ) );
		return 1 === count( $listed ) ? $listed[0] : null;
	}

	/**
	 * The URL that filters start from: the type's archive link on its archive, else the page's permalink,
	 * else the archive link (or home). Never holds paging or filter parameters.
	 *
	 * @param string $type Content type key.
	 */
	public static function base_url( string $type ): string {
		$url = '';
		if ( is_post_type_archive( $type ) ) {
			$url = (string) get_post_type_archive_link( $type );
		} elseif ( is_singular() ) {
			$url = (string) get_permalink( get_queried_object_id() );
		} elseif ( is_front_page() ) {
			$url = home_url( '/' );
		}
		if ( '' === $url ) {
			$url = (string) get_post_type_archive_link( $type );
		}
		if ( '' === $url ) {
			$url = home_url( '/' );
		}

		$definition = Definition::for_type( $type );
		$keys       = array_merge( array( UrlState::SEARCH, 'paged', 'page' ), array_column( $definition['filters'] ?? array(), 'key' ) );
		return remove_query_arg( $keys, $url );
	}

	/**
	 * Translated strings the view module needs (script modules cannot load translations yet).
	 *
	 * @return array<string, string>
	 */
	public static function strings(): array {
		return array(
			/* translators: 1: filter label, 2: number of selected options. */
			'selectedOne'   => _n( '%1$s, %2$s selected', '%1$s, %2$s selected', 1, 'pegwork' ),
			/* translators: 1: filter label, 2: number of selected options. */
			'selectedOther' => _n( '%1$s, %2$s selected', '%1$s, %2$s selected', 2, 'pegwork' ),
		);
	}

	/**
	 * The result count as text.
	 *
	 * @param int $count Records.
	 */
	public static function count_text( int $count ): string {
		if ( 0 === $count ) {
			return __( 'No results', 'pegwork' );
		}
		/* translators: %s: number of results. */
		return sprintf( _n( '%s result', '%s results', $count, 'pegwork' ), number_format_i18n( $count ) );
	}

	/**
	 * Summary text of a multi-value filter: "Impact category, 2 selected", or the label.
	 *
	 * @param string $label    Filter label.
	 * @param int    $selected Selected options.
	 */
	public static function summary( string $label, int $selected ): string {
		if ( 0 === $selected ) {
			return $label;
		}
		/* translators: 1: filter label, 2: number of selected options. */
		return sprintf( _n( '%1$s, %2$s selected', '%1$s, %2$s selected', $selected, 'pegwork' ), $label, number_format_i18n( $selected ) );
	}

	/**
	 * The block markup. Pure: everything comes from the arguments.
	 *
	 * @param array<string, mixed> $attributes Block attributes (unused beyond state(); kept for wrappers).
	 * @param array<string, mixed> $state      From state(): `type`, `definition`, `url`, `base`, `count`, `id`, `wrapper`.
	 * @return string Markup.
	 */
	public static function render( array $attributes, array $state ): string {
		unset( $attributes );
		$definition = $state['definition'];
		$url        = $state['url'];
		$id         = (string) $state['id'];
		$base       = (string) $state['base'];
		$active     = UrlState::active( $url );
		$plural     = mb_strtolower( (string) $definition['plural'] );
		$count_text = self::count_text( (int) $state['count'] );

		$summaries = array();
		$fields    = array();
		if ( ! empty( $definition['search'] ) ) {
			$fields[] = self::search( $id, $plural, $url['q'] );
		}
		foreach ( $definition['filters'] as $filter ) {
			$values = $url['values'][ $filter['key'] ] ?? array();
			if ( 'boolean' === $filter['kind'] ) {
				$fields[] = self::toggle( $id, $filter, $values );
			} elseif ( $filter['multiple'] ) {
				$summaries[ $filter['key'] ] = self::summary( $filter['label'], count( $values ) );
				$fields[]                    = self::checkboxes( $id, $filter, $values, $summaries[ $filter['key'] ] );
			} elseif ( 'taxonomy' === $filter['kind'] ) {
				$fields[] = self::radios( $id, $filter, $values );
			} else {
				$fields[] = self::select( $id, $filter, $values );
			}
		}

		$context = array(
			'type'      => $state['type'],
			'base'      => $base,
			'search'    => ! empty( $definition['search'] ),
			'filters'   => array_map(
				static fn( array $filter ): array => array(
					'key'      => $filter['key'],
					'kind'     => $filter['kind'],
					'multiple' => (bool) $filter['multiple'],
					'label'    => $filter['label'],
				),
				$definition['filters']
			),
			'active'    => $active,
			'summaries' => (object) $summaries,
			'countText' => $count_text,
		);

		list( $action, $hidden ) = self::action( $base );

		$html  = '<div ' . $state['wrapper'] . ' data-wp-interactive="' . esc_attr( self::STORE ) . '" ' . wp_interactivity_data_wp_context( $context ) . ' data-wp-on-window--popstate="callbacks.popstate">';
		$html .= '<form class="pegwork-filters__form" method="get" role="search" action="' . esc_url( $action ) . '"'
			/* translators: %s: content type plural name, lowercase. */
			. ' aria-label="' . esc_attr( sprintf( __( 'Filter %s', 'pegwork' ), $plural ) ) . '"'
			. ' data-wp-on--submit="actions.submit" data-wp-on--change="actions.change" data-wp-on--input="actions.input">';
		foreach ( $hidden as $name => $value ) {
			$html .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		}
		$html .= '<div class="pegwork-filters__fields">' . implode( '', $fields ) . '</div>';
		$html .= '<div class="pegwork-filters__actions">';
		$html .= '<button type="submit" class="pegwork-filters__apply wp-element-button">' . esc_html__( 'Apply filters', 'pegwork' ) . '</button>';
		$html .= '<a class="pegwork-filters__clear" href="' . esc_url( $base ) . '" data-wp-on--click="actions.clear" data-wp-bind--hidden="!context.active"' . ( $active ? '' : ' hidden' ) . '>' . esc_html__( 'Clear all', 'pegwork' ) . '</a>';
		$html .= '</div></form>';
		$html .= '<p class="pegwork-filters__count" role="status" data-wp-text="context.countText">' . esc_html( $count_text ) . '</p>';
		$html .= '</div>';
		return $html;
	}

	/**
	 * Form action without a query string (a GET form drops it) and the base URL's own parameters as hidden
	 * fields (plain permalinks: `?page_id=5`).
	 *
	 * @param string $base Base URL.
	 * @return array{0: string, 1: array<string, string>}
	 */
	private static function action( string $base ): array {
		$query = (string) wp_parse_url( $base, PHP_URL_QUERY );
		$args  = array();
		if ( '' !== $query ) {
			wp_parse_str( $query, $args );
		}
		$hidden = array();
		foreach ( $args as $name => $value ) {
			if ( is_string( $name ) && is_string( $value ) ) {
				$hidden[ $name ] = $value;
			}
		}
		$action = strtok( $base, '?#' );
		return array( false === $action ? $base : $action, $hidden );
	}

	/**
	 * The search box.
	 *
	 * @param string $id     Block instance ID.
	 * @param string $plural Content type plural name, lowercase.
	 * @param string $value  Current search.
	 */
	private static function search( string $id, string $plural, string $value ): string {
		$input = $id . '-q';
		return '<div class="pegwork-filters__field pegwork-filters__search">'
			/* translators: %s: content type plural name, lowercase. */
			. '<label class="pegwork-filters__label" for="' . esc_attr( $input ) . '">' . esc_html( sprintf( __( 'Search %s', 'pegwork' ), $plural ) ) . '</label>'
			. '<input type="search" id="' . esc_attr( $input ) . '" name="' . esc_attr( UrlState::SEARCH ) . '" value="' . esc_attr( $value ) . '" maxlength="' . UrlState::MAX_SEARCH . '" autocomplete="off">'
			. '</div>';
	}

	/**
	 * One checkbox for a boolean field (`<key>=1`).
	 *
	 * @param string               $id     Block instance ID.
	 * @param array<string, mixed> $filter Filter.
	 * @param string[]             $values Current values.
	 */
	private static function toggle( string $id, array $filter, array $values ): string {
		$input = $id . '-' . $filter['key'];
		return '<div class="pegwork-filters__field pegwork-filters__toggle">'
			. '<input type="checkbox" id="' . esc_attr( $input ) . '" name="' . esc_attr( $filter['key'] ) . '" value="1"' . checked( in_array( '1', $values, true ), true, false ) . '>'
			. '<label for="' . esc_attr( $input ) . '">' . esc_html( $filter['label'] ) . '</label>'
			. '</div>';
	}

	/**
	 * Checkboxes for a multi-value filter (`<key>[]=a`), in a disclosure with a summary.
	 *
	 * @param string               $id      Block instance ID.
	 * @param array<string, mixed> $filter  Filter.
	 * @param string[]             $values  Current values.
	 * @param string               $summary Summary text.
	 */
	private static function checkboxes( string $id, array $filter, array $values, string $summary ): string {
		$options = '';
		foreach ( $filter['options'] as $index => $option ) {
			$input    = $id . '-' . $filter['key'] . '-' . $index;
			$options .= '<div class="pegwork-filters__option">'
				. '<input type="checkbox" id="' . esc_attr( $input ) . '" name="' . esc_attr( $filter['key'] ) . '[]" value="' . esc_attr( $option['value'] ) . '"' . checked( in_array( $option['value'], $values, true ), true, false ) . '>'
				. '<label for="' . esc_attr( $input ) . '">' . esc_html( $option['label'] ) . '</label>'
				. '</div>';
		}
		return '<details class="pegwork-filters__field pegwork-filters__details">'
			. '<summary class="pegwork-filters__label" data-wp-text="context.summaries.' . esc_attr( $filter['key'] ) . '">' . esc_html( $summary ) . '</summary>'
			. '<fieldset><legend class="screen-reader-text">' . esc_html( $filter['label'] ) . '</legend>'
			. '<div class="pegwork-filters__options">' . $options . '</div></fieldset>'
			. '</details>';
	}

	/**
	 * Radio buttons for a single-value taxonomy filter, with "Any" first.
	 *
	 * @param string               $id     Block instance ID.
	 * @param array<string, mixed> $filter Filter.
	 * @param string[]             $values Current values.
	 */
	private static function radios( string $id, array $filter, array $values ): string {
		$current = $values[0] ?? '';
		$choices = array_merge(
			array(
				array(
					'value' => '',
					'label' => __( 'Any', 'pegwork' ),
				),
			),
			$filter['options']
		);
		$options = '';
		foreach ( $choices as $index => $option ) {
			$input    = $id . '-' . $filter['key'] . '-' . $index;
			$options .= '<div class="pegwork-filters__option">'
				. '<input type="radio" id="' . esc_attr( $input ) . '" name="' . esc_attr( $filter['key'] ) . '" value="' . esc_attr( $option['value'] ) . '"' . checked( $option['value'], $current, false ) . '>'
				. '<label for="' . esc_attr( $input ) . '">' . esc_html( $option['label'] ) . '</label>'
				. '</div>';
		}
		return '<fieldset class="pegwork-filters__field pegwork-filters__radios">'
			. '<legend class="pegwork-filters__label">' . esc_html( $filter['label'] ) . '</legend>'
			. '<div class="pegwork-filters__options">' . $options . '</div>'
			. '</fieldset>';
	}

	/**
	 * A select for a single-value select field, with "Any" first.
	 *
	 * @param string               $id     Block instance ID.
	 * @param array<string, mixed> $filter Filter.
	 * @param string[]             $values Current values.
	 */
	private static function select( string $id, array $filter, array $values ): string {
		$input   = $id . '-' . $filter['key'];
		$current = $values[0] ?? '';
		$options = '<option value="">' . esc_html__( 'Any', 'pegwork' ) . '</option>';
		foreach ( $filter['options'] as $option ) {
			$options .= '<option value="' . esc_attr( $option['value'] ) . '"' . selected( $option['value'], $current, false ) . '>' . esc_html( $option['label'] ) . '</option>';
		}
		return '<div class="pegwork-filters__field pegwork-filters__select">'
			. '<label class="pegwork-filters__label" for="' . esc_attr( $input ) . '">' . esc_html( $filter['label'] ) . '</label>'
			. '<select id="' . esc_attr( $input ) . '" name="' . esc_attr( $filter['key'] ) . '">' . $options . '</select>'
			. '</div>';
	}
}
