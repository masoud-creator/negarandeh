<?php
/**
 * Content generator — نگارنده.
 *
 * @package Negarandeh
 * @var array<string,mixed>  $gen_settings
 * @var array<string,string> $placeholders
 * @var array<string,mixed>  $queue
 * @var array<int,string>    $topics
 */

defined( 'ABSPATH' ) || exit;

$categories = get_categories( array( 'hide_empty' => false ) );
$users      = get_users( array( 'capability' => 'edit_posts' ) );
$seo_active = NEGARANDEH_SEO_Handler::get_active_plugin_name();
$topics_raw    = (string) ( $gen_settings['topics'] ?? '' );
$log_url       = admin_url( 'admin.php?page=' . NEGARANDEH_Plugin::SLUG_LOG );
$cron_interval = NEGARANDEH_Batch_Processor::get_cron_interval_minutes();
?>
<div <?php echo NEGARANDEH_I18n::wrap_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="negarandeh-hero">
		<h1><?php echo esc_html( NEGARANDEH_Plugin::display_name() ); ?></h1>
		<p class="negarandeh-hero-tagline">
			<?php esc_html_e( 'با نگارنده برای هر موضوعی مقاله سئو-محور، تصویر شاخص و متادیتا بسازید. لیست موضوعات را تعریف کنید، پرامپت را شخصی‌سازی کنید، و به‌صورت دستی یا خودکار منتشر کنید.', 'negarandeh' ); ?>
		</p>
		<div class="negarandeh-hero-actions">
			<?php $auto_enabled = ! empty( $gen_settings['automation_enabled'] ); ?>
			<div class="negarandeh-hero-power-wrap">
				<span class="negarandeh-automation-status <?php echo esc_attr( $auto_enabled ? 'is-on' : 'is-off' ); ?>" id="negarandeh-automation-status-badge" aria-live="polite">
					<span class="negarandeh-automation-status-dot" aria-hidden="true"></span>
					<span class="negarandeh-automation-status-text"><?php echo esc_html( $auto_enabled ? __( 'روشن', 'negarandeh' ) : __( 'خاموش', 'negarandeh' ) ); ?></span>
				</span>
				<button type="button" class="negarandeh-hero-toggle <?php echo esc_attr( $auto_enabled ? 'is-on' : 'is-off' ); ?>" id="negarandeh-toggle-automation" data-enabled="<?php echo esc_attr( $auto_enabled ? '1' : '0' ); ?>" aria-pressed="<?php echo esc_attr( $auto_enabled ? 'true' : 'false' ); ?>">
					<span class="negarandeh-hero-toggle-icon" aria-hidden="true"><?php echo esc_html( $auto_enabled ? '⏹' : '▶' ); ?></span>
					<span class="negarandeh-hero-toggle-label"><?php echo esc_html( $auto_enabled ? __( 'توقف (Stop)', 'negarandeh' ) : __( 'استارت (Start)', 'negarandeh' ) ); ?></span>
				</button>
			</div>
			<span class="negarandeh-hero-toggle-hint">
				<?php esc_html_e( 'تا وقتی استارت نزنید، صف دستی و Cron هیچ تولیدی انجام نمی‌دهند — حتی بعد از ذخیره تنظیمات.', 'negarandeh' ); ?>
			</span>
		</div>
	</div>

	<div class="negarandeh-credit-bar" id="negarandeh-credit-bar">
		<div class="negarandeh-credit-main">
			<span class="negarandeh-credit-icon" aria-hidden="true">&#128176;</span>
			<div class="negarandeh-credit-texts">
				<span class="negarandeh-credit-label"><?php esc_html_e( 'اعتبار AvalAI', 'negarandeh' ); ?></span>
				<span class="negarandeh-credit-value" id="negarandeh-credit-value"><?php esc_html_e( 'در حال دریافت...', 'negarandeh' ); ?></span>
				<span class="negarandeh-credit-sub" id="negarandeh-credit-sub"></span>
			</div>
		</div>
		<button type="button" class="button button-small" id="negarandeh-refresh-credit"><?php esc_html_e( 'بروزرسانی', 'negarandeh' ); ?></button>
	</div>

	<?php if ( $seo_active ) : ?>
		<div class="negarandeh-notice negarandeh-notice-info">
			<strong><?php esc_html_e( 'سئو:', 'negarandeh' ); ?></strong>
			<?php
			printf(
				/* translators: %s: SEO plugin name */
				esc_html__( 'متادیتای سئو روی %s اعمال می‌شود.', 'negarandeh' ),
				esc_html( $seo_active )
			);
			?>
		</div>
	<?php else : ?>
		<div class="negarandeh-notice negarandeh-notice-warning">
			<strong><?php esc_html_e( 'هشدار سئو:', 'negarandeh' ); ?></strong>
			<?php
			printf(
				/* translators: %s: comma-separated SEO plugin names */
				esc_html__( 'پلاگین سئو پشتیبانی‌شده یافت نشد. برای اعمال خودکار متادیتا یکی از این پلاگین‌ها را نصب کنید: %s', 'negarandeh' ),
				esc_html( NEGARANDEH_SEO_Handler::get_supported_plugins_list_text() )
			);
			?>
		</div>
	<?php endif; ?>

	<div class="negarandeh-grid">
		<div class="negarandeh-main">
			<form method="post" action="options.php" id="negarandeh-generator-form">
				<?php settings_fields( 'negarandeh_generator_group' ); ?>

				<div class="negarandeh-card">
					<div class="negarandeh-card-header">
						<span class="negarandeh-step-num">1</span>
						<div>
							<h2 class="negarandeh-card-title">
								<?php esc_html_e( 'لیست موضوعات', 'negarandeh' ); ?>
								<code class="negarandeh-inline-hint" dir="ltr">{topic}</code>
							</h2>
							<p class="negarandeh-card-desc"><?php esc_html_e( 'هر خط یک موضوع — برای هر موضوع یک پست جداگانه ساخته می‌شود. مثال: نام محصول، کلمه کلیدی، عنوان دوره، شهر، برند…', 'negarandeh' ); ?></p>
						</div>
					</div>
					<textarea name="negarandeh_generator_settings[topics]" rows="7" class="large-text negarandeh-topics-textarea" id="negarandeh-topics" dir="<?php echo esc_attr( NEGARANDEH_I18n::get_direction() ); ?>"><?php echo esc_textarea( $topics_raw ); ?></textarea>
				</div>

				<div class="negarandeh-card">
					<div class="negarandeh-card-header">
						<span class="negarandeh-step-num">2</span>
						<div>
							<h2 class="negarandeh-card-title"><?php esc_html_e( 'قالب پرامپت', 'negarandeh' ); ?></h2>
							<p class="negarandeh-card-desc"><?php esc_html_e( 'دستور تولید محتوا — از placeholderها استفاده کنید. {topic} با هر موضوع از لیست جایگزین می‌شود.', 'negarandeh' ); ?></p>
						</div>
					</div>
					<div class="negarandeh-placeholders-wrap">
						<p class="negarandeh-placeholders-label"><?php esc_html_e( 'Placeholderها', 'negarandeh' ); ?></p>
						<div class="negarandeh-placeholders">
							<?php foreach ( $placeholders as $tag => $desc ) : ?>
								<button type="button" class="button button-small negarandeh-insert-placeholder" data-tag="<?php echo esc_attr( $tag ); ?>" title="<?php echo esc_attr( $desc ); ?>">
									<code dir="ltr"><?php echo esc_html( $tag ); ?></code>
								</button>
							<?php endforeach; ?>
						</div>
					</div>
					<textarea name="negarandeh_generator_settings[prompt_template]" rows="12" class="large-text negarandeh-prompt-textarea" id="negarandeh-prompt" dir="<?php echo esc_attr( NEGARANDEH_I18n::get_direction() ); ?>"><?php echo esc_textarea( $gen_settings['prompt_template'] ); ?></textarea>
					<ul class="negarandeh-placeholder-legend" aria-label="<?php esc_attr_e( 'راهنمای placeholderهای پرامپت', 'negarandeh' ); ?>">
						<?php foreach ( $placeholders as $tag => $desc ) : ?>
							<li class="negarandeh-placeholder-item">
								<code class="negarandeh-placeholder-tag" dir="ltr"><?php echo esc_html( $tag ); ?></code>
								<span class="negarandeh-placeholder-desc"><?php echo esc_html( $desc ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>

					<?php
					$builder_lang = in_array( $gen_settings['language'] ?? '', array( 'fa', 'en' ), true )
						? (string) $gen_settings['language']
						: NEGARANDEH_I18n::get_lang();
					$builder_word_count = max( 400, min( 5000, (int) ( $gen_settings['word_count'] ?? 2500 ) ) );
					?>
					<div class="negarandeh-prompt-builder">
						<button type="button" class="negarandeh-prompt-builder-toggle" id="negarandeh-prompt-builder-toggle" aria-expanded="false" aria-controls="negarandeh-prompt-builder-panel">
							<?php esc_html_e( 'ساخت پرامپت', 'negarandeh' ); ?>
						</button>
						<div id="negarandeh-prompt-builder-panel" class="negarandeh-prompt-builder-panel" hidden>
							<p class="description negarandeh-prompt-builder-intro">
								<?php esc_html_e( 'گزینه‌های زیر فقط برای ساخت پرامپت در همین لحظه استفاده می‌شوند و ذخیره نمی‌شوند. پرامپت بر اساس الگوی حرفه‌ای سئو ساخته می‌شود و از {topic} استفاده می‌کند.', 'negarandeh' ); ?>
							</p>
							<div class="negarandeh-prompt-builder-grid">
								<div class="negarandeh-prompt-builder-field">
									<label for="negarandeh-builder-word-count"><?php esc_html_e( 'تعداد کلمات', 'negarandeh' ); ?></label>
									<input type="number" id="negarandeh-builder-word-count" min="400" max="5000" step="100" value="<?php echo esc_attr( (string) $builder_word_count ); ?>" />
								</div>
								<div class="negarandeh-prompt-builder-field">
									<label for="negarandeh-builder-language"><?php esc_html_e( 'زبان محتوا', 'negarandeh' ); ?></label>
									<select id="negarandeh-builder-language">
										<option value="fa" <?php selected( $builder_lang, 'fa' ); ?>><?php esc_html_e( 'فارسی', 'negarandeh' ); ?></option>
										<option value="en" <?php selected( $builder_lang, 'en' ); ?>><?php esc_html_e( 'English', 'negarandeh' ); ?></option>
									</select>
								</div>
								<div class="negarandeh-prompt-builder-field">
									<label for="negarandeh-builder-tone"><?php esc_html_e( 'لحن', 'negarandeh' ); ?></label>
									<select id="negarandeh-builder-tone">
										<option value="professional"><?php esc_html_e( 'حرفه‌ای', 'negarandeh' ); ?></option>
										<option value="friendly"><?php esc_html_e( 'دوستانه', 'negarandeh' ); ?></option>
										<option value="formal"><?php esc_html_e( 'رسمی', 'negarandeh' ); ?></option>
										<option value="educational"><?php esc_html_e( 'آموزشی', 'negarandeh' ); ?></option>
										<option value="news"><?php esc_html_e( 'خبری', 'negarandeh' ); ?></option>
										<option value="persuasive"><?php esc_html_e( 'متقاعدکننده', 'negarandeh' ); ?></option>
									</select>
								</div>
								<div class="negarandeh-prompt-builder-field negarandeh-prompt-builder-field--wide">
									<label for="negarandeh-builder-audience"><?php esc_html_e( 'مخاطب', 'negarandeh' ); ?></label>
									<input type="text" id="negarandeh-builder-audience" class="large-text" placeholder="<?php esc_attr_e( 'مثلاً: مبتدیان، مدیران سایت، والدین…', 'negarandeh' ); ?>" />
								</div>
							</div>
							<div class="negarandeh-prompt-builder-checks">
								<label><input type="checkbox" id="negarandeh-builder-include-intro" value="1" checked /> <?php esc_html_e( 'مقدمه', 'negarandeh' ); ?></label>
								<label><input type="checkbox" id="negarandeh-builder-include-toc" value="1" /> <?php esc_html_e( 'فهرست مطالب', 'negarandeh' ); ?></label>
								<label><input type="checkbox" id="negarandeh-builder-include-faq" value="1" checked /> <?php esc_html_e( 'افزودن FAQ', 'negarandeh' ); ?></label>
								<label><input type="checkbox" id="negarandeh-builder-include-conclusion" value="1" checked /> <?php esc_html_e( 'جمع‌بندی', 'negarandeh' ); ?></label>
								<label><input type="checkbox" id="negarandeh-builder-seo-focus" value="1" checked /> <?php esc_html_e( 'تمرکز سئو', 'negarandeh' ); ?></label>
							</div>
							<div class="negarandeh-prompt-builder-field negarandeh-prompt-builder-field--wide">
								<label for="negarandeh-builder-notes"><?php esc_html_e( 'توضیحات', 'negarandeh' ); ?></label>
								<textarea id="negarandeh-builder-notes" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'نیازها، محدودیت‌ها یا نکات خاص برای ساخت پرامپت…', 'negarandeh' ); ?>"></textarea>
							</div>
							<div class="negarandeh-prompt-builder-actions">
								<button type="button" class="button button-primary" id="negarandeh-generate-prompt-btn"><?php esc_html_e( 'ساخت پرامپت', 'negarandeh' ); ?></button>
								<button type="button" class="button button-secondary" id="negarandeh-apply-prompt-btn" disabled><?php esc_html_e( 'انتقال به قالب پرامپت', 'negarandeh' ); ?></button>
							</div>
							<div id="negarandeh-prompt-builder-status" class="negarandeh-test-result" aria-live="polite" hidden></div>
							<label for="negarandeh-prompt-builder-preview" class="negarandeh-prompt-builder-preview-label" id="negarandeh-prompt-builder-preview-label" hidden><?php esc_html_e( 'پیش‌نمایش پرامپت تولیدشده', 'negarandeh' ); ?></label>
							<textarea id="negarandeh-prompt-builder-preview" class="large-text negarandeh-prompt-builder-preview" rows="10" readonly hidden dir="<?php echo esc_attr( NEGARANDEH_I18n::get_direction() ); ?>"></textarea>
						</div>
					</div>
				</div>

				<div class="negarandeh-card negarandeh-settings-grid negarandeh-settings-compact">
					<div class="negarandeh-card-header">
						<span class="negarandeh-step-num">3</span>
						<div>
							<h2 class="negarandeh-card-title"><?php esc_html_e( 'تنظیمات انتشار', 'negarandeh' ); ?></h2>
							<p class="negarandeh-card-desc"><?php esc_html_e( 'وضعیت پست، دسته‌بندی، نویسنده، زبان محتوا و برچسب‌ها.', 'negarandeh' ); ?></p>
						</div>
					</div>
					<table class="form-table" role="presentation">
						<tr>
							<th><?php esc_html_e( 'وضعیت پست', 'negarandeh' ); ?></th>
							<td>
								<select name="negarandeh_generator_settings[post_status]" id="negarandeh-post-status">
									<option value="draft" <?php selected( $gen_settings['post_status'], 'draft' ); ?>><?php esc_html_e( 'پیش‌نویس (پیشنهادی)', 'negarandeh' ); ?></option>
									<option value="pending" <?php selected( $gen_settings['post_status'], 'pending' ); ?>><?php esc_html_e( 'در انتظار بازبینی', 'negarandeh' ); ?></option>
									<option value="publish" <?php selected( $gen_settings['post_status'], 'publish' ); ?>><?php esc_html_e( 'منتشر شده', 'negarandeh' ); ?></option>
									<option value="scheduled" <?php selected( $gen_settings['post_status'], 'scheduled' ); ?>><?php esc_html_e( 'انتشار زمان‌بندی‌شده', 'negarandeh' ); ?></option>
								</select>
							</td>
							<th><?php esc_html_e( 'دسته‌بندی', 'negarandeh' ); ?></th>
							<td>
								<select name="negarandeh_generator_settings[category_id]">
									<option value="0"><?php esc_html_e( '— بدون دسته —', 'negarandeh' ); ?></option>
									<?php foreach ( $categories as $cat ) : ?>
										<option value="<?php echo esc_attr( $cat->term_id ); ?>" <?php selected( $gen_settings['category_id'], $cat->term_id ); ?>><?php echo esc_html( $cat->name ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr class="negarandeh-schedule-interval-row" id="negarandeh-schedule-interval-row" <?php echo 'scheduled' !== ( $gen_settings['post_status'] ?? '' ) ? 'hidden' : ''; ?>>
							<th><?php esc_html_e( 'فاصله انتشار', 'negarandeh' ); ?></th>
							<td colspan="3">
								<div class="negarandeh-schedule-interval-inline">
									<label class="screen-reader-text" for="negarandeh-schedule-interval"><?php esc_html_e( 'فاصله انتشار (ساعت)', 'negarandeh' ); ?></label>
									<input type="number" name="negarandeh_generator_settings[schedule_interval_hours]" id="negarandeh-schedule-interval" class="negarandeh-schedule-interval-input" value="<?php echo esc_attr( (string) (int) ( $gen_settings['schedule_interval_hours'] ?? 6 ) ); ?>" min="1" max="48" step="1" <?php disabled( 'scheduled' !== ( $gen_settings['post_status'] ?? '' ) ); ?> />
									<span class="negarandeh-schedule-interval-unit"><?php esc_html_e( 'ساعت', 'negarandeh' ); ?></span>
								</div>
								<p class="description negarandeh-schedule-interval-desc"><?php esc_html_e( 'مقاله اول بلافاصله منتشر می‌شود؛ مقاله دوم، سوم و بعدی هر «فاصله انتشار» ساعت بعد زمان‌بندی می‌شوند (مثلاً با فاصله ۶ ساعت: الان، +۶ ساعت، +۱۲ ساعت).', 'negarandeh' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'نویسنده', 'negarandeh' ); ?></th>
							<td>
								<select name="negarandeh_generator_settings[author_id]">
									<?php foreach ( $users as $user ) : ?>
										<option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( $gen_settings['author_id'], $user->ID ); ?>><?php echo esc_html( $user->display_name ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<th><?php esc_html_e( 'حداقل کلمات', 'negarandeh' ); ?></th>
							<td><input type="number" name="negarandeh_generator_settings[word_count]" value="<?php echo esc_attr( $gen_settings['word_count'] ); ?>" min="400" max="5000" step="100" /></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'زبان محتوا', 'negarandeh' ); ?></th>
							<td>
								<select name="negarandeh_generator_settings[language]">
									<option value="fa" <?php selected( $gen_settings['language'], 'fa' ); ?>><?php esc_html_e( 'فارسی', 'negarandeh' ); ?></option>
									<option value="en" <?php selected( $gen_settings['language'], 'en' ); ?>><?php esc_html_e( 'English', 'negarandeh' ); ?></option>
								</select>
							</td>
							<th><?php esc_html_e( 'تصویر شاخص', 'negarandeh' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="negarandeh_generator_settings[generate_image]" value="1" <?php checked( $gen_settings['generate_image'], 1 ); ?> />
									<?php esc_html_e( 'تولید تصویر شاخص با AI', 'negarandeh' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'در تنظیمات می‌توانید مدل AI تصویرسازی را مشخص کنید.', 'negarandeh' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'برچسب‌ها', 'negarandeh' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="negarandeh_generator_settings[generate_tags]" id="negarandeh-generate-tags" value="1" <?php checked( ! empty( $gen_settings['generate_tags'] ) ); ?> />
									<?php esc_html_e( 'ساخت برچسب (تگ) با AI', 'negarandeh' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'برای هر پست جدید، برچسب‌های مرتبط از AI گرفته و به پست اضافه می‌شود.', 'negarandeh' ); ?></p>
							</td>
							<th class="negarandeh-tag-count-label"><?php esc_html_e( 'تعداد برچسب', 'negarandeh' ); ?></th>
							<td class="negarandeh-tag-count-field">
								<input type="number" name="negarandeh_generator_settings[tag_count]" id="negarandeh-tag-count" value="<?php echo esc_attr( (string) (int) ( $gen_settings['tag_count'] ?? 5 ) ); ?>" min="1" max="15" step="1" <?php disabled( empty( $gen_settings['generate_tags'] ) ); ?> />
								<p class="description"><?php esc_html_e( 'بین ۱ تا ۱۵ برچسب برای هر پست.', 'negarandeh' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="negarandeh-card negarandeh-settings-grid">
					<div class="negarandeh-card-header">
						<span class="negarandeh-step-num">4</span>
						<div>
							<h2 class="negarandeh-card-title"><?php esc_html_e( 'تولید خودکار', 'negarandeh' ); ?></h2>
							<p class="negarandeh-card-desc"><?php esc_html_e( 'نحوهٔ پردازش صف تولید را انتخاب کنید: پس‌زمینه با WP-Cron یا اجرای دستی در همین صفحه با AJAX.', 'negarandeh' ); ?></p>
						</div>
					</div>
					<?php
					$queue_driver = in_array( $gen_settings['queue_driver'] ?? '', array( 'ajax', 'wp_cron' ), true )
						? (string) $gen_settings['queue_driver']
						: 'wp_cron';
					?>
					<fieldset class="negarandeh-queue-driver-fieldset">
						<legend class="screen-reader-text"><?php esc_html_e( 'روش تولید', 'negarandeh' ); ?></legend>
						<label class="negarandeh-queue-driver-option">
							<input type="radio" name="negarandeh_generator_settings[queue_driver]" value="wp_cron" <?php checked( $queue_driver, 'wp_cron' ); ?> />
							<span><?php esc_html_e( 'WP-Cron', 'negarandeh' ); ?></span>
						</label>
						<label class="negarandeh-queue-driver-option">
							<input type="radio" name="negarandeh_generator_settings[queue_driver]" value="ajax" <?php checked( $queue_driver, 'ajax' ); ?> />
							<span><?php esc_html_e( 'AJAX — دستی', 'negarandeh' ); ?></span>
						</label>
					</fieldset>

					<div class="negarandeh-queue-driver-panel" id="negarandeh-queue-driver-wp-cron-panel" <?php echo 'wp_cron' !== $queue_driver ? 'hidden' : ''; ?>>
						<p class="negarandeh-cron-interval-row">
							<label for="negarandeh-cron-interval"><?php esc_html_e( 'فاصله اجرا:', 'negarandeh' ); ?></label>
							<select name="negarandeh_generator_settings[cron_interval_minutes]" id="negarandeh-cron-interval">
								<?php for ( $m = 1; $m <= 5; $m++ ) : ?>
									<option value="<?php echo esc_attr( (string) $m ); ?>" <?php selected( $cron_interval, $m ); ?>>
										<?php
										echo esc_html(
											sprintf(
												/* translators: %d: number of minutes */
												_n( 'هر %d دقیقه', 'هر %d دقیقه', $m, 'negarandeh' ),
												(int) $m
											)
										);
										?>
									</option>
								<?php endfor; ?>
							</select>
						</p>
						<p class="description"><?php esc_html_e( 'پست‌ها در پس‌زمینه ساخته می‌شوند و نیازی به باز نگه‌داشتن مرورگر نیست.', 'negarandeh' ); ?></p>
						<p class="description"><?php esc_html_e( 'بعد از تغییر فاصله، «ذخیره تنظیمات» را بزنید تا WP-Cron با interval جدید ثبت شود.', 'negarandeh' ); ?></p>
						<?php if ( 'wp_cron' === $queue_driver && ! $auto_enabled ) : ?>
							<p class="description negarandeh-cron-waiting"><?php esc_html_e( 'Cron ذخیره می‌شود؛ برای اجرا باید استارت بزنید.', 'negarandeh' ); ?></p>
						<?php elseif ( 'wp_cron' === $queue_driver && $next_auto && $auto_enabled ) : ?>
							<p class="description">
								<strong><?php esc_html_e( 'اجرای بعدی:', 'negarandeh' ); ?></strong>
								<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $next_auto ) ); ?>
							</p>
						<?php endif; ?>
					</div>

					<div class="negarandeh-queue-driver-panel" id="negarandeh-queue-driver-ajax-panel" <?php echo 'ajax' !== $queue_driver ? 'hidden' : ''; ?>>
						<p class="description"><?php esc_html_e( 'محتوا در همین صفحه ساخته می‌شود. بستن مرورگر یا رفتن به لینک دیگر باعث توقف فرایند ساخت خواهد شد.', 'negarandeh' ); ?></p>
					</div>
				</div>

				<div class="negarandeh-card">
					<div class="negarandeh-card-header">
						<span class="negarandeh-step-num">5</span>
						<div>
							<h2 class="negarandeh-card-title"><?php esc_html_e( 'پرامپت تصویر شاخص', 'negarandeh' ); ?></h2>
							<p class="negarandeh-card-desc"><?php esc_html_e( 'دقیقاً همین متن (بعد از جایگزینی placeholderها) به Image API ارسال می‌شود. برای Cron حتماً «ذخیره تنظیمات» را بزنید.', 'negarandeh' ); ?></p>
						</div>
					</div>
					<div class="negarandeh-placeholders-wrap">
						<p class="negarandeh-placeholders-label"><?php esc_html_e( 'Placeholderها', 'negarandeh' ); ?></p>
						<div class="negarandeh-placeholders">
							<?php foreach ( $image_placeholders as $tag => $desc ) : ?>
								<button type="button" class="button button-small negarandeh-insert-placeholder" data-tag="<?php echo esc_attr( $tag ); ?>" title="<?php echo esc_attr( $desc ); ?>">
									<code dir="ltr"><?php echo esc_html( $tag ); ?></code>
								</button>
							<?php endforeach; ?>
						</div>
					</div>
					<textarea name="negarandeh_generator_settings[image_prompt_template]" rows="18" class="large-text negarandeh-image-prompt-textarea" id="negarandeh-image-prompt" dir="<?php echo esc_attr( NEGARANDEH_I18n::get_direction() ); ?>"><?php echo esc_textarea( $gen_settings['image_prompt_template'] ); ?></textarea>
					<ul class="negarandeh-placeholder-legend" aria-label="<?php esc_attr_e( 'راهنمای placeholderهای تصویر', 'negarandeh' ); ?>">
						<?php foreach ( $image_placeholders as $tag => $desc ) : ?>
							<li class="negarandeh-placeholder-item">
								<code class="negarandeh-placeholder-tag" dir="ltr"><?php echo esc_html( $tag ); ?></code>
								<span class="negarandeh-placeholder-desc"><?php echo esc_html( $desc ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
					<p class="negarandeh-image-in-post-option">
						<label>
							<input type="checkbox" name="negarandeh_generator_settings[insert_image_in_post]" id="negarandeh-insert-image-in-post" value="1" <?php checked( ! empty( $gen_settings['insert_image_in_post'] ) ); ?> />
							<?php esc_html_e( 'افزودن تصویر در پست', 'negarandeh' ); ?>
						</label>
						<span class="description"><?php esc_html_e( 'در بعضی قالب‌ها تصویر شاخص در پست قرار نمی‌گیرد؛ با فعال کردن این گزینه، تصویر بعد از اولین پاراگراف محتوای پست درج می‌شود.', 'negarandeh' ); ?></span>
					</p>
					<p class="negarandeh-image-prompt-actions">
						<button type="button" class="button button-secondary" id="negarandeh-preview-image-prompt"><?php esc_html_e( 'پیش‌نمایش تصویر', 'negarandeh' ); ?></button>
						<button type="button" class="button button-secondary" id="negarandeh-reset-image-prompt"><?php esc_html_e( 'ریست پرامپت تصویر', 'negarandeh' ); ?></button>
						<span class="description"><?php esc_html_e( 'اولین موضوع لیست برای تست استفاده می‌شود.', 'negarandeh' ); ?></span>
					</p>
					<div id="negarandeh-image-preview-status" class="negarandeh-test-result" aria-live="polite"></div>
					<div id="negarandeh-image-preview-box" class="negarandeh-image-preview-box" hidden>
						<p class="negarandeh-image-preview-title"><?php esc_html_e( 'پیش‌نمایش', 'negarandeh' ); ?></p>
						<img id="negarandeh-image-preview-img" src="" alt="" />
						<details class="negarandeh-image-preview-details">
							<summary><?php esc_html_e( 'پرامپت ارسال‌شده', 'negarandeh' ); ?></summary>
							<pre id="negarandeh-image-preview-prompt-used" dir="ltr"></pre>
						</details>
					</div>
				</div>

				<div class="negarandeh-actions">
					<?php submit_button( __( 'ذخیره تنظیمات', 'negarandeh' ), 'secondary', 'submit', false ); ?>
					<button type="button" class="button button-primary button-hero" id="negarandeh-start-btn">
						<span class="negarandeh-start-btn__inner">
							<span class="dashicons dashicons-controls-play negarandeh-start-btn__icon" aria-hidden="true"></span>
							<span class="negarandeh-start-btn__label"><?php esc_html_e( 'شروع تولید', 'negarandeh' ); ?></span>
						</span>
					</button>
				</div>
			</form>
		</div>

		<div class="negarandeh-sidebar">
			<div class="negarandeh-panel negarandeh-panel-power" id="negarandeh-power-panel">
				<h3><span class="negarandeh-panel-icon">&#9889;</span> <?php esc_html_e( 'وضعیت تولید', 'negarandeh' ); ?></h3>
				<p class="negarandeh-power-state <?php echo esc_attr( $auto_enabled ? 'is-on' : 'is-off' ); ?>" id="negarandeh-power-state-line">
					<strong><?php echo esc_html( $auto_enabled ? __( 'روشن — تولید مجاز است', 'negarandeh' ) : __( 'خاموش — تولید متوقف است', 'negarandeh' ) ); ?></strong>
				</p>
				<p class="description" id="negarandeh-power-state-hint">
					<?php
					if ( $auto_enabled ) {
						esc_html_e( 'صف دستی و Cron (در صورت فعال بودن) می‌توانند اجرا شوند.', 'negarandeh' );
					} else {
						esc_html_e( 'برای شروع، دکمه استارت بالای صفحه را بزنید.', 'negarandeh' );
					}
					?>
				</p>
			</div>

			<div class="negarandeh-panel" id="negarandeh-auto-cron-panel">
				<h3><span class="negarandeh-panel-icon">&#9201;</span> <?php esc_html_e( 'زمان‌بندی Cron', 'negarandeh' ); ?></h3>
				<div id="negarandeh-cron-status-content"
					data-interval-minutes="<?php echo esc_attr( (string) $cron_interval ); ?>"
					data-active-interval-minutes="<?php echo esc_attr( (string) NEGARANDEH_Batch_Processor::get_active_cron_interval_minutes() ); ?>">
					<?php if ( 'wp_cron' === ( $gen_settings['queue_driver'] ?? 'wp_cron' ) ) : ?>
						<?php if ( $auto_enabled && $next_auto ) : ?>
							<p class="negarandeh-cron-active"><?php echo esc_html( NEGARANDEH_Batch_Processor::get_cron_running_label() ); ?></p>
							<p class="description">
								<strong><?php esc_html_e( 'اجرای بعدی:', 'negarandeh' ); ?></strong>
								<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $next_auto ) ); ?>
							</p>
						<?php elseif ( $auto_enabled ) : ?>
							<p class="negarandeh-cron-active">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: interval label */
										__( 'فعال — %s — در انتظار WP-Cron', 'negarandeh' ),
										NEGARANDEH_Batch_Processor::get_cron_interval_label( NEGARANDEH_Batch_Processor::get_active_cron_interval_minutes() )
									)
								);
								?>
							</p>
						<?php else : ?>
							<p class="negarandeh-cron-waiting"><?php esc_html_e( 'Cron تنظیم شده — منتظر استارت', 'negarandeh' ); ?></p>
							<p class="description">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: interval label */
										__( 'تولید خودکار (%s) فعال است؛ تا استارت نزنید اجرا نمی‌شود.', 'negarandeh' ),
										NEGARANDEH_Batch_Processor::get_cron_interval_label( $cron_interval )
									)
								);
								?>
							</p>
						<?php endif; ?>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Cron غیرفعال', 'negarandeh' ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<div class="negarandeh-panel" id="negarandeh-topic-status-panel">
				<h3><span class="negarandeh-panel-icon">&#9776;</span> <?php esc_html_e( 'وضعیت موضوعات', 'negarandeh' ); ?></h3>
				<div class="negarandeh-stat-row" id="negarandeh-topic-stats">
					<span class="negarandeh-stat-pill negarandeh-stat-pill--ok"><?php echo esc_html( (int) ( $topic_stats['success'] ?? 0 ) + (int) ( $topic_stats['warning'] ?? 0 ) ); ?> <?php esc_html_e( 'موفق', 'negarandeh' ); ?></span>
					<span class="negarandeh-stat-pill negarandeh-stat-pill--err"><?php echo esc_html( (int) ( $topic_stats['error'] ?? 0 ) ); ?> <?php esc_html_e( 'خطا', 'negarandeh' ); ?></span>
					<span class="negarandeh-stat-pill negarandeh-stat-pill--wait"><?php echo esc_html( (int) ( $topic_stats['pending'] ?? 0 ) ); ?> <?php esc_html_e( 'انتظار', 'negarandeh' ); ?></span>
				</div>
				<ul class="negarandeh-topic-board" id="negarandeh-topic-board">
					<?php if ( empty( $topic_board ) ) : ?>
						<li class="description"><?php esc_html_e( 'لیست خالی است.', 'negarandeh' ); ?></li>
					<?php else : ?>
						<?php foreach ( $topic_board as $row ) : ?>
							<li class="negarandeh-topic-row negarandeh-status-<?php echo esc_attr( (string) ( $row['status'] ?? 'pending' ) ); ?>">
								<strong><?php echo esc_html( (string) ( $row['topic'] ?? '' ) ); ?></strong>
								<span class="negarandeh-topic-badge"><?php echo esc_html( NEGARANDEH_Batch_Processor::get_status_label( (string) ( $row['status'] ?? 'pending' ) ) ); ?></span>
								<?php if ( ! empty( $row['message'] ) ) : ?><small><?php echo esc_html( (string) $row['message'] ); ?></small><?php endif; ?>
							</li>
						<?php endforeach; ?>
					<?php endif; ?>
				</ul>
				<div class="negarandeh-topic-actions">
					<button type="button" class="button button-small" id="negarandeh-reset-failed-topics"><?php esc_html_e( 'آزادسازی خطادارها', 'negarandeh' ); ?></button>
					<button type="button" class="button button-small" id="negarandeh-reset-generated-topics"><?php esc_html_e( 'ریست موضوعات ساخته‌شده', 'negarandeh' ); ?></button>
				</div>
				<p class="description"><?php esc_html_e( 'ریست: موضوعات موفق دوباره قابل تولید می‌شوند. پست‌های قبلی حذف نمی‌شوند. اگر تولید روشن باشد، Stop می‌شود.', 'negarandeh' ); ?></p>
			</div>

			<div class="negarandeh-panel" id="negarandeh-status-panel">
				<h3><span class="negarandeh-panel-icon">&#9881;</span> <?php esc_html_e( 'صف دستی', 'negarandeh' ); ?></h3>
				<div id="negarandeh-status-content">
					<?php if ( ! empty( $queue['status'] ) ) : ?>
						<p><?php echo esc_html( ( $queue['current'] ?? 0 ) . ' / ' . ( $queue['total'] ?? 0 ) ); ?> — <?php echo esc_html( $queue['status'] ); ?></p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'صف خالی', 'negarandeh' ); ?></p>
					<?php endif; ?>
				</div>
				<div class="negarandeh-progress-wrap" style="display:none;">
					<div class="negarandeh-progress-bar"><div class="negarandeh-progress-fill"></div></div>
					<span class="negarandeh-progress-text">0%</span>
				</div>
				<button type="button" class="button button-small" id="negarandeh-clear-queue"><?php esc_html_e( 'پاک کردن صف', 'negarandeh' ); ?></button>
			</div>

			<div class="negarandeh-panel">
				<h3><span class="negarandeh-panel-icon">&#128196;</span> <?php esc_html_e( 'لاگ تولید', 'negarandeh' ); ?></h3>
				<div class="negarandeh-log-panel-actions">
					<a class="button button-small" href="<?php echo esc_url( $log_url ); ?>"><?php esc_html_e( 'صفحه لاگ', 'negarandeh' ); ?></a>
					<button type="button" class="button button-small" id="negarandeh-clear-log" <?php disabled( empty( $permanent_log ) ); ?>><?php esc_html_e( 'پاکسازی', 'negarandeh' ); ?></button>
				</div>
				<ul id="negarandeh-log-list" class="negarandeh-log-list negarandeh-permanent-log">
					<?php echo NEGARANDEH_Batch_Processor::render_log_list_html( $permanent_log ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</ul>
			</div>

			<div class="negarandeh-panel negarandeh-tips">
				<h3><?php esc_html_e( 'راهنمای سریع', 'negarandeh' ); ?></h3>
				<ul>
					<li><?php esc_html_e( 'اول API را در «تنظیمات API» تست کنید.', 'negarandeh' ); ?></li>
					<li><?php esc_html_e( 'با ۱–۲ موضوع شروع کنید.', 'negarandeh' ); ?></li>
					<li><?php esc_html_e( 'پیش‌نویس = بازبینی قبل از انتشار.', 'negarandeh' ); ?></li>
					<li><?php esc_html_e( 'خطادارها را «آزاد» کنید تا دوباره امتحان شوند.', 'negarandeh' ); ?></li>
					<li><?php esc_html_e( 'برای تولید دوباره همان موضوعات، «ریست موضوعات ساخته‌شده» را بزنید.', 'negarandeh' ); ?></li>
				</ul>
			</div>

			<div class="negarandeh-panel negarandeh-tips negarandeh-credit">
				<p><?php esc_html_e( 'این پلاگین به صورت رایگان و اپن سورس منتشر شده و مسئولیت و نحوه استفاده از آن به عهده شماست.', 'negarandeh' ); ?></p>
				<p class="negarandeh-credit-sign">
					<?php esc_html_e( 'با تشکر', 'negarandeh' ); ?><br />
					<span dir="ltr">M@soud</span>
				</p>
			</div>
		</div>
	</div>
</div>
