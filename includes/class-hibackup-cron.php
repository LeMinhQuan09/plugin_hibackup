<?php
/**
 * HiBackup Cron Scheduler
 * Quản lý lịch trình tự động sao lưu nền và dọn dẹp các bản backup cũ trên Google Drive.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HiBackup_Cron {

	const CRON_HOOK = 'hibackup_scheduled_backup_event';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_intervals' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'execute_scheduled_backup' ) );
	}

	/**
	 * Bổ sung các chu kỳ cron phong phú
	 */
	public static function add_cron_intervals( $schedules ) {
		$schedules['every_2_hours'] = array(
			'interval' => 2 * HOUR_IN_SECONDS,
			'display'  => __( 'Mỗi 2 giờ', 'hibackup' ),
		);
		$schedules['every_4_hours'] = array(
			'interval' => 4 * HOUR_IN_SECONDS,
			'display'  => __( 'Mỗi 4 giờ', 'hibackup' ),
		);
		$schedules['every_6_hours'] = array(
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'Mỗi 6 giờ (4 lần/ngày)', 'hibackup' ),
		);
		$schedules['every_2_days'] = array(
			'interval' => 2 * DAY_IN_SECONDS,
			'display'  => __( '2 ngày một lần (48 giờ)', 'hibackup' ),
		);
		$schedules['every_3_days'] = array(
			'interval' => 3 * DAY_IN_SECONDS,
			'display'  => __( '3 ngày một lần', 'hibackup' ),
		);
		$schedules['weekly'] = array(
			'interval' => 7 * DAY_IN_SECONDS,
			'display'  => __( 'Một lần mỗi tuần', 'hibackup' ),
		);
		$schedules['biweekly'] = array(
			'interval' => 14 * DAY_IN_SECONDS,
			'display'  => __( '2 tuần một lần', 'hibackup' ),
		);
		$schedules['monthly'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Một lần mỗi tháng', 'hibackup' ),
		);
		return $schedules;
	}

	/**
	 * Cập nhật lịch trình cron theo cài đặt
	 */
	public static function update_schedule( $frequency, $hour = 2 ) {
		wp_clear_scheduled_hook( self::CRON_HOOK );

		if ( 'none' === $frequency ) {
			return;
		}

		$valid_intervals = array(
			'hourly',
			'every_2_hours',
			'every_4_hours',
			'every_6_hours',
			'twicedaily',
			'daily',
			'every_2_days',
			'every_3_days',
			'weekly',
			'biweekly',
			'monthly',
		);

		if ( in_array( $frequency, $valid_intervals, true ) ) {
			$hour = max( 0, min( 23, intval( $hour ) ) );

			$short_intervals = array(
				'hourly'        => HOUR_IN_SECONDS,
				'every_2_hours' => 2 * HOUR_IN_SECONDS,
				'every_4_hours' => 4 * HOUR_IN_SECONDS,
				'every_6_hours' => 6 * HOUR_IN_SECONDS,
				'twicedaily'    => 12 * HOUR_IN_SECONDS,
			);

			// Nếu là chu kỳ ngắn (theo giờ): Lần chạy đầu tiên sẽ sau đúng chu kỳ đã chọn kể từ hiện tại
			if ( isset( $short_intervals[ $frequency ] ) ) {
				$first_run = time() + $short_intervals[ $frequency ];
			} else {
				// Với chu kỳ ngày/tuần/tháng: Đặt đúng vào khung giờ được chọn theo Múi giờ của website
				$tz = wp_timezone();
				$now_local = new DateTime( 'now', $tz );
				$target_local = new DateTime( sprintf( 'today %02d:00:00', $hour ), $tz );

				// Nếu giờ hôm nay đã qua thì lên lịch cho ngày mai
				if ( $target_local <= $now_local ) {
					$target_local->modify( '+1 day' );
				}

				// Lấy Unix timestamp UTC để nạp vào wp_schedule_event
				$first_run = $target_local->getTimestamp();
			}

			wp_schedule_event( $first_run, $frequency, self::CRON_HOOK );
		}
	}

	/**
	 * Thực thi sao lưu tự động khi cron kích hoạt
	 */
	public static function execute_scheduled_backup() {
		$options = get_option( 'hibackup_settings', array() );

		// Tạo một phiên sao lưu hoàn chỉnh
		$orchestrator = new HiBackup();
		$result = $orchestrator->run_full_backup( array(
			'include_db'      => ! empty( $options['backup_db'] ),
			'include_plugins' => ! empty( $options['backup_plugins'] ),
			'include_themes'  => ! empty( $options['backup_themes'] ),
			'include_uploads' => ! empty( $options['backup_uploads'] ),
			'include_config'  => ! empty( $options['backup_config'] ),
			'include_core'    => ! empty( $options['backup_core'] ),
		) );

		// Tự động xoay vòng bản sao lưu trên Drive (xóa bản cũ nếu vượt quá giới hạn)
		$retention_count = ! empty( $options['retention_count'] ) ? intval( $options['retention_count'] ) : 5;
		if ( $retention_count > 0 ) {
			self::prune_old_backups( $retention_count );
		}

		return $result;
	}

	/**
	 * Xoay vòng bản sao lưu cũ trên Google Drive
	 */
	private static function prune_old_backups( $keep_count ) {
		$logs = get_option( 'hibackup_history', array() );
		if ( count( $logs ) > $keep_count ) {
			$gdrive = new HiBackup_GDrive();
			// Sắp xếp theo ngày giảm dần
			usort( $logs, function( $a, $b ) {
				return $b['timestamp'] - $a['timestamp'];
			} );

			$to_keep = array_slice( $logs, 0, $keep_count );
			$to_delete = array_slice( $logs, $keep_count );

			foreach ( $to_delete as $item ) {
				if ( ! empty( $item['drive_file_id'] ) ) {
					$gdrive->delete_file( $item['drive_file_id'] );
				}
			}

			update_option( 'hibackup_history', $to_keep );
		}
	}
}
