<?php
/**
 * Run log and run detail views.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Admin;

use PegworkAutomation\Engine;
use PegworkAutomation\Plan;
use PegworkAutomation\Runs;

/**
 * The run log (newest first, filtered by automation and status, paginated) and one run with its step
 * attempts and, when it failed, Retry. The same data as `wp pegwork automation log [--run=<id>]`: never a
 * run's context (its trigger payload may hold private field values), only statuses, step summaries and
 * errors.
 */
final class RunLog {

	/**
	 * Runs per page.
	 */
	public const PER_PAGE = 20;

	/**
	 * Prints the run log.
	 */
	public static function render_log(): void {
		$automations = pegwork_registry()->automations();
		$runs        = Engine::instance()->runs();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$automation = isset( $_GET['automation'] ) ? sanitize_text_field( wp_unslash( $_GET['automation'] ) ) : '';
		$status     = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$paged      = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$status = in_array( $status, Runs::STATUSES, true ) ? $status : '';

		$total = $runs->count( '' === $automation ? null : $automation, '' === $status ? null : $status );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged = min( $paged, $pages );
		$rows  = $runs->page( '' === $automation ? null : $automation, '' === $status ? null : $status, self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );

		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Automation run log', 'pegwork' ) . '</h1> ';
		echo '<a class="page-title-action" href="' . esc_url( Screen::url() ) . '">' . esc_html__( 'Back to automations', 'pegwork' ) . '</a>';
		echo '<hr class="wp-header-end">';
		Screen::notice();

		// Filters: a GET form back to this view.
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="pegwork-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( Screen::SLUG ) . '">';
		echo '<input type="hidden" name="view" value="log">';
		echo '<label for="pegwork-filter-automation">' . esc_html__( 'Automation', 'pegwork' ) . '</label>';
		echo '<select id="pegwork-filter-automation" name="automation">';
		echo '<option value="">' . esc_html__( 'All automations', 'pegwork' ) . '</option>';
		$keys = array_keys( $automations );
		if ( '' !== $automation && ! isset( $automations[ $automation ] ) ) {
			$keys[] = $automation; // A removed automation still has runs.
		}
		foreach ( $keys as $key ) {
			$key   = (string) $key;
			$label = isset( $automations[ $key ] ) ? $automations[ $key ]['label'] . ' (' . $key . ')' : $key;
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $automation, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<label for="pegwork-filter-status">' . esc_html__( 'Status', 'pegwork' ) . '</label>';
		echo '<select id="pegwork-filter-status" name="status">';
		echo '<option value="">' . esc_html__( 'All statuses', 'pegwork' ) . '</option>';
		foreach ( Runs::STATUSES as $option ) {
			echo '<option value="' . esc_attr( $option ) . '"' . selected( $status, $option, false ) . '>' . esc_html( Format::run_status( $option ) ) . '</option>';
		}
		echo '</select>';
		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'pegwork' ) . '</button>';
		echo '</form>';

		echo '<p class="pegwork-count">' . esc_html(
			/* translators: %s: number of runs. */
			sprintf( _n( '%s run', '%s runs', $total, 'pegwork' ), number_format_i18n( $total ) )
		) . '</p>';

		echo '<table class="widefat striped pegwork-table">';
		echo '<caption class="screen-reader-text">' . esc_html__( 'Runs, newest first', 'pegwork' ) . '</caption>';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Run', 'pegwork' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Automation', 'pegwork' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Trigger', 'pegwork' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Record', 'pegwork' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Status', 'pegwork' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Outcome', 'pegwork' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Created', 'pegwork' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( array() === $rows ) {
			$empty = '' === $automation && '' === $status
				? __( 'No runs yet. Runs appear here when an automation is triggered or run now.', 'pegwork' )
				: __( 'No runs match these filters.', 'pegwork' );
			echo '<tr class="no-items"><td colspan="7">' . esc_html( $empty ) . '</td></tr>';
		}

