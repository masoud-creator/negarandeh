<?php
/**
 * Background queue for batch post generation.
 *
 * @package Negarandeh
 */

defined( 'ABSPATH' ) || exit;

class NEGARANDEH_Batch_Processor {

	/** @var self|null */
	private static $instance = null;

	/**
	 * In-flight generation stage — used by shutdown handler when the request dies mid-run.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $active_stage = null;

	const QUEUE_OPTION          = 'negarandeh_generation_queue';
	const PERMANENT_LOG_OPTION  = 'negarandeh_permanent_log';
	const TOPIC_STATUS_OPTION   = 'negarandeh_topic_status';
	const LOCK_OPTION           = 'negarandeh_queue_processing_lock';
	const IMAGE_LOCK_OPTION     = 'negarandeh_image_processing_lock';
	const AUTO_CRON_HOOK        = 'negarandeh_auto_generate';
	const FEATURED_IMAGE_HOOK   = 'negarandeh_generate_featured_image';
	const CRON_INDEX_OPTION     = 'negarandeh_cron_topic_index';
	const PERMANENT_LOG_MAX     = 5000;
	const MANUAL_QUEUE_HOOK     = 'negarandeh_process_queue';

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( self::MANUAL_QUEUE_HOOK, array( $this, 'process_next' ) );
		add_action( self::AUTO_CRON_HOOK, array( $this, 'auto_cron_generate' ) );
		add_action( self::FEATURED_IMAGE_HOOK, array( $this, 'cron_generate_featured_image' ), 10, 1 );
		add_action( 'init', array( $this, 'maybe_handle_image_loopback' ), 1 );
		add_action( 'init', array( $this, 'maybe_recover_pending_featured_images' ), 30 );
		add_action( 'wp_ajax_negarandeh_start_generation', array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_negarandeh_process_queue_step', array( $this, 'ajax_process_step' ) );
		add_action( 'wp_ajax_negarandeh_get_queue_status', array( $this, 'ajax_status' ) );
		add_action( 'wp_ajax_negarandeh_clear_queue', array( $this, 'ajax_clear' ) );
		add_action( 'wp_ajax_negarandeh_reset_failed_topics', array( $this, 'ajax_reset_failed_topics' ) );
		add_action( 'wp_ajax_negarandeh_reset_generated_topics', array( $this, 'ajax_reset_generated_topics' ) );
		add_action( 'wp_ajax_negarandeh_clear_log', array( $this, 'ajax_clear_log' ) );

		add_action( 'init', array( $this, 'remove_invalid_recurring_cron' ), 5 );
		add_action( 'init', array( $this, 'ensure_auto_cron_scheduled' ), 20 );

		register_shutdown_function( array( $this, 'shutdown_flush_active_stage' ) );
	}

	public static function is_automation_enabled(): bool {
		$gen = self::get_generator_settings();

		return is_array( $gen ) && ! empty( $gen['automation_enabled'] );
	}

	public static function stop_all_generation(): void {
		wp_clear_scheduled_hook( self::MANUAL_QUEUE_HOOK );
		self::unschedule_auto_cron();

		$queue = get_option( self::QUEUE_OPTION, array() );
		if ( is_array( $queue ) && ! empty( $queue['status'] ) && 'running' === $queue['status'] ) {
			$queue['status']   = 'stopped';
			$queue['finished'] = current_time( 'mysql' );
			update_option( self::QUEUE_OPTION, $queue, false );
		}
	}

	public static function remove_invalid_recurring_cron_static(): void {
		self::instance()->remove_invalid_recurring_cron();
	}

	/**
	 * Old versions scheduled the queue hook every minute — causes duplicate posts.
	 */
	public function remove_invalid_recurring_cron(): void {
		$crons = _get_cron_array();
		if ( empty( $crons ) || ! is_array( $crons ) ) {
			return;
		}

		$changed = false;
		$hook_names = array( self::MANUAL_QUEUE_HOOK );
		$invalid_schedules = array(
			'negarandeh_every_minute',
			'negarandeh_hourly_generate',
		);

		foreach ( $crons as $timestamp => $hooks ) {
			foreach ( $hook_names as $hook_name ) {
				if ( empty( $hooks[ $hook_name ] ) || ! is_array( $hooks[ $hook_name ] ) ) {
					continue;
				}

				foreach ( $hooks[ $hook_name ] as $key => $event ) {
					if ( ! empty( $event['schedule'] ) && in_array( (string) $event['schedule'], $invalid_schedules, true ) ) {
						unset( $crons[ $timestamp ][ $hook_name ][ $key ] );
						$changed = true;
					}
				}

				if ( empty( $crons[ $timestamp ][ $hook_name ] ) ) {
					unset( $crons[ $timestamp ][ $hook_name ] );
				}
			}

			if ( empty( $crons[ $timestamp ] ) ) {
				unset( $crons[ $timestamp ] );
			}
		}

		if ( $changed ) {
			_set_cron_array( $crons );
		}

		wp_clear_scheduled_hook( 'negarandeh_hourly_generate' );
	}

	/**
	 * @param array<int,string> $topics Topic labels.
	 * @return array<string,mixed>
	 */
	public function enqueue( array $topics ): array {
		if ( ! self::is_automation_enabled() ) {
			return array(
				'success' => false,
				'message' => __( 'تولید غیرفعال است. ابتدا Start را بزنید.', 'negarandeh' ),
			);
		}

		$topics = array_values(
			array_filter(
				array_map( 'trim', $topics ),
				static function ( $t ) {
					return '' !== $t;
				}
			)
		);

		if ( empty( $topics ) ) {
			return array(
				'success' => false,
				'message' => __( 'لیست موضوعات خالی است.', 'negarandeh' ),
			);
		}

		$existing = get_option( self::QUEUE_OPTION, array() );
		if ( ! empty( $existing['status'] ) && 'running' === $existing['status'] ) {
			return array(
				'success' => false,
				'message' => __( 'صف تولید در حال اجراست. ابتدا صبر کنید یا «پاک کردن صف» را بزنید.', 'negarandeh' ),
			);
		}

		$queue = array(
			'status'            => 'running',
			'run_id'            => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'negarandeh_', true ),
			'topics'            => $topics,
			'current'           => 0,
			'total'             => count( $topics ),
			'completed_indices' => array(),
			'results'           => array(),
			'started'           => current_time( 'mysql' ),
		);

