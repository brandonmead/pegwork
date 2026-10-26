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
	 * Option set to '1' when activation is pending for fire_activated(), '0' after.
	 */
	public const ACTIVATED_OPTION = 'pegwork_activated';

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
		add_action( 'wp_loaded', array( self::class, 'fire_activated' ) );
		add_action( 'admin_notices', array( Notices::class, 'render' ) );
		Admin\EditorPanel::register();
		Admin\Menu::register();
		Admin\SiteHealth::register();
		Options\Screen::register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'pegwork schema', SchemaCommand::class );
			\WP_CLI::add_command( 'pegwork relationship', Cli\RelationshipCommand::class );
			\WP_CLI::add_command( 'pegwork options', Cli\OptionsCommand::class );
		}
	}

	/**
	 * Activation hook: clears the registry hash, so the next load syncs (seeds terms, flushes rewrite rules), and
	 * marks activation pending for fire_activated(). Runs before Pegwork's content and modules are loaded.
	 */
	public static function activate(): void {
		delete_option( Registrar::HASH_OPTION );
		self::mark_activated();
	}

	/**
	 * Deactivation hook: drops the saved rewrite rules, so WordPress rebuilds them without Pegwork's types (a
	 * flush here would keep them, since they are registered in this request), and clears the registry hash.
	 */
	public static function deactivate(): void {
		delete_option( 'rewrite_rules' );
		delete_option( Registrar::HASH_OPTION );
	}

	/**
	 * Marks activation pending: the next full load fires pegwork_activated. Called on plugin activation and
	 * when a module is enabled.
	 */
	public static function mark_activated(): void {
		update_option( self::ACTIVATED_OPTION, '1', true );
	}

	/**
	 * Fires pegwork_activated once after activation or a module enable, when modules have booted. The option
	 * stays (as '0') so the check costs no query. Hooked to wp_loaded.
	 */
	public static function fire_activated(): void {
		$flag = get_option( self::ACTIVATED_OPTION );
		if ( false === $flag ) {
			add_option( self::ACTIVATED_OPTION, '0', '', true );
			return;
		}
		if ( '1' !== (string) $flag ) {
			return;
		}
		update_option( self::ACTIVATED_OPTION, '0', true );

		/**
		 * Fires once on the first load after Pegwork is activated or a module is enabled, after modules boot.
		 * Modules recover state that went stale while they were not running (automation reschedules lost wake-ups).
		 */
		do_action( 'pegwork_activated' );
	}

	/**
	 * Display name of the plugin.
	 */
	public static function name(): string {
		return __( 'Pegwork Platform for WordPress', 'pegwork' );
	}

	/**
	 * Runs once all plugins are loaded: installs the rate limit table when needed, loads the enabled modules
	 * (they boot on pegwork_loaded) and registers the CLI commands.
	 */
	public static function plugins_loaded(): void {
		Throttle\Schema::maybe_install();
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
