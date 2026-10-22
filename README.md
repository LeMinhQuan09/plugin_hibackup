# HiBackup 🚀 – WordPress Cloud Backup to Google Drive

[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net)
[![Version](https://img.shields.io/badge/Version-1.1.0-green.svg)](https://github.com/LeMinhQuan09/plugin_hibackup/releases)
[![Author](https://img.shields.io/badge/Author-hiseo-orange.svg)](https://hiseo.vn)
[![License](https://img.shields.io/badge/License-GPLv2-lightgrey.svg)](LICENSE)

**HiBackup** là plugin WordPress chuyên nghiệp giúp sao lưu toàn diện mã nguồn (*Source Code*) và cơ sở dữ liệu (*Database*), tự động đẩy thẳng lên **Google Drive** và **xóa sạch file tạm trên hosting ngay lập tức**, đảm bảo **0MB dung lượng hosting chiếm dụng**.

---

## 🌟 Tính năng nổi bật

* ☁️ **Zero Hosting Footprint (0MB Hosting):** File nén Zip và SQL thô được xóa vĩnh viễn khỏi hosting ngay sau khi upload thành công lên Google Drive. Nếu quá trình upload gặp sự cố, hệ thống cũng tự dọn dẹp file tạm, không để lại rác trên máy chủ.
* ⚡ **Resumable Chunked Upload (Google Drive API v3):** Tải lên theo các chunk nhỏ (2MB) với cơ chế tiếp tục (resumable), không lo bị Timeout hoặc tràn bộ nhớ RAM (PHP Memory Limit) ngay cả với các website nặng hàng GB.
* 🔑 **Hỗ trợ 2 phương thức kết nối Google Drive:**
  * **Google OAuth 2.0:** Đăng nhập và ủy quyền 1-Click dành cho tài khoản Google cá nhân.
  * **Service Account (JSON Key):** Dành cho Google Workspace hoặc sao lưu nền hoàn toàn tự động không cần refresh token.
* 📦 **Tùy chọn thành phần sao lưu linh hoạt:**
  * Cơ sở dữ liệu (Database SQL).
  * **Toàn bộ mã nguồn (Full Source):** Sao lưu 100% website gồm tất cả các file gốc WordPress (`.htaccess`, `index.php`, `wp-config.php`, `wp-load.php`, `wp-content`...).
  * Hoặc tùy chọn riêng lẻ: Plugins, Themes, Uploads (Media), File cấu hình `wp-config.php`, WordPress Core.
  * Nút **"Tất cả"** tiện lợi chọn / bỏ chọn nhanh toàn bộ thành phần.
* ⏰ **Lịch trình tự động thông minh (WP-Cron):**
  * Hỗ trợ chu kỳ: Hàng ngày, 2-3 ngày, hàng tuần, 2 tuần, hàng tháng hoặc theo giờ (mỗi 1h, 2h, 4h, 6h, 12h).
  * Tùy chọn khung giờ chạy ban đêm (ví dụ: 02:00 AM) chuẩn xác theo **Múi giờ của website**.
  * Tự động dọn dẹp và xoay vòng bản sao lưu trên Google Drive (tự xóa các bản cũ khi vượt quá số lượng thiết lập).
* 🔄 **Tích hợp Tự động Cập nhật từ GitHub (PUC v5.4):**
  * Tự động nhận diện bản phát hành mới từ GitHub Releases.
  * Hiển thị thông báo và nút **"Cập nhật ngay"** 1-Click trực tiếp trong Dashboard WordPress.
* 📊 **Tiến trình thời gian thực:**
  * Thanh tiến trình phần trăm (%) kèm console log chi tiết từng bước.

---

## 🛠️ Hướng dẫn Cài đặt

1. Tải file `hibackup.zip` từ mục [Releases](https://github.com/LeMinhQuan09/plugin_hibackup/releases).
2. Trong trang quản trị WordPress: Vào **Plugins > Cài mới > Tải plugin lên** > Chọn file `hibackup.zip` và bấm **Cài đặt ngay**.
3. Bấm **Kích hoạt gói mở rộng**.
4. Truy cập menu **HiBackup** trên thanh điều hướng bên trái để bắt đầu cấu hình.

---

## 🔑 Hướng dẫn Kết nối Google Drive

### Phương thức 1: Google OAuth 2.0 (Khuyên dùng cho Gmail cá nhân)
1. Truy cập [Google Cloud Console](https://console.cloud.google.com/) và tạo một Project mới.
2. Vào **APIs & Services > Library**, tìm kiếm **Google Drive API** và chọn **Enable**.
3. Vào **OAuth consent screen**, chọn User Type là **External**, điền thông tin ứng dụng và thêm email của bạn vào danh sách **Test users**.
4. Vào **Credentials > Create Credentials > OAuth client ID**:
   * Loại ứng dụng: **Web application**.
   * Mục **Authorized redirect URIs**: Dán đường link Redirect URI được cung cấp sẵn trong tab *Cấu hình Google Drive* của HiBackup.
5. Copy **Client ID** và **Client Secret** dán vào plugin > Bấm **Lưu Cài đặt**.
6. Bấm nút **"Đăng nhập & Ủy quyền Google Drive (1-Click)"** để cấp quyền.

### Phương thức 2: Service Account JSON (Google Workspace / Doanh nghiệp)
1. Trong Google Cloud Console, vào **Credentials > Create Credentials > Service Account**.
2. Đặt tên Service Account và bấm hoàn tất.
3. Nhấp vào Service Account vừa tạo > Tab **Keys** > **Add Key > Create new key > JSON**. File key sẽ tự động tải về máy.
4. Mở file JSON, copy toàn bộ nội dung và dán vào ô **Service Account Key** trong HiBackup.
5. Mở Google Drive, tạo thư mục lưu trữ (ví dụ: `Website_Backup`) > Bấm **Chia sẻ (Share)** và thêm địa chỉ email của Service Account với quyền **Người chỉnh sửa (Editor)**.
6. Copy Folder ID từ thanh URL dán vào ô **Google Drive Folder ID** trong plugin.

---

## 📦 Quy trình Đóng gói & Phát hành Bản cập nhật (Cho Nhà phát triển)

Dự án tích hợp sẵn công cụ build tự động giúp loại trừ các tệp rác:

1. Chỉnh sửa code trên máy Local.
2. Tăng số phiên bản trong `hibackup.php` (`Version: 1.0.1` và `HIBACKUP_VERSION`).
3. Chạy file đóng gói:
   * **Cách 1:** Nhấp đúp chuột vào file `build.bat`.
   * **Cách 2:** Chạy lệnh `php scripts/build.php`.
4. File ZIP phát hành chuẩn sẽ được tạo tại `dist/hibackup.zip`.
5. Tạo Release trên GitHub:
   * Vào **Releases > Draft a new release**.
   * Tag version: `v1.0.1`.
   * Đính kèm file `dist/hibackup.zip` vào mục **Assets**.
   * Bấm **Publish release**.
6. Toàn bộ các website Live cài đặt HiBackup sẽ tự động nhận diện bản mới và có thể bấm **"Cập nhật ngay"**!

---

## 👨‍💻 Thông tin Tác giả & Giấy phép

* **Phát triển bởi:** [hiseo](https://hiseo.vn)
* **Trang chủ:** [https://hiseo.vn](https://hiseo.vn)
* **Giấy phép:** [GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html)
