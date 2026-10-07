<?php
/**
 * Remove plugin data on uninstall.
 *
 * @package MBR_Critical_CSS
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'mbr_ccss_settings' );
wp_clear_scheduled_hook( 'mbr_ccss_cleanup' );

// Rate-limit counters and job slots.
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'mbr\\_ccss\\_rl\\_%' OR option_name LIKE 'mbr\\_ccss\\_slot\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
