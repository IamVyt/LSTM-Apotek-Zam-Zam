<?php
/**
 * IMPORT DATA EXCEL ke MySQL
 * Apotek Zam Zam - Sistem Prediksi Persediaan Obat
 *
 * Skrip ini meng-impor data historis mingguan dari file Excel
 * Tes_Obat_LSTM_FINAL.xlsx (5 obat x 53 minggu = 265 records)
 * ke tabel data_historis di database apotek_zamzam.
 *
 * Cara pakai (jalankan SEKALI setelah setup.php):
 *   http://localhost/pharmapredictt/import_excel.php
 *
 * Setelah selesai, sistem siap melakukan prediksi LSTM dengan
 * data REAL skripsi (bukan data seed sintetik).
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistem Setup & Import Data — Apotek Zam Zam</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/main.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/redesign.css">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body {
            background: linear-gradient(135deg, #f0f4f8 0%, #d9e2ec 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            font-family: 'Plus Jakarta Sans', sans-serif;
            margin: 0;
        }

        .setup-container {
            width: 100%;
            max-width: 900px;
            background: #ffffff;
            border-radius: 24px;
            box-shadow: 0 20px 40px rgba(15, 23, 42, 0.08), 0 1px 3px rgba(15, 23, 42, 0.05);
            padding: 48px;
            overflow: hidden;
            position: relative;
            animation: fadeInScale 0.4s ease-out forwards;
        }

        .setup-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 6px;
            background: linear-gradient(90deg, #3b82f6, #8b5cf6, #d946ef);
        }

        .setup-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .setup-header h1 {
            font-size: 2rem;
            font-weight: 800;
            color: #0f172a;
            margin: 0 0 8px 0;
            letter-spacing: -0.02em;
        }

        .setup-header p {
            color: #64748b;
            font-size: 1.05rem;
            margin: 0;
        }

        .icon-hero {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 72px;
            height: 72px;
            background: #eff6ff;
            color: #3b82f6;
            border-radius: 20px;
            margin-bottom: 24px;
        }

        .icon-hero svg {
            width: 36px;
            height: 36px;
            stroke-width: 1.5;
        }

        .log-ok {
            color: #065f46;
            background: #d1fae5;
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 12px;
            border-left: 4px solid #10b981;
            font-weight: 600;
            font-size: 0.95rem;
        }

        .log-err {
            color: #991b1b;
            background: #fee2e2;
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 12px;
            border-left: 4px solid #ef4444;
            font-weight: 600;
            font-size: 0.95rem;
        }

        .log-info {
            color: #1e40af;
            background: #dbeafe;
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 12px;
            border-left: 4px solid #3b82f6;
            font-weight: 600;
            font-size: 0.95rem;
        }

        @keyframes fadeInScale {
            from {
                opacity: 0;
                transform: scale(0.98);
            }

            to {
                opacity: 1;
                transform: scale(1);
            }
        }
    </style>
</head>

<body>
    <div class="setup-container">
        <div class="setup-header">
            <div class="icon-hero"><i data-lucide="database"></i></div>
            <h1>Sistem Setup & Import Data</h1>
            <p>Apotek Zam Zam — Modul Prediksi Persediaan LSTM</p>
        </div>

        <?php
        require_once __DIR__ . '/includes/SimpleXLSX.php';

        // ─── AUTO-DETECT FILE: prioritas CSV > XLSX ───
        $csvFile = __DIR__ . '/Data_Obat_Mingguan.csv';
        $excelFile = __DIR__ . '/Tes_Obat_LSTM_FINAL.xlsx';
        $useCsv = file_exists($csvFile);
        $useXlsx = file_exists($excelFile);

        if (!$useCsv && !$useXlsx) {
            die("<div class='log-err'>Tidak ada file data ditemukan.<br>Letakkan salah satu file ini di root folder:<br>"
                . "• <code>Data_Obat_Mingguan.csv</code> (rekomendasi)<br>"
                . "• <code>Tes_Obat_LSTM_FINAL.xlsx</code></div>");
        }

        $sourceFile = $useCsv ? $csvFile : $excelFile;
        $sourceType = $useCsv ? 'CSV' : 'Excel';
        echo "<div class='log-info'>Sumber data: <b>" . basename($sourceFile) . "</b> ({$sourceType})</div>";

        // ─── HELPER: Parse tanggal fleksibel (US M/D/Y, ISO Y-M-D, dll) ───
        function parseTanggal($val)
        {
            if (empty($val))
                return date('Y-m-d');
            if ($val instanceof DateTime)
                return $val->format('Y-m-d');
            // Coba detect format umum: 4/28/2025, 28/04/2025, 2025-04-28
            $val = trim((string) $val);
            // ISO format Y-m-d
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $val))
                return substr($val, 0, 10);
            // US format M/D/Y atau D/M/Y - asumsikan US (M/D/Y) karena CSV ini pakai itu
            if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})/', $val, $m)) {
                return sprintf('%04d-%02d-%02d', $m[3], $m[1], $m[2]);
            }
            // Fallback: strtotime
            $ts = strtotime($val);
            return $ts ? date('Y-m-d', $ts) : date('Y-m-d');
        }

        // ─── BACA DATA ───
        $rows = [];
        if ($useCsv) {
            if (($handle = fopen($csvFile, 'r')) !== false) {
                while (($data = fgetcsv($handle, 0, ',')) !== false) {
                    $rows[] = $data;
                }
                fclose($handle);
            }
        } else {
            $xlsx = \Shuchkin\SimpleXLSX::parse($excelFile);
            if (!$xlsx) {
                die("<div class='log-err'>Gagal membaca Excel: " . \Shuchkin\SimpleXLSX::parseError() . "</div>");
            }
            $sheetIndex = -1;
            foreach ($xlsx->sheetNames() as $idx => $name) {
                if (stripos($name, 'Data Mingguan') !== false) {
                    $sheetIndex = $idx;
                    break;
                }
            }
            if ($sheetIndex === -1)
                die("<div class='log-err'>Sheet 'Data Mingguan' tidak ditemukan di Excel.</div>");
            $rows = $xlsx->rows($sheetIndex);
        }

        if (empty($rows)) {
            die("<div class='log-err'>File kosong atau gagal di-parse.</div>");
        }

        $dataExcel = [];
        // Mapping obat — DATA REAL APOTEK ZAM ZAM (5 fast moving)
// Order penting: cek lebih spesifik dulu (CANDESARTAN sebelum AMLODIPIN, dst)
        $obatMap = [
            'AMLODIPIN' => 1,
            'CANDESARTAN' => 2,
            'IBUPROFEN' => 3,
            'PARASETAMOL' => 4,
            'PIROXICAM' => 5,
        ];

        foreach ($rows as $index => $row) {
            if ($index === 0)
                continue;                       // Skip header
            if (empty($row[1]) || empty($row[3]))
                continue;   // Skip baris kosong
        
            $tanggalStr = parseTanggal($row[1]);
            $tanggalAkhirStr = !empty($row[2]) ? parseTanggal($row[2]) : $tanggalStr;
            $namaObatStr = trim((string) $row[3]);

            $obatId = 0;
            foreach ($obatMap as $keyword => $id) {
                if (stripos($namaObatStr, $keyword) !== false) {
                    $obatId = $id;
                    break;
                }
            }
            if ($obatId === 0)
                continue;

            $dataExcel[] = [
                $obatId,
                $tanggalStr,
                $tanggalAkhirStr,
                (float) $row[5],   // stok awal
                (float) $row[6],   // total masuk
                (float) $row[7],   // total keluar
                (float) $row[8],   // stok akhir
                (float) $row[9],   // rata keluar
            ];
        }

        // Tambahkan minggu_ke sequential per obat (1, 2, ..., 53)
        $mingguCounter = [];
        foreach ($dataExcel as &$row) {
            $oid = $row[0];
            $mingguCounter[$oid] = ($mingguCounter[$oid] ?? 0) + 1;
            array_splice($row, 1, 0, [$mingguCounter[$oid]]);  // insert minggu_ke sebelum tanggal
        }
        unset($row);

        try {
            $db = getDB();
            echo "<div class='log-info'>Koneksi database OK. Membaca " . count($dataExcel) . " baris data dari Excel.</div>";

            // Pengecekan dilewati karena obat pasti sudah ada
        
            // Hapus data lama
            $db->exec("DELETE FROM data_historis");
            $db->exec("ALTER TABLE data_historis AUTO_INCREMENT = 1");
            echo "<div class='log-info'>Data historis lama dibersihkan.</div>";

            // Insert data baru dari Excel — LENGKAP semua kolom (termasuk minggu_ke & tanggal_akhir untuk grafik)
            $stmt = $db->prepare("INSERT INTO data_historis (obat_id, minggu_ke, tanggal, tanggal_akhir, stok_awal, jumlah_masuk, jumlah_keluar, stok_akhir, rata_rata_keluar) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $count = 0;
            foreach ($dataExcel as $idx => $row) {
                try {
                    $stmt->execute($row);
                    $count++;
                } catch (PDOException $e) {
                    echo "<div class='log-err'>Gagal baris $idx: " . implode(', ', $row) . " | Error: " . $e->getMessage() . "</div>";
                }
            }
            echo "<div class='log-ok'>Berhasil import <b>{$count} records</b> data historis mingguan.</div>";

            // Update stok_saat_ini per obat = stok akhir minggu terakhir
            $db->exec("UPDATE obat o
               JOIN (SELECT obat_id, stok_akhir
                     FROM data_historis dh
                     WHERE tanggal = (SELECT MAX(tanggal) FROM data_historis WHERE obat_id = dh.obat_id)
                    ) latest ON latest.obat_id = o.id
               SET o.stok_saat_ini = latest.stok_akhir");
            echo "<div class='log-ok'>Stok saat ini per obat diupdate dari minggu terakhir.</div>";

            // Tampilkan ringkasan per obat
            echo "<h3 style='margin-top:40px; margin-bottom:16px; color:#0f172a;'>Ringkasan Data per Obat</h3>";
            $stmt = $db->query("SELECT o.nama_obat,
                               COUNT(dh.id) as jml_minggu,
                               MIN(dh.tanggal) as tgl_awal,
                               MAX(dh.tanggal) as tgl_akhir,
                               SUM(dh.jumlah_keluar) as total_keluar,
                               ROUND(AVG(dh.jumlah_keluar), 2) as avg_keluar,
                               o.stok_saat_ini
                        FROM obat o
                        LEFT JOIN data_historis dh ON dh.obat_id = o.id
                        WHERE o.id BETWEEN 1 AND 5
                        GROUP BY o.id, o.nama_obat
                        ORDER BY o.id");
            echo "<div class='table-container mb-4'><table class='data-table'><thead><tr><th>Obat</th><th>Jml Minggu</th><th>Tgl Awal</th><th>Tgl Akhir</th><th>Total Keluar</th><th>Avg/Minggu</th><th>Stok Sekarang</th></tr></thead><tbody>";
            while ($r = $stmt->fetch()) {
                echo "<tr><td><b>{$r['nama_obat']}</b></td><td>{$r['jml_minggu']}</td><td>{$r['tgl_awal']}</td><td>{$r['tgl_akhir']}</td><td>{$r['total_keluar']}</td><td>{$r['avg_keluar']}</td><td><b>{$r['stok_saat_ini']}</b></td></tr>";
            }
            echo "</tbody></table></div>";

            echo "<div class='log-ok' style='margin-top:20px;'><i data-lucide='check-circle' style='display:inline-block; vertical-align:middle; margin-right:6px;'></i> <b>Import selesai. Sistem siap menjalankan prediksi LSTM dengan data skripsi.</b></div>";
            echo "<div style='margin-top:30px; text-align:right;'><a href='" . BASE_URL . "/pages/predictions.php' class='btn btn-primary' style='padding: 12px 24px;'><i data-lucide='cpu' style='margin-right:8px;'></i> Mulai Prediksi AI</a></div>";

        } catch (Exception $e) {
            echo "<div class='log-err'>ERROR: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
        ?>
    </div> <!-- setup-container -->
    <script>
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    </script>
</body>

</html>