/**
 * Apotek Zam Zam - LSTM Prediction Page JavaScript
 * UX Reference: Dwinur01/prediksi-lstm
 * - Stepper visual progress
 * - Manual parameter config (Epochs, LR, Window Size)
 * - Training console & live progress
 * - Cascade reveal for results
 */

let predictionChart = null;
let lossChartInstance = null;
let errorChartInstance = null;
let liveLossChartInstance = null;
window.currentPredictionData = null; // Store data globally for pagination

// ═══════════════════════════════════════════════════
// STEPPER MANAGEMENT
// ═══════════════════════════════════════════════════
const STEPS = ['step-config', 'step-preprocess', 'step-training', 'step-result'];
let currentStepIndex = 0;

function setStep(index) {
    currentStepIndex = index;
    STEPS.forEach((id, i) => {
        const el = document.getElementById(id);
        if (!el) return;
        el.classList.remove('active', 'completed');
        if (i < index) el.classList.add('completed');
        else if (i === index) el.classList.add('active');
    });
    // Progress line
    const line = document.getElementById('stepperProgressLine');
    if (line) {
        const pct = index === 0 ? 0 : Math.min(100, (index / (STEPS.length - 1)) * 100);
        const offset = 96 * (pct / 100); // Account for 48px left + 48px right padding
        line.style.width = `calc(${pct}% - ${offset}px)`;
    }
    if (window.lucide) lucide.createIcons();
}

function resetStepper() { setStep(0); }

// ═══════════════════════════════════════════════════
// TRAINING CONSOLE
// ═══════════════════════════════════════════════════
function consoleLog(msg, type = '') {
    const console = document.getElementById('trainingConsole');
    if (!console) return;
    const line = document.createElement('div');
    line.className = 'log-line' + (type ? ' ' + type + '-line' : '');
    const time = new Date().toLocaleTimeString('id-ID');
    line.innerHTML = `<span class="log-prefix">[${time}]</span> ${msg}`;
    console.appendChild(line);
    console.scrollTop = console.scrollHeight;
}

function clearConsole() {
    const c = document.getElementById('trainingConsole');
    if (c) c.innerHTML = '';
}

// ═══════════════════════════════════════════════════
// TRAINING PROGRESS PANEL
// ═══════════════════════════════════════════════════
function showTrainingPanel() {
    const p = document.getElementById('trainingPanel');
    if (p) { p.style.display = 'block'; p.classList.add('show'); }
}

function hideTrainingPanel() {
    const p = document.getElementById('trainingPanel');
    if (p) { p.classList.remove('show'); setTimeout(() => { p.style.display = 'none'; }, 300); }
}

function updateTrainingStat(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
}

function updateTimer(seconds) {
    const m = Math.floor(seconds / 60).toString().padStart(2, '0');
    const s = (seconds % 60).toString().padStart(2, '0');
    const el = document.getElementById('trainingTimer');
    if (el) el.textContent = m + ':' + s;
}

// ═══════════════════════════════════════════════════
// PYTHON SERVICE AUTO-START
// ═══════════════════════════════════════════════════
function prewarmPythonService() {
    fetch(BASE_URL + '/api/service_control.php?action=status')
        .then(r => r.json())
        .then(data => {
            if (!data.running) {
                fetch(BASE_URL + '/api/service_control.php?action=start', { method: 'POST' }).catch(() => {});
            }
        })
        .catch(() => {});
}

document.addEventListener('DOMContentLoaded', () => {
    setTimeout(prewarmPythonService, 500);
});

async function checkPythonService() {
    try {
        const r = await fetch(BASE_URL + '/api/service_control.php?action=status');
        const data = await r.json();
        return data.running === true;
    } catch (e) { return false; }
}

async function spawnPythonService() {
    const r = await fetch(BASE_URL + '/api/service_control.php?action=start', { method: 'POST' });
    return await r.json();
}

async function waitForPythonService() {
    const r = await fetch(BASE_URL + '/api/service_control.php?action=wait', { method: 'POST' });
    return await r.json();
}

async function ensurePythonServiceRunning() {
    consoleLog('Memeriksa Python LSTM service...', 'epoch');
    updateTrainingStat('trainStatStatus', '\u{1F50D}');
    const isRunning = await checkPythonService();
    if (isRunning) {
        consoleLog('\u2713 Python service aktif', 'success');
        return true;
    }

    consoleLog('Python service belum aktif. Menyalakan otomatis...', 'epoch');
    updateTrainingStat('trainStatStatus', '\u{1F504}');
    showToast('Python service belum jalan. Menyalakan otomatis... (~30 detik)', 'info');
    const spawn = await spawnPythonService();
    if (!spawn.success) {
        consoleLog('\u2717 Gagal start Python service: ' + (spawn.message || 'unknown'), 'error');
        showToast('Gagal start Python service: ' + (spawn.message || 'unknown'), 'error');
        return false;
    }
    if (spawn.already_running) { consoleLog('\u2713 Service sudah aktif', 'success'); return true; }

    const wait = await waitForPythonService();
    if (wait.success && wait.running) {
        consoleLog(`\u2713 Python service ready (${wait.wait_seconds}s)`, 'success');
        showToast(`Python service ready dalam ${wait.wait_seconds} detik!`, 'success');
        return true;
    } else {
        consoleLog('\u2717 Service timeout. Cek python/service.log', 'error');
        showToast('Service tidak ready. Coba lagi atau cek python/service.log.', 'warning');
        return false;
    }
}

