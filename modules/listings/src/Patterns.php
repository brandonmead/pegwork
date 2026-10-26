<?php
/**
 * Generated block patterns for content types with a `listing`.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkListings;

use WP_Block_Pattern_Categories_Registry;
use WP_Block_Patterns_Registry;

/**
 * Registers five patterns per content type with a `listing`, in category "Pegwork":
 * `pegwork/<type>-card`, `-details`, `-directory`, `-featured`, `-latest`; and per relationship, one
 * for each end: `pegwork/<type>-related-<relationship key>` (the records linked to the one being viewed).
 *
 * Overriding: Pegwork registers on `init` priority 20 and skips any name already registered, so a theme
 * (a `patterns/` file with `Slug: pegwork/<type>-card`) or a plugin registering earlier wins. The
 * directory, featured and latest patterns embed the card registered under that name, so an overridden card
 * flows into them. Filter `pegwork_listing_pattern` changes or drops (return null) a generated pattern.
 */
final class Patterns {

	/**
	 * Pattern category slug.
	 */
	public const CATEGORY = 'pegwork';

	/**
	 * Pattern kinds, in registration order (the card first: the others embed it).
	 */
	public const KINDS = array( 'card', 'details', 'directory', 'featured', 'latest' );

	/**
	 * Records per page in the directory pattern and variation.
	 */
	public const PER_PAGE = 12;

	/**
	 * Records in the latest pattern.
	 */
	public const LATEST = 3;

	/**
	 * Records in a related records pattern.
	 */
	public const RELATED = 6;

	/**
	 * Narrowest card column in grids (columns wrap below it, so the grid works at phone width).
	 */
	public const MIN_COLUMN = '16rem';

	/**
	 * Font size preset of a card's meta lines (terms, badges, select and date fields), so they match.
	 */
	public const META_SIZE = 'small';

	/**
	 * Date format of date fields in a card ("Oct 2026").
	 */
	public const CARD_DATE_FORMAT = 'M Y';

	/**
	 * Class of the Clear all link in a directory's no-results message (the filters module points it at
	 * the directory's base URL; `?` without it).
	 */
	public const CLEAR_CLASS = 'pegwork-filters-clear';

	/**
	 * Registers on init priority 20, or now when init has passed. Safe to call more than once.
	 */
	public static function register(): void {
		if ( did_action( 'init' ) && ! doing_action( 'init' ) ) {
			self::register_patterns();
			return;
		}
		if ( ! has_action( 'init', array( self::class, 'register_patterns' ) ) ) {
			add_action( 'init', array( self::class, 'register_patterns' ), 20 );
		}
	}

	/**
	 * Pattern name.
	 *
	 * @param string $type Content type key.
	 * @param string $kind One of KINDS.
	 */
	public static function name( string $type, string $kind ): string {
		return 'pegwork/' . $type . '-' . $kind;
	}

	/**
	 * Registers the category and every missing pattern.
	 */
	public static function register_patterns(): void {
		if ( ! WP_Block_Pattern_Categories_Registry::get_instance()->is_registered( self::CATEGORY ) ) {
			register_block_pattern_category( self::CATEGORY, array( 'label' => __( 'Pegwork', 'pegwork' ) ) );
		}

		$registry = WP_Block_Patterns_Registry::get_instance();
		foreach ( pegwork_registry()->content_types() as $type => $definition ) {
			if ( null === pegwork_registry()->listing( $type ) ) {
				continue;
			}
			foreach ( self::KINDS as $kind ) {
				$name = self::name( $type, $kind );
				if ( $registry->is_registered( $name ) ) {
					continue;
				}

				/**
				 * Filters a generated pattern before it registers.
				 *
				 * @param array|null $pattern Pattern properties for register_block_pattern(); null skips it.
				 * @param string     $type    Content type key.
				 * @param string     $kind    card, details, directory, featured or latest.
				 */
				$pattern = apply_filters( 'pegwork_listing_pattern', self::pattern( $type, $kind ), $type, $kind );
				if ( is_array( $pattern ) && isset( $pattern['content'] ) ) {
					register_block_pattern( $name, $pattern );
				}
			}
		}

		foreach ( pegwork_registry()->relationships() as $key => $relationship ) {
			foreach ( array( $relationship['to'], $relationship['from'] ) as $context ) {
				$name = self::related_name( $context, $key );
				if ( $registry->is_registered( $name ) ) {
					continue;
				}
				/** This filter is documented above; kind `related`, fourth argument the relationship key. */
				$pattern = apply_filters( 'pegwork_listing_pattern', self::related_pattern( $context, $key ), $context, 'related', $key );
				if ( is_array( $pattern ) && isset( $pattern['content'] ) ) {
					register_block_pattern( $name, $pattern );
				}
			}
		}
	}

