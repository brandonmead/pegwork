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
use Pegwork\Content\Registrar;
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
	 * Package directories added on the last init, in registration order.
	 *
	 * @var string[]
	 */
	private static array $packages = array();

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
		Admin\EditorPanel::register();
		Admin\Menu::register();
		Options\Screen::register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'pegwork schema', SchemaCommand::class );
			\WP_CLI::add_command( 'pegwork relationship', Cli\RelationshipCommand::class );
			\WP_CLI::add_command( 'pegwork options', Cli\OptionsCommand::class );
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
	 * Builds the registry on init, priority 5, then registers its content.
	 */
	public static function init(): void {
		$builder = new RegistryBuilder();

		/**
		 * Fires so packages can add schema sources before the registry loads.
		 *
		 * @param RegistryBuilder $builder Call $builder->add_package( $dir ) for each package.
		 */
		do_action( 'pegwork_register', $builder );

		$db             = get_option( Loader::DB_OPTION, array() );
		self::$packages = $builder->packages();
		self::$result   = Loader::load( self::$packages, is_array( $db ) ? $db : array() );

		Registrar::register( self::$result->registry );

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
	 * Package directories added through pegwork_register on the last init. Empty before init.
	 *
	 * @return string[]
	 */
	public static function packages(): array {
		return self::$packages;
	}

	/**
	 * The effective registry. Empty before init.
	 */
	public static function registry(): Registry {
		return self::result()->registry;
	}
}
