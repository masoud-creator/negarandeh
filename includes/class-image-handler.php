<?php
/**
 * Featured image download and attachment.
 *
 * @package Negarandeh
 */

defined( 'ABSPATH' ) || exit;

class NEGARANDEH_Image_Handler {

	/**
	 * Whether the post has a real, usable featured image (not just a stale _thumbnail_id).
	 */
	public static function post_has_usable_featured_image( int $post_id ): bool {
		if ( $post_id < 1 ) {
			return false;
		}

		$thumb_id = (int) get_post_thumbnail_id( $post_id );
		if ( $thumb_id < 1 ) {
			return false;
		}

		$attachment = get_post( $thumb_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			delete_post_meta( $post_id, '_thumbnail_id' );
			clean_post_cache( $post_id );
			return false;
		}

		if ( ! wp_attachment_is_image( $thumb_id ) ) {
			delete_post_meta( $post_id, '_thumbnail_id' );
			clean_post_cache( $post_id );
			return false;
		}

		$url = wp_get_attachment_image_url( $thumb_id, 'full' );
		if ( ! is_string( $url ) || '' === $url ) {
			delete_post_meta( $post_id, '_thumbnail_id' );
			clean_post_cache( $post_id );
			return false;
		}

		return true;
	}

	/**
	 * Generate and attach featured image.
	 *
	 * @param int    $post_id      Post ID.
	 * @param string $image_prompt Prompt for image generation.
	 * @param string $alt_text     Alt text for accessibility/SEO.
	 * @return int|WP_Error Attachment ID or error.
	 */
	public static function set_featured_image( int $post_id, string $image_prompt, string $alt_text = '' ) {
		$response = NEGARANDEH_Avalai_API::generate_image(
			$image_prompt,
			array( 'featured_save' => true )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = NEGARANDEH_Avalai_API::normalize_image_response( $response );
		if ( ! NEGARANDEH_Avalai_API::response_has_image( $response ) ) {
			return new WP_Error(
				'negarandeh_no_image',
				__( 'API تصویر برگرداند ولی دادهٔ قابل ذخیره دریافت نشد.', 'negarandeh' )
			);
		}

		return self::attach_api_image_to_post( $post_id, $response, $alt_text );
	}

	/**
	 * Save an existing API image response as the post featured image.
	 *
	 * Uses wp_upload_bits + wp_insert_attachment (more reliable than sideload in WP-Cron).
	 *
	 * @param int                   $post_id  Post ID.
	 * @param array<string,mixed>   $response Normalized API image response.
	 * @param string                $alt_text Alt text.
	 * @return int|WP_Error Attachment ID or error.
	 */
	public static function attach_api_image_to_post( int $post_id, array $response, string $alt_text = '' ) {
		$response      = NEGARANDEH_Avalai_API::normalize_image_response( $response );
		$media_user_id = self::resolve_media_user_id( $post_id );

		if ( $media_user_id < 1 ) {
			return new WP_Error(
				'negarandeh_no_media_user',
				__( 'برای ذخیره تصویر در WP-Cron کاربر دارای مجوز آپلود یافت نشد (نویسنده/مدیر را در تنظیمات مشخص کنید).', 'negarandeh' )
			);
		}

		return self::with_media_upload_context(
			$media_user_id,
			static function () use ( $post_id, $response, $alt_text, $media_user_id ) {
				self::load_admin_file_includes();

				$extracted = self::extract_binary_from_response( $response );
				if ( is_wp_error( $extracted ) ) {
					return $extracted;
				}

				$binary = $extracted['binary'];
				$ext    = $extracted['ext'];
				$mime   = self::mime_from_extension( $ext );

				$temp_path = self::write_binary_to_temp( $binary );
				if ( ! is_wp_error( $temp_path ) ) {
					$resized_path = self::fit_to_featured_size( $temp_path );
					if ( is_string( $resized_path ) && file_exists( $resized_path ) ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
						$resized_binary = file_get_contents( $resized_path );
						if ( is_string( $resized_binary ) && strlen( $resized_binary ) >= 100 ) {
							$binary = $resized_binary;
							$ext    = self::detect_extension( $resized_path );
							$mime   = self::mime_from_extension( $ext );
						}
						if ( $resized_path !== $temp_path ) {
							self::delete_temp_file( $resized_path );
						}
					}
					self::delete_temp_file( $temp_path );
				}

				if ( strlen( $binary ) < 100 ) {
					return new WP_Error(
						'negarandeh_invalid_image',
						__( 'فایل تصویر دریافتی از API نامعتبر است.', 'negarandeh' )
					);
				}

				$upload_dir = wp_upload_dir();
				if ( ! empty( $upload_dir['error'] ) ) {
					return new WP_Error(
						'negarandeh_upload_dir',
						sprintf(
							/* translators: %s: upload directory error */
							__( 'پوشه uploads در دسترس نیست: %s', 'negarandeh' ),
							$upload_dir['error']
						)
					);
				}

				$filename = self::build_featured_filename( $post_id, $ext );
				$upload   = wp_upload_bits( $filename, null, $binary );

				if ( ! empty( $upload['error'] ) ) {
					return new WP_Error(
						'negarandeh_upload_bits_failed',
						sprintf(
							/* translators: %s: WordPress upload error */
							__( 'ذخیره تصویر در uploads ناموفق: %s', 'negarandeh' ),
							$upload['error']
						)
					);
				}

				$file_path = $upload['file'];
				$filetype  = wp_check_filetype( $filename, null );
				if ( ! empty( $filetype['type'] ) ) {
					$mime = $filetype['type'];
				}

				$attachment = array(
					'post_mime_type' => $mime,
					'post_title'     => sanitize_text_field(
						$alt_text ?: ( ( $post = get_post( $post_id ) ) ? $post->post_title : __( 'تصویر شاخص', 'negarandeh' ) )
					),
					'post_content'   => '',
					'post_status'    => 'inherit',
					'post_parent'    => $post_id,
				);

				if ( $media_user_id > 0 ) {
					$attachment['post_author'] = $media_user_id;
				}

				$attachment_id = wp_insert_attachment( $attachment, $file_path, $post_id );

				if ( is_wp_error( $attachment_id ) ) {
					self::delete_temp_file( $file_path );
					return new WP_Error(
						$attachment_id->get_error_code(),
						sprintf(
							/* translators: %s: WordPress error */
							__( 'ایجاد پیوست تصویر ناموفق: %s', 'negarandeh' ),
							$attachment_id->get_error_message()
						)
					);
				}

				if ( ! $attachment_id ) {
					self::delete_temp_file( $file_path );
					return new WP_Error(
						'negarandeh_attachment_failed',
						__( 'ایجاد پیوست تصویر ناموفق بود.', 'negarandeh' )
					);
				}

				$metadata = function_exists( 'wp_generate_attachment_metadata' )
					? wp_generate_attachment_metadata( $attachment_id, $file_path )
					: array();
				if ( ! is_wp_error( $metadata ) && is_array( $metadata ) && ! empty( $metadata ) ) {
					wp_update_attachment_metadata( $attachment_id, $metadata );
				}

				if ( $alt_text ) {
					update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );
				}

				if ( ! set_post_thumbnail( $post_id, $attachment_id ) ) {
					update_post_meta( $post_id, '_thumbnail_id', (int) $attachment_id );
					clean_post_cache( $post_id );
				}

				if ( ! self::post_has_usable_featured_image( $post_id ) ) {
					return new WP_Error(
						'negarandeh_thumbnail_failed',
						__( 'تصویر ذخیره شد ولی به‌عنوان شاخص پست تنظیم نشد.', 'negarandeh' )
					);
				}

				delete_post_meta( $post_id, '_negarandeh_image_error' );

				return (int) $attachment_id;
			}
		);
	}

