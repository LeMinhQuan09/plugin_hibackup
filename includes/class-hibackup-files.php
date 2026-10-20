<?php
/**
 * HiBackup File Archiver
 * Nén mã nguồn WordPress và file Database SQL vào file Zip hoàn chỉnh.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HiBackup_Files {

	/**
	 * Tạo file ZIP chứa database và các thành phần mã nguồn được chọn
	 *
	 * @param string $zip_path Đường dẫn lưu file ZIP tạm
	 * @param string|null $db_sql_path Đường dẫn file SQL đã dump (nếu có)
	 * @param array $options Các tùy chọn thành phần cần backup
	 * @return string|WP_Error Đường dẫn file ZIP đã nén thành công hoặc WP_Error
	 */
	public static function create_zip( $zip_path, $db_sql_path = null, $options = array() ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'missing_ziparchive', __( 'Máy chủ chưa bật PHP extension ZipArchive.', 'hibackup' ) );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 );
		}

		$zip = new ZipArchive();
		$res = $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		if ( $res !== true ) {
			return new WP_Error( 'zip_open_failed', sprintf( __( 'Không thể tạo file ZIP (Mã lỗi: %d).', 'hibackup' ), $res ) );
		}

		// 1. Thêm file Database SQL vào thư mục gốc của file Zip
		if ( ! empty( $db_sql_path ) && file_exists( $db_sql_path ) ) {
			$zip->addFile( $db_sql_path, 'database.sql' );
		}

		// 2. Thêm file wp-config.php (nếu được bật)
		if ( ! empty( $options['include_config'] ) ) {
			$config_file = ABSPATH . 'wp-config.php';
			if ( file_exists( $config_file ) ) {
				$zip->addFile( $config_file, 'wp-config.php' );
			}
		}

		// 3. Thêm các thư mục mã nguồn
		$directories_to_zip = array();
		if ( ! empty( $options['include_plugins'] ) ) {
			$directories_to_zip['wp-content/plugins'] = WP_PLUGIN_DIR;
		}
		if ( ! empty( $options['include_themes'] ) ) {
			$directories_to_zip['wp-content/themes'] = get_theme_root();
		}
		if ( ! empty( $options['include_uploads'] ) ) {
			$upload_dir = wp_upload_dir();
			$directories_to_zip['wp-content/uploads'] = $upload_dir['basedir'];
		}
		if ( ! empty( $options['include_core'] ) ) {
			$directories_to_zip['wp-admin'] = ABSPATH . 'wp-admin';
			$directories_to_zip['wp-includes'] = ABSPATH . WPINC;
		}

		// Danh sách các mẫu cần bỏ qua (để tiết kiệm dung lượng và tránh loop)
		$exclude_patterns = array(
			'hibackup-temp',
			'wp-content/cache',
			'cache',
			'.git',
			'.svn',
			'node_modules',
			'.DS_Store',
			'error_log',
			'debug.log',
		);

		foreach ( $directories_to_zip as $relative_prefix => $dir_path ) {
			if ( is_dir( $dir_path ) ) {
				self::add_folder_to_zip( $zip, $dir_path, $relative_prefix, $exclude_patterns );
			}
		}

		$zip->close();

		if ( ! file_exists( $zip_path ) || filesize( $zip_path ) === 0 ) {
			return new WP_Error( 'zip_empty', __( 'File ZIP tạo ra rỗng hoặc bị lỗi khi đóng nén.', 'hibackup' ) );
		}

		return $zip_path;
	}

	/**
	 * Đệ quy thêm file trong thư mục vào Zip với bộ lọc loại trừ
	 */
	private static function add_folder_to_zip( &$zip, $folder_path, $local_prefix, $exclude_patterns = array() ) {
		$folder_path = rtrim( $folder_path, '/\\' );
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $folder_path, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $files as $file ) {
			$file_path = wp_normalize_path( $file->getRealPath() );
			$relative_path = substr( $file_path, strlen( wp_normalize_path( $folder_path ) ) + 1 );
			$zip_entry_path = ltrim( $local_prefix . '/' . $relative_path, '/' );

			// Kiểm tra loại trừ
			$should_exclude = false;
			foreach ( $exclude_patterns as $pattern ) {
				if ( false !== strpos( $file_path, $pattern ) ) {
					$should_exclude = true;
					break;
				}
			}

			if ( $should_exclude ) {
				continue;
			}

			if ( $file->isDir() ) {
				$zip->addEmptyDir( $zip_entry_path );
			} elseif ( $file->isFile() ) {
				$zip->addFile( $file_path, $zip_entry_path );
			}
		}
	}
}
