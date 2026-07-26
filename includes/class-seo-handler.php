<?php
/**
 * SEO meta handler — Yoast SEO, Rank Math, All in One SEO, and built-in fallback.
 *
 * @package Negarandeh
 */

defined( 'ABSPATH' ) || exit;

class NEGARANDEH_SEO_Handler {

	/**
	 * Supported SEO plugins (slug => display name).
	 *
	 * @return array<string,string>
	 */
	public static function get_supported_plugins(): array {
		return array(
			'yoast'   => 'Yoast SEO',
			'rankmath'=> 'Rank Math',
			'aioseo'  => 'All in One SEO',
		);
	}

	/**
	 * Detect active supported SEO plugin slug, or empty string.
	 */
	public static function detect_active_plugin(): string {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}

		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return 'rankmath';
		}

		if ( defined( 'AIOSEO_VERSION' ) || defined( 'AIOSEOP_VERSION' ) || class_exists( '\AIOSEO\Plugin\Common\Models\Post' ) ) {
			return 'aioseo';
		}

		return '';
	}

	/**
	 * Human-readable name of the active SEO plugin, or empty if none.
	 */
	public static function get_active_plugin_name(): string {
		$slug     = self::detect_active_plugin();
		$plugins  = self::get_supported_plugins();

		return '' !== $slug && isset( $plugins[ $slug ] ) ? $plugins[ $slug ] : '';
	}

	/**
	 * Comma-separated list of supported plugin names.
	 */
	public static function get_supported_plugins_list_text(): string {
		return implode( '، ', array_values( self::get_supported_plugins() ) );
	}

	/**
	 * Apply SEO metadata to a post.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $seo     SEO data from AI.
	 */
	public static function apply( int $post_id, array $seo ): void {
		$title       = sanitize_text_field( $seo['meta_title'] ?? '' );
		$description = sanitize_textarea_field( $seo['meta_description'] ?? '' );
		$keyword     = sanitize_text_field( $seo['focus_keyword'] ?? '' );
		$slug        = sanitize_title( $seo['slug'] ?? '' );

		if ( $slug ) {
			wp_update_post(
				array(
					'ID'        => $post_id,
					'post_name' => $slug,
				)
			);
		}

		$active = self::detect_active_plugin();

		if ( 'yoast' === $active ) {
			update_post_meta( $post_id, '_yoast_wpseo_title', $title );
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', $description );
			update_post_meta( $post_id, '_yoast_wpseo_focuskw', $keyword );
		}

		if ( 'rankmath' === $active ) {
			update_post_meta( $post_id, 'rank_math_title', $title );
			update_post_meta( $post_id, 'rank_math_description', $description );
			update_post_meta( $post_id, 'rank_math_focus_keyword', $keyword );
		}

		if ( 'aioseo' === $active ) {
			self::apply_aioseo( $post_id, $title, $description, $keyword );
		}

		// Built-in fallback (always stored for front-end output when no SEO plugin).
		update_post_meta( $post_id, '_negarandeh_meta_title', $title );
		update_post_meta( $post_id, '_negarandeh_meta_description', $description );
		update_post_meta( $post_id, '_negarandeh_focus_keyword', $keyword );
		update_post_meta( $post_id, '_negarandeh_og_title', $title );
		update_post_meta( $post_id, '_negarandeh_og_description', $description );
	}

	/**
	 * @param int    $post_id     Post ID.
	 * @param string $title       Meta title.
	 * @param string $description Meta description.
	 * @param string $keyword     Focus keyword.
	 */
	private static function apply_aioseo( int $post_id, string $title, string $description, string $keyword ): void {
		if ( class_exists( '\AIOSEO\Plugin\Common\Models\Post' ) ) {
			$aioseo_post = \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id );
			if ( ! $aioseo_post ) {
				$aioseo_post          = new \AIOSEO\Plugin\Common\Models\Post();
				$aioseo_post->post_id = $post_id;
			}
			$aioseo_post->title       = $title;
			$aioseo_post->description = $description;
			if ( $keyword && property_exists( $aioseo_post, 'keywords' ) ) {
				$aioseo_post->keywords = $keyword;
			}
			$aioseo_post->save();
		}

		// Post meta fallback (AIOSEO legacy / import paths).
		update_post_meta( $post_id, '_aioseo_title', $title );
		update_post_meta( $post_id, '_aioseo_description', $description );
		update_post_meta( $post_id, '_aioseo_keywords', $keyword );

		// All in One SEO Pack (classic).
		update_post_meta( $post_id, '_aioseop_title', $title );
		update_post_meta( $post_id, '_aioseop_description', $description );
		update_post_meta( $post_id, '_aioseop_keywords', $keyword );
	}

	/**
	 * Register front-end meta output when no SEO plugin handles it.
	 */
	public static function register_hooks(): void {
		add_action( 'wp_head', array( __CLASS__, 'output_meta_tags' ), 1 );
	}

	public static function output_meta_tags(): void {
		if ( ! is_singular( 'post' ) ) {
			return;
		}

		if ( '' !== self::detect_active_plugin() ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		$title       = get_post_meta( $post_id, '_negarandeh_meta_title', true );
		$description = get_post_meta( $post_id, '_negarandeh_meta_description', true );

		if ( ! $title && ! $description ) {
			return;
		}

		if ( $description ) {
			echo '<meta name="description" content="' . esc_attr( $description ) . '" />' . "\n";
		}
		if ( $title ) {
			echo '<meta property="og:title" content="' . esc_attr( $title ) . '" />' . "\n";
		}
		if ( $description ) {
			echo '<meta property="og:description" content="' . esc_attr( $description ) . '" />' . "\n";
		}
		if ( has_post_thumbnail( $post_id ) ) {
			echo '<meta property="og:image" content="' . esc_url( get_the_post_thumbnail_url( $post_id, 'large' ) ) . '" />' . "\n";
		}
	}
}

NEGARANDEH_SEO_Handler::register_hooks();