// ═══════════════════════════════════════════════════
// MAIN PREDICTION FUNCTION
// ═══════════════════════════════════════════════════
async function runPrediction() {
    const obatId = document.getElementById('obatSelect').value;
    const periode = document.getElementById('periodeSelect').value;
    const epochs = document.getElementById('epochsSelect').value;
    const lr = document.getElementById('lrSelect').value;
    const windowSize = document.getElementById('windowSelect').value;

    if (!obatId) { showToast('Pilih obat terlebih dahulu', 'warning'); return; }

    const btn = document.getElementById('btnPredict');
    const originalText = btn.innerHTML;
    btn.disabled = true;

    // Reset UI
    const predResultsEl = document.getElementById('predictionResults');
    if (predResultsEl) predResultsEl.style.display = 'none';
    const exportBarEl = document.getElementById('exportBar');
    if (exportBarEl) exportBarEl.style.display = 'none';
    const tmEl = document.getElementById('trainingMetrics');
    if (tmEl) tmEl.style.display = 'none';
    document.querySelectorAll('.result-section-reveal').forEach(el => el.classList.remove('revealed'));
    clearConsole();
    resetStepper();

    let timer = null;
    let elapsed = 0;

    try {
        // Step 1: Config (already done)
        setStep(0);
        consoleLog(`Konfigurasi: Epochs=${epochs}, LR=${lr}, Window=${windowSize}, Periode=${periode}`);
        showTrainingPanel();
        
        const spinnerIcon = document.getElementById('trainingSpinnerIcon');
        const trainingTitle = document.getElementById('trainingTitle');
        if (spinnerIcon) spinnerIcon.style.display = 'block';
        if (trainingTitle) trainingTitle.textContent = 'Training LSTM Model...';
        
        updateTrainingStat('trainStatLR', lr);
        updateTrainingStat('trainStatEpoch', '0/' + epochs);
        updateTrainingStat('trainStatLoss', '-');
        updateTrainingStat('trainStatStatus', '\u23F3');

        // Step 2: Preprocessing (check service)
        setStep(1);
        consoleLog('Preprocessing: Memeriksa service & memuat data historis...');
        btn.innerHTML = '<span class="spinner" style="width:18px;height:18px;border-width:2px;"></span> Preprocessing...';

        const ok = await ensurePythonServiceRunning();
        if (!ok) { btn.disabled = false; btn.innerHTML = originalText; hideTrainingPanel(); resetStepper(); return; }

        consoleLog('\u2713 Data historis dimuat. Normalisasi Min-Max diterapkan.', 'success');

        // Step 3: Training
        setStep(2);
        consoleLog('Memulai training LSTM...');
        updateTrainingStat('trainStatStatus', '\u{1F3CB}\uFE0F');

        timer = setInterval(() => {
            elapsed++;
            updateTimer(elapsed);
            btn.innerHTML = `<span class="spinner" style="width:18px;height:18px;border-width:2px;"></span> Training... (${elapsed}s)`;
            // Simulate epoch progress
            const simEpoch = Math.min(parseInt(epochs), Math.floor(elapsed * parseInt(epochs) / 60));
            updateTrainingStat('trainStatEpoch', simEpoch + '/' + epochs);
        }, 1000);

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

        const response = await fetchAPI(BASE_URL + '/api/predictions.php', {
            method: 'POST',
            timeout: 300000,
            body: JSON.stringify({
                obat_id: parseInt(obatId),
                periode: parseInt(periode),
                epochs: parseInt(epochs),
                learning_rate: parseFloat(lr),
                window_size: parseInt(windowSize),
                csrf_token: csrfToken
            })
        });

        clearInterval(timer);

        if (response.success) {
            // Step 4: Results
            setStep(3);
            updateTrainingStat('trainStatStatus', '\u2705');
            
            if (spinnerIcon) spinnerIcon.style.display = 'none';
            if (trainingTitle) trainingTitle.textContent = 'Training Selesai!';

            const mp = response.data.model_params || {};
            updateTrainingStat('trainStatEpoch', (mp.epochs_actual || epochs) + '/' + (mp.epochs_configured || epochs));
            updateTrainingStat('trainStatLoss', response.data.loss_history?.length > 0
                ? response.data.loss_history[response.data.loss_history.length - 1].toFixed(6) : '-');
            consoleLog(`\u2713 Training selesai! Epoch terbaik: ${mp.epoch_terbaik || '-'}`, 'success');
            consoleLog(`  Note: Sistem berhenti di epoch ${mp.epochs_actual}. Model terbaik ada di epoch ${mp.epoch_terbaik} (EarlyStopping patience = ${mp.patience_used || 50} epoch).`, 'epoch');
            consoleLog(`  MAPE (Semua Data): ${response.data.mape}% | Test Set (20%): ${response.data.mape_test ?? '-'}% | Akurasi: ${response.data.accuracy}%`, 'success');

            // ── Auto-Tune result log ──────────────────────────────────────────
            if (response.data.model_is_degenerate) {
                consoleLog(`  \u26A0\uFE0F MODEL DEGENERATE (kernel sum: ${response.data.kernel_sum?.toFixed(6) ?? '-'}) \u2014 coba kurangi epoch atau ganti window size`, 'warning');
            }
            const trialsLog = mp.trials_log || [];
            if (trialsLog.length > 1) {
                const bestTrial = trialsLog.reduce((a, b) => a.mape < b.mape ? a : b);
                const trialSummary = trialsLog.map(t => `Seed${t.seed}/LR${t.lr}=${t.mape}%`).join(' | ');
                consoleLog(`  Auto-Tune (${trialsLog.length} trial): ${trialSummary}`, 'epoch');
                consoleLog(`  \u2705 Konfigurasi terbaik: Seed=${bestTrial.seed}, LR=${bestTrial.lr}, MAPE=${bestTrial.mape}%`, 'success');
            }
            // ─────────────────────────────────────────────────────────────────

            consoleLog(`  Klasifikasi: ${response.data.mape_class || '-'}`, 'success');
            consoleLog(`  Training time: ${mp.training_time_seconds || elapsed}s`, 'epoch');

            displayPredictionResults(response.data);
            const obatSelectEl = document.getElementById('obatSelect');
            const drugName = obatSelectEl?.options[obatSelectEl.selectedIndex]?.text || 'Obat';
            showExportBar(drugName);
            refreshPredictionHistoryTable();
            showToast(`Prediksi berhasil! (${elapsed}s) \u2014 ${response.data.mape_class || ''}`, 'success');
        } else {
            consoleLog('\u2717 ' + (response.message || 'Gagal'), 'error');
            showToast(response.message || 'Gagal menjalankan prediksi', 'error');
            
            if (spinnerIcon) spinnerIcon.style.display = 'none';
            if (trainingTitle) trainingTitle.textContent = 'Training Gagal!';
            resetStepper();
        }
    } catch (error) {
        console.error('Prediction error:', error);
        consoleLog('\u2717 Error: ' + error.message, 'error');
        showToast('Gagal menjalankan prediksi: ' + error.message, 'error');
        
        const spinnerIcon = document.getElementById('trainingSpinnerIcon');
        const trainingTitle = document.getElementById('trainingTitle');
        if (spinnerIcon) spinnerIcon.style.display = 'none';
        if (trainingTitle) trainingTitle.textContent = 'Terjadi Kesalahan!';
        
        resetStepper();
    } finally {
        if (timer) clearInterval(timer);
        btn.disabled = false;
        btn.innerHTML = originalText;
        if (window.lucide) lucide.createIcons();
    }
}