		update_option( self::QUEUE_OPTION, $queue, false );
		self::release_lock();
		self::schedule_queue_once( 2 );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of topics */
				__( '%d موضوع به صف اضافه شد.', 'negarandeh' ),
				count( $topics )
			),
			'total'   => count( $topics ),
		);
	}

	public function process_next(): bool {
		if ( ! self::is_automation_enabled() ) {
			return false;
		}

		self::prepare_long_running_request( 600 );

		if ( ! self::acquire_lock() ) {
			return false;
		}

		try {
			$this->maybe_resume_stuck_queue();
			$this->run_process_step();
		} finally {
			self::release_lock();
		}

		return true;
	}

	private function run_process_step(): void {
		if ( ! self::is_automation_enabled() ) {
			return;
		}

		$queue = get_option( self::QUEUE_OPTION, array() );

		if ( empty( $queue ) || 'running' !== ( $queue['status'] ?? '' ) ) {
			return;
		}

		$index             = (int) ( $queue['current'] ?? 0 );
		$topics            = $queue['topics'] ?? array();
		$completed_indices = $queue['completed_indices'] ?? array();
		$run_id            = (string) ( $queue['run_id'] ?? '' );

		if ( $index >= count( $topics ) ) {
			$queue['status']   = 'completed';
			$queue['finished'] = current_time( 'mysql' );
			update_option( self::QUEUE_OPTION, $queue, false );
			return;
		}

		if ( in_array( $index, $completed_indices, true ) ) {
			$queue['current'] = $index + 1;
			if ( $queue['current'] >= count( $topics ) ) {
				$queue['status']   = 'completed';
				$queue['finished'] = current_time( 'mysql' );
			} else {
				self::schedule_queue_once();
			}
			update_option( self::QUEUE_OPTION, $queue, false );
			return;
		}

		$topic = $topics[ $index ];

		if ( self::post_exists_for_topic( $topic ) ) {
			$existing_id = self::get_existing_post_id_for_topic( $topic );

			// Same queue run already created the post (e.g. timeout after save) — finalize, not skip.
			if ( $run_id && self::post_exists_for_queue_item( $topic, $run_id ) ) {
				$this->finalize_queue_item_success( $queue, $index, $topic, $topics, $existing_id, $run_id, array() );
				return;
			}

			self::set_topic_status(
				$topic,
				'skipped',
				array(
					'message' => __( 'پست قبلاً ساخته شده — رد شد.', 'negarandeh' ),
					'post_id' => $existing_id,
				)
			);
			$this->append_permanent_log(
				array(
					'topic'   => $topic,
					'index'   => $index + 1,
					'status'  => 'skipped',
					'message' => __( 'پست قبلاً برای این موضوع وجود دارد.', 'negarandeh' ),
					'post_id' => $existing_id,
					'source'  => 'manual_queue',
					'time'    => current_time( 'mysql' ),
				)
			);
			$queue['completed_indices'][] = $index;
			$queue['current']             = $index + 1;
			if ( $queue['current'] >= count( $topics ) ) {
				$queue['status']   = 'completed';
				$queue['finished'] = current_time( 'mysql' );
			} else {
				self::schedule_queue_once();
			}
			unset( $queue['phase'], $queue['phase_label'] );
			update_option( self::QUEUE_OPTION, $queue, false );
			return;
		}

		if ( self::is_topic_marked_failed( $topic ) ) {
			$status_row = self::get_topic_status( $topic );
			$this->append_permanent_log(
				array(
					'topic'   => $topic,
					'index'   => $index + 1,
					'status'  => 'skipped',
					'message' => (string) ( $status_row['message'] ?? __( 'قبلاً خطا خورده — رد شد.', 'negarandeh' ) ),
					'source'  => 'manual_queue',
					'time'    => current_time( 'mysql' ),
				)
			);
			$queue['completed_indices'][] = $index;
			$queue['current']             = $index + 1;
			if ( $queue['current'] >= count( $topics ) ) {
				$queue['status']   = 'completed';
				$queue['finished'] = current_time( 'mysql' );
			} else {
				self::schedule_queue_once();
			}
			update_option( self::QUEUE_OPTION, $queue, false );
			return;
		}

		if ( $run_id && self::post_exists_for_queue_item( $topic, $run_id ) ) {
			$queue['completed_indices'][] = $index;
			$queue['current']             = $index + 1;
			if ( $queue['current'] >= count( $topics ) ) {
				$queue['status']   = 'completed';
				$queue['finished'] = current_time( 'mysql' );
			}
			unset( $queue['phase'], $queue['phase_label'] );
			update_option( self::QUEUE_OPTION, $queue, false );
			return;
		}

		$result = array(
			'topic' => $topic,
			'index' => $index + 1,
			'time'  => current_time( 'mysql' ),
		);

		$queue['phase']       = 'content';
		$queue['phase_label'] = sprintf(
			/* translators: %s: topic label */
			__( 'تولید متن: %s', 'negarandeh' ),
			$topic
		);
		update_option( self::QUEUE_OPTION, $queue, false );

		$log_id = $this->append_permanent_log(
			array_merge(
				$result,
				array(
					'status'  => 'info',
					'message' => __( 'در حال تولید مقاله...', 'negarandeh' ),
					'source'  => 'manual_queue',
				)
			)
		);

		$this->begin_active_stage(
			'content',
			array(
				'log_id' => $log_id,
				'topic'  => $topic,
				'source' => 'manual_queue',
			)
		);

		NEGARANDEH_Avalai_API::reset_last_usage();
		$content = NEGARANDEH_Content_Generator::generate_for_topic(
			$topic,
			array(
				'topics' => $topics,
				'index'  => $index + 1,
			)
		);
		$article_usage = NEGARANDEH_Avalai_API::get_last_usage();
		self::clear_active_stage();

		if ( is_wp_error( $content ) ) {
			$result            = self::attach_usage_to_result( $result, $article_usage, array() );
			$result['status']  = 'error';
			$result['message'] = NEGARANDEH_Avalai_API::format_error_for_display( $content );
			self::set_topic_status(
				$topic,
				'error',
				array( 'message' => $result['message'] )
			);
		} else {
			$queue['phase']       = 'post';
			$queue['phase_label'] = sprintf(
				/* translators: %s: topic label */
				__( 'ساخت پست: %s', 'negarandeh' ),
				$topic
			);
			update_option( self::QUEUE_OPTION, $queue, false );

			$post_id = NEGARANDEH_Post_Creator::create(
				$content,
				array(
					'topic'  => $topic,
					'topics' => $topics,
					'index'  => $index + 1,
					'run_id' => $run_id,
				)
			);
			$result = self::attach_usage_to_result( $result, $article_usage, array() );

			if ( is_wp_error( $post_id ) ) {
				$result['status']  = 'error';
				$result['message'] = $post_id->get_error_message();
				self::set_topic_status(
					$topic,
					'error',
					array( 'message' => $result['message'] )
				);
			} else {
				$job = array(
					'topic'  => $topic,
					'topics' => $topics,
					'index'  => $index + 1,
					'run_id' => $run_id,
					'source' => 'manual_queue',
				);

				$result['status']   = 'success';
				$result['post_id']  = $post_id;
				$result['title']    = get_the_title( $post_id );
				$result['edit_url'] = esc_url_raw( (string) get_edit_post_link( $post_id, 'raw' ) );
				$result['phase']    = 'content';
				$result['time']     = current_time( 'mysql' );
				$result['message']  = __( 'مقاله ذخیره شد؛ در انتظار تصویر شاخص...', 'negarandeh' );

				self::set_topic_status(
					$topic,
					'success',
					array(
						'message' => $result['title'],
						'post_id' => $post_id,
					)
				);

				$this->update_permanent_log_entry( $log_id, array_merge( $result, array( 'source' => 'manual_queue' ) ) );

				$image_warning = $this->run_featured_image_step( $post_id, $content, $job, $queue, $topic, $log_id );
				$image_usage   = NEGARANDEH_Avalai_API::get_last_usage();
				$result        = self::attach_usage_to_result( $result, $article_usage, $image_usage );
				$result['time'] = current_time( 'mysql' );

				if ( $image_warning ) {
					$result['status']        = 'warning';
					$result['phase']         = 'image';
					$result['image_warning'] = $image_warning;
					$result['message']       = $image_warning;
					$this->schedule_deferred_featured_image( (int) $post_id, $content, $job, $log_id );
				} else {
					$gen_now = self::get_generator_settings();
					if ( ! empty( $gen_now['generate_image'] ) ) {
						$result['phase']   = 'done';
						$result['message'] = __( 'مقاله و تصویر شاخص آماده شد.', 'negarandeh' );
					} else {
						$result['phase']   = 'done';
						$result['message'] = __( 'مقاله ذخیره شد.', 'negarandeh' );
					}
				}
			}
		}

		$result['source']             = 'manual_queue';
		$queue['results'][]           = $result;
		$queue['completed_indices'][] = $index;
		$queue['current']             = $index + 1;
		unset( $queue['phase'], $queue['phase_label'] );

		if ( $queue['current'] >= count( $topics ) ) {
			$queue['status']   = 'completed';
			$queue['finished'] = current_time( 'mysql' );
		}

		update_option( self::QUEUE_OPTION, $queue, false );
		$this->update_permanent_log_entry( $log_id, $result );

		if ( 'running' === ( $queue['status'] ?? '' ) ) {
			self::schedule_queue_once();
		}
	}

	/**
	 * WP Cron: one post every 5 minutes from the topic list (round-robin).
	 */
	public function auto_cron_generate(): void {
		$gen = self::get_generator_settings();
		if ( 'wp_cron' !== self::get_queue_driver() || empty( $gen['automation_enabled'] ) ) {
			return;
		}

		$api_settings = NEGARANDEH_Avalai_API::get_settings();
		if ( empty( $api_settings['api_key'] ) || empty( $api_settings['api_base_url'] ) ) {
			$this->append_permanent_log(
				array(
					'status'  => 'error',
					'message' => __( 'تولید خودکار: کلید یا آدرس API تنظیم نشده است.', 'negarandeh' ),
					'source'  => 'auto_cron',
					'time'    => current_time( 'mysql' ),
				)
			);
			return;
		}

		self::prepare_long_running_request( 600 );

		if ( ! self::acquire_lock() ) {
			return;
		}

		try {
			$this->run_scheduled_generation( $gen );
		} finally {
			self::release_lock();
		}
	}

	/**
	 * WP-Cron: generate featured image in a separate request (avoids article+image timeout).
	 *
	 * @param int $post_id Post ID.
	 */
	public function cron_generate_featured_image( $post_id ): void {
		$post_id = (int) $post_id;
		if ( $post_id < 1 || ! get_post( $post_id ) ) {
			return;
		}

		self::prepare_long_running_request( 900 );

		if ( ! self::acquire_image_lock() ) {
			wp_schedule_single_event( time() + 60, self::FEATURED_IMAGE_HOOK, array( $post_id ) );
			return;
		}

		try {
			$payload = get_post_meta( $post_id, '_negarandeh_pending_image', true );
			if ( ! is_array( $payload ) || empty( $payload ) ) {
				return;
			}

			// Claim the job so a duplicate cron cannot re-run it.
			delete_post_meta( $post_id, '_negarandeh_pending_image' );

			if ( NEGARANDEH_Image_Handler::post_has_usable_featured_image( $post_id ) ) {
				return;
			}

			// Stale _thumbnail_id — allow generation to continue.
			delete_post_meta( $post_id, '_thumbnail_id' );
			clean_post_cache( $post_id );

			$topic   = sanitize_text_field( (string) ( $payload['topic'] ?? '' ) );
			$content = array(
				'title'        => (string) ( $payload['title'] ?? get_the_title( $post_id ) ),
				'image_prompt' => (string) ( $payload['image_prompt'] ?? '' ),
				'image_alt'    => (string) ( $payload['image_alt'] ?? '' ),
			);
			$job     = array(
				'topic'  => $topic,
				'topics' => is_array( $payload['topics'] ?? null ) ? $payload['topics'] : array(),
				'index'  => (int) ( $payload['index'] ?? 1 ),
				'run_id' => (string) ( $payload['run_id'] ?? '' ),
				'source' => (string) ( $payload['source'] ?? 'auto_cron' ),
			);

			$dummy_queue   = array();
			$log_id        = isset( $payload['log_id'] ) ? (string) $payload['log_id'] : '';
			$image_warning = $this->run_featured_image_step( $post_id, $content, $job, $dummy_queue, $topic, $log_id );
			$image_usage   = NEGARANDEH_Avalai_API::get_last_usage();

			if ( $image_warning ) {
				// record_image_problem already wrote the permanent log.
				return;
			}

			if ( '' !== $log_id ) {
				$patch = array(
					'status'        => 'success',
					'image_warning' => null,
				);
				$image_total = (int) ( $image_usage['total_tokens'] ?? 0 );
				if ( $image_total > 0 || ! empty( $image_usage['estimated_cost'] ) ) {
					$patch['image_usage'] = $image_usage;
				}
				$this->update_permanent_log_entry( $log_id, $patch );
			}
		} finally {
			self::release_image_lock();
		}
	}

	/**
	 * @param array<string,mixed> $gen Generator settings.
	 */
	private function run_scheduled_generation( array $gen ): void {
		$topics = self::parse_topics_list( (string) ( $gen['topics'] ?? '' ) );
		if ( empty( $topics ) ) {
			$this->append_permanent_log(
				array(
					'status'  => 'error',
					'message' => __( 'تولید خودکار: لیست موضوعات خالی است.', 'negarandeh' ),
					'source'  => 'auto_cron',
					'time'    => current_time( 'mysql' ),
				)
			);
			return;
		}

		$index  = (int) get_option( self::CRON_INDEX_OPTION, 0 );
		$count  = count( $topics );
		$picked = null;

		for ( $offset = 0; $offset < $count; $offset++ ) {
			$idx   = ( $index + $offset ) % $count;
			$topic = $topics[ $idx ];

			if ( self::post_exists_for_topic( $topic ) ) {
				$existing_id = self::get_existing_post_id_for_topic( $topic );
				if ( 'success' !== ( self::get_topic_status( $topic )['status'] ?? '' ) ) {
					self::set_topic_status(
						$topic,
						'success',
						array(
							'message' => __( 'پست موجود شناسایی شد.', 'negarandeh' ),
							'post_id' => $existing_id,
						)
					);
				}
				continue;
			}

			if ( self::is_topic_marked_failed( $topic ) ) {
				continue;
			}

			$picked = array(
				'topic' => $topic,
				'index' => $idx,
			);
			break;
		}

		$next_index = $picked ? ( ( $picked['index'] + 1 ) % $count ) : ( ( $index + 1 ) % $count );
		update_option( self::CRON_INDEX_OPTION, $next_index, false );

		if ( ! $picked ) {
			return;
		}

		$topic      = $picked['topic'];
		$list_index = $picked['index'] + 1;
		$run_id     = 'auto_' . gmdate( 'Y-m-d-H-i' );

		$result = array(
			'topic'  => $topic,
			'index'  => $list_index,
			'time'   => current_time( 'mysql' ),
			'source' => 'auto_cron',
			'run_id' => $run_id,
		);

		$log_id = $this->append_permanent_log(
			array_merge(
				$result,
				array(
					'status'  => 'info',
					'message' => __( 'در حال تولید مقاله...', 'negarandeh' ),
				)
			)
		);

		$this->begin_active_stage(
			'content',
			array(
				'log_id' => $log_id,
				'topic'  => $topic,
				'source' => 'auto_cron',
			)
		);

		NEGARANDEH_Avalai_API::reset_last_usage();
		$content = NEGARANDEH_Content_Generator::generate_for_topic(
			$topic,
			array(
				'topics' => $topics,
				'index'  => $list_index,
			)
		);
		$article_usage = NEGARANDEH_Avalai_API::get_last_usage();
		self::clear_active_stage();

		if ( is_wp_error( $content ) ) {
			$result            = self::attach_usage_to_result( $result, $article_usage, array() );
			$result['status']  = 'error';
			$result['message'] = NEGARANDEH_Avalai_API::format_error_for_display( $content );
			self::set_topic_status(
				$topic,
				'error',
				array( 'message' => $result['message'] )
			);
			$this->update_permanent_log_entry( $log_id, $result );
			return;
		}

		NEGARANDEH_Avalai_API::reset_last_usage();
		$post_id = NEGARANDEH_Post_Creator::create(
			$content,
			array(
				'topic'  => $topic,
				'topics' => $topics,
				'index'  => $list_index,
				'run_id' => $run_id,
				'source' => 'auto_cron',
			)
		);
		$result = self::attach_usage_to_result( $result, $article_usage, array() );

		if ( is_wp_error( $post_id ) ) {
			$result['status']  = 'error';
			$result['message'] = $post_id->get_error_message();
			self::set_topic_status(
				$topic,
				'error',
				array( 'message' => $result['message'] )
			);
			$this->update_permanent_log_entry( $log_id, $result );
			return;
		}

		$result['status']   = 'success';
		$result['post_id']  = $post_id;
		$result['title']    = get_the_title( $post_id );
		$result['edit_url'] = esc_url_raw( (string) get_edit_post_link( $post_id, 'raw' ) );
		$result['phase']    = 'content';
		$result['time']     = current_time( 'mysql' );
		$result['message']  = __( 'مقاله ذخیره شد؛ در انتظار تصویر شاخص...', 'negarandeh' );

		self::set_topic_status(
			$topic,
			'success',
			array(
				'message' => $result['title'],
				'post_id' => $post_id,
			)
		);

		// Log article success before image step (image API can fail without losing the log).
		$this->update_permanent_log_entry( $log_id, $result );

		$job = array(
			'topic'  => $topic,
			'topics' => $topics,
			'index'  => $list_index,
			'run_id' => $run_id,
			'source' => 'auto_cron',
		);

		$dummy_queue   = array();
		$image_warning = $this->run_featured_image_step( (int) $post_id, $content, $job, $dummy_queue, $topic, $log_id );
		$image_usage   = NEGARANDEH_Avalai_API::get_last_usage();
		$result        = self::attach_usage_to_result( $result, $article_usage, $image_usage );
		$result['time'] = current_time( 'mysql' );

		if ( $image_warning ) {
			$result['status']        = 'warning';
			$result['phase']         = 'image';
			$result['image_warning'] = $image_warning;
			$result['message']       = $image_warning;
			$this->schedule_deferred_featured_image( (int) $post_id, $content, $job, $log_id );
		} else {
			$gen_now = self::get_generator_settings();
			if ( ! empty( $gen_now['generate_image'] ) ) {
				$result['phase']   = 'done';
				$result['message'] = __( 'مقاله و تصویر شاخص آماده شد.', 'negarandeh' );
			} else {
				$result['phase']   = 'done';
				$result['message'] = __( 'مقاله ذخیره شد.', 'negarandeh' );
			}
		}

		$this->update_permanent_log_entry( $log_id, $result );
	}

	/**
	 * Queue featured-image generation for a later request (fallback when inline save fails).
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $content Parsed AI content.
	 * @param array<string,mixed> $job     Job context.
	 * @param string|null         $log_id  Permanent log entry ID to update when done.
	 * @return bool True when a deferred job was scheduled.
	 */
	private function schedule_deferred_featured_image( int $post_id, array $content, array $job, ?string $log_id = null ): bool {
		$settings = self::get_generator_settings();
		if ( empty( $settings['generate_image'] ) || $post_id < 1 ) {
			return false;
		}

		if ( NEGARANDEH_Image_Handler::post_has_usable_featured_image( $post_id ) ) {
			return false;
		}

		$payload = array(
			'title'        => (string) ( $content['title'] ?? '' ),
			'image_prompt' => (string) ( $content['image_prompt'] ?? '' ),
			'image_alt'    => (string) ( $content['image_alt'] ?? ( $content['title'] ?? '' ) ),
			'topic'        => sanitize_text_field( (string) ( $job['topic'] ?? '' ) ),
			'topics'       => is_array( $job['topics'] ?? null ) ? array_values( $job['topics'] ) : array(),
			'index'        => (int) ( $job['index'] ?? 1 ),
			'run_id'       => sanitize_text_field( (string) ( $job['run_id'] ?? '' ) ),
			'source'       => sanitize_key( (string) ( $job['source'] ?? 'auto_cron' ) ),
			'log_id'       => is_string( $log_id ) ? $log_id : '',
			'scheduled_at' => time(),
		);

		update_post_meta( $post_id, '_negarandeh_pending_image', $payload );

		$args = array( $post_id );
		if ( ! wp_next_scheduled( self::FEATURED_IMAGE_HOOK, $args ) ) {
			wp_schedule_single_event( time() + 5, self::FEATURED_IMAGE_HOOK, $args );
		}

		$this->spawn_featured_image_loopback( $post_id );

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron( time() );
		}

		$this->report_stage_log(
			is_string( $log_id ) ? $log_id : '',
			array(
				'status'  => 'info',
				'topic'   => (string) ( $job['topic'] ?? '' ),
				'post_id' => $post_id,
				'source'  => (string) ( $job['source'] ?? '' ),
				'phase'   => 'image',
				'message' => __( 'تلاش مجدد ذخیره تصویر شاخص در صف قرار گرفت.', 'negarandeh' ),
			),
			true,
			false
		);

		return true;
	}

	/**
	 * Fire a non-blocking loopback so pending image work does not wait for a visitor.
	 */
	private function spawn_featured_image_loopback( int $post_id ): void {
		$token = wp_hash( 'negarandeh_image_' . $post_id );
		$url   = add_query_arg(
			array(
				'negarandeh_do_image' => 1,
				'post_id'             => $post_id,
				'token'               => $token,
			),
			home_url( '/' )
		);

		wp_remote_get(
			$url,
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
	}

	/**
	 * Public entry for loopback / WP-Cron image jobs.
	 *
	 * @param int $post_id Post ID.
	 */
	public function process_pending_featured_image( int $post_id ): void {
		$this->cron_generate_featured_image( $post_id );
	}

	/**
	 * Handle ?negarandeh_do_image=1 loopback requests.
	 */
	public function maybe_handle_image_loopback(): void {
		if ( empty( $_GET['negarandeh_do_image'] ) || empty( $_GET['post_id'] ) || empty( $_GET['token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$post_id = absint( $_GET['post_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token   = sanitize_text_field( wp_unslash( (string) $_GET['token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $post_id < 1 || ! hash_equals( wp_hash( 'negarandeh_image_' . $post_id ), $token ) ) {
			status_header( 403 );
			exit;
		}

		self::prepare_long_running_request( 900 );
		$this->cron_generate_featured_image( $post_id );

		status_header( 204 );
		exit;
	}

	/**
	 * Recover stuck pending images when someone hits the site.
	 */
	public function maybe_recover_pending_featured_images(): void {
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( ! empty( $_GET['negarandeh_do_image'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$posts = get_posts(
			array(
				'post_type'              => 'post',
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'             => array(
					array(
						'key'     => '_negarandeh_pending_image',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		if ( empty( $posts[0] ) ) {
			return;
		}

		$post_id = (int) $posts[0];
		$args    = array( $post_id );
		if ( ! wp_next_scheduled( self::FEATURED_IMAGE_HOOK, $args ) ) {
			wp_schedule_single_event( time() + 1, self::FEATURED_IMAGE_HOOK, $args );
		}
		$this->spawn_featured_image_loopback( $post_id );
	}

	/**
	 * Run featured image generation; catch fatals/timeouts so article log is preserved.
	 *
	 * @param string $log_id Permanent log entry ID for this topic run.
	 * @return string|null Warning message or null on success / intentional skip.
	 */
	private function run_featured_image_step( int $post_id, array $content, array $job, array &$queue, string $topic, string $log_id = '' ): ?string {
		try {
			return $this->generate_featured_image_for_post( $post_id, $content, $job, $queue, $topic, $log_id );
		} catch ( \Throwable $e ) {
			$message = $e->getMessage();
			if ( '' === trim( $message ) ) {
				$message = __( 'خطای ناشناخته هنگام ذخیره تصویر شاخص.', 'negarandeh' );
			}
			$this->record_image_problem( $post_id, $topic, $message, $log_id, (string) ( $job['source'] ?? '' ) );
			self::clear_active_stage();
			return $message;
		}
	}

	/**
	 * Generate featured image after the post is saved.
	 *
	 * @param int                   $post_id Post ID.
	 * @param array<string,mixed>   $content Parsed AI content.
	 * @param array<string,mixed>   $job     Queue job data.
	 * @param array<string,mixed>   $queue   Queue state (updated when non-empty run_id).
	 * @param string                $topic   Topic label.
	 * @param string                $log_id  Permanent log entry ID.
	 * @return string|null Image warning message or null on success / intentional skip.
	 */
	private function generate_featured_image_for_post( int $post_id, array $content, array $job, array &$queue, string $topic, string $log_id = '' ): ?string {
		$source   = (string) ( $job['source'] ?? '' );
		$settings = self::get_generator_settings();

		if ( empty( $settings['generate_image'] ) ) {
			$this->report_stage_log(
				$log_id,
				array(
					'status'  => 'info',
					'topic'   => $topic,
					'post_id' => $post_id,
					'source'  => $source,
					'phase'   => 'image',
					'message' => __( 'تولید تصویر شاخص غیرفعال است؛ مرحله تصویر رد شد.', 'negarandeh' ),
				),
				true,
				false
			);
			return null;
		}

		$existing_thumb_id = (int) get_post_thumbnail_id( $post_id );
		if ( NEGARANDEH_Image_Handler::post_has_usable_featured_image( $post_id ) ) {
			$this->report_stage_log(
				$log_id,
				array(
					'status'  => 'info',
					'topic'   => $topic,
					'post_id' => $post_id,
					'source'  => $source,
					'phase'   => 'image',
					'message' => sprintf(
						/* translators: %d: attachment ID */
						__( 'پست از قبل تصویر شاخص معتبر داشت (پیوست #%d)؛ تولید تصویر رد شد.', 'negarandeh' ),
						$existing_thumb_id
					),
				),
				true,
				false
			);
			return null;
		}

		// Orphaned/broken _thumbnail_id was cleared by the usable-image check — continue generation.
		if ( $existing_thumb_id > 0 ) {
			$this->report_stage_log(
				$log_id,
				array(
					'status'  => 'warning',
					'topic'   => $topic,
					'post_id' => $post_id,
					'source'  => $source,
					'phase'   => 'image',
					'message' => sprintf(
						/* translators: %d: broken attachment ID */
						__( 'تصویر شاخص نامعتبر (پیوست #%d) پاک شد؛ تولید مجدد شروع می‌شود.', 'negarandeh' ),
						$existing_thumb_id
					),
				),
				true,
				false
			);
		}

		if ( ! empty( $queue['run_id'] ) ) {
			$queue['phase']       = 'image';
			$queue['phase_label'] = sprintf(
				/* translators: %s: topic label */
				__( 'تولید تصویر: %s', 'negarandeh' ),
				$topic
			);
			update_option( self::QUEUE_OPTION, $queue, false );
		}

		$this->begin_active_stage(
			'image',
			array(
				'log_id'  => $log_id,
				'topic'   => $topic,
				'post_id' => $post_id,
				'source'  => $source,
			)
		);

		$this->report_stage_log(
			$log_id,
			array(
				'status'  => 'info',
				'topic'   => $topic,
				'post_id' => $post_id,
				'source'  => $source,
				'phase'   => 'image',
				'message' => __( 'در حال تولید و ذخیره تصویر شاخص...', 'negarandeh' ),
			),
			true,
			false
		);

		self::prepare_long_running_request( 900 );

		$image_prompt = NEGARANDEH_Content_Generator::resolve_image_prompt( $content, $job );
		if ( ! $image_prompt ) {
			$message = __( 'پرامپت تصویر خالی بود.', 'negarandeh' );
			$this->record_image_problem( $post_id, $topic, $message, $log_id, $source );
			self::clear_active_stage();
			return $message;
		}

		NEGARANDEH_Avalai_API::reset_last_usage();
		$alt = $content['image_alt'] ?? ( $content['title'] ?? '' );

		$response = NEGARANDEH_Avalai_API::generate_image(
			$image_prompt,
			array( 'featured_save' => true )
		);

		if ( is_wp_error( $response ) ) {
			$message = NEGARANDEH_Avalai_API::format_error_for_display( $response );
			$this->record_image_problem( $post_id, $topic, $message, $log_id, $source );
			self::clear_active_stage();
			return $message;
		}

		$response = NEGARANDEH_Avalai_API::normalize_image_response( $response );
		if ( ! NEGARANDEH_Avalai_API::response_has_image( $response ) ) {
			$message = __( 'API تصویر برگرداند ولی دادهٔ قابل ذخیره دریافت نشد.', 'negarandeh' );
			$this->record_image_problem( $post_id, $topic, $message, $log_id, $source );
			self::clear_active_stage();
			return $message;
		}

		$image_result = NEGARANDEH_Image_Handler::attach_api_image_to_post( $post_id, $response, $alt );

		if ( is_wp_error( $image_result ) ) {
			$message = NEGARANDEH_Avalai_API::format_error_for_display( $image_result );
			$this->record_image_problem( $post_id, $topic, $message, $log_id, $source );
			self::clear_active_stage();
			return $message;
		}

		if ( ! NEGARANDEH_Image_Handler::post_has_usable_featured_image( $post_id ) ) {
			update_post_meta( $post_id, '_thumbnail_id', (int) $image_result );
			clean_post_cache( $post_id );
		}

		if ( ! NEGARANDEH_Image_Handler::post_has_usable_featured_image( $post_id ) ) {
			$message = __( 'فایل تصویر ذخیره شد ولی شاخص پست تنظیم نشد.', 'negarandeh' );
			$this->record_image_problem( $post_id, $topic, $message, $log_id, $source );
			self::clear_active_stage();
			return $message;
		}

		delete_post_meta( $post_id, '_negarandeh_image_error' );

		if ( ! empty( $settings['insert_image_in_post'] ) ) {
			NEGARANDEH_Post_Creator::insert_image_after_first_paragraph( $post_id, (int) $image_result, $alt );
		}

		$this->report_stage_log(
			$log_id,
			array(
				'status'         => 'success',
				'topic'          => $topic,
				'post_id'        => $post_id,
				'source'         => $source,
				'phase'          => 'image',
				'attachment_id'  => (int) $image_result,
				'image_warning'  => null,
				'message'        => sprintf(
					/* translators: %d: attachment ID */
					__( 'تصویر شاخص ذخیره شد (پیوست #%d).', 'negarandeh' ),
					(int) $image_result
				),
			),
			true,
			false
		);

		if ( '' !== $log_id ) {
			$this->update_permanent_log_entry(
				$log_id,
				array(
					'time'          => current_time( 'mysql' ),
					'phase'         => 'done',
					'image_ok'      => 1,
					'attachment_id' => (int) $image_result,
					'image_warning' => null,
					'message'       => __( 'مقاله و تصویر شاخص آماده شد.', 'negarandeh' ),
				)
			);
		}

		self::clear_active_stage();
		return null;
	}

	/**
	 * Persist image failure to post meta + Negarandeh permanent log (always).
	 */
	private function record_image_problem( int $post_id, string $topic, string $message, string $log_id = '', string $source = '' ): void {
		if ( $post_id > 0 ) {
			update_post_meta( $post_id, '_negarandeh_image_error', $message );
		}

		$entry = array(
			'status'        => 'warning',
			'topic'         => $topic,
			'post_id'       => $post_id > 0 ? $post_id : null,
			'source'        => $source,
			'phase'         => 'image',
			'image_warning' => $message,
			'message'       => $message,
			'title'         => $post_id > 0 ? get_the_title( $post_id ) : '',
			'edit_url'      => $post_id > 0 ? esc_url_raw( (string) get_edit_post_link( $post_id, 'raw' ) ) : '',
			'time'          => current_time( 'mysql' ),
		);

		// Dedicated image-failure line (does not rewrite the article row as a vague "موفق").
		$this->append_permanent_log( $entry );

		if ( '' !== $log_id ) {
			$this->update_permanent_log_entry(
				$log_id,
				array(
					'status'        => 'warning',
					'phase'         => 'image',
					'image_warning' => $message,
					'message'       => $message,
					'time'          => current_time( 'mysql' ),
				)
			);
		}

		if ( $topic ) {
			self::set_topic_status(
				$topic,
				'warning',
				array(
					'message' => $message,
					'post_id' => $post_id > 0 ? $post_id : null,
				)
			);
		}
	}

	/**
	 * Write a stage event into the permanent log.
	 *
	 * @param array<string,mixed> $entry         Log fields.
	 * @param bool                $as_append     Append a standalone line (recommended for image stages).
	 * @param bool                $update_parent Also merge into the parent topic log row.
	 */
	private function report_stage_log( string $log_id, array $entry, bool $as_append = false, bool $update_parent = true ): void {
		$entry['time'] = current_time( 'mysql' );

		if ( $update_parent && '' !== $log_id ) {
			$this->update_permanent_log_entry( $log_id, $entry );
		}

		if ( $as_append ) {
			$this->append_permanent_log( $entry );
		} elseif ( '' === $log_id || ! $update_parent ) {
			$this->append_permanent_log( $entry );
		}
	}

	/**
	 * Mark an in-flight stage so abrupt death (timeout/fatal) is logged.
	 *
	 * @param array<string,mixed> $context Context fields.
	 */
	private function begin_active_stage( string $phase, array $context = array() ): void {
		self::$active_stage = array_merge(
			array(
				'phase'  => $phase,
				'log_id' => '',
				'topic'  => '',
				'post_id'=> 0,
				'source' => '',
				'since'  => time(),
			),
			$context,
			array( 'phase' => $phase )
		);
	}

	private static function clear_active_stage(): void {
		self::$active_stage = null;
	}

	/**
	 * If PHP dies mid-generation, still write a warning to the Negarandeh log.
	 */
	public function shutdown_flush_active_stage(): void {
		if ( null === self::$active_stage || ! is_array( self::$active_stage ) ) {
			return;
		}

		$stage = self::$active_stage;
		self::$active_stage = null;

		$phase   = (string) ( $stage['phase'] ?? '' );
		$topic   = (string) ( $stage['topic'] ?? '' );
		$post_id = (int) ( $stage['post_id'] ?? 0 );
		$log_id  = (string) ( $stage['log_id'] ?? '' );
		$source  = (string) ( $stage['source'] ?? '' );

		$error = error_get_last();
		$fatal = is_array( $error ) && in_array(
			(int) ( $error['type'] ?? 0 ),
			array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ),
			true
		);

		$phase_label = 'image' === $phase
			? __( 'تصویر شاخص', 'negarandeh' )
			: ( 'content' === $phase ? __( 'تولید مقاله', 'negarandeh' ) : $phase );

		if ( $fatal ) {
			$message = sprintf(
				/* translators: 1: stage label, 2: PHP error */
				__( 'فرایند در مرحله «%1$s» با خطای سرور قطع شد: %2$s', 'negarandeh' ),
				$phase_label,
				(string) ( $error['message'] ?? '' )
			);
		} else {
			$message = sprintf(
				/* translators: %s: stage label */
				__( 'فرایند در مرحله «%s» ناتمام ماند (احتمالاً timeout یا قطع هاست).', 'negarandeh' ),
				$phase_label
			);
		}

		if ( 'image' === $phase ) {
			$this->record_image_problem( $post_id, $topic, $message, $log_id, $source );
			return;
		}

		$entry = array(
			'status'  => 'error',
			'topic'   => $topic,
			'post_id' => $post_id > 0 ? $post_id : null,
			'source'  => $source,
			'phase'   => $phase,
			'message' => $message,
			'time'    => current_time( 'mysql' ),
		);

		$this->append_permanent_log( $entry );
		if ( '' !== $log_id ) {
			$this->update_permanent_log_entry( $log_id, $entry );
		}
	}

	/**
	 * Mark a queue item complete when the post was saved but the queue step did not finish.
	 *
	 * @param array<string,mixed> $queue       Queue state.
	 * @param int                 $index       Current topic index.
	 * @param string              $topic       Topic label.
	 * @param array<int,string>   $topics      All topics.
	 * @param int                 $post_id     Existing post ID.
	 * @param string              $run_id      Queue run ID.
	 * @param array<string,mixed> $content     AI content for image retry (optional).
	 */
	private function finalize_queue_item_success( array $queue, int $index, string $topic, array $topics, int $post_id, string $run_id, array $content = array() ): void {
		if ( empty( $content ) ) {
			$content = array(
				'title'        => get_the_title( $post_id ),
				'image_prompt' => '',
				'image_alt'    => get_the_title( $post_id ),
			);
		}

		$job = array(
			'topic'  => $topic,
			'topics' => $topics,
			'index'  => $index + 1,
			'run_id' => $run_id,
			'source' => 'manual_queue',
		);

		$result = array(
			'topic'     => $topic,
			'index'     => $index + 1,
			'status'    => 'success',
			'post_id'   => $post_id,
			'title'     => get_the_title( $post_id ),
			'edit_url'  => esc_url_raw( (string) get_edit_post_link( $post_id, 'raw' ) ),
			'source'    => 'manual_queue',
			'time'      => current_time( 'mysql' ),
			'recovered' => true,
		);

		$log_id = $this->append_permanent_log( $result );

		$image_warning = $this->run_featured_image_step( $post_id, $content, $job, $queue, $topic, $log_id );
		if ( $image_warning ) {
			$result['image_warning'] = $image_warning;
			$result['message']       = $image_warning;
			$this->schedule_deferred_featured_image( $post_id, $content, $job, $log_id );
		}

		self::set_topic_status(
			$topic,
			'success',
			array(
				'message' => $result['title'],
				'post_id' => $post_id,
			)
		);

		$queue['results'][]           = $result;
		$queue['completed_indices'][] = $index;
		$queue['current']             = $index + 1;
		unset( $queue['phase'], $queue['phase_label'] );

		if ( $queue['current'] >= count( $topics ) ) {
			$queue['status']   = 'completed';
			$queue['finished'] = current_time( 'mysql' );
		} else {
			self::schedule_queue_once();
		}

		update_option( self::QUEUE_OPTION, $queue, false );
	}

	/**
	 * @return array<int,string>
	 */
	public static function parse_topics_list( string $raw ): array {
		$lines = preg_split( '/[\r\n,]+/', $raw );

		return array_values(
			array_filter(
				array_map( 'trim', is_array( $lines ) ? $lines : array() ),
				static function ( $t ) {
					return '' !== $t;
				}
			)
		);
	}

	public static function schedule_auto_cron( ?int $minutes = null ): void {
		wp_clear_scheduled_hook( self::AUTO_CRON_HOOK );

		$minutes = null !== $minutes ? max( 1, min( 5, $minutes ) ) : self::get_cron_interval_minutes();
		wp_schedule_event(
			time() + ( $minutes * MINUTE_IN_SECONDS ),
			self::get_cron_schedule_name( $minutes ),
			self::AUTO_CRON_HOOK
		);
	}

	public static function unschedule_auto_cron(): void {
		wp_clear_scheduled_hook( self::AUTO_CRON_HOOK );
	}

	public static function sync_auto_cron_schedule( bool $enabled, ?int $minutes = null ): void {
		self::unschedule_auto_cron();

		if ( $enabled ) {
			self::schedule_auto_cron( $minutes );
		}
	}

	public function ensure_auto_cron_scheduled(): void {
		if ( ! self::is_auto_cron_enabled() || ! self::is_automation_enabled() ) {
			return;
		}

		$expected = self::get_cron_schedule_name();
		$current  = function_exists( 'wp_get_schedule' ) ? wp_get_schedule( self::AUTO_CRON_HOOK ) : false;

		if ( ! wp_next_scheduled( self::AUTO_CRON_HOOK ) || ! $current || $current !== $expected ) {
			self::schedule_auto_cron();
		}
	}

	/**
	 * Cron interval in minutes (1–5).
	 */
	public static function get_cron_interval_minutes(): int {
		$gen     = self::get_generator_settings();
		$minutes = is_array( $gen ) ? (int) ( $gen['cron_interval_minutes'] ?? 1 ) : 1;

		return max( 1, min( 5, $minutes ) );
	}

	/**
	 * WP-Cron schedule slug for the configured interval.
	 */
	public static function get_cron_schedule_name( ?int $minutes = null ): string {
		$minutes = null !== $minutes ? max( 1, min( 5, $minutes ) ) : self::get_cron_interval_minutes();

		return 'negarandeh_every_' . $minutes . '_minutes';
	}

	/**
	 * Minutes for the currently scheduled WP-Cron event (may differ until settings are saved).
	 */
	public static function get_active_cron_interval_minutes(): int {
		$schedule = function_exists( 'wp_get_schedule' ) ? wp_get_schedule( self::AUTO_CRON_HOOK ) : false;

		if ( is_string( $schedule ) && preg_match( '/^negarandeh_every_(\d+)_minutes$/', $schedule, $matches ) ) {
			return max( 1, min( 5, (int) $matches[1] ) );
		}

		if ( 'negarandeh_every_five_minutes' === $schedule ) {
			return 5;
		}

		return self::get_cron_interval_minutes();
	}

	/**
	 * Human-readable cron interval label.
	 */
	public static function get_cron_interval_label( ?int $minutes = null ): string {
		$minutes = null !== $minutes ? max( 1, min( 5, $minutes ) ) : self::get_cron_interval_minutes();

		/* translators: %d: number of minutes */
		return sprintf( _n( 'هر %d دقیقه', 'هر %d دقیقه', $minutes, 'negarandeh' ), $minutes );
	}

	/**
	 * Sidebar / status line when auto cron is running.
	 */
	public static function get_cron_running_label( ?int $minutes = null ): string {
		$minutes = null !== $minutes ? max( 1, min( 5, $minutes ) ) : self::get_active_cron_interval_minutes();

		return sprintf(
			/* translators: %s: interval label e.g. "هر ۱ دقیقه" */
			__( 'در حال اجرا — %s', 'negarandeh' ),
			self::get_cron_interval_label( $minutes )
		);
	}

	/**
	 * @return int|false Next cron run timestamp or false.
	 */
	public static function get_next_auto_cron_run() {
		return wp_next_scheduled( self::AUTO_CRON_HOOK );
	}

	public static function is_auto_cron_enabled(): bool {
		return 'wp_cron' === self::get_queue_driver();
	}

	public static function get_status_label( string $status ): string {
		$labels = array(
			'success' => __( 'موفق', 'negarandeh' ),
			'error'   => __( 'خطا', 'negarandeh' ),
			'skipped' => __( 'رد شد', 'negarandeh' ),
			'warning' => __( 'هشدار', 'negarandeh' ),
			'pending' => __( 'در انتظار', 'negarandeh' ),
			'info'    => __( 'در حال انجام', 'negarandeh' ),
		);

		return $labels[ $status ] ?? $status;
	}

	public static function get_queue_driver(): string {
		$gen    = self::get_generator_settings();
		$driver = is_array( $gen ) ? (string) ( $gen['queue_driver'] ?? 'wp_cron' ) : 'wp_cron';

		return in_array( $driver, array( 'ajax', 'wp_cron' ), true ) ? $driver : 'wp_cron';
	}

	/**
	 * Run one queue step via AJAX (reliable on WAMP without wp-cron).
	 */
	public function ajax_process_step(): void {
		check_ajax_referer( 'negarandeh_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'دسترسی غیرمجاز.', 'negarandeh' ) ) );
		}

		if ( ! self::is_automation_enabled() ) {
			wp_send_json_error(
				array_merge(
					$this->get_status_payload(),
					array( 'message' => __( 'تولید غیرفعال است. ابتدا Start را بزنید.', 'negarandeh' ) )
				)
			);
		}

		self::extend_time_limit( 600 );

		$processed = $this->process_next();

		wp_send_json_success(
			array_merge(
				$this->get_status_payload(),
				array( 'step_ran' => $processed )
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function get_status_payload(): array {
		$queue  = get_option( self::QUEUE_OPTION, array() );
		$gen    = self::get_generator_settings();
		$topics = self::parse_topics_list( is_array( $gen ) ? (string) ( $gen['topics'] ?? '' ) : '' );
		$log    = self::get_permanent_log( 100 );

		return array(
			'queue'              => $queue,
			'log'                => $log,
			'topic_board'        => self::get_topic_status_board( $topics ),
			'topic_stats'        => self::get_topic_stats( $topics ),
			'next_auto_cron'               => (int) self::get_next_auto_cron_run(),
			'automation_enabled'           => self::is_automation_enabled() ? 1 : 0,
			'cron_interval_minutes'        => self::get_cron_interval_minutes(),
			'cron_active_interval_minutes' => self::get_active_cron_interval_minutes(),
		);
	}

	/**
	 * Prevent concurrent queue workers (duplicate posts / API charges).
	 */
	private static function acquire_lock(): bool {
		$lock = get_option( self::LOCK_OPTION, array() );
		if ( is_array( $lock ) && ! empty( $lock['since'] ) ) {
			$age = time() - (int) $lock['since'];
			if ( $age < 15 * MINUTE_IN_SECONDS ) {
				return false;
			}
		}

		update_option(
			self::LOCK_OPTION,
			array(
				'since' => time(),
				'pid'   => function_exists( 'getmypid' ) ? getmypid() : 0,
			),
			false
		);

		return true;
	}

	private static function release_lock(): void {
		delete_option( self::LOCK_OPTION );
		delete_transient( 'negarandeh_queue_processing_lock' );
	}

	/**
	 * Separate lock so featured-image cron does not block article generation.
	 */
	private static function acquire_image_lock(): bool {
		$lock = get_option( self::IMAGE_LOCK_OPTION, array() );
		if ( is_array( $lock ) && ! empty( $lock['since'] ) ) {
			$age = time() - (int) $lock['since'];
			if ( $age < 20 * MINUTE_IN_SECONDS ) {
				return false;
			}
		}

		update_option(
			self::IMAGE_LOCK_OPTION,
			array(
				'since' => time(),
				'pid'   => function_exists( 'getmypid' ) ? getmypid() : 0,
			),
			false
		);

		return true;
	}

	private static function release_image_lock(): void {
		delete_option( self::IMAGE_LOCK_OPTION );
	}

	/**
	 * Delay between manual queue steps (seconds).
	 */
	private static function get_queue_step_delay_seconds(): int {
		if ( 'wp_cron' === self::get_queue_driver() ) {
			return self::get_cron_interval_minutes() * MINUTE_IN_SECONDS;
		}

		return (int) apply_filters( 'negarandeh_queue_delay_seconds', 30 );
	}

	/**
	 * Schedule exactly one cron run (no duplicate events).
	 */
	private static function schedule_queue_once( int $delay_seconds = 0 ): void {
		if ( $delay_seconds < 1 ) {
			$delay_seconds = self::get_queue_step_delay_seconds();
		}
		wp_clear_scheduled_hook( self::MANUAL_QUEUE_HOOK );
		wp_schedule_single_event( time() + max( 1, $delay_seconds ), self::MANUAL_QUEUE_HOOK );
	}

	/**
	 * Skip if this queue run already created a post for the topic.
	 */
	private static function post_exists_for_queue_item( string $topic, string $run_id ): bool {
		if ( '' === $run_id || '' === $topic ) {
			return false;
		}

		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to prevent duplicate posts for the same queue run/topic.
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_negarandeh_queue_run',
						'value' => $run_id,
					),
					array(
						'key'   => '_negarandeh_topic',
						'value' => $topic,
					),
				),
			)
		);

		return ! empty( $posts );
	}

	public static function normalize_topic_key( string $topic ): string {
		return md5( mb_strtolower( trim( $topic ) ) );
	}

	public static function post_exists_for_topic( string $topic ): bool {
		return (bool) self::get_existing_post_id_for_topic( $topic );
	}

	public static function get_existing_post_id_for_topic( string $topic ): int {
		$topic = trim( $topic );
		if ( '' === $topic ) {
			return 0;
		}

		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Topic lookup is intentionally stored as post meta for generated post deduplication.
				'meta_query'     => array(
					array(
						'key'   => '_negarandeh_topic',
						'value' => $topic,
					),
				),
			)
		);

		return ! empty( $posts[0] ) ? (int) $posts[0] : 0;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function get_topic_status( string $topic ): array {
		$key    = self::normalize_topic_key( $topic );
		$all    = get_option( self::TOPIC_STATUS_OPTION, array() );
		$stored = is_array( $all ) && isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : array();

		return wp_parse_args(
			$stored,
			array(
				'topic'      => $topic,
				'status'     => 'pending',
				'message'    => '',
				'post_id'    => 0,
				'updated_at' => '',
			)
		);
	}

	/**
	 * @param array<string,mixed> $extra Extra fields.
	 */
	public static function set_topic_status( string $topic, string $status, array $extra = array() ): void {
		$key = self::normalize_topic_key( $topic );
		$all = get_option( self::TOPIC_STATUS_OPTION, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}

		$all[ $key ] = array_merge(
			array(
				'topic'      => $topic,
				'status'     => $status,
				'message'    => '',
				'post_id'    => 0,
				'updated_at' => current_time( 'mysql' ),
			),
			$extra,
			array(
				'topic'      => $topic,
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			)
		);

		update_option( self::TOPIC_STATUS_OPTION, $all, false );
	}

	public static function is_topic_marked_failed( string $topic ): bool {
		$status = self::get_topic_status( $topic )['status'] ?? 'pending';

		return 'error' === $status;
	}

	public static function reset_failed_topics(): int {
		$all     = get_option( self::TOPIC_STATUS_OPTION, array() );
		$changed = 0;

		if ( ! is_array( $all ) ) {
			return 0;
		}

		foreach ( $all as $key => $row ) {
			if ( ! is_array( $row ) || 'error' !== ( $row['status'] ?? '' ) ) {
				continue;
			}
			unset( $all[ $key ] );
			++$changed;
		}

		update_option( self::TOPIC_STATUS_OPTION, $all, false );

		return $changed;
	}

	/**
	 * Allow regenerating posts for topics that already have content.
	 *
	 * Unlinks `_negarandeh_topic` meta (old posts stay), clears topic status,
	 * resets cron index, clears the queue, and stops automation if it was on.
	 *
	 * @param array<int,string>|null $topics Topics to reset; null = current list.
	 * @return array{cleared:int,unlinked:int,stopped:bool}
	 */
	public static function reset_generated_topics( ?array $topics = null ): array {
		$gen = self::get_generator_settings();
		if ( null === $topics ) {
			$topics = self::parse_topics_list( is_array( $gen ) ? (string) ( $gen['topics'] ?? '' ) : '' );
		}

		$was_enabled = self::is_automation_enabled();
		if ( $was_enabled ) {
			$gen                       = is_array( $gen ) ? $gen : array();
			$gen['automation_enabled'] = 0;
			update_option( 'negarandeh_generator_settings', $gen );
		}

		self::stop_all_generation();
		wp_clear_scheduled_hook( self::MANUAL_QUEUE_HOOK );
		delete_option( self::LOCK_OPTION );
		delete_transient( 'negarandeh_queue_processing_lock' );
		delete_option( self::QUEUE_OPTION );
		update_option( self::CRON_INDEX_OPTION, 0, false );

		$all     = get_option( self::TOPIC_STATUS_OPTION, array() );
		$all     = is_array( $all ) ? $all : array();
		$cleared = 0;
		$unlinked = 0;

		foreach ( $topics as $topic ) {
			$topic = trim( (string) $topic );
			if ( '' === $topic ) {
				continue;
			}

			$unlinked += self::unlink_topic_from_posts( $topic );

			$key = self::normalize_topic_key( $topic );
			if ( isset( $all[ $key ] ) ) {
				unset( $all[ $key ] );
				++$cleared;
			}
		}

		update_option( self::TOPIC_STATUS_OPTION, $all, false );
		NEGARANDEH_Post_Creator::reset_schedule_sequence();

		return array(
			'cleared'  => $cleared,
			'unlinked' => $unlinked,
			'stopped'  => $was_enabled,
		);
	}

	/**
	 * Remove topic meta so the same topic can be generated again.
	 * Existing posts are kept.
	 */
	public static function unlink_topic_from_posts( string $topic ): int {
		$topic = trim( $topic );
		if ( '' === $topic ) {
			return 0;
		}

		$posts = get_posts(
			array(
				'post_type'              => 'post',
				'post_status'            => 'any',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Needed to clear topic dedup links for regenerate.
				'meta_query'             => array(
					array(
						'key'   => '_negarandeh_topic',
						'value' => $topic,
					),
				),
			)
		);

		$count = 0;
		foreach ( $posts as $post_id ) {
			delete_post_meta( (int) $post_id, '_negarandeh_topic' );
			delete_post_meta( (int) $post_id, '_negarandeh_queue_run' );
			++$count;
		}

		return $count;
	}

	/**
	 * @param array<int,string> $topics Topic list.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_topic_status_board( array $topics ): array {
		$board = array();

		foreach ( $topics as $topic ) {
			$row     = self::get_topic_status( $topic );
			$post_id = self::get_existing_post_id_for_topic( $topic );

			if ( $post_id && 'success' !== ( $row['status'] ?? '' ) && 'warning' !== ( $row['status'] ?? '' ) ) {
				$row['status']  = 'success';
				$row['post_id'] = $post_id;
				$row['message'] = __( 'پست موجود', 'negarandeh' );
			} elseif ( ! $post_id && 'pending' === ( $row['status'] ?? 'pending' ) ) {
				$row['status'] = 'pending';
			}

			$row['topic'] = $topic;
			$board[]      = $row;
		}

		return $board;
	}

	/**
	 * @param array<int,string> $topics Topic list.
	 * @return array{total:int,success:int,error:int,pending:int,warning:int}
	 */
	public static function get_topic_stats( array $topics ): array {
		$stats = array(
			'total'   => count( $topics ),
			'success' => 0,
			'error'   => 0,
			'pending' => 0,
			'warning' => 0,
		);

		foreach ( self::get_topic_status_board( $topics ) as $row ) {
			$status = $row['status'] ?? 'pending';
			if ( isset( $stats[ $status ] ) ) {
				++$stats[ $status ];
			}
		}

		return $stats;
	}

	/**
	 * Merge article + image token usage into a result/log entry.
	 *
	 * @param array<string,mixed> $result        Result/log entry.
	 * @param array<string,mixed> $article_usage Usage from the text request.
	 * @param array<string,mixed> $image_usage   Usage from the image request.
	 * @return array<string,mixed>
	 */
	public static function attach_usage_to_result( array $result, array $article_usage, array $image_usage = array() ): array {
		$article_total = (int) ( $article_usage['total_tokens'] ?? 0 );
		$image_total   = (int) ( $image_usage['total_tokens'] ?? 0 );

		if ( $article_total > 0 || $image_total > 0 || ! empty( $article_usage['estimated_cost'] ) || ! empty( $image_usage['estimated_cost'] ) ) {
			$est_cost = 0.0;
			$has_cost = false;
			foreach ( array( $article_usage, $image_usage ) as $u ) {
				if ( isset( $u['estimated_cost'] ) && is_numeric( $u['estimated_cost'] ) ) {
					$est_cost += (float) $u['estimated_cost'];
					$has_cost  = true;
				}
			}

			$result['usage'] = array(
				'article_prompt'     => (int) ( $article_usage['prompt_tokens'] ?? 0 ),
				'article_completion' => (int) ( $article_usage['completion_tokens'] ?? 0 ),
				'article_total'      => $article_total,
				'image_total'        => $image_total,
				'total'              => $article_total + $image_total,
				'estimated_cost'     => $has_cost ? $est_cost : null,
			);
		}

		return $result;
	}

	/**
	 * Human-readable usage summary for a log entry, or '' when no usage recorded.
	 *
	 * @param array<string,mixed> $entry Log entry.
	 */
	public static function format_usage_label( array $entry ): string {
		if ( empty( $entry['usage'] ) || ! is_array( $entry['usage'] ) ) {
			return '';
		}

		$u     = $entry['usage'];
		$parts = array();

		$article_total = (int) ( $u['article_total'] ?? 0 );
		if ( $article_total > 0 ) {
			$parts[] = sprintf(
				/* translators: 1: prompt tokens, 2: completion tokens, 3: total tokens */
				__( 'مقاله: %1$s ورودی + %2$s خروجی = %3$s توکن', 'negarandeh' ),
				number_format_i18n( (int) ( $u['article_prompt'] ?? 0 ) ),
				number_format_i18n( (int) ( $u['article_completion'] ?? 0 ) ),
				number_format_i18n( $article_total )
			);
		}

		$image_total = (int) ( $u['image_total'] ?? 0 );
		if ( $image_total > 0 ) {
			$parts[] = sprintf(
				/* translators: %s: image tokens */
				__( 'تصویر: %s توکن', 'negarandeh' ),
				number_format_i18n( $image_total )
			);
		}

		if ( isset( $u['estimated_cost'] ) && is_numeric( $u['estimated_cost'] ) && (float) $u['estimated_cost'] > 0 ) {
			$parts[] = sprintf(
				/* translators: %s: estimated cost */
				__( 'هزینه تقریبی: %s', 'negarandeh' ),
				number_format_i18n( (float) $u['estimated_cost'], 4 )
			);
		}

		return implode( ' — ', $parts );
	}

	/**
	 * @param array<string,mixed> $entry Log entry.
	 * @return string Log entry ID.
	 */
	public function append_permanent_log( array $entry ): string {
		$entry = wp_parse_args(
			$entry,
			array(
				'id'     => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'log_', true ),
				'time'   => current_time( 'mysql' ),
				'status' => 'info',
			)
		);

		$log   = get_option( self::PERMANENT_LOG_OPTION, array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = $entry;

		if ( count( $log ) > self::PERMANENT_LOG_MAX ) {
			$log = array_slice( $log, -self::PERMANENT_LOG_MAX );
		}

		update_option( self::PERMANENT_LOG_OPTION, $log, false );

		return (string) $entry['id'];
	}

	/**
	 * @param string              $log_id Log entry ID from append_permanent_log().
	 * @param array<string,mixed> $patch  Fields to merge into the entry.
	 */
	public function update_permanent_log_entry( string $log_id, array $patch ): void {
		if ( '' === $log_id ) {
			return;
		}

		$log = get_option( self::PERMANENT_LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			return;
		}

		$updated = false;
		foreach ( $log as $index => $entry ) {
			if ( ! is_array( $entry ) || (string) ( $entry['id'] ?? '' ) !== $log_id ) {
				continue;
			}

			$merged = array_merge( $entry, $patch );
			foreach ( $patch as $key => $value ) {
				if ( null === $value ) {
					unset( $merged[ $key ] );
				}
			}

			if ( isset( $patch['image_usage'] ) && is_array( $patch['image_usage'] ) ) {
				$existing_usage = is_array( $merged['usage'] ?? null ) ? $merged['usage'] : array();
				$article_usage  = array(
					'prompt_tokens'     => (int) ( $existing_usage['article_prompt'] ?? 0 ),
					'completion_tokens' => (int) ( $existing_usage['article_completion'] ?? 0 ),
					'total_tokens'      => (int) ( $existing_usage['article_total'] ?? 0 ),
					'estimated_cost'    => $existing_usage['estimated_cost'] ?? null,
				);
				$merged         = self::attach_usage_to_result( $merged, $article_usage, $patch['image_usage'] );
				unset( $merged['image_usage'] );
			}

			if (
				isset( $patch['status'] )
				&& 'success' === $patch['status']
				&& empty( $patch['image_warning'] )
				&& ! array_key_exists( 'message', $patch )
			) {
				unset( $merged['message'] );
			}

			$log[ $index ] = $merged;
			$updated      = true;
			break;
		}

		if ( $updated ) {
			update_option( self::PERMANENT_LOG_OPTION, $log, false );
		}
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_permanent_log( int $limit = 100 ): array {
		$log = get_option( self::PERMANENT_LOG_OPTION, array() );
		$log = is_array( $log ) ? $log : array();

		return array_reverse( array_slice( $log, -max( 1, $limit ) ) );
	}

	public static function clear_permanent_log(): void {
		delete_option( self::PERMANENT_LOG_OPTION );
	}

	/**
	 * @param array<int,array<string,mixed>> $log Log entries (newest first).
	 */
	public static function render_log_list_html( array $log ): string {
		if ( empty( $log ) ) {
			return '<li class="description">' . esc_html__( 'لاگی ثبت نشده است.', 'negarandeh' ) . '</li>';
		}

		$html = '';
		foreach ( $log as $item ) {
			$status = (string) ( $item['status'] ?? 'info' );
			$phase  = (string) ( $item['phase'] ?? '' );
			$label  = (string) ( $item['topic'] ?? '' );
			$html  .= '<li class="negarandeh-log-' . esc_attr( $status ) . '">';
			$html  .= '<time>' . esc_html( (string) ( $item['time'] ?? '' ) ) . '</time> ';
			if ( ! empty( $item['source'] ) && 'auto_cron' === $item['source'] ) {
				$html .= '<em>[WP-Cron]</em> ';
			} elseif ( ! empty( $item['source'] ) && 'manual_queue' === $item['source'] ) {
				$html .= '<em>[' . esc_html__( 'صف', 'negarandeh' ) . ']</em> ';
			}
			if ( 'image' === $phase ) {
				$html .= '<em>[' . esc_html__( 'تصویر', 'negarandeh' ) . ']</em> ';
			} elseif ( 'content' === $phase ) {
				$html .= '<em>[' . esc_html__( 'مقاله', 'negarandeh' ) . ']</em> ';
			}
			if ( $label ) {
				$html .= '<strong>' . esc_html( $label ) . '</strong> ';
			}
			$html .= '<span class="negarandeh-log-status">' . esc_html( self::get_status_label( $status ) ) . '</span> ';

			if ( 'image' === $phase && ! empty( $item['message'] ) ) {
				$html .= '<span class="negarandeh-log-info-text">' . esc_html( (string) $item['message'] ) . '</span> ';
				if ( ! empty( $item['edit_url'] ) && in_array( $status, array( 'success', 'warning' ), true ) ) {
					$html .= '<a href="' . esc_url( (string) $item['edit_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'ویرایش', 'negarandeh' ) . '</a> ';
				}
			} elseif ( in_array( $status, array( 'success', 'warning' ), true ) ) {
				if ( ! empty( $item['title'] ) ) {
					$html .= esc_html( (string) $item['title'] ) . ' ';
				}
				if ( ! empty( $item['edit_url'] ) ) {
					$html .= '<a href="' . esc_url( (string) $item['edit_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'ویرایش', 'negarandeh' ) . '</a> ';
				}
				if ( ! empty( $item['message'] ) && ( empty( $item['title'] ) || (string) $item['message'] !== (string) $item['title'] ) ) {
					$html .= '<br><em class="negarandeh-log-info-text">' . esc_html( (string) $item['message'] ) . '</em>';
				}
				if ( ! empty( $item['image_warning'] ) ) {
					$html .= '<br><em class="negarandeh-log-warn-text">' . esc_html__( 'تصویر:', 'negarandeh' ) . '<br>' . esc_html( (string) $item['image_warning'] ) . '</em>';
				}
			} elseif ( 'info' === $status && ! empty( $item['message'] ) ) {
				$html .= '<span class="negarandeh-log-info-text">' . esc_html( (string) $item['message'] ) . '</span>';
			} elseif ( ! empty( $item['message'] ) ) {
				$html .= '<span class="negarandeh-log-error-text">' . esc_html( (string) $item['message'] ) . '</span>';
			}
			$usage_label = self::format_usage_label( $item );
			if ( '' !== $usage_label ) {
				$html .= ' <span class="negarandeh-log-usage">' . esc_html( $usage_label ) . '</span>';
			}
			$html .= '</li>';
		}

		return $html;
	}

	public function ajax_clear_log(): void {
		check_ajax_referer( 'negarandeh_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'دسترسی غیرمجاز.', 'negarandeh' ) ) );
		}

		self::clear_permanent_log();

		wp_send_json_success(
			array(
				'message' => __( 'لاگ پاک شد.', 'negarandeh' ),
				'log'     => array(),
			)
		);
	}

	public function ajax_reset_failed_topics(): void {
		check_ajax_referer( 'negarandeh_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'دسترسی غیرمجاز.', 'negarandeh' ) ) );
		}

		$count = self::reset_failed_topics();

		wp_send_json_success(
			array_merge(
				$this->get_status_payload(),
				array(
					'message' => sprintf(
						/* translators: %d: number of topics reset */
						__( '%d موضوع خطادار برای تلاش مجدد آزاد شد.', 'negarandeh' ),
						$count
					),
				)
			)
		);
	}

	public function ajax_reset_generated_topics(): void {
		check_ajax_referer( 'negarandeh_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'دسترسی غیرمجاز.', 'negarandeh' ) ) );
		}

		$result = self::reset_generated_topics();

		$message = sprintf(
			/* translators: 1: number of topic statuses cleared, 2: number of posts unlinked */
			__( 'ریست انجام شد: %1$d موضوع آزاد شد، لینک %2$d پست قبلی برداشته شد (پست‌ها حذف نشدند).', 'negarandeh' ),
			(int) $result['cleared'],
			(int) $result['unlinked']
		);

		if ( ! empty( $result['stopped'] ) ) {
			$message .= ' ' . __( 'تولید Stop شد.', 'negarandeh' );
		}

		wp_send_json_success(
			array_merge(
				$this->get_status_payload(),
				array(
					'message' => $message,
					'stopped' => ! empty( $result['stopped'] ),
				)
			)
		);
	}

	public function ajax_start(): void {
		check_ajax_referer( 'negarandeh_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'دسترسی غیرمجاز.', 'negarandeh' ) ) );
		}

		if ( ! self::is_automation_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'تولید غیرفعال است. ابتدا Start را بزنید.', 'negarandeh' ) ) );
		}

		$generator_settings = isset( $_POST['generator_settings'] )
			? map_deep( wp_unslash( (array) $_POST['generator_settings'] ), 'sanitize_textarea_field' )
			: array();

		if ( ! empty( $generator_settings ) ) {
			wp_send_json_error( array( 'message' => __( 'تنظیمات ذخیره نشده دارد. تنظیمات را ذخیره کنید یا صفحه را رفرش کنید که بتوانید شروع کنید.', 'negarandeh' ) ) );
		}

		$topics_raw = isset( $_POST['topics'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['topics'] ) ) : '';
		if ( ! $topics_raw ) {
			$gen        = self::get_generator_settings();
			$topics_raw = is_array( $gen ) ? (string) ( $gen['topics'] ?? '' ) : '';
		}

		$topics = self::parse_topics_list( $topics_raw );
		$result = $this->enqueue( $topics );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		}

		wp_send_json_error( $result );
	}

	public function ajax_status(): void {
		check_ajax_referer( 'negarandeh_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		$this->maybe_resume_stuck_queue();

		wp_send_json_success( $this->get_status_payload() );
	}

	/**
	 * If a running queue has a saved post but the step never finalized, complete it.
	 */
	public function maybe_resume_stuck_queue(): void {
		if ( ! self::is_automation_enabled() ) {
			return;
		}

		$queue = get_option( self::QUEUE_OPTION, array() );
		if ( empty( $queue ) || 'running' !== ( $queue['status'] ?? '' ) ) {
			return;
		}

		$index             = (int) ( $queue['current'] ?? 0 );
		$topics            = $queue['topics'] ?? array();
		$completed_indices = $queue['completed_indices'] ?? array();
		$run_id            = (string) ( $queue['run_id'] ?? '' );

		if ( ! $run_id || $index >= count( $topics ) || in_array( $index, $completed_indices, true ) ) {
			return;
		}

		$topic = $topics[ $index ];
		if ( ! self::post_exists_for_queue_item( $topic, $run_id ) ) {
			return;
		}

		if ( ! self::acquire_lock() ) {
			return;
		}

		try {
			$post_id = self::get_existing_post_id_for_topic( $topic );
			if ( $post_id ) {
				$this->finalize_queue_item_success( $queue, $index, $topic, $topics, $post_id, $run_id, array() );
			}
		} finally {
			self::release_lock();
		}
	}

	public function ajax_clear(): void {
		check_ajax_referer( 'negarandeh_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		wp_clear_scheduled_hook( self::MANUAL_QUEUE_HOOK );
		delete_option( self::LOCK_OPTION );
		delete_transient( 'negarandeh_queue_processing_lock' );
		delete_option( self::QUEUE_OPTION );

		wp_send_json_success();
	}

	/**
	 * Extend PHP execution time for long AI batch steps.
	 *
	 * @param int $seconds Seconds to allow.
	 */
	private static function extend_time_limit( int $seconds ): void {
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- AI content/image generation exceeds default limits.
			set_time_limit( max( 1, $seconds ) );
		}
	}

	/**
	 * Keep WP-Cron / background requests alive for long AI + upload work.
	 *
	 * @param int $seconds Seconds to allow.
	 */
	private static function prepare_long_running_request( int $seconds ): void {
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}

		self::extend_time_limit( $seconds );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function get_generator_settings(): array {
		$settings = get_option( 'negarandeh_generator_settings', array() );

		return is_array( $settings ) ? $settings : array();
	}
}