	/**
	 * Decode image bytes from a normalized API response.
	 *
	 * @param array<string,mixed> $response Normalized response.
	 * @return array{binary:string,ext:string}|WP_Error
	 */
	private static function extract_binary_from_response( array $response ) {
		$items  = is_array( $response['data'] ?? null ) ? $response['data'] : array();
		$errors = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			if ( ! empty( $item['b64_json'] ) ) {
				$binary = base64_decode( self::normalize_b64_payload( (string) $item['b64_json'] ), true );
				if ( false !== $binary && '' !== $binary && strlen( $binary ) >= 100 ) {
					return array(
						'binary' => $binary,
						'ext'    => self::detect_extension_from_binary( $binary ),
					);
				}
				$errors[] = 'b64_decode_failed';
				continue;
			}

			if ( ! empty( $item['url'] ) ) {
				$from_url = self::binary_from_url( (string) $item['url'] );
				if ( ! is_wp_error( $from_url ) ) {
					return $from_url;
				}
				$errors[] = $from_url->get_error_message();
			}
		}

		return new WP_Error(
			'negarandeh_no_image',
			__( 'تصویر از API دریافت نشد (نه URL و نه base64).', 'negarandeh' ),
			array( 'details' => $errors )
		);
	}

	/**
	 * @return array{binary:string,ext:string}|WP_Error
	 */
	private static function binary_from_url( string $url ) {
		if ( preg_match( '#^data:image/[^;]+;base64,(.+)$#s', $url, $matches ) ) {
			$binary = base64_decode( preg_replace( '/\s+/', '', $matches[1] ), true );
			if ( false !== $binary && '' !== $binary && strlen( $binary ) >= 100 ) {
				return array(
					'binary' => $binary,
					'ext'    => self::detect_extension_from_binary( $binary ),
				);
			}

			return new WP_Error( 'negarandeh_b64_decode', __( 'رمزگشایی data URL تصویر ناموفق بود.', 'negarandeh' ) );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 120,
				'sslverify' => apply_filters( 'https_local_ssl_verify', true ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'negarandeh_download_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'دانلود تصویر ناموفق: %s', 'negarandeh' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'negarandeh_download_failed',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'دانلود تصویر ناموفق (HTTP %d).', 'negarandeh' ),
					$code
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || strlen( $body ) < 100 ) {
			return new WP_Error( 'negarandeh_download_failed', __( 'دانلود تصویر ناموفق: پاسخ خالی.', 'negarandeh' ) );
		}

		return array(
			'binary' => $body,
			'ext'    => self::detect_extension_from_binary( $body ),
		);
	}

	private static function normalize_b64_payload( string $payload ): string {
		$payload = trim( $payload );
		if ( preg_match( '#^data:image/[^;]+;base64,(.+)$#s', $payload, $matches ) ) {
			$payload = $matches[1];
		}

		return preg_replace( '/\s+/', '', $payload );
	}

	/**
	 * Safe ASCII filename for wp_upload_bits (Persian slugs often sanitize to empty).
	 */
	private static function build_featured_filename( int $post_id, string $ext ): string {
		$post     = get_post( $post_id );
		$base     = ( $post && $post->post_name ) ? $post->post_name : 'post-' . $post_id;
		$filename = sanitize_file_name( $base . '-featured.' . $ext );

		if ( ! preg_match( '/^[a-z0-9][a-z0-9._-]*\.(jpe?g|png|webp|gif)$/i', $filename ) ) {
			$filename = 'negarandeh-post-' . $post_id . '-featured.' . strtolower( $ext );
		}

		return $filename;
	}

	/**
	 * wp-admin file/image helpers (needed during WP-Cron where admin includes are not loaded).
	 */
	private static function load_admin_file_includes(): void {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// wp_get_image_editor is in wp-includes/media.php (loaded on front/cron);
		// wp_generate_attachment_metadata and related helpers are admin-only.
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
	}

	/**
	 * WP-Cron runs without a logged-in user — impersonate an author who can upload files.
	 */
	private static function resolve_media_user_id( int $post_id ): int {
		$settings   = get_option( 'negarandeh_generator_settings', array() );
		$candidates = array(
			(int) ( is_array( $settings ) ? ( $settings['author_id'] ?? 0 ) : 0 ),
		);

		$post = get_post( $post_id );
		if ( $post ) {
			$candidates[] = (int) $post->post_author;
		}

		foreach ( $candidates as $user_id ) {
			if ( $user_id > 0 && user_can( $user_id, 'upload_files' ) ) {
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

		if ( ! empty( $admins[0] ) && user_can( (int) $admins[0], 'upload_files' ) ) {
			return (int) $admins[0];
		}

		return 0;
	}

	/**
	 * Run media upload code with upload_files capability (required during WP-Cron).
	 *
	 * @param int      $user_id  User to impersonate.
	 * @param callable $callback Callback returning int|WP_Error.
	 * @return int|WP_Error
	 */
	private static function with_media_upload_context( int $user_id, callable $callback ) {
		$previous_user = get_current_user_id();

		// Impersonate a real user — required because WP-Cron has no admin cookie/session.
		wp_set_current_user( $user_id );

		$cap_filter = static function ( $allcaps ) {
			$allcaps['upload_files']      = true;
			$allcaps['edit_posts']        = true;
			$allcaps['edit_others_posts'] = true;
			$allcaps['edit_published_posts'] = true;
			$allcaps['edit_post']         = true;

			return $allcaps;
		};

		add_filter( 'user_has_cap', $cap_filter, 10, 1 );

		try {
			return $callback();
		} finally {
			remove_filter( 'user_has_cap', $cap_filter, 10 );
			wp_set_current_user( $previous_user );
		}
	}

	/**
	 * GD or Imagick required for resize + WordPress media metadata.
	 *
	 * @return true|WP_Error
	 */
	public static function ensure_image_editor_available() {
		if ( ! function_exists( 'wp_get_image_editor' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		if ( wp_image_editor_supports( array( 'methods' => array( 'resize' ) ) ) ) {
			return true;
		}

		return new WP_Error(
			'negarandeh_no_image_editor',
			__( 'افزونه GD یا Imagick در PHP فعال نیست — برای ذخیره تصویر شاخص در WAMP آن را در php.ini فعال کنید.', 'negarandeh' )
		);
	}

	/**
	 * Get temp file path from API response (URL or base64).
	 *
	 * @param array<string,mixed> $response API response.
	 * @return string|WP_Error Path to temp file.
	 */
	private static function get_temp_image_file( array $response ) {
		$item = $response['data'][0] ?? array();

		if ( ! empty( $item['b64_json'] ) ) {
			$binary = base64_decode( (string) $item['b64_json'], true );
			if ( false === $binary || '' === $binary ) {
				return new WP_Error( 'negarandeh_b64_decode', __( 'رمزگشایی تصویر base64 ناموفق بود.', 'negarandeh' ) );
			}

			return self::write_binary_to_temp( $binary );
		}

		$url = $item['url'] ?? '';
		if ( ! $url ) {
			return new WP_Error( 'negarandeh_no_image', __( 'تصویر از API دریافت نشد (نه URL و نه base64).', 'negarandeh' ) );
		}

		if ( preg_match( '#^data:image/[^;]+;base64,(.+)$#s', $url, $matches ) ) {
			$binary = base64_decode( preg_replace( '/\s+/', '', $matches[1] ), true );
			if ( false !== $binary && '' !== $binary ) {
				return self::write_binary_to_temp( $binary );
			}

			return new WP_Error( 'negarandeh_b64_decode', __( 'رمزگشایی data URL تصویر ناموفق بود.', 'negarandeh' ) );
		}

		return self::download_remote_image( $url );
	}

	/**
	 * Download remote image bytes (more reliable than download_url during WP-Cron).
	 *
	 * @return string|WP_Error Temp file path.
	 */
	private static function download_remote_image( string $url ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 120,
				'sslverify' => apply_filters( 'https_local_ssl_verify', true ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$tmp = download_url( $url, 120 );
			if ( ! is_wp_error( $tmp ) ) {
				return self::ensure_temp_extension( $tmp );
			}

			return new WP_Error(
				'negarandeh_download_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'دانلود تصویر ناموفق: %s', 'negarandeh' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'negarandeh_download_failed',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'دانلود تصویر ناموفق (HTTP %d).', 'negarandeh' ),
					$code
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || '' === $body ) {
			return new WP_Error( 'negarandeh_download_failed', __( 'دانلود تصویر ناموفق: پاسخ خالی.', 'negarandeh' ) );
		}

		return self::write_binary_to_temp( $body );
	}

	/**
	 * Write decoded bytes to a temp file with a proper image extension.
	 *
	 * @return string|WP_Error
	 */
	private static function write_binary_to_temp( string $binary ) {
		self::load_admin_file_includes();

		$ext = self::detect_extension_from_binary( $binary );
		$tmp = wp_tempnam( 'negarandeh-featured' );

		if ( ! $tmp ) {
			return new WP_Error( 'negarandeh_temp_file', __( 'ایجاد فایل موقت ناموفق بود.', 'negarandeh' ) );
		}

		$path = preg_replace( '/\.tmp$/', '.' . $ext, $tmp );
		if ( $path === $tmp ) {
			$path = $tmp . '.' . $ext;
		}

		if ( $path !== $tmp && file_exists( $tmp ) ) {
			self::delete_temp_file( $tmp );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $path, $binary ) ) {
			self::delete_temp_file( $path );
			return new WP_Error( 'negarandeh_temp_write', __( 'نوشتن فایل تصویر ناموفق بود.', 'negarandeh' ) );
		}

		return $path;
	}

	/**
	 * Rename temp download to include extension for wp_check_filetype().
	 */
	private static function ensure_temp_extension( string $path ): string {
		if ( preg_match( '/\.(jpe?g|png|webp|gif)$/i', $path ) ) {
			return $path;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$binary = file_get_contents( $path );
		if ( false === $binary ) {
			return $path;
		}

		$ext      = self::detect_extension_from_binary( $binary );
		$new_path = preg_replace( '/\.tmp$/', '.' . $ext, $path );
		if ( $new_path === $path ) {
			$new_path = $path . '.' . $ext;
		}

		if ( self::move_temp_file( $path, $new_path ) ) {
			return $new_path;
		}

		return $path;
	}

	/**
	 * Move a temp file using the WordPress filesystem API.
	 *
	 * @param string $from Source path.
	 * @param string $to   Destination path.
	 */
	private static function move_temp_file( string $from, string $to ): bool {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! $wp_filesystem ) {
			WP_Filesystem();
		}

		return $wp_filesystem && $wp_filesystem->move( $from, $to, true );
	}

	private static function detect_extension_from_binary( string $binary ): string {
		if ( strncmp( $binary, "\x89PNG\r\n\x1a\n", 8 ) === 0 ) {
			return 'png';
		}
		if ( strncmp( $binary, "\xFF\xD8\xFF", 3 ) === 0 ) {
			return 'jpg';
		}
		if ( strncmp( $binary, 'RIFF', 4 ) === 0 && substr( $binary, 8, 4 ) === 'WEBP' ) {
			return 'webp';
		}
		if ( strncmp( $binary, 'GIF8', 4 ) === 0 ) {
			return 'gif';
		}

		return 'jpg';
	}

	private static function detect_extension( string $path ): string {
		$type = wp_check_filetype( $path );
		if ( ! empty( $type['ext'] ) ) {
			return $type['ext'];
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$binary = @file_get_contents( $path );
		if ( is_string( $binary ) && '' !== $binary ) {
			return self::detect_extension_from_binary( $binary );
		}

		return 'jpg';
	}

	private static function mime_from_extension( string $ext ): string {
		$map = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'webp' => 'image/webp',
			'gif'  => 'image/gif',
		);

		return $map[ strtolower( $ext ) ] ?? 'image/jpeg';
	}

	private static function is_valid_image_file( string $path ): bool {
		if ( ! file_exists( $path ) || filesize( $path ) < 100 ) {
			return false;
		}

		if ( function_exists( 'getimagesize' ) ) {
			$info = @getimagesize( $path );
			return is_array( $info ) && ! empty( $info[0] ) && ! empty( $info[1] );
		}

		return true;
	}

	/**
	 * Data URL or remote URL for browser preview (no file saved).
	 *
	 * @param array<string,mixed> $response API image response.
	 * @return string|WP_Error
	 */
	public static function get_preview_src( array $response ) {
		$response = NEGARANDEH_Avalai_API::normalize_image_response( $response );
		$items    = is_array( $response['data'] ?? null ) ? $response['data'] : array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			if ( ! empty( $item['b64_json'] ) ) {
				$payload = self::normalize_b64_payload( (string) $item['b64_json'] );
				$binary  = base64_decode( $payload, true );
				$ext     = is_string( $binary ) && '' !== $binary
					? self::detect_extension_from_binary( $binary )
					: 'jpg';

				return 'data:image/' . ( 'jpg' === $ext ? 'jpeg' : $ext ) . ';base64,' . $payload;
			}

			if ( ! empty( $item['url'] ) ) {
				return esc_url_raw( (string) $item['url'] );
			}
		}

		return new WP_Error( 'negarandeh_no_image', __( 'تصویر از API دریافت نشد.', 'negarandeh' ) );
	}

	/**
	 * Crop/resize to featured-image dimensions (landscape 16:9).
	 *
	 * @param string $file_path Temp file path.
	 * @return string|WP_Error
	 */
	private static function fit_to_featured_size( string $file_path ) {
		if ( ! wp_image_editor_supports( array( 'methods' => array( 'resize' ) ) ) ) {
			return $file_path;
		}

		$target = NEGARANDEH_Avalai_API::get_featured_output_dimensions();
		if ( null === $target || $target['width'] < 1 || $target['height'] < 1 ) {
			return $file_path;
		}

		if ( ! function_exists( 'wp_get_image_editor' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$editor = wp_get_image_editor( $file_path );
		if ( is_wp_error( $editor ) ) {
			return $file_path;
		}

		$resize = $editor->resize( $target['width'], $target['height'], true );
		if ( is_wp_error( $resize ) ) {
			return $file_path;
		}

		$ext   = self::detect_extension( $file_path );
		$saved = $editor->save( null, self::mime_from_extension( $ext ) );
		if ( is_wp_error( $saved ) ) {
			return $file_path;
		}

		if ( ! empty( $saved['path'] ) && is_string( $saved['path'] ) ) {
			if ( $saved['path'] !== $file_path && file_exists( $file_path ) ) {
				self::delete_temp_file( $file_path );
			}
			return $saved['path'];
		}

		return $file_path;
	}

	/**
	 * Delete a temp file using WordPress filesystem API.
	 *
	 * @param string $path File path.
	 */
	private static function delete_temp_file( string $path ): void {
		if ( '' !== $path && file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}
}
