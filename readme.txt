=== HiBackup ===
Contributors: hiseo
Tags: backup, google drive, cloud backup, database backup, auto backup
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sao lưu toàn diện mã nguồn và cơ sở dữ liệu WordPress lên Google Drive với dung lượng lưu trữ 0MB trên hosting.

== Description ==

HiBackup là plugin sao lưu WordPress hiện đại, tự động nén toàn bộ mã nguồn và cơ sở dữ liệu để tải trực tiếp lên Google Drive cá nhân của bạn.
Điểm đặc biệt: Ngay sau khi tải lên thành công, toàn bộ file tạm trên hosting sẽ được xóa sạch lập tức, đảm bảo hosting của bạn không bao giờ bị đầy dung lượng.

* Hỗ trợ Google Drive OAuth 2.0 (1-Click) và Service Account JSON.
* Sao lưu linh hoạt: Database, Plugins, Themes, Uploads, Config, Core WP.
* Lịch trình sao lưu tự động phong phú (Hàng ngày, 2-3 ngày, hàng tuần, hàng tháng hoặc theo giờ).
* Tự động xóa các bản sao lưu cũ trên Google Drive khi vượt số lượng quy định.
* Hỗ trợ cập nhật tự động trực tiếp từ GitHub Releases.

== Installation ==

1. Tải file `hibackup.zip` lên thư mục `/wp-content/plugins/` hoặc cài đặt qua trang Quản lý Plugins của WordPress.
2. Kích hoạt plugin qua menu 'Plugins' trong WordPress.
3. Truy cập menu 'HiBackup' trong trang quản trị để kết nối Google Drive và thiết lập lịch sao lưu.

== Changelog ==

= 1.0.1 =
* Sửa lỗi: Hiển thị thông báo WordPress (admin notices) ngay ngắn dưới banner với mỏ neo chuẩn wp-header-end.
* Giao diện: Thêm icon mũi tên xoay animation và nhãn trạng thái mở/đóng hướng dẫn cấu hình.
* Tài liệu: Cập nhật hướng dẫn chi tiết theo giao diện mới của Google Cloud Console và bổ sung bước Test Users để tránh lỗi 403 access_denied.

= 1.0.0 =
* Phiên bản phát hành chính thức đầu tiên.
* Hỗ trợ tải trực tiếp lên Google Drive qua Resumable Chunked Upload.
* Tự động dọn dẹp file tạm trên hosting (0MB footprint).
* Hỗ trợ đặt tên theo quy tắc chuẩn: bk_[site]_[type]_[Y-m-d].zip.
* Tích hợp cơ chế tự động cập nhật từ GitHub Releases (PUC).
