<?php
/**
 * Uninstall file for ML Gallery Migrator Pro.
 * 
 * This file is run when the plugin is deleted from the WordPress admin.
 * It removes all plugin-specific data: tables, options, and logs.
 * 
 * @package MLGalleryMigratorPro
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/**
 * 1. Remove database tables
 */
$tables = [
	$wpdb->prefix . 'ml_gallery_migration_map',
	$wpdb->prefix . 'ml_gallery_migration_logs',
];

foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS $table" );
}

/**
 * 2. Remove options from wp_options
 */
$options = [
	'mlgmp_state',
	'mlgmp_pending_gallery_covers',
	'mlgmp_pending_album_covers',
];

foreach ( $options as $option ) {
	delete_option( $option );
}

/**
 * 3. Note: We DO NOT remove physical files in /wp-content/ml-gallery/
 * because they are considered migrated assets belonging to the user's library.
 * The plugin is a migrator; the assets stay.
 */
