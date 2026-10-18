<?php
/**
 * Automations list view.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Admin;

use PegworkAutomation\Engine;
use PegworkAutomation\Overrides;

/**
 * Every registered automation with its trigger, source, enabled state (JSON default and site override),
 * last run, open and recent failed runs, next scheduled firing, and its actions. The same data as
 * `wp pegwork automation list`.
 */
final class AutomationList {

	/**
	 * Prints the view.
	 */
	public static function render(): void {
		$registry    = pegwork_registry();
		$automations = $registry->automations();
		$engine      = Engine::instance();
		$summary     = $engine->runs()->summary( $engine->runs()->stamp( $engine->clock()->now()->modify( '-7 days' ) ) );

		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Automations', 'pegwork' ) . '</h1> ';
		echo '<a class="page-title-action" href="' . esc_url( Screen::url( array( 'view' => 'log' ) ) ) . '">' . esc_html__( 'View run log', 'pegwork' ) . '</a>';
		echo '<hr class="wp-header-end">';
		Screen::notice();
		echo '<p>' . esc_html__( 'Automations come from the schema registry and are edited as JSON. Here you can switch one on or off for this site, run a schedule now, and follow its runs.', 'pegwork' ) . '</p>';

		// Not wp-list-table: its small-screen CSS stacks cells without their headers.
		echo '<table class="widefat striped pegwork-table">';
		echo '<caption class="screen-reader-text">' . esc_html__( 'Automations', 'pegwork' ) . '</caption>';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Automation', 'pegwork' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Trigger', 'pegwork' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Enabled', 'pegwork' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Last run', 'pegwork' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Next run', 'pegwork' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Actions', 'pegwork' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( array() === $automations ) {
			echo '<tr class="no-items"><td colspan="6">' . esc_html__( 'No automations registered. Add an automations section to a schema package (see the registry reference) or import one with wp pegwork schema import.', 'pegwork' ) . '</td></tr>';
		}

		foreach ( $automations as $key => $definition ) {
			$key        = (string) $key;
			$is_enabled = Overrides::enabled( $definition );
			$schedule   = 'schedule' === $definition['trigger']['type'];

			echo '<tr>';
			echo '<th scope="row"><strong>' . esc_html( $definition['label'] ) . '</strong><br><code>' . esc_html( $key ) . '</code>';
			$source = Format::source( $registry->automation_source( $key ) );
			if ( '' !== $source ) {
				echo '<span class="pegwork-meta">' . esc_html( $source ) . '</span>';
			}
			echo '</th>';
			echo '<td>' . esc_html( Format::trigger( $definition['trigger'] ) ) . '</td>';
			echo '<td>' . self::enabled( $key, $definition, $is_enabled ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in enabled().
			echo '<td>' . self::last_run( $summary[ $key ] ?? array() ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in last_run().
			echo '<td>' . self::next_run( $key, $schedule, $is_enabled, $engine ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in next_run().
			echo '<td class="pegwork-actions">';
			self::actions( $key, (string) $definition['label'], $schedule, $is_enabled );
			echo '</td></tr>';
		}

		echo '</tbody></table>';
		echo '<p class="pegwork-cli-note">';
		printf(
			/* translators: %s: CLI command. */
			esc_html__( 'The same rules apply to %s. Times are in the site time zone.', 'pegwork' ),
			'<code>wp pegwork automation enable|disable|run|retry</code>'
		);
		echo '</p>';
	}

	/**
	 * The enabled cell: effective state, then the JSON default and the site override.
	 *
	 * @param string               $key        Automation key.
	 * @param array<string, mixed> $definition Canonical automation.
	 * @param bool                 $is_enabled Effective state.
	 */
	private static function enabled( string $key, array $definition, bool $is_enabled ): string {
		$json     = ( $definition['enabled'] ?? true ) ? __( 'on', 'pegwork' ) : __( 'off', 'pegwork' );
		$override = Overrides::get( $key );
		$detail   = null === $override
			/* translators: %s: on or off. */
			? sprintf( __( 'JSON default %s, no site override', 'pegwork' ), $json )
			/* translators: 1: on or off (JSON default), 2: on or off (site override). */
			: sprintf( __( 'JSON default %1$s, site override %2$s', 'pegwork' ), $json, 'on' === $override ? __( 'on', 'pegwork' ) : __( 'off', 'pegwork' ) );

		return Format::badge( $is_enabled ? 'on' : 'off', $is_enabled ? __( 'Enabled', 'pegwork' ) : __( 'Disabled', 'pegwork' ) )
			. '<span class="pegwork-meta">' . esc_html( $detail ) . '</span>';
	}

	/**
	 * The last run cell: status and time of the latest run, then open runs and failures in 7 days.
	 *
	 * @param array<string, mixed> $stats Row of Runs::summary(), or empty.
	 */
	private static function last_run( array $stats ): string {
		if ( ! isset( $stats['last_status'] ) || '' === $stats['last_status'] ) {
			$out = esc_html__( 'Never run', 'pegwork' );
		} else {
			$out = Format::badge( (string) $stats['last_status'], Format::run_status( (string) $stats['last_status'] ) ) . ' ' . Format::time( (string) $stats['last_at'] );
		}

		$open   = (int) ( $stats['queued'] ?? 0 ) + (int) ( $stats['running'] ?? 0 ) + (int) ( $stats['waiting'] ?? 0 );
		$failed = (int) ( $stats['failed'] ?? 0 );
		if ( $open > 0 ) {
			/* translators: %s: number of queued, running or waiting runs. */
			$out .= '<span class="pegwork-meta">' . esc_html( sprintf( _n( '%s open run', '%s open runs', $open, 'pegwork' ), number_format_i18n( $open ) ) ) . '</span>';
		}
		if ( $failed > 0 ) {
			/* translators: %s: number of failed runs. */
			$out .= '<span class="pegwork-meta">' . esc_html( sprintf( _n( '%s failed in the last 7 days', '%s failed in the last 7 days', $failed, 'pegwork' ), number_format_i18n( $failed ) ) ) . '</span>';
		}
		return $out;
	}

	/**
	 * The next run cell: the pending schedule firing.
	 *
	 * @param string $key        Automation key.
	 * @param bool   $schedule   Whether it has a schedule trigger.
	 * @param bool   $is_enabled Effective state.
	 * @param Engine $engine     Engine.
	 */
	private static function next_run( string $key, bool $schedule, bool $is_enabled, Engine $engine ): string {
		if ( ! $schedule ) {
			return esc_html__( 'When its event fires', 'pegwork' );
		}
		$next = $engine->scheduler()->next( Engine::SCHEDULE_HOOK, array( $key ) );
		if ( ! $is_enabled ) {
			return esc_html__( 'None while disabled', 'pegwork' );
		}
		return null === $next ? esc_html__( 'Scheduled on the next page load', 'pegwork' ) : Format::timestamp( $next );
	}

	/**
	 * The actions cell: Enable/Disable, Run now (schedules only), and a link to the automation's runs.
	 *
	 * @param string $key        Automation key.
	 * @param string $label      Automation label.
	 * @param bool   $schedule   Whether it has a schedule trigger.
	 * @param bool   $is_enabled Effective state.
	 */
	private static function actions( string $key, string $label, bool $schedule, bool $is_enabled ): void {
		$name = '<span class="screen-reader-text"> ' . esc_html( $label ) . '</span>';
		$post = esc_url( admin_url( 'admin-post.php' ) );

		echo '<form method="post" action="' . $post . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		Screen::form_fields( Actions::TOGGLE, Actions::TOGGLE . '_' . $key );
		echo '<input type="hidden" name="automation" value="' . esc_attr( $key ) . '">';
		echo '<input type="hidden" name="op" value="' . esc_attr( $is_enabled ? 'disable' : 'enable' ) . '">';
		echo '<button type="submit" class="' . esc_attr( $is_enabled ? 'button' : 'button button-primary' ) . '">' . esc_html( $is_enabled ? __( 'Disable', 'pegwork' ) : __( 'Enable', 'pegwork' ) ) . $name . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $name is escaped above.
		echo '</form>';

		if ( $schedule ) {
			if ( $is_enabled ) {
				echo '<form method="post" action="' . $post . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				Screen::form_fields( Actions::RUN, Actions::RUN . '_' . $key );
				echo '<input type="hidden" name="automation" value="' . esc_attr( $key ) . '">';
				echo '<button type="submit" class="button">' . esc_html__( 'Run now', 'pegwork' ) . $name . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $name is escaped above.
				echo '</form>';
			} else {
				$id = 'pegwork-run-reason-' . sanitize_html_class( $key );
				echo '<button type="button" class="button disabled" aria-disabled="true" aria-describedby="' . esc_attr( $id ) . '">' . esc_html__( 'Run now', 'pegwork' ) . $name . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $name is escaped above.
				echo '<span class="pegwork-reason description" id="' . esc_attr( $id ) . '">' . esc_html__( 'Enable it to run it now.', 'pegwork' ) . '</span>';
			}
		}

		echo '<a class="pegwork-runs-link" href="' . esc_url(
			Screen::url(
				array(
					'view'       => 'log',
					'automation' => $key,
				)
			)
		) . '">' . esc_html__( 'View runs', 'pegwork' ) . '<span class="screen-reader-text"> ' . esc_html(
			/* translators: %s: automation label. */
			sprintf( __( 'of %s', 'pegwork' ), $label )
		) . '</span></a>';
	}
}
