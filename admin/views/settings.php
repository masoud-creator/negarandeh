<?php
/**
 * API settings — نگارنده.
 *
 * @package Negarandeh
 * @var array<string,mixed> $settings
 */

defined( 'ABSPATH' ) || exit;

$default_base = NEGARANDEH_Avalai_API::DEFAULT_BASE_URL;
$docs_base    = NEGARANDEH_I18n::LANG_EN === NEGARANDEH_I18n::get_lang() ? 'https://docs.avalai.org/en/' : 'https://docs.avalai.org/fa/';
?>
<div <?php echo NEGARANDEH_I18n::wrap_attrs( 'negarandeh-settings-wrap' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="negarandeh-hero negarandeh-settings-hero">
		<h1><?php esc_html_e( 'تنظیمات API', 'negarandeh' ); ?></h1>
		<p class="negarandeh-hero-tagline">
			<?php
			printf(
				/* translators: 1: docs link, 2: default API URL */
				esc_html__( 'اتصال به AvalAI — سازگار با OpenAI API. مستندات: %1$s | آدرس پیش‌فرض: %2$s', 'negarandeh' ),
				'<a href="' . esc_url( $docs_base ) . '" target="_blank" rel="noopener" style="color:#fff;text-decoration:underline">' . esc_html( preg_replace( '#^https://#', '', $docs_base ) ) . '</a>',
				esc_html( $default_base )
			);
			?>
		</p>
	</div>

	<div class="negarandeh-notice negarandeh-notice-docs">
		<strong><?php esc_html_e( 'نکته:', 'negarandeh' ); ?></strong>
		<?php esc_html_e( 'مدل متن و تصویر جدا تنظیم می‌شوند.', 'negarandeh' ); ?>
		<?php
		echo wp_kses(
			sprintf(
				/* translators: 1: default chat model HTML, 2: default image model HTML */
				__( 'دیفالت متن: %1$s — دیفالت تصویر: %2$s', 'negarandeh' ),
				'<code dir="ltr">gpt-4o-mini</code>',
				'<code dir="ltr">' . esc_html( NEGARANDEH_Avalai_API::DEFAULT_IMAGE_MODEL ) . '</code>'
			),
			array(
				'code' => array(
					'dir' => true,
				),
			)
		);
		?>
	</div>

	<div class="negarandeh-settings-card">
		<form method="post" action="options.php">
			<?php settings_fields( 'negarandeh_settings_group' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="negarandeh_ui_language"><?php esc_html_e( 'زبان پنل', 'negarandeh' ); ?></label></th>
					<td>
						<select id="negarandeh_ui_language" name="negarandeh_settings[ui_language]">
							<?php
							$ui_lang = (string) ( $settings['ui_language'] ?? 'auto' );
							$langs   = array(
								'fa'   => __( 'فارسی', 'negarandeh' ),
								'en'   => __( 'English', 'negarandeh' ),
								'auto' => __( 'خودکار (مطابق وردپرس)', 'negarandeh' ),
							);
							foreach ( $langs as $val => $label ) :
								?>
								<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $ui_lang, $val ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'چیدمان RTL برای فارسی و LTR برای انگلیسی. بعد از ذخیره صفحه را رفرش کنید.', 'negarandeh' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="negarandeh_api_base_url"><?php esc_html_e( 'آدرس API', 'negarandeh' ); ?></label></th>
					<td>
						<input type="url" id="negarandeh_api_base_url" name="negarandeh_settings[api_base_url]" value="<?php echo esc_attr( $settings['api_base_url'] ); ?>" class="large-text code" dir="ltr" placeholder="<?php echo esc_attr( $default_base ); ?>" />
						<p class="description"><?php esc_html_e( 'بدون /chat/completions در انتها.', 'negarandeh' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="negarandeh_api_key"><?php esc_html_e( 'کلید API', 'negarandeh' ); ?></label></th>
					<td>
						<input type="password" id="negarandeh_api_key" name="negarandeh_settings[api_key]" value="<?php echo esc_attr( $settings['api_key'] ?? '' ); ?>" class="large-text code" dir="ltr" autocomplete="off" spellcheck="false" placeholder="sk-..." />
						<button type="button" class="button" id="negarandeh-test-api" data-has-saved-key="<?php echo ! empty( $settings['api_key'] ) ? '1' : '0'; ?>" data-has-base-url="<?php echo ! empty( $settings['api_base_url'] ) ? '1' : '0'; ?>"><?php esc_html_e( 'تست اتصال', 'negarandeh' ); ?></button>
						<div id="negarandeh-test-result" class="negarandeh-test-result" aria-live="polite"></div>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="negarandeh_chat_model"><?php esc_html_e( 'مدل متن', 'negarandeh' ); ?></label></th>
					<td>
						<input type="text" id="negarandeh_chat_model" name="negarandeh_settings[chat_model]" value="<?php echo esc_attr( $settings['chat_model'] ); ?>" class="regular-text" dir="ltr" placeholder="gpt-4o-mini" />
						<button type="button" class="button" id="negarandeh-load-text-models"><?php esc_html_e( 'لیست مدل‌های متن', 'negarandeh' ); ?></button>
						<div id="negarandeh-text-models-panel" class="negarandeh-models-panel" hidden data-kind="text" data-target="#negarandeh_chat_model">
							<div class="negarandeh-models-toolbar">
								<button type="button" class="negarandeh-models-close" id="negarandeh-close-text-models" aria-label="<?php esc_attr_e( 'بستن', 'negarandeh' ); ?>">
									<span class="negarandeh-models-close__icon" aria-hidden="true">&times;</span>
									<span class="negarandeh-models-close__label"><?php esc_html_e( 'بستن', 'negarandeh' ); ?></span>
								</button>
								<input type="search" class="negarandeh-models-filter regular-text" placeholder="<?php esc_attr_e( 'جستجوی مدل…', 'negarandeh' ); ?>" />
								<span class="negarandeh-models-meta description"></span>
							</div>
							<div class="negarandeh-models-tips" role="note">
								<strong class="negarandeh-models-tips-title"><?php esc_html_e( 'نکته مهم:', 'negarandeh' ); ?></strong>
								<ul>
									<li><?php esc_html_e( 'ممکن است همه مدل‌ها فعال نباشند؛ اول تست کنید.', 'negarandeh' ); ?></li>
									<li><?php esc_html_e( 'هزینه استفاده از هر مدل بر اساس نرخ ورودی و خروجی متفاوت است؛ قبل از استفاده حتماً آن را چک کنید تا اعتبارتان خالی نشود.', 'negarandeh' ); ?></li>
									<li><?php esc_html_e( 'سعی کنید از مدل‌هایی که می‌شناسید استفاده کنید یا در مورد آن‌ها تحقیق کنید.', 'negarandeh' ); ?></li>
								</ul>
							</div>
							<div class="negarandeh-models-list" role="listbox" aria-label="<?php esc_attr_e( 'مدل‌های متن', 'negarandeh' ); ?>"></div>
							<p class="description"><?php esc_html_e( 'روی یک مدل کلیک کنید تا نام آن کپی و در فیلد بالا قرار بگیرد. سپس ذخیره را بزنید.', 'negarandeh' ); ?></p>
						</div>
						<p class="description">
							<?php
							echo wp_kses(
								sprintf(
									/* translators: 1: default chat model, 2: mid-tier model, 3: powerful model, 4: powerful model */
									__( 'به‌صورت پیش‌فرض %1$s است؛ اگر به مطالب حرفه‌ای‌تر و استدلال عمیق‌تر نیاز دارید، مدل %2$s با هزینه کمی بیشتر مناسب است.', 'negarandeh' ),
									'<code dir="ltr">gpt-4o-mini</code>',
									'<code dir="ltr">gpt-5.4-mini</code>'
								),
								array(
									'code' => array(
										'dir' => true,
									),
								)
							);
							?>
							<br />
							<?php
							echo wp_kses(
								sprintf(
									/* translators: 1: powerful model, 2: powerful model */
									__( 'مدل‌های %1$s و %2$s قدرتمندترند و هزینه بیشتری دارند.', 'negarandeh' ),
									'<code dir="ltr">gpt-5.4</code>',
									'<code dir="ltr">gpt-5.5</code>'
								),
								array(
									'code' => array(
										'dir' => true,
									),
								)
							);
							?>
							<br />
							<?php esc_html_e( 'برای انتخاب مدل مناسب برای خودتان به مستندات راهنما مراجعه کنید.', 'negarandeh' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="negarandeh_image_model"><?php esc_html_e( 'مدل تصویر', 'negarandeh' ); ?></label></th>
					<td>
						<input type="text" id="negarandeh_image_model" name="negarandeh_settings[image_model]" value="<?php echo esc_attr( $settings['image_model'] ?? NEGARANDEH_Avalai_API::DEFAULT_IMAGE_MODEL ); ?>" class="regular-text" dir="ltr" placeholder="<?php echo esc_attr( NEGARANDEH_Avalai_API::DEFAULT_IMAGE_MODEL ); ?>" />
						<button type="button" class="button" id="negarandeh-load-image-models"><?php esc_html_e( 'لیست مدل‌های تصویر', 'negarandeh' ); ?></button>
						<button type="button" class="button" id="negarandeh-test-image"><?php esc_html_e( 'تست تصویر', 'negarandeh' ); ?></button>
						<div id="negarandeh-image-models-panel" class="negarandeh-models-panel" hidden data-kind="image" data-target="#negarandeh_image_model">
							<div class="negarandeh-models-toolbar">
								<button type="button" class="negarandeh-models-close" id="negarandeh-close-image-models" aria-label="<?php esc_attr_e( 'بستن', 'negarandeh' ); ?>">
									<span class="negarandeh-models-close__icon" aria-hidden="true">&times;</span>
									<span class="negarandeh-models-close__label"><?php esc_html_e( 'بستن', 'negarandeh' ); ?></span>
								</button>
								<input type="search" class="negarandeh-models-filter regular-text" placeholder="<?php esc_attr_e( 'جستجوی مدل…', 'negarandeh' ); ?>" />
								<span class="negarandeh-models-meta description"></span>
							</div>
							<div class="negarandeh-models-tips" role="note">
								<strong class="negarandeh-models-tips-title"><?php esc_html_e( 'نکته مهم:', 'negarandeh' ); ?></strong>
								<ul>
									<li><?php esc_html_e( 'ممکن است همه مدل‌ها فعال نباشند؛ اول تست کنید.', 'negarandeh' ); ?></li>
									<li><?php esc_html_e( 'هزینه استفاده از هر مدل بر اساس نرخ ورودی و خروجی متفاوت است؛ قبل از استفاده حتماً آن را چک کنید تا اعتبارتان خالی نشود.', 'negarandeh' ); ?></li>
									<li><?php esc_html_e( 'سعی کنید از مدل‌هایی که می‌شناسید استفاده کنید یا در مورد آن‌ها تحقیق کنید.', 'negarandeh' ); ?></li>
								</ul>
							</div>
							<div class="negarandeh-models-list" role="listbox" aria-label="<?php esc_attr_e( 'مدل‌های تصویر', 'negarandeh' ); ?>"></div>
							<p class="description"><?php esc_html_e( 'روی یک مدل کلیک کنید تا نام آن کپی و در فیلد بالا قرار بگیرد. سپس ذخیره را بزنید.', 'negarandeh' ); ?></p>
						</div>
						<div id="negarandeh-test-image-result" class="negarandeh-test-result" aria-live="polite"></div>
						<p class="description">
							<?php
							echo wp_kses(
								sprintf(
									/* translators: %s: default image model HTML */
									__( 'برای انتخاب مدل AI به مستندات مراجعه کنید، گزینه پیش‌فرض: %s', 'negarandeh' ),
									'<code dir="ltr">' . esc_html( NEGARANDEH_Avalai_API::DEFAULT_IMAGE_MODEL ) . '</code>'
								),
								array(
									'code' => array(
										'dir' => true,
									),
								)
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="negarandeh_image_api_mode"><?php esc_html_e( 'روش تصویر', 'negarandeh' ); ?></label></th>
					<td>
						<select id="negarandeh_image_api_mode" name="negarandeh_settings[image_api_mode]">
							<?php
							$modes = array(
								'auto'   => __( 'خودکار', 'negarandeh' ),
								'chat'   => __( 'chat/completions', 'negarandeh' ),
								'images' => __( 'images/generations', 'negarandeh' ),
							);
							foreach ( $modes as $val => $label ) :
								?>
								<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $settings['image_api_mode'] ?? 'auto', $val ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="negarandeh_temperature"><?php esc_html_e( 'Temperature', 'negarandeh' ); ?></label></th>
					<td><input type="number" id="negarandeh_temperature" name="negarandeh_settings[temperature]" value="<?php echo esc_attr( $settings['temperature'] ); ?>" min="0" max="2" step="0.1" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="negarandeh_max_tokens"><?php esc_html_e( 'حداکثر توکن', 'negarandeh' ); ?></label></th>
					<td>
						<input type="number" id="negarandeh_max_tokens" name="negarandeh_settings[max_tokens]" value="<?php echo esc_attr( $settings['max_tokens'] ); ?>" min="500" max="16000" step="100" />
						<p class="description"><?php esc_html_e( 'مقالات بلند: ۱۲۰۰۰+', 'negarandeh' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="negarandeh_image_size"><?php esc_html_e( 'اندازه تصویر', 'negarandeh' ); ?></label></th>
					<td>
						<select id="negarandeh_image_size" name="negarandeh_settings[image_size]">
							<option value="1200x675" <?php selected( $settings['image_size'], '1200x675' ); ?>><?php esc_html_e( '1200×675 (۱۶:۹)', 'negarandeh' ); ?></option>
							<option value="1792x1024" <?php selected( $settings['image_size'], '1792x1024' ); ?>><?php esc_html_e( '1792×1024', 'negarandeh' ); ?></option>
						</select>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'ذخیره', 'negarandeh' ) ); ?>
		</form>
	</div>
</div>
