<?php
/**
 * AvalAI API client (OpenAI-compatible).
 *
 * @see https://docs.avalai.org
 * @package Negarandeh
 */

defined( 'ABSPATH' ) || exit;

class NEGARANDEH_Avalai_API {

	public const DEFAULT_BASE_URL    = 'https://api.avalai.ir/v1';
	public const DEFAULT_IMAGE_MODEL = 'gemini-3.1-flash-image';

	/** @var array<string,mixed> Last HTTP exchange for admin debugging. */
	private static $last_http_exchange = array();

	/** @var array<string,mixed> Token/cost usage from the most recent successful request. */
	private static $last_usage = array();

	/**
	 * Send chat completion request.
	 *
	 * @param array<string,mixed> $messages Messages array.
	 * @param array<string,mixed> $args     Extra args.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function chat( array $messages, array $args = array() ) {
		$settings = self::get_settings();

		if ( empty( $settings['api_key'] ) ) {
			return new WP_Error( 'negarandeh_no_api_key', __( 'کلید API AvalAI تنظیم نشده است.', 'negarandeh' ) );
		}

		if ( empty( $settings['api_base_url'] ) ) {
			return new WP_Error( 'negarandeh_no_base_url', __( 'آدرس API AvalAI تنظیم نشده است.', 'negarandeh' ) );
		}

		$model = trim( (string) ( $args['model'] ?? $settings['chat_model'] ?? 'gpt-4o-mini' ) );
		$body  = wp_parse_args(
			$args,
			array(
				'model'       => $model,
				'messages'    => $messages,
				'temperature' => (float) ( $args['temperature'] ?? $settings['temperature'] ?? 0.7 ),
				'max_tokens'  => (int) ( $args['max_tokens'] ?? $settings['max_tokens'] ?? 4096 ),
			)
		);

		if ( ! self::is_gemini_model( $model ) && ! isset( $body['response_format'] ) && empty( $args['plain_text'] ) ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		unset( $body['plain_text'] );

		return self::request( '/chat/completions', $body, $settings );
	}

	/**
	 * Generate image.
	 *
	 * @param string              $prompt Image prompt.
	 * @param array<string,mixed> $args   Extra args.
	 * @return array<string,mixed>|WP_Error Normalized { data: [{url|b64_json}] }.
	 */
	public static function generate_image( string $prompt, array $args = array(), ?array $settings = null ) {
		$settings = $settings ?? self::get_settings();

		if ( empty( $settings['api_key'] ) ) {
			return new WP_Error( 'negarandeh_no_api_key', __( 'کلید API AvalAI تنظیم نشده است.', 'negarandeh' ) );
		}

		$model    = self::resolve_image_model_name( $settings, $args );
		$mode     = self::resolve_image_mode( $settings, $args );
		$strategy = self::get_image_model_strategy( $model );

		if ( 'chat' === $mode ) {
			return self::generate_image_via_chat( $prompt, $args, $settings );
		}

		$result = self::generate_image_via_images_api( $prompt, $args, $settings );

		if ( is_wp_error( $result ) && ! empty( $args['single_attempt'] ) ) {
			return $result;
		}

		// Auto: images/generations 404 → chat فقط برای مدل‌های multimodal (نه gpt-image / Gemini).
		if (
			is_wp_error( $result )
			&& 'auto' === ( $settings['image_api_mode'] ?? 'auto' )
			&& 'chat_modalities' === $strategy
			&& 404 === self::get_error_http_status( $result )
		) {
			$chat_result = self::generate_image_via_chat( $prompt, $args, $settings );
			if ( ! is_wp_error( $chat_result ) ) {
				return $chat_result;
			}
		}

		return $result;
	}

