<?php
/**
 * WordPress post creation from AI output.
 *
 * @package Negarandeh
 */

defined( 'ABSPATH' ) || exit;

class NEGARANDEH_Post_Creator {

	public const SCHEDULE_INDEX_OPTION = 'negarandeh_schedule_post_index';
	public const SCHEDULE_BASE_OPTION  = 'negarandeh_schedule_base_timestamp';

	/**
	 * Reset staggered publish sequence (first post immediate, then every N hours).
	 */
	public static function reset_schedule_sequence(): void {
		delete_option( self::SCHEDULE_INDEX_OPTION );
		delete_option( self::SCHEDULE_BASE_OPTION );
	}

	/**
	 * Resolve a real author for posts (AJAX has a session; WP-Cron usually does not).
	 *
	 * @param array<string,mixed> $settings Generator settings.
	 */
	public static function resolve_author_id( array $settings = array() ): int {
		$candidates = array(
			(int) ( $settings['author_id'] ?? 0 ),
			(int) get_current_user_id(),
		);

		foreach ( $candidates as $user_id ) {
			if ( $user_id > 0 && user_can( $user_id, 'edit_posts' ) ) {
				return $user_id;
			}
		}

		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'ID',
			)
		);

		if ( ! empty( $admins[0] ) ) {
			return (int) $admins[0];
		}

		return 1;
	}

	/**
	 * Resolve WordPress post status and dates for staggered scheduled publishing.
	 *
	 * @param array<string,mixed> $settings Generator settings.
	 * @return array{post_status:string,post_date:?string,post_date_gmt:?string,slot:int,publish_timestamp:int}
	 */
	public static function resolve_schedule_post_dates( array $settings ): array {
		$interval_hours = max( 1, min( 48, (int) ( $settings['schedule_interval_hours'] ?? 6 ) ) );
		$slot           = max( 0, (int) get_option( self::SCHEDULE_INDEX_OPTION, 0 ) );
		$now            = (int) current_time( 'timestamp' );

		if ( 0 === $slot ) {
			update_option( self::SCHEDULE_BASE_OPTION, $now, false );

			return array(
				'post_status'        => 'publish',
				'post_date'          => null,
				'post_date_gmt'      => null,
				'slot'               => $slot,
				'publish_timestamp'  => $now,
			);
		}

		$base      = (int) get_option( self::SCHEDULE_BASE_OPTION, $now );
		$timestamp = $base + ( $slot * $interval_hours * HOUR_IN_SECONDS );

		if ( $timestamp <= $now ) {
			return array(
				'post_status'        => 'publish',
				'post_date'          => null,
				'post_date_gmt'      => null,
				'slot'               => $slot,
				'publish_timestamp'  => $now,
			);
		}

		$local = wp_date( 'Y-m-d H:i:s', $timestamp );

		return array(
			'post_status'        => 'future',
			'post_date'          => $local,
			'post_date_gmt'      => get_gmt_from_date( $local ),
			'slot'               => $slot,
			'publish_timestamp'  => $timestamp,
		);
	}

	/**
	 * Advance schedule slot after a post is successfully created.
	 */
	public static function advance_schedule_slot(): void {
		$slot = max( 0, (int) get_option( self::SCHEDULE_INDEX_OPTION, 0 ) );
		update_option( self::SCHEDULE_INDEX_OPTION, $slot + 1, false );
	}

	/**
	 * Create post from parsed AI content.
	 *
	 * @param array<string,mixed> $content  Parsed JSON from AI.
	 * @param array<string,mixed> $job      Queue job data.
	 * @return int|WP_Error Post ID.
	 */
	public static function create( array $content, array $job ) {
		$settings = get_option( 'negarandeh_generator_settings', array() );

		$post_status_setting = sanitize_key( $settings['post_status'] ?? 'draft' );
		$schedule_meta       = array();

		$article_html = NEGARANDEH_Content_Generator::clean_article_html( (string) ( $content['content'] ?? '' ) );

		$post_data = array(
			'post_title'   => sanitize_text_field( $content['title'] ?? '' ),
			'post_content' => wp_kses_post( $article_html ),
			'post_excerpt' => sanitize_textarea_field( $content['excerpt'] ?? '' ),
			'post_type'    => 'post',
			// WP-Cron has no logged-in session — never fall back to user 0.
			'post_author'  => self::resolve_author_id( is_array( $settings ) ? $settings : array() ),
		);

		if ( 'scheduled' === $post_status_setting ) {
			$schedule_meta = self::resolve_schedule_post_dates( $settings );
			$post_data['post_status'] = $schedule_meta['post_status'];

			if ( ! empty( $schedule_meta['post_date'] ) && ! empty( $schedule_meta['post_date_gmt'] ) ) {
				$post_data['post_date']     = $schedule_meta['post_date'];
				$post_data['post_date_gmt'] = $schedule_meta['post_date_gmt'];
				$post_data['edit_date']     = $schedule_meta['post_date'];
				$post_data['edit_date_gmt'] = $schedule_meta['post_date_gmt'];
			}
		} else {
			$post_data['post_status'] = $post_status_setting;
		}

		if ( empty( $post_data['post_title'] ) || empty( $post_data['post_content'] ) ) {
			return new WP_Error( 'negarandeh_invalid_content', __( 'محتوای تولیدشده نامعتبر است.', 'negarandeh' ) );
		}

		$post_id = wp_insert_post( $post_data, true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( 'scheduled' === $post_status_setting ) {
			self::advance_schedule_slot();
			update_post_meta( $post_id, '_negarandeh_scheduled_slot', (int) ( $schedule_meta['slot'] ?? 0 ) );
			update_post_meta(
				$post_id,
				'_negarandeh_scheduled_at',
				wp_date( 'Y-m-d H:i:s', (int) ( $schedule_meta['publish_timestamp'] ?? current_time( 'timestamp' ) ) )
			);
		}

		// Category.
		$cat_id = (int) ( $settings['category_id'] ?? 0 );
		if ( $cat_id > 0 ) {
			wp_set_post_categories( $post_id, array( $cat_id ) );
		}

		// Tags from AI.
		if ( ! empty( $settings['generate_tags'] ) ) {
			$tag_count = max( 1, min( 15, (int) ( $settings['tag_count'] ?? 5 ) ) );
			$tags      = NEGARANDEH_Content_Generator::normalize_tags_list( $content['tags'] ?? array() );
			if ( ! empty( $tags ) ) {
				$tags = array_slice( $tags, 0, $tag_count );
				wp_set_post_tags( $post_id, $tags, false );
			}
		}

		// SEO.
		NEGARANDEH_SEO_Handler::apply(
			$post_id,
			array(
				'meta_title'       => $content['meta_title'] ?? $content['title'] ?? '',
				'meta_description' => $content['meta_description'] ?? $content['excerpt'] ?? '',
				'focus_keyword'    => $content['focus_keyword'] ?? '',
				'slug'             => $content['slug'] ?? '',
			)
		);

		update_post_meta( $post_id, '_negarandeh_generated', 1 );
		update_post_meta( $post_id, '_negarandeh_topic', sanitize_text_field( $job['topic'] ?? '' ) );
		if ( ! empty( $job['run_id'] ) ) {
			update_post_meta( $post_id, '_negarandeh_queue_run', sanitize_text_field( (string) $job['run_id'] ) );
		}
		update_post_meta( $post_id, '_negarandeh_generated_at', current_time( 'mysql' ) );

		/**
		 * Featured image is generated by NEGARANDEH_Batch_Processor after the post is saved
		 * so the queue can update progress and avoid PHP timeouts on WAMP.
		 */

		/**
		 * Fires after an auto-generated post (and optional featured image) is created.
		 *
		 * @param int $post_id New post ID.
		 */
		do_action( 'negarandeh_post_created', $post_id );

		return $post_id;
	}

	/**
	 * Insert the featured image into post content after the first paragraph.
	 *
	 * @param int    $post_id       Post ID.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $alt_text      Image alt text.
	 * @return bool True when content was updated.
	 */
	public static function insert_image_after_first_paragraph( int $post_id, int $attachment_id, string $alt_text = '' ): bool {
		if ( $post_id < 1 || $attachment_id < 1 ) {
			return false;
		}

		$post = get_post( $post_id );
		if ( ! $post || '' === trim( (string) $post->post_content ) ) {
			return false;
		}

		$content = (string) $post->post_content;
		$marker  = 'wp-image-' . $attachment_id;

		if ( false !== strpos( $content, $marker ) ) {
			return true;
		}

		$image_html = self::build_inline_image_html( $attachment_id, $alt_text );
		if ( '' === $image_html ) {
			return false;
		}

		$new_content = self::append_after_first_paragraph( $content, $image_html );
		if ( $new_content === $content ) {
			return false;
		}

		$updated = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $new_content,
			),
			true
		);

		return ! is_wp_error( $updated );
	}

	/**
	 * @param int    $attachment_id Attachment ID.
	 * @param string $alt_text      Alt text.
	 */
	private static function build_inline_image_html( int $attachment_id, string $alt_text = '' ): string {
		$attrs = array(
			'class' => 'wp-image-' . $attachment_id,
		);

		if ( '' !== trim( $alt_text ) ) {
			$attrs['alt'] = sanitize_text_field( $alt_text );
		}

		$img = wp_get_attachment_image( $attachment_id, 'large', false, $attrs );
		if ( ! $img ) {
			return '';
		}

		return '<figure class="wp-block-image size-large">' . $img . '</figure>';
	}

	/**
	 * @param string $content HTML post content.
	 * @param string $insert  HTML to insert.
	 */
	private static function append_after_first_paragraph( string $content, string $insert ): string {
		if ( preg_match( '/<\/p>/i', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			$position = (int) $matches[0][1] + strlen( $matches[0][0] );

			return substr( $content, 0, $position ) . "\n\n" . $insert . "\n\n" . substr( $content, $position );
		}

		return $insert . "\n\n" . $content;
	}
}
