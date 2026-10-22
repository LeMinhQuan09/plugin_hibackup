/**
 * HiBackup Admin JavaScript Handler
 */
(function ($) {
	'use strict';

	$(document).ready(function () {
		// 1. Toggle phương thức kết nối Google Drive
		$('.hibackup-auth-toggle').on('change', function () {
			var selected = $(this).val();
			if (selected === 'service_account') {
				$('#auth-section-oauth2').hide();
				$('#auth-section-sa').show();
			} else {
				$('#auth-section-oauth2').show();
				$('#auth-section-sa').hide();
			}
		});

		// 2. Kiểm tra kết nối Google Drive (Đồng thời tự động lưu lại cấu hình)
		$('#btn-test-gdrive').on('click', function (e) {
			e.preventDefault();
			var $btn = $(this);
			var $resultBox = $('#test-connection-result');

			var authType = $('.hibackup-auth-toggle:checked').val() || 'oauth2';
			var clientId = $('#gdrive_client_id').val() || '';
			var clientSecret = $('#gdrive_client_secret').val() || '';
			var refreshToken = $('#gdrive_refresh_token').val() || '';
			var saJson = $('#gdrive_service_account_json').val() || '';
			var folderId = $('#gdrive_folder_id').val() || '';

			$btn.prop('disabled', true).addClass('updating-message');
			$resultBox.html('<div class="notice notice-info"><p>' + hibackupData.strings.testingConnection + '</p></div>').show();

			$.ajax({
				url: hibackupData.ajaxUrl,
				type: 'POST',
				data: {
					action: 'hibackup_test_connection',
					nonce: hibackupData.nonce,
					gdrive_auth_type: authType,
					gdrive_client_id: clientId,
					gdrive_client_secret: clientSecret,
					gdrive_refresh_token: refreshToken,
					gdrive_service_account_json: saJson,
					gdrive_folder_id: folderId,
				},
				success: function (response) {
					$btn.prop('disabled', false).removeClass('updating-message');
					if (response.success) {
						$resultBox.html('<div class="notice notice-success is-dismissible"><p>' + response.data.message + '</p></div>');
					} else {
						$resultBox.html('<div class="notice notice-error is-dismissible"><p><strong>Thất bại:</strong> ' + response.data.message + '</p></div>');
					}
				},
				error: function (xhr, status, error) {
					$btn.prop('disabled', false).removeClass('updating-message');
					$resultBox.html('<div class="notice notice-error is-dismissible"><p><strong>Lỗi mạng:</strong> ' + error + '</p></div>');
				},
			});
		});

		// 3. Khởi chạy Sao lưu thủ công qua AJAX
		$('#btn-start-backup').on('click', function (e) {
			e.preventDefault();
			var $btn = $(this);
			var $progressBox = $('#hibackup-progress-box');
			var $progressBar = $('#hibackup-progress-bar');
			var $status = $('#hibackup-progress-status');
			var $percentage = $('#hibackup-progress-percentage');
			var $console = $('#hibackup-log-console');

			$btn.prop('disabled', true);
			$progressBox.slideDown();
			$console.html('');

			function appendLog(text, type) {
				var cls = type ? 'log-' + type : '';
				var time = new Date().toLocaleTimeString();
				$console.append('<div class="log-item ' + cls + '">[' + time + '] ' + text + '</div>');
				$console.scrollTop($console[0].scrollHeight);
			}

			function updateProgress(percent, statusText) {
				$progressBar.css('width', percent + '%');
				$percentage.text(percent + '%');
				if (statusText) {
					$status.text(statusText);
					appendLog(statusText);
				}
			}

			updateProgress(10, 'Bắt đầu tiến trình: Đang trích xuất Database và nén mã nguồn...');

			// Mô phỏng thanh tiến trình giai đoạn tạo file
			var timer = setInterval(function () {
				var current = parseInt($percentage.text());
				if (current < 45) {
					updateProgress(current + 5);
				}
			}, 1500);

				var isFullSource = $('#run_include_full_source').is(':checked') ? 1 : 0;

				$.ajax({
					url: hibackupData.ajaxUrl,
					type: 'POST',
					timeout: 600000, // 10 phút
					data: {
						action: 'hibackup_run_backup',
						nonce: hibackupData.nonce,
						include_db: $('#run_include_db').is(':checked') ? 1 : 0,
						include_full_source: isFullSource,
						include_plugins: isFullSource || $('#run_include_plugins').is(':checked') ? 1 : 0,
						include_themes: isFullSource || $('#run_include_themes').is(':checked') ? 1 : 0,
						include_uploads: isFullSource || $('#run_include_uploads').is(':checked') ? 1 : 0,
						include_config: isFullSource || $('#run_include_config').is(':checked') ? 1 : 0,
						include_core: isFullSource || $('#run_include_core').is(':checked') ? 1 : 0,
					},
				success: function (response) {
					clearInterval(timer);
					$btn.prop('disabled', false);

					if (response.success) {
						updateProgress(100, 'Hoàn tất! File đã được lưu an toàn trên Google Drive.');
						appendLog('✓ File nén tạm trên hosting đã được xóa sạch!', 'success');
						appendLog('✓ ' + response.data.message, 'success');
						$('#hibackup-progress-spinner').removeClass('is-active');

						setTimeout(function () {
							if (confirm('Sao lưu thành công! Bạn có muốn chuyển sang tab Lịch sử để xem bản sao lưu trên Google Drive không?')) {
								window.location.href = window.location.href.split('&tab=')[0] + '&tab=history';
							}
						}, 1200);
					} else {
						updateProgress(100, 'Gặp lỗi trong quá trình sao lưu.');
						appendLog('✗ Lỗi: ' + response.data.message, 'error');
						$('#hibackup-progress-spinner').removeClass('is-active');
					}
				},
				error: function (xhr, status, error) {
					clearInterval(timer);
					$btn.prop('disabled', false);
					updateProgress(100, 'Lỗi kết nối máy chủ.');
					appendLog('✗ Lỗi Timeout/Mạng: ' + error, 'error');
					$('#hibackup-progress-spinner').removeClass('is-active');
				},
			});
		});

		// 4. Xóa mục lịch sử
		$('.btn-delete-history').on('click', function (e) {
			e.preventDefault();
			var $btn = $(this);
			var itemId = $btn.data('id');
			var fileId = $btn.data('fileid');

			if (!confirm(hibackupData.strings.confirmDelete)) {
				return;
			}

			$btn.prop('disabled', true);

			$.ajax({
				url: hibackupData.ajaxUrl,
				type: 'POST',
				data: {
					action: 'hibackup_delete_history',
					nonce: hibackupData.nonce,
					item_id: itemId,
					file_id: fileId,
				},
				success: function (response) {
					if (response.success) {
						$('#row-' + itemId).fadeOut(300, function () {
							$(this).remove();
						});
					} else {
						alert(response.data.message);
						$btn.prop('disabled', false);
					}
				},
				error: function () {
					alert('Không thể kết nối đến máy chủ.');
					$btn.prop('disabled', false);
				},
			});
		});

		// 5. Quản lý phân cấp & Chọn tất cả (Tab Cấu hình chung)
		function syncFullSourceOptions() {
			var isFull = $('#backup_full_source').is(':checked');
			var $subBox = $('#hibackup-sub-components-options');
			var $subCbs = $subBox.find('.hibackup-sub-cb');

			if (isFull) {
				$subBox.addClass('is-disabled');
				$subBox.find('.hibackup-sub-hint-text').hide();
				$subBox.find('.hibackup-included-badge').show();
				$subCbs.prop('disabled', true);
			} else {
				$subBox.removeClass('is-disabled');
				$subBox.find('.hibackup-sub-hint-text').show();
				$subBox.find('.hibackup-included-badge').hide();
				$subCbs.prop('disabled', false);
			}
		}

		function syncOptionsSelectAll() {
			var dbChecked = $('input[name="backup_db"]').is(':checked');
			var fullChecked = $('#backup_full_source').is(':checked');
			var $subCbs = $('#hibackup-sub-components-options .hibackup-sub-cb');
			var subCheckedCount = $subCbs.filter(':checked').length;
			var $toggle = $('#hibackup_toggle_all_options');

			var isAll = dbChecked && (fullChecked || subCheckedCount === $subCbs.length);
			var isNone = !dbChecked && !fullChecked && subCheckedCount === 0;

			if (isAll) {
				$toggle.prop('checked', true).prop('indeterminate', false);
			} else if (isNone) {
				$toggle.prop('checked', false).prop('indeterminate', false);
			} else {
				$toggle.prop('checked', false).prop('indeterminate', true);
			}
		}

		$('#backup_full_source').on('change', function () {
			syncFullSourceOptions();
			syncOptionsSelectAll();
		});

		$('input[name="backup_db"], .hibackup-sub-cb').on('change', function () {
			syncOptionsSelectAll();
		});

		$('#hibackup_toggle_all_options').on('change', function () {
			var isChecked = $(this).is(':checked');
			$('input[name="backup_db"]').prop('checked', isChecked);
			$('#backup_full_source').prop('checked', isChecked);
			if (isChecked) {
				$('.hibackup-sub-cb').prop('checked', true);
			} else {
				$('.hibackup-sub-cb').prop('checked', false);
			}
			syncFullSourceOptions();
		});

		syncFullSourceOptions();
		syncOptionsSelectAll();

		// 6. Quản lý phân cấp & Chọn tất cả (Tab Sao lưu ngay)
		function syncFullSourceRun() {
			var isFull = $('#run_include_full_source').is(':checked');
			var $subBox = $('#hibackup-sub-components-run');
			var $subCbs = $subBox.find('.run-sub-cb');

			if (isFull) {
				$subBox.addClass('is-disabled');
				$subBox.find('.hibackup-sub-hint-text').hide();
				$subBox.find('.hibackup-included-badge').show();
				$subCbs.prop('disabled', true);
			} else {
				$subBox.removeClass('is-disabled');
				$subBox.find('.hibackup-sub-hint-text').show();
				$subBox.find('.hibackup-included-badge').hide();
				$subCbs.prop('disabled', false);
			}
		}

		function syncBackupNowSelectAll() {
			var dbChecked = $('#run_include_db').is(':checked');
			var fullChecked = $('#run_include_full_source').is(':checked');
			var $subCbs = $('#hibackup-sub-components-run .run-sub-cb');
			var subCheckedCount = $subCbs.filter(':checked').length;
			var $toggle = $('#run_toggle_all_components');

			var isAll = dbChecked && (fullChecked || subCheckedCount === $subCbs.length);
			var isNone = !dbChecked && !fullChecked && subCheckedCount === 0;

			if (isAll) {
				$toggle.prop('checked', true).prop('indeterminate', false);
			} else if (isNone) {
				$toggle.prop('checked', false).prop('indeterminate', false);
			} else {
				$toggle.prop('checked', false).prop('indeterminate', true);
			}
		}

		$('#run_include_full_source').on('change', function () {
			syncFullSourceRun();
			syncBackupNowSelectAll();
		});

		$('#run_include_db, .run-sub-cb').on('change', function () {
			syncBackupNowSelectAll();
		});

		$('#run_toggle_all_components').on('change', function () {
			var isChecked = $(this).is(':checked');
			$('#run_include_db').prop('checked', isChecked);
			$('#run_include_full_source').prop('checked', isChecked);
			if (isChecked) {
				$('.run-sub-cb').prop('checked', true);
			} else {
				$('.run-sub-cb').prop('checked', false);
			}
			syncFullSourceRun();
		});

		syncFullSourceRun();
		syncBackupNowSelectAll();
	});
})(jQuery);
