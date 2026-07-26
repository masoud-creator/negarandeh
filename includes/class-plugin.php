<?php
/**
 * Plugin branding and shared helpers.
 *
 * @package Negarandeh
 */

defined( 'ABSPATH' ) || exit;

class NEGARANDEH_Plugin {

	public static function display_name(): string {
		return NEGARANDEH_I18n::LANG_EN === NEGARANDEH_I18n::get_lang() ? 'Negarandeh' : __( 'نگارنده', 'negarandeh' );
	}

	public const VERSION     = '1.1.1';
	public const TEXT_DOMAIN = 'negarandeh';
	public const SLUG        = 'negarandeh';
	public const SLUG_SETTINGS = 'negarandeh-settings';
	public const SLUG_LOG      = 'negarandeh-log';
	public const SLUG_GUIDE    = 'negarandeh-guide';

	/**
	 * Default topic list for new installs.
	 */
	public static function default_topics_list(): string {
		if ( NEGARANDEH_I18n::LANG_EN === NEGARANDEH_I18n::get_lang() ) {
			return "Machine learning\nPython for beginners\nContent SEO\nAI in business";
		}

		return "یادگیری ماشین\nپایتون برای مبتدیان\nسئو محتوا\nهوش مصنوعی در کسب‌وکار";
	}
}
