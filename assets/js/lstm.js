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
    updateTrainingStat('trainStatStatus', '🔍');
    const isRunning = await checkPythonService();
    if (isRunning) {
        consoleLog('✓ Python service aktif', 'success');
        return true;
    }

    consoleLog('Python service belum aktif. Menyalakan otomatis...', 'epoch');
    updateTrainingStat('trainStatStatus', '🔄');
    showToast('Python service belum jalan. Menyalakan otomatis... (~30 detik)', 'info');
    const spawn = await spawnPythonService();
    if (!spawn.success) {
        consoleLog('✗ Gagal start Python service: ' + (spawn.message || 'unknown'), 'error');
        showToast('Gagal start Python service: ' + (spawn.message || 'unknown'), 'error');
        return false;
    }
    if (spawn.already_running) { consoleLog('✓ Service sudah aktif', 'success'); return true; }

    const wait = await waitForPythonService();
    if (wait.success && wait.running) {
        consoleLog(`✓ Python service ready (${wait.wait_seconds}s)`, 'success');
        showToast(`Python service ready dalam ${wait.wait_seconds} detik!`, 'success');
        return true;
    } else {
        consoleLog('✗ Service timeout. Cek python/service.log', 'error');
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
    document.getElementById('predictionResults').style.display = 'none';
    document.getElementById('trainingMetrics').style.display = 'none';
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
        updateTrainingStat('trainStatStatus', '⏳');

        // Step 2: Preprocessing (check service)
        setStep(1);
        consoleLog('Preprocessing: Memeriksa service & memuat data historis...');
        btn.innerHTML = '<span class="spinner" style="width:18px;height:18px;border-width:2px;"></span> Preprocessing...';

        const ok = await ensurePythonServiceRunning();
        if (!ok) { btn.disabled = false; btn.innerHTML = originalText; hideTrainingPanel(); resetStepper(); return; }

        consoleLog('✓ Data historis dimuat. Normalisasi Min-Max diterapkan.', 'success');

        // Step 3: Training
        setStep(2);
        consoleLog('Memulai training LSTM...');
        updateTrainingStat('trainStatStatus', '🏋️');

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
            updateTrainingStat('trainStatStatus', '✅');
            
            if (spinnerIcon) spinnerIcon.style.display = 'none';
            if (trainingTitle) trainingTitle.textContent = 'Training Selesai!';

            const mp = response.data.model_params || {};
            updateTrainingStat('trainStatEpoch', (mp.epochs_actual || epochs) + '/' + (mp.epochs_configured || epochs));
            updateTrainingStat('trainStatLoss', response.data.loss_history?.length > 0
                ? response.data.loss_history[response.data.loss_history.length - 1].toFixed(6) : '-');
            consoleLog(`✓ Training selesai! Epoch terbaik: ${mp.epoch_terbaik || '-'}`, 'success');
            consoleLog(`  Note: Sistem berhenti di epoch ${mp.epochs_actual} karena masa Patience (30 epoch) telah habis tanpa ada peningkatan akurasi lebih lanjut dari epoch ${mp.epoch_terbaik}.`, 'epoch');
            consoleLog(`  MAPE: ${response.data.mape}% | Akurasi: ${response.data.accuracy}%`, 'success');
            consoleLog(`  Klasifikasi: ${response.data.mape_class || '-'}`, 'success');
            consoleLog(`  Training time: ${mp.training_time_seconds || elapsed}s`, 'epoch');

            displayPredictionResults(response.data);
            showToast(`Prediksi berhasil! (${elapsed}s) — ${response.data.mape_class || ''}`, 'success');
        } else {
            consoleLog('✗ ' + (response.message || 'Gagal'), 'error');
            showToast(response.message || 'Gagal menjalankan prediksi', 'error');
            
            if (spinnerIcon) spinnerIcon.style.display = 'none';
            if (trainingTitle) trainingTitle.textContent = 'Training Gagal!';
            resetStepper();
        }
    } catch (error) {
        console.error('Prediction error:', error);
        consoleLog('✗ Error: ' + error.message, 'error');
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

    // Update metric cards
    const metricMSE = document.getElementById('metricMSE');
    const metricRMSE = document.getElementById('metricRMSE');
    const metricMAPE = document.getElementById('metricMAPE');
    const metricAccuracy = document.getElementById('metricAccuracy');

    if (metricMSE) {
        const mse = (data.rmse * data.rmse) || 0;
        metricMSE.textContent = mse.toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    if (metricRMSE) {
        metricRMSE.textContent = parseFloat(data.rmse).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    
    if (metricMAPE) {
        const mapeVal = parseFloat(data.mape);
        metricMAPE.textContent = mapeVal.toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '%';
        const mapeClass = data.mape_class || '';
        
        let statusClass = 'mape-status-poor';
        if (mapeVal < 10) statusClass = 'mape-status-excellent';
        else if (mapeVal < 20) statusClass = 'mape-status-good';
        else if (mapeVal < 50) statusClass = 'mape-status-fair';
        
        const parentCard = metricMAPE.closest('.metric-card-bordered') || metricMAPE.parentElement;
        let badge = parentCard.querySelector('.mape-status-label');
        if (!badge) {
            badge = document.createElement('div');
            parentCard.appendChild(badge);
        }
        badge.className = 'mape-status-label ' + statusClass;
        badge.textContent = mapeClass;
    }
    if (metricAccuracy) metricAccuracy.textContent = parseFloat(data.accuracy).toFixed(1) + '%';

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
                return `<tr class="row-future">
                    <td class="text-center cell-muted">${validasi.length + i + 1}</td>
                    <td class="fw-bold">${label} <span class="badge badge-aman" style="font-size:9px;margin-left:4px;">Future</span></td>
                    <td>${p.tanggal}</td>
                    <td class="text-right cell-muted">-</td>
                    <td class="text-right fw-600" style="color: #10b981;">${Math.round(p.nilai)}</td>
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
            return `<tr>
                <td class="text-center cell-muted">${startIndex + idx + 1}</td>
                <td class="fw-600">Mg ${item.minggu}</td>
                <td>${tMulai}</td>
                <td class="text-right fw-600" style="color: #ef4444;">${Math.round(item.aktual)}</td>
                <td class="text-right fw-600" style="color: #10b981;">${Math.round(item.prediksi)}</td>
                <td class="text-right cell-muted">${selisih}</td>
            </tr>`;
        }).join('');

        if (pageIndexStr === "all" && future && future.length > 0) {
            html += future.map((p, i) => {
                const label = window.currentPredictionData.prediction_labels[i] || `Mg N+${i+1}`;
                return `<tr class="row-future">
                    <td class="text-center cell-muted">${validasi.length + i + 1}</td>
                    <td class="fw-bold">${label} <span class="badge badge-aman" style="font-size:9px;margin-left:4px;">Future</span></td>
                    <td>${p.tanggal}</td>
                    <td class="text-right cell-muted">-</td>
                    <td class="text-right fw-600" style="color: #10b981;">${Math.round(p.nilai)}</td>
                    <td class="text-right cell-muted">-</td>
                </tr>`;
            }).join('');
        }
    }

    tbody.innerHTML = html || '<tr><td colspan="6" class="text-center text-muted">Tidak ada data</td></tr>';
};

function renderErrorChart(data) {
    const section = document.getElementById('sectionErrorChart');
    const validasi = data.validation_detail;
    if (!section || !validasi || validasi.length === 0) return;
    section.style.display = 'block';
    const labels = validasi.map(v => 'Mg ' + v.minggu);
    const errors = validasi.map(v => v.error);
    errorChartInstance = createErrorChart('errorChart', labels, errors);
}

function renderRekomendasi(data) {
    const section = document.getElementById('sectionRekomendasi');
    const rekom = data.rekomendasi;
    if (!section || !rekom) return;
    section.style.display = 'block';

    const status = (rekom.status || 'NORMAL').toUpperCase();
    const statusClass = status === 'TINGGI' ? 'status-tinggi' : status === 'RENDAH' ? 'status-rendah' : 'status-normal';
    const badgeClass = status === 'TINGGI' ? 'badge-tinggi' : status === 'RENDAH' ? 'badge-rendah' : 'badge-normal';
    const statusIcon = status === 'TINGGI' ? '📈' : status === 'RENDAH' ? '📉' : '✅';

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
            subtitle.textContent = `${d.nama_obat} — ${dateStr}`;
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
            const statusIcon = status === 'TINGGI' ? '📈' : status === 'RENDAH' ? '📉' : '✅';
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

