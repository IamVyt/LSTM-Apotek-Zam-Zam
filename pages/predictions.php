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
$obatList = $db->query("SELECT id, nama_obat FROM obat WHERE status = 1 ORDER BY nama_obat")->fetchAll();

// Recent prediction history
$stmtHistory = $db->query("SELECT p.*, o.nama_obat,
                            (SELECT COUNT(*) FROM data_historis dh WHERE dh.obat_id = p.obat_id) AS total_data_historis
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

// Definisi Fitur (Dinamis)
$features = ['stok_awal', 'jumlah_masuk', 'jumlah_keluar', 'stok_akhir', 'rata_rata_keluar'];
$jmlFitur = count($features);

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<main class="main-content">
    <div class="page-header predictions-header-row">
        <div>
            <h1>Prediksi Persediaan LSTM</h1>
            <p class="subtitle">Pemrosesan data menggunakan Recurrent Neural Network.</p>
        </div>
        <!-- Toggle Mode Akademis -->
        <div class="academic-toggle-wrap" title="Tampilkan rumus matematis & tab teknis LSTM">
            <div>
                <span class="toggle-label">Mode Akademis</span>
                <span class="toggle-hint">Rumus + Dapur Pacu LSTM</span>
            </div>
            <div class="academic-toggle" id="academicToggle" onclick="toggleAcademicMode()" role="switch" aria-checked="false"></div>
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
                            <option value="500">500 epochs</option>
                            <option value="700">700 epochs</option>
                            <option value="1000">1000 epochs</option>
                            <option value="1500" selected>1500 epochs (Max)</option>
                        </select>
                    </div>
                    <div class="param-field">
                        <label class="form-label">
                            Learning Rate <span class="param-badge">Optimizer</span>
                        </label>
                        <select class="form-control" id="lrSelect">
                            <option value="0.001" selected>0.001</option>
                            <option value="0.005">0.005</option>
                            <option value="0.01">0.01</option>
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
                            <span class="b-label">Variabel</span>
                            <span class="b-value"><?php echo $jmlFitur; ?> Multi</span>
                        </div>

                        <div class="bento-item">
                            <span class="b-label">Loss</span>
                            <span class="b-value">MSE</span>
                        </div>
                        <div class="bento-item">
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



    <!-- ═══════ PREDICTION RESULTS — HERO + TAB-BASED LAYOUT ═══════ -->
    <div id="predictionResults" style="display:none;">

        <!-- ═════ HERO SECTION — selalu terlihat (MAPE, RMSE, Rekomendasi) ═════ -->
        <div class="result-hero result-section-reveal" id="resultHero">
            <div class="hero-rekom-card" id="heroRekom">
                <span class="hero-rekom-label">💊 Rekomendasi Tindakan</span>
                <div class="hero-rekom-status">
                    <span id="heroRekomStatus">—</span>
                    <span class="hero-status-badge hero-status-normal" id="heroRekomBadge">NORMAL</span>
                </div>
                <div class="hero-rekom-text" id="heroRekomText">Menunggu hasil prediksi…</div>
            </div>
            <div class="hero-metric-full-card">
                <div class="hmf-header">
                    <span class="hm-label" style="margin-bottom:0;">📊 Metrik Evaluasi Model</span>
                    <span class="hm-class-badge hm-class-fair" id="heroMAPEClass">—</span>
                </div>
                <div class="hero-metric-mini-grid">
                    <div class="hmm-item">
                        <span class="hmm-label">MAPE</span>
                        <span class="hmm-value" id="heroMAPE">—%</span>
                    </div>
                    <div class="hmm-item">
                        <span class="hmm-label">RMSE</span>
                        <span class="hmm-value" id="heroRMSE">—</span>
                    </div>
                    <div class="hmm-item">
                        <span class="hmm-label">MAE</span>
                        <span class="hmm-value" id="heroMAE">—</span>
                    </div>
                    <div class="hmm-item">
                        <span class="hmm-label">MSE</span>
                        <span class="hmm-value" id="heroMSE">—</span>
                    </div>
                </div>
                <div class="hero-metric-footer">
                    Akurasi Model: <strong id="heroAccuracy">—%</strong>
                    <span id="heroMAPEAll" style="margin-left:10px;" title="MAPE test set (20% terakhir data) — metrik generalisasi model">Test set: —</span>
                </div>
            </div>
        </div>

        <!-- ═════ EXPORT BAR — tampil otomatis setelah hasil keluar ═════ -->
        <div class="export-action-bar result-section-reveal" id="exportBar" style="display:none;">
            <span class="export-bar-label">
                <i data-lucide="download" class="icon-14 icon-inline"></i>
                <span id="exportBarDrugName">Simpan hasil:</span>
            </span>
            <div class="export-btn-group">
                <button class="btn btn-export-excel" onclick="exportToExcel()">
                    <i data-lucide="file-spreadsheet" class="icon-16"></i> Excel (.xlsx)
                </button>
                <button class="btn btn-export-print" onclick="exportToPrint()">
                    <i data-lucide="printer" class="icon-16"></i> Cetak / PDF
                </button>
            </div>
        </div>

        <!-- ═════ TAB NAVIGATION ═════ -->
        <div class="result-tabs result-section-reveal" role="tablist">
            <button class="result-tab active" data-tab="tab-visual" onclick="switchResultTab('tab-visual')" role="tab">
                <i data-lucide="line-chart" class="tab-icon"></i> Visualisasi Grafik
            </button>
            <button class="result-tab" data-tab="tab-tabel" onclick="switchResultTab('tab-tabel')" role="tab">
                <i data-lucide="table" class="tab-icon"></i> Tabel Prediksi
            </button>
            <button class="result-tab tab-academic academic-only" data-tab="tab-dapur" onclick="switchResultTab('tab-dapur')" role="tab">
                <i data-lucide="cpu" class="tab-icon"></i> Dapur Pacu LSTM
            </button>
        </div>

        <!-- ═════ TAB 1: VISUALISASI GRAFIK ═════ -->
        <div class="result-tab-panel active" id="tab-visual" role="tabpanel">
            <!-- Chart Prediksi vs Aktual -->
            <div class="card mb-3 fade-in result-section-reveal">
                <div class="card-header card-header-accent">
                    <h3><i data-lucide="trending-up"></i> Visualisasi Prediksi vs Aktual</h3>
                </div>
                <div class="chart-container" style="height:380px;">
                    <canvas id="predictionChart"></canvas>
                </div>
            </div>

            <!-- Grafik Loss per Epoch -->
            <div class="card mb-3 fade-in result-section-reveal" id="sectionLossChart" style="display:none;">
                <div class="card-header">
                    <h3>Grafik Loss per Epoch (Konvergensi Model)</h3>
                </div>
                <div style="padding:20px;">
                    <div class="chart-container" style="height:340px;">
                        <canvas id="lossChart"></canvas>
                    </div>
                    <p class="chart-description" style="margin-top:12px;">
                        Loss yang menurun dan stabil menandakan model berhasil belajar (konvergen).
                    </p>
                </div>
            </div>

            <!-- Analisis Residual -->
            <div class="card mb-3 fade-in result-section-reveal" id="sectionErrorChart" style="display:none;">
                <div class="card-header">
                    <h3>Analisis Residual / Error per Minggu</h3>
                </div>
                <div style="padding:20px;">
                    <div class="chart-container" style="height:320px;">
                        <canvas id="errorChart"></canvas>
                    </div>
                    <p class="chart-description" style="margin-top:12px;">
                        Selisih (Aktual − Prediksi) per minggu — mencakup seluruh data (train+test). Bar hijau = under-predict, bar merah = over-predict.
                    </p>
                </div>
            </div>

            <!-- Rumus Error Metrics (Academic Only) -->
            <div class="card mb-3 fade-in academic-only">
                <div class="card-header">
                    <h3>📐 Rumus Evaluasi Error</h3>
                </div>
                <div style="padding:20px;">
                    <div class="math-grid">
                        <div class="math-formula-card">
                            <h4 class="math-title">MAPE — Mean Absolute Percentage Error</h4>
                            <div class="math-display">
                                $$\text{MAPE} = \frac{100\%}{n}\sum_{t=1}^{n}\left|\frac{Y_t - \hat{Y}_t}{Y_t}\right|$$
                            </div>
                            <p class="math-desc">Mengukur rata-rata persentase kesalahan prediksi. Semakin kecil semakin baik. &lt;10% = Sangat Baik.</p>
                        </div>
                        <div class="math-formula-card">
                            <h4 class="math-title">RMSE — Root Mean Square Error</h4>
                            <div class="math-display">
                                $$\text{RMSE} = \sqrt{\frac{1}{n}\sum_{t=1}^{n}(Y_t - \hat{Y}_t)^2}$$
                            </div>
                            <p class="math-desc">Akar rata-rata kuadrat selisih. Memberi penalti lebih besar pada error besar (outlier).</p>
                        </div>
                        <div class="math-formula-card">
                            <h4 class="math-title">MSE — Mean Squared Error</h4>
                            <div class="math-display">
                                $$\text{MSE} = \frac{1}{n}\sum_{t=1}^{n}(Y_t - \hat{Y}_t)^2$$
                            </div>
                            <p class="math-desc">Rata-rata kuadrat selisih. Digunakan sebagai loss function selama training LSTM.</p>
                        </div>
                        <div class="math-formula-card">
                            <h4 class="math-title">MAE — Mean Absolute Error</h4>
                            <div class="math-display">
                                $$\text{MAE} = \frac{1}{n}\sum_{t=1}^{n}|Y_t - \hat{Y}_t|$$
                            </div>
                            <p class="math-desc">Rata-rata selisih absolut. Robust terhadap outlier dibanding RMSE.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═════ TAB 2: TABEL PREDIKSI ═════ -->
        <div class="result-tab-panel" id="tab-tabel" role="tabpanel">
            <div class="card mb-3 fade-in result-section-reveal" id="sectionValidasi">
                <div class="card-header">
                    <h3>Data Training &amp; Validasi (Semua Data)</h3>
                </div>
                <div style="padding:20px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:10px;">
                        <p class="text-xs text-muted" style="margin:0;">
                            <span style="background:#f3f4f6;color:#6b7280;padding:1px 5px;border-radius:3px;font-size:9px;font-weight:600;">TRAIN</span> = 80% data pelatihan &nbsp;|&nbsp;
                            <span style="background:#dbeafe;color:#1d4ed8;padding:1px 5px;border-radius:3px;font-size:9px;font-weight:700;">TEST</span> = 20% validasi &nbsp;|&nbsp;
                            <span style="background:#d1fae5;color:#065f46;padding:1px 5px;border-radius:3px;font-size:9px;font-weight:700;">FUTURE</span> = prediksi masa depan
                        </p>
                        <select id="valPageSelect" class="form-control" style="width:auto; height:auto; padding:8px 36px 8px 16px; font-size:0.85rem; font-weight:600; line-height:1.5; border-radius:6px;" onchange="renderValidasiPage(this.value)">
                            <option value="all">Semua Data</option>
                        </select>
                    </div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th style="width:60px;">No</th>
                                    <th>Periode (Mg)</th>
                                    <th>Tanggal</th>
                                    <th class="text-right">Aktual</th>
                                    <th class="text-right">Prediksi</th>
                                    <th class="text-right">Selisih</th>
                                    <th class="text-right">APE (%)</th>
                                </tr>
                            </thead>
                            <tbody id="validasiTableBody">
                                <!-- Diisi oleh JS -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═════ TAB 3: DAPUR PACU LSTM (Academic Only) ═════ -->
        <div class="result-tab-panel" id="tab-dapur" role="tabpanel">
            <!-- Rumus Normalisasi Min-Max -->
            <div class="card mb-3 fade-in">
                <div class="card-header">
                    <h3>📐 Rumus Normalisasi Min-Max</h3>
                </div>
                <div style="padding:20px;">
                    <div class="math-formula-card">
                        <div class="math-display">
                            $$X_{\text{norm}} = \frac{X - X_{\min}}{X_{\max} - X_{\min}}$$
                        </div>
                        <p class="math-desc">Mentransformasi data ke skala [0, 1] agar gradient descent stabil dan tidak didominasi fitur berskala besar.</p>
                    </div>
                </div>
            </div>

            <!-- Tabel Normalisasi -->
            <div class="card mb-3 fade-in" id="sectionNormalisasi" style="display:none;">
                <div class="card-header">
                    <h3>Tabel Hasil Normalisasi Min-Max</h3>
                </div>
                <div style="padding:20px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:10px;">
                        <p class="text-xs text-muted" id="normDescText" style="margin:0;">
                            Data diubah ke skala 0-1 untuk mempermudah pelatihan. (Min: -, Max: -)
                        </p>
                        <select id="normPageSelect" class="form-control" style="width:auto; height:auto; padding:8px 36px 8px 16px; font-size:0.85rem; font-weight:600; line-height:1.5; border-radius:6px;" onchange="renderNormalisasiPage(this.value)">
                            <option value="all">Semua Data</option>
                        </select>
                    </div>
                    <div class="table-container">
                        <table class="data-table" id="normalisasiTable">
                            <thead id="normalisasiHead"></thead>
                            <tbody id="normalisasiTableBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Rumus LSTM Gates -->
            <div class="card mb-3 fade-in">
                <div class="card-header">
                    <h3>📐 Rumus Arsitektur Gate LSTM</h3>
                </div>
                <div style="padding:20px;">
                    <div class="math-grid">
                        <div class="math-formula-card">
                            <h4 class="math-title">Forget Gate</h4>
                            <div class="math-display">
                                $$f_t = \sigma(W_f \cdot [h_{t-1}, x_t] + b_f)$$
                            </div>
                            <p class="math-desc">Memutuskan informasi mana dari cell state sebelumnya yang dibuang.</p>
                        </div>
                        <div class="math-formula-card">
                            <h4 class="math-title">Input Gate</h4>
                            <div class="math-display">
                                $$i_t = \sigma(W_i \cdot [h_{t-1}, x_t] + b_i)$$
                            </div>
                            <p class="math-desc">Memutuskan informasi baru mana yang akan disimpan ke cell state.</p>
                        </div>
                        <div class="math-formula-card">
                            <h4 class="math-title">Cell Candidate</h4>
                            <div class="math-display">
                                $$\tilde{C}_t = \tanh(W_C \cdot [h_{t-1}, x_t] + b_C)$$
                            </div>
                            <p class="math-desc">Membuat kandidat nilai baru yang akan ditambahkan ke cell state.</p>
                        </div>
                        <div class="math-formula-card">
                            <h4 class="math-title">Cell State Update</h4>
                            <div class="math-display">
                                $$C_t = f_t \odot C_{t-1} + i_t \odot \tilde{C}_t$$
                            </div>
                            <p class="math-desc">Memperbarui cell state dengan menggabungkan informasi lama dan baru.</p>
                        </div>
                        <div class="math-formula-card">
                            <h4 class="math-title">Output Gate</h4>
                            <div class="math-display">
                                $$o_t = \sigma(W_o \cdot [h_{t-1}, x_t] + b_o)$$
                            </div>
                            <p class="math-desc">Memutuskan bagian mana dari cell state yang menjadi output.</p>
                        </div>
                        <div class="math-formula-card">
                            <h4 class="math-title">Hidden State Output</h4>
                            <div class="math-display">
                                $$h_t = o_t \odot \tanh(C_t)$$
                            </div>
                            <p class="math-desc">Output akhir yang diteruskan ke timestep berikutnya dan ke layer atas.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bobot Gate LSTM Hasil Training -->
            <div class="card mb-3 fade-in" id="sectionArsitektur" style="display:none;">
                <div class="card-header">
                    <h3>Hasil Bobot Gate LSTM (Layer 1)</h3>
                </div>
                <div style="padding:20px;">
                    <p class="text-xs text-muted" style="margin:0 0 16px 0;">
                        Nilai bobot rata-rata setiap gate LSTM setelah training selesai.
                    </p>
                    <div class="grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:16px;" id="bobotCardsContainer">
                        <!-- diisi oleh JS -->
                    </div>
                </div>
            </div>
        </div>

        <!-- Hidden compatibility container (for backward-compat with old JS references) -->
        <div id="sectionRekomendasi" style="display:none;">
            <div id="rekomendasiContent"></div>
        </div>

    </div><!-- end predictionResults -->

    <!-- ═════ MODAL TRACEABILITY — Pembuktian Manual 1 Baris Data ═════ -->
    <div class="trace-modal-backdrop" id="traceModal" onclick="closeTraceModal(event)">
        <div class="trace-modal" onclick="event.stopPropagation()">
            <div class="trace-modal-header">
                <div>
                    <h2 class="trace-modal-title">🔬 Pembuktian Manual Perhitungan</h2>
                    <p class="trace-modal-subtitle" id="traceModalSubtitle">Minggu —</p>
                </div>
                <button class="trace-modal-close" onclick="closeTraceModal()" aria-label="Tutup">×</button>
            </div>
            <div class="trace-modal-body" id="traceModalBody">
                <!-- Diisi oleh JS -->
            </div>
            <div class="trace-modal-footer">
                <p class="trace-modal-note">
                    📖 Perhitungan diatas membuktikan jejak (traceability) bagaimana sistem menghasilkan prediksi untuk minggu ini, dari input mentah hingga output final.
                </p>
                <button class="btn btn-primary" onclick="closeTraceModal()">Tutup</button>
            </div>
        </div>
    </div>
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
                            <th class="text-center">Total Data</th>
                            <th class="text-center">MAPE</th>
                            <th class="text-center">RMSE</th>
                            <th class="text-center">MAE</th>
                            <th class="text-center">Akurasi</th>
                            <th class="text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="riwayatPrediksiBody">
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
                            <td class="text-center fw-600"><?php echo number_format((int)$p['total_data_historis']); ?> baris</td>
                            <td class="text-center">
                                <span class="badge <?php echo (float)$p['mape'] <= 20 ? 'badge-aman' : 'badge-waspada'; ?>">
                                    <?php echo number_format($p['mape'], 2); ?>%
                                </span>
                            </td>
                            <td class="text-center fw-600" style="color:#ef4444;"><?php echo number_format($p['rmse'], 2); ?></td>
                            <td class="text-center fw-600" style="color:#d97706;"><?php echo number_format($p['mae'], 2); ?></td>
                            <td class="text-center fw-600" style="color:#10b981;"><?php echo number_format($p['akurasi'], 2); ?>%</td>
                            <td class="text-center">
                                <div style="display:flex; gap:6px; justify-content:center; flex-wrap:wrap;">
                                    <button class="btn btn-sm" style="color:#3b82f6; background:#eff6ff; border:1px solid #dbeafe; padding: 6px 12px;" onclick="viewHistory(<?php echo $p['id']; ?>)" title="Lihat Detail Hasil">
                                        <i data-lucide="eye" class="icon-14"></i> Lihat
                                    </button>
                                    <button class="btn btn-sm" style="color:#ef4444; background:#fef2f2; border:1px solid #fee2e2; padding: 6px 12px;" onclick="deleteHistory(<?php echo $p['id']; ?>)" title="Hapus Riwayat">
                                        <i data-lucide="trash-2" class="icon-14"></i> Hapus
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recentPredictions)): ?>
                        <tr><td colspan="9" class="text-center text-muted" style="padding:32px;">Belum ada riwayat perhitungan.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ═══════ MODAL: LIHAT DETAIL RIWAYAT PREDIKSI ═══════ -->
    <div class="modal-overlay" id="historyDetailModal">
        <div class="modal" style="max-width: 900px; width: 95%;">
            <div class="modal-header" style="background: linear-gradient(135deg, #eff6ff, #f0fdf4); border-bottom: 1px solid #e2e8f0;">
                <div style="display:flex; align-items:center; gap:12px;">
                    <div style="width:40px; height:40px; border-radius:10px; background:linear-gradient(135deg,#3b82f6,#1d9e75); display:flex; align-items:center; justify-content:center; color:white;">
                        <i data-lucide="file-bar-chart" style="width:20px; height:20px;"></i>
                    </div>
                    <div>
                        <h3 style="margin:0; font-size:1.05rem;">Detail Hasil Prediksi</h3>
                        <p id="historyModalSubtitle" style="margin:0; font-size:0.78rem; color:var(--text-muted);">Memuat...</p>
                    </div>
                </div>
                <button class="modal-close" onclick="closeModal('historyDetailModal')">
                    <i data-lucide="x" style="width:18px; height:18px;"></i>
                </button>
            </div>
            <div class="modal-body" style="padding:20px; max-height:75vh; overflow-y:auto;">
                <!-- Loading state -->
                <div id="historyDetailLoading" style="text-align:center; padding:48px;">
                    <div class="spinner" style="width:32px; height:32px; border-width:3px; margin:0 auto 16px;"></div>
                    <p style="color:var(--text-muted); font-size:0.9rem;">Memuat data riwayat...</p>
                </div>

                <!-- Content (hidden until loaded) -->
                <div id="historyDetailContent" style="display:none;">
                    <!-- Summary Info Cards -->
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap:12px; margin-bottom:20px;" id="historyMetricsGrid">
                        <div class="metric-card-bordered border-blue" style="padding:14px; text-align:center;">
                            <h4 style="font-size:0.65rem; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted);">Epochs</h4>
                            <div id="histDetailEpochs" style="font-size:1.1rem; font-weight:700; color:var(--text-primary);">-</div>
                        </div>
                        <div class="metric-card-bordered border-purple" style="padding:14px; text-align:center;">
                            <h4 style="font-size:0.65rem; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted);">Learning Rate</h4>
                            <div id="histDetailLR" style="font-size:1.1rem; font-weight:700; color:var(--text-primary);">-</div>
                        </div>
                        <div class="metric-card-bordered border-orange" style="padding:14px; text-align:center;">
                            <h4 style="font-size:0.65rem; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted);">RMSE</h4>
                            <div id="histDetailRMSE" style="font-size:1.1rem; font-weight:700; color:#ef4444;">-</div>
                        </div>
                        <div class="metric-card-bordered border-green" style="padding:14px; text-align:center;">
                            <h4 style="font-size:0.65rem; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted);">MAPE</h4>
                            <div id="histDetailMAPE" style="font-size:1.1rem; font-weight:700; color:var(--primary);">-</div>
                        </div>
                    </div>

                    <!-- Chart -->
                    <div class="card mb-3" style="border:1px solid var(--border-color);">
                        <div class="card-header" style="padding:14px 18px;">
                            <h3 style="font-size:0.85rem; margin:0;"><i data-lucide="trending-up" class="icon-16 icon-inline"></i> Grafik Prediksi vs Aktual</h3>
                        </div>
                        <div class="chart-container" style="height:280px; padding:12px;">
                            <canvas id="historyDetailChart"></canvas>
                        </div>
                    </div>

                    <!-- Validation Table -->
                    <div class="card" style="border:1px solid var(--border-color);" id="historyValidationSection">
                        <div class="card-header" style="padding:14px 18px; display:flex; justify-content:space-between; align-items:center;">
                            <h3 style="font-size:0.85rem; margin:0;"><i data-lucide="table-2" class="icon-16 icon-inline"></i> Hasil Prediksi vs Aktual</h3>
                        </div>
                        <div class="table-container" style="max-height:280px; overflow-y:auto; border:none;">
                            <table class="data-table">
                                <thead style="position:sticky; top:0; z-index:10;">
                                    <tr>
                                        <th style="width:50px;">No</th>
                                        <th>Periode</th>
                                        <th>Tanggal</th>
                                        <th class="text-right">Aktual</th>
                                        <th class="text-right">Prediksi</th>
                                        <th class="text-right">Selisih</th>
                                    </tr>
                                </thead>
                                <tbody id="historyValidationBody">
                                    <tr><td colspan="6" class="text-center text-muted">Tidak ada data</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Future Predictions Table -->
                    <div class="card mt-3" style="border:1px solid var(--border-color);" id="historyFutureSection">
                        <div class="card-header" style="padding:14px 18px;">
                            <h3 style="font-size:0.85rem; margin:0;"><i data-lucide="calendar-plus" class="icon-16 icon-inline"></i> Prediksi Masa Depan</h3>
                        </div>
                        <div class="table-container" style="border:none;">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th style="width:50px;">No</th>
                                        <th>Periode</th>
                                        <th class="text-right">Prediksi (Unit)</th>
                                    </tr>
                                </thead>
                                <tbody id="historyFutureBody">
                                    <tr><td colspan="3" class="text-center text-muted">Tidak ada data</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Rekomendasi Tindakan -->
                    <div class="card mt-3" style="border:1px solid var(--border-color);" id="historyRekomendasiSection">
                        <div class="card-header" style="padding:14px 18px;">
                            <h3 style="font-size:0.85rem; margin:0;">💊 Rekomendasi Tindakan untuk Apoteker</h3>
                        </div>
                        <div id="historyRekomendasiContent" style="padding:0;">
                            <!-- Diisi oleh JS -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</main>

