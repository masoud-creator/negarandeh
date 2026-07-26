(function ($) {
	'use strict';

	var pollTimer = null;

	function t(key, fallback) {
		if (typeof negarandehAdmin !== 'undefined' && negarandehAdmin.i18n && negarandehAdmin.i18n[key]) {
			return negarandehAdmin.i18n[key];
		}
		return fallback || '';
	}

	function tFormat(key, fallback) {
		var str = t(key, fallback);
		var args = Array.prototype.slice.call(arguments, 2);
		args.forEach(function (val, i) {
			str = str.replace('%' + (i + 1) + '$s', val).replace('%' + (i + 1) + '$d', val);
		});
		if (args.length) {
			str = str.replace('%s', args[0]).replace('%d', args[0]);
		}
		return str;
	}

	function ajaxUrl() {
		if (typeof negarandehAdmin !== 'undefined' && negarandehAdmin.ajaxUrl) {
			return negarandehAdmin.ajaxUrl;
		}
		if (typeof ajaxurl !== 'undefined') {
			return ajaxurl;
		}
		return '';
	}

	function ajaxNonce() {
		return typeof negarandehAdmin !== 'undefined' ? negarandehAdmin.nonce : '';
	}

	function ajaxUrlUnavailableMessage() {
		return t('ajaxUrlUnavailable', 'آدرس Ajax در دسترس نیست. صفحه را رفرش کنید.');
	}

	function requireAjaxUrl(silent) {
		var url = ajaxUrl();
		if (!url && !silent) {
			alert(ajaxUrlUnavailableMessage());
		}
		return url || null;
	}

	function showTestResultIn($el, type, message) {
		if (!$el.length) {
			alert(message);
			return;
		}
		$el.removeClass('negarandeh-test-ok negarandeh-test-fail negarandeh-test-pending')
			.addClass(type === 'ok' ? 'negarandeh-test-ok' : (type === 'fail' ? 'negarandeh-test-fail' : 'negarandeh-test-pending'))
			.html($('<div/>').text(message).html())
			.show();
	}

	function setAutomationButtonState(enabled) {
		var $btn = $('#negarandeh-toggle-automation');
		if (!$btn.length) {
			return;
		}
		$btn.attr('data-enabled', enabled ? '1' : '0')
			.attr('aria-pressed', enabled ? 'true' : 'false')
			.toggleClass('is-on', !!enabled)
			.toggleClass('is-off', !enabled);
		$btn.find('.negarandeh-hero-toggle-icon').text(enabled ? '⏹' : '▶');
		$btn.find('.negarandeh-hero-toggle-label').text(enabled ? t('stop', 'توقف (Stop)') : t('start', 'استارت (Start)'));

		var $badge = $('#negarandeh-automation-status-badge');
		if ($badge.length) {
			$badge.toggleClass('is-on', !!enabled).toggleClass('is-off', !enabled);
			$badge.find('.negarandeh-automation-status-text').text(enabled ? t('on', 'روشن') : t('off', 'خاموش'));
		}

		var $powerLine = $('#negarandeh-power-state-line');
		if ($powerLine.length) {
			$powerLine.toggleClass('is-on', !!enabled).toggleClass('is-off', !enabled);
			$powerLine.find('strong').text(enabled ? t('powerOn', 'روشن — تولید مجاز است') : t('powerOff', 'خاموش — تولید متوقف است'));
		}

		var $powerHint = $('#negarandeh-power-state-hint');
		if ($powerHint.length) {
			$powerHint.text(enabled ? t('powerHintOn', 'صف دستی و Cron (در صورت فعال بودن) می\u200cتوانند اجرا شوند.') : t('powerHintOff', 'برای شروع، دکمه استارت بالای صفحه را بزنید.'));
		}

		if (typeof negarandehAdmin !== 'undefined') {
			negarandehAdmin.automationEnabled = enabled ? 1 : 0;
		}

		var $startBtn = $('#negarandeh-start-btn');
		if ($startBtn.length) {
			$startBtn.toggleClass('is-disabled-by-stop', !enabled);
		}
	}

	function getCronIntervalMinutes(data) {
		data = data || {};

		var $sel = $('#negarandeh-cron-interval');
		if ($sel.length) {
			var fromForm = parseInt($sel.val(), 10);
			if (fromForm >= 1 && fromForm <= 5) {
				return fromForm;
			}
		}

		var $panel = $('#negarandeh-cron-status-content');
		if ($panel.length) {
			var fromAttr = parseInt($panel.attr('data-interval-minutes'), 10);
			if (fromAttr >= 1 && fromAttr <= 5) {
				return fromAttr;
			}
		}

		var automationOn = typeof data.automation_enabled !== 'undefined'
			? !!data.automation_enabled
			: isAutomationEnabled();
		var nextRun = data.next_auto_cron || 0;

		if (automationOn && nextRun && typeof data.cron_active_interval_minutes !== 'undefined') {
			var active = parseInt(data.cron_active_interval_minutes, 10);
			if (active >= 1 && active <= 5) {
				return active;
			}
		}

		if (typeof data.cron_interval_minutes !== 'undefined') {
			var saved = parseInt(data.cron_interval_minutes, 10);
			if (saved >= 1 && saved <= 5) {
				return saved;
			}
		}

		if (typeof negarandehAdmin !== 'undefined' && negarandehAdmin.cronIntervalMinutes) {
			var bootSaved = parseInt(negarandehAdmin.cronIntervalMinutes, 10);
			if (bootSaved >= 1 && bootSaved <= 5) {
				return bootSaved;
			}
		}

		return 5;
	}

	function cronIntervalLabel(minutes) {
		minutes = parseInt(minutes, 10) || 5;
		if (minutes < 1) {
			minutes = 1;
		}
		if (minutes > 5) {
			minutes = 5;
		}
		var tpl = t('cronEveryMin', 'هر %d دقیقه');
		return tpl.replace('%d', String(minutes));
	}

	function formatCronMessage(template, intervalText) {
		return (template || '').replace('%s', intervalText);
	}

	function updateCronPanel(data) {
		var $wrap = $('#negarandeh-cron-status-content');
		if (!$wrap.length) {
			return;
		}

		var cronEnabled = getQueueDriver() === 'wp_cron';
		var automationOn = typeof data.automation_enabled !== 'undefined'
			? !!data.automation_enabled
			: isAutomationEnabled();
		var nextRun = data.next_auto_cron || 0;
		var intervalMin = getCronIntervalMinutes(data || {});
		var intervalText = cronIntervalLabel(intervalMin);

		if (!cronEnabled) {
			$wrap.html('<p class="description">' + escapeHtml(t('cronDisabled', 'Cron غیرفعال')) + '</p>');
			return;
		}

		if (automationOn && nextRun) {
			var dateStr = '';
			try {
				dateStr = new Date(nextRun * 1000).toLocaleString();
			} catch (e) {
				dateStr = String(nextRun);
			}
			$wrap.html(
				'<p class="negarandeh-cron-active">' + escapeHtml(formatCronMessage(t('cronRunning', 'در حال اجرا — %s'), intervalText)) + '</p>' +
				'<p class="description"><strong>' + escapeHtml(t('cronNextRun', 'اجرای بعدی:')) + '</strong> ' + escapeHtml(dateStr) + '</p>'
			);
			return;
		}

		if (automationOn) {
			$wrap.html('<p class="negarandeh-cron-active">' + escapeHtml(formatCronMessage(t('cronActive', 'فعال — %s — در انتظار WP-Cron'), intervalText)) + '</p>');
			return;
		}

		$wrap.html(
			'<p class="negarandeh-cron-waiting">' + escapeHtml(t('cronWaiting', 'Cron تنظیم شده — منتظر استارت')) + '</p>' +
			'<p class="description">' + escapeHtml(formatCronMessage(t('cronNeedStart', 'تولید خودکار (%s) فعال است؛ تا استارت نزنید اجرا نمی\u200cشود.'), intervalText)) + '</p>'
		);
	}

	function isAutomationEnabled() {
		return typeof negarandehAdmin !== 'undefined' && (negarandehAdmin.automationEnabled === 1 || negarandehAdmin.automationEnabled === '1');
	}

	function tryFormatJson(str) {
		if (!str) {
			return '';
		}
		try {
			return JSON.stringify(JSON.parse(str), null, 2);
		} catch (e) {
			return str;
		}
	}

	function showImageTestError($el, response) {
		if (!$el.length) {
			alert(formatAjaxError(response, null));
			return;
		}

		var data = response && response.data ? response.data : {};
		var $wrap = $('<div class="negarandeh-test-error-detail"/>');

		if (data.message) {
			$wrap.append($('<p class="negarandeh-test-error-message"/>').text(data.message));
		}
		if (data.http_code) {
			$wrap.append($('<p class="negarandeh-test-error-meta"/>').text('HTTP ' + data.http_code));
		}
		if (data.url) {
			$wrap.append($('<p class="negarandeh-test-error-meta"/>').text('URL: ' + data.url));
		}
		if (data.request_body) {
			$wrap.append(
				$('<details class="negarandeh-debug-details" open/>')
					.append($('<summary/>').text('Request'))
					.append($('<pre class="negarandeh-debug-block"/>').text(tryFormatJson(data.request_body)))
			);
		}
		if (data.response_body) {
			$wrap.append(
				$('<details class="negarandeh-debug-details" open/>')
					.append($('<summary/>').text('Response'))
					.append($('<pre class="negarandeh-debug-block"/>').text(tryFormatJson(data.response_body)))
			);
		} else if (data.details) {
			$wrap.append(
				$('<details class="negarandeh-debug-details" open/>')
					.append($('<summary/>').text('Response'))
					.append($('<pre class="negarandeh-debug-block"/>').text(data.details))
			);
		}

		if (!$wrap.children().length) {
			$wrap.append($('<p/>').text(t('testFail', 'خطا')));
		}

		$el.removeClass('negarandeh-test-ok negarandeh-test-fail negarandeh-test-pending')
			.addClass('negarandeh-test-fail')
			.empty()
			.append($wrap)
			.show();
	}

	function formatAjaxError(response, xhr) {
		var parts = [];

		if (response && response.data) {
			if (response.data.message) {
				parts.push(response.data.message);
			}
			if (response.data.http_code) {
				parts.push('HTTP ' + response.data.http_code);
			}
			if (response.data.url) {
				parts.push('URL: ' + response.data.url);
			}
			if (response.data.request_body) {
				parts.push('Request: ' + response.data.request_body);
			}
			if (response.data.response_body) {
				parts.push('Response: ' + response.data.response_body);
			}
			if (response.data.details && (!response.data.response_body || response.data.details.indexOf('Response:') !== 0)) {
				parts.push(response.data.details);
			}
		}

		if (!parts.length && xhr && xhr.responseText) {
			try {
				var parsed = JSON.parse(xhr.responseText);
				if (parsed.data) {
					return formatAjaxError(parsed, null);
				}
			} catch (e) {
				parts.push(xhr.responseText.substring(0, 500));
			}
		}

		return parts.length ? parts.join('\n\n') : t('testFail', 'خطا');
	}

	function showTestResult(type, message) {
		showTestResultIn($('#negarandeh-test-result'), type, message);
	}

	function insertAtCursor(textarea, text) {
		var start = textarea.selectionStart;
		var end = textarea.selectionEnd;
		var value = textarea.value;
		textarea.value = value.substring(0, start) + text + value.substring(end);
		textarea.selectionStart = textarea.selectionEnd = start + text.length;
		textarea.focus();
	}

	function useAjaxQueueDriver() {
		return getQueueDriver() !== 'wp_cron';
	}

	function getQueueDriver() {
		var $checked = $('#negarandeh-generator-form input[name="negarandeh_generator_settings[queue_driver]"]:checked');
		if ($checked.length) {
			return $checked.val();
		}
		if (typeof negarandehAdmin !== 'undefined' && negarandehAdmin.queueDriver) {
			return negarandehAdmin.queueDriver;
		}
		return 'wp_cron';
	}

	function toggleQueueDriverPanels() {
		var driver = getQueueDriver();
		var isWpCron = driver === 'wp_cron';

		$('#negarandeh-queue-driver-wp-cron-panel').prop('hidden', !isWpCron);
		$('#negarandeh-queue-driver-ajax-panel').prop('hidden', isWpCron);

		if (typeof negarandehAdmin !== 'undefined') {
			negarandehAdmin.queueDriver = driver;
		}
	}

	function getSavedGeneratorSettings() {
		if (typeof negarandehAdmin !== 'undefined' && negarandehAdmin.savedGeneratorSettings) {
			return negarandehAdmin.savedGeneratorSettings;
		}
		return {};
	}

	function normalizeGeneratorSettingValue(val) {
		return String(val == null ? '' : val).replace(/\r\n/g, '\n');
	}

	function hasUnsavedGeneratorSettings() {
		var saved = getSavedGeneratorSettings();
		var current = collectGeneratorSettings();
		var keys = Object.keys(saved);

		for (var i = 0; i < keys.length; i++) {
			var key = keys[i];
			var savedVal = normalizeGeneratorSettingValue(saved[key]);
			var currentVal = normalizeGeneratorSettingValue(current[key]);
			if (savedVal !== currentVal) {
				return true;
			}
		}

		return false;
	}

	function resumeRunningQueue() {
		if (useAjaxQueueDriver()) {
			driveQueue(false);
		} else {
			startPolling();
		}
	}

	function escapeHtml(text) {
		return $('<div/>').text(text || '').html();
	}

	function setStartBtnLabel($btn, label, icon) {
		if (!$btn || !$btn.length) {
			return;
		}

		icon = icon || 'controls-play';
		var spinClass = 'update' === icon ? ' negarandeh-start-btn__icon--spin' : '';

		$btn.html(
			'<span class="negarandeh-start-btn__inner">' +
			'<span class="dashicons dashicons-' + escapeHtml(icon) + ' negarandeh-start-btn__icon' + spinClass + '" aria-hidden="true"></span>' +
			'<span class="negarandeh-start-btn__label">' + escapeHtml(label) + '</span>' +
			'</span>'
		);
	}

	function statusLabel(status) {
		var map = {
			success: t('statusSuccess', 'موفق'),
			error: t('statusError', 'خطا'),
			skipped: t('statusSkipped', 'رد شد'),
			warning: t('statusWarning', 'هشدار'),
			pending: t('statusPending', 'در انتظار'),
			info: t('statusInfo', 'در حال انجام')
		};
		return map[status] || status || '';
	}

	function renderTopicBoard(board, stats) {
		var $board = $('#negarandeh-topic-board');
		var $stats = $('#negarandeh-topic-stats');
		if (!$board.length) {
			return;
		}

		$board.empty();
		if (!board || !board.length) {
			$board.append('<li class="description">' + escapeHtml(t('boardEmpty', 'لیست خالی است.')) + '</li>');
			return;
		}

		board.forEach(function (row) {
			var status = row.status || 'pending';
			var label = row.topic || '';
			var html = '<li class="negarandeh-topic-row negarandeh-status-' + status + '">';
			html += '<strong>' + escapeHtml(label) + '</strong> ';
			html += '<span class="negarandeh-topic-badge">' + escapeHtml(statusLabel(status)) + '</span>';
			if (row.message) {
				html += '<small>' + escapeHtml(row.message) + '</small>';
			}
			html += '</li>';
			$board.append(html);
		});

		if ($stats.length && stats) {
			var ok = (stats.success || 0) + (stats.warning || 0);
			$stats.html(
				'<span class="negarandeh-stat-pill negarandeh-stat-pill--ok">' + ok + ' ' + escapeHtml(t('statOk', 'موفق')) + '</span>' +
				'<span class="negarandeh-stat-pill negarandeh-stat-pill--err">' + (stats.error || 0) + ' ' + escapeHtml(t('statErr', 'خطا')) + '</span>' +
				'<span class="negarandeh-stat-pill negarandeh-stat-pill--wait">' + (stats.pending || 0) + ' ' + escapeHtml(t('statWait', 'انتظار')) + '</span>'
			);
		}
	}

	function renderLog(log) {
		var $list = $('#negarandeh-log-list');
		$list.empty();

		if (!log || !log.length) {
			$list.append('<li class="description">' + escapeHtml(t('logEmpty', 'لاگی ثبت نشده است.')) + '</li>');
			$('#negarandeh-clear-log').prop('disabled', true);
			return;
		}

		$('#negarandeh-clear-log').prop('disabled', false);

		log.forEach(function (item) {
			var status = item.status || 'info';
			var cls = 'negarandeh-log-' + status;
			if ((status === 'success' || status === 'warning') && item.image_warning) {
				cls = 'negarandeh-log-warn';
			}
			var html = '<li class="' + cls + '">';
			if (item.time) {
				html += '<time>' + escapeHtml(item.time) + '</time> ';
			}
			if (item.source === 'auto_cron' || item.source === 'hourly_cron') {
				html += '<em>[WP-Cron]</em> ';
			} else if (item.source === 'manual_queue') {
				html += '<em>[' + escapeHtml(t('queueTag', 'صف')) + ']</em> ';
			}
			if (item.topic) {
				html += '<strong>' + escapeHtml(item.topic) + '</strong> ';
			}
			html += '<span class="negarandeh-log-status">' + escapeHtml(statusLabel(status)) + '</span> ';
			if (status === 'success' || status === 'warning') {
				html += escapeHtml(item.title || '') + ' ';
				if (item.edit_url) {
					html += '<a href="' + escapeHtml(item.edit_url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(t('editPost', 'ویرایش')) + '</a>';
				}
				if (item.image_warning) {
					html += '<br><em class="negarandeh-log-warn-text">' + escapeHtml(t('imageLabel', 'تصویر:')) + '<br>' + escapeHtml(item.image_warning) + '</em>';
				} else if (status === 'warning' && item.message) {
					html += '<br><em class="negarandeh-log-warn-text">' + escapeHtml(item.message) + '</em>';
				}
			} else if (status === 'info' && item.message) {
				html += '<span class="negarandeh-log-info-text">' + escapeHtml(item.message) + '</span>';
			} else if (item.message) {
				html += '<span class="negarandeh-log-error-text">' + escapeHtml(item.message) + '</span>';
			}
			var usageLabel = formatUsage(item.usage);
			if (usageLabel) {
				html += ' <span class="negarandeh-log-usage">' + escapeHtml(usageLabel) + '</span>';
			}
			html += '</li>';
			$list.append(html);
		});
	}

	function formatNum(n) {
		n = parseInt(n, 10) || 0;
		return n.toLocaleString();
	}

	function formatUsage(usage) {
		if (!usage || typeof usage !== 'object') {
			return '';
		}
		var parts = [];
		var articleTotal = parseInt(usage.article_total, 10) || 0;
		if (articleTotal > 0) {
			parts.push(tFormat('usageArticle', 'مقاله: %1$s ورودی + %2$s خروجی = %3$s توکن', formatNum(usage.article_prompt), formatNum(usage.article_completion), formatNum(articleTotal)));
		}
		var imageTotal = parseInt(usage.image_total, 10) || 0;
		if (imageTotal > 0) {
			parts.push(tFormat('usageImage', 'تصویر: %s توکن', formatNum(imageTotal)));
		}
		if (usage.estimated_cost && parseFloat(usage.estimated_cost) > 0) {
			parts.push(tFormat('usageCost', 'هزینه تقریبی: %s', parseFloat(usage.estimated_cost).toFixed(4)));
		}
		return parts.join(' — ');
	}

	function updateStatus(data) {
		var queue = data.queue || {};
		var current = queue.current || 0;
		var total = queue.total || 0;
		var status = queue.status || 'idle';
		var displayCurrent = current;
		if (status === 'running' && total > 0 && current < total) {
			displayCurrent = current + 1;
		}
		var pct = total > 0 ? Math.round((displayCurrent / total) * 100) : 0;

		var statusText = status;
		if (status === 'running') {
			statusText = t('generating', 'در حال تولید...') + ' (' + displayCurrent + '/' + total + ')';
			if (queue.phase_label) {
				statusText += ' — ' + queue.phase_label;
			}
		} else if (status === 'completed') {
			statusText = t('completed', 'تولید کامل شد!');
			$('#negarandeh-start-btn').prop('disabled', false);
			setStartBtnLabel($('#negarandeh-start-btn'), t('startGenerate', 'شروع تولید'));
		}

		$('#negarandeh-status-content').html(
			'<p><strong>' + escapeHtml(t('statusLabel', 'وضعیت:')) + '</strong> ' + statusText + '</p>' +
			(total ? '<p>' + (status === 'running' ? displayCurrent : current) + ' / ' + total + '</p>' : '')
		);

		if (total > 0 && status === 'running') {
			$('.negarandeh-progress-wrap').show();
			$('.negarandeh-progress-fill').css('width', pct + '%');
			$('.negarandeh-progress-text').text(pct + '%');
		} else if (status === 'completed') {
			$('.negarandeh-progress-wrap').show();
			$('.negarandeh-progress-fill').css('width', '100%');
			$('.negarandeh-progress-text').text('100%');
			stopPolling();
		}

		renderLog(data.log);
		renderTopicBoard(data.topic_board, data.topic_stats);

		if (typeof data.automation_enabled !== 'undefined') {
			setAutomationButtonState(!!data.automation_enabled);
		}

		updateCronPanel(data);
	}

	function pollStatus() {
		var url = requireAjaxUrl(true);
		if (!url) {
			return;
		}
		$.post(url, {
			action: 'negarandeh_get_queue_status',
			nonce: ajaxNonce()
		}).done(function (response) {
			if (response.success) {
				updateStatus(response.data);
			}
		});
	}

	function processQueueStep() {
		var url = requireAjaxUrl(true);
		if (!url) {
			return $.Deferred().reject().promise();
		}
		return $.ajax({
			url: url,
			type: 'POST',
			dataType: 'json',
			timeout: 600000,
			data: {
				action: 'negarandeh_process_queue_step',
				nonce: ajaxNonce()
			}
		});
	}

	function driveQueue(lockRetried) {
		if (!requireAjaxUrl(true)) {
			alert(ajaxUrlUnavailableMessage());
			$('#negarandeh-start-btn').prop('disabled', false);
			setStartBtnLabel($('#negarandeh-start-btn'), t('startGeneratePosts', 'شروع تولید پست\u200cها'));
			stopPolling();
			return;
		}
		processQueueStep().done(function (response) {
			if (!response || !response.success) {
				alert(t('error', 'خطا'));
				$('#negarandeh-start-btn').prop('disabled', false);
			setStartBtnLabel($('#negarandeh-start-btn'), t('startGeneratePosts', 'شروع تولید پست\u200cها'));
				stopPolling();
				return;
			}

			updateStatus(response.data);

			var queue = response.data.queue || {};
			if (queue.status === 'running') {
				if (response.data.step_ran === false) {
					if (!lockRetried) {
						setTimeout(function () {
							driveQueue(true);
						}, 2000);
					} else {
						alert(t('queueLocked', 'صف قفل شده است. «پاک کردن صف» را بزنید و دوباره تلاش کنید.'));
						$('#negarandeh-start-btn').prop('disabled', false);
			setStartBtnLabel($('#negarandeh-start-btn'), t('startGeneratePosts', 'شروع تولید پست\u200cها'));
						stopPolling();
					}
					return;
				}
				driveQueue(false);
			} else {
				stopPolling();
				$('#negarandeh-start-btn').prop('disabled', false);
			setStartBtnLabel($('#negarandeh-start-btn'), t('startGeneratePosts', 'شروع تولید پست\u200cها'));
			}
		}).fail(function (xhr) {
			var msg = formatAjaxError(null, xhr);
			alert(msg || t('error', 'خطا'));
			$('#negarandeh-start-btn').prop('disabled', false);
			setStartBtnLabel($('#negarandeh-start-btn'), t('startGeneratePosts', 'شروع تولید پست\u200cها'));
			stopPolling();
		});
	}

	function startPolling() {
		stopPolling();
		pollTimer = setInterval(pollStatus, 5000);
		pollStatus();
	}

	function stopPolling() {
		if (pollTimer) {
			clearInterval(pollTimer);
			pollTimer = null;
		}
	}

	function collectGeneratorSettings() {
		var $form = $('#negarandeh-generator-form');
		var data = {};
		$form.find('[name^="negarandeh_generator_settings"]').each(function () {
			var $el = $(this);
			var name = $el.attr('name').replace('negarandeh_generator_settings[', '').replace(']', '');
			if ($el.attr('type') === 'checkbox') {
				data[name] = $el.is(':checked') ? '1' : '';
			} else if ($el.attr('type') === 'radio') {
				if ($el.is(':checked')) {
					data[name] = $el.val();
				}
			} else {
				data[name] = $el.val();
			}
		});
		return data;
	}

	function runApiTest() {
		var $btn = $('#negarandeh-test-api');
		var apiKey = $('#negarandeh_api_key').val() || '';
		var baseUrl = $('#negarandeh_api_base_url').val() || '';
		var chatModel = $('#negarandeh_chat_model').val() || '';
		var hasSavedKey = $('#negarandeh-test-api').data('has-saved-key') === 1 || $('#negarandeh-test-api').data('has-saved-key') === '1';
		var hasBaseUrl = $('#negarandeh-test-api').data('has-base-url') === 1 || $('#negarandeh-test-api').data('has-base-url') === '1';

		if (!baseUrl.trim() && !hasBaseUrl) {
			showTestResult('fail', t('noBaseUrl', 'آدرس API وارد یا ذخیره نشده است.'));
			return;
		}

		if (!apiKey.trim() && !hasSavedKey) {
			showTestResult('fail', t('noApiKey', 'کلید API وارد یا ذخیره نشده است.'));
			return;
		}

		$btn.prop('disabled', true);
		showTestResult('pending', t('testing', 'در حال تست اتصال...'));

		var url = requireAjaxUrl(true);
		if (!url) {
			showTestResult('fail', ajaxUrlUnavailableMessage());
			$btn.prop('disabled', false);
			return;
		}

		$.ajax({
			url: url,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'negarandeh_test_api',
				nonce: ajaxNonce(),
				api_key: apiKey,
				api_base_url: baseUrl,
				chat_model: chatModel
			}
		}).done(function (response) {
			if (response && response.success) {
				var msg = (response.data && response.data.message) ? response.data.message : t('testOk', 'اتصال موفق!');
				showTestResult('ok', msg);
			} else {
				showTestResult('fail', formatAjaxError(response, null));
			}
		}).fail(function (xhr) {
			showTestResult('fail', formatAjaxError(null, xhr));
		}).always(function () {
			$btn.prop('disabled', false);
		});
	}

	function loadCredit() {
		var $bar = $('#negarandeh-credit-bar');
		if (!$bar.length) {
			return;
		}
		var $value = $('#negarandeh-credit-value');
		var $sub = $('#negarandeh-credit-sub');
		$bar.removeClass('is-error is-ready').addClass('is-loading');
		$value.text(t('creditLoading', 'در حال دریافت اعتبار...'));
		$sub.text('');

		var url = requireAjaxUrl(true);
		if (!url) {
			$bar.removeClass('is-loading').addClass('is-error');
			$value.text(ajaxUrlUnavailableMessage());
			return;
		}

		$.post(url, {
			action: 'negarandeh_get_credit',
			nonce: ajaxNonce()
		}).done(function (response) {
			$bar.removeClass('is-loading');
			if (response && response.success && response.data) {
				var d = response.data;
				$bar.addClass('is-ready');
				var main = d.remaining_irt_human || '0';
				$value.text(main);

				var subParts = [];
				if (d.account_tier !== null && typeof d.account_tier !== 'undefined') {
					subParts.push(t('creditTier', 'سطح حساب') + ' ' + d.account_tier);
				}
				if (d.packages && d.packages.length) {
					subParts.push(tFormat('activePackages', '%d بسته فعال', d.packages.length));
				}
				$sub.text(subParts.join(' • '));
			} else {
				$bar.addClass('is-error');
				$value.text((response && response.data && response.data.message) ? response.data.message : t('creditError', 'دریافت اعتبار ناموفق بود.'));
			}
		}).fail(function () {
			$bar.removeClass('is-loading').addClass('is-error');
			$value.text(t('creditError', 'دریافت اعتبار ناموفق بود.'));
		});
	}

	$(function () {
		setAutomationButtonState(isAutomationEnabled());
		toggleQueueDriverPanels();
		loadCredit();

		$(document).on('click', '#negarandeh-refresh-credit', function () {
			loadCredit();
		});

		$(document).on('change', '#negarandeh-generator-form input[name="negarandeh_generator_settings[queue_driver]"], #negarandeh-cron-interval', function () {
			toggleQueueDriverPanels();
			updateCronPanel({ automation_enabled: isAutomationEnabled() ? 1 : 0, next_auto_cron: 0 });
		});

		$(document).on('click', '#negarandeh-toggle-automation', function (e) {
			e.preventDefault();
			var $btn = $(this);
			var enabled = $btn.attr('data-enabled') === '1';
			$btn.prop('disabled', true);

			var url = requireAjaxUrl();
			if (!url) {
				$btn.prop('disabled', false);
				return;
			}

			$.post(url, {
				action: 'negarandeh_toggle_automation',
				nonce: ajaxNonce(),
				enabled: enabled ? '' : '1'
			}).done(function (response) {
				if (response && response.success && response.data) {
					setAutomationButtonState(!!response.data.enabled);
					updateCronPanel(response.data);
				} else {
					alert(t('error', 'خطا'));
				}
			}).fail(function () {
				alert(t('error', 'خطا'));
			}).always(function () {
				$btn.prop('disabled', false);
			});
		});

		$(document).on('click', '#negarandeh-prompt-builder-toggle', function () {
			var $btn = $(this);
			var $panel = $('#negarandeh-prompt-builder-panel');
			var open = $panel.prop('hidden');

			$panel.prop('hidden', !open);
			$btn.attr('aria-expanded', open ? 'true' : 'false');
		});

		function collectPromptBuilderData() {
			return {
				word_count: $('#negarandeh-builder-word-count').val() || '',
				language: $('#negarandeh-builder-language').val() || '',
				tone: $('#negarandeh-builder-tone').val() || '',
				audience: $('#negarandeh-builder-audience').val() || '',
				notes: $('#negarandeh-builder-notes').val() || '',
				include_faq: $('#negarandeh-builder-include-faq').is(':checked') ? '1' : '',
				include_toc: $('#negarandeh-builder-include-toc').is(':checked') ? '1' : '',
				include_intro: $('#negarandeh-builder-include-intro').is(':checked') ? '1' : '',
				include_conclusion: $('#negarandeh-builder-include-conclusion').is(':checked') ? '1' : '',
				seo_focus: $('#negarandeh-builder-seo-focus').is(':checked') ? '1' : ''
			};
		}

		$(document).on('click', '#negarandeh-generate-prompt-btn', function () {
			var $btn = $(this);
			var $apply = $('#negarandeh-apply-prompt-btn');
			var $status = $('#negarandeh-prompt-builder-status');
			var $preview = $('#negarandeh-prompt-builder-preview');
			var $previewLabel = $('#negarandeh-prompt-builder-preview-label');

			var url = requireAjaxUrl();
			if (!url) {
				return;
			}

			$btn.prop('disabled', true);
			$apply.prop('disabled', true);
			$preview.val('').prop('hidden', true);
			$previewLabel.prop('hidden', true);
			showTestResultIn($status, 'pending', t('generatingPrompt', 'در حال ساخت پرامپت…'));
			$status.prop('hidden', false);

			$.post(url, Object.assign({
				action: 'negarandeh_build_prompt',
				nonce: ajaxNonce()
			}, collectPromptBuilderData())).done(function (response) {
				if (response && response.success && response.data && response.data.prompt) {
					$preview.val(response.data.prompt).prop('hidden', false);
					$previewLabel.prop('hidden', false);
					$apply.prop('disabled', false);
					showTestResultIn(
						$status,
						'ok',
						response.data.message || t('promptGenerated', 'پرامپت آماده است. می\u200cتوانید آن را به قالب پرامپت منتقل کنید.')
					);
				} else {
					showTestResultIn($status, 'fail', formatAjaxError(response, null));
				}
			}).fail(function (xhr) {
				showTestResultIn($status, 'fail', formatAjaxError(null, xhr));
			}).always(function () {
				$btn.prop('disabled', false);
			});
		});

		$(document).on('click', '#negarandeh-apply-prompt-btn', function () {
			var $status = $('#negarandeh-prompt-builder-status');
			var prompt = $('#negarandeh-prompt-builder-preview').val() || '';

			if (!prompt.trim()) {
				showTestResultIn($status, 'fail', t('error', 'خطا'));
				$status.prop('hidden', false);
				return;
			}

			$('#negarandeh-prompt').val(prompt).trigger('focus');
			showTestResultIn(
				$status,
				'ok',
				t('promptApplied', 'پرامپت به قالب منتقل شد. برای استفاده «ذخیره تنظیمات» را بزنید.')
			);
			$status.prop('hidden', false);
		});

		$(document).on('change', '#negarandeh-generate-tags', function () {
			var enabled = $(this).is(':checked');
			$('#negarandeh-tag-count').prop('disabled', !enabled);
		});

		$(document).on('change', '#negarandeh-post-status', function () {
			var scheduled = $(this).val() === 'scheduled';
			$('#negarandeh-schedule-interval-row').prop('hidden', !scheduled);
			$('#negarandeh-schedule-interval').prop('disabled', !scheduled);
		});

		$(document).on('click', '.negarandeh-insert-placeholder', function () {
			var tag = $(this).data('tag');
			var $prompt = $('#negarandeh-prompt');
			var $imagePrompt = $('#negarandeh-image-prompt');
			var target = document.activeElement;

			if ($imagePrompt.length && ($imagePrompt.is(':focus') || $(target).is('#negarandeh-image-prompt'))) {
				insertAtCursor($imagePrompt[0], tag);
			} else if ($prompt.length) {
				insertAtCursor($prompt[0], tag);
			}
		});

		$(document).on('click', '#negarandeh-start-btn', function () {
			var $btn = $(this);
			var topics = $('#negarandeh-topics').val();

			if (hasUnsavedGeneratorSettings()) {
				alert(t('unsavedSettings', 'تنظیمات ذخیره نشده دارد. تنظیمات را ذخیره کنید یا صفحه را رفرش کنید که بتوانید شروع کنید.'));
				return;
			}

			if (!isAutomationEnabled()) {
				alert(t('needStart', 'ابتدا استارت را بزنید تا تولید فعال شود.'));
				return;
			}

			if (!topics || !topics.trim()) {
				alert(t('needTopic', 'لطفاً حداقل یک موضوع وارد کنید.'));
				return;
			}

			if ($btn.prop('disabled')) {
				return;
			}

			$btn.prop('disabled', true);
			setStartBtnLabel($btn, t('generating', 'در حال تولید...'), 'update');

			var url = requireAjaxUrl();
			if (!url) {
				$btn.prop('disabled', false);
				setStartBtnLabel($btn, t('startGenerate', 'شروع تولید'));
				return;
			}

			$.post(url, {
				action: 'negarandeh_start_generation',
				nonce: ajaxNonce(),
				topics: topics
			}).done(function (response) {
				if (response.success) {
					resumeRunningQueue();
				} else {
					alert(response.data && response.data.message ? response.data.message : t('error', 'خطا'));
					$btn.prop('disabled', false);
				setStartBtnLabel($btn, t('startGenerate', 'شروع تولید'));
				}
			}).fail(function () {
				alert(t('error', 'خطا'));
				$btn.prop('disabled', false);
				setStartBtnLabel($btn, t('startGenerate', 'شروع تولید'));
			});
		});

		$(document).on('click', '#negarandeh-reset-failed-topics', function () {
			var $btn = $(this);
			$btn.prop('disabled', true);
			var url = requireAjaxUrl();
			if (!url) {
				$btn.prop('disabled', false);
				return;
			}
			$.post(url, {
				action: 'negarandeh_reset_failed_topics',
				nonce: ajaxNonce()
			}).done(function (response) {
				if (response && response.success && response.data) {
					updateStatus(response.data);
					if (response.data.message) {
						alert(response.data.message);
					}
				}
			}).always(function () {
				$btn.prop('disabled', false);
			});
		});

		$(document).on('click', '#negarandeh-reset-generated-topics', function () {
			if (!confirm(t(
				'confirmResetGenerated',
				'موضوعات ساخته‌شده دوباره قابل تولید می‌شوند. پست‌های قبلی حذف نمی‌شوند. اگر تولید روشن باشد Stop می‌شود. ادامه؟'
			))) {
				return;
			}

			var $btn = $(this);
			$btn.prop('disabled', true);
			var url = requireAjaxUrl();
			if (!url) {
				$btn.prop('disabled', false);
				return;
			}

			$.post(url, {
				action: 'negarandeh_reset_generated_topics',
				nonce: ajaxNonce()
			}).done(function (response) {
				if (response && response.success && response.data) {
					if (response.data.stopped || response.data.automation_enabled === 0) {
						setAutomationButtonState(false);
					}
					stopPolling();
					updateStatus(response.data);
					updateCronPanel(response.data);
					$('#negarandeh-start-btn').prop('disabled', false);
			setStartBtnLabel($('#negarandeh-start-btn'), t('startGenerate', 'شروع تولید'));
					$('#negarandeh-status-content').html('<p class="description">' + escapeHtml(t('queueEmpty', 'صف خالی است.')) + '</p>');
					$('.negarandeh-progress-wrap').hide();
					if (response.data.message) {
						alert(response.data.message);
					}
				} else {
					alert(t('error', 'خطا'));
				}
			}).fail(function () {
				alert(t('error', 'خطا'));
			}).always(function () {
				$btn.prop('disabled', false);
			});
		});

		$(document).on('click', '#negarandeh-clear-queue', function () {
			if (!confirm(t('confirmClear', 'صف تولید پاک شود؟'))) {
				return;
			}
			var url = requireAjaxUrl();
			if (!url) {
				return;
			}
			$.post(url, {
				action: 'negarandeh_clear_queue',
				nonce: ajaxNonce()
			}).done(function () {
				stopPolling();
				$('#negarandeh-start-btn').prop('disabled', false);
			setStartBtnLabel($('#negarandeh-start-btn'), t('startGenerate', 'شروع تولید'));
				$('#negarandeh-status-content').html('<p class="description">' + escapeHtml(t('queueEmpty', 'صف خالی است.')) + '</p>');
				$('.negarandeh-progress-wrap').hide();
			});
		});

		$(document).on('click', '#negarandeh-clear-log', function () {
			if (!confirm(t('confirmClearLog', 'همهٔ رکوردهای لاگ حذف شوند؟'))) {
				return;
			}
			var $btn = $(this);
			$btn.prop('disabled', true);
			var url = requireAjaxUrl();
			if (!url) {
				$btn.prop('disabled', false);
				return;
			}
			$.post(url, {
				action: 'negarandeh_clear_log',
				nonce: ajaxNonce()
			}).done(function (response) {
				if (response && response.success) {
					renderLog([]);
					$('.negarandeh-log-count').text(tFormat('recordsCount', '%d رکورد', 0));
				} else {
					alert(t('error', 'خطا'));
					$btn.prop('disabled', false);
				}
			}).fail(function () {
				alert(t('error', 'خطا'));
				$btn.prop('disabled', false);
			});
		});

		$(document).on('click', '#negarandeh-test-api', function (e) {
			e.preventDefault();
			runApiTest();
		});

		function i18nStr(key, fallback) {
			return t(key, fallback);
		}

		function renderModelsList($panel, models) {
			var $list = $panel.find('.negarandeh-models-list');
			$list.empty();
			if (!models || !models.length) {
				$list.append($('<p class="description"/>').text(i18nStr('modelsEmpty', 'مدلی برای این دسته یافت نشد.')));
				return;
			}

			models.forEach(function (model) {
				var id = String(model.id || '');
				var price = String(model.pricing_label || '') || i18nStr('modelNoPrice', 'قیمت اعلام نشده');
				var owned = String(model.owned_by || '');
				var $btn = $('<button type="button" class="negarandeh-model-item" role="option"/>')
					.attr('data-model-id', id)
					.attr('title', i18nStr('modelClickToCopy', 'کلیک برای کپی'));

				$btn.append($('<code class="negarandeh-model-id" dir="ltr"/>').text(id));
				$btn.append($('<span class="negarandeh-model-price"/>').text(price));
				if (owned) {
					$btn.append(
						$('<span class="negarandeh-model-owned"/>').text(
							(negarandehAdmin.i18n.modelOwnedBy || 'ارائه‌دهنده: %s').replace('%s', owned)
						)
					);
				}
				$list.append($btn);
			});
		}

		function filterModelsList($panel) {
			var q = String($panel.find('.negarandeh-models-filter').val() || '').toLowerCase().trim();
			$panel.find('.negarandeh-model-item').each(function () {
				var id = String($(this).data('model-id') || '').toLowerCase();
				var text = $(this).text().toLowerCase();
				$(this).toggle(!q || id.indexOf(q) !== -1 || text.indexOf(q) !== -1);
			});
		}

		function setModelsPanelOpen($panel, isOpen) {
			$panel.prop('hidden', !isOpen);
		}

		function loadModelsList(kind, $btn, $panel) {
			var url = requireAjaxUrl(true);
			if (!url) {
				alert(ajaxUrlUnavailableMessage());
				return;
			}

			$btn.prop('disabled', true);
			setModelsPanelOpen($panel, true);
			$panel.find('.negarandeh-models-list').html(
				$('<p class="description"/>').text(i18nStr('loadingModels', 'در حال دریافت لیست مدل‌ها...'))
			);
			$panel.find('.negarandeh-models-meta').text('');

			$.ajax({
				url: url,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'negarandeh_list_models',
					nonce: ajaxNonce(),
					kind: kind,
					api_key: $('#negarandeh_api_key').val() || '',
					api_base_url: $('#negarandeh_api_base_url').val() || ''
				}
			}).done(function (res) {
				if (!res || !res.success || !res.data) {
					var msg = (res && res.data && res.data.message) ? res.data.message : i18nStr('modelsLoadFail', 'دریافت لیست مدل‌ها ناموفق بود.');
					$panel.find('.negarandeh-models-list').html($('<p class="negarandeh-test-fail"/>').text(msg));
					return;
				}

				var models = res.data.models || [];
				var countTpl = negarandehAdmin.i18n.modelsCount || '%d مدل';
				var source = res.data.source === 'auth'
					? i18nStr('modelsSourceAuth', 'از API کلید شما')
					: i18nStr('modelsSourcePublic', 'لیست عمومی AvalAI');
				$panel.find('.negarandeh-models-meta').text(
					countTpl.replace('%d', String(models.length)) + ' — ' + source
				);
				$panel.data('models', models);
				renderModelsList($panel, models);
				filterModelsList($panel);
			}).fail(function () {
				$panel.find('.negarandeh-models-list').html(
					$('<p class="negarandeh-test-fail"/>').text(i18nStr('modelsLoadFail', 'دریافت لیست مدل‌ها ناموفق بود.'))
				);
			}).always(function () {
				$btn.prop('disabled', false);
			});
		}

		$(document).on('click', '#negarandeh-load-text-models', function (e) {
			e.preventDefault();
			loadModelsList('text', $(this), $('#negarandeh-text-models-panel'));
		});

		$(document).on('click', '#negarandeh-load-image-models', function (e) {
			e.preventDefault();
			loadModelsList('image', $(this), $('#negarandeh-image-models-panel'));
		});

		$(document).on('click', '#negarandeh-close-text-models', function (e) {
			e.preventDefault();
			setModelsPanelOpen($('#negarandeh-text-models-panel'), false);
		});

		$(document).on('click', '#negarandeh-close-image-models', function (e) {
			e.preventDefault();
			setModelsPanelOpen($('#negarandeh-image-models-panel'), false);
		});

		$(document).on('input', '.negarandeh-models-filter', function () {
			filterModelsList($(this).closest('.negarandeh-models-panel'));
		});

		$(document).on('click', '.negarandeh-model-item', function (e) {
			e.preventDefault();
			var $item = $(this);
			var $panel = $item.closest('.negarandeh-models-panel');
			var modelId = String($item.data('model-id') || '');
			var target = String($panel.data('target') || '');
			if (!modelId || !target) {
				return;
			}

			var $input = $(target);
			$input.val(modelId).trigger('change').trigger('input');
			$panel.find('.negarandeh-model-item').removeClass('is-selected');
			$item.addClass('is-selected');

			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(modelId).catch(function () {});
			}

			var $note = $panel.find('.negarandeh-models-copied');
			if (!$note.length) {
				$note = $('<p class="negarandeh-models-copied description"/>');
				$panel.find('.negarandeh-models-list').after($note);
			}
			$note.text(i18nStr('modelCopied', 'نام مدل کپی و در فیلد قرار گرفت. ذخیره را فراموش نکنید.'));
		});

		$(document).on('click', '#negarandeh-test-image', function (e) {
			e.preventDefault();
			var $btn = $(this);
			var $result = $('#negarandeh-test-image-result');

			$btn.prop('disabled', true);
			showTestResultIn($result, 'pending', t('testingImage', 'در حال تست تصویر...'));

			var url = requireAjaxUrl(true);
			if (!url) {
				showTestResultIn($result, 'fail', ajaxUrlUnavailableMessage());
				$btn.prop('disabled', false);
				return;
			}

			$.ajax({
				url: url,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'negarandeh_test_image',
					nonce: ajaxNonce(),
					api_key: $('#negarandeh_api_key').val() || '',
					api_base_url: $('#negarandeh_api_base_url').val() || '',
					image_model: $('#negarandeh_image_model').val() || ''
				}
			}).done(function (response) {
				if (response && response.success) {
					showTestResultIn($result, 'ok', response.data.message || t('testImageOk', 'تولید تصویر موفق!'));
				} else {
					showImageTestError($result, response);
				}
			}).fail(function (xhr) {
				var parsed = null;
				try {
					parsed = xhr.responseText ? JSON.parse(xhr.responseText) : null;
				} catch (e) {
					parsed = null;
				}
				if (parsed && parsed.data) {
					showImageTestError($result, parsed);
				} else {
					showTestResultIn($result, 'fail', formatAjaxError(null, xhr));
				}
			}).always(function () {
				$btn.prop('disabled', false);
			});
		});

		$(document).on('click', '#negarandeh-reset-image-prompt', function (e) {
			e.preventDefault();
			if (!confirm(t('confirmResetImagePrompt', 'پرامپت تصویر با متن پیش‌فرض جایگزین شود؟'))) {
				return;
			}

			var defaults = (typeof negarandehAdmin !== 'undefined' && negarandehAdmin.defaultImagePrompt)
				? String(negarandehAdmin.defaultImagePrompt)
				: '';

			if (!defaults) {
				alert(t('error', 'خطا'));
				return;
			}

			$('#negarandeh-image-prompt').val(defaults).trigger('change');
			alert(t('imagePromptReset', 'پرامپت تصویر به پیش‌فرض برگشت. برای اعمال، ذخیره تنظیمات را بزنید.'));
		});

		$(document).on('click', '#negarandeh-preview-image-prompt', function (e) {
			e.preventDefault();
			var $btn = $(this);
			var $status = $('#negarandeh-image-preview-status');
			var $box = $('#negarandeh-image-preview-box');
			var $img = $('#negarandeh-image-preview-img');
			var prompt = $('#negarandeh-image-prompt').val() || '';
			var topics = $('#negarandeh-topics').val() || '';

			if (!prompt.trim()) {
				showTestResultIn($status, 'fail', t('emptyPrompt', 'پرامپت تصویر خالی است.'));
				$box.prop('hidden', true);
				return;
			}

			$btn.prop('disabled', true);
			$box.prop('hidden', true);
			showTestResultIn($status, 'pending', t('previewImage', 'در حال ساخت تصویر...'));

			var url = requireAjaxUrl(true);
			if (!url) {
				showTestResultIn($status, 'fail', ajaxUrlUnavailableMessage());
				$btn.prop('disabled', false);
				return;
			}

			$.ajax({
				url: url,
				type: 'POST',
				dataType: 'json',
				timeout: 200000,
				data: {
					action: 'negarandeh_preview_image_prompt',
					nonce: ajaxNonce(),
					prompt: prompt,
					topics: topics
				}
			}).done(function (response) {
				if (response && response.success && response.data && response.data.image_src) {
					$img.attr('src', response.data.image_src);
					$('#negarandeh-image-preview-prompt-used').text(response.data.prompt_used || '');
					$box.prop('hidden', false);
					var msg = t('previewReady', 'پیش‌نمایش تصویر (ذخیره نشده)');
					if (response.data.topic_used) {
						msg += ' — ' + t('previewTopic', 'موضوع:') + ' ' + response.data.topic_used;
					}
					showTestResultIn($status, 'ok', msg);
				} else {
					showTestResultIn($status, 'fail', formatAjaxError(response, null));
				}
			}).fail(function (xhr) {
				showTestResultIn($status, 'fail', formatAjaxError(null, xhr));
			}).always(function () {
				$btn.prop('disabled', false);
			});
		});

		if ($('#negarandeh-status-panel').length) {
			var url = requireAjaxUrl(true);
			if (!url) {
				return;
			}
			$.post(url, {
				action: 'negarandeh_get_queue_status',
				nonce: ajaxNonce()
			}).done(function (response) {
				if (response && response.success) {
					updateStatus(response.data);
					var queue = response.data.queue || {};
					if (queue.status === 'running') {
						$('#negarandeh-start-btn').prop('disabled', true);
						setStartBtnLabel($('#negarandeh-start-btn'), t('generating', 'در حال تولید...'), 'update');
						resumeRunningQueue();
					}
				}
			});
		}
	});
})(jQuery);