		foreach ( $rows as $run ) {
			$label = isset( $automations[ $run['automation'] ] ) ? $automations[ $run['automation'] ]['label'] : __( '(removed)', 'pegwork' );
			echo '<tr>';
			echo '<th scope="row"><a href="' . esc_url( self::run_url( $run['id'] ) ) . '">' . esc_html(
				/* translators: %d: run ID. */
				sprintf( __( 'Run %d', 'pegwork' ), $run['id'] )
			) . '</a></th>';
			echo '<td>' . esc_html( $label ) . '<br><code>' . esc_html( $run['automation'] ) . '</code></td>';
			echo '<td>' . Format::run_trigger( $run ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Format.
			echo '<td>' . Format::record( $run['record_id'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Format.
			echo '<td>' . Format::badge( $run['status'], Format::run_status( $run['status'] ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Format.
			echo '<td>' . esc_html( Format::outcome( $run ) ) . '</td>';
			echo '<td>' . Format::time( $run['created_at'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Format.
			echo '</tr>';
		}
		echo '</tbody></table>';

		self::pagination( $paged, $pages, $automation, $status );
	}

	/**
	 * Prints one run and its step attempts.
	 *
	 * @param int $id Run ID.
	 */
	public static function render_run( int $id ): void {
		$runs = Engine::instance()->runs();
		$run  = $id > 0 ? $runs->get( $id ) : null;

		/* translators: %d: run ID. */
		echo '<h1 class="wp-heading-inline">' . esc_html( null === $run ? __( 'Run not found', 'pegwork' ) : sprintf( __( 'Run %d', 'pegwork' ), $id ) ) . '</h1> ';
		echo '<a class="page-title-action" href="' . esc_url( Screen::url( array( 'view' => 'log' ) ) ) . '">' . esc_html__( 'Back to run log', 'pegwork' ) . '</a>';
		echo '<hr class="wp-header-end">';
		Screen::notice();

		if ( null === $run ) {
			echo '<p>' . esc_html__( 'There is no run with this ID. Old finished runs are pruned after 90 days (failed runs after 180).', 'pegwork' ) . '</p>';
			return;
		}

		$automation = pegwork_registry()->automation( $run['automation'] );
		$label      = null === $automation ? __( '(removed)', 'pegwork' ) : $automation['label'];
		$outcome    = Format::outcome( $run );

		echo '<table class="widefat striped pegwork-table pegwork-run">';
		echo '<caption class="screen-reader-text">' . esc_html__( 'Run details', 'pegwork' ) . '</caption><tbody>';
		self::detail(
			__( 'Automation', 'pegwork' ),
			esc_html( $label ) . ' <code>' . esc_html( $run['automation'] ) . '</code> <a href="' . esc_url(
				Screen::url(
					array(
						'view'       => 'log',
						'automation' => $run['automation'],
					)
				)
			) . '">' . esc_html__( 'All its runs', 'pegwork' ) . '</a>'
		);
		self::detail( __( 'Status', 'pegwork' ), Format::badge( $run['status'], Format::run_status( $run['status'] ) ) );
		self::detail( __( 'Trigger', 'pegwork' ), Format::run_trigger( $run ) );
		self::detail( __( 'Record', 'pegwork' ), Format::record( $run['record_id'] ) );
		self::detail( __( 'Current step', 'pegwork' ), '' === (string) $run['current_step'] ? esc_html__( 'None', 'pegwork' ) : '<code>' . esc_html( (string) $run['current_step'] ) . '</code>' );
		self::detail( 'failed' === $run['status'] ? __( 'Error', 'pegwork' ) : __( 'Outcome', 'pegwork' ), '' === $outcome ? esc_html__( 'None', 'pegwork' ) : esc_html( $outcome ) );
		if ( 'waiting' === $run['status'] ) {
			self::detail( __( 'Resumes at', 'pegwork' ), Format::time( $run['resume_at'], __( 'Not set', 'pegwork' ) ) );
		}
		self::detail( __( 'Loop depth', 'pegwork' ), esc_html( number_format_i18n( (int) $run['depth'] ) ) );
		self::detail( __( 'Created', 'pegwork' ), Format::time( $run['created_at'] ) );
		self::detail( __( 'Started', 'pegwork' ), Format::time( $run['started_at'], __( 'Not yet', 'pegwork' ) ) );
		self::detail( __( 'Finished', 'pegwork' ), Format::time( $run['finished_at'], __( 'Not yet', 'pegwork' ) ) );
		echo '</tbody></table>';

		if ( 'failed' === $run['status'] ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pegwork-retry">';
			Screen::form_fields( Actions::RETRY, Actions::RETRY . '_' . $run['id'] );
			echo '<input type="hidden" name="run" value="' . esc_attr( (string) $run['id'] ) . '">';
			echo '<p><button type="submit" class="button button-primary" aria-describedby="pegwork-retry-help">' . esc_html__( 'Retry', 'pegwork' ) . '</button> ';
			echo '<span class="description" id="pegwork-retry-help">' . esc_html__( 'Resumes the run at the step that failed; earlier steps are not repeated.', 'pegwork' ) . '</span></p>';
			echo '</form>';
		}

		$steps = $runs->steps( $run['id'] );
		echo '<h2>' . esc_html__( 'Step attempts', 'pegwork' ) . '</h2>';
		echo '<table class="widefat striped pegwork-table">';
		echo '<caption class="screen-reader-text">' . esc_html__( 'Step attempts, oldest first', 'pegwork' ) . '</caption>';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Step', 'pegwork' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Attempt', 'pegwork' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Status', 'pegwork' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Started', 'pegwork' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Duration', 'pegwork' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Summary', 'pegwork' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Error', 'pegwork' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( array() === $steps ) {
			echo '<tr class="no-items"><td colspan="7">' . esc_html__( 'No step has run yet.', 'pegwork' ) . '</td></tr>';
		}
		$plan     = Plan::for_run( $run, $automation );
		$previous = null;
		$taken    = array(); // Latest decision summary by decision id: the path as it ran.
		$items    = Format::item_headings( $steps, $plan );
		foreach ( array_values( $steps ) as $index => $step ) {
			$step_id = (string) $step['step_id'];
			$depth   = $plan?->depth( $step_id );
			$where   = $plan?->location( $step_id );
			if ( null !== $depth && 'loop' === $step['step_type'] && null !== $step['iteration'] ) {
				++$depth; // A skipped item's row sits with the body.
			}
			if ( isset( $items[ $index ] ) ) {
				$loop = 'loop' === $step['step_type'] ? $step_id : $plan?->loop_of( $step_id );
				$at   = null === $loop ? 0 : (int) $plan?->depth( $loop );
				echo '<tr class="pegwork-branch-row pegwork-item-row"><td colspan="7" class="pegwork-depth-' . esc_attr( (string) $at ) . '"><strong>' . esc_html( $items[ $index ] ) . '</strong></td></tr>';
				$previous = null; // Each item's branches get their heading again.
			}
			if ( null !== $where && $where['first'] && $previous !== $step_id ) {
				$label = $where['label'];
				if ( ( $taken[ $where['decision'] ]['index'] ?? null ) === $where['branch'] && is_string( $taken[ $where['decision'] ]['branch'] ?? null ) ) {
					$label = $taken[ $where['decision'] ]['branch'];
				}
				$text = -1 === $where['branch']
					/* translators: %s: decision step ID. */
					? sprintf( esc_html__( 'Otherwise branch of %s', 'pegwork' ), '<code>' . esc_html( $where['decision'] ) . '</code>' )
					/* translators: 1: branch label, 2: decision step ID. */
					: sprintf( esc_html__( 'Branch %1$s of %2$s', 'pegwork' ), '<strong>' . esc_html( $label ) . '</strong>', '<code>' . esc_html( $where['decision'] ) . '</code>' );
				echo '<tr class="pegwork-branch-row"><td colspan="7" class="pegwork-depth-' . esc_attr( (string) max( 0, (int) $depth - 1 ) ) . '">' . $text . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			}
			if ( 'decision' === $step['step_type'] ) {
				$taken[ $step_id ] = (array) $step['summary'];
			}
			$previous = $step_id;

			$class = null !== $depth && $depth > 0 ? ' class="pegwork-depth-' . esc_attr( (string) $depth ) . '"' : '';
			$gone  = null !== $plan && null === $depth ? '<span class="pegwork-meta">' . esc_html__( '(no longer in this automation)', 'pegwork' ) . '</span>' : '';
			echo '<tr>';
			echo '<th scope="row"' . $class . '><code>' . esc_html( $step_id ) . '</code><span class="pegwork-meta">' . esc_html( (string) $step['step_type'] ) . '</span>' . $gone . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			echo '<td>' . esc_html( number_format_i18n( (int) $step['attempt'] ) ) . '</td>';
			echo '<td>' . Format::badge( (string) $step['status'], Format::step_status( (string) $step['status'] ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Format.
			echo '<td>' . Format::time( (string) $step['started_at'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Format.
			/* translators: %s: duration in milliseconds. */
			echo '<td>' . esc_html( sprintf( __( '%s ms', 'pegwork' ), number_format_i18n( (int) $step['duration_ms'] ) ) ) . '</td>';
			$reads = in_array( $step['step_type'], array( 'get_record', 'find_records' ), true ) ? Format::read_records( (array) $step['summary'] ) : '';
			$wait  = 'wait' === $step['step_type'] ? Format::wait_label( (array) $step['summary'], (int) $step['attempt'] ) : '';
			echo '<td>' . ( '' === $wait ? '' : '<p class="pegwork-wait-label">' . esc_html( $wait ) . '</p>' ) . ( 'decision' === $step['step_type'] ? Format::decision( (array) $step['summary'] ) : Format::summary( (array) $step['summary'] ) ) . $reads . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Format.
			echo '<td>' . esc_html( (string) $step['error'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * URL of one run.
	 *
	 * @param int $id Run ID.
	 */
	public static function run_url( int $id ): string {
		return Screen::url(
			array(
				'view' => 'run',
				'run'  => $id,
			)
		);
	}

	/**
	 * Prints one row of the run details table.
	 *
	 * @param string $label Row header (plain text).
	 * @param string $html  Escaped cell HTML.
	 */
	private static function detail( string $label, string $html ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Callers pass escaped HTML.
	}

	/**
	 * Prints the page links.
	 *
	 * @param int    $paged      Current page.
	 * @param int    $pages      Page count.
	 * @param string $automation Automation filter.
	 * @param string $status     Status filter.
	 */
	private static function pagination( int $paged, int $pages, string $automation, string $status ): void {
		if ( $pages < 2 ) {
			return;
		}
		$args  = array_filter(
			array(
				'view'       => 'log',
				'automation' => $automation,
				'status'     => $status,
			),
			fn( string $value ) => '' !== $value
		);
		$links = paginate_links(
			array(
				'base'               => add_query_arg( 'paged', '%#%', Screen::url( $args ) ),
				'format'             => '',
				'current'            => $paged,
				'total'              => $pages,
				'prev_text'          => __( 'Newer runs', 'pegwork' ),
				'next_text'          => __( 'Older runs', 'pegwork' ),
				'before_page_number' => '<span class="screen-reader-text">' . esc_html__( 'Page', 'pegwork' ) . ' </span>',
			)
		);
		echo '<nav class="pegwork-pages" aria-label="' . esc_attr__( 'Run log pages', 'pegwork' ) . '">';
		echo '<span>' . esc_html(
			/* translators: 1: current page, 2: number of pages. */
			sprintf( __( 'Page %1$s of %2$s', 'pegwork' ), number_format_i18n( $paged ), number_format_i18n( $pages ) )
		) . '</span> ';
		echo wp_kses_post( (string) $links );
		echo '</nav>';
	}
}
