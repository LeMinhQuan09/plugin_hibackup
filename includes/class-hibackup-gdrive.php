<?php
/**
 * HiBackup Google Drive API Client (v3)
 * Xử lý xác thực OAuth2 / Service Account và tải file qua Resumable Upload Session
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HiBackup_GDrive {

	private $auth_type;
	private $client_id;
	private $client_secret;
	private $refresh_token;
	private $service_account_json;
	private $folder_id;
	private $access_token = null;

	public function __construct() {
		$options = get_option( 'hibackup_settings', array() );
		$this->auth_type            = ! empty( $options['gdrive_auth_type'] ) ? $options['gdrive_auth_type'] : 'oauth2';
		$this->client_id            = ! empty( $options['gdrive_client_id'] ) ? trim( $options['gdrive_client_id'] ) : '';
		$this->client_secret        = ! empty( $options['gdrive_client_secret'] ) ? trim( $options['gdrive_client_secret'] ) : '';
		$this->refresh_token        = ! empty( $options['gdrive_refresh_token'] ) ? trim( $options['gdrive_refresh_token'] ) : '';
		$this->service_account_json = ! empty( $options['gdrive_service_account_json'] ) ? trim( $options['gdrive_service_account_json'] ) : '';
		$this->folder_id            = ! empty( $options['gdrive_folder_id'] ) ? trim( $options['gdrive_folder_id'] ) : '';
	}

	/**
	 * Lấy Access Token từ Google
	 */
	public function get_access_token() {
		if ( ! empty( $this->access_token ) ) {
			return $this->access_token;
		}

		// Kiểm tra cache transient
		$cached_token = get_transient( 'hibackup_gdrive_access_token' );
		if ( ! empty( $cached_token ) ) {
			$this->access_token = $cached_token;
			return $cached_token;
		}

		if ( 'service_account' === $this->auth_type ) {
			return $this->get_service_account_token();
		} else {
			return $this->get_oauth2_token();
		}
	}

	/**
	 * Lấy Access Token bằng Refresh Token (OAuth 2.0)
	 */
	private function get_oauth2_token() {
		if ( empty( $this->client_id ) || empty( $this->client_secret ) || empty( $this->refresh_token ) ) {
			return new WP_Error( 'missing_credentials', __( 'Chưa cấu hình đầy đủ Client ID, Client Secret hoặc Refresh Token.', 'hibackup' ) );
		}

		$response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
			'timeout' => 30,
			'body'    => array(
				'client_id'     => $this->client_id,
				'client_secret' => $this->client_secret,
				'refresh_token' => $this->refresh_token,
				'grant_type'    => 'refresh_token',
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			$msg = ! empty( $body['error_description'] ) ? $body['error_description'] : ( ! empty( $body['error'] ) ? $body['error'] : 'Lỗi lấy token' );
			return new WP_Error( 'token_error', sprintf( __( 'Lỗi từ Google OAuth: %s', 'hibackup' ), $msg ) );
		}

		$this->access_token = $body['access_token'];
		$expires_in = ! empty( $body['expires_in'] ) ? intval( $body['expires_in'] ) - 120 : 3400;
		set_transient( 'hibackup_gdrive_access_token', $this->access_token, $expires_in );

		return $this->access_token;
	}

	/**
	 * Lấy Access Token bằng Service Account JSON Key
	 */
	private function get_service_account_token() {
		if ( empty( $this->service_account_json ) ) {
			return new WP_Error( 'missing_sa_json', __( 'Chưa dán nội dung Service Account JSON Key.', 'hibackup' ) );
		}

		$sa_data = json_decode( $this->service_account_json, true );
		if ( empty( $sa_data['client_email'] ) || empty( $sa_data['private_key'] ) ) {
			return new WP_Error( 'invalid_sa_json', __( 'Dữ liệu Service Account JSON không hợp lệ (thiếu client_email hoặc private_key).', 'hibackup' ) );
		}

		$now = time();
		$header = base64_encode( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$claim = base64_encode( wp_json_encode( array(
			'iss'   => $sa_data['client_email'],
			'scope' => 'https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/drive',
			'aud'   => 'https://oauth2.googleapis.com/token',
			'exp'   => $now + 3600,
			'iat'   => $now,
		) ) );

		$header = str_replace( array( '+', '/', '=' ), array( '-', '_', '' ), $header );
		$claim  = str_replace( array( '+', '/', '=' ), array( '-', '_', '' ), $claim );
		$signature_input = $header . '.' . $claim;

		$signature = '';
		$binary_signature = '';
		$pkey = openssl_pkey_get_private( $sa_data['private_key'] );
		if ( ! $pkey ) {
			return new WP_Error( 'invalid_pkey', __( 'Không thể nạp Private Key từ Service Account. Vui lòng kiểm tra lại chuỗi khóa.', 'hibackup' ) );
		}

		openssl_sign( $signature_input, $binary_signature, $pkey, OPENSSL_ALGO_SHA256 );
		$signature = str_replace( array( '+', '/', '=' ), array( '-', '_', '' ), base64_encode( $binary_signature ) );

		$jwt = $signature_input . '.' . $signature;

		$response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
			'timeout' => 30,
			'body'    => array(
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $jwt,
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			$msg = ! empty( $body['error_description'] ) ? $body['error_description'] : ( ! empty( $body['error'] ) ? $body['error'] : 'Lỗi lấy token' );
			return new WP_Error( 'token_error', sprintf( __( 'Lỗi cấp quyền Service Account: %s', 'hibackup' ), $msg ) );
		}

		$this->access_token = $body['access_token'];
		$expires_in = ! empty( $body['expires_in'] ) ? intval( $body['expires_in'] ) - 120 : 3400;
		set_transient( 'hibackup_gdrive_access_token', $this->access_token, $expires_in );

		return $this->access_token;
	}

	/**
	 * Kiểm tra kết nối tới Google Drive
	 */
	public function test_connection() {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		// Gọi lấy thông tin Drive / About
		$response = wp_remote_get( 'https://www.googleapis.com/drive/v3/about?fields=user,storageQuota', array(
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( isset( $body['error'] ) ) {
			return new WP_Error( 'api_error', $body['error']['message'] ?? 'Lỗi không xác định từ Google Drive' );
		}

		// Nếu có chỉ định Folder ID, kiểm tra thư mục đó
		if ( ! empty( $this->folder_id ) ) {
			$folder_res = wp_remote_get( 'https://www.googleapis.com/drive/v3/files/' . urlencode( $this->folder_id ) . '?fields=id,name,mimeType,capabilities&supportsAllDrives=true', array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
				),
			) );

			if ( ! is_wp_error( $folder_res ) ) {
				$folder_data = json_decode( wp_remote_retrieve_body( $folder_res ), true );
				if ( isset( $folder_data['error'] ) ) {
					return new WP_Error( 'folder_error', sprintf( __( 'Không tìm thấy thư mục Google Drive (Folder ID: %s). Hãy kiểm tra lại ID hoặc quyền truy cập thư mục.', 'hibackup' ), $this->folder_id ) );
				}
				$body['target_folder'] = $folder_data['name'] ?? $this->folder_id;
			}
		}

		return $body;
	}

	/**
	 * Tải file lên Google Drive qua Resumable Upload
	 * Tránh quá tải RAM máy chủ và không bị timeout với file dung lượng lớn
	 *
	 * @param string $file_path Đường dẫn file cần upload
	 * @param string $file_name Tên file trên Google Drive
	 * @param callable $progress_callback Callback báo tiến độ
	 * @return array|WP_Error Thông tin file trên Drive hoặc WP_Error
	 */
	public function upload_file( $file_path, $file_name = '', $progress_callback = null ) {
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'file_not_found', __( 'File cần upload không tồn tại trên hosting.', 'hibackup' ) );
		}

		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$file_size = filesize( $file_path );
		if ( empty( $file_name ) ) {
			$file_name = basename( $file_path );
		}

		// 1. Khởi tạo phiên Resumable Upload
		$metadata = array(
			'name'     => $file_name,
			'mimeType' => 'application/zip',
		);
		if ( ! empty( $this->folder_id ) ) {
			$metadata['parents'] = array( $this->folder_id );
		}

		$init_res = wp_remote_post( 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&supportsAllDrives=true', array(
			'timeout' => 60,
			'headers' => array(
				'Authorization'        => 'Bearer ' . $token,
				'Content-Type'         => 'application/json; charset=UTF-8',
				'X-Upload-Content-Type'=> 'application/zip',
				'X-Upload-Content-Length' => (string) $file_size,
			),
			'body' => wp_json_encode( $metadata ),
		) );

		if ( is_wp_error( $init_res ) ) {
			return $init_res;
		}

		$response_code = wp_remote_retrieve_response_code( $init_res );
		$headers = wp_remote_retrieve_headers( $init_res );
		$upload_url = $headers['location'] ?? '';

		if ( $response_code !== 200 || empty( $upload_url ) ) {
			$err_body = wp_remote_retrieve_body( $init_res );
			return new WP_Error( 'resumable_init_failed', sprintf( __( 'Khởi tạo upload session thất bại (Mã lỗi: %d): %s', 'hibackup' ), $response_code, $err_body ) );
		}

		// 2. Upload file theo từng chunk (mỗi chunk 2MB)
		$chunk_size = 2 * 1024 * 1024; // 2MB
		$handle = fopen( $file_path, 'rb' );
		if ( ! $handle ) {
			return new WP_Error( 'file_open_failed', __( 'Không thể mở đọc file backup.', 'hibackup' ) );
		}

		$byte_offset = 0;
		$upload_result = null;

		while ( ! feof( $handle ) && $byte_offset < $file_size ) {
			$chunk = fread( $handle, $chunk_size );
			$chunk_length = strlen( $chunk );
			$last_byte = $byte_offset + $chunk_length - 1;

			$ch = curl_init();
			curl_setopt( $ch, CURLOPT_URL, $upload_url );
			curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'PUT' );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $chunk );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_TIMEOUT, 120 );
			curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
			curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );

			$wp_ca = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
			if ( file_exists( $wp_ca ) ) {
				curl_setopt( $ch, CURLOPT_CAINFO, $wp_ca );
			}

			curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
				'Content-Length: ' . $chunk_length,
				'Content-Range: bytes ' . $byte_offset . '-' . $last_byte . '/' . $file_size,
				'Content-Type: application/zip',
			) );

			$response_body = curl_exec( $ch );
			$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$curl_err = curl_error( $ch );
			curl_close( $ch );

			// Dự phòng: nếu cURL gặp lỗi môi trường, dùng WordPress HTTP API
			if ( ! empty( $curl_err ) ) {
				$wp_req = wp_remote_request( $upload_url, array(
					'method'    => 'PUT',
					'timeout'   => 120,
					'sslverify' => false,
					'headers'   => array(
						'Content-Length' => (string) $chunk_length,
						'Content-Range'  => 'bytes ' . $byte_offset . '-' . $last_byte . '/' . $file_size,
						'Content-Type'   => 'application/zip',
					),
					'body'      => $chunk,
				) );

				if ( ! is_wp_error( $wp_req ) ) {
					$http_code = wp_remote_retrieve_response_code( $wp_req );
					$response_body = wp_remote_retrieve_body( $wp_req );
					$curl_err = '';
				}
			}

			if ( ! empty( $curl_err ) ) {
				fclose( $handle );
				return new WP_Error( 'curl_chunk_error', sprintf( __( 'Lỗi tải chunk lên Google Drive: %s', 'hibackup' ), $curl_err ) );
			}

			$byte_offset += $chunk_length;

			if ( is_callable( $progress_callback ) ) {
				call_user_func( $progress_callback, $byte_offset, $file_size );
			}

			// 308 Resume Incomplete là bình thường cho chunk tiếp theo
			if ( 200 === $http_code || 201 === $http_code ) {
				$upload_result = json_decode( $response_body, true );
				break;
			} elseif ( 308 !== $http_code ) {
				fclose( $handle );
				return new WP_Error( 'upload_chunk_failed', sprintf( __( 'Lỗi tải lên tại byte %d (HTTP %d): %s', 'hibackup' ), $byte_offset, $http_code, $response_body ) );
			}
		}

		fclose( $handle );

		if ( empty( $upload_result['id'] ) ) {
			return new WP_Error( 'upload_incomplete', __( 'Tải lên không trả về ID file hợp lệ từ Google Drive.', 'hibackup' ) );
		}

		return $upload_result;
	}

	/**
	 * Xóa một file trên Google Drive theo ID
	 */
	public function delete_file( $file_id ) {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_request( 'https://www.googleapis.com/drive/v3/files/' . urlencode( $file_id ), array(
			'method'  => 'DELETE',
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		return ( 204 === $code || 200 === $code );
	}
}
