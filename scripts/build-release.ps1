# ==============================================================================
# Script đóng gói bản phát hành HiBackup (Release Packaging Script)
# ==============================================================================
param (
    [switch]$NoPause
)

$ErrorActionPreference = "Stop"
$RootDir = Split-Path -Parent $PSScriptRoot
$MainFile = Join-Path $RootDir "hibackup.php"
$DistDir = Join-Path $RootDir "dist"
$BuildDir = Join-Path $RootDir "build"

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "  HiBackup - Bat dau qua trinh Dong goi Ban phat hanh" -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan

# 1. Doc so phien ban tu hibackup.php
if (-not (Test-Path $MainFile)) {
    Write-Error "Khong tim thay file $MainFile"
    exit 1
}

$Version = "1.0.0"
Get-Content $MainFile | ForEach-Object {
    if ($_ -match 'Version:\s*([0-9\.]+)') {
        $Version = $matches[1]
    }
}

Write-Host "-> Phien ban phat hien: v$Version" -ForegroundColor Yellow

# 2. Kiem tra cu phap toan bo file PHP (Linting)
Write-Host "-> Dang kiem tra cu phap toan bo file PHP..." -ForegroundColor Yellow
$PhpFiles = Get-ChildItem -Path $RootDir -Filter "*.php" -Recurse | Where-Object {
    $_.FullName -notmatch "node_modules|build|dist|\.git|scratch|vendor"
}

$LintErrorCount = 0
foreach ($file in $PhpFiles) {
    $lintOutput = php -l $file.FullName 2>&1
    if ($LASTEXITCODE -ne 0) {
        Write-Host "   [LOI CU PHAP] $($file.FullName)" -ForegroundColor Red
        Write-Host "   $lintOutput" -ForegroundColor Red
        $LintErrorCount++
    }
}

if ($LintErrorCount -gt 0) {
    Write-Error "Dong goi that bai do co $LintErrorCount file PHP bi loi cu phap!"
    exit 1
}
Write-Host "   [OK] Toan bo file PHP deu hop le, khong co loi cu phap!" -ForegroundColor Green

# 3. Chuan bi thu muc build
if (Test-Path $BuildDir) {
    Remove-Item -Recurse -Force $BuildDir
}
if (-not (Test-Path $DistDir)) {
    New-Item -ItemType Directory -Path $DistDir | Out-Null
}

$PluginStaging = Join-Path $BuildDir "hibackup"
New-Item -ItemType Directory -Path $PluginStaging -Force | Out-Null

# 4. Sao chep cac thu muc va file san pham can thiet (Loai tru file rac)
Write-Host "-> Dang sao chep cac tep can thiet vao thu muc phat hanh..." -ForegroundColor Yellow

$ItemsToCopy = @(
    "hibackup.php",
    "uninstall.php",
    "readme.txt",
    "includes",
    "assets"
)

foreach ($item in $ItemsToCopy) {
    $sourcePath = Join-Path $RootDir $item
    if (Test-Path $sourcePath) {
        Copy-Item -Path $sourcePath -Destination $PluginStaging -Recurse -Force
    }
}

# 5. Dong goi file ZIP
$ZipVersioned = Join-Path $DistDir "hibackup-v$Version.zip"
$ZipClean = Join-Path $DistDir "hibackup.zip"

if (Test-Path $ZipVersioned) { Remove-Item -Force $ZipVersioned }
if (Test-Path $ZipClean) { Remove-Item -Force $ZipClean }

Write-Host "-> Dang tao file nen ZIP chuan WordPress..." -ForegroundColor Yellow
Compress-Archive -Path $PluginStaging -DestinationPath $ZipVersioned -CompressionLevel Optimal
Copy-Item -Path $ZipVersioned -Destination $ZipClean -Force

# Don dep thu muc tam build
Remove-Item -Recurse -Force $BuildDir

$ZipSize = [Math]::Round(((Get-Item $ZipClean).Length / 1KB), 2)
Write-Host "==========================================================" -ForegroundColor Green
Write-Host "  DONG GOI THANH CONG!" -ForegroundColor Green
Write-Host "  File ZIP phat hanh: dist/hibackup.zip ($ZipSize KB)" -ForegroundColor Cyan
Write-Host "  File ZIP theo version: dist/hibackup-v$Version.zip" -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Green

Write-Host ""
Write-Host "CACH TUNG BAN CAP NHAT LEN GITHUB RELEASES:" -ForegroundColor Yellow
Write-Host "1. Day code len GitHub repository cua ban."
Write-Host "2. Vao tab 'Releases' tren GitHub -> Bam 'Draft a new release'."
Write-Host "3. Dien Tag version: v$Version (vi du: v$Version)."
Write-Host "4. Dinh kem file 'dist/hibackup.zip' vao muc dinh kem (Assets) cua Release."
Write-Host "5. Bam 'Publish release'."
Write-Host "-> Ngay sau do, tat ca cac trang web Live dang cai HiBackup se nhan duoc thong bao cap nhat 1-Click!" -ForegroundColor Green

if (-not $NoPause) {
    Read-Host -Prompt "Nhan Enter de thoat..."
}
