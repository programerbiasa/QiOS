@echo off
:: Script Perbaikan Otomatis Akses Network Sharing / Samba Windows 11
:: Masalah: Workstation Service (LanmanWorkstation) gagal start karena dependensi mrxsmb10 (SMBv1) yang hilang.
:: Solusi: Reset dependensi ke SMBv2/SMBv3 modern (bowser/mrxsmb20/nsi) dan start service.

echo ========================================================
echo       PERBAIKAN NETWORK SHARING WINDOWS 11
echo ========================================================
echo.

:: Cek apakah dijalankan sebagai Administrator
net session >nul 2>&1
if %errorlevel% neq 0 (
    echo [PERINGATAN] Skrip ini membutuhkan hak Administrator!
    echo Silakan klik kanan file ini lalu pilih "Run as administrator".
    echo.
    pause
    exit /b 1
)

echo [1/3] Menghapus dependensi usang (mrxsmb10) dari LanmanWorkstation...
sc.exe config LanmanWorkstation depend= bowser/mrxsmb20/nsi
if %errorlevel% equ 0 (
    echo     -> Berhasil memperbarui dependensi!
) else (
    echo     -> Gagal memperbarui dependensi.
)

echo.
echo [2/3] Mengatur Workstation Service agar otomatis berjalan (Auto Start)...
sc.exe config LanmanWorkstation start= auto

echo.
echo [3/3] Menjalankan kembali Workstation Service...
net start LanmanWorkstation

echo.
echo ========================================================
sc.exe query LanmanWorkstation
echo ========================================================
echo.
echo PERBAIKAN SELESAI!
echo Sekarang silakan coba buka kembali folder jaringan di File Explorer:
echo \\172.16.5.254 atau \\172.16.5.11\sd
echo.
pause
