<?php
/**
 * HiBackup Release Packager (PHP CLI)
 * Tự động kiểm tra cú pháp, sao chép và nén file ZIP phát hành.
 */

if ( php_sapi_name() !== 'cli' ) {
	die( "Chỉ chạy qua dòng lệnh (CLI).\n" );
}

$root_dir  = dirname( __DIR__ );
$dist_dir  = $root_dir . '/dist';
$build_dir = $root_dir . '/build';
$main_file = $root_dir . '/hibackup.php';

echo "==========================================================\n";
echo "  HiBackup - Bắt đầu quá trình Đóng gói Bản phát hành\n";
echo "==========================================================\n";

if ( ! file_exists( $main_file ) ) {
	die( "Lỗi: Không tìm thấy file hibackup.php\n" );
}

// 1. Đọc phiên bản từ hibackup.php
$main_content = file_get_contents( $main_file );
$version = '1.0.0';
if ( preg_match( '/Version:\s*([0-9\.]+)/i', $main_content, $matches ) ) {
	$version = trim( $matches[1] );
}
echo "-> Phiên bản phát hiện: v{$version}\n";

// 2. Kiểm tra cú pháp PHP (Linting)
echo "-> Đang kiểm tra cú pháp toàn bộ file PHP...\n";
$php_files = new RegexIterator(
	new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root_dir ) ),
	'/^.+\.php$/i',
	RecursiveRegexIterator::GET_MATCH
);

$lint_errors = 0;
foreach ( $php_files as $file_match ) {
	$file_path = $file_match[0];
	if ( preg_match( '/(\/|\\\\)(node_modules|build|dist|\.git|scratch|vendor|tests)(\/|\\\\)/i', $file_path ) ) {
		continue;
	}

	exec( 'php -l ' . escapeshellarg( $file_path ) . ' 2>&1', $output, $code );
	if ( $code !== 0 ) {
		echo "   [LỖI CÚ PHÁP] {$file_path}\n";
		$lint_errors++;
	}
	unset( $output );
}

if ( $lint_errors > 0 ) {
	die( "Đóng gói thất bại do có {$lint_errors} file PHP bị lỗi cú pháp!\n" );
}
echo "   [OK] Toàn bộ file PHP đều hợp lệ, không có lỗi cú pháp!\n";

// 3. Chuẩn bị thư mục build
if ( ! is_dir( $dist_dir ) ) {
	mkdir( $dist_dir, 0755, true );
}

$zip_versioned = $dist_dir . "/hibackup-v{$version}.zip";
$zip_clean     = $dist_dir . "/hibackup.zip";

if ( file_exists( $zip_versioned ) ) {
	@unlink( $zip_versioned );
}
if ( file_exists( $zip_clean ) ) {
	@unlink( $zip_clean );
}

// 4. Tạo file ZIP bằng PHP ZipArchive
echo "-> Đang nén file ZIP phát hành bằng ZipArchive...\n";
$zip = new ZipArchive();
if ( $zip->open( $zip_clean, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
	die( "Lỗi: Không thể khởi tạo file ZIP: {$zip_clean}\n" );
}

$items_to_copy = array(
	'hibackup.php',
	'uninstall.php',
	'readme.txt',
	'includes',
	'assets',
);

foreach ( $items_to_copy as $item ) {
	$item_path = $root_dir . '/' . $item;
	if ( is_file( $item_path ) ) {
		$zip->addFile( $item_path, 'hibackup/' . $item );
	} elseif ( is_dir( $item_path ) ) {
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $item_path, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $files as $file ) {
			$sub_path = substr( $file->getRealPath(), strlen( $root_dir ) + 1 );
			$sub_path = str_replace( '\\', '/', $sub_path );
			if ( $file->isDir() ) {
				$zip->addEmptyDir( 'hibackup/' . $sub_path );
			} elseif ( $file->isFile() ) {
				$zip->addFile( $file->getRealPath(), 'hibackup/' . $sub_path );
			}
		}
	}
}

$zip->close();
copy( $zip_clean, $zip_versioned );

$zip_size = round( filesize( $zip_clean ) / 1024, 2 );

echo "==========================================================\n";
echo "  ĐÓNG GÓI THÀNH CÔNG!\n";
echo "  File ZIP phát hành: dist/hibackup.zip ({$zip_size} KB)\n";
echo "  File ZIP theo version: dist/hibackup-v{$version}.zip\n";
echo "==========================================================\n\n";

echo "CÁCH TUNG BẢN CẬP NHẬT LÊN GITHUB RELEASES:\n";
echo "1. Đẩy code lên GitHub repository của bạn.\n";
echo "2. Vào tab 'Releases' trên GitHub -> Bấm 'Draft a new release'.\n";
echo "3. Điền Tag version: v{$version} (ví dụ: v{$version}).\n";
echo "4. Đính kèm file 'dist/hibackup.zip' vào mục đính kèm (Assets) của Release.\n";
echo "5. Bấm 'Publish release'.\n";
echo "-> Ngay sau đó, tất cả các trang web Live đang cài HiBackup sẽ nhận được thông báo cập nhật 1-Click!\n\n";
