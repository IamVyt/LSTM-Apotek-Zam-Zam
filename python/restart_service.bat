@echo off
title Apotek Zam Zam - Restart LSTM Service
echo ============================================
echo   Restarting Apotek Zam Zam LSTM Service
echo ============================================

REM Kill semua proses Python yang sedang jalan
echo [1/3] Menghentikan proses Python lama...
taskkill /F /IM python.exe >nul 2>&1
taskkill /F /IM python3.exe >nul 2>&1
timeout /t 2 /nobreak >nul
echo [OK] Proses lama dihentikan.

REM Pindah ke folder python
cd /d "%~dp0"

REM Aktifkan venv
echo [2/3] Mengaktifkan virtual environment...
if not exist "venv" (
    echo [ERROR] Virtual environment belum dibuat. Jalankan start_service.bat terlebih dahulu.
    pause
    exit
)
call venv\Scripts\activate.bat

REM Install dependencies dari requirements.txt
echo [INFO] Memeriksa dependensi (TensorFlow)...
pip install -r requirements.txt

REM Start service baru
echo [3/3] Menjalankan LSTM service baru...
echo.
python lstm_service.py
pause