</div><!-- end app-wrapper -->

<?php
// Cache-busting: filemtime() bikin URL berubah otomatis ketika file di-update
$jsBase = __DIR__ . '/../assets/js';
$mainV   = file_exists("$jsBase/main.js")   ? filemtime("$jsBase/main.js")   : time();
$chartsV = file_exists("$jsBase/charts.js") ? filemtime("$jsBase/charts.js") : time();
$lstmV   = file_exists("$jsBase/lstm.js")   ? filemtime("$jsBase/lstm.js")   : time();
?>
<!-- MathJax untuk render rumus matematis di Mode Akademis -->
<script>
window.MathJax = {
    tex: {
        inlineMath: [['\\(', '\\)']],
        displayMath: [['$$', '$$']],
        processEscapes: true
    },
    svg: { fontCache: 'global' },
    startup: { typeset: false }  // di-typeset manual saat Mode Akademis ON
};
</script>
<script src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-svg.js" id="mathjaxLib" defer></script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/main.js?v=<?= $mainV ?>"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/charts.js?v=<?= $chartsV ?>"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/lstm.js?v=<?= $lstmV ?>"></script>

<!-- Tab switching & Academic mode toggle -->
<script>
function switchResultTab(tabId) {
    document.querySelectorAll('.result-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === tabId));
    document.querySelectorAll('.result-tab-panel').forEach(p => p.classList.toggle('active', p.id === tabId));
    // Re-render charts inside tab kalau perlu (Chart.js auto-resizes saat visible)
    if (window.predictionChart) window.predictionChart.resize();
    if (window.lossChartInstance) window.lossChartInstance.resize();
    if (window.errorChartInstance) window.errorChartInstance.resize();
}

function toggleAcademicMode() {
    const toggle = document.getElementById('academicToggle');
    const isOn = !toggle.classList.contains('on');
    toggle.classList.toggle('on', isOn);
    toggle.setAttribute('aria-checked', isOn ? 'true' : 'false');
    document.body.classList.toggle('academic-mode', isOn);
    localStorage.setItem('academicMode', isOn ? '1' : '0');

    // Render rumus MathJax saat ON pertama kali
    if (isOn && window.MathJax && window.MathJax.typesetPromise) {
        window.MathJax.typesetPromise().catch(err => console.warn('MathJax typeset error:', err));
    }
    // Kalau lagi di tab dapur pacu dan mode dimatikan, pindah ke tab visual
    if (!isOn) {
        const dapurTab = document.querySelector('.result-tab[data-tab="tab-dapur"]');
        if (dapurTab && dapurTab.classList.contains('active')) switchResultTab('tab-visual');
    }
}

// Restore academic mode dari localStorage saat halaman dimuat
document.addEventListener('DOMContentLoaded', () => {
    if (localStorage.getItem('academicMode') === '1') {
        toggleAcademicMode();
    }
});
</script>
<!-- CSS: Badge TRAIN/TEST + Export Bar -->
<style>
.row-train-dim td { opacity: 0.78; }
.row-train-dim:hover td { opacity: 1; background-color: #f9fafb; cursor: pointer; }
.row-test-highlight td { background-color: #eff6ff !important; }
.row-test-highlight:hover td { background-color: #dbeafe !important; cursor: pointer; }

/* ── Export Action Bar ── */
.export-action-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--surface);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    padding: 10px 16px;
    margin-bottom: 14px;
    flex-wrap: wrap;
    gap: 10px;
}
.export-bar-label {
    font-size: 0.82rem;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 6px;
    font-weight: 500;
}
.export-btn-group { display: flex; gap: 8px; flex-wrap: wrap; }
.btn-export-excel {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #16a34a;
    color: #fff;
    border: none;
    border-radius: 7px;
    padding: 7px 14px;
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s;
}
.btn-export-excel:hover { background: #15803d; }
.btn-export-print {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #2563eb;
    color: #fff;
    border: none;
    border-radius: 7px;
    padding: 7px 14px;
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s;
}
.btn-export-print:hover { background: #1d4ed8; }
</style>
</body>
</html>
