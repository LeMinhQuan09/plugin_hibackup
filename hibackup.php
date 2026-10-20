<?php
/**
 * Plugin Name: HiBackup
 * Plugin URI: https://hiseo.vn
 * Description: Sao lưu toàn diện mã nguồn (source code) và cơ sở dữ liệu (database) WordPress tự động tải lên Google Drive và không lưu trữ trên hosting.
 * Version: 1.0.1
 * Author: hiseo
 * Author URI: https://hiseo.vn
 * Text Domain: hibackup
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL v2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Chống truy cập trực tiếp
}

// Định nghĩa các hằng số cơ bản
define( 'HIBACKUP_VERSION', '1.0.1' );
define( 'HIBACKUP_PLUGIN_FILE', __FILE__ );
define( 'HIBACKUP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HIBACKUP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'HIBACKUP_TEMP_DIR', wp_upload_dir()['basedir'] . '/hibackup-temp/' );

// Nạp các lớp xử lý
require_once HIBACKUP_PLUGIN_DIR . 'includes/class-hibackup-gdrive.php';
require_once HIBACKUP_PLUGIN_DIR . 'includes/class-hibackup-db.php';
require_once HIBACKUP_PLUGIN_DIR . 'includes/class-hibackup-files.php';
require_once HIBACKUP_PLUGIN_DIR . 'includes/class-hibackup-cron.php';
require_once HIBACKUP_PLUGIN_DIR . 'includes/class-hibackup-admin.php';
require_once HIBACKUP_PLUGIN_DIR . 'includes/class-hibackup.php';

/**
 * Khởi động plugin
 */
function hibackup_init() {
	$plugin = new HiBackup();
	$plugin->run();

	// Tự động kiểm tra cập nhật từ GitHub nếu đã cấu hình Repo URL
	if ( file_exists( HIBACKUP_PLUGIN_DIR . 'includes/puc/plugin-update-checker.php' ) ) {
		require_once HIBACKUP_PLUGIN_DIR . 'includes/puc/plugin-update-checker.php';
		$settings = get_option( 'hibackup_settings', array() );
		$repo_url = ! empty( $settings['github_repo_url'] ) ? trim( $settings['github_repo_url'] ) : 'https://github.com/LeMinhQuan09/plugin_hibackup';

		if ( ! empty( $repo_url ) && class_exists( 'YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
			$updater = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
				$repo_url,
				HIBACKUP_PLUGIN_FILE,
				'hibackup'
			);

			if ( ! empty( $settings['github_token'] ) ) {
				$updater->setAuthentication( trim( $settings['github_token'] ) );
			}

			// Kích hoạt nhận diện file ZIP đính kèm từ GitHub Releases
			if ( method_exists( $updater->getVcsApi(), 'enableReleaseAssets' ) ) {
				$updater->getVcsApi()->enableReleaseAssets();
			}
		}
	}
}
add_action( 'plugins_loaded', 'hibackup_init' );

/**
 * Hook kích hoạt plugin
 */
register_activation_hook( __FILE__, array( 'HiBackup', 'activate' ) );

/**
 * Hook hủy kích hoạt plugin
 */
register_deactivation_hook( __FILE__, array( 'HiBackup', 'deactivate' ) );
