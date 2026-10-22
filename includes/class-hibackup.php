<?php
/**
 * HiBackup Main Controller
 * Điều phối toàn bộ luồng tạo backup, tải lên Google Drive và xóa file tạm.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HiBackup {

	public function run() {
		// Tự động kiểm tra và nâng cấp cấu hình khi cập nhật phiên bản mới
		self::maybe_upgrade();

		// Khởi động module Admin
		if ( is_admin() ) {
			$admin = new HiBackup_Admin();
			$admin->init();
		}

		// Khởi động module Cron
		HiBackup_Cron::init();
	}

	/**
	 * Tự động kiểm tra nâng cấp khi cài phiên bản mới trên web Live
	 */
	public static function maybe_upgrade() {
		$installed_ver = get_option( 'hibackup_version', '0.0.0' );
		if ( version_compare( $installed_ver, HIBACKUP_VERSION, '<' ) ) {
			self::ensure_temp_directory();
			$settings = get_option( 'hibackup_settings', array() );
			$defaults = array(
				'gdrive_auth_type'  => 'oauth2',
				'backup_db'          => 1,
				'backup_full_source' => 0,
				'backup_plugins'     => 1,
				'backup_themes'     => 1,
				'backup_uploads'    => 1,
				'backup_config'     => 1,
				'backup_core'       => 0,
				'auto_delete_local' => 1,
				'cron_frequency'    => 'none',
				'retention_count'   => 5,
				'github_repo_url'   => 'https://github.com/LeMinhQuan09/plugin_hibackup',
				'github_token'      => '',
			);
			$settings = wp_parse_args( $settings, $defaults );
			update_option( 'hibackup_settings', $settings );
			update_option( 'hibackup_version', HIBACKUP_VERSION );
		}
	}

	/**
	 * Kích hoạt plugin: Tạo thư mục tạm và bảo vệ
	 */
	public static function activate() {
		self::ensure_temp_directory();

		// Cài đặt mặc định nếu chưa có
		if ( false === get_option( 'hibackup_settings' ) ) {
			update_option( 'hibackup_settings', array(
				'gdrive_auth_type'     => 'oauth2',
				'backup_db'            => 1,
				'backup_full_source'   => 0,
				'backup_plugins'       => 1,
				'backup_themes'        => 1,
				'backup_uploads'       => 1,
				'backup_config'        => 1,
				'backup_core'          => 0,
				'auto_delete_local'    => 1, // Luôn xóa file trên hosting
				'cron_frequency'       => 'none',
				'retention_count'      => 5,
				'github_repo_url'      => 'https://github.com/LeMinhQuan09/plugin_hibackup',
				'github_token'         => '',
			) );
		}
	}

	/**
	 * Hủy kích hoạt plugin: Xóa cron và dọn dẹp file tạm
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( HiBackup_Cron::CRON_HOOK );
		self::clean_temp_directory();
	}

	/**
	 * Đảm bảo thư mục tạm tồn tại và có file bảo vệ chống truy cập trực tiếp
	 */
	public static function ensure_temp_directory() {
		$dir = HIBACKUP_TEMP_DIR;
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// File index.php trống
		$index_file = $dir . 'index.php';
		if ( ! file_exists( $index_file ) ) {
			file_put_contents( $index_file, '<?php // Silence is golden.' );
		}

		// File .htaccess cấm tải trực tiếp
		$htaccess_file = $dir . '.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			file_put_contents( $htaccess_file, "Order deny,allow\nDeny from all\n" );
		}
	}

	/**
	 * Dọn dẹp sạch toàn bộ file trong thư mục tạm
	 */
	public static function clean_temp_directory() {
		$dir = HIBACKUP_TEMP_DIR;
		if ( is_dir( $dir ) ) {
			$files = glob( $dir . '*' );
			if ( ! empty( $files ) ) {
				foreach ( $files as $file ) {
					if ( is_file( $file ) && ! in_array( basename( $file ), array( 'index.php', '.htaccess' ), true ) ) {
						@unlink( $file );
					}
				}
			}
		}
	}

	/**
	 * Thực thi quy trình sao lưu toàn diện
	 *
	 * @param array $options Tùy chọn cấu hình
	 * @param callable $progress_callback Callback cập nhật tiến trình
	 * @return array|WP_Error Kết quả sao lưu hoặc lỗi
	 */
	public function run_full_backup( $options = array(), $progress_callback = null ) {
		self::ensure_temp_directory();
		self::clean_temp_directory(); // Dọn dẹp sạch mọi file tạm/rác còn sót lại (nếu có lần trước bị ngắt đột ngột)

		// 1. Tên website
		$site_name = sanitize_title( get_bloginfo( 'name' ) );
		if ( empty( $site_name ) ) {
			$host = parse_url( home_url(), PHP_URL_HOST );
			$site_name = $host ? sanitize_title( $host ) : 'site';
		}

		// 2. Xác định loại bản sao lưu (full, db, source, custom)
		$has_db          = ! empty( $options['include_db'] );
		$has_full_source = ! empty( $options['include_full_source'] );
		$has_files       = $has_full_source || ! empty( $options['include_plugins'] ) || ! empty( $options['include_themes'] ) || ! empty( $options['include_uploads'] ) || ! empty( $options['include_core'] ) || ! empty( $options['include_config'] );

		if ( $has_db && $has_files ) {
			$type_tag = 'full';
		} elseif ( $has_db ) {
			$type_tag = 'db';
		} elseif ( $has_files ) {
			$type_tag = 'source';
		} else {
			$type_tag = 'custom';
		}

		// 3. Ngày theo múi giờ website (Y-m-d: bỏ giờ, phút, giây)
		$date_str = current_time( 'Y-m-d' );

		// 4. Quy tắc cố định: bk_[ten-web]_[loai]_[nam-thang-ngay]
		$base_name = sprintf( 'bk_%s_%s_%s', $site_name, $type_tag, $date_str );

		// Tránh trùng tên nếu backup nhiều lần trong cùng một ngày (tự tăng _2, _3...)
		$history = get_option( 'hibackup_history', array() );
		$count_today = 0;
		if ( is_array( $history ) ) {
			foreach ( $history as $h ) {
				if ( ! empty( $h['filename'] ) && 0 === strpos( $h['filename'], $base_name ) ) {
					$count_today++;
				}
			}
		}

		if ( $count_today > 0 ) {
			$backup_name = sprintf( '%s_%d', $base_name, $count_today + 1 );
		} else {
			$backup_name = $base_name;
		}

		$sql_file = null;
		$zip_file = HIBACKUP_TEMP_DIR . $backup_name . '.zip';

		// 1. Export Database SQL
		if ( ! empty( $options['include_db'] ) ) {
			if ( is_callable( $progress_callback ) ) {
				call_user_func( $progress_callback, 'db_dump', 10, __( 'Đang trích xuất cơ sở dữ liệu WordPress ra file SQL...', 'hibackup' ) );
			}

			$sql_file = HIBACKUP_TEMP_DIR . $backup_name . '.sql';
			$dump_res = HiBackup_DB::dump( $sql_file );
			if ( is_wp_error( $dump_res ) ) {
				self::clean_temp_directory();
				return $dump_res;
			}
		}

		// 2. Nén các file mã nguồn và SQL vào ZIP
		if ( is_callable( $progress_callback ) ) {
			call_user_func( $progress_callback, 'zipping', 35, __( 'Đang nén mã nguồn và cơ sở dữ liệu vào file ZIP...', 'hibackup' ) );
		}

		$zip_res = HiBackup_Files::create_zip( $zip_file, $sql_file, $options );
		if ( is_wp_error( $zip_res ) ) {
			self::clean_temp_directory();
			return $zip_res;
		}

		// Xóa file SQL sau khi đã nén vào Zip để tiết kiệm ổ cứng
		if ( ! empty( $sql_file ) && file_exists( $sql_file ) ) {
			@unlink( $sql_file );
		}

		$final_zip_size = filesize( $zip_file );

		// 3. Tải file ZIP lên Google Drive
		if ( is_callable( $progress_callback ) ) {
			call_user_func( $progress_callback, 'uploading', 50, __( 'Đang kết nối và tải file lên Google Drive...', 'hibackup' ) );
		}

		$gdrive = new HiBackup_GDrive();
		$upload_res = $gdrive->upload_file( $zip_file, $backup_name . '.zip', function( $uploaded_bytes, $total_bytes ) use ( $progress_callback ) {
			if ( is_callable( $progress_callback ) && $total_bytes > 0 ) {
				$percent = 50 + intval( ( $uploaded_bytes / $total_bytes ) * 45 ); // 50% -> 95%
				$msg = sprintf(
					__( 'Đang tải lên Google Drive: %s / %s (%d%%)...', 'hibackup' ),
					size_format( $uploaded_bytes ),
					size_format( $total_bytes ),
					intval( ( $uploaded_bytes / $total_bytes ) * 100 )
				);
				call_user_func( $progress_callback, 'uploading_chunk', $percent, $msg );
			}
		} );

		if ( is_wp_error( $upload_res ) ) {
			// Xóa file ZIP nếu upload thất bại
			@unlink( $zip_file );
			return $upload_res;
		}

		// 4. QUAN TRỌNG: Xóa vĩnh viễn file ZIP trên hosting ngay sau khi upload thành công
		if ( file_exists( $zip_file ) ) {
			@unlink( $zip_file );
		}

		if ( is_callable( $progress_callback ) ) {
			call_user_func( $progress_callback, 'complete', 100, __( 'Đã xóa file tạm trên hosting và hoàn tất sao lưu lên Google Drive!', 'hibackup' ) );
		}

		$history_type = 'Custom';
		if ( ! empty( $options['include_db'] ) && ! empty( $options['include_full_source'] ) ) {
			$history_type = 'Full (Full Source + DB)';
		} elseif ( ! empty( $options['include_full_source'] ) ) {
			$history_type = 'Full Source';
		} elseif ( ! empty( $options['include_db'] ) && ! empty( $options['include_plugins'] ) ) {
			$history_type = 'Full (Source + DB)';
		} elseif ( ! empty( $options['include_db'] ) ) {
			$history_type = 'Database Only';
		} elseif ( $has_files ) {
			$history_type = 'Source Files';
		}

		// 5. Lưu lịch sử sao lưu
		$history_item = array(
			'id'            => uniqid( 'bk_' ),
			'timestamp'     => time(),
			'filename'      => $backup_name . '.zip',
			'file_size'     => size_format( $final_zip_size ),
			'drive_file_id' => $upload_res['id'] ?? '',
			'drive_link'    => ! empty( $upload_res['id'] ) ? 'https://drive.google.com/file/d/' . $upload_res['id'] . '/view' : '',
			'type'          => $history_type,
			'status'        => 'success',
		);

		$history = get_option( 'hibackup_history', array() );
		array_unshift( $history, $history_item );
		// Giới hạn lưu 50 logs gần nhất
		$history = array_slice( $history, 0, 50 );
		update_option( 'hibackup_history', $history );

		return $history_item;
	}
}
