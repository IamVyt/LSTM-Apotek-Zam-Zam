<?php
/**
 * Predictions API - Run LSTM predictions via Python service
 * Apotek Zam Zam
 *
 * This endpoint bridges the PHP frontend with the Python LSTM microservice.
 * Historical data is fetched from MySQL, sent to the Python Flask service,
 * and results are saved back to the database.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

// Allow generous timeout since Python LSTM training may take a while
set_time_limit(300);
ini_set('max_execution_time', '300');
ini_set('memory_limit', '256M');

requireAPIAuth();

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'POST') {
        $action = $_GET['action'] ?? '';
        if ($action === 'batch') {
            runBatchPrediction($db);
        } elseif ($action === 'delete') {
            deletePredictionHistory($db);
        } else {
            runPrediction($db);
        }
    } elseif ($method === 'GET') {
        $action = $_GET['action'] ?? 'history';
        if ($action === 'history') {
            getPredictionHistory($db);
        } elseif ($action === 'detail') {
            getPredictionDetail($db);
        } else {
            jsonResponse(['success' => false, 'message' => 'Action tidak valid'], 400);
        }
    } else {
        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }
} catch (Exception $e) {
    error_log("Predictions API Error: " . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Internal server error: ' . $e->getMessage()], 500);
}

function deletePredictionHistory(PDO $db): void
{
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = isset($input['id']) ? $input['id'] : 0;

    if ($id === 'all') {
        $db->exec("TRUNCATE TABLE prediksi_lstm");
        jsonResponse(['success' => true, 'message' => 'Semua riwayat berhasil dihapus']);
    } else {
        $id = (int) $id;
        $stmt = $db->prepare("DELETE FROM prediksi_lstm WHERE id = ?");
        $stmt->execute([$id]);
        jsonResponse(['success' => true, 'message' => 'Riwayat berhasil dihapus']);
    }
}

/**
 * Batch prediction: prediksi SEMUA obat sekaligus dalam 1 request.
 * Loop tiap obat di database, panggil Python LSTM untuk masing-masing,
 * agregasi hasil ke 1 response JSON. Cocok untuk dashboard / laporan
 * total kebutuhan stok semua obat dalam 1 periode.
 */
