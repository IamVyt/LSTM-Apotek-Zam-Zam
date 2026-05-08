<?php
/**
 * Inventory API - CRUD operations for obat (drugs)
 * Apotek Zam Zam
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
requireAPIAuth();

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($method) {
        case 'GET':
            handleGet($db, $action);
            break;
        case 'POST':
            handlePost($db);
            break;
        default:
            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }
} catch (Exception $e) {
    error_log("Inventory API Error: " . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Internal server error'], 500);
}

function handleGet(PDO $db, string $action): void {
    switch ($action) {
        case 'get':
            getSingle($db);
            break;
        case 'get_history':
            getHistory($db);
            break;
        case 'list':
        default:
            getList($db);
            break;
    }
}

function getList(PDO $db): void {
    $page = max(1, inputInt('page', 1));
    $search = inputString('search');
    $kategori = inputString('kategori');
    $status = inputString('status');
    $perPage = ITEMS_PER_PAGE;

    $where = ["o.status >= 0"];
    $params = [];

    if ($search !== '') {
        $where[] = "(o.nama_obat LIKE :search1 OR o.kode_obat LIKE :search2 OR k.nama_kategori LIKE :search3)";
        $params[':search1'] = "%{$search}%";
        $params[':search2'] = "%{$search}%";
        $params[':search3'] = "%{$search}%";
    }

    if ($kategori !== '' && $kategori !== 'all') {
        $where[] = "o.kategori = :kategori";
        $params[':kategori'] = $kategori;
    }

    if ($status === 'kritis') {
        $where[] = "o.stok_saat_ini <= o.stok_minimum AND o.stok_saat_ini > 0";
    } elseif ($status === 'aman') {
        $where[] = "o.stok_saat_ini > o.stok_minimum * 1.5";
    } elseif ($status === 'waspada') {
        $where[] = "o.stok_saat_ini > o.stok_minimum AND o.stok_saat_ini <= o.stok_minimum * 1.5";
    } elseif ($status === 'habis') {
        $where[] = "o.stok_saat_ini <= 0";
    }

    $whereSQL = implode(' AND ', $where);

    // Count total
    $countStmt = $db->prepare("SELECT COUNT(*) FROM obat o LEFT JOIN kategori_obat k ON o.kategori = k.id WHERE {$whereSQL}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $pagination = paginate($total, $perPage, $page);

    // Fetch records
    $sql = "SELECT o.*, k.nama_kategori, s.nama_supplier
            FROM obat o
            LEFT JOIN kategori_obat k ON o.kategori = k.id
            LEFT JOIN supplier s ON o.supplier_id = s.id
            WHERE {$whereSQL}
            ORDER BY o.updated_at DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $db->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
    $stmt->execute();

    $obat = $stmt->fetchAll();

    // Enrich with status badge info
    foreach ($obat as &$item) {
        $badge = getStatusBadge((int)$item['stok_saat_ini'], (int)$item['stok_minimum']);
        $item['status_label'] = $badge['label'];
        $item['status_class'] = $badge['class'];
        $pct = $item['stok_minimum'] > 0 ? min(100, round(($item['stok_saat_ini'] / $item['stok_minimum']) * 100)) : 100;
        $item['stok_pct'] = $pct;
        $item['progress_color'] = getProgressColor($pct);
    }

    jsonResponse([
        'success'    => true,
        'data'       => $obat,
        'pagination' => $pagination
    ]);
}

function getSingle(PDO $db): void {
    $id = inputInt('id');
    if (!$id) {
        jsonResponse(['success' => false, 'message' => 'ID diperlukan'], 400);
    }

    $stmt = $db->prepare("SELECT o.*, k.nama_kategori, s.nama_supplier
                           FROM obat o
                           LEFT JOIN kategori_obat k ON o.kategori = k.id
                           LEFT JOIN supplier s ON o.supplier_id = s.id
                           WHERE o.id = ?");
    $stmt->execute([$id]);
    $obat = $stmt->fetch();

    if (!$obat) {
        jsonResponse(['success' => false, 'message' => 'Data tidak ditemukan'], 404);
    }

    jsonResponse(['success' => true, 'data' => $obat]);
}

function getHistory(PDO $db): void {
    $page = max(1, inputInt('page', 1));
    $search = inputString('search');
    $perPage = ITEMS_PER_PAGE;

    $where = ["1=1"];
    $params = [];

    if ($search !== '') {
        $where[] = "(o.nama_obat LIKE :search1 OR o.kode_obat LIKE :search2 OR k.nama_kategori LIKE :search3)";
        $params[':search1'] = "%{$search}%";
        $params[':search2'] = "%{$search}%";
        $params[':search3'] = "%{$search}%";
    }

    $whereSQL = implode(' AND ', $where);

    // Count total
    $countStmt = $db->prepare("SELECT COUNT(*) FROM data_historis h JOIN obat o ON h.obat_id = o.id LEFT JOIN kategori_obat k ON o.kategori = k.id WHERE {$whereSQL}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $pagination = paginate($total, $perPage, $page);

    // Fetch records
    $sql = "SELECT h.*, o.nama_obat, o.kode_obat, k.nama_kategori
            FROM data_historis h
            JOIN obat o ON h.obat_id = o.id
            LEFT JOIN kategori_obat k ON o.kategori = k.id
            WHERE {$whereSQL}
            ORDER BY h.tanggal DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $db->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
    $stmt->execute();

    $history = $stmt->fetchAll();

    jsonResponse([
        'success'    => true,
        'data'       => $history,
        'pagination' => $pagination
    ]);
}

function handlePost(PDO $db): void {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    $action = $input['action'] ?? '';

    switch ($action) {
        case 'add':
            addObat($db, $input);
            break;
        case 'edit':
            editObat($db, $input);
            break;
        case 'delete':
            deleteObat($db, $input);
            break;
        case 'upload_history':
            uploadHistory($db, $input);
            break;
        default:
            jsonResponse(['success' => false, 'message' => 'Action tidak valid'], 400);
    }
}

function addObat(PDO $db, array $input): void {
    $required = ['nama_obat', 'kategori', 'bentuk_sediaan', 'satuan', 'stok_saat_ini', 'stok_minimum', 'harga_satuan'];
    foreach ($required as $field) {
        if (empty($input[$field]) && $input[$field] !== '0') {
            jsonResponse(['success' => false, 'message' => "Field {$field} diperlukan"], 400);
        }
    }

    $kodeObat = generateKodeObat();

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("INSERT INTO obat (kode_obat, nama_obat, kategori, bentuk_sediaan, satuan, stok_saat_ini, stok_minimum, harga_satuan, supplier_id, tanggal_kadaluarsa, status, created_at, updated_at)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())");
        $stmt->execute([
            $kodeObat,
            $input['nama_obat'],
            (int)$input['kategori'],
            $input['bentuk_sediaan'],
            $input['satuan'],
            (int)$input['stok_saat_ini'],
            (int)$input['stok_minimum'],
            (float)$input['harga_satuan'],
            !empty($input['supplier_id']) ? (int)$input['supplier_id'] : null,
            !empty($input['tanggal_kadaluarsa']) ? $input['tanggal_kadaluarsa'] : null
        ]);

        $obatId = (int)$db->lastInsertId();

        // Record initial stock transaction
        if ((int)$input['stok_saat_ini'] > 0) {
            $stmt2 = $db->prepare("INSERT INTO transaksi_stok (obat_id, jenis, jumlah, stok_sebelum, stok_sesudah, keterangan, user_id, tanggal, created_at)
                                    VALUES (?, 'masuk', ?, 0, ?, 'Stok awal', ?, NOW(), NOW())");
            $stmt2->execute([$obatId, (int)$input['stok_saat_ini'], (int)$input['stok_saat_ini'], $_SESSION['user_id']]);
        }

        $db->commit();
        jsonResponse(['success' => true, 'message' => 'Obat berhasil ditambahkan', 'data' => ['id' => $obatId, 'kode_obat' => $kodeObat]]);
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Add Obat Error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Gagal menambahkan obat'], 500);
    }
}

function editObat(PDO $db, array $input): void {
    $id = (int)($input['id'] ?? 0);
    if (!$id) {
        jsonResponse(['success' => false, 'message' => 'ID diperlukan'], 400);
    }

    // Fetch current data
    $current = $db->prepare("SELECT * FROM obat WHERE id = ?");
    $current->execute([$id]);
    $currentObat = $current->fetch();

    if (!$currentObat) {
        jsonResponse(['success' => false, 'message' => 'Data tidak ditemukan'], 404);
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("UPDATE obat SET
            nama_obat = ?, kategori = ?, bentuk_sediaan = ?, satuan = ?,
            stok_saat_ini = ?, stok_minimum = ?, harga_satuan = ?,
            supplier_id = ?, tanggal_kadaluarsa = ?, updated_at = NOW()
            WHERE id = ?");
        $stmt->execute([
            $input['nama_obat'] ?? $currentObat['nama_obat'],
            (int)($input['kategori'] ?? $currentObat['kategori']),
            $input['bentuk_sediaan'] ?? $currentObat['bentuk_sediaan'],
            $input['satuan'] ?? $currentObat['satuan'],
            (int)($input['stok_saat_ini'] ?? $currentObat['stok_saat_ini']),
            (int)($input['stok_minimum'] ?? $currentObat['stok_minimum']),
            (float)($input['harga_satuan'] ?? $currentObat['harga_satuan']),
            !empty($input['supplier_id']) ? (int)$input['supplier_id'] : $currentObat['supplier_id'],
            !empty($input['tanggal_kadaluarsa']) ? $input['tanggal_kadaluarsa'] : $currentObat['tanggal_kadaluarsa'],
            $id
        ]);

        // Log stock change as adjustment if stock changed
        $newStok = (int)($input['stok_saat_ini'] ?? $currentObat['stok_saat_ini']);
        $oldStok = (int)$currentObat['stok_saat_ini'];
        if ($newStok !== $oldStok) {
            $stmt2 = $db->prepare("INSERT INTO transaksi_stok (obat_id, jenis, jumlah, stok_sebelum, stok_sesudah, keterangan, user_id, tanggal, created_at)
                                    VALUES (?, 'penyesuaian', ?, ?, ?, 'Penyesuaian stok manual', ?, NOW(), NOW())");
            $stmt2->execute([$id, abs($newStok - $oldStok), $oldStok, $newStok, $_SESSION['user_id']]);
        }

        $db->commit();
        jsonResponse(['success' => true, 'message' => 'Data obat berhasil diperbarui']);
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Edit Obat Error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Gagal memperbarui data obat'], 500);
    }
}

function deleteObat(PDO $db, array $input): void {
    $id = (int)($input['id'] ?? 0);
    if (!$id) {
        jsonResponse(['success' => false, 'message' => 'ID diperlukan'], 400);
    }

    // Soft delete
    $stmt = $db->prepare("UPDATE obat SET status = 0, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$id]);

    jsonResponse(['success' => true, 'message' => 'Obat berhasil dihapus']);
}

function uploadHistory(PDO $db, array $input): void {
    $rows = $input['rows'] ?? [];

    if (empty($rows)) {
        jsonResponse(['success' => false, 'message' => 'Data histori kosong'], 400);
    }

    $db->beginTransaction();
    try {
        // Build a lookup map for nama_obat -> obat_id
        $stmtObat = $db->query("SELECT id, LOWER(nama_obat) as nama_obat FROM obat");
        $obatMap = [];
        while ($row = $stmtObat->fetch()) {
            $obatMap[$row['nama_obat']] = (int)$row['id'];
        }

        // Group rows by resolved obat_id
        $grouped = [];
        foreach ($rows as $row) {
            $namaObat = strtolower(trim($row['nama_obat'] ?? ''));
            $obatId = $row['obat_id'] ?? ($obatMap[$namaObat] ?? 0);

            if (!$obatId) {
                continue; // Skip if drug not found
            }

            if (!isset($grouped[$obatId])) {
                $grouped[$obatId] = [];
            }
            $grouped[$obatId][] = $row;
        }

        if (empty($grouped)) {
            throw new Exception("Tidak ada data valid yang cocok dengan nama obat di database.");
        }

        $stmtDel = $db->prepare("DELETE FROM data_historis WHERE obat_id = ?");
        $stmtIns = $db->prepare("INSERT INTO data_historis 
            (obat_id, minggu_ke, tanggal, tanggal_akhir, stok_awal, jumlah_masuk, jumlah_keluar, stok_akhir, rata_rata_keluar) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $count = 0;
        foreach ($grouped as $obatId => $obatRows) {
            // Clear existing history for this drug
            $stmtDel->execute([$obatId]);

            // Insert new history
            foreach ($obatRows as $row) {
                // Sanitasi tanggal: konversi M/D/YYYY (Excel) → YYYY-MM-DD (MySQL)
                $tanggal      = parseDate($row['tanggal'] ?? $row['tanggal_mulai'] ?? '');
                $tanggalAkhir = parseDate($row['tanggal_akhir'] ?? $tanggal);
                $mingguKe = (int)($row['minggu_ke'] ?? 1);
                
                $stmtIns->execute([
                    $obatId,
                    $mingguKe,
                    $tanggal,
                    $tanggalAkhir,
                    (int)($row['stok_awal'] ?? 0),
                    (int)($row['jumlah_masuk'] ?? 0),
                    (int)($row['jumlah_keluar'] ?? 0),
                    (int)($row['stok_akhir'] ?? 0),
                    (float)($row['rata_rata_keluar'] ?? 0)
                ]);
                $count++;
            }
        }

        $db->commit();
        jsonResponse(['success' => true, 'message' => "$count baris data historis berhasil disimpan"]);
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Upload History Error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

/**
 * Konversi berbagai format tanggal ke YYYY-MM-DD untuk MySQL.
 * Menangani: YYYY-MM-DD, M/D/YYYY, MM/DD/YYYY, D-M-YYYY
 */
function parseDate(string $dateStr): string {
    $dateStr = trim($dateStr);
    if (empty($dateStr)) return date('Y-m-d');

    // Sudah YYYY-MM-DD
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) return $dateStr;

    // Format M/D/YYYY atau MM/DD/YYYY (default export Excel)
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2,4})$#', $dateStr, $m)) {
        $year = strlen($m[3]) === 2 ? '20' . $m[3] : $m[3];
        return sprintf('%04d-%02d-%02d', $year, $m[1], $m[2]);
    }

    // Format D-M-YYYY (Indonesia dengan tanda hubung)
    if (preg_match('#^(\d{1,2})-(\d{1,2})-(\d{4})$#', $dateStr, $m)) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }

    // Fallback: coba strtotime
    $ts = strtotime($dateStr);
    if ($ts !== false) return date('Y-m-d', $ts);

    return date('Y-m-d');
}
