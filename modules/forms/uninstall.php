<?php
/**
 * Forms module uninstall, included by Pegwork's uninstall.php: clears the retention cleanup event.
 * The entries table stays (entries are content); options are removed by the core uninstall (pegwork_ prefix).
 *
 * @package Pegwork
 */

defined( 'ABSPATH' ) || exit;
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_unschedule_hook( 'pegwork_forms_cleanup' );
