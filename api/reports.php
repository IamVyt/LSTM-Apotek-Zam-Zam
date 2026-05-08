<?php
/**
 * Reports API - Aggregated data, export CSV
 * Apotek Zam Zam
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
requireAPIAuth();

$db = getDB();
$type = $_GET['type'] ?? 'stok';

try {
    switch ($type) {
        case 'stok':
            getStokReport($db);
            break;
        case 'transaksi':
            getTransaksiReport($db);
            break;
        case 'kategori':
            getKategoriReport($db);
            break;
        case 'status':
            getStatusReport($db);
            break;
        case 'export_csv':
            exportCSV($db);
            break;
        default:
            jsonResponse(['success' => false, 'message' => 'Tipe laporan tidak valid'], 400);
    }
} catch (Exception $e) {
    error_log("Reports API Error: " . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Internal server error'], 500);
}

function getStokReport(PDO $db): void {
    $from = inputString('from', date('Y-m-01'));
    $to = inputString('to', date('Y-m-d'));

    // Monthly stock movement by category
    $stmt = $db->prepare("SELECT
        k.nama_kategori,
        DATE_FORMAT(t.tanggal, '%Y-%m') AS bulan,
        SUM(CASE WHEN t.jenis = 'masuk' THEN t.jumlah ELSE 0 END) AS total_masuk,
        SUM(CASE WHEN t.jenis = 'keluar' THEN t.jumlah ELSE 0 END) AS total_keluar
        FROM transaksi_stok t
        JOIN obat o ON t.obat_id = o.id
        LEFT JOIN kategori_obat k ON o.kategori = k.id
        WHERE t.tanggal BETWEEN ? AND ?
        GROUP BY k.nama_kategori, bulan
        ORDER BY bulan, k.nama_kategori");
    $stmt->execute([$from, $to]);
    $data = $stmt->fetchAll();

    // Summary stats
    $stmtSummary = $db->prepare("SELECT
        COUNT(DISTINCT t.obat_id) AS obat_terlibat,
        SUM(CASE WHEN t.jenis = 'masuk' THEN t.jumlah ELSE 0 END) AS total_masuk,
        SUM(CASE WHEN t.jenis = 'keluar' THEN t.jumlah ELSE 0 END) AS total_keluar,
        COUNT(*) AS total_transaksi
        FROM transaksi_stok t
        WHERE t.tanggal BETWEEN ? AND ?");
    $stmtSummary->execute([$from, $to]);
    $summary = $stmtSummary->fetch();

    jsonResponse([
        'success' => true,
        'data'    => $data,
        'summary' => $summary,
        'period'  => ['from' => $from, 'to' => $to]
    ]);
}

function getTransaksiReport(PDO $db): void {
    $from = inputString('from', date('Y-m-01'));
    $to = inputString('to', date('Y-m-d'));
    $page = max(1, inputInt('page', 1));
    $perPage = ITEMS_PER_PAGE;

    $countStmt = $db->prepare("SELECT COUNT(*) FROM transaksi_stok t WHERE t.tanggal BETWEEN ? AND ?");
    $countStmt->execute([$from, $to]);
    $total = (int) $countStmt->fetchColumn();

    $pagination = paginate($total, $perPage, $page);

    $stmt = $db->prepare("SELECT t.*, o.nama_obat, o.kode_obat, u.full_name AS user_name
                           FROM transaksi_stok t
                           JOIN obat o ON t.obat_id = o.id
                           LEFT JOIN users u ON t.user_id = u.id
                           WHERE t.tanggal BETWEEN ? AND ?
                           ORDER BY t.created_at DESC
                           LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $from);
    $stmt->bindValue(2, $to);
    $stmt->bindValue(3, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(4, $pagination['offset'], PDO::PARAM_INT);
    $stmt->execute();
    $transaksi = $stmt->fetchAll();

    jsonResponse([
        'success'    => true,
        'data'       => $transaksi,
        'pagination' => $pagination
    ]);
}

function getKategoriReport(PDO $db): void {
    $stmt = $db->prepare("SELECT k.nama_kategori, COUNT(o.id) AS jumlah_obat,
                           SUM(o.stok_saat_ini) AS total_stok,
                           SUM(o.stok_saat_ini * o.harga_satuan) AS nilai_stok
                           FROM kategori_obat k
                           LEFT JOIN obat o ON o.kategori = k.id AND o.status = 1
                           GROUP BY k.id, k.nama_kategori
                           ORDER BY jumlah_obat DESC");
    $stmt->execute();
    $data = $stmt->fetchAll();

    jsonResponse(['success' => true, 'data' => $data]);
}

function getStatusReport(PDO $db): void {
    $stmt = $db->prepare("SELECT
        SUM(CASE WHEN stok_saat_ini > stok_minimum * 1.5 THEN 1 ELSE 0 END) AS aman,
        SUM(CASE WHEN stok_saat_ini > stok_minimum AND stok_saat_ini <= stok_minimum * 1.5 THEN 1 ELSE 0 END) AS waspada,
        SUM(CASE WHEN stok_saat_ini > 0 AND stok_saat_ini <= stok_minimum THEN 1 ELSE 0 END) AS kritis,
        SUM(CASE WHEN stok_saat_ini <= 0 THEN 1 ELSE 0 END) AS habis
        FROM obat WHERE status = 1");
    $stmt->execute();
    $data = $stmt->fetch();

    jsonResponse(['success' => true, 'data' => $data]);
}

function exportCSV(PDO $db): void {
    $from = inputString('from', date('Y-m-01'));
    $to = inputString('to', date('Y-m-d'));

    $stmt = $db->prepare("SELECT t.tanggal, o.kode_obat, o.nama_obat, t.jenis,
                           t.jumlah, t.stok_sebelum, t.stok_sesudah, t.keterangan,
                           u.full_name AS petugas
                           FROM transaksi_stok t
                           JOIN obat o ON t.obat_id = o.id
                           LEFT JOIN users u ON t.user_id = u.id
                           WHERE t.tanggal BETWEEN ? AND ?
                           ORDER BY t.tanggal DESC");
    $stmt->execute([$from, $to]);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="laporan_transaksi_' . date('Ymd') . '.csv"');

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM

    fputcsv($output, ['Tanggal', 'Kode Obat', 'Nama Obat', 'Jenis', 'Jumlah', 'Stok Sebelum', 'Stok Sesudah', 'Keterangan', 'Petugas']);

    foreach ($rows as $row) {
        fputcsv($output, [
            $row['tanggal'], $row['kode_obat'], $row['nama_obat'],
            ucfirst($row['jenis']), $row['jumlah'], $row['stok_sebelum'],
            $row['stok_sesudah'], $row['keterangan'], $row['petugas']
        ]);
    }

    fclose($output);
    exit;
}
