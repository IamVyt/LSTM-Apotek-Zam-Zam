<?php
/**
 * Settings API - Save settings to pengaturan table
 * Apotek Zam Zam
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
requireAPIAuth();

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['settings'])) {
    jsonResponse(['success' => false, 'message' => 'Data tidak valid'], 400);
}

$settings = $input['settings'];

// Allowed setting keys
$allowedKeys = [
    'nama_apotek', 'alamat_apotek', 'telp_apotek', 'email_apotek',
    'lstm_sequence_length', 'lstm_hidden_units', 'lstm_epochs', 'lstm_learning_rate',
    'notif_stok_kritis', 'notif_prediksi', 'notif_kadaluarsa'
];

try {
    $db->beginTransaction();

    foreach ($settings as $key => $value) {
        if (!in_array($key, $allowedKeys)) continue;
        setSetting($db, $key, $value);
    }

    $db->commit();
    jsonResponse(['success' => true, 'message' => 'Pengaturan berhasil disimpan']);
} catch (Exception $e) {
    $db->rollBack();
    error_log("Settings API Error: " . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Gagal menyimpan pengaturan'], 500);
}
