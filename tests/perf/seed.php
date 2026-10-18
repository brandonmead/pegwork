<?php
/**
 * Seeds published SI for Good Projects for the directory performance budget (docs/phase-2-design.md section 10).
 *
 * Shared by PHPUnit (DirectoryPerformanceTest) and WP-CLI on a site with the examples/sifg package:
 *
 *     wp eval-file tests/perf/seed.php [count] [seed]
 *
 * Deterministic for a given seed: random Type and Status, 1 to 3 impact terms, 30% Needs volunteers,
 * 120 to 250 word descriptions. Every second record mentions "data" (the search scenario).
 *
 * @package Pegwork
 */

if ( ! function_exists( 'pegwork_perf_seed' ) ) {
	/**
	 * Creates published Projects.
	 *
	 * @param int $count How many.
	 * @param int $seed  Random seed.
	 * @return int[] Post IDs.
	 */
	function pegwork_perf_seed( int $count = 500, int $seed = 42 ): array {
		if ( class_exists( 'Pegwork\Content\Taxonomies' ) ) {
			Pegwork\Content\Taxonomies::seed( pegwork_registry() );
		}

		$slugs = array();
		foreach ( array( 'sifg_project_type', 'sifg_project_status', 'sifg_impact' ) as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'fields'     => 'id=>slug',
				)
			);
			if ( ! is_array( $terms ) || array() === $terms ) {
				return array();
			}
			$slugs[ $taxonomy ] = array_values( $terms );
		}

		$words = explode( ' ', 'community volunteers learning river garden mentoring library climate water food school health tools neighborhood network training youth energy open map local support research access skills program data' );

		mt_srand( $seed ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_seeding_mt_srand -- Deterministic fixture.
		wp_defer_term_counting( true );

		$ids = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$length = mt_rand( 120, 250 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- Deterministic fixture.
			$text   = array();
			for ( $w = 0; $w < $length; $w++ ) {
				$text[] = $words[ mt_rand( 0, count( $words ) - 2 ) ]; // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- Deterministic fixture; the last word ("data") is added below.
			}
			if ( 0 === $i % 2 ) {
				$text[ mt_rand( 0, $length - 1 ) ] = 'data'; // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- Deterministic fixture.
			}

			$volunteers = mt_rand( 1, 10 ) <= 3; // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- Deterministic fixture.
			$id         = wp_insert_post(
				array(
					'post_type'    => 'sifg_project',
					'post_status'  => 'publish',
					'post_title'   => sprintf( 'Perf project %03d', $i ),
					'post_content' => '<!-- wp:paragraph --><p>' . implode( ' ', $text ) . '</p><!-- /wp:paragraph -->',
					'post_date'    => gmdate( 'Y-m-d H:i:s', strtotime( '2026-01-01 00:00:00' ) + $i * 3600 ),
					'meta_input'   => array(
						'_sifg_project_who_for'          => 'Neighborhood groups',
						'_sifg_project_website'          => 'https://example.org/project-' . $i,
						'_sifg_project_needs_volunteers' => $volunteers ? '1' : '',
						'_sifg_project_volunteer_link'   => $volunteers ? 'https://example.org/project-' . $i . '/volunteer' : '',
						'_sifg_project_contact_email'    => 'perf-' . $i . '@example.org',
						'_sifg_project_last_verified'    => gmdate( 'Y-m-d', strtotime( '2026-01-01' ) + mt_rand( 0, 280 ) * DAY_IN_SECONDS ), // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- Deterministic fixture.
					),
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				continue;
			}

			$impact = $slugs['sifg_impact'];
			$picked = array();
			$wanted = mt_rand( 1, 3 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- Deterministic fixture.
			$offset = mt_rand( 0, count( $impact ) - 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- Deterministic fixture.
			for ( $n = 0; $n < $wanted; $n++ ) {
				$picked[] = $impact[ ( $offset + $n * 5 ) % count( $impact ) ];
			}
			wp_set_object_terms( $id, $slugs['sifg_project_type'][ mt_rand( 0, count( $slugs['sifg_project_type'] ) - 1 ) ], 'sifg_project_type' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- Deterministic fixture.
			wp_set_object_terms( $id, $slugs['sifg_project_status'][ mt_rand( 0, count( $slugs['sifg_project_status'] ) - 1 ) ], 'sifg_project_status' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- Deterministic fixture.
			wp_set_object_terms( $id, $picked, 'sifg_impact' );

			$ids[] = $id;
		}

		wp_defer_term_counting( false );
		return $ids;
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI && isset( $args ) && is_array( $args ) ) {
	$pegwork_perf_ids = pegwork_perf_seed( (int) ( $args[0] ?? 500 ), (int) ( $args[1] ?? 42 ) );
	WP_CLI::success( sprintf( 'Seeded %d published Projects.', count( $pegwork_perf_ids ) ) );
}