// ═══════════════════════════════════════════════════
// DISPLAY PREDICTION RESULTS (with cascade reveal)
// ═══════════════════════════════════════════════════
function displayPredictionResults(data) {
    window.currentPredictionData = data; // Save for pagination

    const resultsDiv = document.getElementById('predictionResults');
    if (!resultsDiv) return;
    resultsDiv.style.display = 'block';

    // Show Metrics in Training Panel
    const metricsPanel = document.getElementById('trainingMetrics');
    if (metricsPanel) metricsPanel.style.display = 'grid';

    // ─── METRIC CARDS (di training panel) ───
    const metricMSE = document.getElementById('metricMSE');
    const metricRMSE = document.getElementById('metricRMSE');
    const metricMAPE = document.getElementById('metricMAPE');
    const metricAccuracy = document.getElementById('metricAccuracy');

    const rmseVal = parseFloat(data.rmse) || 0;
    const mapeVal = parseFloat(data.mape) || 0;
    const accVal  = parseFloat(data.accuracy) || 0;

    if (metricMSE) metricMSE.textContent = (rmseVal * rmseVal).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (metricRMSE) metricRMSE.textContent = rmseVal.toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    if (metricMAPE) {
        metricMAPE.textContent = mapeVal.toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '%';
        let statusClass = 'mape-status-poor';
        if (mapeVal < 10) statusClass = 'mape-status-excellent';
        else if (mapeVal < 20) statusClass = 'mape-status-good';
        else if (mapeVal < 50) statusClass = 'mape-status-fair';
        const parentCard = metricMAPE.closest('.metric-card-bordered') || metricMAPE.parentElement;
        let badge = parentCard.querySelector('.mape-status-label');
        if (!badge) { badge = document.createElement('div'); parentCard.appendChild(badge); }
        badge.className = 'mape-status-label ' + statusClass;
        badge.textContent = data.mape_class || '';
    }
    if (metricAccuracy) metricAccuracy.textContent = accVal.toFixed(1) + '%';

    // ─── HERO SECTION (selalu terlihat di atas tabs) ───
    renderHeroSection(data, rmseVal, mapeVal, accVal);

    // Destroy old charts
    if (predictionChart) { predictionChart.destroy(); predictionChart = null; }
    if (lossChartInstance) { lossChartInstance.destroy(); lossChartInstance = null; }
    if (errorChartInstance) { errorChartInstance.destroy(); errorChartInstance = null; }

    // Create prediction chart
    predictionChart = createPredictionChart('predictionChart', {
        labels: data.historical_labels || [],
        values: data.historical_values || []
    }, {
        labels: data.prediction_labels || [],
        values: data.prediction_values || []
    });
    // Ekspor instance ke window agar bisa di-resize saat switch tab
    window.predictionChart = predictionChart;

    // Render all sections
    renderArsitektur(data);
    renderLossChart(data);
    renderNormalisasi(data);
    renderValidasiDetail(data);
    renderErrorChart(data);
    renderRekomendasi(data);

    // Cascade reveal animation
    const sections = resultsDiv.querySelectorAll('.result-section-reveal');
    sections.forEach((sec, i) => {
        setTimeout(() => { sec.classList.add('revealed'); }, 150 * i);
    });

    // Scroll to results
    setTimeout(() => {
        resultsDiv.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 300);
}

// ═══════════════════════════════════════════════════
// SECTION RENDERERS
// ═══════════════════════════════════════════════════

function renderArsitektur(data) {
    const section = document.getElementById('sectionArsitektur');
    const bobot = data.arsitektur?.bobot_final;
    if (!section || !bobot) return;
    section.style.display = 'block';

    const container = document.getElementById('bobotCardsContainer');
    if (container) {
        const wf = Array.isArray(bobot.W_f) ? bobot.W_f[0].toFixed(4) : bobot.W_f.toFixed(4);
        const wi = Array.isArray(bobot.W_i) ? bobot.W_i[0].toFixed(4) : bobot.W_i.toFixed(4);
        const wc = Array.isArray(bobot.W_c) ? bobot.W_c[0].toFixed(4) : bobot.W_c.toFixed(4);
        const wo = Array.isArray(bobot.W_o) ? bobot.W_o[0].toFixed(4) : bobot.W_o.toFixed(4);

        container.innerHTML = `
            <div class="gate-card gate-forget">
                <div class="gate-header">
                    <div class="gate-icon"><i data-lucide="minus-circle"></i></div>
                    <h4>Forget Gate (f)</h4>
                </div>
                <div class="gate-body">
                    <div class="gate-val"><span class="label">Weight</span> <span class="val">${wf}</span></div>
                    <div class="gate-val"><span class="label">Bias</span> <span class="val">${bobot.b_f.toFixed(4)}</span></div>
                </div>
            </div>
            <div class="gate-card gate-input">
                <div class="gate-header">
                    <div class="gate-icon"><i data-lucide="log-in"></i></div>
                    <h4>Input Gate (i)</h4>
                </div>
                <div class="gate-body">
                    <div class="gate-val"><span class="label">Weight</span> <span class="val">${wi}</span></div>
                    <div class="gate-val"><span class="label">Bias</span> <span class="val">${bobot.b_i.toFixed(4)}</span></div>
                </div>
            </div>
            <div class="gate-card gate-cell">
                <div class="gate-header">
                    <div class="gate-icon"><i data-lucide="cpu"></i></div>
                    <h4>Cell Candidate (C)</h4>
                </div>
                <div class="gate-body">
                    <div class="gate-val"><span class="label">Weight</span> <span class="val">${wc}</span></div>
                    <div class="gate-val"><span class="label">Bias</span> <span class="val">${bobot.b_c.toFixed(4)}</span></div>
                </div>
            </div>
            <div class="gate-card gate-output">
                <div class="gate-header">
                    <div class="gate-icon"><i data-lucide="log-out"></i></div>
                    <h4>Output Gate (o)</h4>
                </div>
                <div class="gate-body">
                    <div class="gate-val"><span class="label">Weight</span> <span class="val">${wo}</span></div>
                    <div class="gate-val"><span class="label">Bias</span> <span class="val">${bobot.b_o.toFixed(4)}</span></div>
                </div>
            </div>
        `;
        if (window.lucide) lucide.createIcons();
    }
}

function renderLossChart(data) {
    const section = document.getElementById('sectionLossChart');
    const lossHistory = data.loss_history;
    if (!section || !lossHistory || lossHistory.length === 0) return;
    section.style.display = 'block';
    lossChartInstance = createLossChart('lossChart', lossHistory, data.val_loss_history || []);
    window.lossChartInstance = lossChartInstance;
}

// ═══════════════════════════════════════════════════
// HERO SECTION — selalu terlihat di atas tabs (MAPE, RMSE, Rekomendasi)
// ═══════════════════════════════════════════════════
function renderHeroSection(data, rmseVal, mapeVal, accVal) {
    // MAPE & RMSE & MAE & MSE & Accuracy
    const heroMAPE = document.getElementById('heroMAPE');
    const heroRMSE = document.getElementById('heroRMSE');
    const heroMAE = document.getElementById('heroMAE');
    const heroMSE = document.getElementById('heroMSE');
    const heroAccuracy = document.getElementById('heroAccuracy');
    const heroMAPEClass = document.getElementById('heroMAPEClass');

    const maeVal = parseFloat(data.mae) || 0;
    const mseVal = parseFloat(data.mse) || (rmseVal * rmseVal);

    if (heroMAPE) heroMAPE.textContent = mapeVal.toFixed(2) + '%';
    if (heroRMSE) heroRMSE.textContent = rmseVal.toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (heroMAE) heroMAE.textContent = maeVal.toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (heroMSE) heroMSE.textContent = mseVal.toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (heroAccuracy) heroAccuracy.textContent = accVal.toFixed(2) + '%';

    // Tampilkan MAPE test set sebagai metrik sekunder (generalisasi 20% data terakhir)
    const heroMAPEAll = document.getElementById('heroMAPEAll');
    if (heroMAPEAll) {
        const mapeTestVal = parseFloat(data.mape_test);
        if (!isNaN(mapeTestVal)) {
            heroMAPEAll.textContent = `Test set: ${mapeTestVal.toFixed(2)}%`;
            heroMAPEAll.style.display = 'block';
        } else {
            heroMAPEAll.style.display = 'none';
        }
    }

    if (heroMAPEClass) {
        let cls = 'poor';
        if (mapeVal < 10) cls = 'excellent';
        else if (mapeVal < 20) cls = 'good';
        else if (mapeVal < 50) cls = 'fair';
        heroMAPEClass.className = 'hm-class-badge hm-class-' + cls;
        heroMAPEClass.textContent = data.mape_class || '';
    }

    // Rekomendasi
    const rekom = data.rekomendasi;
    if (rekom) {
        const status = (rekom.status || 'NORMAL').toUpperCase();
        const icon = status === 'TINGGI' ? '\u{1F4C8}' : status === 'RENDAH' ? '\u{1F4C9}' : '\u2705';
        const badgeCls = status === 'TINGGI' ? 'hero-status-tinggi' : status === 'RENDAH' ? 'hero-status-rendah' : 'hero-status-normal';

        const heroRekomStatus = document.getElementById('heroRekomStatus');
        const heroRekomBadge = document.getElementById('heroRekomBadge');
        const heroRekomText = document.getElementById('heroRekomText');

        if (heroRekomStatus) heroRekomStatus.textContent = icon + ' ' + (rekom.avg_per_minggu || 0).toLocaleString('id-ID') + ' /mg';
        if (heroRekomBadge) {
            heroRekomBadge.className = 'hero-status-badge ' + badgeCls;
            heroRekomBadge.textContent = status;
        }
        if (heroRekomText) heroRekomText.textContent = rekom.text || '';
    }
}

function renderNormalisasi(data) {
    const section = document.getElementById('sectionNormalisasi');
    const normTable = data.norm_table;
    const normInfo = data.norm_info;
    if (!section || !normTable || normTable.length === 0) return;
    section.style.display = 'block';

    const headEl = document.getElementById('normalisasiHead');
    if (headEl) {
        headEl.innerHTML = `<tr>
            <th class="text-center">Periode</th>
            <th>Aktual (Total Keluar)</th>
            <th class="text-right">Ternormalisasi</th>
        </tr>`;
    }
    
    const normDesc = document.getElementById('normDescText');
    if (normDesc && normInfo && normInfo['Total Keluar']) {
        normDesc.textContent = `Data diubah ke skala 0-1 untuk mempermudah pelatihan. (Min: ${normInfo['Total Keluar'].min}, Max: ${normInfo['Total Keluar'].max})`;
    }

    // Populate dropdown
    const selectEl = document.getElementById('normPageSelect');
    if (selectEl) {
        selectEl.innerHTML = '<option value="all">Semua Data</option>';
        const totalItems = normTable.length;
        const pageSize = 10;
        for (let i = 0; i < totalItems; i += pageSize) {
            const start = i + 1;
            const end = Math.min(i + pageSize, totalItems);
            selectEl.innerHTML += `<option value="${i}">Mg ${start} - Mg ${end}</option>`;
        }
        selectEl.value = "all"; 
    }

    renderNormalisasiPage("all");
}

window.renderNormalisasiPage = function(pageIndexStr) {
    if (!window.currentPredictionData) return;
    const normTable = window.currentPredictionData.norm_table;
    const tbody = document.getElementById('normalisasiTableBody');
    if (!tbody) return;

    let itemsToRender = normTable;
    if (pageIndexStr !== "all") {
        const startIndex = parseInt(pageIndexStr);
        itemsToRender = normTable.slice(startIndex, startIndex + 10);
    }

    let rows = '';
    itemsToRender.forEach(item => {
        const v = item['Total Keluar'];
        if (v) {
            rows += `<tr>
                <td class="text-center cell-muted">Mg ${item.minggu}</td>
                <td class="fw-600">${v.asli}</td>
                <td class="text-right cell-highlight">${Number(v.norm).toFixed(6)}</td>
            </tr>`;
        }
    });
    tbody.innerHTML = rows || '<tr><td colspan="3" class="text-center text-muted">Tidak ada data normalisasi</td></tr>';
};

function renderValidasiDetail(data) {
    const section = document.getElementById('sectionValidasi');
    const validasi = data.validation_detail;
    const future = data.predictions;
    if (!section || !validasi || validasi.length === 0) return;
    section.style.display = 'block';

    // Populate dropdown
    const selectEl = document.getElementById('valPageSelect');
    if (selectEl) {
        selectEl.innerHTML = '<option value="all">Semua Data</option>';
        const totalItems = validasi.length;
        const pageSize = 10;
        for (let i = 0; i < totalItems; i += pageSize) {
            const start = i + 1;
            const end = Math.min(i + pageSize, totalItems);
            selectEl.innerHTML += `<option value="${i}">Mg ${start} - Mg ${end}</option>`;
        }
        if (future && future.length > 0) {
            selectEl.innerHTML += `<option value="future">Prediksi Masa Depan (N+)</option>`;
        }
        selectEl.value = "all";
    }

    renderValidasiPage("all");
}

window.renderValidasiPage = function(pageIndexStr) {
    if (!window.currentPredictionData) return;
    const validasi = window.currentPredictionData.validation_detail;
    const future = window.currentPredictionData.predictions;
    const tbody = document.getElementById('validasiTableBody');
    if (!tbody) return;

    let html = '';

    if (pageIndexStr === "future") {
        if (future && future.length > 0) {
            html += future.map((p, i) => {
                const label = window.currentPredictionData.prediction_labels[i] || `Mg N+${i+1}`;
                return `<tr class="row-future row-future-highlight">
                    <td class="text-center cell-muted">${validasi.length + i + 1}</td>
                    <td class="fw-bold">${label} <span class="future-badge">FUTURE</span></td>
                    <td>${p.tanggal}</td>
                    <td class="text-right cell-muted">-</td>
                    <td class="text-right fw-700" style="color: #047857;">${Math.round(p.nilai)}</td>
                    <td class="text-right cell-muted">-</td>
                    <td class="text-right cell-muted">-</td>
                </tr>`;
            }).join('');
        }
    } else {
        let itemsToRender = validasi;
        let startIndex = 0;
        if (pageIndexStr !== "all") {
            startIndex = parseInt(pageIndexStr);
            itemsToRender = validasi.slice(startIndex, startIndex + 10);
        }

        html += itemsToRender.map((item, idx) => {
            const tMulai = item.tanggal_mulai || '-';
            const selisih = Math.abs(item.error).toFixed(2);
            const actualIdx = startIndex + idx;
            const isTest = item.is_test === true;
            const rowClass = isTest ? 'row-test-highlight' : 'row-train-dim';
            const badgeHtml = isTest
                ? '<span style="font-size:9px;background:#dbeafe;color:#1d4ed8;padding:1px 5px;border-radius:3px;margin-left:4px;font-weight:700;">TEST</span>'
                : '<span style="font-size:9px;background:#f3f4f6;color:#6b7280;padding:1px 5px;border-radius:3px;margin-left:4px;">TRAIN</span>';
            return `<tr class="${rowClass}" onclick="showTraceModal(${actualIdx})" title="Klik untuk lihat pembuktian manual">
                <td class="text-center cell-muted">${startIndex + idx + 1}</td>
                <td class="fw-600">Mg ${item.minggu}${badgeHtml}</td>
                <td>${tMulai}</td>
                <td class="text-right fw-600" style="color: #ef4444;">${Math.round(item.aktual)}</td>
                <td class="text-right fw-600" style="color: #10b981;">${Math.round(item.prediksi)}</td>
                <td class="text-right cell-muted">${selisih}</td>
                <td class="text-right" style="color:${item.ape > 50 ? '#ef4444' : item.ape > 20 ? '#f59e0b' : '#10b981'};font-weight:600;">${item.ape.toFixed(2)}%</td>
            </tr>`;
        }).join('');

        if (pageIndexStr === "all" && future && future.length > 0) {
            html += future.map((p, i) => {
                const label = window.currentPredictionData.prediction_labels[i] || `Mg N+${i+1}`;
                return `<tr class="row-future row-future-highlight">
                    <td class="text-center cell-muted">${validasi.length + i + 1}</td>
                    <td class="fw-bold">${label} <span class="future-badge">FUTURE</span></td>
                    <td>${p.tanggal}</td>
                    <td class="text-right cell-muted">-</td>
                    <td class="text-right fw-700" style="color: #047857;">${Math.round(p.nilai)}</td>
                    <td class="text-right cell-muted">-</td>
                    <td class="text-right cell-muted">-</td>
                </tr>`;
            }).join('');
        }
    }

    tbody.innerHTML = html || '<tr><td colspan="7" class="text-center text-muted">Tidak ada data</td></tr>';
};

function renderErrorChart(data) {
    const section = document.getElementById('sectionErrorChart');
    const validasi = data.validation_detail;
    if (!section || !validasi || validasi.length === 0) return;
    section.style.display = 'block';
    const labels = validasi.map(v => 'Mg ' + v.minggu);
    const errors = validasi.map(v => v.error);
    errorChartInstance = createErrorChart('errorChart', labels, errors);
    window.errorChartInstance = errorChartInstance;
}

function renderRekomendasi(data) {
    const section = document.getElementById('sectionRekomendasi');
    const rekom = data.rekomendasi;
    if (!section || !rekom) return;
    section.style.display = 'block';

    const status = (rekom.status || 'NORMAL').toUpperCase();
    const statusIcon = status === 'TINGGI' ? '\u{1F4C8}' : status === 'RENDAH' ? '\u{1F4C9}' : '\u2705';

    const contentEl = document.getElementById('rekomendasiContent');
    if (contentEl) {
        const themeColor = status === 'TINGGI' ? '#ef4444' : status === 'RENDAH' ? '#f59e0b' : '#10b981';
        const themeBg = status === 'TINGGI' ? '#fee2e2' : status === 'RENDAH' ? '#fef3c7' : '#d1fae5';
        
        contentEl.innerHTML = `
            <div style="background: #ffffff; padding: 24px; border-radius: var(--radius-md);">
                <div style="display: flex; align-items: center; gap: 16px; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid var(--border-color);">
                    <div style="width: 48px; height: 48px; border-radius: 12px; background: ${themeBg}; color: ${themeColor}; display: flex; align-items: center; justify-content: center; font-size: 24px;">
                        ${statusIcon}
                    </div>
                    <div>
                        <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); font-weight: 600; margin-bottom: 4px;">Status Permintaan</div>
                        <div style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary);">${status}</div>
                    </div>
                </div>

                <div style="background: #f8fafc; border-left: 4px solid ${themeColor}; border-radius: 4px; padding: 16px 20px; margin-bottom: 24px; color: var(--text-secondary); font-size: 0.95rem; line-height: 1.6;">
                    ${escapeHtml(rekom.text || 'Tidak ada rekomendasi spesifik saat ini.')}
                </div>

                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px;">
                    <div style="background: white; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 16px; text-align: center;">
                        <div style="font-size: 1.75rem; font-weight: 700; color: var(--text-primary); margin-bottom: 4px;">${rekom.total_kebutuhan || 0}</div>
                        <div style="font-size: 0.75rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Total Kebutuhan (Unit)</div>
                    </div>
                    <div style="background: white; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 16px; text-align: center;">
                        <div style="font-size: 1.75rem; font-weight: 700; color: var(--text-primary); margin-bottom: 4px;">${rekom.avg_per_minggu || 0}</div>
                        <div style="font-size: 0.75rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Rata-rata / Minggu</div>
                    </div>
                </div>
            </div>`;
    }
}

// Utility to escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ═══════════════════════════════════════════════════
// REFRESH RIWAYAT PREDIKSI (tanpa reload halaman)
// Dipanggil otomatis setelah prediksi baru selesai, supaya baris baru
// langsung tampil di tabel "Riwayat Prediksi Sebelumnya" tanpa perlu F5.
// ═══════════════════════════════════════════════════
async function refreshPredictionHistoryTable() {
    try {
        const res = await fetchAPI(BASE_URL + '/api/predictions.php?action=history&limit=10');
        if (!res.success) return;
        renderPredictionHistoryTable(res.data);
    } catch (e) {
        // Diamkan saja — tabel lama masih tampil, tidak mengganggu alur utama
        console.warn('Gagal me-refresh riwayat prediksi:', e);
    }
}

function renderPredictionHistoryTable(items) {
    const tbody = document.getElementById('riwayatPrediksiBody');
    if (!tbody) return;

    if (!items || items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted" style="padding:32px;">Belum ada riwayat perhitungan.</td></tr>';
        return;
    }

    tbody.innerHTML = items.map(p => {
        let params = {};
        try { params = JSON.parse(p.model_params) || {}; } catch (e) { params = {}; }
        const ep = params.epochs_actual ?? params.epochs ?? '-';
        const lr = params.learning_rate ?? '-';
        const ws = params.window_size ?? '-';

        const mapeVal = parseFloat(p.mape) || 0;
        const rmseVal = parseFloat(p.rmse) || 0;
        const maeVal  = parseFloat(p.mae) || 0;
        const akuVal  = parseFloat(p.akurasi) || 0;
        const totalData = Number(p.total_data_historis || 0);

        const tglRun = new Date(p.created_at).toLocaleDateString('id-ID', {
            day: '2-digit', month: 'short', year: 'numeric'
        }) + ', ' + new Date(p.created_at).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });

        return `<tr>
            <td><span class="fw-600" style="color:var(--primary);">${escapeHtml(p.nama_obat)}</span></td>
            <td class="text-sm">${tglRun}</td>
            <td class="text-xs text-muted">Ep: ${ep} &bull; LR: ${lr} &bull; Win: ${ws}</td>
            <td class="text-center fw-600">${totalData.toLocaleString('id-ID')} baris</td>
            <td class="text-center">
                <span class="badge ${mapeVal <= 20 ? 'badge-aman' : 'badge-waspada'}">${mapeVal.toFixed(2)}%</span>
            </td>
            <td class="text-center fw-600" style="color:#ef4444;">${rmseVal.toFixed(2)}</td>
            <td class="text-center fw-600" style="color:#d97706;">${maeVal.toFixed(2)}</td>
            <td class="text-center fw-600" style="color:#10b981;">${akuVal.toFixed(2)}%</td>
            <td class="text-center">
                <div style="display:flex; gap:6px; justify-content:center; flex-wrap:wrap;">
                    <button class="btn btn-sm" style="color:#3b82f6; background:#eff6ff; border:1px solid #dbeafe; padding: 6px 12px;" onclick="viewHistory(${p.id})" title="Lihat Detail Hasil">
                        <i data-lucide="eye" class="icon-14"></i> Lihat
                    </button>
                    <button class="btn btn-sm" style="color:#ef4444; background:#fef2f2; border:1px solid #fee2e2; padding: 6px 12px;" onclick="deleteHistory(${p.id})" title="Hapus Riwayat">
                        <i data-lucide="trash-2" class="icon-14"></i> Hapus
                    </button>
                </div>
            </td>
        </tr>`;
    }).join('');

    if (window.lucide) lucide.createIcons();
}

