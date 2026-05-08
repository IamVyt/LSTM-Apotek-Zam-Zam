<?php
/**
 * Service Control API
 * Apotek Zam Zam
 *
 * Endpoint untuk:
 *   GET  ?action=status — cek apakah Python LSTM service jalan
 *   POST ?action=start  — spawn Python service di background
 *   POST ?action=wait   — polling sampai service ready (max 60 detik)
 *
 * Dipakai oleh Predictions page untuk auto-start service tanpa CMD.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
requireAPIAuth();

$action = $_GET['action'] ?? 'status';
$method = $_SERVER['REQUEST_METHOD'];

function checkServiceHealth(int $timeoutSec = 3): array {
    $ch = curl_init(PYTHON_LSTM_URL . '/health');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeoutSec,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'running'   => ($httpCode === 200 && $response !== false),
        'http_code' => $httpCode,
        'response'  => $response ? json_decode($response, true) : null,
    ];
}

function spawnPythonService(): array {
    // Path ke silent batch launcher
    $batPath = __DIR__ . '/../python/start_service_silent.bat';
    $batPath = str_replace('/', '\\', realpath($batPath));

    if (!file_exists($batPath)) {
        return ['ok' => false, 'message' => 'start_service_silent.bat tidak ditemukan: ' . $batPath];
    }

    // Spawn detached process di Windows: 'start /B' tanpa window, /MIN minimized
    // Gunakan popen dengan 'start' untuk detach dari proses PHP parent
    $cmd = 'start /B "" "' . $batPath . '"';

    // Eksekusi via cmd /c agar proses fully detached
    pclose(popen('cmd /c "' . $cmd . '" > nul 2>&1', 'r'));

    return ['ok' => true, 'message' => 'Python service launcher dispatched', 'cmd' => $cmd];
}

try {
    if ($action === 'status') {
        $health = checkServiceHealth(3);
        echo json_encode([
            'success' => true,
            'running' => $health['running'],
            'detail'  => $health['response'],
            'http'    => $health['http_code'],
        ]);
        exit;
    }

    if ($action === 'start' && $method === 'POST') {
        // Cek dulu apakah sudah jalan (jangan spawn duplikat)
        $health = checkServiceHealth(2);
        if ($health['running']) {
            echo json_encode([
                'success' => true,
                'already_running' => true,
                'message' => 'Service sudah berjalan'
            ]);
            exit;
        }

        $result = spawnPythonService();
        echo json_encode([
            'success' => $result['ok'],
            'spawned' => $result['ok'],
            'message' => $result['message'],
            'hint'    => 'Service sedang start. Polling /health setiap 2 detik.',
        ]);
        exit;
    }

    if ($action === 'wait' && $method === 'POST') {
        // Polling /health setiap 2 detik, max 60 detik
        $maxWaitSec = 60;
        $elapsed = 0;
        $intervalSec = 2;

        while ($elapsed < $maxWaitSec) {
            $health = checkServiceHealth(2);
            if ($health['running']) {
                echo json_encode([
                    'success' => true,
                    'running' => true,
                    'wait_seconds' => $elapsed,
                    'detail' => $health['response']
                ]);
                exit;
            }
            sleep($intervalSec);
            $elapsed += $intervalSec;
        }

        echo json_encode([
            'success' => false,
            'running' => false,
            'wait_seconds' => $elapsed,
            'message' => 'Timeout (60s). Service tidak merespons. Cek file python/service.log.',
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Action tidak valid']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
