<?php
/**
 * Generation log page.
 *
 * @package Negarandeh
 * @var array<int,array<string,mixed>> $permanent_log
 * @var int                            $log_count
 */

defined( 'ABSPATH' ) || exit;
?>
<div <?php echo NEGARANDEH_I18n::wrap_attrs( 'negarandeh-log-page' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<h1><?php esc_html_e( 'لاگ تولید', 'negarandeh' ); ?></h1>
	<p class="description">
		<?php
		printf(
			/* translators: %d: number of log entries shown */
			esc_html__( 'آخرین رویدادهای تولید محتوا (حداکثر %d رکورد).', 'negarandeh' ),
			(int) NEGARANDEH_Batch_Processor::PERMANENT_LOG_MAX
		);
		?>
	</p>

	<div class="negarandeh-log-toolbar">
		<span class="negarandeh-log-count">
			<?php
			printf(
				/* translators: %d: log entry count */
				esc_html__( '%d رکورد', 'negarandeh' ),
				(int) $log_count
			);
			?>
		</span>
		<button type="button" class="button button-secondary" id="negarandeh-clear-log" <?php disabled( $log_count === 0 ); ?>>
			<?php esc_html_e( 'پاکسازی لاگ', 'negarandeh' ); ?>
		</button>
		<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . NEGARANDEH_Plugin::SLUG ) ); ?>">
			<?php esc_html_e( 'بازگشت به تولید', 'negarandeh' ); ?>
		</a>
	</div>

	<div class="negarandeh-panel negarandeh-log-panel-full">
		<ul id="negarandeh-log-list" class="negarandeh-log-list negarandeh-permanent-log negarandeh-log-list-full">
			<?php echo NEGARANDEH_Batch_Processor::render_log_list_html( $permanent_log ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</ul>
	</div>
</div>
