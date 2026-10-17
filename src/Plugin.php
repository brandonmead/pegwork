<?php
/**
 * Plugin bootstrap.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork;

use Pegwork\Admin\Notices;
use Pegwork\Api\Registry;
use Pegwork\Api\RegistryBuilder;
use Pegwork\Cli\SchemaCommand;
use Pegwork\Registry\Loader;
use Pegwork\Registry\Result;

/**
 * Wires Pegwork into WordPress. Content and module loading arrive in later PRs.
 */
final class Plugin {

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Outcome of the last registry load, null before init.
	 *
	 * @var Result|null
	 */
	private static ?Result $result = null;

	/**
	 * Registers the plugin's WordPress hooks. Safe to call more than once.
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_action( 'plugins_loaded', array( self::class, 'plugins_loaded' ) );
		add_action( 'init', array( self::class, 'init' ), 5 );
		add_action( 'admin_notices', array( Notices::class, 'render' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'pegwork schema', SchemaCommand::class );
		}
	}

	/**
	 * Display name of the plugin.
	 */
	public static function name(): string {
		return __( 'Pegwork Platform for WordPress', 'pegwork' );
	}

	/**
	 * Runs once all plugins are loaded: loads the enabled modules (they boot on pegwork_loaded) and registers the CLI commands.
	 */
	public static function plugins_loaded(): void {
		Modules\Loader::load();
		self::register_module_cli();
	}

	/**
	 * Registers `wp pegwork module` when running under WP-CLI.
	 */
	private static function register_module_cli(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'pegwork module', Cli\ModuleCommand::class );
		}
	}

	/**
	 * Builds the registry on init, priority 5. Content registration (PR 3) follows here.
	 */
	public static function init(): void {
		$builder = new RegistryBuilder();

		/**
		 * Fires so packages can add schema sources before the registry loads.
		 *
		 * @param RegistryBuilder $builder Call $builder->add_package( $dir ) for each package.
		 */
		do_action( 'pegwork_register', $builder );

		$db           = get_option( Loader::DB_OPTION, array() );
		self::$result = Loader::load( $builder->packages(), is_array( $db ) ? $db : array() );

		/**
		 * Fires after Pegwork has registered its content.
		 */
		do_action( 'pegwork_loaded' );
	}

	/**
	 * Outcome of the last registry load. Empty before init.
	 */
	public static function result(): Result {
		return self::$result ?? new Result( new Registry() );
	}

	/**
	 * The effective registry. Empty before init.
	 */
	public static function registry(): Registry {
		return self::result()->registry;
	}
}