	/**
	 * @param string              $prompt   Image prompt.
	 * @param array<string,mixed> $args     Extra args.
	 * @param array<string,mixed> $settings Settings.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function generate_image_via_chat( string $prompt, array $args, array $settings ) {
		$image_base = self::get_image_base_url( $settings );
		if ( '' === $image_base ) {
			return new WP_Error( 'negarandeh_no_base_url', __( 'آدرس API تنظیم نشده است.', 'negarandeh' ) );
		}

		$model = self::resolve_image_model_name( $settings, $args );

		if ( self::is_gemini_image_model( $model ) ) {
			$single = ! empty( $args['single_attempt'] ) && empty( $args['featured_save'] );
			return self::generate_gemini_image( $prompt, $settings, $model, $image_base, $single );
		}

		$body = array(
			'model'      => $model,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => mb_substr( $prompt, 0, 4000 ),
				),
			),
			'modalities' => array( 'image', 'text' ),
		);

		$attempts = array(
			$body,
		);
		if ( empty( $args['single_attempt'] ) ) {
			$attempts[] = array_merge( $body, array( 'modalities' => array( 'image' ) ) );
		}

		$last_error = new WP_Error( 'negarandeh_no_image', __( 'تصویر در پاسخ chat/completions یافت نشد.', 'negarandeh' ) );

		foreach ( $attempts as $attempt_body ) {
			$result = self::request( '/chat/completions', $attempt_body, $settings, $image_base );

			if ( is_wp_error( $result ) ) {
				$last_error = $result;
				continue;
			}

			if ( self::chat_response_reasoning_only( $result ) ) {
				$last_error = new WP_Error(
					'negarandeh_image_reasoning_only',
					__( 'مدل فقط reasoning برگرداند — تصویر در message.images نیامد.', 'negarandeh' ),
					array(
						'details' => self::truncate_text( (string) ( $result['choices'][0]['message']['reasoning'] ?? '' ), 400 ),
						'body'    => wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ),
					)
				);
				continue;
			}

			$normalized = self::normalize_chat_image_response( $result );
			if ( self::response_has_image( $normalized ) ) {
				return $normalized;
			}

			$last_error = new WP_Error(
				'negarandeh_no_image',
				__( 'پاسخ chat/completions بدون تصویر بود.', 'negarandeh' ),
				array( 'body' => wp_json_encode( $result, JSON_UNESCAPED_UNICODE ) )
			);
		}

		return $last_error;
	}

	/**
	 * Gemini image models (Nano Banana) via chat/completions + generationConfig.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	private static function generate_gemini_image( string $prompt, array $settings, string $model, string $base_url, bool $single_attempt = false ) {
		$attempts = array(
			self::build_gemini_image_body( $prompt, $settings, $model ),
			self::build_gemini_image_body_simple( $prompt, $model, $settings ),
		);

		if ( ! $single_attempt ) {
			$attempts[] = self::build_gemini_image_body(
				$prompt,
				$settings,
				$model,
				array( 'modalities' => array( 'image' ) )
			);
		}

		$last_error = null;

		foreach ( $attempts as $body ) {
			$result = self::request( '/chat/completions', $body, $settings, $base_url );

			if ( is_wp_error( $result ) ) {
				$last_error = $result;
				continue;
			}

			if ( self::chat_response_reasoning_only( $result ) ) {
				$last_error = new WP_Error(
					'negarandeh_image_reasoning_only',
					__( 'Gemini فقط reasoning برگرداند — تصویر ساخته نشد.', 'negarandeh' ),
					array( 'body' => wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) )
				);
				continue;
			}

			$normalized = self::normalize_chat_image_response( $result );
			if ( self::response_has_image( $normalized ) ) {
				return $normalized;
			}

			$last_error = new WP_Error(
				'negarandeh_no_image',
				__( 'پاسخ Gemini بدون تصویر بود — message.images خالی است.', 'negarandeh' ),
				array( 'body' => wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) )
			);
		}

		return $last_error ?? new WP_Error( 'negarandeh_no_image', __( 'تولید تصویر Gemini ناموفق بود.', 'negarandeh' ) );
	}

	/**
	 * AvalAI gemini-2.5-flash-image: extra_body.generationConfig (see docs.avalai.org).
	 *
	 * @param array<string,mixed> $extra Extra body fields.
	 * @return array<string,mixed>
	 */
	private static function build_gemini_image_body( string $prompt, array $settings, string $model, array $extra = array() ): array {
		$size_key  = $settings['image_size'] ?? '1200x675';
		$image_cfg = array(
			'aspectRatio' => self::get_image_aspect_ratio( $size_key ),
		);

		if ( preg_match( '/gemini-3-pro-image/i', $model ) ) {
			$image_cfg['imageSize'] = '2K';
		}

		$body = array(
			'model'      => $model,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type' => 'text',
							'text' => 'Create a wide 16:9 landscape featured image: ' . mb_substr( $prompt, 0, 3750 ),
						),
					),
				),
			),
			'modalities' => array( 'image', 'text' ),
			'temperature' => 1.0,
			'max_tokens'    => max( 8192, (int) ( $settings['max_tokens'] ?? 4096 ) ),
			'extra_body'    => array(
				'generationConfig' => array(
					'imageConfig' => $image_cfg,
				),
			),
		);

		return array_merge( $body, $extra );
	}

	/**
	 * Fallback: plain content + modalities (AvalAI stable-release example).
	 *
	 * @return array<string,mixed>
	 */
	private static function build_gemini_image_body_simple( string $prompt, string $model, array $settings ): array {
		return array(
			'model'       => $model,
			'messages'    => array(
				array(
					'role'    => 'user',
					'content' => 'A photorealistic wide 16:9 featured image: ' . mb_substr( $prompt, 0, 3900 ),
				),
			),
			'modalities'  => array( 'image', 'text' ),
			'max_tokens'  => max( 8192, (int) ( $settings['max_tokens'] ?? 4096 ) ),
			'extra_body'  => array(
				'generationConfig' => array(
					'imageConfig' => array(
						'aspectRatio' => self::get_image_aspect_ratio( $settings['image_size'] ?? '1200x675' ),
					),
				),
			),
		);
	}

	/**
	 * @param string              $prompt   Image prompt.
	 * @param array<string,mixed> $args     Extra args.
	 * @param array<string,mixed> $settings Settings.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function generate_image_via_images_api( string $prompt, array $args, array $settings ) {
		$image_base = self::get_image_base_url( $settings );
		if ( '' === $image_base ) {
			return new WP_Error( 'negarandeh_no_base_url', __( 'آدرس API تنظیم نشده است.', 'negarandeh' ) );
		}

		$model    = self::resolve_image_model_name( $settings, $args );
		$size_key = $settings['image_size'] ?? '1200x675';
		$body     = array(
			'model'  => trim( (string) ( $args['model'] ?? $model ) ),
			'prompt' => mb_substr( (string) ( $args['prompt'] ?? $prompt ), 0, 4000 ),
			'n'      => max( 1, (int) ( $args['n'] ?? 1 ) ),
			'size'   => self::resolve_images_api_size(
				(string) ( $args['size'] ?? $size_key ),
				$model
			),
		);

		if ( self::is_openai_image_model( $model ) ) {
			$quality         = (string) ( $args['quality'] ?? 'high' );
			$body['quality'] = in_array( $quality, array( 'high', 'medium', 'low', 'auto' ), true ) ? $quality : 'high';
		}

		$attempts = array(
			array_merge( $body, array( 'response_format' => 'b64_json' ) ),
		);
		if ( empty( $args['single_attempt'] ) && empty( $args['featured_save'] ) ) {
			array_unshift( $attempts, array_merge( $body, array( 'response_format' => 'url' ) ) );
		}

		$last_error = new WP_Error( 'negarandeh_no_image', __( 'پاسخ images/generations خالی بود.', 'negarandeh' ) );

		foreach ( $attempts as $attempt ) {
			$result = self::request( '/images/generations', $attempt, $settings, $image_base );
			if ( ! is_wp_error( $result ) && self::response_has_image( $result ) ) {
				return $result;
			}
			if ( is_wp_error( $result ) ) {
				$last_error = $result;
			}
		}

		return $last_error;
	}

	/**
	 * @param array<string,mixed> $response Chat API response.
	 * @return array<string,mixed>
	 */
	public static function normalize_chat_image_response( array $response ): array {
		$data    = array();
		$choices = $response['choices'] ?? array();
		$message = is_array( $choices[0]['message'] ?? null ) ? $choices[0]['message'] : array();

		if ( ! empty( $message['images'] ) && is_array( $message['images'] ) ) {
			foreach ( $message['images'] as $img ) {
				if ( is_array( $img ) ) {
					$item = self::parse_image_payload( $img );
					if ( $item ) {
						$data[] = $item;
					}
				} elseif ( is_string( $img ) && '' !== trim( $img ) ) {
					$item = self::url_to_image_data( $img );
					if ( $item ) {
						$data[] = $item;
					}
				}
			}
		}

		$content = $message['content'] ?? null;
		if ( is_string( $content ) && '' !== trim( $content ) ) {
			$data = array_merge( $data, self::extract_data_urls_from_text( $content ) );
		} elseif ( is_array( $content ) ) {
			foreach ( $content as $part ) {
				if ( ! is_array( $part ) ) {
					continue;
				}
				if ( in_array( $part['type'] ?? '', array( 'image_url', 'output_image', 'image' ), true ) ) {
					if ( ! empty( $part['image_url']['url'] ) ) {
						$item = self::url_to_image_data( (string) $part['image_url']['url'] );
						if ( $item ) {
							$data[] = $item;
						}
					}
				}
			}
		}

		if ( empty( $data ) && ! empty( $response['data'] ) && is_array( $response['data'] ) ) {
			foreach ( $response['data'] as $item ) {
				if ( is_array( $item ) ) {
					$parsed = self::parse_image_payload( $item );
					if ( $parsed ) {
						$data[] = $parsed;
					}
				}
			}
		}

		if ( empty( $data ) ) {
			$data = self::extract_inline_data_images( $response );
		}

		return array( 'data' => $data );
	}

	/**
	 * @param array<string,mixed> $data API response.
	 */
	public static function response_has_image( array $data ): bool {
		$items = $data['data'] ?? array();
		if ( ! is_array( $items ) ) {
			return false;
		}

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( ! empty( $item['url'] ) || ! empty( $item['b64_json'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Ensure API output is in { data: [{url|b64_json}] } shape before saving.
	 *
	 * @param array<string,mixed> $response Raw or normalized API response.
	 * @return array<string,mixed>
	 */
	public static function normalize_image_response( array $response ): array {
		if ( self::response_has_image( $response ) ) {
			return $response;
		}

		if ( ! empty( $response['choices'] ) || ! empty( $response['candidates'] ) ) {
			return self::normalize_chat_image_response( $response );
		}

		return $response;
	}

	/**
	 * Test API connection.
	 *
	 * @param array<string,mixed>|null $settings Optional settings override.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function test_connection( ?array $settings = null ) {
		$settings = $settings ?? self::get_settings();

		if ( empty( $settings['api_key'] ) ) {
			return new WP_Error( 'negarandeh_no_api_key', __( 'کلید API تنظیم نشده است.', 'negarandeh' ) );
		}

		if ( empty( $settings['api_base_url'] ) ) {
			return new WP_Error( 'negarandeh_no_base_url', __( 'آدرس API تنظیم نشده است.', 'negarandeh' ) );
		}

		return self::request(
			'/chat/completions',
			array(
				'model'       => $settings['chat_model'] ?? 'gpt-4o-mini',
				'messages'    => array(
					array(
						'role'    => 'user',
						'content' => 'Say OK',
					),
				),
				'max_tokens'  => 5,
				'temperature' => 0,
			),
			$settings
		);
	}

	/**
	 * Test image API.
	 *
	 * @param array<string,mixed>|null $settings Settings override.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function test_image( ?array $settings = null ) {
		$settings = $settings ?? self::get_settings();
		self::reset_http_debug();

		return self::generate_image(
			'A simple red circle on white background, minimal test image',
			array(),
			$settings
		);
	}

	public static function reset_http_debug(): void {
		self::$last_http_exchange = array();
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function get_last_http_exchange(): array {
		return self::$last_http_exchange;
	}

	/**
	 * Normalize the usage block from an API response into a flat structure.
	 *
	 * @param array<string,mixed> $data Decoded API response.
	 */
	private static function capture_usage( array $data ): void {
		$usage = array();

		if ( ! empty( $data['usage'] ) && is_array( $data['usage'] ) ) {
			$usage = $data['usage'];
		} elseif ( ! empty( $data['usageMetadata'] ) && is_array( $data['usageMetadata'] ) ) {
			// Native Gemini style.
			$meta  = $data['usageMetadata'];
			$usage = array(
				'prompt_tokens'     => (int) ( $meta['promptTokenCount'] ?? 0 ),
				'completion_tokens' => (int) ( $meta['candidatesTokenCount'] ?? 0 ),
				'total_tokens'      => (int) ( $meta['totalTokenCount'] ?? 0 ),
			);
		}

		$prompt     = (int) ( $usage['prompt_tokens'] ?? 0 );
		$completion = (int) ( $usage['completion_tokens'] ?? 0 );
		$total      = (int) ( $usage['total_tokens'] ?? ( $prompt + $completion ) );

		$est_cost = null;
		foreach ( array( 'estimated_cost', 'cost' ) as $cost_key ) {
			if ( isset( $data[ $cost_key ] ) && is_numeric( $data[ $cost_key ] ) ) {
				$est_cost = (float) $data[ $cost_key ];
				break;
			}
		}

		self::$last_usage = array(
			'prompt_tokens'     => $prompt,
			'completion_tokens' => $completion,
			'total_tokens'      => $total,
			'estimated_cost'    => $est_cost,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function get_last_usage(): array {
		return self::$last_usage;
	}

	public static function reset_last_usage(): void {
		self::$last_usage = array();
	}

	/**
	 * Derive the User API base (https://host/user/v1) from the configured chat base URL.
	 */
	public static function get_user_api_base_url( ?array $settings = null ): string {
		$settings = $settings ?? self::get_settings();
		$base     = self::normalize_base_url( $settings['api_base_url'] ?? self::DEFAULT_BASE_URL );

		// Strip a trailing /v1, /v2 … then attach /user/v1.
		$root = preg_replace( '#/v\d+$#', '', $base );
		$root = is_string( $root ) ? rtrim( $root, '/' ) : $base;

		return $root . '/user/v1';
	}

	/**
	 * Fetch current AvalAI credit balance.
	 *
	 * @param array<string,mixed>|null $settings Optional settings override.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get_credit( ?array $settings = null ) {
		$settings = $settings ?? self::get_settings();
		$api_key  = self::normalize_api_key( $settings['api_key'] ?? '' );

		if ( '' === $api_key ) {
			return new WP_Error( 'negarandeh_no_api_key', __( 'کلید API AvalAI تنظیم نشده است.', 'negarandeh' ) );
		}

		$url = self::get_user_api_base_url( $settings ) . '/credit';

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Accept'        => 'application/json',
					'Authorization' => self::get_authorization_header( $api_key ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				$response->get_error_code(),
				sprintf( '%s | URL: %s', $response->get_error_message(), $url ),
				array( 'url' => $url )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		self::record_http_exchange( $url, '', $code, $raw );

		if ( $code >= 400 || ! is_array( $data ) ) {
			$parsed = self::parse_api_error( $code, $raw, is_array( $data ) ? $data : null );

			return new WP_Error(
				'negarandeh_credit_error',
				$parsed['message'],
				array(
					'status' => $code,
					'body'   => $raw,
					'url'    => $url,
				)
			);
		}

		return $data;
	}

	/**
	 * List AvalAI models (authenticated /v1/models, with public fallback).
	 *
	 * @param array<string,mixed>|null $settings Optional settings.
	 * @param string                   $kind     text|image|all
	 * @return array{models:array<int,array<string,mixed>>,source:string}|WP_Error
	 */
	public static function list_models( ?array $settings = null, string $kind = 'all' ) {
		$settings = $settings ?? self::get_settings();
		$kind     = in_array( $kind, array( 'text', 'image', 'all' ), true ) ? $kind : 'all';
		$api_key  = self::normalize_api_key( $settings['api_key'] ?? '' );
		$base     = self::normalize_base_url( $settings['api_base_url'] ?? self::DEFAULT_BASE_URL );

		$auth_url   = $base . '/models';
		$public_url = self::get_public_models_url( $settings );
		$raw_list   = null;
		$source     = 'auth';

		if ( '' !== $api_key ) {
			$response = wp_remote_get(
				$auth_url,
				array(
					'timeout' => 45,
					'headers' => array(
						'Accept'        => 'application/json',
						'Authorization' => self::get_authorization_header( $api_key ),
					),
				)
			);

			if ( ! is_wp_error( $response ) ) {
				$code = (int) wp_remote_retrieve_response_code( $response );
				$body = (string) wp_remote_retrieve_body( $response );
				$data = json_decode( $body, true );
				self::record_http_exchange( $auth_url, '', $code, $body );

				if ( $code >= 200 && $code < 300 && is_array( $data ) && ! empty( $data['data'] ) && is_array( $data['data'] ) ) {
					$raw_list = $data['data'];
				}
			}
		}

		if ( null === $raw_list ) {
			$source   = 'public';
			$response = wp_remote_get(
				$public_url,
				array(
					'timeout' => 45,
					'headers' => array( 'Accept' => 'application/json' ),
				)
			);

			if ( is_wp_error( $response ) ) {
				return new WP_Error(
					$response->get_error_code(),
					sprintf( '%s | URL: %s', $response->get_error_message(), $public_url ),
					array( 'url' => $public_url )
				);
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = (string) wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );
			self::record_http_exchange( $public_url, '', $code, $body );

			if ( $code >= 400 || ! is_array( $data ) || empty( $data['data'] ) || ! is_array( $data['data'] ) ) {
				$parsed = self::parse_api_error( $code, $body, is_array( $data ) ? $data : null );

				return new WP_Error(
					'negarandeh_models_error',
					$parsed['message'],
					array(
						'status' => $code,
						'body'   => $body,
						'url'    => $public_url,
					)
				);
			}

			$raw_list = $data['data'];
		}

		$models = array();
		foreach ( $raw_list as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$normalized = self::normalize_listed_model( $row );
			if ( '' === $normalized['id'] ) {
				continue;
			}
			if ( 'all' !== $kind && $normalized['kind'] !== $kind ) {
				continue;
			}
			$models[] = $normalized;
		}

		usort(
			$models,
			static function ( $a, $b ) {
				return strcasecmp( (string) ( $a['id'] ?? '' ), (string) ( $b['id'] ?? '' ) );
			}
		);

		return array(
			'models' => $models,
			'source' => $source,
			'count'  => count( $models ),
		);
	}

	/**
	 * Public models URL derived from configured API base.
	 */
	public static function get_public_models_url( ?array $settings = null ): string {
		$settings = $settings ?? self::get_settings();
		$base     = self::normalize_base_url( $settings['api_base_url'] ?? self::DEFAULT_BASE_URL );
		$root     = preg_replace( '#/v\d+$#', '', $base );
		$root     = is_string( $root ) ? rtrim( $root, '/' ) : 'https://api.avalai.ir';

		return $root . '/public/models';
	}

	/**
	 * @param array<string,mixed> $row Raw model row from AvalAI.
	 * @return array<string,mixed>
	 */
	public static function normalize_listed_model( array $row ): array {
		$id    = sanitize_text_field( (string) ( $row['id'] ?? '' ) );
		$mode  = sanitize_key( (string) ( $row['mode'] ?? '' ) );
		$owned = sanitize_text_field( (string) ( $row['owned_by'] ?? '' ) );
		$price = is_array( $row['pricing'] ?? null ) ? $row['pricing'] : array();
		$kind  = self::classify_model_kind( $id, $mode );

		return array(
			'id'                => $id,
			'owned_by'          => $owned,
			'mode'              => $mode,
			'kind'              => $kind,
			'pricing'           => array(
				'input'        => isset( $price['input'] ) && is_numeric( $price['input'] ) ? (float) $price['input'] : null,
				'output'       => isset( $price['output'] ) && is_numeric( $price['output'] ) ? (float) $price['output'] : null,
				'cached_input' => isset( $price['cached_input'] ) && is_numeric( $price['cached_input'] ) ? (float) $price['cached_input'] : null,
				'per_image'    => isset( $price['output_cost_per_image'] ) && is_numeric( $price['output_cost_per_image'] ) ? (float) $price['output_cost_per_image'] : null,
			),
			'pricing_label'     => self::format_model_pricing_label( $price, $mode, $kind ),
			'max_input_tokens'  => isset( $row['max_input_tokens'] ) ? (int) $row['max_input_tokens'] : null,
			'max_output_tokens' => isset( $row['max_output_tokens'] ) ? (int) $row['max_output_tokens'] : null,
		);
	}

	/**
	 * @param string $id   Model id.
	 * @param string $mode AvalAI mode.
	 */
	public static function classify_model_kind( string $id, string $mode = '' ): string {
		$id = trim( $id );

		if ( 'image_generation' === $mode ) {
			return 'image';
		}

		if ( self::is_gemini_image_model( $id ) || self::is_images_api_model( $id ) ) {
			return 'image';
		}

		if ( preg_match( '/(^|[-_.])(image|imagen|dall-e|flux|sdxl|stable-diffusion)([-_.]|$)/i', $id ) ) {
			return 'image';
		}

		if ( in_array( $mode, array( 'chat', 'completion' ), true ) ) {
			return 'text';
		}

		if ( '' === $mode && $id ) {
			// Unknown mode: treat as text unless clearly non-chat.
			if ( preg_match( '/embedding|moderation|tts|whisper|rerank|ocr|video|audio|speech|search/i', $id ) ) {
				return 'other';
			}
			return 'text';
		}

		return 'other';
	}

	/**
	 * Human-readable pricing for the models picker.
	 *
	 * @param array<string,mixed> $pricing Raw pricing object.
	 */
	public static function format_model_pricing_label( array $pricing, string $mode = '', string $kind = '' ): string {
		$parts = array();

		if ( 'image' === $kind || 'image_generation' === $mode || isset( $pricing['output_cost_per_image'] ) ) {
			if ( isset( $pricing['output_cost_per_image'] ) && is_numeric( $pricing['output_cost_per_image'] ) ) {
				$parts[] = sprintf(
					/* translators: %s: USD amount */
					__( 'هر تصویر: $%s', 'negarandeh' ),
					self::format_price_number( (float) $pricing['output_cost_per_image'] )
				);
			}

			foreach ( $pricing as $key => $value ) {
				if ( ! is_string( $key ) || ! is_numeric( $value ) ) {
					continue;
				}
				if ( 0 !== strpos( $key, 'output_cost_per_image_' ) ) {
					continue;
				}
				$resolution = substr( $key, strlen( 'output_cost_per_image_' ) );
				$parts[]    = sprintf(
					/* translators: 1: resolution, 2: USD amount */
					__( '%1$s: $%2$s', 'negarandeh' ),
					$resolution,
					self::format_price_number( (float) $value )
				);
			}

			if ( empty( $parts ) && isset( $pricing['input'] ) && is_numeric( $pricing['input'] ) ) {
				$parts[] = sprintf(
					/* translators: %s: USD per 1M tokens */
					__( 'ورودی: $%s / ۱M', 'negarandeh' ),
					self::format_price_number( (float) $pricing['input'] )
				);
			}
			if ( empty( $parts ) && isset( $pricing['output'] ) && is_numeric( $pricing['output'] ) ) {
				$parts[] = sprintf(
					/* translators: %s: USD per 1M tokens */
					__( 'خروجی: $%s / ۱M', 'negarandeh' ),
					self::format_price_number( (float) $pricing['output'] )
				);
			}

			return implode( ' — ', $parts );
		}

		if ( isset( $pricing['input'] ) && is_numeric( $pricing['input'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: USD per 1M tokens */
				__( 'ورودی: $%s / ۱M', 'negarandeh' ),
				self::format_price_number( (float) $pricing['input'] )
			);
		}
		if ( isset( $pricing['output'] ) && is_numeric( $pricing['output'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: USD per 1M tokens */
				__( 'خروجی: $%s / ۱M', 'negarandeh' ),
				self::format_price_number( (float) $pricing['output'] )
			);
		}
		if ( isset( $pricing['cached_input'] ) && is_numeric( $pricing['cached_input'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: USD per 1M tokens */
				__( 'کش: $%s / ۱M', 'negarandeh' ),
				self::format_price_number( (float) $pricing['cached_input'] )
			);
		}

		return implode( ' — ', $parts );
	}

	private static function format_price_number( float $n ): string {
		if ( $n >= 1 ) {
			return number_format_i18n( $n, 2 );
		}
		if ( $n >= 0.01 ) {
			return number_format_i18n( $n, 3 );
		}

		return rtrim( rtrim( number_format( $n, 6, '.', '' ), '0' ), '.' );
	}

		public static function enrich_error_with_http_debug( WP_Error $error ): WP_Error {
		$data     = $error->get_error_data();
		$data     = is_array( $data ) ? $data : array();
		$exchange = self::$last_http_exchange;

		if ( empty( $data['url'] ) && ! empty( $exchange['url'] ) ) {
			$data['url'] = (string) $exchange['url'];
		}
		if ( empty( $data['request_body'] ) && ! empty( $exchange['request_body'] ) ) {
			$data['request_body'] = (string) $exchange['request_body'];
		}
		if ( empty( $data['body'] ) && ! empty( $exchange['response_body'] ) ) {
			$data['body'] = (string) $exchange['response_body'];
		}
		if ( empty( $data['status'] ) && isset( $exchange['http_code'] ) ) {
			$data['status'] = (int) $exchange['http_code'];
		}

		return new WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
	}

	public static function normalize_api_key( string $key ): string {
		return trim( $key );
	}

	public static function normalize_base_url( string $url ): string {
		$url = trim( $url );
		$url = rtrim( $url, '/' );

		if ( $url && ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . $url;
		}

		return is_string( $url ) ? $url : '';
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function get_settings(): array {
		$defaults = array(
			'api_base_url'       => self::DEFAULT_BASE_URL,
			'image_api_base_url' => '',
			'image_api_mode'     => 'auto',
			'api_key'            => '',
			'chat_model'         => 'gpt-4o-mini',
			'image_model'        => self::DEFAULT_IMAGE_MODEL,
			'temperature'        => 0.7,
			'max_tokens'         => 12000,
			'image_size'         => '1200x675',
			'ui_language'        => 'auto',
		);

		$saved    = get_option( 'negarandeh_settings', array() );
		$settings = wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );

		if ( ! empty( $settings['api_key'] ) ) {
			$settings['api_key'] = self::normalize_api_key( $settings['api_key'] );
		}

		foreach ( array( 'api_base_url', 'image_api_base_url' ) as $url_key ) {
			if ( empty( $settings[ $url_key ] ) ) {
				continue;
			}
			$settings[ $url_key ] = self::normalize_base_url( $settings[ $url_key ] );
		}

		if ( empty( $settings['api_base_url'] ) ) {
			$settings['api_base_url'] = self::DEFAULT_BASE_URL;
		}

		if ( ! in_array( $settings['image_size'], array( '1200x675', '1792x1024' ), true ) ) {
			$settings['image_size'] = '1792x1024' === ( $settings['image_size'] ?? '' ) ? '1792x1024' : '1200x675';
		}

		return $settings;
	}

	/**
	 * Target pixel dimensions for the saved featured image (after crop/resize).
	 *
	 * @return array{width:int,height:int}|null Null when no post-processing needed.
	 */
	public static function get_featured_output_dimensions( ?string $size_key = null ): ?array {
		if ( null === $size_key ) {
			$size_key = self::get_settings()['image_size'] ?? '1200x675';
		}

		$presets = array(
			'1200x675'  => array( 'width' => 1200, 'height' => 675 ),
			'1792x1024' => array( 'width' => 1792, 'height' => 1024 ),
		);

		if ( isset( $presets[ $size_key ] ) ) {
			return $presets[ $size_key ];
		}

		if ( preg_match( '/^(\d+)x(\d+)$/', $size_key, $matches ) ) {
			return array(
				'width'  => (int) $matches[1],
				'height' => (int) $matches[2],
			);
		}

		return array( 'width' => 1200, 'height' => 675 );
	}

	/**
	 * Size sent to images/generations (API may not accept exact featured dimensions).
	 */
	public static function resolve_api_image_size( string $size_key ): string {
		$map = array(
			'1200x675' => '1792x1024',
		);

		return $map[ $size_key ] ?? $size_key;
	}

	/**
	 * Size for POST /images/generations — varies by model family (DALL-E vs gpt-image).
	 */
	public static function resolve_images_api_size( string $size_key, string $model ): string {
		if ( self::is_openai_image_model( $model ) ) {
			$gpt_map = array(
				'1200x675'  => '1536x1024',
				'1792x1024' => '1536x1024',
				'1024x1792' => '1024x1536',
				'1024x1024' => '1024x1024',
			);

			return $gpt_map[ $size_key ] ?? '1536x1024';
		}

		return self::resolve_api_image_size( $size_key );
	}

	/**
	 * Aspect ratio for Gemini imageConfig.
	 */
	public static function get_image_aspect_ratio( string $size_key ): string {
		$map = array(
			'1200x675'  => '16:9',
			'1792x1024' => '16:9',
			'1024x1024' => '1:1',
			'1024x1792' => '9:16',
		);

		return $map[ $size_key ] ?? '16:9';
	}

	public static function get_image_base_url( ?array $settings = null ): string {
		$settings   = $settings ?? self::get_settings();
		$image_base = self::normalize_base_url( $settings['image_api_base_url'] ?? '' );

		if ( '' !== $image_base ) {
			return $image_base;
		}

		return self::normalize_base_url( $settings['api_base_url'] ?? self::DEFAULT_BASE_URL );
	}

	public static function is_gemini_model( string $model_slug ): bool {
		return (bool) preg_match( '/^gemini/i', trim( $model_slug ) );
	}

	public static function is_gemini_image_model( string $model_slug ): bool {
		$model = strtolower( trim( $model_slug ) );

		if ( preg_match( '/^nano-banana/i', $model ) ) {
			return true;
		}

		if ( ! preg_match( '/^gemini/i', $model ) ) {
			return false;
		}

		return (bool) preg_match( '/image|imagen/i', $model );
	}

	public static function is_openai_image_model( string $model_slug ): bool {
		return (bool) preg_match( '/^(gpt-image|dall-e)/i', trim( $model_slug ) );
	}

	public static function is_images_api_model( string $model_slug ): bool {
		if ( self::is_gemini_image_model( $model_slug ) ) {
			return false;
		}

		return (bool) preg_match(
			'/^(dall-e|gpt-image|flux|stable-diffusion|seedream|imagen|recraft|ideogram)/i',
			trim( $model_slug )
		);
	}

	/**
	 * Routing strategy for the configured image model.
	 *
	 * @return 'gemini_chat'|'images_api'|'chat_modalities'
	 */
	public static function get_image_model_strategy( string $model_slug ): string {
		if ( self::is_gemini_image_model( $model_slug ) ) {
			return 'gemini_chat';
		}

		if ( self::is_images_api_model( $model_slug ) ) {
			return 'images_api';
		}

		return 'chat_modalities';
	}

	/**
	 * Human-readable endpoint hint for settings UI.
	 *
	 * @return array{strategy:string,endpoint:string,hint:string}
	 */
	public static function get_image_model_info( string $model_slug ): array {
		$strategy = self::get_image_model_strategy( $model_slug );

		$info = array(
			'gemini_chat'     => array(
				'endpoint' => 'chat/completions',
				'hint'     => __( 'Gemini Image: chat/completions با modalities و extra_body.generationConfig. تصویر در message.images (base64).', 'negarandeh' ),
			),
			'images_api'      => array(
				'endpoint' => 'images/generations',
				'hint'     => __( 'مدل تصویری OpenAI/Flux: images/generations. برای gpt-image اندازه landscape و quality=high اعمال می‌شود.', 'negarandeh' ),
			),
			'chat_modalities' => array(
				'endpoint' => 'chat/completions',
				'hint'     => __( 'مدل چت multimodal: chat/completions با modalities image+text.', 'negarandeh' ),
			),
		);

		return array_merge(
			array( 'strategy' => $strategy ),
			$info[ $strategy ] ?? $info['chat_modalities']
		);
	}

	/**
	 * @param array<string,mixed> $settings Settings.
	 * @param array<string,mixed> $args     Call args.
	 */
	private static function resolve_image_mode( array $settings, array $args = array() ): string {
		$mode = $settings['image_api_mode'] ?? 'auto';
		if ( in_array( $mode, array( 'chat', 'images' ), true ) ) {
			return $mode;
		}

		$model    = self::resolve_image_model_name( $settings, $args );
		$strategy = self::get_image_model_strategy( $model );

		if ( 'images_api' === $strategy ) {
			return 'images';
		}

		return 'chat';
	}

	/**
	 * @param array<string,mixed> $settings Settings.
	 * @param array<string,mixed> $args     Call args.
	 */
	private static function resolve_image_model_name( array $settings, array $args = array() ): string {
		$explicit = trim( (string) ( $args['model'] ?? $settings['image_model'] ?? '' ) );

		return '' !== $explicit ? $explicit : self::DEFAULT_IMAGE_MODEL;
	}

	private static function get_error_http_status( WP_Error $error ): int {
		$data = $error->get_error_data();

		return (int) ( is_array( $data ) ? ( $data['status'] ?? 0 ) : 0 );
	}

	/**
	 * @param array<string,mixed> $response Chat API response.
	 */
	private static function chat_response_reasoning_only( array $response ): bool {
		$message = $response['choices'][0]['message'] ?? array();
		if ( ! is_array( $message ) || empty( $message['reasoning'] ) ) {
			return false;
		}

		$content = $message['content'] ?? null;
		if ( null === $content ) {
			return true;
		}

		if ( is_string( $content ) && '' === trim( $content ) ) {
			return true;
		}

		return is_array( $content ) && empty( $content );
	}

	/**
	 * @param string                   $path     e.g. /chat/completions
	 * @param array<string,mixed>      $body     Request body.
	 * @param array<string,mixed>|null $settings Optional settings.
	 * @param string                   $base_url Optional base URL override.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function request( string $path, array $body, ?array $settings = null, string $base_url = '' ) {
		$settings = $settings ?? self::get_settings();
		$api_key  = self::normalize_api_key( $settings['api_key'] ?? '' );
		$base_url = $base_url ? self::normalize_base_url( $base_url ) : self::normalize_base_url( $settings['api_base_url'] ?? '' );
		$url      = $base_url . $path;

		if ( '' === $api_key ) {
			return new WP_Error( 'negarandeh_no_api_key', __( 'کلید API AvalAI تنظیم نشده است.', 'negarandeh' ) );
		}

		if ( '' === $base_url ) {
			return new WP_Error( 'negarandeh_no_base_url', __( 'آدرس API AvalAI تنظیم نشده است.', 'negarandeh' ) );
		}

		$timeout = self::resolve_request_timeout( $path, $body );

		$request_json = wp_json_encode( $body );
		$result       = self::execute_http_request( $url, $request_json, $api_key, $timeout );

		// Transient network / AvalAI stalls: one automatic retry for chat article requests.
		if ( is_wp_error( $result ) && self::is_timeout_error( $result ) && '/chat/completions' === $path ) {
			if ( function_exists( 'usleep' ) ) {
				usleep( 1500000 ); // 1.5s
			}
			$result = self::execute_http_request( $url, $request_json, $api_key, $timeout );
		}

		if ( is_wp_error( $result ) ) {
			self::record_http_exchange( $url, $request_json, 0, $result->get_error_message() );
			$message = self::humanize_transport_error( $result, $timeout );

			return new WP_Error(
				$result->get_error_code(),
				$message,
				array(
					'url'          => $url,
					'request_body' => $request_json,
					'timeout'      => $timeout,
				)
			);
		}

		list( $code, $raw, $data ) = $result;
		self::record_http_exchange( $url, $request_json, $code, $raw );

		if ( $code < 400 && is_array( $data ) ) {
			self::capture_usage( $data );
		}

		if ( $code >= 400 || ( 0 === $code && '' !== $raw ) ) {
			$parsed = self::parse_api_error( $code, $raw, is_array( $data ) ? $data : null );

			return new WP_Error(
				'negarandeh_api_error',
				$parsed['message'],
				array(
					'status'       => $code,
					'body'         => $raw,
					'details'      => $parsed['details'],
					'url'          => $url,
					'request_body' => $request_json,
				)
			);
		}

		if ( ! is_array( $data ) && '' !== trim( $raw ) ) {
			$parsed = self::parse_api_error( $code, $raw, null );

			return new WP_Error(
				'negarandeh_api_error',
				sprintf(
					/* translators: 1: HTTP code, 2: error detail */
					__( 'پاسخ API نامعتبر (HTTP %1$d): %2$s', 'negarandeh' ),
					$code,
					$parsed['message']
				),
				array(
					'status'  => $code,
					'body'    => $raw,
					'details' => $parsed['details'],
					'url'     => $url,
				)
			);
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * @return array{0:int,1:string,2:array<string,mixed>|null}|WP_Error
	 */
	private static function execute_http_request( string $url, string $request_json, string $api_key, int $timeout ) {
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'Authorization' => self::get_authorization_header( $api_key ),
				),
				'body'    => $request_json,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				$response->get_error_code(),
				$response->get_error_message(),
				array( 'url' => $url )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		return array( $code, $raw, is_array( $data ) ? $data : null );
	}

	/**
	 * HTTP wait budget for AvalAI calls (article gen often exceeds 120s on heavy prompts).
	 *
	 * @param array<string,mixed> $body Request body.
	 */
	private static function resolve_request_timeout( string $path, array $body ): int {
		$timeout = 180;

		$max_tokens = isset( $body['max_tokens'] ) ? (int) $body['max_tokens'] : 0;
		if ( $max_tokens >= 8000 ) {
			$timeout = 300;
		}

		if (
			'/images/generations' === $path
			|| ! empty( $body['modalities'] )
			|| ! empty( $body['extra_body'] )
			|| ! empty( $body['generationConfig'] )
		) {
			$timeout = 300;
		}

		/**
		 * Filter AvalAI HTTP timeout in seconds.
		 *
		 * @param int                  $timeout Timeout seconds.
		 * @param string               $path    API path.
		 * @param array<string,mixed>  $body    Request body.
		 */
		$timeout = (int) apply_filters( 'negarandeh_api_timeout', $timeout, $path, $body );

		return max( 30, min( 600, $timeout ) );
	}

	private static function is_timeout_error( WP_Error $error ): bool {
		$message = strtolower( $error->get_error_message() );
		$code    = strtolower( $error->get_error_code() );

		if ( false !== strpos( $message, 'timed out' ) || false !== strpos( $message, 'curl error 28' ) ) {
			return true;
		}

		return false !== strpos( $code, 'timeout' );
	}

	private static function humanize_transport_error( WP_Error $error, int $timeout ): string {
		if ( self::is_timeout_error( $error ) ) {
			return sprintf(
				/* translators: %d: timeout seconds */
				__( 'زمان پاسخ API تمام شد (پس از %d ثانیه، بدون دریافت داده). معمولاً برای پرامپت‌های خیلی سنگین یا شلوغی AvalAI رخ می‌دهد؛ موضوع دوباره تلاش می‌شود. اگر تکرار شد، مدل سریع‌تر یا پرامپت کوتاه‌تر امتحان کنید.', 'negarandeh' ),
				$timeout
			);
		}

		return $error->get_error_message();
	}

	private static function get_authorization_header( string $api_key ): string {
		if ( preg_match( '/^Apikey\s+/i', $api_key ) ) {
			return $api_key;
		}

		return 'Bearer ' . $api_key;
	}

	/**
	 * @param mixed $node Response node.
	 * @return array<int,array<string,string>>
	 */
	private static function extract_inline_data_images( $node ): array {
		if ( ! is_array( $node ) ) {
			return array();
		}

		$found = array();

		foreach ( array( 'inline_data', 'inlineData' ) as $key ) {
			if ( empty( $node[ $key ] ) || ! is_array( $node[ $key ] ) ) {
				continue;
			}
			$b64 = $node[ $key ]['data'] ?? '';
			if ( is_string( $b64 ) && '' !== trim( $b64 ) ) {
				$found[] = array( 'b64_json' => preg_replace( '/\s+/', '', $b64 ) );
			}
		}

		foreach ( $node as $value ) {
			if ( is_array( $value ) ) {
				$found = array_merge( $found, self::extract_inline_data_images( $value ) );
			}
		}

		return $found;
	}

	/**
	 * @param array<string,mixed> $img Image fragment from API.
	 * @return array<string,string>|null
	 */
	private static function parse_image_payload( array $img ): ?array {
		if ( ! empty( $img['image_url']['url'] ) ) {
			return self::url_to_image_data( (string) $img['image_url']['url'] );
		}

		if ( ! empty( $img['b64_json'] ) ) {
			return array( 'b64_json' => self::normalize_base64_payload( (string) $img['b64_json'] ) );
		}

		if ( ! empty( $img['url'] ) ) {
			return self::url_to_image_data( (string) $img['url'] );
		}

		return null;
	}

	/**
	 * @return array<string,string>|null
	 */
	private static function url_to_image_data( string $url ): ?array {
		if ( preg_match( '#^data:image/[^;]+;base64,(.+)$#s', $url, $m ) ) {
			return array( 'b64_json' => self::normalize_base64_payload( $m[1] ) );
		}

		if ( filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return array( 'url' => $url );
		}

		return null;
	}

	/**
	 * Strip whitespace and data-URL prefix from base64 payloads.
	 */
	private static function normalize_base64_payload( string $payload ): string {
		$payload = trim( $payload );
		if ( preg_match( '#^data:image/[^;]+;base64,(.+)$#s', $payload, $m ) ) {
			$payload = $m[1];
		}

		return preg_replace( '/\s+/', '', $payload );
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	private static function extract_data_urls_from_text( string $text ): array {
		$found = array();
		if ( preg_match( '#data:image/[^;]+;base64,([A-Za-z0-9+/=\s]+)#s', $text, $m ) ) {
			$found[] = array( 'b64_json' => self::normalize_base64_payload( $m[1] ) );
		}

		return $found;
	}

	/**
	 * @param int                        $http_code HTTP status.
	 * @param string                     $raw_body  Raw body.
	 * @param array<string,mixed>|null   $data      Decoded JSON.
	 * @return array{message:string,details:string}
	 */
	public static function parse_api_error( int $http_code, string $raw_body, ?array $data = null ): array {
		if ( null === $data ) {
			$data = json_decode( $raw_body, true );
			if ( ! is_array( $data ) ) {
				$data = array();
			}
		}

		$messages = self::collect_error_messages( $data );

		if ( empty( $messages ) && '' !== trim( $raw_body ) ) {
			$plain = trim( wp_strip_all_tags( $raw_body ) );
			if ( '' !== $plain ) {
				$messages[] = self::truncate_text( $plain, 400 );
			}
		}

		$summary_parts = array();
		if ( $http_code > 0 ) {
			$summary_parts[] = 'HTTP ' . $http_code;
		}
		$summary_parts[] = ! empty( $messages ) ? implode( ' | ', $messages ) : __( 'پاسخ خطا بدون پیام مشخص', 'negarandeh' );

		$details = '';
		if ( '' !== trim( $raw_body ) ) {
			$pretty = $raw_body;
			if ( ! empty( $data ) ) {
				$encoded = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
				if ( $encoded ) {
					$pretty = $encoded;
				}
			}
			$details = self::truncate_text( $pretty, 2000 );
		}

		return array(
			'message' => implode( ' — ', $summary_parts ),
			'details' => $details,
		);
	}

	/**
	 * @param mixed $data Response fragment.
	 * @param int   $depth Recursion guard.
	 * @return array<int,string>
	 */
	private static function collect_error_messages( $data, int $depth = 0 ): array {
		if ( $depth > 6 || null === $data ) {
			return array();
		}

		if ( is_string( $data ) && '' !== trim( $data ) ) {
			return array( trim( $data ) );
		}

		if ( ! is_array( $data ) ) {
			return array();
		}

		$messages   = array();
		$string_keys = array( 'message', 'error_message', 'detail', 'title', 'description', 'reason', 'msg' );

		foreach ( $string_keys as $key ) {
			if ( ! empty( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
				$messages[] = trim( $data[ $key ] );
			}
		}

		if ( isset( $data['error'] ) ) {
			if ( is_string( $data['error'] ) ) {
				$messages[] = trim( $data['error'] );
			} else {
				$messages = array_merge( $messages, self::collect_error_messages( $data['error'], $depth + 1 ) );
			}
		}

		foreach ( $data as $value ) {
			if ( is_array( $value ) ) {
				$messages = array_merge( $messages, self::collect_error_messages( $value, $depth + 1 ) );
			}
		}

		return array_values( array_filter( array_unique( $messages ) ) );
	}

	public static function format_error_for_display( WP_Error $error ): string {
		$lines      = array( $error->get_error_message() );
		$error_data = $error->get_error_data();
		$data       = is_array( $error_data ) ? $error_data : array();

		if ( ! empty( $data['url'] ) ) {
			$lines[] = 'URL: ' . $data['url'];
		}
		if ( ! empty( $data['status'] ) ) {
			$lines[] = 'HTTP: ' . (int) $data['status'];
		}
		if ( ! empty( $data['request_body'] ) ) {
			$lines[] = 'Request: ' . (string) $data['request_body'];
		}
		if ( ! empty( $data['details'] ) ) {
			$lines[] = (string) $data['details'];
		} elseif ( ! empty( $data['body'] ) ) {
			$parsed = self::parse_api_error( (int) ( $data['status'] ?? 0 ), (string) $data['body'], null );
			if ( ! empty( $parsed['details'] ) ) {
				$lines[] = $parsed['details'];
			}
		}

		return implode( "\n\n", array_filter( $lines ) );
	}

	public static function send_json_error( WP_Error $error ): void {
		$data    = $error->get_error_data();
		$data    = is_array( $data ) ? $data : array();
		$details = isset( $data['details'] ) ? (string) $data['details'] : '';

		if ( ! $details && ! empty( $data['body'] ) ) {
			$parsed  = self::parse_api_error( (int) ( $data['status'] ?? 0 ), (string) $data['body'], null );
			$details = $parsed['details'];
		}

		wp_send_json_error(
			array(
				'message'       => $error->get_error_message(),
				'http_code'     => isset( $data['status'] ) ? (int) $data['status'] : 0,
				'details'       => $details,
				'response_body' => ! empty( $data['body'] ) ? self::truncate_text( (string) $data['body'], 4000 ) : '',
				'url'           => isset( $data['url'] ) ? (string) $data['url'] : '',
				'request_body'  => isset( $data['request_body'] ) ? (string) $data['request_body'] : '',
				'code'          => $error->get_error_code(),
			)
		);
	}

	private static function record_http_exchange( string $url, string $request_body, int $http_code, string $response_body ): void {
		self::$last_http_exchange = array(
			'url'           => $url,
			'request_body'  => $request_body,
			'response_body' => self::truncate_text( $response_body, 8000 ),
			'http_code'     => $http_code,
		);
	}

	private static function truncate_text( string $text, int $max ): string {
		$text = trim( $text );

		return mb_strlen( $text ) <= $max ? $text : mb_substr( $text, 0, $max ) . '…';
	}
}
