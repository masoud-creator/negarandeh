<?php
/**
 * Guide page — AvalAI setup & models.
 *
 * @package Negarandeh
 */

defined( 'ABSPATH' ) || exit;

$settings_url = admin_url( 'admin.php?page=' . NEGARANDEH_Plugin::SLUG_SETTINGS );
$gen_url      = admin_url( 'admin.php?page=' . NEGARANDEH_Plugin::SLUG );
$api_base     = NEGARANDEH_Avalai_API::DEFAULT_BASE_URL;
$lang         = NEGARANDEH_I18n::get_lang();

$credit_url  = 'https://chat.avalai.org/platform/billing/credit';
$models_url  = 'https://chat.avalai.org/platform/models';
$docs_base   = NEGARANDEH_I18n::LANG_EN === $lang ? 'https://docs.avalai.org/en/' : 'https://docs.avalai.org/fa/';
$docs_models = $docs_base . 'models/model-details';
$docs_main   = $docs_base;
?>
<div <?php echo NEGARANDEH_I18n::wrap_attrs( 'negarandeh-guide-wrap' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="negarandeh-hero negarandeh-guide-hero">
		<h1><?php esc_html_e( 'راهنمای نگارنده', 'negarandeh' ); ?></h1>
		<p class="negarandeh-hero-tagline">
			<?php esc_html_e( 'همه‌چیز برای شروع: اعتبار حساب، انتخاب مدل مناسب، و اتصال به پلاگین.', 'negarandeh' ); ?>
		</p>
		<div class="negarandeh-hero-badges">
			<span class="negarandeh-badge">&#128176; <?php esc_html_e( 'اعتبار', 'negarandeh' ); ?></span>
			<span class="negarandeh-badge">&#129302; <?php esc_html_e( 'مدل‌ها', 'negarandeh' ); ?></span>
			<span class="negarandeh-badge">&#9881; <?php esc_html_e( 'تنظیمات', 'negarandeh' ); ?></span>
		</div>
	</div>

	<div class="negarandeh-guide-quick">
		<a class="negarandeh-guide-quick-card" href="<?php echo esc_url( $credit_url ); ?>" target="_blank" rel="noopener noreferrer">
			<span class="negarandeh-guide-quick-icon" aria-hidden="true">&#128179;</span>
			<span class="negarandeh-guide-quick-label"><?php esc_html_e( 'شارژ اعتبار', 'negarandeh' ); ?></span>
		</a>
		<a class="negarandeh-guide-quick-card" href="<?php echo esc_url( $models_url ); ?>" target="_blank" rel="noopener noreferrer">
			<span class="negarandeh-guide-quick-icon" aria-hidden="true">&#128200;</span>
			<span class="negarandeh-guide-quick-label"><?php esc_html_e( 'لیست مدل‌ها', 'negarandeh' ); ?></span>
		</a>
		<a class="negarandeh-guide-quick-card" href="<?php echo esc_url( $docs_models ); ?>" target="_blank" rel="noopener noreferrer">
			<span class="negarandeh-guide-quick-icon" aria-hidden="true">&#128214;</span>
			<span class="negarandeh-guide-quick-label"><?php esc_html_e( 'مستندات مدل', 'negarandeh' ); ?></span>
		</a>
		<a class="negarandeh-guide-quick-card negarandeh-guide-quick-card--internal" href="<?php echo esc_url( $settings_url ); ?>">
			<span class="negarandeh-guide-quick-icon" aria-hidden="true">&#128273;</span>
			<span class="negarandeh-guide-quick-label"><?php esc_html_e( 'تنظیمات API', 'negarandeh' ); ?></span>
		</a>
	</div>

	<div class="negarandeh-guide-grid">
		<!-- اعتبار -->
		<section class="negarandeh-card negarandeh-guide-section">
			<div class="negarandeh-guide-section-head">
				<span class="negarandeh-guide-section-icon negarandeh-guide-icon-credit" aria-hidden="true">&#128176;</span>
				<div>
					<h2 class="negarandeh-card-title"><?php esc_html_e( '۱. افزایش اعتبار (Credit)', 'negarandeh' ); ?></h2>
					<p class="negarandeh-card-desc"><?php esc_html_e( 'بدون اعتبار کافی، درخواست‌های API رد می‌شوند.', 'negarandeh' ); ?></p>
				</div>
			</div>

			<div class="negarandeh-guide-body">
				<p>
					<?php esc_html_e( 'برای تولید متن و تصویر باید در پلتفرم AvalAI اعتبار (Credit) داشته باشید. هر درخواست به API — چه مقاله، چه تصویر شاخص — از اعتبار شما کسر می‌شود.', 'negarandeh' ); ?>
				</p>

				<ol class="negarandeh-guide-steps">
					<li>
						<span class="negarandeh-guide-step-num">1</span>
						<?php esc_html_e( 'وارد حساب AvalAI شوید.', 'negarandeh' ); ?>
					</li>
					<li>
						<span class="negarandeh-guide-step-num">2</span>
						<?php esc_html_e( 'از منوی پلتفرم به بخش «اعتبار / Billing» بروید.', 'negarandeh' ); ?>
					</li>
					<li>
						<span class="negarandeh-guide-step-num">3</span>
						<?php esc_html_e( 'مبلغ موردنظر را شارژ کنید و وضعیت اعتبار را بررسی کنید.', 'negarandeh' ); ?>
					</li>
					<li>
						<span class="negarandeh-guide-step-num">4</span>
						<?php esc_html_e( 'کلید API همان حساب را در پلاگین وارد کنید — اعتبار بین پنل و API مشترک است.', 'negarandeh' ); ?>
					</li>
				</ol>

				<div class="negarandeh-guide-tip">
					<span class="negarandeh-guide-tip-icon" aria-hidden="true">&#128161;</span>
					<p>
						<strong><?php esc_html_e( 'نکته:', 'negarandeh' ); ?></strong>
						<?php esc_html_e( 'تولید تصویر معمولاً گران‌تر از متن کوتاه است. برای تست اولیه با ۱–۲ موضوع شروع کنید و مصرف را در پنل AvalAI ببینید.', 'negarandeh' ); ?>
					</p>
				</div>

				<a class="negarandeh-guide-btn" href="<?php echo esc_url( $credit_url ); ?>" target="_blank" rel="noopener noreferrer">
					<span aria-hidden="true">&#128179;</span>
					<?php esc_html_e( 'رفتن به صفحه شارژ اعتبار', 'negarandeh' ); ?>
					<span class="negarandeh-guide-btn-arrow" aria-hidden="true">&#8592;</span>
				</a>
			</div>
		</section>

		<!-- مدل‌ها -->
		<section class="negarandeh-card negarandeh-guide-section">
			<div class="negarandeh-guide-section-head">
				<span class="negarandeh-guide-section-icon negarandeh-guide-icon-models" aria-hidden="true">&#129302;</span>
				<div>
					<h2 class="negarandeh-card-title"><?php esc_html_e( '۲. انتخاب مدل (متن و تصویر)', 'negarandeh' ); ?></h2>
					<p class="negarandeh-card-desc"><?php esc_html_e( 'کیفیت، سرعت و هزینه در هر مدل متفاوت است.', 'negarandeh' ); ?></p>
				</div>
			</div>

			<div class="negarandeh-guide-body">
				<p>
					<?php esc_html_e( 'مدل‌های AvalAI از نظر کیفیت خروجی، هزینه هر درخواست (توکن یا تصویر) و قابلیت‌های API با هم فرق دارند. قبل از انتخاب، لیست مدل‌ها و جزئیات فنی را ببینید.', 'negarandeh' ); ?>
				</p>

				<div class="negarandeh-guide-compare">
					<div class="negarandeh-guide-compare-item">
						<span class="negarandeh-guide-compare-icon">&#9889;</span>
						<strong><?php esc_html_e( 'کیفیت', 'negarandeh' ); ?></strong>
						<p><?php esc_html_e( 'مدل‌های قوی‌تر متن طولانی‌تر، ساختار بهتر و JSON دقیق‌تر می‌دهند.', 'negarandeh' ); ?></p>
					</div>
					<div class="negarandeh-guide-compare-item">
						<span class="negarandeh-guide-compare-icon">&#128176;</span>
						<strong><?php esc_html_e( 'هزینه', 'negarandeh' ); ?></strong>
						<p><?php esc_html_e( 'مدل‌های ارزان‌تر برای حجم بالا مناسب‌اند؛ مدل‌های پریمیوم برای مقالات مهم.', 'negarandeh' ); ?></p>
					</div>
					<div class="negarandeh-guide-compare-item">
						<span class="negarandeh-guide-compare-icon">&#128268;</span>
						<strong><?php esc_html_e( 'API', 'negarandeh' ); ?></strong>
						<p><?php esc_html_e( 'برخی مدل‌ها فقط chat، برخی images/generations — نام دقیق مدل را کپی کنید.', 'negarandeh' ); ?></p>
					</div>
				</div>

				<h3 class="negarandeh-guide-subtitle"><?php esc_html_e( 'کجا مدل را ببینم؟', 'negarandeh' ); ?></h3>
				<ul class="negarandeh-guide-list">
					<li>
						<strong><?php esc_html_e( 'پلتفرم AvalAI', 'negarandeh' ); ?></strong> —
						<?php esc_html_e( 'لیست زنده مدل‌ها، قیمت تقریبی و وضعیت دسترسی.', 'negarandeh' ); ?>
						<a href="<?php echo esc_url( $models_url ); ?>" target="_blank" rel="noopener noreferrer">chat.avalai.org/platform/models</a>
					</li>
					<li>
						<strong><?php esc_html_e( 'مستندات فنی', 'negarandeh' ); ?></strong> —
						<?php esc_html_e( 'جزئیات endpoint، پارامترها و محدودیت هر مدل.', 'negarandeh' ); ?>
						<a href="<?php echo esc_url( $docs_models ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( preg_replace( '#^https://#', '', $docs_models ) ); ?></a>
					</li>
				</ul>

				<div class="negarandeh-guide-actions-row">
					<a class="negarandeh-guide-btn" href="<?php echo esc_url( $models_url ); ?>" target="_blank" rel="noopener noreferrer">
						<span aria-hidden="true">&#128200;</span>
						<?php esc_html_e( 'مشاهده مدل‌ها در پلتفرم', 'negarandeh' ); ?>
					</a>
					<a class="negarandeh-guide-btn negarandeh-guide-btn--outline" href="<?php echo esc_url( $docs_models ); ?>" target="_blank" rel="noopener noreferrer">
						<span aria-hidden="true">&#128214;</span>
						<?php esc_html_e( 'جزئیات مدل در مستندات', 'negarandeh' ); ?>
					</a>
				</div>
			</div>
		</section>

		<!-- پیشنهاد مدل -->
		<section class="negarandeh-card negarandeh-guide-section negarandeh-guide-section--wide">
			<div class="negarandeh-guide-section-head">
				<span class="negarandeh-guide-section-icon negarandeh-guide-icon-pick" aria-hidden="true">&#127919;</span>
				<div>
					<h2 class="negarandeh-card-title"><?php esc_html_e( '۳. پیشنهاد برای این پلاگین', 'negarandeh' ); ?></h2>
					<p class="negarandeh-card-desc"><?php esc_html_e( 'نام مدل را دقیقاً همان‌طور که در پلتفرم می‌بینید در تنظیمات وارد کنید.', 'negarandeh' ); ?></p>
				</div>
			</div>

			<div class="negarandeh-guide-model-grid">
				<div class="negarandeh-guide-model-card">
					<div class="negarandeh-guide-model-head">
						<span aria-hidden="true">&#128221;</span>
						<h3><?php esc_html_e( 'مدل متن (Chat)', 'negarandeh' ); ?></h3>
					</div>
					<ul>
						<li><code dir="ltr">gpt-4o-mini</code> — <?php esc_html_e( 'اقتصادی، سریع، مناسب شروع', 'negarandeh' ); ?></li>
						<li><code dir="ltr">gpt-4o</code> — <?php esc_html_e( 'کیفیت بالاتر، مقالات مهم', 'negarandeh' ); ?></li>
						<li><code dir="ltr">gemini-2.5-flash</code> — <?php esc_html_e( 'جایگزین سریع Gemini', 'negarandeh' ); ?></li>
					</ul>
					<p class="negarandeh-guide-model-note"><?php esc_html_e( 'برای مقالات بلند max_tokens را ۱۲۰۰۰+ بگذارید.', 'negarandeh' ); ?></p>
				</div>
				<div class="negarandeh-guide-model-card">
					<div class="negarandeh-guide-model-head">
						<span aria-hidden="true">&#128444;</span>
						<h3><?php esc_html_e( 'مدل تصویر', 'negarandeh' ); ?></h3>
					</div>
					<ul>
						<li><code dir="ltr">gpt-image-1</code> — <?php esc_html_e( 'OpenAI Images API', 'negarandeh' ); ?></li>
						<li><code dir="ltr">dall-e-3</code> — <?php esc_html_e( 'کیفیت بالا، landscape', 'negarandeh' ); ?></li>
						<li><code dir="ltr">gemini-2.5-flash-image</code> — <?php esc_html_e( 'Gemini از مسیر chat', 'negarandeh' ); ?></li>
					</ul>
					<p class="negarandeh-guide-model-note"><?php esc_html_e( 'روش تولید تصویر = خودکار (پلاگین مسیر درست را انتخاب می‌کند).', 'negarandeh' ); ?></p>
				</div>
			</div>
		</section>

		<!-- اتصال پلاگین -->
		<section class="negarandeh-card negarandeh-guide-section negarandeh-guide-section--wide">
			<div class="negarandeh-guide-section-head">
				<span class="negarandeh-guide-section-icon negarandeh-guide-icon-plugin" aria-hidden="true">&#9881;</span>
				<div>
					<h2 class="negarandeh-card-title"><?php esc_html_e( '۴. اتصال به پلاگین', 'negarandeh' ); ?></h2>
					<p class="negarandeh-card-desc"><?php esc_html_e( 'سه فیلد اصلی — بقیه اختیاری.', 'negarandeh' ); ?></p>
				</div>
			</div>

			<ol class="negarandeh-guide-steps negarandeh-guide-steps--horizontal">
				<li>
					<span class="negarandeh-guide-step-num">1</span>
					<strong><?php esc_html_e( 'آدرس API', 'negarandeh' ); ?></strong>
					<code dir="ltr"><?php echo esc_html( $api_base ); ?></code>
				</li>
				<li>
					<span class="negarandeh-guide-step-num">2</span>
					<strong><?php esc_html_e( 'کلید API', 'negarandeh' ); ?></strong>
					<?php esc_html_e( 'از پنل AvalAI → API Keys', 'negarandeh' ); ?>
				</li>
				<li>
					<span class="negarandeh-guide-step-num">3</span>
					<strong><?php esc_html_e( 'نام مدل‌ها', 'negarandeh' ); ?></strong>
					<?php esc_html_e( 'از صفحه مدل‌ها کپی کنید', 'negarandeh' ); ?>
				</li>
				<li>
					<span class="negarandeh-guide-step-num">4</span>
					<strong><?php esc_html_e( 'تست', 'negarandeh' ); ?></strong>
					<?php esc_html_e( '«تست اتصال» و «تست تصویر»', 'negarandeh' ); ?>
				</li>
			</ol>

			<div class="negarandeh-guide-actions-row">
				<a class="negarandeh-guide-btn" href="<?php echo esc_url( $settings_url ); ?>">
					<span aria-hidden="true">&#128273;</span>
					<?php esc_html_e( 'رفتن به تنظیمات API', 'negarandeh' ); ?>
				</a>
				<a class="negarandeh-guide-btn negarandeh-guide-btn--outline" href="<?php echo esc_url( $gen_url ); ?>">
					<span aria-hidden="true">&#9998;</span>
					<?php esc_html_e( 'شروع تولید محتوا', 'negarandeh' ); ?>
				</a>
				<a class="negarandeh-guide-btn negarandeh-guide-btn--outline" href="<?php echo esc_url( $docs_main ); ?>" target="_blank" rel="noopener noreferrer">
					<span aria-hidden="true">&#128218;</span>
					<?php esc_html_e( 'مستندات AvalAI', 'negarandeh' ); ?>
				</a>
			</div>
		</section>
	</div>
</div>
