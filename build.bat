@echo off
chcp 65001 >nul
php "%~dp0scripts\build.php"
pause
