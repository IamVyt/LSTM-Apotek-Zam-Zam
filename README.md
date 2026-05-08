# Sistem Prediksi Persediaan Obat (LSTM) - Apotek Zam Zam

Sistem informasi berbasis web yang menggunakan algoritma **Long Short-Term Memory (LSTM)** untuk memprediksi kebutuhan stok obat di Apotek Zam Zam. Sistem ini dirancang untuk membantu manajemen perencanaan dan pengendalian persediaan obat secara cerdas dan otomatis.

## 🚀 Fitur Utama
- **Dashboard Analitik**: Visualisasi stok dan tren penggunaan obat secara real-time.
- **Prediksi LSTM**: Forecasting kebutuhan stok mingguan dengan akurasi tinggi menggunakan Deep Learning (TensorFlow/Keras).
- **Import Data Excel**: Kemudahan input data historis langsung dari file Excel penelitian.
- **Cetak Laporan Resmi**: Laporan hasil analisis prediksi yang siap cetak (A4) untuk kebutuhan manajerial atau dokumen skripsi.
- **Manajemen Obat**: Pengelolaan data obat, kategori, dan stok minimum (safety stock).

## 🛠️ Teknologi
- **Backend**: PHP 8.x, MySQL
- **Deep Learning Service**: Python 3.10+, TensorFlow 2.x, Flask (Microservice)
- **Frontend**: Vanilla JS, Chart.js, Lucide Icons, CSS3 (Modern Bento UI)
- **Database**: MariaDB/MySQL

## 📦 Instalasi
1. Clone repositori:
   ```bash
   git clone https://github.com/IamVyt/Prediksi-Apotek-LSTM.git
   ```
2. Import database `database.sql` ke MySQL.
3. Sesuaikan konfigurasi di `config/config.php` dan `config/database.php`.
4. Setup Virtual Environment Python:
   ```bash
   cd python
   python -m venv venv
   source venv/bin/activate # atau venv\Scripts\activate di Windows
   pip install -r requirements.txt
   ```
5. Jalankan aplikasi melalui XAMPP atau web server lainnya.

## 📊 Dataset
Data yang digunakan adalah data historis mingguan dari Apotek Zam Zam, mencakup 5 fitur utama:
1. Stok Awal
2. Jumlah Masuk
3. Jumlah Keluar (Target Prediksi)
4. Stok Akhir
5. Rata-rata Keluar

---
*Dikembangkan untuk tugas akhir/skripsi Program Studi Informatika.*
