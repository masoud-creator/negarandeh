<?php
/**
 * Uninstall Negarandeh — remove plugin settings only (posts and media are kept).
 *
 * @package Negarandeh
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Delete options, transients, and cron jobs for the current site.
 */
function negarandeh_uninstall_cleanup(): void {
	global $wpdb;

	$options = array(
		'negarandeh_settings',
		'negarandeh_generator_settings',
		'negarandeh_generation_queue',
		'negarandeh_permanent_log',
		'negarandeh_topic_status',
		'negarandeh_queue_processing_lock',
		'negarandeh_cron_topic_index',
		'negarandeh_schedule_post_index',
		'negarandeh_schedule_base_timestamp',
		'negarandeh_i18n_fa_to_auto_v2',
	);

	foreach ( $options as $option_name ) {
		delete_option( $option_name );
	}

	// Catch any future or legacy options stored under the same prefix.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'negarandeh_' ) . '%'
		)
	);

	delete_transient( 'negarandeh_queue_processing_lock' );

	$transient_like        = $wpdb->esc_like( '_transient_negarandeh_' ) . '%';
	$transient_timeout_like = $wpdb->esc_like( '_transient_timeout_negarandeh_' ) . '%';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$transient_like,
			$transient_timeout_like
		)
	);

	negarandeh_uninstall_clear_cron();
}

/**
 * Remove all WP-Cron events registered by this plugin.
 */
function negarandeh_uninstall_clear_cron(): void {
	$hooks = array(
		'negarandeh_auto_generate',
		'negarandeh_process_queue',
		'negarandeh_generate_featured_image',
		'negarandeh_hourly_generate',
	);

	foreach ( $hooks as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}

	$crons = _get_cron_array();
	if ( ! is_array( $crons ) ) {
		return;
	}

	$changed = false;

	foreach ( $crons as $timestamp => $hooks_at_time ) {
		if ( ! is_array( $hooks_at_time ) ) {
			continue;
		}

		foreach ( array_keys( $hooks_at_time ) as $hook_name ) {
			if ( is_string( $hook_name ) && 0 === strpos( $hook_name, 'negarandeh_' ) ) {
				unset( $crons[ $timestamp ][ $hook_name ] );
				$changed = true;
			}
		}

		if ( empty( $crons[ $timestamp ] ) ) {
			unset( $crons[ $timestamp ] );
		}
	}

	if ( $changed ) {
		_set_cron_array( $crons );
	}
}

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		negarandeh_uninstall_cleanup();
		restore_current_blog();
	}
} else {
	negarandeh_uninstall_cleanup();
}