// ═══════════════════════════════════════════════════
// HISTORY DELETION
// ═══════════════════════════════════════════════════
function deleteHistory(id) {
    if (!confirm('Apakah Anda yakin ingin menghapus riwayat prediksi ini?')) return;
    fetch(`${BASE_URL}/api/predictions.php?action=delete`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: id })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            alert(data.message || 'Gagal menghapus riwayat');
        }
    })
    .catch(err => {
        console.error(err);
        alert('Terjadi kesalahan koneksi saat menghapus riwayat.');
    });
}

function deleteAllHistory() {
    if (!confirm('Peringatan: Anda akan menghapus SEMUA riwayat prediksi. Lanjutkan?')) return;
    fetch(`${BASE_URL}/api/predictions.php?action=delete`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: 'all' })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            alert(data.message || 'Gagal menghapus riwayat');
        }
    })
    .catch(err => {
        console.error(err);
        alert('Terjadi kesalahan koneksi saat menghapus riwayat.');
    });
}

// ═══════════════════════════════════════════════════
// VIEW HISTORY DETAIL (Read-only modal)
// ═══════════════════════════════════════════════════
let historyDetailChartInstance = null;

async function viewHistory(id) {
    // Open modal & show loading
    openModal('historyDetailModal');
    const loading = document.getElementById('historyDetailLoading');
    const content = document.getElementById('historyDetailContent');
    const subtitle = document.getElementById('historyModalSubtitle');
    
    if (loading) loading.style.display = 'block';
    if (content) content.style.display = 'none';
    if (subtitle) subtitle.textContent = 'Memuat...';

    // Destroy previous chart if exists
    if (historyDetailChartInstance) {
        historyDetailChartInstance.destroy();
        historyDetailChartInstance = null;
    }

    try {
        const response = await fetchAPI(`${BASE_URL}/api/predictions.php?action=detail&id=${id}`, {
            method: 'GET',
            timeout: 15000
        });

        if (!response.success) {
            showToast(response.message || 'Gagal memuat detail', 'error');
            closeModal('historyDetailModal');
            return;
        }

        const d = response.data;

        // Update subtitle
        if (subtitle) {
            const dateStr = new Date(d.created_at).toLocaleDateString('id-ID', {
                day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit'
            });
            subtitle.textContent = `${d.nama_obat} \u2014 ${dateStr}`;
        }

        // Update metric cards
        const mp = d.model_params || {};
        setText('histDetailEpochs', mp.epochs_actual || '-');
        setText('histDetailLR', mp.learning_rate || '-');
        setText('histDetailRMSE', parseFloat(d.rmse).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
        
        const mapeVal = parseFloat(d.mape);
        const mapeEl = document.getElementById('histDetailMAPE');
        if (mapeEl) {
            mapeEl.textContent = mapeVal.toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '%';
            mapeEl.style.color = mapeVal < 10 ? '#10b981' : mapeVal < 20 ? '#1d9e75' : mapeVal < 50 ? '#f59e0b' : '#ef4444';
        }

        // Render chart
        const chartCanvas = document.getElementById('historyDetailChart');
        if (chartCanvas && d.historical_labels && d.historical_values) {
            historyDetailChartInstance = createPredictionChart('historyDetailChart', {
                labels: d.historical_labels,
                values: d.historical_values
            }, {
                labels: d.prediction_labels || [],
                values: d.prediction_values || []
            });
        }

        // Render validation table
        const valBody = document.getElementById('historyValidationBody');
        const valSection = document.getElementById('historyValidationSection');
        if (valBody && d.validation_detail && d.validation_detail.length > 0) {
            if (valSection) valSection.style.display = 'block';
            valBody.innerHTML = d.validation_detail.map((item, idx) => {
                const selisih = Math.abs(item.error || (item.aktual - item.prediksi)).toFixed(2);
                const tgl = item.tanggal_mulai || '-';
                return `<tr>
                    <td class="text-center cell-muted">${idx + 1}</td>
                    <td class="fw-600">Mg ${item.minggu || (idx + 1)}</td>
                    <td>${tgl}</td>
                    <td class="text-right fw-600" style="color:#ef4444;">${Math.round(item.aktual)}</td>
                    <td class="text-right fw-600" style="color:#10b981;">${Math.round(item.prediksi)}</td>
                    <td class="text-right cell-muted">${selisih}</td>
                </tr>`;
            }).join('');
        } else {
            if (valSection) valSection.style.display = 'none';
        }

        // Render future predictions table
        const futBody = document.getElementById('historyFutureBody');
        const futSection = document.getElementById('historyFutureSection');
        if (futBody && d.prediction_values && d.prediction_values.length > 0) {
            if (futSection) futSection.style.display = 'block';
            futBody.innerHTML = d.prediction_values.map((val, idx) => {
                const label = (d.prediction_labels && d.prediction_labels[idx]) || `Mg N+${idx + 1}`;
                return `<tr class="row-future">
                    <td class="text-center cell-muted">${idx + 1}</td>
                    <td class="fw-600">${label} <span class="badge badge-aman" style="font-size:9px;margin-left:4px;">Future</span></td>
                    <td class="text-right fw-600" style="color:#10b981;">${Math.round(val)}</td>
                </tr>`;
            }).join('');
        } else {
            if (futSection) futSection.style.display = 'none';
        }

        // Render rekomendasi
        const rekomSection = document.getElementById('historyRekomendasiSection');
        const rekomContent = document.getElementById('historyRekomendasiContent');
        if (rekomContent && d.rekomendasi) {
            const rekom = d.rekomendasi;
            const status = (rekom.status || 'NORMAL').toUpperCase();
            const statusIcon = status === 'TINGGI' ? '\u{1F4C8}' : status === 'RENDAH' ? '\u{1F4C9}' : '\u2705';
            const themeColor = status === 'TINGGI' ? '#ef4444' : status === 'RENDAH' ? '#f59e0b' : '#10b981';
            const themeBg = status === 'TINGGI' ? '#fee2e2' : status === 'RENDAH' ? '#fef3c7' : '#d1fae5';

            if (rekomSection) rekomSection.style.display = 'block';
            rekomContent.innerHTML = `
                <div style="background: #ffffff; padding: 20px; border-radius: var(--radius-md);">
                    <div style="display: flex; align-items: center; gap: 14px; margin-bottom: 16px; padding-bottom: 14px; border-bottom: 1px solid var(--border-color);">
                        <div style="width: 42px; height: 42px; border-radius: 10px; background: ${themeBg}; color: ${themeColor}; display: flex; align-items: center; justify-content: center; font-size: 22px;">
                            ${statusIcon}
                        </div>
                        <div>
                            <div style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); font-weight: 600; margin-bottom: 2px;">Status Permintaan</div>
                            <div style="font-size: 1.15rem; font-weight: 700; color: var(--text-primary);">${status}</div>
                        </div>
                    </div>

                    <div style="background: #f8fafc; border-left: 4px solid ${themeColor}; border-radius: 4px; padding: 14px 18px; margin-bottom: 18px; color: var(--text-secondary); font-size: 0.88rem; line-height: 1.6;">
                        ${escapeHtml(rekom.text || 'Tidak ada rekomendasi spesifik saat ini.')}
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;">
                        <div style="background: white; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 14px; text-align: center;">
                            <div style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 4px;">${rekom.total_kebutuhan || 0}</div>
                            <div style="font-size: 0.7rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Total Kebutuhan (Unit)</div>
                        </div>
                        <div style="background: white; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 14px; text-align: center;">
                            <div style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 4px;">${rekom.avg_per_minggu || 0}</div>
                            <div style="font-size: 0.7rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Rata-rata / Minggu</div>
                        </div>
                    </div>
                </div>`;
        } else {
            if (rekomSection) rekomSection.style.display = 'none';
        }

        // Show content, hide loading
        if (loading) loading.style.display = 'none';
        if (content) content.style.display = 'block';

        // Refresh icons
        if (window.lucide) lucide.createIcons();

    } catch (error) {
        console.error('viewHistory error:', error);
        showToast('Gagal memuat detail riwayat: ' + error.message, 'error');
        closeModal('historyDetailModal');
    }
}

// Helper: set text content safely
function setText(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
}

// ═══════════════════════════════════════════════════════════════════
// TRACEABILITY MODAL — Pembuktian Manual Perhitungan Per-Baris
// ═══════════════════════════════════════════════════════════════════

const FEATURE_LABELS = [
    'Stok Awal Minggu',
    'Total Masuk',
    'Total Keluar',
    'Stok Akhir Minggu',
    'Rata-rata Keluar/Hari'
];
const TARGET_FEATURE_INDEX = 2; // Total Keluar

// ═══════════════════════════════════════════════════
// EXPORT FUNCTIONS — Excel & Cetak/PDF
// ═══════════════════════════════════════════════════

window.exportToExcel = function() {
    const data = window.currentPredictionData;
    if (!data) { alert('Tidak ada data prediksi untuk diekspor.'); return; }
    if (typeof XLSX === 'undefined') { alert('Library SheetJS belum siap, coba lagi.'); return; }

    const wb       = XLSX.utils.book_new();
    const drugName = data.drug_name || 'Obat';
    const runDate  = new Date().toLocaleString('id-ID', { timeZone: 'Asia/Jakarta' });
    const mp       = data.model_params || {};

    // ── Sheet 1: Ringkasan ──
    const summaryRows = [
        ['SISTEM PREDIKSI PERSEDIAAN OBAT — APOTEK ZAM ZAM'],
        [],
        ['Nama Obat',          drugName],
        ['Tanggal Ekspor',     runDate],
        [],
        ['METRIK EVALUASI MODEL'],
        ['MAPE (Semua Data)',  `${data.mape}%`],
        ['MAPE (Test Set)',    `${data.mape_test ?? '-'}%`],
        ['RMSE',               data.rmse],
        ['MAE',                data.mae],
        ['Akurasi',            `${data.accuracy}%`],
        [],
        ['PARAMETER MODEL'],
        ['Epochs Aktual / Maks', `${mp.epochs_actual ?? '-'} / ${mp.epochs_configured ?? '-'}`],
        ['Epoch Terbaik',      mp.epoch_terbaik ?? '-'],
        ['Learning Rate',      mp.learning_rate ?? '-'],
        ['Window Size',        mp.window_size ?? '-'],
        ['Hidden Units',       mp.hidden_units ?? '-'],
        ['Seed Terbaik',       mp.best_seed ?? '-'],
        ['Waktu Training',     mp.training_time_seconds ? `${parseFloat(mp.training_time_seconds).toFixed(1)} detik` : '-'],
    ];
    const wsSummary = XLSX.utils.aoa_to_sheet(summaryRows);
    wsSummary['!cols'] = [{ wch: 30 }, { wch: 26 }];
    XLSX.utils.book_append_sheet(wb, wsSummary, 'Ringkasan');

    // ── Sheet 2: Data Training & Validasi ──
    const hdr2 = ['No', 'Minggu', 'Tanggal', 'Jenis Data', 'Aktual (Unit)', 'Prediksi (Unit)', 'Selisih', 'APE (%)'];
    const rows2 = (data.validation_detail || []).map((it, i) => [
        i + 1,
        `Mg ${it.minggu}`,
        it.tanggal_mulai || '-',
        it.is_test ? 'TEST' : 'TRAIN',
        it.aktual,
        Math.round(it.prediksi),
        parseFloat(it.error.toFixed(2)),
        parseFloat(it.ape.toFixed(2)),
    ]);
    const wsValid = XLSX.utils.aoa_to_sheet([hdr2, ...rows2]);
    wsValid['!cols'] = [
        { wch: 5 }, { wch: 10 }, { wch: 14 }, { wch: 12 },
        { wch: 14 }, { wch: 15 }, { wch: 10 }, { wch: 10 },
    ];
    XLSX.utils.book_append_sheet(wb, wsValid, 'Data Training & Validasi');

    // ── Sheet 3: Prediksi Masa Depan ──
    const hdr3   = ['No', 'Periode', 'Tanggal Prediksi', 'Prediksi (Unit)'];
    const preds  = data.predictions || [];
    const labels = data.prediction_labels || [];
    const rows3  = preds.map((it, i) => [
        i + 1,
        labels[i] || `N+${i + 1}`,
        it.tanggal || '-',
        Math.round(it.nilai),
    ]);
    const wsFuture = XLSX.utils.aoa_to_sheet([hdr3, ...rows3]);
    wsFuture['!cols'] = [{ wch: 5 }, { wch: 14 }, { wch: 18 }, { wch: 16 }];
    XLSX.utils.book_append_sheet(wb, wsFuture, 'Prediksi Masa Depan');

    // ── Download ──
    const safeName = drugName.replace(/[^a-zA-Z0-9]/g, '_');
    XLSX.writeFile(wb, `Prediksi_${safeName}_${new Date().toISOString().slice(0, 10)}.xlsx`);
};

window.exportToPrint = function() {
    const data = window.currentPredictionData;
    if (!data) { alert('Tidak ada data prediksi untuk dicetak.'); return; }

    const drugName  = data.drug_name || 'Obat';
    const mp        = data.model_params || {};
    const runDate   = new Date().toLocaleString('id-ID', { timeZone: 'Asia/Jakarta' });
    const mapeNum   = parseFloat(data.mape);
    const mapeColor = mapeNum <= 10 ? '#16a34a' : mapeNum <= 20 ? '#d97706' : '#dc2626';
    const preds     = data.predictions || [];
    const labels    = data.prediction_labels || [];

    const validRows = (data.validation_detail || []).map((it, i) => {
        const badge = it.is_test
            ? '<span style="background:#dbeafe;color:#1d4ed8;padding:1px 4px;border-radius:3px;font-size:9px;font-weight:700;">TEST</span>'
            : '<span style="background:#f3f4f6;color:#6b7280;padding:1px 4px;border-radius:3px;font-size:9px;">TRAIN</span>';
        return `<tr style="${it.is_test ? 'background:#eff6ff;' : ''}">
            <td>${i + 1}</td>
            <td>Mg ${it.minggu} ${badge}</td>
            <td>${it.tanggal_mulai || '-'}</td>
            <td>${it.aktual.toLocaleString('id-ID')}</td>
            <td>${Math.round(it.prediksi).toLocaleString('id-ID')}</td>
            <td>${it.error.toFixed(2)}</td>
            <td style="color:${it.ape > 20 ? '#dc2626' : '#16a34a'};font-weight:600;">${it.ape.toFixed(2)}%</td>
        </tr>`;
    }).join('');

    const futureRows = preds.map((it, i) =>
        `<tr><td>${i + 1}</td><td>${labels[i] || `N+${i+1}`}</td><td>${it.tanggal || '-'}</td>
         <td style="font-weight:700;color:#1d4ed8;">${Math.round(it.nilai).toLocaleString('id-ID')} unit</td></tr>`
    ).join('');

    const html = `<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8">
<title>Laporan Prediksi — ${drugName}</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Segoe UI',Arial,sans-serif; font-size:10.5pt; color:#1e293b; background:#fff; }
.hdr { background:linear-gradient(135deg,#1e3a5f,#1d9e75); color:#fff; padding:18px 28px;
       display:flex; justify-content:space-between; align-items:center; }
.hdr h1 { font-size:13pt; font-weight:700; } .hdr p { font-size:8.5pt; opacity:.85; margin-top:3px; }
.drug-pill { background:rgba(255,255,255,.2); padding:5px 14px; border-radius:20px; font-size:12pt; font-weight:700; }
.content { padding:20px 28px; }
.sec-title { font-size:10.5pt; font-weight:700; color:#1e3a5f; border-bottom:2px solid #dbeafe;
             padding-bottom:5px; margin:20px 0 12px; }
.metrics { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; }
.mc { border:1px solid #e2e8f0; border-radius:7px; padding:10px; text-align:center; }
.ml { font-size:7.5pt; text-transform:uppercase; letter-spacing:.5px; color:#64748b; margin-bottom:3px; }
.mv { font-size:15pt; font-weight:700; }
.params { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; }
.pi { background:#f8fafc; border-radius:6px; padding:7px 10px; }
.pl { font-size:7.5pt; color:#64748b; } .pv { font-size:9.5pt; font-weight:600; }
table { width:100%; border-collapse:collapse; font-size:9pt; }
th { background:#1e3a5f; color:#fff; padding:7px 9px; text-align:left; font-size:8.5pt; }
td { padding:5px 9px; border-bottom:1px solid #e2e8f0; }
tr:last-child td { border-bottom:none; }
.footer { margin-top:28px; text-align:center; font-size:8pt; color:#94a3b8;
          border-top:1px solid #e2e8f0; padding-top:10px; }
.print-btn { position:fixed; bottom:20px; right:20px; background:#1e3a5f; color:#fff;
             border:none; padding:9px 18px; border-radius:8px; font-size:10pt; cursor:pointer;
             box-shadow:0 4px 12px rgba(0,0,0,.2); }
@media print { .print-btn { display:none; }
  @page { margin:1cm; }
  body { -webkit-print-color-adjust:exact; print-color-adjust:exact; } }
</style></head><body>
<div class="hdr"><div><h1>Laporan Prediksi Persediaan Obat</h1>
<p>Sistem LSTM — Apotek Zam Zam &nbsp;|&nbsp; ${runDate}</p></div>
<div class="drug-pill">${drugName}</div></div>
<div class="content">
  <div class="sec-title">📊 Metrik Evaluasi Model</div>
  <div class="metrics">
    <div class="mc"><div class="ml">MAPE</div><div class="mv" style="color:${mapeColor};">${data.mape}%</div></div>
    <div class="mc"><div class="ml">RMSE</div><div class="mv" style="color:#dc2626;">${parseFloat(data.rmse).toLocaleString('id-ID',{maximumFractionDigits:2})}</div></div>
    <div class="mc"><div class="ml">MAE</div><div class="mv" style="color:#d97706;">${parseFloat(data.mae).toLocaleString('id-ID',{maximumFractionDigits:2})}</div></div>
    <div class="mc"><div class="ml">Akurasi</div><div class="mv" style="color:#16a34a;">${data.accuracy}%</div></div>
  </div>
  <div class="sec-title">⚙️ Parameter Model LSTM</div>
  <div class="params">
    <div class="pi"><div class="pl">Epochs Aktual</div><div class="pv">${mp.epochs_actual ?? '-'} / ${mp.epochs_configured ?? '-'}</div></div>
    <div class="pi"><div class="pl">Learning Rate</div><div class="pv">${mp.learning_rate ?? '-'}</div></div>
    <div class="pi"><div class="pl">Window Size</div><div class="pv">${mp.window_size ?? '-'}</div></div>
    <div class="pi"><div class="pl">Hidden Units</div><div class="pv">${mp.hidden_units ?? '-'}</div></div>
    <div class="pi"><div class="pl">Seed Terbaik</div><div class="pv">${mp.best_seed ?? '-'}</div></div>
    <div class="pi"><div class="pl">Waktu Training</div><div class="pv">${mp.training_time_seconds ? parseFloat(mp.training_time_seconds).toFixed(1)+' dtk' : '-'}</div></div>
  </div>
  <div class="sec-title">📋 Data Training &amp; Validasi</div>
  <table><thead><tr><th>No</th><th>Periode</th><th>Tanggal</th>
  <th>Aktual</th><th>Prediksi</th><th>Selisih</th><th>APE (%)</th></tr></thead>
  <tbody>${validRows}</tbody></table>
  <div class="sec-title">🔮 Prediksi Masa Depan</div>
  <table><thead><tr><th>No</th><th>Periode</th><th>Tanggal</th><th>Prediksi (Unit)</th></tr></thead>
  <tbody>${futureRows}</tbody></table>
  <div class="footer">Laporan ini dibuat otomatis oleh Sistem Prediksi Persediaan Obat LSTM — Apotek Zam Zam<br>${runDate}</div>
</div>
<button class="print-btn" onclick="window.print()">🖨️ Cetak / Simpan PDF</button>
<script>window.onload=()=>setTimeout(()=>window.print(),600);<\/script>
</body></html>`;

    const win = window.open('', '_blank', 'width=940,height=720,scrollbars=yes');
    if (!win) { alert('Pop-up diblokir browser. Izinkan pop-up untuk halaman ini.'); return; }
    win.document.write(html);
    win.document.close();
};

// ── Tampilkan export bar + simpan drug_name setelah prediksi berhasil ──
function showExportBar(drugName) {
    const bar  = document.getElementById('exportBar');
    const label = document.getElementById('exportBarDrugName');
    if (bar)   bar.style.display = 'flex';
    if (label) label.textContent = `Simpan hasil — ${drugName}:`;
    if (window.currentPredictionData) window.currentPredictionData.drug_name = drugName;
    if (window.lucide) window.lucide.createIcons();
}

window.showTraceModal = function(validationIdx) {
    const data = window.currentPredictionData;
    if (!data || !data.validation_detail) return;
    const entry = data.validation_detail[validationIdx];
    if (!entry || !entry.trace) { console.warn('Trace data tidak tersedia.'); return; }

    const trace = entry.trace;
    const g = trace.gates;
    const minggu = entry.minggu;
    const tanggal = entry.tanggal_mulai || '-';

    setText('traceModalSubtitle', `Minggu ke-${minggu} \u00B7 ${tanggal}`);

    const body = document.getElementById('traceModalBody');
    if (!body) return;

    const featureHeaders = FEATURE_LABELS.map(f => `<th>${f}</th>`).join('');
    const inputRows = trace.input_window.map(win => `
        <tr>
            <td class="fw-600">Mg ${win.row_minggu}</td>
            ${win.features_asli.map(v => `<td class="num-asli">${v.toLocaleString('id-ID')}</td>`).join('')}
        </tr>`).join('');
    const normRows = trace.input_window.map(win => `
        <tr>
            <td class="fw-600">Mg ${win.row_minggu}</td>
            ${win.features_norm.map(v => `<td class="num-norm">${v.toFixed(6)}</td>`).join('')}
        </tr>`).join('');

    const dlm = String.fromCharCode(36, 36);
    const x = trace.input_window[0]?.features_norm || [];
    const tMin = trace.target_min;
    const tMax = trace.target_max;
    const tRange = trace.target_range;
    const predNorm = trace.prediksi_norm;

    function gateCalcHtml(W, U, h_prev, xVals, b, z, label) {
        if (!W) return '<em style="color:#94a3b8;">Data bobot tidak tersedia (prediksi lama)</em>';
        return `
            <p class="trace-step-desc" style="margin-top:10px;">Substitusi nilai bobot (${label}):</p>
            <div class="trace-formula" style="font-size:0.82rem;">
                ${dlm}z = (U \\times h_{t-1}) + (W_1 \\times x_1) + \\cdots + (W_5 \\times x_5) + b${dlm}
            </div>
            <div class="trace-formula" style="background:#f8fafc;font-size:0.78rem;margin-top:6px;">
                <div style="text-align:left;padding:8px 12px;font-family:monospace;line-height:1.8;">
                    z = (${U?.toFixed(4) || 0} &times; ${h_prev?.toFixed(6) || 0})<br>
                    ${W.map((w, i) => `&nbsp;&nbsp;&nbsp;&nbsp;+ (${w.toFixed(4)} &times; ${xVals[i]?.toFixed(6) || 0})`).join('<br>')}<br>
                    &nbsp;&nbsp;&nbsp;&nbsp;+ ${b?.toFixed(4) || 0}<br>
                    <strong>= ${z?.toFixed(6) || 0}</strong>
                </div>
            </div>`;
    }

    body.innerHTML = `
        <div class="trace-step">
            <div class="trace-step-header"><span class="trace-step-number">1</span>
                <h4 class="trace-step-title">Input Mentah \u2014 Data ${trace.input_window.length} Minggu</h4></div>
            <p class="trace-step-desc">Sistem mengambil data Minggu ke-${minggu - 1} sebagai input untuk memprediksi Minggu ke-${minggu}.</p>
            <div style="overflow-x:auto;"><table class="trace-table">
                <thead><tr><th>Periode</th>${featureHeaders}</tr></thead>
                <tbody>${inputRows}</tbody>
            </table></div>
        </div>

        <div class="trace-step">
            <div class="trace-step-header"><span class="trace-step-number">2</span>
                <h4 class="trace-step-title">Normalisasi Min-Max ke Skala [0, 1]</h4></div>
            <p class="trace-step-desc">Setiap fitur dinormalisasi menggunakan rumus Min-Max agar gradient descent stabil.</p>
            <div class="trace-formula">${dlm}X_{\\text{norm}} = \\frac{X - X_{\\min}}{X_{\\max} - X_{\\min}}${dlm}</div>
            <div style="overflow-x:auto;"><table class="trace-table">
                <thead><tr><th>Periode</th>${featureHeaders}</tr></thead>
                <tbody>${normRows}</tbody>
            </table></div>
        </div>

        <div class="trace-step">
            <div class="trace-step-header"><span class="trace-step-number">3</span>
                <h4 class="trace-step-title">Forget Gate &mdash; Menentukan Informasi yang Dilupakan</h4></div>
            <p class="trace-step-desc"><em>Forget gate</em> menentukan informasi <em>cell state</em> sebelumnya yang akan dipertahankan atau dibuang menggunakan fungsi sigmoid.</p>
            <div class="trace-formula">${dlm}f_t = \\sigma(U_f \\cdot h_{t-1} + W_f \\cdot x_t + b_f)${dlm}</div>
            <p class="trace-step-desc">h<sub>t-1</sub> = <strong>${g?.h_prev?.toFixed(6) ?? '0.000000'}</strong>, C<sub>t-1</sub> = <strong>${g?.c_prev?.toFixed(6) ?? '0.000000'}</strong></p>
            ${gateCalcHtml(g?.Wf, g?.Uf, g?.h_prev, x, g?.bf, g?.z_f, 'Forget Gate')}
            <div class="trace-formula" style="margin-top:8px;">${dlm}f_t = \\sigma(${g?.z_f?.toFixed(6) || 0}) = ${g?.f_t?.toFixed(6) || 0}${dlm}</div>
        </div>

        <div class="trace-step">
            <div class="trace-step-header"><span class="trace-step-number">4</span>
                <h4 class="trace-step-title">Input Gate &mdash; Menentukan Informasi Baru</h4></div>
            <p class="trace-step-desc"><em>Input gate</em> menentukan informasi baru yang akan disimpan ke <em>cell state</em>.</p>
            <div class="trace-formula">${dlm}i_t = \\sigma(U_i \\cdot h_{t-1} + W_i \\cdot x_t + b_i)${dlm}</div>
            ${gateCalcHtml(g?.Wi, g?.Ui, g?.h_prev, x, g?.bi, g?.z_i, 'Input Gate')}
            <div class="trace-formula" style="margin-top:8px;">${dlm}i_t = \\sigma(${g?.z_i?.toFixed(6) || 0}) = ${g?.i_t?.toFixed(6) || 0}${dlm}</div>
        </div>

        <div class="trace-step">
            <div class="trace-step-header"><span class="trace-step-number">5</span>
                <h4 class="trace-step-title">Candidate Cell State &mdash; Nilai Kandidat Baru</h4></div>
            <p class="trace-step-desc"><em>Candidate cell state</em> menyediakan nilai baru yang berpotensi ditambahkan ke memori menggunakan fungsi tanh.</p>
            <div class="trace-formula">${dlm}\\tilde{C}_t = \\tanh(U_c \\cdot h_{t-1} + W_c \\cdot x_t + b_c)${dlm}</div>
            ${gateCalcHtml(g?.Wc, g?.Uc, g?.h_prev, x, g?.bc, g?.z_c, 'Candidate Cell')}
            <div class="trace-formula" style="margin-top:8px;">${dlm}\\tilde{C}_t = \\tanh(${g?.z_c?.toFixed(6) || 0}) = ${g?.c_tilde?.toFixed(6) || 0}${dlm}</div>
        </div>

        <div class="trace-step">
            <div class="trace-step-header"><span class="trace-step-number">6</span>
                <h4 class="trace-step-title">Pembaruan Cell State &mdash; Memori Jangka Panjang</h4></div>
            <p class="trace-step-desc"><em>Cell state</em> merupakan komponen inti LSTM yang berfungsi sebagai memori jangka panjang.</p>
            <div class="trace-formula">${dlm}C_t = f_t \\odot C_{t-1} + i_t \\odot \\tilde{C}_t${dlm}</div>
            <div class="trace-formula" style="background:#f8fafc;font-size:0.85rem;">
                ${dlm}C_t = ${g?.f_t?.toFixed(6) || 0} \\times ${g?.c_prev?.toFixed(6) || 0} + ${g?.i_t?.toFixed(6) || 0} \\times ${g?.c_tilde?.toFixed(6) || 0} = ${g?.c_t?.toFixed(6) || 0}${dlm}
            </div>
        </div>

        <div class="trace-step">
            <div class="trace-step-header"><span class="trace-step-number">7</span>
                <h4 class="trace-step-title">Output Gate &mdash; Menentukan Keluaran</h4></div>
            <p class="trace-step-desc"><em>Output gate</em> menentukan informasi apa dari <em>cell state</em> yang akan dikeluarkan sebagai <em>hidden state</em>.</p>
            <div class="trace-formula">${dlm}o_t = \\sigma(U_o \\cdot h_{t-1} + W_o \\cdot x_t + b_o)${dlm}</div>
            ${gateCalcHtml(g?.Wo, g?.Uo, g?.h_prev, x, g?.bo, g?.z_o, 'Output Gate')}
            <div class="trace-formula" style="margin-top:8px;">${dlm}o_t = \\sigma(${g?.z_o?.toFixed(6) || 0}) = ${g?.o_t?.toFixed(6) || 0}${dlm}</div>
        </div>

        <div class="trace-step">
            <div class="trace-step-header"><span class="trace-step-number">8</span>
                <h4 class="trace-step-title">Hidden State &mdash; Output LSTM</h4></div>
            <p class="trace-step-desc"><em>Hidden state</em> dihitung dengan mengalikan <em>output gate</em> terhadap aktivasi tanh dari <em>cell state</em>.</p>
            <div class="trace-formula">${dlm}h_t = o_t \\odot \\tanh(C_t)${dlm}</div>
            <div class="trace-formula" style="background:#f8fafc;font-size:0.85rem;">
                ${dlm}h_t = ${g?.o_t?.toFixed(6) || 0} \\times \\tanh(${g?.c_t?.toFixed(6) || 0}) = ${g?.h_t?.toFixed(6) || 0}${dlm}
            </div>
        </div>

        <div class="trace-step">
            <div class="trace-step-header"><span class="trace-step-number">9</span>
                <h4 class="trace-step-title">Output Layer &mdash; Nilai Prediksi Ternormalisasi</h4></div>
            <p class="trace-step-desc"><em>Hidden state</em> h<sub>t</sub> dilewatkan ke lapisan keluaran untuk menghasilkan nilai prediksi ternormalisasi.</p>
            <div class="trace-formula">${dlm}y_t = W_y \\cdot h_t + b_y${dlm}</div>
            <div class="trace-formula" style="background:#fef3c7;border-color:#fbbf24;font-size:0.85rem;">
                ${dlm}y_t = ${g?.Wy?.toFixed(4) || 0} \\times ${g?.h_t?.toFixed(6) || 0} + ${g?.by?.toFixed(4) || 0} = ${predNorm.toFixed(6)}${dlm}
            </div>
        </div>

        <div class="trace-step">
            <div class="trace-step-header"><span class="trace-step-number">10</span>
                <h4 class="trace-step-title">Denormalisasi ke Skala Asli (Unit Obat)</h4></div>
            <p class="trace-step-desc">Nilai y<sub>t</sub> dikembalikan ke skala unit asli menggunakan rumus invers Min-Max.</p>
            <div class="trace-formula">${dlm}X = X_{\\text{norm}} \\times (X_{\\max} - X_{\\min}) + X_{\\min}${dlm}</div>
            <p class="trace-step-desc" style="margin-top:10px;">Dengan X<sub>min</sub> = <strong>${tMin.toLocaleString('id-ID')}</strong> dan X<sub>max</sub> = <strong>${tMax.toLocaleString('id-ID')}</strong>:</p>
            <div class="trace-formula" style="background:#f8fafc;font-size:0.85rem;">
                ${dlm}X = ${predNorm.toFixed(6)} \\times (${tMax.toLocaleString('id-ID')} - ${tMin.toLocaleString('id-ID')}) + ${tMin.toLocaleString('id-ID')} = ${entry.prediksi.toFixed(2)}${dlm}
            </div>
            <p class="trace-step-desc" style="margin-top:10px;"><strong>Prediksi akhir: ${Math.round(entry.prediksi).toLocaleString('id-ID')} unit obat.</strong></p>
        </div>

        <div class="trace-step">
            <div class="trace-step-header"><span class="trace-step-number">11</span>
                <h4 class="trace-step-title">Perhitungan APE sebagai Evaluasi Akurasi</h4></div>
            <p class="trace-step-desc">Hasil prediksi disandingkan dengan data aktual untuk menghitung <em>Absolute Percentage Error</em> (APE).</p>
            <div class="trace-formula">${dlm}\\text{APE} = \\frac{|Y_{\\text{aktual}} - \\hat{Y}|}{Y_{\\text{aktual}}} \\times 100\\%${dlm}</div>
            <div class="trace-formula" style="background:#f8fafc;font-size:0.85rem;">
                ${dlm}\\text{APE} = \\frac{|${entry.aktual} - ${entry.prediksi.toFixed(2)}|}{${entry.aktual}} \\times 100\\% = ${entry.ape.toFixed(2)}\\%${dlm}
            </div>
        </div>

        <div class="trace-conclusion">
            <p class="trace-conclusion-title">\u{1F4CA} Ringkasan Perhitungan Minggu ke-${minggu}</p>
            <div class="trace-conclusion-grid">
                <div class="trace-conclusion-item"><div class="lbl">Aktual</div><div class="val" style="color:#dc2626;">${Math.round(entry.aktual).toLocaleString('id-ID')}</div></div>
                <div class="trace-conclusion-item"><div class="lbl">Prediksi LSTM</div><div class="val" style="color:#2563eb;">${Math.round(entry.prediksi).toLocaleString('id-ID')}</div></div>
                <div class="trace-conclusion-item"><div class="lbl">APE</div><div class="val">${entry.ape.toFixed(2)}%</div></div>
            </div>
        </div>
    `;

    const modal = document.getElementById('traceModal');
    if (modal) modal.classList.add('open');
    document.body.style.overflow = 'hidden';

    if (window.MathJax && window.MathJax.typesetPromise) {
        window.MathJax.typesetPromise([body]).catch(err => console.warn('MathJax error:', err));
    }
};

window.closeTraceModal = function(event) {
    if (event && event.target && event.target.id !== 'traceModal' && event.currentTarget?.id !== 'traceModal') {
        // Tidak menutup jika klik di dalam modal content
    }
    const modal = document.getElementById('traceModal');
    if (modal) modal.classList.remove('open');
    document.body.style.overflow = '';
};

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        const modal = document.getElementById('traceModal');
        if (modal && modal.classList.contains('open')) closeTraceModal();
    }
});
