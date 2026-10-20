<?php
/**
 * HiBackup Uninstall Handler
 * Xóa sạch options và dọn dẹp file tạm khi gỡ cài đặt plugin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Xóa options
delete_option( 'hibackup_settings' );
delete_option( 'hibackup_history' );
delete_transient( 'hibackup_gdrive_access_token' );

// Xóa lịch trình cron
wp_clear_scheduled_hook( 'hibackup_scheduled_backup_event' );

// Dọn dẹp thư mục tạm
$upload_dir = wp_upload_dir();
$temp_dir = $upload_dir['basedir'] . '/hibackup-temp/';
if ( is_dir( $temp_dir ) ) {
	$files = glob( $temp_dir . '*' );
	if ( ! empty( $files ) ) {
		foreach ( $files as $file ) {
			if ( is_file( $file ) ) {
				@unlink( $file );
			}
		}
	}
	@rmdir( $temp_dir );
}
