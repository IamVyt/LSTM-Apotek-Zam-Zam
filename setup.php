<?php
/**
 * Database Setup & Seed Script
 * Apotek Zam Zam - Pharmacy Drug Inventory Prediction System
 *
 * Run this script ONCE to create all tables and seed data.
 * Access via browser: http://localhost/pharmapredictt/setup.php
 *
 * Seeds 5 drugs with 52 weeks (1 year) of weekly historical data.
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(120);

$host = 'localhost';
$dbName = 'pharmapredictt';
$username = 'root';
$password = '';

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Apotek Zam Zam Setup</title>";
echo "<style>body{font-family:'Segoe UI',sans-serif;max-width:800px;margin:40px auto;padding:20px;background:#f0f4f8;color:#1a1a2e;}";
echo ".ok{color:#065F46;background:#D1FAE5;padding:8px 14px;border-radius:8px;margin:6px 0;font-size:14px;}";
echo ".err{color:#991B1B;background:#FEE2E2;padding:8px 14px;border-radius:8px;margin:6px 0;font-size:14px;}";
echo ".info{color:#1D4ED8;background:#DBEAFE;padding:8px 14px;border-radius:8px;margin:6px 0;font-size:14px;}";
echo "h1{color:#1D9E75;} h2{color:#0F6E56;margin-top:24px;}</style></head><body>";
echo "<h1>🏥 Apotek Zam Zam — Database Setup</h1>";

try {
    // Create database if not exists
    $pdo = new PDO("mysql:host={$host};charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$dbName}`");
    echo "<div class='ok'>✅ Database '{$dbName}' siap.</div>";

    // ─── DROP EXISTING TABLES (handles orphaned tablespace) ───
    echo "<h2>🗑️ Membersihkan tabel lama...</h2>";
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $tables = ['pengaturan', 'data_historis', 'prediksi_lstm', 'transaksi_stok', 'obat', 'supplier', 'kategori_obat', 'users'];
    foreach ($tables as $tbl) {
        $pdo->exec("DROP TABLE IF EXISTS `{$tbl}`");
        echo "<div class='ok'>✅ Dropped {$tbl}</div>";
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    // ─── CREATE TABLES ───
    echo "<h2>📋 Membuat Tabel...</h2>";

    // 1. users
    $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `username` VARCHAR(50) NOT NULL UNIQUE,
        `email` VARCHAR(100) NOT NULL UNIQUE,
        `password` VARCHAR(255) NOT NULL,
        `full_name` VARCHAR(100) NOT NULL,
        `role` ENUM('admin','apoteker','asisten') NOT NULL DEFAULT 'asisten',
        `status` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_username` (`username`),
        INDEX `idx_role` (`role`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "<div class='ok'>✅ Tabel users</div>";

    // 2. kategori_obat
    $pdo->exec("CREATE TABLE IF NOT EXISTS `kategori_obat` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `nama_kategori` VARCHAR(100) NOT NULL,
        `deskripsi` TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "<div class='ok'>✅ Tabel kategori_obat</div>";

    // 3. supplier
    $pdo->exec("CREATE TABLE IF NOT EXISTS `supplier` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `nama_supplier` VARCHAR(100) NOT NULL,
        `kontak` VARCHAR(50),
        `alamat` TEXT,
        `email` VARCHAR(100),
        `status` TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "<div class='ok'>✅ Tabel supplier</div>";

    // 4. obat
    $pdo->exec("CREATE TABLE IF NOT EXISTS `obat` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `nama_obat` VARCHAR(150) NOT NULL,
        `kategori` INT,
        `bentuk_sediaan` VARCHAR(50),
        `satuan` VARCHAR(30),
        `stok_saat_ini` INT NOT NULL DEFAULT 0,
        `stok_minimum` INT NOT NULL DEFAULT 10,
        `harga_satuan` DECIMAL(12,2) NOT NULL DEFAULT 0,
        `supplier_id` INT,
        `tanggal_kadaluarsa` DATE,
        `status` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_nama` (`nama_obat`),
        INDEX `idx_kategori` (`kategori`),
        INDEX `idx_status` (`status`),
        FOREIGN KEY (`kategori`) REFERENCES `kategori_obat`(`id`) ON DELETE SET NULL,
        FOREIGN KEY (`supplier_id`) REFERENCES `supplier`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "<div class='ok'>✅ Tabel obat</div>";

    // 5. transaksi_stok
    $pdo->exec("CREATE TABLE IF NOT EXISTS `transaksi_stok` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `obat_id` INT NOT NULL,
        `jenis` ENUM('masuk','keluar','penyesuaian') NOT NULL,
        `jumlah` INT NOT NULL,
        `stok_sebelum` INT NOT NULL DEFAULT 0,
        `stok_sesudah` INT NOT NULL DEFAULT 0,
        `keterangan` TEXT,
        `user_id` INT,
        `tanggal` DATETIME NOT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_obat` (`obat_id`),
        INDEX `idx_jenis` (`jenis`),
        INDEX `idx_tanggal` (`tanggal`),
        FOREIGN KEY (`obat_id`) REFERENCES `obat`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "<div class='ok'>✅ Tabel transaksi_stok</div>";

    // 6. prediksi_lstm
    $pdo->exec("CREATE TABLE IF NOT EXISTS `prediksi_lstm` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `obat_id` INT NOT NULL,
        `tanggal_prediksi` DATETIME NOT NULL,
        `nilai_prediksi` JSON,
        `confidence` DECIMAL(5,2),
        `mae` DECIMAL(10,6),
        `rmse` DECIMAL(10,6),
        `mape` DECIMAL(8,4),
        `akurasi` DECIMAL(5,2),
        `model_params` JSON,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_obat` (`obat_id`),
        INDEX `idx_tanggal` (`tanggal_prediksi`),
        FOREIGN KEY (`obat_id`) REFERENCES `obat`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "<div class='ok'>✅ Tabel prediksi_lstm</div>";

    // 7. data_historis (weekly data — 5 fitur untuk Multivariate LSTM)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `data_historis` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `obat_id` INT NOT NULL,
        `minggu_ke` INT NOT NULL DEFAULT 1,
        `tanggal` DATE NOT NULL,
        `tanggal_akhir` DATE NULL,
        `stok_awal` INT NOT NULL DEFAULT 0,
        `jumlah_masuk` INT NOT NULL DEFAULT 0,
        `jumlah_keluar` INT NOT NULL DEFAULT 0,
        `stok_akhir` INT NOT NULL DEFAULT 0,
        `rata_rata_keluar` DECIMAL(10,2) NOT NULL DEFAULT 0,
        INDEX `idx_obat_tanggal` (`obat_id`, `tanggal`),
        FOREIGN KEY (`obat_id`) REFERENCES `obat`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "<div class='ok'>✅ Tabel data_historis (5 fitur multivariate)</div>";

    // 8. pengaturan
    $pdo->exec("CREATE TABLE IF NOT EXISTS `pengaturan` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `key` VARCHAR(100) NOT NULL UNIQUE,
        `value` TEXT,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "<div class='ok'>✅ Tabel pengaturan</div>";

    // ─── SEED DATA ───
    echo "<h2>🌱 Menanam Data Awal...</h2>";

    // 1. Admin user (password: admin123)
    $adminHash = password_hash('admin123', PASSWORD_BCRYPT);
    $pdo->prepare("INSERT INTO users (username, email, password, full_name, role, status) VALUES (?, ?, ?, ?, ?, 1)")
        ->execute(['admin', 'admin@apotekzamzam.com', $adminHash, 'Administrator', 'admin']);

    $apotekerHash = password_hash('apoteker123', PASSWORD_BCRYPT);
    $pdo->prepare("INSERT INTO users (username, email, password, full_name, role, status) VALUES (?, ?, ?, ?, ?, 1)")
        ->execute(['apoteker', 'apoteker@apotekzamzam.com', $apotekerHash, 'Dr. Siti Rahayu', 'apoteker']);

    echo "<div class='ok'>✅ 2 user (admin/admin123, apoteker/apoteker123)</div>";

    // 2. Kategori obat (sesuai CSV)
    $kategoris = [
        ['Antihipertensi', 'Obat untuk tekanan darah tinggi'],
        ['Analgesik', 'Obat penghilang rasa nyeri'],
        ['Antiinflamasi', 'Obat untuk peradangan'],
    ];
    $stmtKat = $pdo->prepare("INSERT INTO kategori_obat (nama_kategori, deskripsi) VALUES (?, ?)");
    foreach ($kategoris as $k)
        $stmtKat->execute($k);
    echo "<div class='ok'>✅ 3 kategori obat (Antihipertensi, Analgesik, Antiinflamasi)</div>";

    // 3. Supplier
    $pdo->exec("INSERT INTO supplier (nama_supplier, kontak, alamat, email, status) VALUES
        ('PT. Kimia Farma', '021-4287-0000', 'Jl. Veteran No.9, Jakarta Pusat', 'order@kimiafarma.co.id', 1),
        ('PT. Kalbe Farma', '021-4259-6300', 'Jl. Let.Jend. Suprapto Kav.4, Jakarta', 'cs@kalbe.co.id', 1)");
    echo "<div class='ok'>✅ 2 supplier</div>";

    // 4. Obat (5 obat sesuai data skripsi CSV)
    $obatData = [
        // [nama, kategori_id, bentuk, satuan, stok, stok_min, harga, supplier_id, kadaluarsa]
        ['AMLODIPIN', 1, 'Tablet', 'Tablet', 4420, 100, 1500.00, 1, '2027-06-15'],
        ['CANDESARTAN', 1, 'Tablet', 'Tablet', 361, 50, 2500.00, 2, '2027-09-20'],
        ['IBUPROFEN', 2, 'Tablet', 'Tablet', 761, 60, 2100.00, 1, '2027-08-10'],
        ['PARASETAMOL', 2, 'Tablet', 'Tablet', 1140, 100, 1200.00, 2, '2027-12-01'],
        ['PIROXICAM', 3, 'Kapsul', 'Kapsul', 1700, 50, 1800.00, 1, '2027-10-15'],
    ];

    $stmtObat = $pdo->prepare("INSERT INTO obat (nama_obat, kategori, bentuk_sediaan, satuan, stok_saat_ini, stok_minimum, harga_satuan, supplier_id, tanggal_kadaluarsa, status, created_at, updated_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())");

    foreach ($obatData as $o) {
        $stmtObat->execute($o);
    }
    echo "<div class='ok'>✅ 5 obat (AMLODIPIN, CANDESARTAN, IBUPROFEN, PARASETAMOL, PIROXICAM)</div>";

    // 5. Transaksi stok awal
    $adminId = 1;
    $stmtTx = $pdo->prepare("INSERT INTO transaksi_stok (obat_id, jenis, jumlah, stok_sebelum, stok_sesudah, keterangan, user_id, tanggal, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");

    for ($i = 1; $i <= 5; $i++) {
        $stok = $obatData[$i - 1][4]; // stok_saat_ini
        if ($stok > 0) {
            $stmtTx->execute([$i, 'masuk', $stok, 0, $stok, 'Stok awal sistem', $adminId, date('Y-m-d H:i:s', strtotime('-52 weeks'))]);
        }
    }

    // Additional sample transactions
    $sampleTx = [
        [1, 'keluar', 30, 500, 470, 'Penjualan - Resep Hipertensi', 1],
        [2, 'keluar', 20, 400, 380, 'Penjualan - Resep Hipertensi', 2],
        [3, 'keluar', 40, 600, 560, 'Penjualan - Keluhan Nyeri', 1],
        [4, 'keluar', 15, 350, 335, 'Penjualan - Demam/Pusing', 2],
        [5, 'keluar', 25, 300, 275, 'Penjualan - Antiinflamasi', 1],
        [1, 'masuk', 200, 470, 670, 'Restock dari Kimia Farma', 1],
        [3, 'masuk', 150, 560, 710, 'Restock dari Kimia Farma', 1],
    ];

    foreach ($sampleTx as $tx) {
        $stmtTx->execute([$tx[0], $tx[1], $tx[2], $tx[3], $tx[4], $tx[5], $tx[6], date('Y-m-d H:i:s', strtotime('-' . rand(1, 14) . ' days'))]);
    }
    echo "<div class='ok'>✅ Transaksi stok sampel</div>";

    // ─── 6. Data historis MINGGUAN (52 minggu per obat = 260 records) ───
    echo "<div class='info'>⏳ Generating 52 minggu data historis untuk 5 obat (260 records, 5 fitur multivariate)...</div>";

    $stmtHist = $pdo->prepare("INSERT INTO data_historis (obat_id, tanggal, stok_awal, jumlah_masuk, jumlah_keluar, stok_akhir, rata_rata_keluar) VALUES (?, ?, ?, ?, ?, ?, ?)");

    // Weekly consumption patterns per drug (base weekly avg)

    $drugPatterns = [
        1 => [ // AMLODIPIN
            'base' => 1200,
            'name' => 'AMLODIPIN',
            'seasonal' => function ($week) { return 1.0; },
            'noise_pct' => 15,
        ],
        2 => [ // CANDESARTAN
            'base' => 180,
            'name' => 'CANDESARTAN',
            'seasonal' => function ($week) { return 1.0; },
            'noise_pct' => 15,
        ],
        3 => [ // IBUPROFEN
            'base' => 200,
            'name' => 'IBUPROFEN',
            'seasonal' => function ($week) { return 1.0; },
            'noise_pct' => 15,
        ],
        4 => [ // PARASETAMOL
            'base' => 700,
            'name' => 'PARASETAMOL',
            'seasonal' => function ($week) { return 1.0; },
            'noise_pct' => 15,
        ],
        5 => [ // PIROXICAM
            'base' => 300,
            'name' => 'PIROXICAM',
            'seasonal' => function ($week) { return 1.0; },
            'noise_pct' => 15,
        ],
    ];

    // Generate weekly data going back 52 weeks from now
    foreach ($drugPatterns as $obatId => $pattern) {
        $stok = $obatData[$obatId - 1][5] + 500; // Start with higher initial stock
        $baseRate = $pattern['base'];
        $noisePct = $pattern['noise_pct'];
        $seasonalFn = $pattern['seasonal'];

        for ($week = 52; $week >= 1; $week--) {
            // Calculate the Monday of this week
            $tanggal = date('Y-m-d', strtotime("-{$week} weeks monday"));
            $weekOfYear = (int) date('W', strtotime($tanggal));

            // ── Seasonal multiplier ──
            $seasonal = $seasonalFn($weekOfYear);

            // ── Gradual trend (slight upward growth ~5% per year) ──
            $trendMultiplier = 1.0 + (0.05 * (52 - $week) / 52);

            // ── Random noise ──
            $noise = 1.0 + (mt_rand(-$noisePct, $noisePct) / 100);

            // ── Occasional spike (5% chance: flu outbreak, event, etc.) ──
            $spike = (mt_rand(1, 100) <= 5) ? 1.5 : 1.0;

            // ── Calculate weekly consumption ──
            $keluar = max(1, (int) round($baseRate * $seasonal * $trendMultiplier * $noise * $spike));

            // ── Restocking pattern (approximately every 4 weeks or when stock is low) ──
            $masuk = 0;
            if ($week % 4 === 0 || $stok < $baseRate * 3) {
                $masuk = $baseRate * mt_rand(3, 6);
            }

            $stokAwal = $stok; // Stok awal minggu ini = stok akhir minggu lalu
            $stok = $stok + $masuk - $keluar;
            $stok = max(0, $stok);
            $rataRata = round($keluar / 7.0, 2);

            $stmtHist->execute([$obatId, $tanggal, $stokAwal, $masuk, $keluar, $stok, $rataRata]);
        }
    }
    echo "<div class='ok'>✅ 260 records data historis mingguan (52 minggu × 5 obat)</div>";

    // ─── 7. Default settings ───
    $defaultSettings = [
        ['nama_apotek', 'Apotek Zam Zam'],
        ['alamat_apotek', 'Jl. Kesehatan No. 123, Jakarta'],
        ['telp_apotek', '021-1234-5678'],
        ['email_apotek', 'info@apotekzamzam.com'],
        ['notif_stok_kritis', '1'],
        ['notif_prediksi', '1'],
        ['notif_kadaluarsa', '1'],
    ];

    $stmtSet = $pdo->prepare("INSERT INTO pengaturan (`key`, value, updated_at) VALUES (?, ?, NOW())");
    foreach ($defaultSettings as $s) {
        $stmtSet->execute($s);
    }
    echo "<div class='ok'>✅ Default settings</div>";

    echo "<h2>🎉 Setup Selesai!</h2>";
    echo "<div class='ok' style='font-size:16px;padding:16px;'>";
    echo "<strong>Setup berhasil!</strong><br><br>";
    echo "📦 <strong>5 Obat:</strong> AMLODIPIN, CANDESARTAN, IBUPROFEN, PARASETAMOL, PIROXICAM<br>";
    echo "📊 <strong>Data Historis:</strong> 52 minggu (1 tahun) per obat<br>";
    echo "🧠 <strong>LSTM Engine:</strong> Multivariate LSTM Manual + BPTT (jalankan python/start_service.bat)<br><br>";
    echo "🔑 <strong>Login Admin:</strong> admin / admin123<br>";
    echo "🔑 <strong>Login Apoteker:</strong> apoteker / apoteker123<br><br>";
    echo "<a href='/pharmapredictt/login.php' style='color:#1D9E75;font-weight:700;font-size:18px;'>→ Masuk ke Apotek Zam Zam</a>";
    echo "</div>";

    echo "<div class='info' style='margin-top:16px;'>";
    echo "⚠️ <strong>Penting:</strong> Sebelum menjalankan prediksi LSTM, pastikan Python service sudah berjalan:<br>";
    echo "<code style='background:#1a1a2e;color:#4ade80;padding:4px 10px;border-radius:4px;display:inline-block;margin-top:6px;'>cd python && start_service.bat</code>";
    echo "</div>";

} catch (PDOException $e) {
    echo "<div class='err'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    echo "<div class='info'>Pastikan MySQL sedang berjalan dan kredensial database benar.</div>";
}

echo "</body></html>";
