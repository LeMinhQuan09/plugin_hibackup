<?php
/**
 * HiBackup Database Dumper
 * Xuất dữ liệu Database WordPress ra file SQL sạch, an toàn, hỗ trợ chunking tránh tràn bộ nhớ.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HiBackup_DB {

	/**
	 * Xuất database ra file .sql trong thư mục chỉ định
	 *
	 * @param string $output_file Đường dẫn file SQL đầu ra
	 * @param bool $wp_tables_only Chỉ backup bảng có tiền tố WordPress (mặc định true)
	 * @return string|WP_Error Đường dẫn file SQL đã tạo hoặc WP_Error
	 */
	public static function dump( $output_file, $wp_tables_only = true ) {
		global $wpdb;

		// Tăng giới hạn thời gian thực thi và bộ nhớ
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		$handle = fopen( $output_file, 'w+' );
		if ( ! $handle ) {
			return new WP_Error( 'file_create_failed', __( 'Không thể tạo file SQL tạm trên hosting.', 'hibackup' ) );
		}

		// Viết Header file SQL
		$header  = "-- HiBackup WordPress Database Dump\n";
		$header .= "-- Generated at: " . gmdate( 'Y-m-d H:i:s' ) . " GMT\n";
		$header .= "-- Host: " . DB_HOST . "\n";
		$header .= "-- Database: " . DB_NAME . "\n";
		$header .= "-- WordPress Version: " . get_bloginfo( 'version' ) . "\n";
		$header .= "-- --------------------------------------------------------\n\n";
		$header .= "SET FOREIGN_KEY_CHECKS=0;\n";
		$header .= "SET SQL_MODE=\"NO_AUTO_VALUE_ON_ZERO\";\n";
		$header .= "SET time_zone = \"+00:00\";\n";
		$header .= "SET NAMES " . DB_CHARSET . ";\n\n";
		fwrite( $handle, $header );

		// Lấy danh sách các bảng
		$tables = $wpdb->get_col( 'SHOW TABLES' );
		if ( empty( $tables ) ) {
			fclose( $handle );
			return new WP_Error( 'no_tables', __( 'Không tìm thấy bảng nào trong database.', 'hibackup' ) );
		}

		$prefix = $wpdb->prefix;

		foreach ( $tables as $table ) {
			// Bỏ qua bảng không thuộc tiền tố nếu chọn chỉ backup WP tables
			if ( $wp_tables_only && 0 !== strpos( $table, $prefix ) ) {
				continue;
			}

			// 1. Cấu trúc bảng
			fwrite( $handle, "\n-- --------------------------------------------------------\n" );
			fwrite( $handle, "-- Table structure for table `{$table}`\n" );
			fwrite( $handle, "-- --------------------------------------------------------\n" );
			fwrite( $handle, "DROP TABLE IF EXISTS `{$table}`;\n" );

			$create_table = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
			if ( ! empty( $create_table[1] ) ) {
				fwrite( $handle, $create_table[1] . ";\n\n" );
			}

			// 2. Dữ liệu bảng (Phân trang đọc theo batch để tránh tràn RAM)
			$total_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
			if ( $total_rows > 0 ) {
				fwrite( $handle, "-- Dumping data for table `{$table}`\n" );

				$batch_size = 500;
				$offset = 0;

				while ( $offset < $total_rows ) {
					$rows = $wpdb->get_results( "SELECT * FROM `{$table}` LIMIT {$offset}, {$batch_size}", ARRAY_A );
					if ( empty( $rows ) ) {
						break;
					}

					$values = array();
					foreach ( $rows as $row ) {
						$escaped_row = array();
						foreach ( $row as $val ) {
							if ( is_null( $val ) ) {
								$escaped_row[] = 'NULL';
							} else {
								$escaped_row[] = "'" . esc_sql( $val ) . "'";
							}
						}
						$values[] = '(' . implode( ', ', $escaped_row ) . ')';
					}

					if ( ! empty( $values ) ) {
						$insert_query = "INSERT INTO `{$table}` VALUES \n" . implode( ",\n", $values ) . ";\n";
						fwrite( $handle, $insert_query );
					}

					$offset += $batch_size;
				}
				fwrite( $handle, "\n" );
			}
		}

		// Footer file SQL
		fwrite( $handle, "\nSET FOREIGN_KEY_CHECKS=1;\n" );
		fwrite( $handle, "-- End of HiBackup Database Dump\n" );

		fclose( $handle );

		return $output_file;
	}
}
