<?php
/**
 * Public API constants. Required by pegwork.php right after the Composer autoloader.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Semver of the public `Pegwork\Api` surface. Everything outside `Pegwork\Api` is internal.
 */
const VERSION = '0.3.0';

/**
 * Slug of the top-level Pegwork admin menu. Add screens with `add_submenu_page( Pegwork\Api\ADMIN_MENU, ... )`.
 */
const ADMIN_MENU = 'pegwork';
