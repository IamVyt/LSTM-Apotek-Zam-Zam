<?php
/**
 * SERVICE HEALTH CHECKER
 * Apotek Zam Zam - Cek apakah Python LSTM service sedang berjalan
 *
 * Akses: http://localhost/pharmapredictt/check_service.php
 *
 * Berguna ketika: error "Python LSTM service belum berjalan"
 * di halaman Prediksi LSTM. Halaman ini ngecek service & kasih
 * solusi konkret.
 */
require_once __DIR__ . '/config/config.php';

$serviceUrl = PYTHON_LSTM_URL . '/health';
$ch = curl_init($serviceUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 5,
    CURLOPT_CONNECTTIMEOUT => 3,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

$isRunning = ($httpCode === 200 && $response);
$health = $isRunning ? json_decode($response, true) : null;
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Service Status — Apotek Zam Zam</title>
    <meta http-equiv="refresh" content="<?= $isRunning ? '30' : '0; url=' ?>">
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 30px auto;
            padding: 20px;
            background: #f5f5f5;
        }

        .card {
            background: #fff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .08);
        }

        .status {
            font-size: 24px;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            margin-bottom: 20px;
        }

        .ok {
            background: #E8F5E9;
            color: #2E7D32;
            border: 2px solid #2E7D32;
        }

        .err {
            background: #FFEBEE;
            color: #C62828;
            border: 2px solid #C62828;
        }

        h1 {
            color: #1F4E78;
            margin-top: 0;
        }

        h2 {
            color: #1F4E78;
        }

        code {
            background: #f0f0f0;
            padding: 2px 8px;
            border-radius: 3px;
            font-family: Consolas, monospace;
            color: #C62828;
        }

        pre {
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
        }

        .step {
            background: #FFF8E1;
            padding: 15px;
            margin: 10px 0;
            border-left: 4px solid #FFC107;
            border-radius: 4px;
        }

        .btn {
            display: inline-block;
            padding: 12px 24px;
            margin: 5px 5px 5px 0;
            background: #1F4E78;
            color: white;
            text-decoration: none;
            border-radius: 4px;
        }

        .btn-success {
            background: #2E7D32;
        }

        table {
            border-collapse: collapse;
            width: 100%;
            margin-top: 10px;
        }

        td {
            padding: 8px 12px;
            border-bottom: 1px solid #eee;
        }

        td:first-child {
            font-weight: bold;
            width: 200px;
            color: #666;
        }
    </style>
</head>

<body>
    <div class="card">
        <h1>Status Python LSTM Service</h1>

        <?php if ($isRunning): ?>
            <div class="status ok">SERVICE BERJALAN NORMAL</div>

            <h2>Informasi Service</h2>
            <table>
                <tr>
                    <td>Status</td>
                    <td><?= htmlspecialchars($health['status'] ?? '-') ?></td>
                </tr>
                <tr>
                    <td>Service Name</td>
                    <td><?= htmlspecialchars($health['service'] ?? '-') ?></td>
                </tr>
                <tr>
                    <td>TensorFlow Version</td>
                    <td><?= htmlspecialchars($health['tensorflow_version'] ?? '-') ?></td>
                </tr>
                <tr>
                    <td>NumPy Version</td>
                    <td><?= htmlspecialchars($health['numpy_version'] ?? '-') ?></td>
                </tr>
                <tr>
                    <td>URL</td>
                    <td><?= htmlspecialchars(PYTHON_LSTM_URL) ?></td>
                </tr>
                <tr>
                    <td>HTTP Status</td>
                    <td><?= $httpCode ?></td>
                </tr>
            </table>

            <p style="margin-top:20px;">Prediksi LSTM siap dijalankan. Klik di bawah:</p>
            <a href="<?= BASE_URL ?>/pages/predictions.php" class="btn btn-success">Buka Halaman Prediksi LSTM</a>
            <a href="<?= PYTHON_LSTM_URL ?>/health" class="btn" target="_blank">Cek /health Endpoint</a>

            <p style="margin-top:30px;color:#666;font-size:0.9em;">
                Halaman ini auto-refresh setiap 30 detik.
            </p>

        <?php else: ?>
            <div class="status err">SERVICE TIDAK BERJALAN</div>

            <h2>Penyebab</h2>
            <p>Sistem PHP tidak bisa menghubungi Python LSTM service di
                <code><?= htmlspecialchars(PYTHON_LSTM_URL) ?></code>.</p>
            <?php if ($curlError): ?>
                <p><b>Detail teknis:</b> <code><?= htmlspecialchars($curlError) ?></code></p>
            <?php endif; ?>

            <h2>Cara Memperbaiki (3 Langkah)</h2>

            <div class="step">
                <b>Langkah 1.</b> Buka <b>Command Prompt baru</b> (Win+R → ketik <code>cmd</code> → Enter).
            </div>

            <div class="step">
                <b>Langkah 2.</b> Copy-paste perintah ini, tekan Enter:
                <pre>cd C:\xampp\htdocs\pharmapredictt\python
    start_service.bat</pre>
            </div>

            <div class="step">
                <b>Langkah 3.</b> Tunggu sampai muncul tulisan <b>"Running on http://0.0.0.0:5000"</b>.
                <br><b>JANGAN TUTUP</b> jendela CMD itu! Biarkan terus terbuka selama kamu pakai sistem.
            </div>

            <p style="margin-top:20px;">
                Setelah service jalan, klik tombol di bawah untuk cek ulang:
            </p>
            <a href="check_service.php" class="btn btn-success">Cek Lagi (Refresh)</a>
            <a href="<?= BASE_URL ?>/pages/predictions.php" class="btn">Halaman Prediksi LSTM</a>

            <h2 style="margin-top:30px;">Troubleshooting</h2>
            <ul>
                <li><b>Pertama kali jalanin?</b> Tunggu 5-10 menit, TensorFlow lagi download (~500MB).</li>
                <li><b>Error Python tidak ditemukan?</b> Install Python 3.11 dari <a
                        href="https://www.python.org/downloads/release/python-3119/" target="_blank">python.org</a>, jangan
                    lupa centang "Add Python to PATH".</li>
                <li><b>Port 5000 dipakai aplikasi lain?</b> Tutup aplikasi yang pakai port itu (cek dengan
                    <code>netstat -ano | findstr :5000</code>).</li>
                <li><b>Service tetap gagal start?</b> Cek error di jendela CMD start_service.bat — biasanya pesannya jelas.
                </li>
            </ul>
        <?php endif; ?>

    </div>
</body>

</html>