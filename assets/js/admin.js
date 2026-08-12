/**
 * Yoko Link Checker Admin Scripts
 *
 * @package YokoLinkChecker
 * @since   1.0.0
 */

/* global jQuery, ylcAdmin */

(function($) {
	'use strict';

	if (typeof ylcAdmin === 'undefined') {
		return;
	}

	/**
	 * Current polling timeout ID.
	 */
	let pollIntervalId = null;

	/**
	 * Number of polling requests made since polling started.
	 */
	let pollCount = 0;

	/**
	 * Initialize the admin scripts.
	 */
	function init() {
		bindEvents();
		maybeStartPolling();
	}

	/**
	 * Bind event handlers.
	 */
	function bindEvents() {
		// Scan controls
		$(document).on('click', '.ylc-start-scan', handleStartScan);
		$(document).on('click', '.ylc-pause-scan', handlePauseScan);
		$(document).on('click', '.ylc-resume-scan', handleResumeScan);
		$(document).on('click', '.ylc-cancel-scan', handleCancelScan);

		// Data management
		$(document).on('click', '.ylc-clear-data', handleClearData);

		// Settings: toggle auto-scan options visibility.
		$('#yoko_lc_auto_scan_enabled').on('change', function() {
			$('.ylc-auto-scan-option').toggle(this.checked);
		});

		// Clear polling timer on page unload.
		$(window).on('beforeunload', function() {
			stopPolling();
		});
	}

	/**
	 * Start polling if a scan is running.
	 */
	function maybeStartPolling() {
		const $status = $('#ylc-scan-status');
		if ($status.find('.ylc-scanning').length > 0) {
			startPolling();
		}
	}

	/**
	 * Get adaptive polling interval based on poll count.
	 *
	 * Returns 2s for the first 10 polls, 5s for polls 10-30, then 10s.
	 *
	 * @return {number} Polling interval in milliseconds.
	 */
	function getPollInterval() {
		if (pollCount < 10) {
			return 2000;
		} else if (pollCount < 30) {
			return 5000;
		}
		return 10000;
	}

	/**
	 * Start status polling.
	 */
	function startPolling() {
		if (pollIntervalId) {
			return;
		}

		pollCount = 0;
		scheduleNextPoll();
	}

	/**
	 * Schedule the next poll using adaptive interval.
	 */
	function scheduleNextPoll() {
		pollIntervalId = setTimeout(function() {
			pollCount++;
			pollIntervalId = null;
			pollStatus(scheduleNextPoll);
		}, getPollInterval());
	}

	/**
	 * Stop status polling.
	 */
	function stopPolling() {
		if (pollIntervalId) {
			clearTimeout(pollIntervalId);
			pollIntervalId = null;
		}
	}

	/**
	 * Poll for scan status.
	 *
	 * @param {Function} onComplete Callback invoked after the request finishes.
	 */
	function pollStatus(onComplete) {
		$.ajax({
			url: ylcAdmin.ajaxUrl,
			type: 'POST',
			data: {
				action: 'yoko_lc_get_scan_status',
				nonce: ylcAdmin.nonces.get_scan_status
			},
			success: function(response) {
				if (response && response.success && response.data) {
					updateScanStatus(response.data);
				}
			},
			complete: function() {
				if (typeof onComplete === 'function') {
					onComplete();
				}
			}
		});
	}

	/**
	 * Update scan status display.
	 *
	 * @param {Object} data Status data.
	 */
	function updateScanStatus(data) {
		if (!data.running && data.status && data.status.status === 'completed') {
			stopPolling();
			location.reload();
			return;
		}

		if (!data.running && !data.status) {
			stopPolling();
			return;
		}

		if (data.running) {
			const $progressFill = $('.ylc-progress-fill');
			const $progressText = $('.ylc-progress-text');
			const $scanPhase = $('.ylc-scan-phase');

			if (typeof data.progress !== 'undefined') {
				let progress = parseFloat(data.progress);
				if (isNaN(progress) || progress < 0) {
					progress = 0;
				} else if (progress > 100) {
					progress = 100;
				}
				$progressFill.css('width', progress + '%');
				$progressText.text(Math.round(progress * 10) / 10 + '%');
			}

			if (data.status && data.status.phase) {
				$scanPhase.text(ylcAdmin.strings.phase + ' ' + capitalizeFirst(data.status.phase));
			}
		}
	}

	/**
	 * Handle start scan click.
	 *
	 * @param {Event} e Click event.
	 */
	function handleStartScan(e) {
		e.preventDefault();

		if (!confirm(ylcAdmin.strings.confirmStart)) {
			return;
		}

		const $button = $(this);
		$button.prop('disabled', true).text(ylcAdmin.strings.scanning);

		$.ajax({
			url: ylcAdmin.ajaxUrl,
			type: 'POST',
			data: {
				action: 'yoko_lc_start_scan',
				nonce: ylcAdmin.nonces.start_scan
			},
			success: function(response) {
				if (!response || !response.success) {
					alert((response && response.data && response.data.message) || ylcAdmin.strings.error);
					$button.prop('disabled', false).text(ylcAdmin.strings.startNewScan);
					return;
				}
				location.reload();
			},
			error: function() {
				alert(ylcAdmin.strings.error);
				$button.prop('disabled', false).text(ylcAdmin.strings.startNewScan);
			}
		});
	}

	/**
	 * Handle pause scan click.
	 *
	 * @param {Event} e Click event.
	 */
	function handlePauseScan(e) {
		e.preventDefault();

		const $button = $(this);
		const scanId = $button.data('scan-id');

		$button.prop('disabled', true);

		$.ajax({
			url: ylcAdmin.ajaxUrl,
			type: 'POST',
			data: {
				action: 'yoko_lc_pause_scan',
				scan_id: scanId,
				nonce: ylcAdmin.nonces.pause_scan
			},
			success: function(response) {
				if (!response || !response.success) {
					alert((response && response.data && response.data.message) || ylcAdmin.strings.error);
					$button.prop('disabled', false);
					return;
				}
				stopPolling();
				location.reload();
			},
			error: function() {
				alert(ylcAdmin.strings.error);
				$button.prop('disabled', false);
			}
		});
	}

	/**
	 * Handle resume scan click.
	 *
	 * @param {Event} e Click event.
	 */
	function handleResumeScan(e) {
		e.preventDefault();

		const $button = $(this);
		const scanId = $button.data('scan-id');

		$button.prop('disabled', true);

		$.ajax({
			url: ylcAdmin.ajaxUrl,
			type: 'POST',
			data: {
				action: 'yoko_lc_resume_scan',
				scan_id: scanId,
				nonce: ylcAdmin.nonces.resume_scan
			},
			success: function(response) {
				if (!response || !response.success) {
					alert((response && response.data && response.data.message) || ylcAdmin.strings.error);
					$button.prop('disabled', false);
					return;
				}
				location.reload();
			},
			error: function() {
				alert(ylcAdmin.strings.error);
				$button.prop('disabled', false);
			}
		});
	}

	/**
	 * Handle cancel scan click.
	 *
	 * @param {Event} e Click event.
	 */
	function handleCancelScan(e) {
		e.preventDefault();

		if (!confirm(ylcAdmin.strings.confirmCancel)) {
			return;
		}

		const $button = $(this);
		const scanId = $button.data('scan-id');

		$button.prop('disabled', true);

		$.ajax({
			url: ylcAdmin.ajaxUrl,
			type: 'POST',
			data: {
				action: 'yoko_lc_cancel_scan',
				scan_id: scanId,
				nonce: ylcAdmin.nonces.cancel_scan
			},
			success: function(response) {
				if (!response || !response.success) {
					alert((response && response.data && response.data.message) || ylcAdmin.strings.error);
					$button.prop('disabled', false);
					return;
				}
				stopPolling();
				location.reload();
			},
			error: function() {
				alert(ylcAdmin.strings.error);
				$button.prop('disabled', false);
			}
		});
	}

	/**
	 * Handle clear data click.
	 *
	 * @param {Event} e Click event.
	 */
	function handleClearData(e) {
		e.preventDefault();

		if (!confirm(ylcAdmin.strings.confirmClear)) {
			return;
		}

		const $button = $(this);
		$button.prop('disabled', true).text(ylcAdmin.strings.clearing);

		$.ajax({
			url: ylcAdmin.ajaxUrl,
			type: 'POST',
			data: {
				action: 'yoko_lc_clear_data',
				nonce: ylcAdmin.nonces.clear_data
			},
			success: function(response) {
				if (!response || !response.success) {
					alert((response && response.data && response.data.message) || ylcAdmin.strings.error);
					$button.prop('disabled', false).text(ylcAdmin.strings.clearData);
					return;
				}
				alert((response.data && response.data.message) || ylcAdmin.strings.dataCleared);
				location.reload();
			},
			error: function() {
				alert(ylcAdmin.strings.error);
				$button.prop('disabled', false).text(ylcAdmin.strings.clearData);
			}
		});
	}

	/**
	 * Capitalize first letter.
	 *
	 * @param {string} str String to capitalize.
	 * @return {string} Capitalized string.
	 */
	function capitalizeFirst(str) {
		if (!str) return '';
		return str.charAt(0).toUpperCase() + str.slice(1);
	}

	// Initialize on document ready
	$(document).ready(init);

})(jQuery);
