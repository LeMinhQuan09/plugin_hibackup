<?php
/**
 * HiBackup Admin Settings UI and AJAX Handlers
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HiBackup_Admin {

	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_oauth_callback' ) );

		// Đổi "Visit plugin site" thành "View details" và thêm link Cài đặt nhanh trong danh sách Plugins
		add_filter( 'plugin_row_meta', array( $this, 'custom_plugin_row_meta' ), 10, 4 );
		add_filter( 'plugin_action_links_' . plugin_basename( HIBACKUP_PLUGIN_FILE ), array( $this, 'add_action_links' ) );

		// AJAX endpoints
		add_action( 'wp_ajax_hibackup_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_hibackup_run_backup', array( $this, 'ajax_run_backup' ) );
		add_action( 'wp_ajax_hibackup_delete_history', array( $this, 'ajax_delete_history' ) );
	}

	/**
	 * Thêm link Cài đặt nhanh vào danh sách Plugins
	 */
	public function add_action_links( $links ) {
		$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=hibackup' ) ) . '">' . esc_html__( 'Cài đặt', 'hibackup' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Đảm bảo hiển thị tác giả "By hiseo" kèm link, và loại bỏ link "Visit plugin site" ban đầu
	 */
	public function custom_plugin_row_meta( $plugin_meta, $plugin_file, $plugin_data, $status ) {
		if ( plugin_basename( HIBACKUP_PLUGIN_FILE ) === $plugin_file ) {
			$author_url  = ! empty( $plugin_data['AuthorURI'] ) ? $plugin_data['AuthorURI'] : 'https://hiseo.vn';
			$author_name = ! empty( $plugin_data['Author'] ) ? $plugin_data['Author'] : 'hiseo';

			$new_meta   = array();
			$has_author = false;

			foreach ( $plugin_meta as $meta_link ) {
				// Loại bỏ link "Visit plugin site" (hoặc link view details ban đầu dẫn về hiseo.vn)
				if (
					false !== stripos( $meta_link, 'Visit plugin site' ) ||
					false !== stripos( $meta_link, 'trang của gói mở rộng' ) ||
					( false !== stripos( $meta_link, 'View details' ) && false !== strpos( $meta_link, 'hiseo.vn' ) )
				) {
					continue;
				}

				// Giữ và chuẩn hóa mục Author "By hiseo"
				if (
					false !== stripos( $meta_link, 'By ' ) ||
					false !== stripos( $meta_link, 'Bởi ' ) ||
					false !== stripos( $meta_link, $author_name )
				) {
					$has_author = true;
					$new_meta[] = sprintf(
						/* translators: %s: Author link */
						__( 'By %s', 'hibackup' ),
						sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( $author_url ), esc_html( $author_name ) )
					);
					continue;
				}

				$new_meta[] = $meta_link;
			}

			// Nếu danh sách chưa có Author thì chèn ngay sau Version
			if ( ! $has_author ) {
				$author_item = sprintf(
					__( 'By %s', 'hibackup' ),
					sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( $author_url ), esc_html( $author_name ) )
				);
				array_splice( $new_meta, 1, 0, array( $author_item ) );
			}

			return $new_meta;
		}
		return $plugin_meta;
	}

	public function register_menu() {
		add_menu_page(
			__( 'HiBackup – Sao lưu Google Drive', 'hibackup' ),
			__( 'HiBackup', 'hibackup' ),
			'manage_options',
			'hibackup',
			array( $this, 'render_admin_page' ),
			'dashicons-cloud-upload',
			85
		);
	}

	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_hibackup' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'hibackup-admin-css', HIBACKUP_PLUGIN_URL . 'assets/css/admin.css', array(), filemtime( HIBACKUP_PLUGIN_DIR . 'assets/css/admin.css' ) );
		wp_enqueue_script( 'hibackup-admin-js', HIBACKUP_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), filemtime( HIBACKUP_PLUGIN_DIR . 'assets/js/admin.js' ), true );

		wp_localize_script( 'hibackup-admin-js', 'hibackupData', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'hibackup_admin_nonce' ),
			'strings' => array(
				'testingConnection' => __( 'Đang kiểm tra kết nối tới Google Drive...', 'hibackup' ),
				'startingBackup'    => __( 'Đang khởi động tiến trình sao lưu...', 'hibackup' ),
				'confirmDelete'     => __( 'Bạn có chắc chắn muốn xóa bản sao lưu này khỏi lịch sử và Google Drive?', 'hibackup' ),
			),
		) );
	}

	/**
	 * Xử lý lưu cài đặt từ form (Lưu theo từng tab riêng biệt, không ghi đè lẫn nhau)
	 */
	public function handle_save_settings() {
		if ( ! isset( $_POST['hibackup_save_settings_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['hibackup_save_settings_nonce'] ) ), 'hibackup_save_settings' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$current = get_option( 'hibackup_settings', array() );
		$active_tab = sanitize_text_field( wp_unslash( $_POST['active_tab'] ?? 'gdrive' ) );

		if ( 'gdrive' === $active_tab ) {
			$auth_type = sanitize_text_field( wp_unslash( $_POST['gdrive_auth_type'] ?? 'oauth2' ) );
			$sa_json   = trim( wp_unslash( $_POST['gdrive_service_account_json'] ?? '' ) );

			// Tự động nhận diện Service Account nếu có dán JSON key
			if ( ! empty( $sa_json ) ) {
				$auth_type = 'service_account';
			}

			$current['gdrive_auth_type']            = $auth_type;
			$current['gdrive_client_id']            = sanitize_text_field( wp_unslash( $_POST['gdrive_client_id'] ?? '' ) );
			$current['gdrive_client_secret']        = sanitize_text_field( wp_unslash( $_POST['gdrive_client_secret'] ?? '' ) );
			$current['gdrive_refresh_token']        = sanitize_text_field( wp_unslash( $_POST['gdrive_refresh_token'] ?? '' ) );
			$current['gdrive_service_account_json'] = $sa_json;
			$current['gdrive_folder_id']            = sanitize_text_field( wp_unslash( $_POST['gdrive_folder_id'] ?? '' ) );
		} elseif ( 'options' === $active_tab ) {
			$current['backup_db']         = ! empty( $_POST['backup_db'] ) ? 1 : 0;
			$current['backup_plugins']    = ! empty( $_POST['backup_plugins'] ) ? 1 : 0;
			$current['backup_themes']     = ! empty( $_POST['backup_themes'] ) ? 1 : 0;
			$current['backup_uploads']    = ! empty( $_POST['backup_uploads'] ) ? 1 : 0;
			$current['backup_config']     = ! empty( $_POST['backup_config'] ) ? 1 : 0;
			$current['backup_core']       = ! empty( $_POST['backup_core'] ) ? 1 : 0;
			$current['auto_delete_local'] = 1;

			$frequency = sanitize_text_field( wp_unslash( $_POST['cron_frequency'] ?? 'none' ) );
			$cron_hour  = max( 0, min( 23, intval( $_POST['cron_hour'] ?? 2 ) ) );
			$current['cron_frequency']  = $frequency;
			$current['cron_hour']       = $cron_hour;
			$current['retention_count'] = max( 1, intval( $_POST['retention_count'] ?? 5 ) );

			$current['github_repo_url'] = esc_url_raw( trim( wp_unslash( $_POST['github_repo_url'] ?? '' ) ) );
			$current['github_token']    = sanitize_text_field( trim( wp_unslash( $_POST['github_token'] ?? '' ) ) );

			HiBackup_Cron::update_schedule( $frequency, $cron_hour );
		}

		update_option( 'hibackup_settings', $current );

		// Xóa transient token để làm mới xác thực
		delete_transient( 'hibackup_gdrive_access_token' );

		wp_safe_redirect( add_query_arg( array( 'page' => 'hibackup', 'tab' => $active_tab, 'status' => 'saved' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Xử lý OAuth 2.0 Callback từ Google
	 */
	public function handle_oauth_callback() {
		if ( empty( $_GET['code'] ) ) {
			return;
		}

		// Kiểm tra nếu là phản hồi từ Google OAuth cho HiBackup
		$is_hibackup = ( isset( $_GET['state'] ) && 'hibackup_oauth' === $_GET['state'] ) || ( isset( $_GET['page'] ) && 'hibackup' === $_GET['page'] );
		if ( ! $is_hibackup ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$code = sanitize_text_field( wp_unslash( $_GET['code'] ) );
		$settings = get_option( 'hibackup_settings', array() );
		$client_id = $settings['gdrive_client_id'] ?? '';
		$client_secret = $settings['gdrive_client_secret'] ?? '';
		$redirect_uri = admin_url( 'admin.php' );

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			wp_die( __( 'Thiếu Google Client ID hoặc Client Secret. Vui lòng quay lại cài đặt.', 'hibackup' ) );
		}

		$response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
			'timeout' => 30,
			'body'    => array(
				'code'          => $code,
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'redirect_uri'  => $redirect_uri,
				'grant_type'    => 'authorization_code',
			),
		) );

		if ( is_wp_error( $response ) ) {
			wp_die( sprintf( __( 'Lỗi kết nối OAuth: %s', 'hibackup' ), esc_html( $response->get_error_message() ) ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $body['refresh_token'] ) || ! empty( $body['access_token'] ) ) {
			if ( ! empty( $body['refresh_token'] ) ) {
				$settings['gdrive_refresh_token'] = $body['refresh_token'];
			}
			$settings['gdrive_auth_type'] = 'oauth2';
			update_option( 'hibackup_settings', $settings );
			delete_transient( 'hibackup_gdrive_access_token' );

			wp_safe_redirect( admin_url( 'admin.php?page=hibackup&tab=gdrive&status=oauth_success' ) );
			exit;
		} else {
			$err = $body['error_description'] ?? ( $body['error'] ?? __( 'Lỗi không xác định khi lấy token từ Google', 'hibackup' ) );
			wp_die( sprintf( __( 'Lỗi Google OAuth: %s', 'hibackup' ), esc_html( $err ) ) );
		}
	}

	/**
	 * Render giao diện trang quản trị
	 */
	public function render_admin_page() {
		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'backup_now';
		$settings = get_option( 'hibackup_settings', array() );
		$history = get_option( 'hibackup_history', array() );
		$redirect_uri = admin_url( 'admin.php?page=hibackup&tab=gdrive' );

		?>
		<div class="wrap hibackup-wrap">
			<div class="hibackup-header">
				<div class="hibackup-brand">
					<span class="dashicons dashicons-cloud-upload"></span>
					<h1>HiBackup <small>– Sao lưu WordPress lên Google Drive</small></h1>
				</div>
			</div>

			<?php if ( isset( $_GET['status'] ) && 'saved' === $_GET['status'] ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Cài đặt đã được lưu thành công!', 'hibackup' ); ?></p>
				</div>
			<?php elseif ( isset( $_GET['status'] ) && 'oauth_success' === $_GET['status'] ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><strong>🎉 <?php esc_html_e( 'Chúc mừng! Đã kết nối thành công với tài khoản Google Drive cá nhân của bạn!', 'hibackup' ); ?></strong> <?php esc_html_e( 'Giờ bạn có thể vào tab "Sao lưu ngay" để bắt đầu sao lưu.', 'hibackup' ); ?></p>
				</div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper hibackup-tabs">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=hibackup&tab=backup_now' ) ); ?>" class="nav-tab <?php echo 'backup_now' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-controls-play"></span> <?php esc_html_e( 'Sao lưu ngay', 'hibackup' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=hibackup&tab=gdrive' ) ); ?>" class="nav-tab <?php echo 'gdrive' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-google"></span> <?php esc_html_e( 'Cấu hình Google Drive', 'hibackup' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=hibackup&tab=options' ) ); ?>" class="nav-tab <?php echo 'options' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-admin-settings"></span> <?php esc_html_e( 'Tùy chọn & Lịch trình', 'hibackup' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=hibackup&tab=history' ) ); ?>" class="nav-tab <?php echo 'history' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-backup"></span> <?php esc_html_e( 'Lịch sử sao lưu', 'hibackup' ); ?> (<?php echo count( $history ); ?>)
				</a>
			</nav>

			<div class="hibackup-tab-content">
				<?php
				switch ( $active_tab ) {
					case 'gdrive':
						$this->render_tab_gdrive( $settings, $redirect_uri );
						break;
					case 'options':
						$this->render_tab_options( $settings );
						break;
					case 'history':
						$this->render_tab_history( $history );
						break;
					case 'backup_now':
					default:
						$this->render_tab_backup_now( $settings );
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	private function render_tab_backup_now( $settings ) {
		?>
		<div class="hibackup-card">
			<h2><?php esc_html_e( 'Khởi tạo bản sao lưu ngay lập tức', 'hibackup' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Hệ thống sẽ nén dữ liệu đã chọn, trích xuất Database, đẩy trực tiếp lên thư mục Google Drive của bạn và tự động xóa sạch file nén trên hosting sau khi hoàn tất.', 'hibackup' ); ?>
			</p>

			<div class="hibackup-backup-options-preview">
				<h3><?php esc_html_e( 'Thành phần sẽ sao lưu:', 'hibackup' ); ?></h3>
				<div class="hibackup-checkbox-grid">
					<label style="grid-column: 1 / -1; padding-bottom: 8px; border-bottom: 1px dashed #dcdcde; margin-bottom: 4px; font-weight: 600;">
						<input type="checkbox" id="run_toggle_all_components"> <?php esc_html_e( 'Tất cả (Chọn / Bỏ chọn toàn bộ)', 'hibackup' ); ?>
					</label>
					<label><input type="checkbox" class="run-component-cb" id="run_include_db" value="1" <?php checked( ! empty( $settings['backup_db'] ) ); ?>> <strong>Database (Cơ sở dữ liệu SQL)</strong></label>
					<label><input type="checkbox" class="run-component-cb" id="run_include_plugins" value="1" <?php checked( ! empty( $settings['backup_plugins'] ) ); ?>> Plugins (Thư mục plugin)</label>
					<label><input type="checkbox" class="run-component-cb" id="run_include_themes" value="1" <?php checked( ! empty( $settings['backup_themes'] ) ); ?>> Themes (Giao diện)</label>
					<label><input type="checkbox" class="run-component-cb" id="run_include_uploads" value="1" <?php checked( ! empty( $settings['backup_uploads'] ) ); ?>> Uploads (Ảnh và tệp tải lên)</label>
					<label><input type="checkbox" class="run-component-cb" id="run_include_config" value="1" <?php checked( ! empty( $settings['backup_config'] ) ); ?>> File wp-config.php</label>
					<label><input type="checkbox" class="run-component-cb" id="run_include_core" value="1" <?php checked( ! empty( $settings['backup_core'] ) ); ?>> Core WP (wp-admin, wp-includes)</label>
				</div>
			</div>

			<div class="hibackup-actions-bar">
				<button type="button" id="btn-start-backup" class="button button-primary button-hero hibackup-btn-hero">
					<span class="dashicons dashicons-cloud-upload"></span>
					<span><?php esc_html_e( 'Bắt đầu Sao lưu & Đẩy lên Google Drive', 'hibackup' ); ?></span>
				</button>
			</div>

			<!-- Box tiến trình chạy thời gian thực -->
			<div id="hibackup-progress-box" class="hibackup-progress-box" style="display: none;">
				<div class="hibackup-progress-header">
					<span id="hibackup-progress-spinner" class="spinner is-active"></span>
					<strong id="hibackup-progress-status"><?php esc_html_e( 'Đang khởi tạo...', 'hibackup' ); ?></strong>
					<span id="hibackup-progress-percentage">0%</span>
				</div>
				<div class="hibackup-progress-bar-wrapper">
					<div id="hibackup-progress-bar" class="hibackup-progress-bar" style="width: 0%;"></div>
				</div>
				<div id="hibackup-log-console" class="hibackup-log-console"></div>
			</div>
		</div>
		<?php
	}

	private function render_tab_gdrive( $settings, $redirect_uri ) {
		$has_sa = ! empty( $settings['gdrive_service_account_json'] );
		$auth_type = ! empty( $settings['gdrive_auth_type'] ) ? $settings['gdrive_auth_type'] : ( $has_sa ? 'service_account' : 'oauth2' );
		if ( $has_sa && empty( $settings['gdrive_client_id'] ) ) {
			$auth_type = 'service_account';
		}
		?>
		<div class="hibackup-card">
			<h2><?php esc_html_e( 'Cấu hình kết nối Google Drive API', 'hibackup' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Bạn có thể chọn kết nối qua OAuth 2.0 (Client ID / Secret) hoặc Service Account JSON Key.', 'hibackup' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=hibackup&tab=gdrive' ) ); ?>">
				<?php wp_nonce_field( 'hibackup_save_settings', 'hibackup_save_settings_nonce' ); ?>
				<input type="hidden" name="active_tab" value="gdrive">

				<table class="form-table">
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Phương thức kết nối', 'hibackup' ); ?></label></th>
						<td>
							<label style="margin-right: 20px;">
								<input type="radio" name="gdrive_auth_type" value="oauth2" <?php checked( 'oauth2', $auth_type ); ?> class="hibackup-auth-toggle">
								<strong>Google OAuth 2.0 (Khuyên dùng cho tài khoản cá nhân)</strong>
							</label>
							<label>
								<input type="radio" name="gdrive_auth_type" value="service_account" <?php checked( 'service_account', $auth_type ); ?> class="hibackup-auth-toggle">
								<strong>Service Account JSON (Khuyên dùng cho Google Workspace / Tự động 100%)</strong>
							</label>
						</td>
					</tr>

					<!-- Khối OAuth 2.0 -->
					<tbody id="auth-section-oauth2" style="<?php echo 'oauth2' === $auth_type ? '' : 'display:none;'; ?>">
						<tr>
							<th scope="row"><label><?php esc_html_e( 'URI chuyển hướng hợp lệ (Redirect URI)', 'hibackup' ); ?></label></th>
							<td>
								<?php $oauth_callback_url = admin_url( 'admin.php' ); ?>
								<input type="text" readonly value="<?php echo esc_url( $oauth_callback_url ); ?>" class="large-text code" onclick="this.select();">
								<p class="description">
									<?php esc_html_e( 'Hãy copy chính xác đường link này và dán vào mục "Authorized redirect URIs" trong Google Cloud Console > Credentials > OAuth Client ID.', 'hibackup' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="gdrive_client_id"><?php esc_html_e( 'Google Client ID', 'hibackup' ); ?></label></th>
							<td>
								<input type="text" id="gdrive_client_id" name="gdrive_client_id" value="<?php echo esc_attr( $settings['gdrive_client_id'] ?? '' ); ?>" class="regular-text" placeholder="xxxx-yyyy.apps.googleusercontent.com">
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="gdrive_client_secret"><?php esc_html_e( 'Google Client Secret', 'hibackup' ); ?></label></th>
							<td>
								<input type="password" id="gdrive_client_secret" name="gdrive_client_secret" value="<?php echo esc_attr( $settings['gdrive_client_secret'] ?? '' ); ?>" class="regular-text">
							</td>
						</tr>
						<tr>
							<th scope="row"><label><?php esc_html_e( 'Trạng thái cấp quyền OAuth', 'hibackup' ); ?></label></th>
							<td>
								<?php
								$has_oauth = ! empty( $settings['gdrive_client_id'] ) && ! empty( $settings['gdrive_client_secret'] );
								$is_connected = ! empty( $settings['gdrive_refresh_token'] );
								?>
								<?php if ( $is_connected ) : ?>
									<div style="margin-bottom: 10px;">
										<span class="hibackup-badge-cloud" style="background: #e6f4ea; color: #137333; font-size: 13px; padding: 6px 14px;">
											<span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Đã kết nối thành công với tài khoản Google Drive!', 'hibackup' ); ?>
										</span>
									</div>
								<?php endif; ?>

								<?php if ( $has_oauth ) : ?>
									<?php
									$auth_params = array(
										'client_id'     => trim( $settings['gdrive_client_id'] ),
										'redirect_uri'  => $oauth_callback_url,
										'response_type' => 'code',
										'scope'         => 'https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/drive.file',
										'access_type'   => 'offline',
										'prompt'        => 'consent',
										'state'         => 'hibackup_oauth',
									);
									$auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( $auth_params, '', '&', PHP_QUERY_RFC3986 );
									?>
									<a href="<?php echo esc_url( $auth_url ); ?>" class="button button-primary hibackup-btn-oauth">
										<span class="dashicons dashicons-google"></span>
										<span><?php echo $is_connected ? esc_html__( 'Cập nhật lại quyền truy cập thư mục (Ủy quyền lại)', 'hibackup' ) : esc_html__( '🔗 Đăng nhập & Ủy quyền Google Drive (1-Click)', 'hibackup' ); ?></span>
									</a>
									<p class="description" style="margin-top: 6px;">
										<?php esc_html_e( 'Bấm vào nút trên để Google xác thực tài khoản và tự động lấy Refresh Token.', 'hibackup' ); ?>
									</p>
								<?php else : ?>
									<p class="description" style="color: #d63638;">
										<?php esc_html_e( 'Vui lòng nhập Google Client ID & Client Secret và bấm "Lưu Cài đặt" để hiện nút Đăng nhập kết nối.', 'hibackup' ); ?>
									</p>
								<?php endif; ?>
								<input type="hidden" id="gdrive_refresh_token" name="gdrive_refresh_token" value="<?php echo esc_attr( $settings['gdrive_refresh_token'] ?? '' ); ?>">
							</td>
						</tr>
					</tbody>

					<!-- Khối Service Account -->
					<tbody id="auth-section-sa" style="<?php echo 'service_account' === $auth_type ? '' : 'display:none;'; ?>">
						<tr>
							<th scope="row"><label for="gdrive_service_account_json"><?php esc_html_e( 'Service Account Key (JSON)', 'hibackup' ); ?></label></th>
							<td>
								<textarea id="gdrive_service_account_json" name="gdrive_service_account_json" rows="8" class="large-text code" placeholder='{"type": "service_account", "project_id": "...", ...}'><?php echo esc_textarea( $settings['gdrive_service_account_json'] ?? '' ); ?></textarea>
								<p class="description">
									<?php esc_html_e( 'Mở file JSON key bạn tải từ Google Cloud Console, copy toàn bộ nội dung và dán vào đây.', 'hibackup' ); ?>
									<br><em>* Lưu ý: Hãy chia sẻ quyền Chỉnh sửa (Editor) thư mục Google Drive cho email của Service Account (client_email).</em>
								</p>
							</td>
						</tr>
					</tbody>

					<!-- Cấu hình chung Folder ID -->
					<tr>
						<th scope="row"><label for="gdrive_folder_id"><?php esc_html_e( 'Google Drive Folder ID', 'hibackup' ); ?></label></th>
						<td>
							<input type="text" id="gdrive_folder_id" name="gdrive_folder_id" value="<?php echo esc_attr( $settings['gdrive_folder_id'] ?? '' ); ?>" class="regular-text" placeholder="1A2B3C4D5E...">
							<p class="description">
								<?php esc_html_e( 'ID thư mục Google Drive để lưu các bản backup. Mở thư mục trên Google Drive, copy đoạn mã ở cuối thanh địa chỉ URL: drive.google.com/drive/folders/<b>[FOLDER_ID]</b>', 'hibackup' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<div class="hibackup-form-footer">
					<?php submit_button( __( 'Lưu Cài đặt', 'hibackup' ), 'primary hibackup-btn-save', 'submit', false ); ?>
					<button type="button" id="btn-test-gdrive" class="button button-secondary hibackup-btn-secondary" style="margin-left: 10px;">
						<span class="dashicons dashicons-yes-alt"></span>
						<span><?php esc_html_e( 'Kiểm tra Kết nối', 'hibackup' ); ?></span>
					</button>
				</div>
			</form>

			<div id="test-connection-result" style="margin-top: 15px; display: none;"></div>
		</div>
		<?php
	}

	private function render_tab_options( $settings ) {
		?>
		<div class="hibackup-card">
			<h2><?php esc_html_e( 'Tùy chọn Sao lưu & Tự động hóa', 'hibackup' ); ?></h2>
			<form method="post" action="">
				<?php wp_nonce_field( 'hibackup_save_settings', 'hibackup_save_settings_nonce' ); ?>
				<input type="hidden" name="active_tab" value="options">

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Thành phần mặc định', 'hibackup' ); ?></th>
						<td>
							<fieldset>
								<label style="font-weight: 600; padding-bottom: 6px; display: inline-block; border-bottom: 1px dashed #c3c4c7; margin-bottom: 6px; width: 100%; max-width: 320px;">
									<input type="checkbox" id="hibackup_toggle_all_options"> <?php esc_html_e( 'Tất cả (Chọn / Bỏ chọn toàn bộ)', 'hibackup' ); ?>
								</label><br>
								<label><input type="checkbox" class="hibackup-component-cb" name="backup_db" value="1" <?php checked( ! empty( $settings['backup_db'] ) ); ?>> <strong>Database (Cơ sở dữ liệu)</strong></label><br>
								<label><input type="checkbox" class="hibackup-component-cb" name="backup_plugins" value="1" <?php checked( ! empty( $settings['backup_plugins'] ) ); ?>> Plugins</label><br>
								<label><input type="checkbox" class="hibackup-component-cb" name="backup_themes" value="1" <?php checked( ! empty( $settings['backup_themes'] ) ); ?>> Themes</label><br>
								<label><input type="checkbox" class="hibackup-component-cb" name="backup_uploads" value="1" <?php checked( ! empty( $settings['backup_uploads'] ) ); ?>> Uploads (Hình ảnh, media)</label><br>
								<label><input type="checkbox" class="hibackup-component-cb" name="backup_config" value="1" <?php checked( ! empty( $settings['backup_config'] ) ); ?>> File cấu hình wp-config.php</label><br>
								<label><input type="checkbox" class="hibackup-component-cb" name="backup_core" value="1" <?php checked( ! empty( $settings['backup_core'] ) ); ?>> WordPress Core (wp-admin, wp-includes)</label>
							</fieldset>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Bộ nhớ Hosting', 'hibackup' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="auto_delete_local" value="1" checked disabled>
								<strong><?php esc_html_e( 'Tự động xóa file trên hosting sau khi đẩy lên Google Drive thành công (Cố định).', 'hibackup' ); ?></strong>
							</label>
							<p class="description">
								<?php esc_html_e( 'Đảm bảo hosting của bạn không bao giờ bị đầy dung lượng do các file backup.', 'hibackup' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="cron_frequency"><?php esc_html_e( 'Tự động sao lưu định kỳ', 'hibackup' ); ?></label></th>
						<td>
							<?php
							$frequency = $settings['cron_frequency'] ?? 'none';
							$cron_hour = isset( $settings['cron_hour'] ) ? intval( $settings['cron_hour'] ) : 2;
							$next_run  = wp_next_scheduled( HiBackup_Cron::CRON_HOOK );
							?>
							<select id="cron_frequency" name="cron_frequency" style="min-width: 320px;">
								<option value="none" <?php selected( 'none', $frequency ); ?>><?php esc_html_e( 'Tắt tự động (Chỉ sao lưu thủ công)', 'hibackup' ); ?></option>

								<optgroup label="<?php esc_attr_e( '── Theo ngày / tuần / tháng (Tiêu chuẩn) ──', 'hibackup' ); ?>">
									<option value="daily" <?php selected( 'daily', $frequency ); ?>><?php esc_html_e( 'Hàng ngày (1 lần/ngày)', 'hibackup' ); ?></option>
									<option value="every_2_days" <?php selected( 'every_2_days', $frequency ); ?>><?php esc_html_e( '2 ngày một lần (Mỗi 48 giờ)', 'hibackup' ); ?></option>
									<option value="every_3_days" <?php selected( 'every_3_days', $frequency ); ?>><?php esc_html_e( '3 ngày một lần', 'hibackup' ); ?></option>
									<option value="weekly" <?php selected( 'weekly', $frequency ); ?>><?php esc_html_e( 'Hàng tuần (Mỗi 7 ngày)', 'hibackup' ); ?></option>
									<option value="biweekly" <?php selected( 'biweekly', $frequency ); ?>><?php esc_html_e( '2 tuần một lần (Mỗi 14 ngày)', 'hibackup' ); ?></option>
									<option value="monthly" <?php selected( 'monthly', $frequency ); ?>><?php esc_html_e( 'Hàng tháng (Mỗi 30 ngày)', 'hibackup' ); ?></option>
								</optgroup>

								<optgroup label="<?php esc_attr_e( '── Theo giờ ──', 'hibackup' ); ?>">
									<option value="hourly" <?php selected( 'hourly', $frequency ); ?>><?php esc_html_e( 'Mỗi 1 giờ (Mỗi tiếng 1 lần)', 'hibackup' ); ?></option>
									<option value="every_2_hours" <?php selected( 'every_2_hours', $frequency ); ?>><?php esc_html_e( 'Mỗi 2 giờ', 'hibackup' ); ?></option>
									<option value="every_4_hours" <?php selected( 'every_4_hours', $frequency ); ?>><?php esc_html_e( 'Mỗi 4 giờ', 'hibackup' ); ?></option>
									<option value="every_6_hours" <?php selected( 'every_6_hours', $frequency ); ?>><?php esc_html_e( 'Mỗi 6 giờ (4 lần/ngày)', 'hibackup' ); ?></option>
									<option value="twicedaily" <?php selected( 'twicedaily', $frequency ); ?>><?php esc_html_e( 'Mỗi 12 giờ (2 lần/ngày)', 'hibackup' ); ?></option>
								</optgroup>
							</select>

							<?php if ( $next_run && 'none' !== $frequency ) : ?>
								<?php
								$tz_string = wp_timezone_string();
								$formatted_time = wp_date( 'd/m/Y H:i:s', $next_run );
								$current_site_time = wp_date( 'd/m/Y H:i:s', time() );
								?>
								<p class="description" style="color: #1a73e8; margin-top: 8px; font-size: 13.5px;">
									<span class="dashicons dashicons-clock" style="vertical-align: text-top; font-size: 18px; margin-right: 4px;"></span>
									<?php printf( esc_html__( 'Lần tự động sao lưu tiếp theo dự kiến: %s', 'hibackup' ), '<strong>' . esc_html( $formatted_time ) . '</strong>' ); ?>
									<span style="color: #64748b; margin-left: 6px;">(Múi giờ web: <code><?php echo esc_html( $tz_string ); ?></code> | Giờ hiện tại: <?php echo esc_html( $current_site_time ); ?>)</span>
								</p>
								<?php if ( in_array( $tz_string, array( 'UTC', '+00:00', '0' ), true ) ) : ?>
									<p class="description" style="color: #b45309; background: #fffbeb; border: 1px solid #fde68a; padding: 6px 12px; border-radius: 6px; margin-top: 6px; display: inline-block;">
										<span class="dashicons dashicons-info" style="font-size: 16px; vertical-align: text-top;"></span>
										<?php esc_html_e( 'Múi giờ website hiện đang là UTC+0 (chậm hơn giờ Việt Nam 7 tiếng). Để hiển thị chuẩn giờ Việt Nam, bạn vào Cài đặt > Tổng quan (Settings > General) > chọn Múi giờ là "Hồ Chí Minh" hoặc UTC+7.', 'hibackup' ); ?>
									</p>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="cron_hour"><?php esc_html_e( 'Khung giờ bắt đầu chạy trong ngày', 'hibackup' ); ?></label></th>
						<td>
							<select id="cron_hour" name="cron_hour">
								<?php for ( $h = 0; $h <= 23; $h++ ) : ?>
									<?php
									$label = sprintf( '%02d:00', $h );
									if ( 0 === $h ) {
										$label .= ' (Nửa đêm 00:00)';
									} elseif ( 2 === $h ) {
										$label .= ' (02:00 AM - Ban đêm, Khuyên dùng)';
									} elseif ( 3 === $h ) {
										$label .= ' (03:00 AM)';
									} elseif ( 12 === $h ) {
										$label .= ' (12:00 Trưa)';
									}
									?>
									<option value="<?php echo esc_attr( $h ); ?>" <?php selected( $h, $cron_hour ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endfor; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Áp dụng cho các chu kỳ Hàng ngày, 2 ngày, 3 ngày, Hàng tuần và Hàng tháng. Nên chọn ban đêm (từ 01:00 đến 04:00 AM) để website vắng khách nhất.', 'hibackup' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="retention_count"><?php esc_html_e( 'Giữ lại số bản trên Drive', 'hibackup' ); ?></label></th>
						<td>
							<input type="number" id="retention_count" name="retention_count" value="<?php echo esc_attr( $settings['retention_count'] ?? 5 ); ?>" min="1" max="50" class="small-text">
							<p class="description">
								<?php esc_html_e( 'Khi số bản backup trên Google Drive vượt quá con số này, plugin sẽ tự động xóa các bản cũ nhất để tiết kiệm dung lượng tài khoản Google.', 'hibackup' ); ?>
							</p>
						</td>
					</tr>

					<!-- Tự động cập nhật từ GitHub -->
					<tr>
						<th scope="row"><label for="github_repo_url"><?php esc_html_e( 'Cập nhật từ GitHub Repository', 'hibackup' ); ?></label></th>
						<td>
							<input type="url" id="github_repo_url" name="github_repo_url" value="<?php echo esc_attr( $settings['github_repo_url'] ?? '' ); ?>" class="regular-text" placeholder="https://github.com/your-username/hibackup">
							<p class="description">
								<?php esc_html_e( 'Nhập đường dẫn GitHub repository của plugin. Khi bạn phát hành (Release) bản mới trên GitHub, WordPress trên web Live sẽ tự động nhận diện và hiện nút "Cập nhật ngay"!', 'hibackup' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="github_token"><?php esc_html_e( 'GitHub Token (Nếu là Private Repo)', 'hibackup' ); ?></label></th>
						<td>
							<input type="password" id="github_token" name="github_token" value="<?php echo esc_attr( $settings['github_token'] ?? '' ); ?>" class="regular-text" placeholder="ghp_xxxxxxxxxxxx">
							<p class="description">
								<?php esc_html_e( 'Chỉ cần điền nếu GitHub repository của bạn ở chế độ Riêng tư (Private). Nếu là repository Công khai (Public), bạn có thể để trống.', 'hibackup' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Lưu Tùy chọn', 'hibackup' ), 'primary hibackup-btn-save' ); ?>
			</form>
		</div>
		<?php
	}

	private function render_tab_history( $history ) {
		?>
		<div class="hibackup-card">
			<h2><?php esc_html_e( 'Lịch sử sao lưu trên Google Drive', 'hibackup' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Danh sách các bản sao lưu đã được tải lên Google Drive an toàn.', 'hibackup' ); ?></p>

			<?php if ( empty( $history ) ) : ?>
				<div class="hibackup-empty-state">
					<span class="dashicons dashicons-cloud"></span>
					<p><?php esc_html_e( 'Chưa có bản sao lưu nào. Hãy vào tab "Sao lưu ngay" để thực hiện lần đầu tiên!', 'hibackup' ); ?></p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width: 25%;"><?php esc_html_e( 'Tên File', 'hibackup' ); ?></th>
							<th><?php esc_html_e( 'Thời gian', 'hibackup' ); ?></th>
							<th><?php esc_html_e( 'Dung lượng', 'hibackup' ); ?></th>
							<th><?php esc_html_e( 'Loại', 'hibackup' ); ?></th>
							<th><?php esc_html_e( 'Lưu trữ', 'hibackup' ); ?></th>
							<th><?php esc_html_e( 'Hành động', 'hibackup' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $history as $item ) : ?>
							<tr id="row-<?php echo esc_attr( $item['id'] ); ?>">
								<td><strong><?php echo esc_html( $item['filename'] ); ?></strong></td>
								<td><?php echo esc_html( date_i18n( 'd/m/Y H:i:s', $item['timestamp'] ) ); ?></td>
								<td><span class="badge"><?php echo esc_html( $item['file_size'] ); ?></span></td>
								<td><?php echo esc_html( $item['type'] ); ?></td>
								<td><span class="hibackup-badge-cloud"><span class="dashicons dashicons-google"></span> Google Drive</span></td>
								<td>
									<?php if ( ! empty( $item['drive_link'] ) ) : ?>
										<a href="<?php echo esc_url( $item['drive_link'] ); ?>" target="_blank" class="button button-small button-primary hibackup-btn-small">
											<span class="dashicons dashicons-external"></span> <span><?php esc_html_e( 'Mở Drive', 'hibackup' ); ?></span>
										</a>
									<?php endif; ?>
									<button type="button" class="button button-small button-link-delete btn-delete-history" data-id="<?php echo esc_attr( $item['id'] ); ?>" data-fileid="<?php echo esc_attr( $item['drive_file_id'] ?? '' ); ?>">
										<?php esc_html_e( 'Xóa', 'hibackup' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * AJAX: Kiểm tra kết nối Google Drive
	 */
	public function ajax_test_connection() {
		check_ajax_referer( 'hibackup_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Bạn không có quyền thực hiện thao tác này.', 'hibackup' ) ) );
		}

		// Tự động lưu các thông số nếu được truyền trực tiếp từ form sang
		if ( isset( $_POST['gdrive_service_account_json'] ) || isset( $_POST['gdrive_client_id'] ) ) {
			$current = get_option( 'hibackup_settings', array() );
			$sa_json = trim( wp_unslash( $_POST['gdrive_service_account_json'] ?? '' ) );
			$auth_type = sanitize_text_field( wp_unslash( $_POST['gdrive_auth_type'] ?? 'oauth2' ) );

			if ( ! empty( $sa_json ) ) {
				$auth_type = 'service_account';
			}

			$current['gdrive_auth_type']            = $auth_type;
			$current['gdrive_client_id']            = sanitize_text_field( wp_unslash( $_POST['gdrive_client_id'] ?? '' ) );
			$current['gdrive_client_secret']        = sanitize_text_field( wp_unslash( $_POST['gdrive_client_secret'] ?? '' ) );
			$current['gdrive_refresh_token']        = sanitize_text_field( wp_unslash( $_POST['gdrive_refresh_token'] ?? '' ) );
			$current['gdrive_service_account_json'] = $sa_json;
			$current['gdrive_folder_id']            = sanitize_text_field( wp_unslash( $_POST['gdrive_folder_id'] ?? '' ) );

			update_option( 'hibackup_settings', $current );
			delete_transient( 'hibackup_gdrive_access_token' );
		}

		$gdrive = new HiBackup_GDrive();
		$res = $gdrive->test_connection();

		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		$email = $res['user']['emailAddress'] ?? 'N/A';
		$display_name = $res['user']['displayName'] ?? '';
		$target_folder = $res['target_folder'] ?? '';

		$quota_total = ! empty( $res['storageQuota']['limit'] ) ? size_format( $res['storageQuota']['limit'] ) : 'Không giới hạn';
		$quota_used = ! empty( $res['storageQuota']['usage'] ) ? size_format( $res['storageQuota']['usage'] ) : '0 MB';

		$msg = sprintf(
			__( 'Kết nối Google Drive thành công!<br>• Tài khoản: <strong>%s (%s)</strong><br>• Dung lượng đã dùng: <strong>%s / %s</strong>', 'hibackup' ),
			esc_html( $display_name ),
			esc_html( $email ),
			esc_html( $quota_used ),
			esc_html( $quota_total )
		);

		if ( ! empty( $target_folder ) ) {
			$msg .= sprintf( __( '<br>• Thư mục đích: <strong>%s</strong>', 'hibackup' ), esc_html( $target_folder ) );
		}

		wp_send_json_success( array( 'message' => $msg ) );
	}

	/**
	 * AJAX: Khởi chạy quá trình sao lưu
	 */
	public function ajax_run_backup() {
		check_ajax_referer( 'hibackup_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Bạn không có quyền thực hiện thao tác này.', 'hibackup' ) ) );
		}

		// Nhận các lựa chọn từ checkbox
		$options = array(
			'include_db'      => ! empty( $_POST['include_db'] ),
			'include_plugins' => ! empty( $_POST['include_plugins'] ),
			'include_themes'  => ! empty( $_POST['include_themes'] ),
			'include_uploads' => ! empty( $_POST['include_uploads'] ),
			'include_config'  => ! empty( $_POST['include_config'] ),
			'include_core'    => ! empty( $_POST['include_core'] ),
		);

		$orchestrator = new HiBackup();
		$result = $orchestrator->run_full_backup( $options );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array(
			'message' => __( 'Sao lưu và tải lên Google Drive hoàn tất thành công! Không có file nào chiếm dung lượng hosting.', 'hibackup' ),
			'data'    => $result,
		) );
	}

	/**
	 * AJAX: Xóa một mục lịch sử
	 */
	public function ajax_delete_history() {
		check_ajax_referer( 'hibackup_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Bạn không có quyền.', 'hibackup' ) ) );
		}

		$item_id = sanitize_text_field( $_POST['item_id'] ?? '' );
		$file_id = sanitize_text_field( $_POST['file_id'] ?? '' );

		if ( ! empty( $file_id ) ) {
			$gdrive = new HiBackup_GDrive();
			$gdrive->delete_file( $file_id );
		}

		$history = get_option( 'hibackup_history', array() );
		$new_history = array_filter( $history, function( $item ) use ( $item_id ) {
			return $item['id'] !== $item_id;
		} );
		update_option( 'hibackup_history', array_values( $new_history ) );

		wp_send_json_success( array( 'message' => __( 'Đã xóa bản ghi thành công.', 'hibackup' ) ) );
	}
}