function runBatchPrediction(PDO $db): void
{
    set_time_limit(600);
    ini_set('max_execution_time', '600');
    ini_set('memory_limit', '512M');

    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $periode = (int) ($input['periode'] ?? 4);
    if ($periode < 1 || $periode > 52)
        $periode = 4;

    // Ambil semua obat aktif
    $obatList = $db->query("SELECT id, nama_obat, stok_saat_ini, stok_minimum
                            FROM obat WHERE status = 1 ORDER BY id ASC")->fetchAll();

    if (empty($obatList)) {
        jsonResponse(['success' => false, 'message' => 'Tidak ada obat aktif di database'], 400);
    }

    $pythonUrl = PYTHON_LSTM_URL . '/predict';
    $results = [];
    $errors = [];
    $startTotal = microtime(true);

    foreach ($obatList as $obat) {
        $obatId = (int) $obat['id'];

        // Ambil data historis 5 fitur multivariate
        $stmtHist = $db->prepare("SELECT stok_awal, jumlah_masuk, jumlah_keluar, stok_akhir, rata_rata_keluar
                                   FROM data_historis WHERE obat_id = ? ORDER BY tanggal ASC");
        $stmtHist->execute([$obatId]);
        $historical = $stmtHist->fetchAll();

        if (count($historical) < 5) {
            $errors[] = ['obat' => $obat['nama_obat'], 'message' => 'Data < 5 minggu, di-skip'];
            continue;
        }

        $multivariate = array_map(fn($r) => [
            (float) $r['stok_awal'],
            (float) $r['jumlah_masuk'],
            (float) $r['jumlah_keluar'],
            (float) $r['stok_akhir'],
            (float) $r['rata_rata_keluar']
        ], $historical);

        // Call Python LSTM
        $payload = json_encode([
            'drug_id' => $obatId,
            'historical_data' => $multivariate,
            'periode' => $periode,
        ]);

        $ch = curl_init($pythonUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $code !== 200) {
            $errors[] = ['obat' => $obat['nama_obat'], 'message' => 'Python service tidak merespons'];
            continue;
        }

        $pyResult = json_decode($resp, true);
        if (!$pyResult || !($pyResult['success'] ?? false)) {
            $errors[] = ['obat' => $obat['nama_obat'], 'message' => $pyResult['message'] ?? 'unknown'];
            continue;
        }

        $d = $pyResult['data'];

        // Simpan ke database (tabel prediksi_lstm)
        $stmt = $db->prepare("INSERT INTO prediksi_lstm
            (obat_id, tanggal_prediksi, nilai_prediksi, confidence, mae, rmse, mape, akurasi, model_params, created_at)
            VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([
            $obatId,
            json_encode($d['predictions']),
            $d['confidence'] ?? 0,
            $d['mae'] ?? 0,
            $d['rmse'] ?? 0,
            $d['mape'] ?? 0,
            $d['accuracy'] ?? 0,
            json_encode($d['model_params'] ?? [])
        ]);

        $results[] = [
            'obat_id' => $obatId,
            'nama_obat' => $obat['nama_obat'],
            'stok_saat_ini' => (int) $obat['stok_saat_ini'],
            'stok_minimum' => (int) $obat['stok_minimum'],
            'predictions' => $d['predictions'],
            'total_kebutuhan' => array_sum($d['predictions']),
            'avg_per_minggu' => round(array_sum($d['predictions']) / max(1, count($d['predictions'])), 2),
            'mae' => $d['mae'],
            'rmse' => $d['rmse'],
            'mape' => $d['mape'],
            'accuracy' => $d['accuracy'],
            'mape_class' => $d['mape_class'] ?? '-',
            'rekomendasi' => $d['rekomendasi'] ?? null,
            'epoch_terbaik' => $d['model_params']['epoch_terbaik'] ?? '-',
            'learning_rate' => $d['model_params']['learning_rate'] ?? '-',
            'training_time' => $d['model_params']['training_time_seconds'] ?? '-',
        ];
    }

    $totalTime = round(microtime(true) - $startTotal, 2);

    // Hitung statistik agregat
    $totalKebutuhan = array_sum(array_column($results, 'total_kebutuhan'));
    $avgMape = count($results) > 0 ? round(array_sum(array_column($results, 'mape')) / count($results), 2) : 0;
    $avgAccuracy = count($results) > 0 ? round(array_sum(array_column($results, 'accuracy')) / count($results), 2) : 0;

    jsonResponse([
        'success' => true,
        'data' => [
            'periode' => $periode,
            'total_obat_diprediksi' => count($results),
            'total_obat_error' => count($errors),
            'total_kebutuhan_semua_obat' => round($totalKebutuhan, 2),
            'rata_rata_mape' => $avgMape,
            'rata_rata_akurasi' => $avgAccuracy,
            'total_training_time' => $totalTime,
            'results' => $results,
            'errors' => $errors,
        ],
    ]);
}

/**
 * Run prediction by sending historical data to Python LSTM service
 */
function runPrediction(PDO $db): void
{
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    $obatId = (int) ($input['obat_id'] ?? 0);
    $periode = (int) ($input['periode'] ?? 4);

    // Manual hyperparameters (optional - from reference-style UX)
    $epochs = isset($input['epochs']) ? (int) $input['epochs'] : null;
    $learningRate = isset($input['learning_rate']) ? (float) $input['learning_rate'] : null;
    $windowSize = isset($input['window_size']) ? (int) $input['window_size'] : null;

    if (!$obatId) {
        jsonResponse(['success' => false, 'message' => 'Pilih obat terlebih dahulu'], 400);
    }

    if ($periode < 1 || $periode > 52) {
        $periode = 4;
    }

    // Get drug info
    $stmtObat = $db->prepare("SELECT id, nama_obat, stok_saat_ini FROM obat WHERE id = ?");
    $stmtObat->execute([$obatId]);
    $obat = $stmtObat->fetch();

    if (!$obat) {
        jsonResponse(['success' => false, 'message' => 'Obat tidak ditemukan'], 404);
    }

    // Fetch weekly historical data (5 fitur multivariate, ordered chronologically)
    $stmtHist = $db->prepare("SELECT minggu_ke, tanggal, tanggal_akhir, stok_awal, jumlah_masuk, jumlah_keluar, stok_akhir, rata_rata_keluar
                               FROM data_historis
                               WHERE obat_id = ?
                               ORDER BY tanggal ASC");
    $stmtHist->execute([$obatId]);
    $historical = $stmtHist->fetchAll();

    if (count($historical) < 5) {
        jsonResponse(['success' => false, 'message' => 'Data historis tidak cukup (minimum 5 minggu)'], 400);
    }

    // Build multivariate data array: [[stok_awal, masuk, keluar, stok_akhir, rata_rata], ...]
    $multivariateData = array_map(function ($row) {
        return [
            (float) $row['stok_awal'],
            (float) $row['jumlah_masuk'],
            (float) $row['jumlah_keluar'],
            (float) $row['stok_akhir'],
            (float) $row['rata_rata_keluar'],
        ];
    }, $historical);

    $consumptionData = array_column($multivariateData, 2); // jumlah_keluar for chart
    $historicalLabels = array_map(fn($row) => 'Mg ' . $row['minggu_ke'], $historical);
    $historicalValues = $consumptionData;

    // ─── Call Python LSTM Service (auto-tune: Python cari konfigurasi terbaik otomatis) ───
    $pythonUrl = PYTHON_LSTM_URL . '/predict';

    $payloadArr = [
        'drug_id' => $obatId,
        'historical_data' => $multivariateData,
        'periode' => $periode,
    ];

    // Pass manual hyperparameters if provided
    if ($epochs !== null)
        $payloadArr['epochs'] = $epochs;
    if ($learningRate !== null)
        $payloadArr['learning_rate'] = $learningRate;
    if ($windowSize !== null)
        $payloadArr['window_size'] = $windowSize;

    $payload = json_encode($payloadArr);

    // Helper: kirim payload ke Python /predict
    $callPython = function () use ($pythonUrl, $payload) {
        $ch = curl_init($pythonUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return ['response' => $resp, 'http' => $code, 'error' => $err];
    };

    // Coba pertama
    $pyCall = $callPython();

    // Jika service down (http 0), AUTO-SPAWN service & retry
    if ($pyCall['response'] === false || $pyCall['http'] === 0) {
        $batPath = realpath(__DIR__ . '/../python/start_service_silent.bat');
        if ($batPath && file_exists($batPath)) {
            $batPath = str_replace('/', '\\', $batPath);
            $cmd = 'cmd /c start /B "" "' . $batPath . '" > nul 2>&1';
            pclose(popen($cmd, 'r'));

            // Polling /health setiap 2 detik, max 90 detik (cukup untuk TF load pertama kali)
            $waited = 0;
            while ($waited < 90) {
                sleep(3);
                $waited += 3;
                $hch = curl_init(PYTHON_LSTM_URL . '/health');
                curl_setopt_array($hch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 3,
                    CURLOPT_CONNECTTIMEOUT => 2,
                ]);
                $hr = curl_exec($hch);
                $hc = curl_getinfo($hch, CURLINFO_HTTP_CODE);
                curl_close($hch);
                if ($hc === 200 && $hr) {
                    // Service ready! Retry prediction
                    $pyCall = $callPython();
                    break;
                }
            }
        }
    }

    $pythonResponse = $pyCall['response'];
    $httpCode = $pyCall['http'];
    $curlError = $pyCall['error'];

    if ($pythonResponse === false || $httpCode !== 200) {
        $errorMsg = $curlError ?: 'Python LSTM service tidak merespons';
        if ($httpCode === 0) {
            $errorMsg = 'Python LSTM service tidak bisa diaktifkan otomatis. Cek file python/service.log untuk detail error. Pastikan Python terinstall dan dependencies sudah terpasang (folder python/venv ada).';
        }
        jsonResponse([
            'success' => false,
            'message' => 'Gagal menjalankan prediksi: ' . $errorMsg,
            'hint' => 'PHP sudah coba auto-spawn service tapi gagal. Cek python/service.log atau jalankan manual: python\\start_service.bat'
        ], 503);
    }

    $pythonResult = json_decode($pythonResponse, true);

    if (!$pythonResult || !($pythonResult['success'] ?? false)) {
        $msg = $pythonResult['message'] ?? 'Unknown error dari Python service';
        jsonResponse(['success' => false, 'message' => 'Python LSTM error: ' . $msg], 500);
    }

    $result = $pythonResult['data'];

    // Inject dates into validation_detail
    // Sekarang validation_detail mencakup SEMUA data (idx=0 s.d. N-window_size-1)
    // Formula: sequence[idx] memprediksi historical[idx + window_size]
    $usedWindowSize = $result['model_params']['window_size'] ?? $windowSize ?? 1;
    if (isset($result['validation_detail']) && is_array($result['validation_detail'])) {
        foreach ($result['validation_detail'] as $idx => &$vdetail) {
            $histIdx = $idx + $usedWindowSize;  // 0-indexed: idx=0 → historical[window_size]
            if (isset($historical[$histIdx])) {
                $vdetail['tanggal_mulai'] = $historical[$histIdx]['tanggal'];
                $vdetail['tanggal_akhir'] = $historical[$histIdx]['tanggal_akhir'] ?? $historical[$histIdx]['tanggal'];
                $vdetail['minggu'] = $historical[$histIdx]['minggu_ke'];
            }
        }
    }

    // Generate prediction labels (future weeks)
    $lastDate = end($historical)['tanggal_akhir'] ?? end($historical)['tanggal'];
    $lastMingguKe = end($historical)['minggu_ke'] ?? count($historical);
    $predictionLabels = [];
    $predictions = [];

    for ($i = 0; $i < $periode; $i++) {
        $futureDate = date('Y-m-d', strtotime($lastDate . ' +' . (($i * 7) + 1) . ' days'));
        $predictionLabels[] = 'Mg ' . ($lastMingguKe + $i + 1);
        $predictions[] = [
            'tanggal' => $futureDate,
            'nilai' => $result['predictions'][$i] ?? 0,
            'confidence' => max(60, ($result['confidence'] ?? 85) - ($i * 0.5))
        ];
    }

    // Embed data for reports inside model_params
    $reportData = [
        'epochs_actual' => $result['model_params']['epochs_actual'] ?? $epochs,
        'learning_rate' => $result['model_params']['learning_rate'] ?? $learningRate,
        'window_size' => $windowSize ?? 4,
        'validation_detail' => $result['validation_detail'] ?? [],
        'historical_labels' => $historicalLabels,
        'future_labels' => $predictionLabels
    ];

    // Save prediction to database
    $stmtSave = $db->prepare("INSERT INTO prediksi_lstm (obat_id, tanggal_prediksi, nilai_prediksi, confidence, mae, rmse, mape, akurasi, model_params, created_at)
                               VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmtSave->execute([
        $obatId,
        json_encode($result['predictions']),
        $result['confidence'],
        $result['mae'],
        $result['rmse'],
        $result['mape'],
        $result['accuracy'],
        json_encode($reportData)
    ]);

    // Return response (termasuk data tambahan untuk skripsi)
    jsonResponse([
        'success' => true,
        'data' => [
            'obat' => $obat,
            'predictions' => $predictions,
            'prediction_values' => $result['predictions'],
            'prediction_labels' => $predictionLabels,
            'historical_labels' => $historicalLabels,
            'historical_values' => $historicalValues,
            'mae' => $result['mae'],
            'rmse' => $result['rmse'],
            'mape' => $result['mape'],
            'mape_test' => $result['mape_test'] ?? null,
            'mape_class' => $result['mape_class'] ?? '',
            'accuracy' => $result['accuracy'],
            'confidence' => $result['confidence'],
            'model_params' => $result['model_params'],
            // ─── Data tambahan untuk section skripsi ───
            'arsitektur' => $result['arsitektur'] ?? null,
            'loss_history' => $result['loss_history'] ?? [],
            'val_loss_history' => $result['val_loss_history'] ?? [],
            'norm_table' => $result['norm_table'] ?? [],
            'norm_info' => $result['norm_info'] ?? null,
            'validation_detail' => $result['validation_detail'] ?? [],
            'rekomendasi' => $result['rekomendasi'] ?? null,
            'train_test_split' => $result['train_test_split'] ?? null,
        ]
    ]);
}

function getPredictionHistory(PDO $db): void
{
    $obatId = inputInt('obat_id');
    $limit = max(1, min(50, inputInt('limit', 10)));

    $where = "1=1";
    $params = [];

    if ($obatId) {
        $where .= " AND obat_id = :obat_id";
        $params[':obat_id'] = $obatId;
    }

    $stmt = $db->prepare("SELECT p.*, o.nama_obat
                           FROM prediksi_lstm p
                           JOIN obat o ON p.obat_id = o.id
                           WHERE {$where}
                           ORDER BY p.created_at DESC
                           LIMIT :lim");
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $history = $stmt->fetchAll();

    jsonResponse(['success' => true, 'data' => $history]);
}

function getPredictionDetail(PDO $db): void
{
    $id = inputInt('id');
    if (!$id) {
        jsonResponse(['success' => false, 'message' => 'ID prediksi tidak valid'], 400);
    }

    // Fetch prediction record with drug name
    $stmt = $db->prepare("SELECT p.*, o.nama_obat, o.stok_saat_ini, o.stok_minimum
                           FROM prediksi_lstm p
                           JOIN obat o ON p.obat_id = o.id
                           WHERE p.id = ?");
    $stmt->execute([$id]);
    $pred = $stmt->fetch();

    if (!$pred) {
        jsonResponse(['success' => false, 'message' => 'Riwayat prediksi tidak ditemukan'], 404);
    }

    // Decode stored JSON data
    $nilaiPrediksi = json_decode($pred['nilai_prediksi'], true) ?: [];
    $modelParams = json_decode($pred['model_params'], true) ?: [];

    // Fetch historical consumption data for chart
    $stmtHist = $db->prepare("SELECT minggu_ke, tanggal, jumlah_keluar
                               FROM data_historis
                               WHERE obat_id = ?
                               ORDER BY tanggal ASC");
    $stmtHist->execute([$pred['obat_id']]);
    $historical = $stmtHist->fetchAll();

    $historicalLabels = array_map(fn($r) => 'Mg ' . $r['minggu_ke'], $historical);
    $historicalValues = array_map(fn($r) => (float) $r['jumlah_keluar'], $historical);

    // Build prediction labels from stored model_params or generate them
    $predictionLabels = $modelParams['future_labels'] ?? [];
    if (empty($predictionLabels) && !empty($nilaiPrediksi)) {
        $lastMg = count($historical);
        for ($i = 0; $i < count($nilaiPrediksi); $i++) {
            $predictionLabels[] = 'Mg ' . ($lastMg + $i + 1);
        }
    }

    // Build validation detail from stored model_params
    $validationDetail = $modelParams['validation_detail'] ?? [];

    // ── Rekonstruksi Rekomendasi dari data tersimpan (tanpa re-run LSTM) ──
    $totalKebutuhan = !empty($nilaiPrediksi) ? array_sum($nilaiPrediksi) : 0;
    $avgPerMinggu = count($nilaiPrediksi) > 0 ? round($totalKebutuhan / count($nilaiPrediksi), 2) : 0;
    $stokSaatIni = (int) $pred['stok_saat_ini'];
    $stokMinimum = (int) $pred['stok_minimum'];
    $namaObat = $pred['nama_obat'];
    $periode = count($nilaiPrediksi);

    // Tentukan status berdasarkan perbandingan kebutuhan vs stok
    if ($totalKebutuhan > $stokSaatIni * 1.5) {
        $status = 'TINGGI';
        $text = "Permintaan {$namaObat} diprediksi TINGGI dalam {$periode} minggu ke depan. "
            . "Total kebutuhan ({$totalKebutuhan} unit) jauh melebihi stok saat ini ({$stokSaatIni} unit). "
            . "Segera lakukan pemesanan tambahan untuk menghindari kehabisan stok.";
    } elseif ($totalKebutuhan < $stokSaatIni * 0.5) {
        $status = 'RENDAH';
        $text = "Permintaan {$namaObat} diprediksi RENDAH dalam {$periode} minggu ke depan. "
            . "Total kebutuhan ({$totalKebutuhan} unit) jauh di bawah stok saat ini ({$stokSaatIni} unit). "
            . "Pertimbangkan untuk mengurangi pemesanan agar tidak terjadi overstock.";
    } else {
        $status = 'NORMAL';
        $text = "Permintaan {$namaObat} diprediksi NORMAL dalam {$periode} minggu ke depan. "
            . "Total kebutuhan ({$totalKebutuhan} unit) sesuai dengan stok saat ini ({$stokSaatIni} unit). "
            . "Pertahankan pola pemesanan yang ada.";
    }

    $rekomendasi = [
        'status' => $status,
        'text' => $text,
        'total_kebutuhan' => round($totalKebutuhan),
        'avg_per_minggu' => $avgPerMinggu,
    ];

    jsonResponse([
        'success' => true,
        'data' => [
            'id' => (int) $pred['id'],
            'nama_obat' => $pred['nama_obat'],
            'obat_id' => (int) $pred['obat_id'],
            'stok_saat_ini' => $stokSaatIni,
            'stok_minimum' => $stokMinimum,
            'tanggal_prediksi' => $pred['tanggal_prediksi'],
            'created_at' => $pred['created_at'],
            'mae' => (float) $pred['mae'],
            'rmse' => (float) $pred['rmse'],
            'mape' => (float) $pred['mape'],
            'akurasi' => (float) $pred['akurasi'],
            'confidence' => (float) $pred['confidence'],
            'prediction_values' => $nilaiPrediksi,
            'prediction_labels' => $predictionLabels,
            'historical_labels' => $historicalLabels,
            'historical_values' => $historicalValues,
            'validation_detail' => $validationDetail,
            'rekomendasi' => $rekomendasi,
            'model_params' => [
                'epochs_actual' => $modelParams['epochs_actual'] ?? '-',
                'learning_rate' => $modelParams['learning_rate'] ?? '-',
                'window_size' => $modelParams['window_size'] ?? '-',
            ],
        ]
    ]);
}
