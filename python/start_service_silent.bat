@echo off
REM Silent launcher - spawned dari PHP, tanpa interaksi user
REM Berbeda dari start_service.bat: tidak ada pause, prompt, atau cek interaktif

cd /d "%~dp0"

REM Buat venv jika belum ada
if not exist "venv" (
    py -3.11 -m venv venv 2>nul
    if errorlevel 1 (
        echo [ERROR] Python 3.11 tidak terinstall > "%~dp0service_error.log"
        exit /b 1
    )
)

REM Aktifkan venv
call venv\Scripts\activate.bat

REM Install dependencies (hanya kalau belum ada)
pip install -r requirements.txt --quiet --disable-pip-version-check 2>nul

REM Jalankan Flask service (di foreground proses ini, tapi proses ini sendiri di-detach oleh PHP)
python lstm_service.py >> "%~dp0service.log" 2>&1
