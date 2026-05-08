@echo off
title Apotek Zam Zam - LSTM Service
cd /d "%~dp0"

if not exist "venv" (
    echo [INFO] Membuat virtual environment...
    py -3.11 -m venv venv 2>nul || py -3.10 -m venv venv 2>nul || python -m venv venv
    echo [OK] Virtual environment dibuat.
)

echo [INFO] Mengaktifkan virtual environment...
call venv\Scripts\activate.bat

echo [INFO] Menginstall/Memperbarui dependensi (TensorFlow)...
echo [INFO] Mohon tunggu, ini mungkin memakan waktu 2-5 menit tergantung internet.
pip install --upgrade pip
pip install -r requirements.txt

echo.
echo [START] Menjalankan LSTM Service di http://localhost:5000
python lstm_service.py
pause