	/**
	 * Name of a related records pattern.
	 *
	 * @param string $context Content type of the record being viewed.
	 * @param string $key     Relationship key.
	 */
	public static function related_name( string $context, string $key ): string {
		return 'pegwork/' . $context . '-related-' . $key;
	}

	/**
	 * A "Related <plural>" section for the single template of one end of a relationship: a heading and a
	 * card grid of the published records at the other end (`where.related`), inside a "Show if" block whose
	 * relationship condition hides the whole section when nothing is related.
	 *
	 * @param string $context Content type of the record being viewed (`from` or `to` of the relationship).
	 * @param string $key     Relationship key.
	 * @return array<string, mixed>|null Pattern properties, or null for an unknown relationship or type.
	 */
	public static function related_pattern( string $context, string $key ): ?array {
		$relationship = pegwork_registry()->relationships()[ $key ] ?? null;
		if ( null === $relationship || ! in_array( $context, array( $relationship['from'], $relationship['to'] ), true ) ) {
			return null;
		}
		$listed = $context === $relationship['to'] ? $relationship['from'] : $relationship['to'];
		$plural = self::plural( $listed );

		$heading = self::block(
			'core/heading',
			array(),
			/* translators: %s: content type plural name, lowercase. */
			'<h2 class="wp-block-heading">' . esc_html( sprintf( __( 'Related %s', 'pegwork' ), mb_strtolower( $plural ) ) ) . '</h2>'
		);
		$query   = self::query(
			$listed,
			'related-' . $key,
			array(
				'perPage' => self::RELATED,
				'pegwork' => array( 'where' => array( Constraints::RELATED_KEY => $key ) ),
				'grid'    => true,
			)
		);
		$section = self::block(
			'core/group',
			array(
				'tagName'   => 'section',
				'className' => 'pegwork-related',
				'align'     => 'wide',
				// Flow layout, so the heading lines up with the wide card grid.
				'layout'    => array( 'type' => 'default' ),
			),
			'<section class="wp-block-group alignwide pegwork-related">' . self::lines( array( $heading, $query ) ) . '</section>'
		);

		return array(
			/* translators: 1: singular name of the type being viewed, 2: plural name of the related type. */
			'title'         => sprintf( __( '%1$s related %2$s', 'pegwork' ), self::singular( $context ), $plural ),
			'templateTypes' => array( 'single-' . $context ),
			'categories'    => array( self::CATEGORY ),
			'keywords'      => array( 'pegwork', 'related', $plural, $key ),
			'content'       => self::block(
				Conditional::NAME,
				array(
					'conditions' => array(
						array(
							'relationship' => $key,
							'op'           => 'not_empty',
						),
					),
				),
				self::lines( array( $section ) )
			),
		);
	}

