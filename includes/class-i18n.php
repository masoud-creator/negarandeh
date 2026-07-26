<?php
/**
 * Plugin UI language (Persian / English) and RTL/LTR layout.
 *
 * @package Negarandeh
 */

defined( 'ABSPATH' ) || exit;

class NEGARANDEH_I18n {

	public const LANG_FA   = 'fa';
	public const LANG_EN   = 'en';
	public const LANG_AUTO = 'auto';

	/** @var array<string,string>|null */
	private static $en_map = null;

	public static function init(): void {
		add_filter( 'gettext_negarandeh', array( __CLASS__, 'filter_gettext' ), 10, 3 );
		add_filter( 'ngettext_negarandeh', array( __CLASS__, 'filter_ngettext' ), 10, 5 );
		add_filter( 'plugin_locale', array( __CLASS__, 'filter_plugin_locale' ), 10, 2 );
		add_filter( 'admin_body_class', array( __CLASS__, 'admin_body_class' ) );
	}

	/**
	 * Use English locale for this text domain when UI language is English.
	 *
	 * @param string $locale Current locale.
	 * @param string $domain Text domain.
	 */
	public static function filter_plugin_locale( string $locale, string $domain ): string {
		if ( 'negarandeh' === $domain && self::LANG_EN === self::get_lang() ) {
			return 'en_US';
		}

		return $locale;
	}

	/**
	 * @return string fa|en
	 */
	public static function get_lang(): string {
		$pref = self::get_preference();

		if ( self::LANG_AUTO === $pref ) {
			$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
			return ( 0 === strpos( $locale, 'en' ) ) ? self::LANG_EN : self::LANG_FA;
		}

		return self::LANG_EN === $pref ? self::LANG_EN : self::LANG_FA;
	}

	public static function get_preference(): string {
		$saved = get_option( 'negarandeh_settings', array() );
		$pref  = is_array( $saved ) ? (string) ( $saved['ui_language'] ?? '' ) : '';

		if ( '' === $pref ) {
			return self::LANG_AUTO;
		}

		return in_array( $pref, array( self::LANG_FA, self::LANG_EN, self::LANG_AUTO ), true )
			? $pref
			: self::LANG_AUTO;
	}

	/**
	 * True when WordPress (or site) locale is an English variant.
	 */
	public static function is_wp_locale_english(): bool {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();

		return 0 === strpos( (string) $locale, 'en' );
	}

	public static function is_rtl(): bool {
		return self::LANG_FA === self::get_lang();
	}

	public static function get_direction(): string {
		return self::is_rtl() ? 'rtl' : 'ltr';
	}

	/**
	 * Opening attributes for admin page wrapper.
	 */
	public static function wrap_attrs( string $extra_class = '' ): string {
		$classes = trim( 'wrap negarandeh-wrap ' . ( self::is_rtl() ? 'negarandeh-rtl' : 'negarandeh-ltr' ) . ' ' . $extra_class );

		return sprintf(
			'class="%s" dir="%s" lang="%s"',
			esc_attr( $classes ),
			esc_attr( self::get_direction() ),
			esc_attr( self::get_lang() )
		);
	}

	/**
	 * @param string $classes Existing admin body classes.
	 */
	public static function admin_body_class( string $classes ): string {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return $classes;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return $classes;
		}

		if ( false === strpos( (string) $screen->id, NEGARANDEH_Plugin::SLUG ) ) {
			return $classes;
		}

		return trim( $classes . ' negarandeh-admin-ui-' . self::get_lang() . ' ' . ( self::is_rtl() ? 'negarandeh-admin-rtl' : 'negarandeh-admin-ltr' ) );
	}

	/**
	 * @param string $text Original (Persian) msgid.
	 */
	public static function filter_gettext( string $translated, string $text, string $domain ): string {
		if ( self::LANG_EN !== self::get_lang() ) {
			return $translated;
		}

		$map = self::get_en_map();

		return $map[ $text ] ?? $translated;
	}

	/**
	 * Plural forms (e.g. _n) — map via the same Persian→English dictionary.
	 *
	 * @param string $translation Current translation.
	 * @param string $single      Singular msgid.
	 * @param string $plural      Plural msgid.
	 * @param int    $number      Number.
	 * @param string $domain      Text domain.
	 */
	public static function filter_ngettext( string $translation, string $single, string $plural, int $number, string $domain ): string {
		if ( self::LANG_EN !== self::get_lang() ) {
			return $translation;
		}

		$map = self::get_en_map();
		$key = ( 1 === (int) $number ) ? $single : $plural;

		if ( isset( $map[ $key ] ) ) {
			return $map[ $key ];
		}

		if ( isset( $map[ $single ] ) ) {
			return $map[ $single ];
		}

		return $translation;
	}

	/**
	 * @return array<string,string>
	 */
	public static function get_en_map(): array {
		if ( null === self::$en_map ) {
			$file = NEGARANDEH_PLUGIN_DIR . 'languages/en.php';
			self::$en_map = is_readable( $file ) ? (array) include $file : array();
		}

		return self::$en_map;
	}
}
