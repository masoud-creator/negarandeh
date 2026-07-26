<?php
/**
 * Admin pages and settings.
 *
 * @package Negarandeh
 */

defined( 'ABSPATH' ) || exit;

class NEGARANDEH_Admin {

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'load-toplevel_page_' . NEGARANDEH_Plugin::SLUG, array( $this, 'maybe_spawn_overdue_cron' ) );
		add_action( 'wp_ajax_negarandeh_toggle_automation', array( $this, 'ajax_toggle_automation' ) );
		add_action( 'wp_ajax_negarandeh_get_credit', array( $this, 'ajax_get_credit' ) );
		add_action( 'wp_ajax_negarandeh_test_api', array( $this, 'ajax_test_api' ) );
		add_action( 'wp_ajax_negarandeh_test_image', array( $this, 'ajax_test_image' ) );
		add_action( 'wp_ajax_negarandeh_list_models', array( $this, 'ajax_list_models' ) );
		add_action( 'wp_ajax_negarandeh_preview_image_prompt', array( $this, 'ajax_preview_image_prompt' ) );
		add_action( 'wp_ajax_negarandeh_build_prompt', array( $this, 'ajax_build_prompt' ) );
	}

	public function register_menu(): void {
		add_menu_page(
			NEGARANDEH_Plugin::display_name(),
			NEGARANDEH_Plugin::display_name(),
			'manage_options',
			NEGARANDEH_Plugin::SLUG,
			array( $this, 'render_generator_page' ),
			'dashicons-welcome-write-blog',
			30
		);

		add_submenu_page(
			NEGARANDEH_Plugin::SLUG,
			__( 'تولید محتوا', 'negarandeh' ),
			__( 'تولید محتوا', 'negarandeh' ),
			'manage_options',
			NEGARANDEH_Plugin::SLUG,
			array( $this, 'render_generator_page' )
		);

		add_submenu_page(
			NEGARANDEH_Plugin::SLUG,
			__( 'تنظیمات API', 'negarandeh' ),
			__( 'تنظیمات API', 'negarandeh' ),
			'manage_options',
			NEGARANDEH_Plugin::SLUG_SETTINGS,
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			NEGARANDEH_Plugin::SLUG,
			__( 'لاگ تولید', 'negarandeh' ),
			__( 'لاگ تولید', 'negarandeh' ),
			'manage_options',
			NEGARANDEH_Plugin::SLUG_LOG,
			array( $this, 'render_log_page' )
		);

		add_submenu_page(
			NEGARANDEH_Plugin::SLUG,
			__( 'راهنما', 'negarandeh' ),
			__( 'راهنما', 'negarandeh' ),
			'manage_options',
			NEGARANDEH_Plugin::SLUG_GUIDE,
			array( $this, 'render_guide_page' )
		);
	}

	public function register_settings(): void {
		register_setting( 'negarandeh_settings_group', 'negarandeh_settings', array( $this, 'sanitize_api_settings' ) );
		register_setting( 'negarandeh_generator_group', 'negarandeh_generator_settings', array( $this, 'sanitize_generator_settings' ) );
	}

	/**
	 * @param array<string,mixed>|mixed $input Settings.
	 * @return array<string,mixed>
	 */
	public function sanitize_api_settings( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$existing = NEGARANDEH_Avalai_API::get_settings();
		$api_key  = NEGARANDEH_Avalai_API::normalize_api_key( $input['api_key'] ?? '' );
		$base_url = NEGARANDEH_Avalai_API::normalize_base_url( $input['api_base_url'] ?? '' );

		if ( '' === $api_key ) {
			$api_key = $existing['api_key'] ?? '';
		}

		if ( '' === $base_url ) {
			$base_url = $existing['api_base_url'] ?? NEGARANDEH_Avalai_API::DEFAULT_BASE_URL;
		}

		$image_mode = in_array( $input['image_api_mode'] ?? '', array( 'auto', 'chat', 'images' ), true )
			? $input['image_api_mode']
			: ( $existing['image_api_mode'] ?? 'auto' );

		$image_model = sanitize_text_field( $input['image_model'] ?? NEGARANDEH_Avalai_API::DEFAULT_IMAGE_MODEL );

		return array(
			'api_base_url'       => esc_url_raw( $base_url ),
			'image_api_base_url' => '',
			'image_api_mode'     => $image_mode,
			'api_key'            => $api_key,
			'chat_model'         => sanitize_text_field( $input['chat_model'] ?? 'gpt-4o-mini' ),
			'image_model'        => $image_model,
			'temperature'        => max( 0, min( 2, (float) ( $input['temperature'] ?? 0.7 ) ) ),
			'max_tokens'         => max( 500, min( 16000, (int) ( $input['max_tokens'] ?? 4096 ) ) ),
			'image_size'         => in_array( $input['image_size'] ?? '', array( '1200x675', '1792x1024' ), true )
				? $input['image_size']
				: '1200x675',
			'ui_language'        => in_array( $input['ui_language'] ?? '', array( 'fa', 'en', 'auto' ), true )
				? $input['ui_language']
				: ( $existing['ui_language'] ?? 'auto' ),
		);
	}

	/**
	 * Effective generator settings as rendered in the admin form (includes UI defaults).
	 *
	 * @param array<string,mixed>|null $settings Raw option value; loads from DB when null.
	 * @return array<string,mixed>
	 */
	public function resolve_generator_settings_for_ui( ?array $settings = null ): array {
		if ( null === $settings ) {
			$settings = get_option( 'negarandeh_generator_settings', array() );
		}
		$settings = is_array( $settings ) ? $settings : array();

		$defaults = array(
			'prompt_template'         => '',
			'topics'                  => NEGARANDEH_Plugin::default_topics_list(),
			'post_status'             => 'draft',
			'schedule_interval_hours' => 6,
			'category_id'             => 0,
			'author_id'               => get_current_user_id(),
			'generate_image'          => 0,
			'insert_image_in_post'    => 0,
			'generate_tags'           => 0,
			'tag_count'               => 5,
			'image_prompt_template'   => NEGARANDEH_Content_Generator::default_image_prompt_template(),
			'word_count'              => 2500,
			'language'                => NEGARANDEH_I18n::get_lang(),
			'hourly_cron_enabled'     => 0,
			'cron_interval_minutes'   => 1,
			'automation_enabled'      => 0,
			'queue_driver'            => 'wp_cron',
		);

		$settings = wp_parse_args( $settings, $defaults );

		if ( '' === trim( (string) ( $settings['prompt_template'] ?? '' ) ) ) {
			$settings['prompt_template'] = NEGARANDEH_Content_Generator::default_prompt_template(
				(string) ( $settings['language'] ?? '' )
			);
		}

		return $settings;
	}

	/**
	 * Flatten generator settings for client-side dirty detection.
	 *
	 * @param array<string,mixed> $settings Raw settings.
	 * @return array<string,string>
	 */
	public function get_generator_settings_snapshot( array $settings ): array {
		$settings = wp_parse_args(
			$settings,
			array(
				'topics'                  => '',
				'prompt_template'         => '',
				'post_status'             => 'draft',
				'schedule_interval_hours' => '6',
				'category_id'             => '0',
				'author_id'               => '0',
				'word_count'              => '2500',
				'language'                => 'fa',
				'generate_image'          => 0,
				'insert_image_in_post'    => 0,
				'generate_tags'           => 0,
				'tag_count'               => '5',
				'image_prompt_template'   => '',
				'queue_driver'            => 'wp_cron',
				'cron_interval_minutes'   => '1',
			)
		);

		return array(
			'topics'                  => (string) $settings['topics'],
			'prompt_template'         => (string) $settings['prompt_template'],
			'post_status'             => (string) $settings['post_status'],
			'schedule_interval_hours' => (string) (int) $settings['schedule_interval_hours'],
			'category_id'             => (string) (int) $settings['category_id'],
			'author_id'               => (string) (int) $settings['author_id'],
			'word_count'              => (string) (int) $settings['word_count'],
			'language'                => (string) $settings['language'],
			'generate_image'          => ! empty( $settings['generate_image'] ) ? '1' : '',
			'insert_image_in_post'    => ! empty( $settings['insert_image_in_post'] ) ? '1' : '',
			'generate_tags'           => ! empty( $settings['generate_tags'] ) ? '1' : '',
			'tag_count'               => (string) (int) $settings['tag_count'],
			'image_prompt_template'   => (string) $settings['image_prompt_template'],
			'queue_driver'            => in_array( $settings['queue_driver'] ?? '', array( 'ajax', 'wp_cron' ), true )
				? (string) $settings['queue_driver']
				: 'wp_cron',
			'cron_interval_minutes'   => (string) max( 1, min( 5, (int) $settings['cron_interval_minutes'] ) ),
		);
	}

	/**
	 * @param array<string,mixed>|mixed $input Settings.
	 * @return array<string,mixed>
	 */
	public function sanitize_generator_settings( $input ): array {
		$input = is_array( $input ) ? $input : array();

		$topics_raw = sanitize_textarea_field( $input['topics'] ?? '' );
		$existing   = get_option( 'negarandeh_generator_settings', array() );
		$existing   = is_array( $existing ) ? $existing : array();

		$automation_enabled = 0;
		if ( array_key_exists( 'automation_enabled', $input ) ) {
			$automation_enabled = ! empty( $input['automation_enabled'] ) ? 1 : 0;
		} elseif ( ! empty( $existing['automation_enabled'] ) ) {
			$automation_enabled = 1;
		}

		$cron_interval = isset( $input['cron_interval_minutes'] )
			? max( 1, min( 5, (int) $input['cron_interval_minutes'] ) )
			: max( 1, min( 5, (int) ( $existing['cron_interval_minutes'] ?? 1 ) ) );

		$post_status = in_array( $input['post_status'] ?? '', array( 'draft', 'publish', 'pending', 'scheduled' ), true )
			? $input['post_status']
			: 'draft';

		$schedule_interval = isset( $input['schedule_interval_hours'] )
			? max( 1, min( 48, (int) $input['schedule_interval_hours'] ) )
			: max( 1, min( 48, (int) ( $existing['schedule_interval_hours'] ?? 6 ) ) );

		if (
			'scheduled' === $post_status
			&& 'scheduled' !== ( $existing['post_status'] ?? '' )
		) {
			NEGARANDEH_Post_Creator::reset_schedule_sequence();
		}

		if (
			'scheduled' === $post_status
			&& isset( $input['schedule_interval_hours'] )
			&& $schedule_interval !== (int) ( $existing['schedule_interval_hours'] ?? 6 )
		) {
			NEGARANDEH_Post_Creator::reset_schedule_sequence();
		}

		if (
			'scheduled' !== $post_status
			&& 'scheduled' === ( $existing['post_status'] ?? '' )
		) {
			NEGARANDEH_Post_Creator::reset_schedule_sequence();
		}

		$queue_driver = in_array( $input['queue_driver'] ?? '', array( 'ajax', 'wp_cron' ), true )
			? $input['queue_driver']
			: ( in_array( $existing['queue_driver'] ?? '', array( 'ajax', 'wp_cron' ), true )
				? $existing['queue_driver']
				: 'wp_cron' );

		$content_language = in_array( $input['language'] ?? '', array( 'fa', 'en' ), true )
			? $input['language']
			: ( in_array( $existing['language'] ?? '', array( 'fa', 'en' ), true )
				? $existing['language']
				: NEGARANDEH_I18n::get_lang() );

		$sanitized = array(
			'prompt_template'       => sanitize_textarea_field(
				$input['prompt_template'] ?? NEGARANDEH_Content_Generator::default_prompt_template( $content_language )
			),
			'topics'                => $topics_raw,
			'post_status'           => $post_status,
			'schedule_interval_hours' => $schedule_interval,
			'category_id'           => absint( $input['category_id'] ?? 0 ),
			'author_id'             => absint( $input['author_id'] ?? get_current_user_id() ),
			'generate_image'        => ! empty( $input['generate_image'] ) ? 1 : 0,
			'insert_image_in_post'  => ! empty( $input['insert_image_in_post'] ) ? 1 : 0,
			'generate_tags'         => ! empty( $input['generate_tags'] ) ? 1 : 0,
			'tag_count'             => isset( $input['tag_count'] )
				? max( 1, min( 15, (int) $input['tag_count'] ) )
				: max( 1, min( 15, (int) ( $existing['tag_count'] ?? 5 ) ) ),
			'image_prompt_template' => sanitize_textarea_field( $input['image_prompt_template'] ?? NEGARANDEH_Content_Generator::default_image_prompt_template() ),
			'word_count'            => max( 400, min( 5000, (int) ( $input['word_count'] ?? 2500 ) ) ),
			'language'              => $content_language,
			'queue_driver'          => $queue_driver,
			'hourly_cron_enabled'   => 'wp_cron' === $queue_driver ? 1 : 0,
			'cron_interval_minutes' => $cron_interval,
			'automation_enabled'    => $automation_enabled,
		);

		NEGARANDEH_Batch_Processor::sync_auto_cron_schedule(
			'wp_cron' === $sanitized['queue_driver'] && ! empty( $sanitized['automation_enabled'] ),
			$cron_interval
		);

		return $sanitized;
	}

	public function enqueue_assets( string $hook ): void {
		$screen           = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_plugin_screen = ( $screen && false !== strpos( (string) $screen->id, NEGARANDEH_Plugin::SLUG ) )
			|| false !== strpos( $hook, NEGARANDEH_Plugin::SLUG );

		if ( ! $is_plugin_screen ) {
			return;
		}

		wp_enqueue_style( 'dashicons' );

		wp_enqueue_style(
			'negarandeh-admin',
			NEGARANDEH_PLUGIN_URL . 'admin/assets/admin.css',
			array( 'dashicons' ),
			NEGARANDEH_VERSION . '.' . filemtime( NEGARANDEH_PLUGIN_DIR . 'admin/assets/admin.css' )
		);

		wp_enqueue_script(
			'negarandeh-admin',
			NEGARANDEH_PLUGIN_URL . 'admin/assets/admin.js',
			array( 'jquery' ),
			NEGARANDEH_VERSION . '.' . filemtime( NEGARANDEH_PLUGIN_DIR . 'admin/assets/admin.js' ),
			true
		);

		$gen_settings = get_option( 'negarandeh_generator_settings', array() );
		$gen_settings = is_array( $gen_settings ) ? $gen_settings : array();
		$gen_settings = $this->resolve_generator_settings_for_ui( $gen_settings );

		wp_localize_script(
			'negarandeh-admin',
			'negarandehAdmin',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'negarandeh_admin' ),
				'queueDriver' => NEGARANDEH_Batch_Processor::get_queue_driver(),
				'automationEnabled' => (int) NEGARANDEH_Batch_Processor::is_automation_enabled(),
				'cronIntervalMinutes' => NEGARANDEH_Batch_Processor::get_cron_interval_minutes(),
				'cronActiveIntervalMinutes' => NEGARANDEH_Batch_Processor::get_active_cron_interval_minutes(),
				'nextAutoCron' => (int) NEGARANDEH_Batch_Processor::get_next_auto_cron_run(),
				'defaultImagePrompt' => NEGARANDEH_Content_Generator::default_image_prompt_template(),
				'savedGeneratorSettings' => $this->get_generator_settings_snapshot( $gen_settings ),
				'i18n'        => array(
					'generating'   => __( 'در حال تولید...', 'negarandeh' ),
					'completed'    => __( 'تولید کامل شد!', 'negarandeh' ),
					'error'        => __( 'خطا رخ داد.', 'negarandeh' ),
					'ajaxUrlUnavailable' => __( 'آدرس Ajax در دسترس نیست. صفحه را رفرش کنید.', 'negarandeh' ),
					'confirmClear' => __( 'صف تولید پاک شود؟', 'negarandeh' ),
					'confirmClearLog' => __( 'همهٔ رکوردهای لاگ حذف شوند؟', 'negarandeh' ),
					'confirmResetGenerated' => __( 'موضوعات ساخته‌شده دوباره قابل تولید می‌شوند. پست‌های قبلی حذف نمی‌شوند. اگر تولید روشن باشد Stop می‌شود. ادامه؟', 'negarandeh' ),
					'confirmResetImagePrompt' => __( 'پرامپت تصویر با متن پیش‌فرض جایگزین شود؟', 'negarandeh' ),
					'imagePromptReset' => __( 'پرامپت تصویر به پیش‌فرض برگشت. برای اعمال، ذخیره تنظیمات را بزنید.', 'negarandeh' ),
					'logCleared'   => __( 'لاگ پاک شد.', 'negarandeh' ),
					'logEmpty'     => __( 'لاگی ثبت نشده است.', 'negarandeh' ),
					'creditLoading' => __( 'در حال دریافت اعتبار...', 'negarandeh' ),
					'creditError'   => __( 'دریافت اعتبار ناموفق بود.', 'negarandeh' ),
					'creditTier'    => __( 'سطح حساب', 'negarandeh' ),
					'start'        => __( 'استارت (Start)', 'negarandeh' ),
					'stop'         => __( 'توقف (Stop)', 'negarandeh' ),
					'on'           => __( 'روشن', 'negarandeh' ),
					'off'          => __( 'خاموش', 'negarandeh' ),
					'powerOn'      => __( 'روشن — تولید مجاز است', 'negarandeh' ),
					'powerOff'     => __( 'خاموش — تولید متوقف است', 'negarandeh' ),
					'powerHintOn'  => __( 'صف دستی و Cron (در صورت فعال بودن) می‌توانند اجرا شوند.', 'negarandeh' ),
					'powerHintOff' => __( 'برای شروع، دکمه استارت بالای صفحه را بزنید.', 'negarandeh' ),
					'needStart'    => __( 'ابتدا استارت را بزنید تا تولید فعال شود.', 'negarandeh' ),
					'testing'      => __( 'در حال تست اتصال...', 'negarandeh' ),
					'testOk'       => __( 'اتصال موفق!', 'negarandeh' ),
					'testFail'     => __( 'اتصال ناموفق.', 'negarandeh' ),
					'noApiKey'     => __( 'کلید API وارد یا ذخیره نشده است.', 'negarandeh' ),
					'noBaseUrl'    => __( 'آدرس API وارد یا ذخیره نشده است.', 'negarandeh' ),
					'testingImage' => __( 'در حال تست تصویر...', 'negarandeh' ),
					'testImageOk'  => __( 'تولید تصویر موفق!', 'negarandeh' ),
					'loadingModels' => __( 'در حال دریافت لیست مدل‌ها...', 'negarandeh' ),
					'modelsLoadFail' => __( 'دریافت لیست مدل‌ها ناموفق بود.', 'negarandeh' ),
					'modelsEmpty' => __( 'مدلی برای این دسته یافت نشد.', 'negarandeh' ),
					'modelsCount' =>
						/* translators: %d: number of models */
						__( '%d مدل', 'negarandeh' ),
					'modelsSourceAuth' => __( 'از API کلید شما', 'negarandeh' ),
					'modelsSourcePublic' => __( 'لیست عمومی AvalAI', 'negarandeh' ),
					'modelClickToCopy' => __( 'کلیک برای کپی', 'negarandeh' ),
					'modelCopied' => __( 'نام مدل کپی و در فیلد قرار گرفت. ذخیره را فراموش نکنید.', 'negarandeh' ),
					'modelOwnedBy' =>
						/* translators: %s: provider name */
						__( 'ارائه‌دهنده: %s', 'negarandeh' ),
					'modelNoPrice' => __( 'قیمت اعلام نشده', 'negarandeh' ),
					'previewImage' => __( 'در حال ساخت تصویر...', 'negarandeh' ),
					'previewReady' => __( 'پیش‌نمایش تصویر (ذخیره نشده)', 'negarandeh' ),
					'emptyPrompt'  => __( 'پرامپت تصویر خالی است.', 'negarandeh' ),
					'cronDisabled' => __( 'Cron غیرفعال', 'negarandeh' ),
					'cronRunning'  =>
						/* translators: %s: cron interval label */
						__( 'در حال اجرا — %s', 'negarandeh' ),
					'cronActive'   =>
						/* translators: %s: cron interval label */
						__( 'فعال — %s — در انتظار WP-Cron', 'negarandeh' ),
					'cronWaiting'  => __( 'Cron تنظیم شده — منتظر استارت', 'negarandeh' ),
					'cronNeedStart'=>
						/* translators: %s: cron interval label */
						__( 'تولید خودکار (%s) فعال است؛ تا استارت نزنید اجرا نمی‌شود.', 'negarandeh' ),
					'cronNextRun'  => __( 'اجرای بعدی:', 'negarandeh' ),
					'cronEveryMin' =>
						/* translators: %d: number of minutes */
						__( 'هر %d دقیقه', 'negarandeh' ),
					'isRtl'        => NEGARANDEH_I18n::is_rtl() ? 1 : 0,
					'uiLang'       => NEGARANDEH_I18n::get_lang(),
					'statusSuccess' => __( 'موفق', 'negarandeh' ),
					'statusError'   => __( 'خطا', 'negarandeh' ),
					'statusSkipped' => __( 'رد شد', 'negarandeh' ),
					'statusWarning' => __( 'هشدار', 'negarandeh' ),
					'statusPending' => __( 'در انتظار', 'negarandeh' ),
					'statusInfo'    => __( 'در حال انجام', 'negarandeh' ),
					'boardEmpty'    => __( 'لیست خالی است.', 'negarandeh' ),
					'statOk'        => __( 'موفق', 'negarandeh' ),
					'statErr'       => __( 'خطا', 'negarandeh' ),
					'statWait'      => __( 'انتظار', 'negarandeh' ),
					'editPost'      => __( 'ویرایش', 'negarandeh' ),
					'imageLabel'    => __( 'تصویر:', 'negarandeh' ),
					'queueTag'      => __( 'صف', 'negarandeh' ),
					'usageArticle'  =>
						/* translators: 1: article prompt tokens, 2: article completion tokens, 3: article total tokens */
						__( 'مقاله: %1$s ورودی + %2$s خروجی = %3$s توکن', 'negarandeh' ),
					'usageImage'    =>
						/* translators: %s: image token count */
						__( 'تصویر: %s توکن', 'negarandeh' ),
					'usageCost'     =>
						/* translators: %s: estimated cost amount */
						__( 'هزینه تقریبی: %s', 'negarandeh' ),
					'statusLabel'   => __( 'وضعیت:', 'negarandeh' ),
					'startGenerate' => __( 'شروع تولید', 'negarandeh' ),
					'startGeneratePosts' => __( 'شروع تولید پست‌ها', 'negarandeh' ),
					'queueLocked'   => __( 'صف قفل شده است. «پاک کردن صف» را بزنید و دوباره تلاش کنید.', 'negarandeh' ),
					'queueEmpty'    => __( 'صف خالی است.', 'negarandeh' ),
					'needTopic'     => __( 'لطفاً حداقل یک موضوع وارد کنید.', 'negarandeh' ),
					'unsavedSettings' => __( 'تنظیمات ذخیره نشده دارد. تنظیمات را ذخیره کنید یا صفحه را رفرش کنید که بتوانید شروع کنید.', 'negarandeh' ),
					'recordsCount'  =>
						/* translators: %d: number of log records */
						__( '%d رکورد', 'negarandeh' ),
					'activePackages'=>
						/* translators: %d: number of active credit packages */
						__( '%d بسته فعال', 'negarandeh' ),
					'previewTopic'  => __( 'موضوع:', 'negarandeh' ),
					'buildPrompt'   => __( 'ساخت پرامپت', 'negarandeh' ),
					'buildPromptIntro' => __( 'گزینه‌های زیر فقط برای ساخت پرامپت در همین لحظه استفاده می‌شوند و ذخیره نمی‌شوند. پرامپت بر اساس الگوی حرفه‌ای سئو ساخته می‌شود و از {topic} استفاده می‌کند.', 'negarandeh' ),
					'builderWordCount' => __( 'تعداد کلمات', 'negarandeh' ),
					'builderAudience' => __( 'مخاطب', 'negarandeh' ),
					'builderAudiencePh' => __( 'مثلاً: مبتدیان، مدیران سایت، والدین…', 'negarandeh' ),
					'builderTone'   => __( 'لحن', 'negarandeh' ),
					'builderLanguage' => __( 'زبان محتوا', 'negarandeh' ),
					'builderNotes'  => __( 'توضیحات', 'negarandeh' ),
					'builderNotesPh' => __( 'نیازها، محدودیت‌ها یا نکات خاص برای ساخت پرامپت…', 'negarandeh' ),
					'builderIncludeFaq' => __( 'افزودن FAQ', 'negarandeh' ),
					'builderIncludeToc' => __( 'فهرست مطالب', 'negarandeh' ),
					'builderIncludeIntro' => __( 'مقدمه', 'negarandeh' ),
					'builderIncludeConclusion' => __( 'جمع‌بندی', 'negarandeh' ),
					'builderSeoFocus' => __( 'تمرکز سئو', 'negarandeh' ),
					'generatePromptAi' => __( 'ساخت پرامپت', 'negarandeh' ),
					'applyPrompt'   => __( 'انتقال به قالب پرامپت', 'negarandeh' ),
					'generatingPrompt' => __( 'در حال ساخت پرامپت…', 'negarandeh' ),
					'promptGenerated' => __( 'پرامپت آماده است. می‌توانید آن را به قالب پرامپت منتقل کنید.', 'negarandeh' ),
					'promptApplied' => __( 'پرامپت به قالب منتقل شد. برای استفاده «ذخیره تنظیمات» را بزنید.', 'negarandeh' ),
					'promptPreviewLabel' => __( 'پیش‌نمایش پرامپت تولیدشده', 'negarandeh' ),
					'toneProfessional' => __( 'حرفه‌ای', 'negarandeh' ),
					'toneFriendly'  => __( 'دوستانه', 'negarandeh' ),
					'toneFormal'    => __( 'رسمی', 'negarandeh' ),
					'toneEducational' => __( 'آموزشی', 'negarandeh' ),
					'toneNews'      => __( 'خبری', 'negarandeh' ),
					'tonePersuasive' => __( 'متقاعدکننده', 'negarandeh' ),
				),
			)
		);
	}

	public function ajax_toggle_automation(): void {
		check_ajax_referer( 'negarandeh_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'دسترسی غیرمجاز.', 'negarandeh' ) ) );
		}

		$enabled_raw = isset( $_POST['enabled'] ) ? sanitize_text_field( wp_unslash( $_POST['enabled'] ) ) : '';
		$enabled     = filter_var( $enabled_raw, FILTER_VALIDATE_BOOLEAN ) ? 1 : 0;

		$gen = get_option( 'negarandeh_generator_settings', array() );
		$gen = is_array( $gen ) ? $gen : array();
		$gen['automation_enabled'] = $enabled;
		update_option( 'negarandeh_generator_settings', $gen );

		if ( $enabled ) {
			NEGARANDEH_Batch_Processor::sync_auto_cron_schedule(
				'wp_cron' === NEGARANDEH_Batch_Processor::get_queue_driver(),
				NEGARANDEH_Batch_Processor::get_cron_interval_minutes()
			);
		} else {
			NEGARANDEH_Batch_Processor::stop_all_generation();
		}

		wp_send_json_success(
			array(
				'enabled'                      => $enabled,
				'next_auto_cron'               => (int) NEGARANDEH_Batch_Processor::get_next_auto_cron_run(),
				'cron_interval_minutes'        => NEGARANDEH_Batch_Processor::get_cron_interval_minutes(),
				'cron_active_interval_minutes' => NEGARANDEH_Batch_Processor::get_active_cron_interval_minutes(),
			)
		);
	}

	public function ajax_get_credit(): void {
		check_ajax_referer( 'negarandeh_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'دسترسی غیرمجاز.', 'negarandeh' ) ) );
		}

		$credit = NEGARANDEH_Avalai_API::get_credit();

		if ( is_wp_error( $credit ) ) {
			wp_send_json_error( array( 'message' => $credit->get_error_message() ) );
		}

		$remaining_irt  = isset( $credit['remaining_irt'] ) ? (float) $credit['remaining_irt'] : null;
		$remaining_unit = isset( $credit['remaining_unit'] ) ? (float) $credit['remaining_unit'] : null;
		$total_unit     = isset( $credit['total_unit'] ) ? (float) $credit['total_unit'] : null;

		$packages = array();
		if ( ! empty( $credit['credit_sources']['packages'] ) && is_array( $credit['credit_sources']['packages'] ) ) {
			foreach ( $credit['credit_sources']['packages'] as $pkg ) {
				if ( ! is_array( $pkg ) ) {
					continue;
				}
				$packages[] = array(
					'name'          => (string) ( $pkg['name'] ?? '' ),
					'remaining_irt' => (float) ( $pkg['remaining_irt'] ?? 0 ),
					'end_date'      => (string) ( $pkg['end_date'] ?? '' ),
				);
			}
		}

		wp_send_json_success(
			array(
				'remaining_irt'       => $remaining_irt,
				'remaining_irt_human' => null !== $remaining_irt ? number_format_i18n( $remaining_irt ) : '',
				'remaining_unit'      => $remaining_unit,
				'total_unit'          => $total_unit,
				'account_tier'        => isset( $credit['account_tier'] ) ? (int) $credit['account_tier'] : null,
				'packages'            => $packages,
			)
		);
	}

	public function ajax_list_models(): void {
		check_ajax_referer( 'negarandeh_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'دسترسی غیرمجاز.', 'negarandeh' ) ) );
		}

		$settings = NEGARANDEH_Avalai_API::get_settings();
		$kind     = sanitize_key( (string) ( $_POST['kind'] ?? 'text' ) );
		if ( ! in_array( $kind, array( 'text', 'image' ), true ) ) {
			$kind = 'text';
		}

		$new_key = isset( $_POST['api_key'] ) ? NEGARANDEH_Avalai_API::normalize_api_key( wp_unslash( (string) $_POST['api_key'] ) ) : '';
		if ( '' !== $new_key && ! preg_match( '/^\*+$/', $new_key ) ) {
			$settings['api_key'] = $new_key;
		}

		$new_base = isset( $_POST['api_base_url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['api_base_url'] ) ) : '';
		if ( '' !== $new_base ) {
			$settings['api_base_url'] = esc_url_raw( NEGARANDEH_Avalai_API::normalize_base_url( $new_base ) );
		}

		$result = NEGARANDEH_Avalai_API::list_models( $settings, $kind );

		if ( is_wp_error( $result ) ) {
			$error = NEGARANDEH_Avalai_API::enrich_error_with_http_debug( $result );
			wp_send_json_error(
				array(
					'message' => NEGARANDEH_Avalai_API::format_error_for_display( $error ),
					'debug'   => $error->get_error_data(),
				)
			);
		}

		wp_send_json_success( $result );
	}

	public function ajax_test_api(): void {
		check_ajax_referer( 'negarandeh_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'دسترسی غیرمجاز.', 'negarandeh' ) ) );
		}

		$settings = NEGARANDEH_Avalai_API::get_settings();
		$new_key      = isset( $_POST['api_key'] ) ? NEGARANDEH_Avalai_API::normalize_api_key( sanitize_text_field( wp_unslash( (string) $_POST['api_key'] ) ) ) : '';
		$new_base_raw = isset( $_POST['api_base_url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['api_base_url'] ) ) : '';
		$new_base     = '' !== $new_base_raw ? NEGARANDEH_Avalai_API::normalize_base_url( $new_base_raw ) : '';

		if ( $new_key ) {
			$settings['api_key'] = $new_key;
		}

		if ( $new_base ) {
			$settings['api_base_url'] = esc_url_raw( $new_base );
		}

		if ( empty( $settings['api_key'] ) ) {
			wp_send_json_error( array( 'message' => __( 'کلید API وارد یا ذخیره نشده است.', 'negarandeh' ) ) );
		}

		if ( empty( $settings['api_base_url'] ) ) {
			wp_send_json_error( array( 'message' => __( 'آدرس API وارد یا ذخیره نشده است.', 'negarandeh' ) ) );
		}

		if ( ! empty( $_POST['chat_model'] ) ) {
			$settings['chat_model'] = sanitize_text_field( wp_unslash( (string) $_POST['chat_model'] ) );
		}

		$result = NEGARANDEH_Avalai_API::test_connection( $settings );

		if ( is_wp_error( $result ) ) {
			NEGARANDEH_Avalai_API::send_json_error( $result );
		}

		if ( $new_key || $new_base ) {
			$settings_saved = NEGARANDEH_Avalai_API::get_settings();
			if ( $new_key ) {
				$settings_saved['api_key'] = $new_key;
			}
			if ( $new_base ) {
				$settings_saved['api_base_url'] = esc_url_raw( $new_base );
			}
			update_option( 'negarandeh_settings', $settings_saved );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: chat model name */
					__( 'اتصال به AvalAI برقرار است. مدل: %s', 'negarandeh' ),
					$settings['chat_model'] ?? 'gpt-4o-mini'
				),
			)
		);
	}

	public function ajax_test_image(): void {
		check_ajax_referer( 'negarandeh_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'دسترسی غیرمجاز.', 'negarandeh' ) ) );
		}

		$settings = NEGARANDEH_Avalai_API::get_settings();

		if ( ! empty( $_POST['api_key'] ) ) {
			$settings['api_key'] = NEGARANDEH_Avalai_API::normalize_api_key( sanitize_text_field( wp_unslash( (string) $_POST['api_key'] ) ) );
		}
		$api_base_url = isset( $_POST['api_base_url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['api_base_url'] ) ) : '';
		if ( '' !== $api_base_url ) {
			$settings['api_base_url'] = esc_url_raw( NEGARANDEH_Avalai_API::normalize_base_url( $api_base_url ) );
		}
		if ( ! empty( $_POST['image_model'] ) ) {
			$settings['image_model'] = sanitize_text_field( wp_unslash( (string) $_POST['image_model'] ) );
		}

		$result = NEGARANDEH_Avalai_API::test_image( $settings );

		if ( is_wp_error( $result ) ) {
			NEGARANDEH_Avalai_API::send_json_error( NEGARANDEH_Avalai_API::enrich_error_with_http_debug( $result ) );
		}

		wp_send_json_success(
			array(
				'message' => __( 'تولید تصویر موفق بود.', 'negarandeh' ),
			)
		);
	}

	public function ajax_preview_image_prompt(): void {
		check_ajax_referer( 'negarandeh_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'دسترسی غیرمجاز.', 'negarandeh' ) ) );
		}

		$template = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['prompt'] ) ) : '';
		if ( '' === trim( $template ) ) {
			wp_send_json_error( array( 'message' => __( 'پرامپت تصویر خالی است.', 'negarandeh' ) ) );
		}

		$topics_raw = isset( $_POST['topics'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['topics'] ) ) : '';
		$topics     = NEGARANDEH_Batch_Processor::parse_topics_list( $topics_raw );
		$topic      = ! empty( $topics[0] ) ? $topics[0] : __( 'نمونه موضوع', 'negarandeh' );

		$resolved_prompt = NEGARANDEH_Content_Generator::build_image_prompt(
			$template,
			$topic,
			array(
				'topics' => $topics,
				'index'  => 1,
			),
			array(
				'title'         => sprintf(
					/* translators: %s: sample topic */
					__( 'راهنمای جامع %s', 'negarandeh' ),
					$topic
				),
				'focus_keyword' => $topic,
				'image_alt'     => $topic,
			)
		);

		$response = NEGARANDEH_Avalai_API::generate_image( $resolved_prompt );
		if ( is_wp_error( $response ) ) {
			NEGARANDEH_Avalai_API::send_json_error( $response );
		}

		$preview_src = NEGARANDEH_Image_Handler::get_preview_src( $response );
		if ( is_wp_error( $preview_src ) ) {
			NEGARANDEH_Avalai_API::send_json_error( $preview_src );
		}

		wp_send_json_success(
			array(
				'image_src'   => $preview_src,
				'prompt_used' => $resolved_prompt,
				'topic_used'  => $topic,
			)
		);
	}

	public function ajax_build_prompt(): void {
		check_ajax_referer( 'negarandeh_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'دسترسی غیرمجاز.', 'negarandeh' ) ) );
		}

		$allowed_tones = array( 'professional', 'friendly', 'formal', 'educational', 'news', 'persuasive' );
		$tone          = isset( $_POST['tone'] ) ? sanitize_key( wp_unslash( (string) $_POST['tone'] ) ) : 'professional';
		if ( ! in_array( $tone, $allowed_tones, true ) ) {
			$tone = 'professional';
		}

		$language = isset( $_POST['language'] ) ? sanitize_key( wp_unslash( (string) $_POST['language'] ) ) : '';
		if ( ! in_array( $language, array( 'fa', 'en' ), true ) ) {
			$language = NEGARANDEH_I18n::get_lang();
		}

		$args = array(
			'word_count'         => isset( $_POST['word_count'] ) ? (int) $_POST['word_count'] : 2500,
			'audience'           => isset( $_POST['audience'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['audience'] ) ) : '',
			'tone'               => $tone,
			'language'           => $language,
			'notes'              => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['notes'] ) ) : '',
			'include_faq'        => ! empty( $_POST['include_faq'] ),
			'include_toc'        => ! empty( $_POST['include_toc'] ),
			'include_intro'      => ! empty( $_POST['include_intro'] ),
			'include_conclusion' => ! empty( $_POST['include_conclusion'] ),
			'seo_focus'          => ! empty( $_POST['seo_focus'] ),
		);

		$result = NEGARANDEH_Content_Generator::generate_prompt_from_builder( $args );

		wp_send_json_success(
			array(
				'prompt'  => $result,
				'message' => __( 'پرامپت آماده است. می‌توانید آن را به قالب پرامپت منتقل کنید.', 'negarandeh' ),
			)
		);
	}

	public function maybe_spawn_overdue_cron(): void {
		if ( ! current_user_can( 'manage_options' ) || ! NEGARANDEH_Batch_Processor::is_auto_cron_enabled() || ! NEGARANDEH_Batch_Processor::is_automation_enabled() ) {
			return;
		}

		$next = NEGARANDEH_Batch_Processor::get_next_auto_cron_run();
		if ( $next && (int) $next <= time() ) {
			spawn_cron();
		}
	}

	public function render_settings_page(): void {
		$settings = NEGARANDEH_Avalai_API::get_settings();
		include NEGARANDEH_PLUGIN_DIR . 'admin/views/settings.php';
	}

	public function render_guide_page(): void {
		include NEGARANDEH_PLUGIN_DIR . 'admin/views/guide.php';
	}

	public function render_log_page(): void {
		$permanent_log = NEGARANDEH_Batch_Processor::get_permanent_log( 500 );
		$log_count     = count( $permanent_log );
		include NEGARANDEH_PLUGIN_DIR . 'admin/views/log.php';
	}

	public function render_generator_page(): void {
		$gen_settings = $this->resolve_generator_settings_for_ui();
		$placeholders       = NEGARANDEH_Content_Generator::get_placeholders_help();
		$image_placeholders = NEGARANDEH_Content_Generator::get_image_placeholders_help();
		$queue         = get_option( NEGARANDEH_Batch_Processor::QUEUE_OPTION, array() );
		$next_auto     = NEGARANDEH_Batch_Processor::get_next_auto_cron_run();
		$cron_index    = (int) get_option( NEGARANDEH_Batch_Processor::CRON_INDEX_OPTION, 0 );
		$topics        = NEGARANDEH_Batch_Processor::parse_topics_list( (string) ( $gen_settings['topics'] ?? '' ) );
		$topic_board   = NEGARANDEH_Batch_Processor::get_topic_status_board( $topics );
		$topic_stats   = NEGARANDEH_Batch_Processor::get_topic_stats( $topics );
		$permanent_log = NEGARANDEH_Batch_Processor::get_permanent_log( 20 );

		include NEGARANDEH_PLUGIN_DIR . 'admin/views/generator.php';
	}
}