	/**
	 * Properties of one generated pattern.
	 *
	 * @param string $type Content type key.
	 * @param string $kind One of KINDS.
	 * @return array<string, mixed> Pattern properties.
	 */
	public static function pattern( string $type, string $kind ): array {
		$plural   = self::plural( $type );
		$singular = self::singular( $type );

		$pattern = match ( $kind ) {
			'card'      => array(
				/* translators: %s: content type singular name. */
				'title' => sprintf( __( '%s card', 'pegwork' ), $singular ),
			),
			'details'   => array(
				/* translators: %s: content type singular name. */
				'title'         => sprintf( __( '%s details', 'pegwork' ), $singular ),
				'templateTypes' => array( 'single-' . $type ),
			),
			'directory' => array(
				/* translators: %s: content type plural name. */
				'title'      => sprintf( __( '%s directory', 'pegwork' ), $plural ),
				'blockTypes' => array( 'core/query' ),
			),
			'featured'  => array(
				/* translators: %s: content type singular name. */
				'title'      => sprintf( __( 'Featured %s', 'pegwork' ), $singular ),
				'blockTypes' => array( 'core/query' ),
			),
			'latest'    => array(
				/* translators: %s: content type plural name. */
				'title'      => sprintf( __( 'Latest %s', 'pegwork' ), $plural ),
				'blockTypes' => array( 'core/query' ),
			),
			default     => array( 'title' => $kind ),
		};

		$pattern['content'] = match ( $kind ) {
			'card'      => self::card( $type ),
			'details'   => self::details( $type ),
			'directory' => self::directory( $type ),
			'featured'  => self::featured( $type ),
			'latest'    => self::latest( $type ),
			default     => '',
		};
		$pattern['categories'] = array( self::CATEGORY );
		$pattern['keywords']   = array( 'pegwork', $plural, $type );
		return $pattern;
	}

	/**
	 * Card for one record in a Query Loop: linked title, excerpt, terms of each taxonomy, then public
	 * boolean (as a badge), select and date fields.
	 *
	 * @param string $type Content type key.
	 * @return string Block markup.
	 */
	public static function card( string $type ): string {
		$blocks = array(
			self::block(
				'core/post-title',
				array(
					'level'  => 3,
					'isLink' => true,
				)
			),
			self::block( 'core/post-excerpt', array( 'excerptLength' => 20 ) ),
		);
		foreach ( self::taxonomies( $type ) as $key => $label ) {
			$blocks[] = self::terms( $key, $label, self::META_SIZE );
		}
		foreach ( pegwork_registry()->fields( $type, 'public' ) as $field ) {
			if ( isset( $field['wp']['storage'] ) ) {
				continue;
			}
			if ( 'boolean' === $field['type'] ) {
				$blocks[] = self::bound_paragraph( $field, array(), 'pegwork-badge', self::META_SIZE );
			} elseif ( 'select' === $field['type'] ) {
				$blocks[] = self::bound_paragraph( $field, array( 'prefix' => self::prefix( $field['label'] ) ), '', self::META_SIZE );
			} elseif ( 'date' === $field['type'] ) {
				$blocks[] = self::bound_paragraph(
					$field,
					array(
						'format' => self::CARD_DATE_FORMAT,
						'prefix' => self::card_date_prefix( $field['label'] ),
					),
					'',
					self::META_SIZE
				);
			}
		}

		return self::group( 'pegwork-card', $blocks );
	}

	/**
	 * Prefix of a date line in a card: "Last verified" becomes "Verified " (so the line reads "Verified
	 * Oct 2026"); any other label becomes "Label: ".
	 *
	 * @param string $label Field label.
	 */
	public static function card_date_prefix( string $label ): string {
		if ( 1 === preg_match( '/^last\s+(\S.*)$/iu', trim( $label ), $match ) ) {
			return mb_strtoupper( mb_substr( $match[1], 0, 1 ) ) . mb_substr( $match[1], 1 ) . ' ';
		}
		return self::prefix( $label );
	}

