# 💊 Apotek Zam Zam - Sistem Prediksi Persediaan Obat (LSTM)

Sistem informasi manajemen inventaris farmasi terintegrasi dengan kecerdasan buatan berbasis **Long Short-Term Memory (LSTM)** untuk memprediksi kebutuhan stok obat di masa mendatang secara akurat.

![Dashboard Preview](https://img.shields.io/badge/Aesthetics-Premium-blueviolet)
![Tech Stack](https://img.shields.io/badge/Stack-PHP%20%7C%20MySQL%20%7C%20Python%20%7C%20TensorFlow-blue)

## 🚀 Fitur Utama
- **Prediksi Multivariate LSTM**: Memprediksi kebutuhan stok berdasarkan 5 variabel (Stok Awal, Masuk, Keluar, Stok Akhir, Rata-rata Keluar).
- **Dashboard Interaktif**: Visualisasi data real-time dengan grafik Chart.js yang modern dan minimalist.
- **Manajemen Inventaris**: Pengelolaan data obat, kategori, supplier, dan riwayat transaksi stok.
- **Report Center**: Pembuatan laporan analisis prediksi otomatis dalam format cetak profesional (A4).
- **Early Stopping & Patience**: Pelatihan model AI yang cerdas untuk menghindari overfitting dan mendapatkan akurasi terbaik.

## 🛠️ Teknologi yang Digunakan
- **Frontend**: HTML5, Vanilla CSS3 (Custom Design), JavaScript (ES6+), Lucide Icons.
- **Backend**: PHP 8.x (Native), MySQL.
- **AI Engine**: Python 3.9+, TensorFlow/Keras, NumPy, Pandas, Scikit-Learn.
- **Charts**: Chart.js 4.x.

## 📋 Prasyarat Instalasi
- Web Server (XAMPP/Laragon) dengan PHP 7.4 - 8.x.
- Python 3.9 atau lebih baru.
- Library Python: `tensorflow`, `numpy`, `pandas`, `scikit-learn`, `flask`, `flask-cors`.

## ⚙️ Cara Instalasi
1. **Clone Repository**:
   ```bash
   git clone https://github.com/IamVyt/LSTM-Apotek-Zam-Zam.git
   ```
2. **Setup Database**:
   - Buat database baru bernama `pharmapredictt` di phpMyAdmin.
   - Impor file `database.sql` atau jalankan skrip `setup.php`.
3. **Konfigurasi Python Service**:
   - Masuk ke folder `python/`.
   - Jalankan `start_service.bat` untuk menginisialisasi environment dan menjalankan API LSTM.
4. **Jalankan Aplikasi**:
   - Buka browser dan akses `http://localhost/pharmapredictt`.

## 📊 Analisis Residual
Sistem dilengkapi dengan grafik analisis residual yang minimalist untuk memantau selisih antara data aktual dan hasil prediksi, membantu apoteker dalam mengevaluasi performa model AI secara visual.

---
**Developed for Apotek Zam Zam Inventory Management Optimization.**
