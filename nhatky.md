# NHẬT KÝ PHÁT TRIỂN & PHÁT HÀNH PLUGIN HIBACKUP

- **Tên plugin:** HiBackup – Sao lưu WordPress lên Google Drive (0MB Hosting Footprint)
- **Tác giả:** hiseo ([https://hiseo.vn](https://hiseo.vn))
- **Mã nguồn GitHub:** [https://github.com/LeMinhQuan09/plugin_hibackup](https://github.com/LeMinhQuan09/plugin_hibackup)
- **Phiên bản hiện tại:** `v1.1.0`
- **Ngày ghi nhận:** 22/10/2026

---

## 1. TỔNG QUAN PHIÊN LÀM VIỆC

Trong phiên làm việc này, toàn bộ quy trình hoàn thiện UI/UX, sửa lỗi tương thích hệ thống WordPress, xây dựng cơ chế tự động cập nhật từ xa (Auto-Update qua GitHub) và quy trình đóng gói phát hành các phiên bản `v1.0.0` và `v1.0.1` đã được hoàn thành 100%.

---

## 2. CHI TIẾT CÁC CÔNG VIỆC ĐÃ TRIỂN KHAI

### 2.1. Tinh chỉnh Giao diện & Trải nghiệm Người dùng (UI/UX)
1. **Thêm Checkbox "Tất cả":**
   - Đã thêm tùy chọn "Tất cả" ở cả tab **Cấu hình chung** (Các thành phần mặc định) và tab **Sao lưu ngay**.
   - Viết Javascript đồng bộ trạng thái: bấm chọn "Tất cả" sẽ tự động tích/bỏ tích toàn bộ các thành phần (Database, Plugins, Themes, Uploads, Wp-config, Core).
2. **Căn chỉnh Nút bấm & Icon:**
   - Sửa lỗi lệch trục dọc giữa icon Dashicon và chữ trong các nút bấm bằng thuộc tính `display: inline-flex; align-items: center; justify-content: center; gap: 6px;`.
3. **Lược bỏ thành phần thừa:**
   - Xóa bỏ huy hiệu badge "Lưu trữ 100% Cloud – 0MB Hosting" trên banner để thanh tiêu đề tinh gọn và trang nhã hơn.

### 2.2. Xử lý Lịch trình WP-Cron & Múi giờ chuẩn xác
- Khắc phục triệt để sai lệch giờ chạy sao lưu tự động ban đêm bằng hàm `wp_timezone()` và `wp_date()` thay vì các hàm thời gian chuẩn của PHP không nhận múi giờ của WordPress.

### 2.3. Xây dựng Cơ chế Tự động Cập nhật từ GitHub (Auto-Update)
- Tích hợp thư viện **Plugin Update Checker (PUC v5.4)** theo đường dẫn `includes/vendor/plugin-update-checker/`.
- Cấu hình nguồn cập nhật trỏ về GitHub Releases: `https://github.com/LeMinhQuan09/plugin_hibackup/`.
- Viết kịch bản tự động kiểm tra cú pháp PHP và nén bản phát hành:
  - `scripts/build.php`: Tự động đọc phiên bản, chạy linting kiểm tra lỗi toàn bộ file PHP, đóng gói file ZIP sạch sẽ vào thư mục `dist/`.
  - `build.bat`: Cho phép kích đúp chuột trên Windows để đóng gói ngay lập tức.

### 2.4. Phát hành v1.0.0 & Xử lý sự cố môi trường
- Khởi tạo Git repository, cấu hình `.gitignore` chuẩn cho WordPress plugin, đẩy toàn bộ mã nguồn và gắn tag **`v1.0.0`** lên GitHub.
- **Giải quyết thắc mắc `Update failed: Could not remove the old plugin`:**
  - Xác định nguyên nhân do môi trường ảo hóa WP Playground mount thư mục trực tiếp từ máy tính nên quyền xóa folder bị khóa.
  - Trên các trang web hosting thực tế (Live Server), cơ chế xóa và ghi đè của WordPress hoạt động bình thường.
- Khôi phục thông tin bản quyền: `By hiseo` liên kết đến `https://hiseo.vn`.

### 2.5. Xây dựng Hộp hướng dẫn tạo Google Client ID & Secret
1. **Tạo khối Hướng dẫn thu gọn / mở rộng (`<details>` & `<summary>`):**
   - Hướng dẫn từng bước cách tạo Google Project, bật Google Drive API, tạo OAuth 2.0 Client ID và Service Account JSON.
2. **Bổ sung Hiệu ứng Mũi tên & Nhãn tương tác trực quan:**
   - Thêm Dashicon mũi tên `dashicons-arrow-right-alt2` tự động xoay 90 độ (`transform: rotate(90deg)`) khi mở ra.
   - Thêm nhãn trạng thái tự đổi màu: hiển thị "Bấm để mở" khi đóng và "Thu gọn" khi mở.
3. **Cập nhật nội dung theo Giao diện mới nhất của Google Cloud Console:**
   - Hướng dẫn trực diện theo layout menu con cột bên trái: **Branding**, **Audience**, **Clients**.
4. **Bổ sung mục Test Users (Giải quyết lỗi `403: access_denied`):**
   - Hướng dẫn chi tiết cách thêm email admin vào mục **Test users** trong tab **Audience** để tránh bị Google chặn.
   - Bổ sung chỉ dẫn vượt qua màn hình cảnh báo *"Google chưa xác minh ứng dụng này"* (Bấm **Nâng cao** > **Đi tới... không an toàn**).

### 2.6. Khắc phục Lỗi vỡ giao diện do Admin Notices
- **Hiện tượng:** Các thông báo của theme và plugin khác (như *Internal Link Juicer*, thông báo lưu cài đặt,...) bị dồn hàng ngang vào bên trong khung Header màu đen.
- **Nguyên nhân:** Javascript `common.js` của WordPress Core tự động tìm thẻ `<h1>` đầu tiên để chèn các thông báo ngay sau nó, khiến các thẻ thông báo bị lọt vào container Flexbox của Header.
- **Giải pháp:**
  - Chèn mỏ neo chuẩn `<hr class="wp-header-end">` ngay phía dưới khối Header.
  - Đổi thẻ tiêu đề Header sang `<h2>`.
  - Bổ sung CSS bảo vệ (`.hibackup-wrap > .notice { width: 100%; clear: both; }`) để mọi thông báo hiển thị ngay ngắn, chuẩn mực toàn chiều ngang bên dưới Header.

### 2.7. Nâng cấp & Phát hành Phiên bản v1.0.1
### 2.8. Phát triển Tính năng "Toàn bộ mã nguồn (Full Source)" (v1.1.0)
- **Yêu cầu:** Bổ sung tùy chọn sao lưu Full Source, bao gồm tất cả các file mã nguồn hiện tại của website WordPress (các file gốc như `index.php`, `.htaccess`, `wp-config.php`, `wp-load.php`, `robots.txt`, thư mục `wp-content`, `wp-admin`, `wp-includes`...).
- **Xử lý cốt lõi (`HiBackup_Files::create_zip`):**
  - Khi bật `include_full_source`, sử dụng `RecursiveDirectoryIterator` duyệt trực tiếp từ thư mục gốc `ABSPATH`.
  - Giữ nguyên cấu trúc tương đối chuẩn (`index.php`, `wp-content/...`, `.htaccess`).
  - Hỗ trợ trường hợp bảo mật khi `wp-config.php` nằm ngoài thư mục `ABSPATH`.
  - Tối ưu bộ lọc loại trừ (`exclude_patterns`): tự động bỏ qua `hibackup-temp`, `cache`, `wp-content/updraft`, `wp-content/backups`, `.git`, `node_modules`, `error_log`...
  - Kiểm tra tuyệt đối chống loop đệ quy nén chính file ZIP đang ghi hoặc file dump SQL.
- **Đồng bộ Giao diện & Trải nghiệm Phân cấp Cha - Con (UI/UX):**
  - Tách bạch cấu trúc: `Database` và `Toàn bộ mã nguồn (Full Source)` là 2 lựa chọn cấp 1 độc lập.
  - Các thành phần con (`Plugins`, `Themes`, `Uploads`, `Config`, `Core`) được gom vào khối nhánh phụ bên dưới.
  - Khi tick chọn **"Toàn bộ mã nguồn (Full Source)"**: Toàn bộ khối thành phần con sẽ tự động làm mờ (`opacity: 0.55`, `disabled`) và hiển thị nhãn xanh **`✓ Đã bao gồm trong Full Source`**, giải quyết triệt để cảm giác trùng lặp, thừa thãi khi tick cả cha lẫn con.
  - Khi bỏ tick "Toàn bộ mã nguồn": Khối thành phần con lập tức sáng lên cho phép người dùng tùy chọn từng phần riêng lẻ.
  - Nút **"Tất cả (Chọn / Bỏ chọn toàn bộ)"** thông minh: khi bấm chọn Tất cả sẽ chọn Database + Full Source và tự động khóa mờ các mục con.
- **Lập lịch Cron & Đặt tên:**
  - Hỗ trợ lưu trữ cấu hình `backup_full_source` trong cron tự động hàng ngày/hàng tuần.
  - Quy ước đặt tên file chuẩn `bk_[site]_full_[date].zip` và ghi lịch sử `Full (Full Source + DB)`.
- **Nâng cấp phiên bản:** Phát hành **`v1.1.0`** (Minor Release).

---

## 3. CẤU TRÚC FILE QUAN TRỌNG

```text
hibackup/
├── assets/
│   ├── css/admin.css            # Toàn bộ styling giao diện trang quản trị HiBackup
│   └── js/admin.js              # Script xử lý AJAX sao lưu thời gian thực, checkbox Tất cả
├── dist/
│   ├── hibackup.zip             # File phát hành đính kèm vào GitHub Releases
│   └── hibackup-v1.0.1.zip      # File lưu trữ bản phát hành v1.0.1
├── includes/
│   ├── class-hibackup.php       # Lớp điều phối chính của plugin
│   ├── class-hibackup-admin.php # Xử lý menu, tabs và giao diện Admin
│   ├── class-hibackup-cron.php  # Lập lịch tự động bằng WP-Cron
│   ├── class-hibackup-db.php    # Xuất cơ sở dữ liệu MySQL/MariaDB
│   ├── class-hibackup-files.php # Nén file mã nguồn và loại trừ thư mục
│   ├── class-hibackup-gdrive.php# Xử lý Google Drive API v3 (Resumable Upload)
│   └── vendor/
│       └── plugin-update-checker/ # Thư viện tự động cập nhật từ GitHub
├── scripts/
│   └── build.php                # Script PHP tự động kiểm tra lỗi và nén file ZIP
├── build.bat                    # Phím tắt đóng gói trên Windows
├── hibackup.php                 # File khởi động chính của plugin
├── nhatky.md                    # Nhật ký công việc và phiên làm việc
├── README.md                    # Tài liệu giới thiệu trên GitHub
└── readme.txt                   # Tài liệu chuẩn WordPress Plugin Directory
```

---

## 4. QUY TRÌNH PHÁT HÀNH CẬP NHẬT TƯƠNG LAI (CHEATSHEET)

Khi có thay đổi mã nguồn và muốn tung bản cập nhật mới cho người dùng:
1. Chỉnh sửa số phiên bản trong `hibackup.php` và `readme.txt` (Ví dụ: `1.0.2`).
2. Mở terminal gõ: `php scripts/build.php` (hoặc nhấp đúp vào `build.bat`).
3. Commit và gắn tag git:
   ```bash
   git add .
   git commit -m "release: v1.0.2"
   git tag -a v1.0.2 -m "Release v1.0.2"
   git push origin main --tags
   ```
4. Lên GitHub: Tạo Release mới với tag `v1.0.2`, kéo file `dist/hibackup.zip` vào và Publish!