	/**
	 * Every public field of a record with its label, for a single template. Fields with `show_when` sit
	 * in a "Show if" block with the same condition; url fields are buttons.
	 *
	 * @param string $type Content type key.
	 * @return string Block markup.
	 */
	public static function details( string $type ): string {
		$blocks = array();
		foreach ( pegwork_registry()->fields( $type, 'public' ) as $field ) {
			$storage = $field['wp']['storage'] ?? null;
			if ( 'post_title' === $storage ) {
				continue;
			}

			if ( 'post_content' === $storage ) {
				$block = self::block( 'core/post-content' );
			} elseif ( 'post_excerpt' === $storage ) {
				$block = self::block( 'core/post-excerpt' );
			} elseif ( 'url' === $field['type'] ) {
				// An empty url leaves a Buttons wrapper with nothing in it, so the button sits in a Show if.
				$block = self::block(
					Conditional::NAME,
					array(
						'conditions' => array(
							array(
								'field' => $field['key'],
								'op'    => 'not_empty',
							),
						),
					),
					self::lines( array( self::button( $field ) ) )
				);
			} elseif ( 'boolean' === $field['type'] ) {
				$block = self::bound_paragraph( $field, array(), 'pegwork-badge' );
			} else {
				$block = self::bound_paragraph( $field, array( 'prefix' => self::prefix( $field['label'] ) ) );
			}

			$condition = $field['show_when'] ?? null;
			if ( is_array( $condition ) && isset( $condition['field'] ) ) {
				$block = self::block(
					Conditional::NAME,
					array(
						'conditions' => array(
							array(
								'field' => $condition['field'],
								'op'    => 'equals',
								'value' => $condition['equals'] ?? null,
							),
						),
					),
					self::lines( array( $block ) )
				);
			}
			$blocks[] = $block;
		}
		foreach ( self::taxonomies( $type ) as $key => $label ) {
			$blocks[] = self::terms( $key, $label );
		}

		return self::group( 'pegwork-details', $blocks );
	}

	/**
	 * A filterable directory: Pegwork Query Loop (filters on, enhanced pagination), card grid, pagination
	 * and a no-results message.
	 *
	 * @param string $type Content type key.
	 * @return string Block markup.
	 */
	public static function directory( string $type ): string {
		return self::query(
			$type,
			'directory',
			array(
				'perPage'            => self::PER_PAGE,
				'enhancedPagination' => true,
				'pegwork'            => array( 'filters' => true ),
				'grid'               => true,
				'pagination'         => true,
			)
		);
	}

	/**
	 * One featured record: the record chosen in the first public options page `post` field for this type
	 * (`where.post_in_option`), else the newest published record.
	 *
	 * @param string $type Content type key.
	 * @return string Block markup.
	 */
	public static function featured( string $type ): string {
		$option = self::option_field( $type );
		return self::query(
			$type,
			'featured',
			array(
				'perPage' => 1,
				// Never an empty object: pattern registration re-serializes `{}` as `[]`, which the editor drops.
				'pegwork' => null === $option
					? array( 'filters' => false )
					: array( 'where' => array( Constraints::OPTION_KEY => $option ) ),
			)
		);
	}

	/**
	 * The newest records: a plain core Query Loop (no Pegwork attribute) with the card.
	 *
	 * @param string $type Content type key.
	 * @return string Block markup.
	 */
	public static function latest( string $type ): string {
		return self::query(
			$type,
			'latest',
			array(
				'perPage' => self::LATEST,
				'grid'    => true,
			)
		);
	}

	/**
	 * The first public options page `post` field whose `post_type` is the type, as `"<page>.<field>"`.
	 *
	 * @param string $type Content type key.
	 * @return string|null Reference, or null when there is none.
	 */
	public static function option_field( string $type ): ?string {
		return self::option_fields( $type )[0]['value'] ?? null;
	}

	/**
	 * Public options page `post` fields whose `post_type` is the type (featured preset choices).
	 *
	 * @param string $type Content type key.
	 * @return list<array{value: string, label: string}> `"<page>.<field>"` and "Page title: Field label".
	 */
	public static function option_fields( string $type ): array {
		$list = array();
		foreach ( pegwork_registry()->options_pages() as $page_key => $page ) {
			foreach ( $page['fields'] ?? array() as $field ) {
				if ( 'post' === ( $field['type'] ?? null ) && ( $field['post_type'] ?? null ) === $type && 'public' === ( $field['visibility'] ?? 'public' ) ) {
					$list[] = array(
						'value' => $page_key . '.' . $field['key'],
						'label' => ( $page['title'] ?? $page_key ) . ': ' . ( $field['label'] ?? $field['key'] ),
					);
				}
			}
		}
		return $list;
	}

