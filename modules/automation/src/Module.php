<?php
/**
 * Automation module entry point.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation;

use Pegwork\Api\Module as ModuleContract;

/**
 * Installs the run log tables, listens on the event bus, executes runs on scheduler wake-ups and
 * registers `wp pegwork automation` and Pegwork > Automations. Nothing runs while the module is disabled.
 */
final class Module implements ModuleContract {

	/**
	 * The manifest slug.
	 */
	public function id(): string {
		return 'automation';
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
		Engine::register();
		Links\Handler::register();
		Admin\Screen::register();
		Webhooks\Inbound::register();

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'pegwork automation', Cli\AutomationCommand::class );
		}
	}
}
