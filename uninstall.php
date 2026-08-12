<?php
/**
 * Uninstall handler.
 *
 * Runs when the plugin is deleted (not on deactivation).
 *
 * Two things happen here, and only one of them is optional. Scheduled events and
 * capabilities are ALWAYS removed: a cron event left pointing at a deleted
 * plugin fires forever with nothing to handle it, and a capability nobody can
 * use is just confusing. Scan data is removed only if the site asked for that on
 * the Settings tab, so a plugin removed for troubleshooting can be reinstalled
 * with its history intact.
 *
 * @package YokoLinkChecker
 * @since   1.0.0
 */

// Exit if not uninstalling. WordPress only defines this from delete_plugins(),
// which already requires the delete_plugins capability.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Wrap in closure to avoid global namespace pollution.
( function () {
	global $wpdb;

	/**
	 * Always: clear scheduled hooks.
	 *
	 * These must go whatever the data setting says. WP-Cron keeps firing events
	 * whose handler no longer exists, which shows up as recurring cron noise on
	 * a site that no longer has the plugin.
	 */
	wp_clear_scheduled_hook( 'yoko_lc_process_scan_batch' );
	wp_clear_scheduled_hook( 'yoko_lc_auto_scan' );

	/**
	 * Always: remove capabilities.
	 *
	 * Only from the role Activator::set_capabilities() grants them to. Removing
	 * them from roles we never granted would be reaching into configuration a
	 * site owner made deliberately.
	 */
	$capabilities = array(
		'yoko_lc_manage_scans',
		'yoko_lc_view_results',
		'yoko_lc_manage_settings',
	);

	$admin_role = get_role( 'administrator' );

	if ( $admin_role ) {
		foreach ( $capabilities as $cap ) {
			$admin_role->remove_cap( $cap );
		}
	}

	/**
	 * Always: clear the scan lock, which is meaningless without the plugin.
	 */
	delete_transient( 'yoko_lc_scan_lock' );

	/**
	 * Optional: destroy scan data.
	 *
	 * Defaults to true, matching the checkbox default on the Settings tab. The
	 * option itself is deleted below only on this branch -- if the site chose to
	 * keep its data, the choice is kept with it so a reinstall remembers it.
	 */
	if ( ! get_option( 'yoko_lc_remove_data_on_uninstall', true ) ) {
		return;
	}

	/**
	 * Remove custom database tables.
	 */
	$tables = array(
		$wpdb->prefix . 'yoko_lc_links',
		$wpdb->prefix . 'yoko_lc_urls',
		$wpdb->prefix . 'yoko_lc_scans',
	);

	foreach ( $tables as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	/**
	 * Remove options.
	 */
	$options = array(
		'yoko_lc_schema_version',
		'yoko_lc_activated_at',
		'yoko_lc_remove_data_on_uninstall',
		'yoko_lc_auto_scan_enabled',
		'yoko_lc_auto_scan_frequency',
		'yoko_lc_post_types',
		'yoko_lc_check_timeout',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Remove scan cursor and last-activity options (dynamic keys).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'yoko_lc_scan_' ) . '%'
		)
	);

	/**
	 * Clear remaining transients.
	 */
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_yoko_lc_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_yoko_lc_' ) . '%'
		)
	);
} )();