	/**
	 * The no-results message of a directory.
	 *
	 * @param string $type Content type key.
	 */
	public static function no_results( string $type ): string {
		/* translators: %s: content type plural name, lowercase. */
		return sprintf( __( 'No %s match your search or filters.', 'pegwork' ), mb_strtolower( self::plural( $type ) ) );
	}

	/**
	 * A Query Loop of the type with the registered card.
	 *
	 * @param string               $type    Content type key.
	 * @param string               $kind    Pattern kind (seeds a stable query ID).
	 * @param array<string, mixed> $options `perPage`, `grid` (wide, responsive columns), `pagination`, `enhancedPagination`, `pegwork`.
	 * @return string Block markup.
	 */
	private static function query( string $type, string $kind, array $options ): string {
		$attrs = array(
			'queryId' => 100 + ( crc32( $type . '-' . $kind ) % 900 ),
			'query'   => array(
				'perPage'  => $options['perPage'],
				'pages'    => 0,
				'offset'   => 0,
				'postType' => $type,
				'order'    => 'desc',
				'orderBy'  => 'date',
				'author'   => '',
				'search'   => '',
				'exclude'  => array(), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- A core/query block attribute, not a get_posts() argument; always empty.
				'sticky'   => '',
				'inherit'  => false,
			),
		);
		if ( ! empty( $options['enhancedPagination'] ) ) {
			$attrs['enhancedPagination'] = true;
		}
		if ( isset( $options['pegwork'] ) ) {
			$attrs[ QueryHooks::ATTRIBUTE ] = $options['pegwork'];
		}

		$grid = ! empty( $options['grid'] );
		if ( $grid ) {
			$attrs['align'] = 'wide';
		}
		$layout = $grid
			? array(
				'type'               => 'grid',
				'minimumColumnWidth' => self::MIN_COLUMN,
			)
			: array( 'type' => 'default' );
		$inner  = array( self::block( 'core/post-template', array( 'layout' => $layout ), self::lines( array( self::registered_card( $type ) ) ) ) );

		if ( ! empty( $options['pagination'] ) ) {
			$inner[] = self::block(
				'core/query-pagination',
				array(
					'layout' => array(
						'type'           => 'flex',
						'justifyContent' => 'center',
					),
				),
				self::lines(
					array(
						self::block( 'core/query-pagination-previous' ),
						self::block( 'core/query-pagination-numbers' ),
						self::block( 'core/query-pagination-next' ),
					)
				)
			);
			$inner[] = self::block(
				'core/query-no-results',
				array(),
				self::lines( array( self::block( 'core/paragraph', array(), '<p>' . esc_html( self::no_results( $type ) ) . ' <a class="' . esc_attr( self::CLEAR_CLASS ) . '" href="?">' . esc_html__( 'Clear all filters', 'pegwork' ) . '</a></p>' ) ) )
			);
		}

		return self::block( 'core/query', $attrs, '<div class="wp-block-query' . ( $grid ? ' alignwide' : '' ) . '">' . self::lines( $inner ) . '</div>' );
	}

	/**
	 * The card registered under `pegwork/<type>-card` (a theme's override, or the generated one).
	 *
	 * @param string $type Content type key.
	 */
	private static function registered_card( string $type ): string {
		$card = WP_Block_Patterns_Registry::get_instance()->get_registered( self::name( $type, 'card' ) );
		return is_array( $card ) && isset( $card['content'] ) && is_string( $card['content'] ) ? $card['content'] : self::card( $type );
	}

	/**
	 * A paragraph bound to a field with pegwork/field; its text is the field label (editor placeholder).
	 *
	 * @param array<string, mixed>  $field      Field.
	 * @param array<string, string> $args       Extra binding args.
	 * @param string                $class_name Optional class.
	 * @param string                $font_size  Optional font size preset slug.
	 */
	private static function bound_paragraph( array $field, array $args, string $class_name = '', string $font_size = '' ): string {
		$attrs   = array(
			'metadata' => array(
				'bindings' => array(
					'content' => array(
						'source' => BindingSource::NAME,
						'args'   => array_merge( array( 'key' => $field['key'] ), $args ),
					),
				),
			),
		);
		$classes = array();
		if ( '' !== $class_name ) {
			$attrs['className'] = $class_name;
			$classes[]          = $class_name;
		}
		if ( '' !== $font_size ) {
			$attrs['fontSize'] = $font_size;
			$classes[]         = 'has-' . $font_size . '-font-size';
		}
		$open = array() === $classes ? '<p>' : '<p class="' . esc_attr( implode( ' ', $classes ) ) . '">';
		return self::block( 'core/paragraph', $attrs, $open . esc_html( $field['label'] ) . '</p>' );
	}

