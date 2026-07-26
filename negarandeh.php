<?php
/**
 * Plugin Name:       Negarandeh
 * Plugin URI:        https://hirca.ir
 * Description:       Create unique, SEO-friendly blog posts using AI-powered content generation and custom prompts.
 * Version:           1.1.1
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            M@soud
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       negarandeh
 *
 * @package Negarandeh
 */

defined( 'ABSPATH' ) || exit;

define( 'NEGARANDEH_VERSION', '1.1.1' );
define( 'NEGARANDEH_PLUGIN_FILE', __FILE__ );
define( 'NEGARANDEH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NEGARANDEH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once NEGARANDEH_PLUGIN_DIR . 'includes/class-i18n.php';
require_once NEGARANDEH_PLUGIN_DIR . 'includes/class-plugin.php';
require_once NEGARANDEH_PLUGIN_DIR . 'includes/class-avalai-api.php';
require_once NEGARANDEH_PLUGIN_DIR . 'includes/class-seo-handler.php';
require_once NEGARANDEH_PLUGIN_DIR . 'includes/class-image-handler.php';
require_once NEGARANDEH_PLUGIN_DIR . 'includes/class-post-creator.php';
require_once NEGARANDEH_PLUGIN_DIR . 'includes/class-content-generator.php';
require_once NEGARANDEH_PLUGIN_DIR . 'includes/class-batch-processor.php';
require_once NEGARANDEH_PLUGIN_DIR . 'includes/class-admin.php';

/**
 * Main plugin bootstrap.
 */
final class NEGARANDEH_Bootstrap {

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		register_activation_hook( NEGARANDEH_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( NEGARANDEH_PLUGIN_FILE, array( $this, 'deactivate' ) );

		add_action( 'plugins_loaded', array( $this, 'init' ), 0 );
	}

	public function init(): void {
		NEGARANDEH_I18n::init();
		$this->ensure_default_api_settings();
		$this->maybe_migrate_ui_language_auto();
		NEGARANDEH_Batch_Processor::instance();
		NEGARANDEH_Admin::instance();
	}

	/**
	 * One-time: old installs defaulted to fa — follow WordPress locale instead.
	 */
	private function maybe_migrate_ui_language_auto(): void {
		if ( get_option( 'negarandeh_i18n_fa_to_auto_v2', false ) ) {
			return;
		}

		$saved = get_option( 'negarandeh_settings', array() );
		if ( is_array( $saved ) ) {
			$lang = (string) ( $saved['ui_language'] ?? '' );
			if ( '' === $lang || 'fa' === $lang ) {
				$saved['ui_language'] = 'auto';
				update_option( 'negarandeh_settings', $saved );
			}
		}

		update_option( 'negarandeh_i18n_fa_to_auto_v2', '1', false );
	}

	/**
	 * Ensure API base URL has a default on first run.
	 */
	private function ensure_default_api_settings(): void {
		$saved = get_option( 'negarandeh_settings', array() );
		if ( ! is_array( $saved ) ) {
			return;
		}

		if ( empty( $saved['api_base_url'] ) ) {
			$saved['api_base_url'] = NEGARANDEH_Avalai_API::DEFAULT_BASE_URL;
			update_option( 'negarandeh_settings', $saved );
		}
	}

	public function activate(): void {
		NEGARANDEH_Batch_Processor::remove_invalid_recurring_cron_static();

		if ( false === get_option( 'negarandeh_settings', false ) ) {
			update_option( 'negarandeh_settings', NEGARANDEH_Avalai_API::get_settings() );
		}

		$gen = get_option( 'negarandeh_generator_settings', array() );
		if ( is_array( $gen ) && 'wp_cron' === ( $gen['queue_driver'] ?? 'wp_cron' ) && ! empty( $gen['automation_enabled'] ) ) {
			NEGARANDEH_Batch_Processor::schedule_auto_cron();
		}
	}

	public function deactivate(): void {
		wp_clear_scheduled_hook( NEGARANDEH_Batch_Processor::MANUAL_QUEUE_HOOK );
		wp_clear_scheduled_hook( NEGARANDEH_Batch_Processor::FEATURED_IMAGE_HOOK );
		NEGARANDEH_Batch_Processor::unschedule_auto_cron();
		delete_option( NEGARANDEH_Batch_Processor::LOCK_OPTION );
		delete_option( NEGARANDEH_Batch_Processor::IMAGE_LOCK_OPTION );
	}
}

add_filter(
	'cron_schedules',
	static function ( array $schedules ): array {
		for ( $minutes = 1; $minutes <= 5; $minutes++ ) {
			$schedules[ 'negarandeh_every_' . $minutes . '_minutes' ] = array(
				'interval' => $minutes * MINUTE_IN_SECONDS,
				/* translators: %d: number of minutes between cron runs */
				'display'  => sprintf( __( 'هر %d دقیقه (نگارنده)', 'negarandeh' ), $minutes ),
			);
		}

		$schedules['negarandeh_every_five_minutes'] = $schedules['negarandeh_every_5_minutes'];

		return $schedules;
	}
);

NEGARANDEH_Bootstrap::instance();
