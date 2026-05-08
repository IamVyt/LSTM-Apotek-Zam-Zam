<?php
/**
 * Predictions Page - LSTM Drug Consumption Forecasting
 * Apotek Zam Zam
 * 
 * UX Reference: Dwinur01/prediksi-lstm
 * - Manual parameter config (Epochs, Learning Rate, Window Size)
 * - Visual stepper progress
 * - Training console & live loss chart
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();
$db = getDB();
$pageTitle = 'Prediksi LSTM';

// Load drug list for selector
$obatList = $db->query("SELECT id, kode_obat, nama_obat FROM obat WHERE status = 1 ORDER BY nama_obat")->fetchAll();

// Recent prediction history
$stmtHistory = $db->query("SELECT p.*, o.nama_obat, o.kode_obat
                            FROM prediksi_lstm p
                            JOIN obat o ON p.obat_id = o.id
                            ORDER BY p.created_at DESC LIMIT 10");
$recentPredictions = $stmtHistory->fetchAll();

// ═══ DATA TRAINING INFO — DINAMIS dari database ═══
// Hitung jumlah minggu per obat (asumsi semua obat punya jumlah minggu yang sama)
$stmtData = $db->query("SELECT obat_id, COUNT(*) AS jml_minggu
                        FROM data_historis
                        GROUP BY obat_id
                        ORDER BY obat_id ASC");
$dataPerObat = $stmtData->fetchAll();
$jmlObatAktif = count($dataPerObat);
$jmlMingguMax = $jmlObatAktif > 0 ? max(array_column($dataPerObat, 'jml_minggu')) : 0;
$jmlMingguMin = $jmlObatAktif > 0 ? min(array_column($dataPerObat, 'jml_minggu')) : 0;
$totalRecords = array_sum(array_column($dataPerObat, 'jml_minggu'));

// Hitung train/test split (80/20 chronological)
// Setting target: 80%/20%, hasil aktual = floor(N*0.8) integer
$trainSize  = (int) floor($jmlMingguMax * 0.8);
$testSize   = $jmlMingguMax - $trainSize;

// Format text — tampilkan setting target (80/20) + jumlah aktual untuk kejelasan akademis
$totalDataText  = $jmlMingguMin === $jmlMingguMax
    ? "{$jmlMingguMax} minggu × {$jmlObatAktif} obat"
    : "{$jmlMingguMin}-{$jmlMingguMax} minggu × {$jmlObatAktif} obat";
$splitRatioText = "80/20 → Train {$trainSize} mg | Test {$testSize} mg";

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<main class="main-content">
    <div class="page-header">
        <div>
            <h1>Prediksi Persediaan LSTM</h1>
            <p class="subtitle">Pemrosesan data menggunakan Recurrent Neural Network.</p>
        </div>
    </div>

    <!-- ═══════ VISUAL STEPPER ═══════ -->
    <div class="prediction-stepper" id="predictionStepper">
        <div class="stepper-progress-line" id="stepperProgressLine" style="width:0%"></div>
        <div class="stepper-step active" id="step-config">
            <div class="stepper-icon">
                <i data-lucide="settings-2"></i>
            </div>
            <span class="stepper-label">Konfigurasi</span>
        </div>
        <div class="stepper-step" id="step-preprocess">
            <div class="stepper-icon">
                <i data-lucide="database"></i>
            </div>
            <span class="stepper-label">Preprocessing</span>
        </div>
        <div class="stepper-step" id="step-training">
            <div class="stepper-icon">
                <i data-lucide="brain-circuit"></i>
            </div>
            <span class="stepper-label">Training LSTM</span>
        </div>
        <div class="stepper-step" id="step-result">
            <div class="stepper-icon">
                <i data-lucide="check-circle"></i>
            </div>
            <span class="stepper-label">Hasil</span>
        </div>
    </div>

    <!-- ═══════ PARAMETER CONFIGURATION FORM ═══════ -->
    <div class="card mb-3 fade-in prediction-form-card" id="configSection">
        <div class="card-header">
            <h3><i data-lucide="sliders-horizontal" class="icon-20 icon-inline"></i> Konfigurasi Model LSTM</h3>
        </div>
        <div class="param-config-section">
            <!-- Left: Form Fields -->
            <div class="param-config-left">
                <!-- Drug Selection -->
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Pilih Obat</label>
                    <select class="form-control" id="obatSelect">
                        <option value="">-- Pilih Obat --</option>
                        <?php foreach ($obatList as $obat): ?>
                        <option value="<?php echo $obat['id']; ?>"><?php echo e($obat['nama_obat']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Parameter Grid: Epochs, Learning Rate, Window Size -->
                <div class="param-field-group">
                    <div class="param-field">
                        <label class="form-label">
                            Epochs <span class="param-badge">Training</span>
                        </label>
                        <select class="form-control" id="epochsSelect">
                            <option value="50">50 epochs</option>
                            <option value="100">100 epochs</option>
                            <option value="200">200 epochs</option>
                            <option value="300">300 epochs</option>
                            <option value="500" selected>500 epochs (Max)</option>
                        </select>
                    </div>
                    <div class="param-field">
                        <label class="form-label">
                            Learning Rate <span class="param-badge">Optimizer</span>
                        </label>
                        <select class="form-control" id="lrSelect">
                            <option value="0.001">0.001</option>
                            <option value="0.005">0.005</option>
                            <option value="0.01" selected>0.01</option>
                            <option value="0.015">0.015</option>
                            <option value="0.02">0.02</option>
                            <option value="0.05">0.05</option>
                        </select>
                    </div>
                </div>

                <div class="param-field-group">
                    <div class="param-field">
                        <label class="form-label">
                            Window Size <span class="param-badge">Sequence</span>
                        </label>
                        <select class="form-control" id="windowSelect">
                            <option value="1" selected>1 (1-Step-Ahead)</option>
                            <option value="3">3 minggu</option>
                            <option value="4">4 minggu</option>
                            <option value="5">5 minggu</option>
                        </select>
                    </div>
                    <div class="param-field">
                        <label class="form-label">Periode Prediksi</label>
                        <select class="form-control" id="periodeSelect">
                            <option value="3">3 minggu ke depan</option>
                            <option value="4">4 minggu ke depan</option>
                            <option value="5" selected>5 minggu ke depan</option>
                            <option value="8">8 minggu ke depan</option>
                        </select>
                    </div>
                </div>

                <!-- Run Button -->
                <button class="btn btn-primary btn-lg w-full mt-1" id="btnPredict" onclick="runPrediction()">
                    <i data-lucide="play"></i> Jalankan Prediksi LSTM
                </button>

                <!-- ═══════ TRAINING PROGRESS PANEL ═══════ -->
                <div class="training-progress-panel" id="trainingPanel" style="display: none; margin-top: 20px;">
                    <div class="training-header">
                        <div class="training-header-left">
                            <div class="training-spinner" id="trainingSpinnerIcon"></div>
                            <span class="training-title" id="trainingTitle">Training LSTM Model...</span>
                        </div>
                        <div class="training-timer" id="trainingTimer">00:00</div>
                    </div>
                    <div class="training-stats-grid">
                        <div class="training-stat">
                            <div class="training-stat-value" id="trainStatEpoch">-</div>
                            <div class="training-stat-label">Epoch</div>
                        </div>
                        <div class="training-stat">
                            <div class="training-stat-value" id="trainStatLoss">-</div>
                            <div class="training-stat-label">Current Loss</div>
                        </div>
                        <div class="training-stat">
                            <div class="training-stat-value" id="trainStatLR">-</div>
                            <div class="training-stat-label">Learning Rate</div>
                        </div>
                        <div class="training-stat">
                            <div class="training-stat-value" id="trainStatStatus">⏳</div>
                            <div class="training-stat-label">Status</div>
                        </div>
                    </div>
                    <!-- Training Console -->
                    <div class="training-console" id="trainingConsole" style="max-height: 150px;">
                        <div class="log-line"><span class="log-prefix">[SYSTEM]</span> Menunggu proses dimulai...</div>
                    </div>

                    <!-- ═══════ METRICS CARDS (Moved here) ═══════ -->
                    <div class="grid grid-3 mt-3" id="trainingMetrics" style="display: none; gap: 12px;">
                        <div class="metric-card-bordered border-red" style="padding: 16px;">
                            <h4 style="font-size: 0.7rem; margin-bottom: 4px;">MSE</h4>
                            <div class="metric-value" id="metricMSE" style="font-size: 1.2rem;">-</div>
                        </div>
                        <div class="metric-card-bordered border-orange" style="padding: 16px;">
                            <h4 style="font-size: 0.7rem; margin-bottom: 4px;">RMSE</h4>
                            <div class="metric-value" id="metricRMSE" style="font-size: 1.2rem;">-</div>
                        </div>
                        <div class="metric-card-bordered border-green" style="padding: 16px;">
                            <h4 style="font-size: 0.7rem; margin-bottom: 4px;">MAPE</h4>
                            <div class="metric-value" id="metricMAPE" style="font-size: 1.2rem;">-</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right: Info Cards -->
            <div class="param-config-right" style="display: flex; flex-direction: column; gap: 24px;">
                <!-- Sistem Siap -->
                <div class="bento-card">
                    <div class="bento-header">
                        <div class="bento-icon" style="color: #10b981; background: #ecfdf5;"><i data-lucide="rocket"></i></div>
                        <div>
                            <h3>Sistem Siap</h3>
                            <p>Konfigurasi model LSTM saat ini</p>
                        </div>
                    </div>
                    <div class="bento-grid">
                        <div class="bento-item">
                            <span class="b-label">Model</span>
                            <span class="b-value">LSTM</span>
                        </div>
                        <div class="bento-item">
                            <span class="b-label">Fitur</span>
                            <span class="b-value">5 Multi</span>
                        </div>
                        <div class="bento-item">
                            <span class="b-label">Optimizer</span>
                            <span class="b-value">SGD+BPTT</span>
                        </div>
                        <div class="bento-item">
                            <span class="b-label">Loss</span>
                            <span class="b-value">MSE</span>
                        </div>
                        <div class="bento-item" style="grid-column: span 2;">
                            <span class="b-label">Normalisasi</span>
                            <span class="b-value">Min-Max [0,1]</span>
                        </div>
                    </div>
                </div>

                <!-- Data Training -->
                <div class="bento-card">
                    <div class="bento-header">
                        <div class="bento-icon" style="color: #3b82f6; background: #eff6ff;"><i data-lucide="database"></i></div>
                        <div>
                            <h3>Data Training</h3>
                            <p>Real-time dari Database</p>
                        </div>
                    </div>
                    <div class="bento-grid">
                        <div class="bento-item" style="grid-column: span 2;">
                            <span class="b-label">Total Data</span>
                            <span class="b-value"><?php echo e($totalDataText); ?> (<?php echo number_format($totalRecords); ?> baris)</span>
                        </div>
                        <div class="bento-item">
                            <span class="b-label">Validasi</span>
                            <span class="b-value">1-Step</span>
                        </div>
                        <div class="bento-item">
                            <span class="b-label">Patience</span>
                            <span class="b-value">15 Epochs</span>
                        </div>
                        <div class="bento-item" style="grid-column: span 2; background: transparent; border: none; padding: 0; margin-top: 8px;">
                            <span class="b-label">Split Ratio</span>
                            <div class="progress-bar" style="margin-top: 4px; background: #e2e8f0 !important;">
                                <div class="progress-bar-fill" style="width: 80%; background: #3b82f6 !important;"></div>
                            </div>
                            <span style="font-size: 0.75rem; color: var(--text-muted); margin-top: 6px; font-weight: 600;"><?php echo e($splitRatioText); ?></span>
                        </div>
                    </div>
                    <div class="bento-footer">
                        <a href="<?php echo BASE_URL; ?>/import_excel.php" target="_blank">
                            <i data-lucide="upload-cloud"></i> Perbarui Data via Excel
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>



    <!-- ═══════ PREDICTION RESULTS (hidden by default) ═══════ -->
    <div id="predictionResults" style="display:none;">
        <div class="result-grid-2">
            <!-- KOLOM KIRI (Visuals / Grafik) -->
            <div class="result-col-left">
                <!-- Chart Prediksi vs Aktual -->
                <div class="card mb-3 fade-in result-section-reveal">
                    <div class="card-header card-header-accent">
                        <h3>
                            <i data-lucide="trending-up"></i> Visualisasi Prediksi vs Aktual
                        </h3>
                    </div>
                    <div class="chart-container" style="height:360px;">
                        <canvas id="predictionChart"></canvas>
                    </div>
                </div>

                <!-- SECTION 2: GRAFIK LOSS PER EPOCH -->
                <div class="card mb-3 fade-in result-section-reveal" id="sectionLossChart" style="display:none;">
                    <div class="card-header">
                        <h3>📉 Grafik Loss per Epoch (Konvergensi Model)</h3>
                    </div>
                    <div class="chart-container" style="height:320px;">
                        <canvas id="lossChart"></canvas>
                    </div>
                    <p class="chart-description">
                        Grafik ini menunjukkan proses pelatihan model. Loss yang menurun dan stabil menandakan model berhasil belajar (konvergen).
                    </p>
                </div>

                <!-- SECTION 5: ANALISIS RESIDUAL / ERROR -->
                <div class="card mb-3 fade-in result-section-reveal" id="sectionErrorChart" style="display:none;">
                    <div class="card-header">
                        <h3>📈 Analisis Residual / Error per Minggu</h3>
                    </div>
                    <div class="chart-container" style="height:300px;">
                        <canvas id="errorChart"></canvas>
                    </div>
                    <p class="chart-description">
                        Grafik error menampilkan selisih (Aktual - Prediksi) per minggu di test set. Bar hijau = under-predict (aktual > prediksi), bar merah = over-predict (prediksi > aktual).
                    </p>
                </div>
            </div>

            <!-- KOLOM KANAN (Data, Tabel, Metrik) -->
            <div class="result-col-right">


                <!-- SECTION 1: NORMALISASI -->
                <div class="card mb-3 fade-in result-section-reveal accordion-style" id="sectionNormalisasi" style="display:none;">
                    <div class="card-header" onclick="this.parentElement.classList.toggle('collapsed')">
                        <h3>1. Normalisasi Min-Max</h3>
                    </div>
                    <div class="accordion-body">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                            <p class="text-xs text-muted" id="normDescText" style="margin: 0;">
                                Data diubah ke skala 0-1 untuk mempermudah pelatihan. (Min: -, Max: -)
                            </p>
                            <select id="normPageSelect" class="form-control" style="width: auto; height: auto; padding: 8px 36px 8px 16px; font-size: 0.85rem; font-weight: 600; line-height: 1.5; border-radius: 6px;" onchange="renderNormalisasiPage(this.value)">
                                <option value="all">Semua Data</option>
                            </select>
                        </div>
                        <div class="table-container" style="max-height: 250px; overflow-y: auto;">
                            <table class="data-table" id="normalisasiTable">
                                <thead id="normalisasiHead" style="position: sticky; top: 0; z-index: 10;"></thead>
                                <tbody id="normalisasiTableBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- SECTION 2: BOBOT LSTM -->
                <div class="card mb-3 fade-in result-section-reveal accordion-style" id="sectionArsitektur" style="display:none;">
                    <div class="card-header" onclick="this.parentElement.classList.toggle('collapsed')">
                        <h3>2. Bobot Gate LSTM (Layer 1 - Unit 1)</h3>
                    </div>
                    <div class="accordion-body">
                        <div class="grid" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:16px;" id="bobotCardsContainer">
                            <!-- Cards will be populated by JS -->
                        </div>
                    </div>
                </div>

                <!-- SECTION 3: HASIL PREDIKSI VS AKTUAL -->
                <div class="card mb-3 fade-in result-section-reveal accordion-style" id="sectionValidasi" style="display:none;">
                    <div class="card-header" onclick="this.parentElement.classList.toggle('collapsed')">
                        <h3>3. Hasil Prediksi vs Aktual</h3>
                    </div>
                    <div class="accordion-body">
                        <div style="display: flex; justify-content: flex-end; margin-bottom: 12px;">
                            <select id="valPageSelect" class="form-control" style="width: auto; height: auto; padding: 8px 36px 8px 16px; font-size: 0.85rem; font-weight: 600; line-height: 1.5; border-radius: 6px;" onchange="renderValidasiPage(this.value)">
                                <option value="all">Semua Data</option>
                            </select>
                        </div>
                        <div class="table-container" style="max-height: 300px; overflow-y: auto;">
                            <table class="data-table">
                                <thead style="position: sticky; top: 0; z-index: 10;">
                                    <tr>
                                        <th style="width:60px;">No</th>
                                        <th>Periode (Mg)</th>
                                        <th>Tanggal</th>
                                        <th class="text-right">Aktual</th>
                                        <th class="text-right">Prediksi</th>
                                        <th class="text-right">Selisih</th>
                                    </tr>
                                </thead>
                                <tbody id="validasiTableBody">
                                    <!-- Diisi oleh JS -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- SECTION 6: REKOMENDASI TINDAKAN -->
                <div class="card mb-3 fade-in result-section-reveal" id="sectionRekomendasi" style="display:none;">
                    <div class="card-header">
                        <h3>💊 Rekomendasi Tindakan untuk Apoteker</h3>
                    </div>
                    <div id="rekomendasiContent">
                        <!-- Diisi oleh JS -->
                    </div>
                </div>
            </div>
        </div>
    </div><!-- end predictionResults -->
    <!-- 4. Riwayat Prediksi (selalu tampil, di luar predictionResults) -->
    <div class="card mt-3 mb-3 fade-in">
        <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
            <div style="display:flex; align-items:center; gap:8px;">
                <i data-lucide="calendar"></i> <h3 style="margin:0;">Riwayat Prediksi Sebelumnya</h3>
            </div>
            <?php if (!empty($recentPredictions)): ?>
            <button class="btn btn-sm" style="background:#fef2f2; color:#ef4444; border:1px solid #fee2e2;" onclick="deleteAllHistory()">
                <i data-lucide="trash-2" class="icon-14"></i> Hapus Semua
            </button>
            <?php endif; ?>
        </div>
        <div class="card-body" style="padding: 0;">
            <div class="table-container" style="border:none;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Nama Obat</th>
                            <th>Tanggal Run</th>
                            <th>Konfigurasi</th>
                            <th class="text-center">Akurasi (MAPE)</th>
                            <th class="text-center">Error (RMSE)</th>
                            <th class="text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentPredictions as $p): ?>
                        <tr>
                            <td><span class="fw-600" style="color:var(--primary);"><?php echo e($p['nama_obat']); ?></span></td>
                            <td class="text-sm"><?php echo formatDate($p['created_at'], 'd M Y, H:i'); ?></td>
                            <td class="text-xs text-muted">
                                <?php 
                                $params = json_decode($p['model_params'], true);
                                if ($params) {
                                    $ep = $params['epochs_actual'] ?? $params['epochs'] ?? '-';
                                    $lr = $params['learning_rate'] ?? '-';
                                    $ws = $params['window_size'] ?? '-';
                                    echo "Ep: {$ep} &bull; LR: {$lr} &bull; Win: {$ws}";
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                            <td class="text-center">
                                <span class="badge <?php echo (float)$p['mape'] <= 20 ? 'badge-success' : 'badge-warning'; ?>">
                                    <?php echo number_format($p['mape'], 2); ?>%
                                </span>
                            </td>
                            <td class="text-center fw-600" style="color:#ef4444;"><?php echo number_format($p['rmse'], 2); ?></td>
                            <td class="text-center">
                                <button class="btn btn-sm" style="color:#ef4444; background:#fef2f2; border:1px solid #fee2e2; padding: 6px 12px;" onclick="deleteHistory(<?php echo $p['id']; ?>)" title="Hapus Riwayat">
                                    <i data-lucide="trash-2" class="icon-14"></i> Hapus
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recentPredictions)): ?>
                        <tr><td colspan="6" class="text-center text-muted" style="padding:32px;">Belum ada riwayat perhitungan.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</main>

<?php
// Cache-busting: filemtime() bikin URL berubah otomatis ketika file di-update
$jsBase = __DIR__ . '/../assets/js';
$mainV   = file_exists("$jsBase/main.js")   ? filemtime("$jsBase/main.js")   : time();
$chartsV = file_exists("$jsBase/charts.js") ? filemtime("$jsBase/charts.js") : time();
$lstmV   = file_exists("$jsBase/lstm.js")   ? filemtime("$jsBase/lstm.js")   : time();
?>
<script src="<?php echo BASE_URL; ?>/assets/js/main.js?v=<?= $mainV ?>"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/charts.js?v=<?= $chartsV ?>"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/lstm.js?v=<?= $lstmV ?>"></script>
</body>
</html>