	/**
	 * A button whose link is a url field; its text is the field label.
	 *
	 * @param array<string, mixed> $field Field.
	 */
	private static function button( array $field ): string {
		$button = self::block(
			'core/button',
			array(
				'metadata' => array(
					'bindings' => array(
						'url' => array(
							'source' => BindingSource::NAME,
							'args'   => array( 'key' => $field['key'] ),
						),
					),
				),
			),
			'<div class="wp-block-button"><a class="wp-block-button__link wp-element-button">' . esc_html( $field['label'] ) . '</a></div>'
		);
		return self::block( 'core/buttons', array(), '<div class="wp-block-buttons">' . self::lines( array( $button ) ) . '</div>' );
	}

	/**
	 * Terms of a taxonomy, prefixed with its label.
	 *
	 * @param string $taxonomy  Taxonomy key.
	 * @param string $label     Label.
	 * @param string $font_size Optional font size preset slug.
	 */
	private static function terms( string $taxonomy, string $label, string $font_size = '' ): string {
		$attrs = array(
			'term'   => $taxonomy,
			'prefix' => self::prefix( $label ),
		);
		if ( '' !== $font_size ) {
			$attrs['fontSize'] = $font_size;
		}
		return self::block( 'core/post-terms', $attrs );
	}

	/**
	 * A group with a class around blocks.
	 *
	 * @param string   $class_name Class.
	 * @param string[] $blocks     Block markup.
	 */
	private static function group( string $class_name, array $blocks ): string {
		return self::block(
			'core/group',
			array(
				'className' => $class_name,
				'layout'    => array( 'type' => 'constrained' ),
			),
			'<div class="wp-block-group ' . esc_attr( $class_name ) . '">' . self::lines( $blocks ) . '</div>'
		);
	}

	/**
	 * Taxonomies used by a type, with their singular labels.
	 *
	 * @param string $type Content type key.
	 * @return array<string, string> Label by taxonomy key.
	 */
	private static function taxonomies( string $type ): array {
		$registry = pegwork_registry();
		$list     = array();
		foreach ( $registry->content_type( $type )['taxonomies'] ?? array() as $usage ) {
			$list[ $usage['key'] ] = $registry->taxonomies()[ $usage['key'] ]['labels']['singular'] ?? $usage['key'];
		}
		return $list;
	}

	/**
	 * "Label: " for a prefix.
	 *
	 * @param string $label Label.
	 */
	private static function prefix( string $label ): string {
		/* translators: %s: field or taxonomy label. */
		return sprintf( __( '%s: ', 'pegwork' ), $label );
	}

	/**
	 * Content type plural label.
	 *
	 * @param string $type Content type key.
	 */
	private static function plural( string $type ): string {
		return (string) ( pegwork_registry()->content_type( $type )['labels']['plural'] ?? $type );
	}

	/**
	 * Content type singular label.
	 *
	 * @param string $type Content type key.
	 */
	private static function singular( string $type ): string {
		return (string) ( pegwork_registry()->content_type( $type )['labels']['singular'] ?? $type );
	}

	/**
	 * Serialized block.
	 *
	 * @param string               $name    Block name.
	 * @param array<string, mixed> $attrs   Attributes.
	 * @param string               $content Inner markup ('' for a void block).
	 */
	private static function block( string $name, array $attrs = array(), string $content = '' ): string {
		return get_comment_delimited_block_content( $name, $attrs, $content );
	}

	/**
	 * Inner markup: one block per line.
	 *
	 * @param string[] $blocks Block markup.
	 */
	private static function lines( array $blocks ): string {
		return "\n" . implode( "\n", $blocks ) . "\n";
	}
}
