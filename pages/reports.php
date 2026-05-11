<?php
/**
 * Official LSTM Prediction Reports Page
 * Apotek Zam Zam
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();
$db = getDB();
$pageTitle = 'Cetak Laporan Prediksi';

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/reports.css">

<main class="main-content">
    <div class="report-center-container">
        <!-- Header Section -->
        <div class="report-header-section print-hidden">
            <h1>Report Center</h1>
            <button class="btn btn-primary" onclick="window.location.href='predictions.php'">
                <i data-lucide="plus"></i> New Report
            </button>
        </div>

        <!-- Controls Section -->
        <div class="report-controls print-hidden">
            <div class="search-wrapper">
                <i data-lucide="search"></i>
                <input type="text" id="reportSearch" class="search-input" placeholder="Search drug reports...">
            </div>
            <select id="reportSort" class="sort-select">
                <option value="recent">Sort By: Recent</option>
                <option value="accuracy">Sort By: Accuracy</option>
                <option value="name">Sort By: Name</option>
            </select>
        </div>

        <!-- Filter Tabs -->
        <div class="filter-tabs print-hidden">
            <div class="filter-tab active" data-filter="all">All Reports</div>
            <div class="filter-tab" data-filter="recent">Recent</div>
            <div class="filter-tab" data-filter="high">High Accuracy</div>
            <div class="filter-tab" data-filter="low">Low Accuracy</div>
            <div class="filter-tab" data-filter="archived">Archived</div>
        </div>

        <!-- Report Grid -->
        <div id="reportGrid" class="report-grid">
            <!-- Cards will be injected here -->
        </div>
        
        <div id="emptyState" class="report-empty-state" style="display:none; text-align: center; padding: 80px 20px;">
            <div style="background: #f1f5f9; width: 80px; height: 80px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 24px auto;">
                <i data-lucide="file-text" style="width: 32px; height: 32px; color: #94a3b8;"></i>
            </div>
            <h3 style="color: #1e293b; font-size: 1.25rem; font-weight: 700; margin-bottom: 8px;">Belum Ada Data</h3>
            <p style="color: #64748b; font-size: 0.95rem;">Silakan jalankan prediksi terlebih dahulu untuk melihat laporan di sini.</p>
        </div>
    </div>

    <!-- Report Detail Modal -->
    <div id="reportModal" class="report-modal">
        <div class="report-modal-content">
            <button class="report-modal-close" onclick="closeReportModal()">
                <i data-lucide="x"></i>
            </button>
            
            <div id="print-area" class="a4-paper">
                <!-- Kop Surat -->
                <div class="kop-surat" style="border-bottom: 2px solid #000; padding-bottom: 8px; margin-bottom: 15px; box-sizing: border-box;">
                    <div class="kop-content" style="display: grid; grid-template-columns: 120px 1fr 120px; align-items: center; width: 100%;">
                        <div class="kop-logo">
                            <img src="<?php echo BASE_URL; ?>/assets/img/logo-black.png" alt="Logo" style="width: 80px; height: 80px; object-fit: contain;">
                        </div>
                        <div class="kop-text" style="text-align: center;">
                            <h1 class="kop-title" style="font-size: 22px; font-weight: 800; letter-spacing: 1px; margin: 0; color: #000;">APOTEK ZAM ZAM</h1>
                            <p class="kop-subtitle" style="font-size: 11px; font-weight: 700; color: #000; margin: 2px 0; text-transform: uppercase;">MANAJEMEN PERENCANAAN & PENGENDALIAN PERSEDIAAN OBAT</p>
                            <div class="kop-contact" style="font-size: 9px; color: #000; line-height: 1.2;">
                                <p style="margin: 0;">Jl. DR. Setia Budi No.14, Bedilan, Kebungson, Kec. Gresik, Kabupaten Gresik, Jawa Timur 61114</p>
                                <p style="margin: 0;">Telepon: 085100943749</p>
                            </div>
                        </div>
                        <div class="kop-spacer"></div>
                    </div>
                </div>

                <!-- Judul Dokumen -->
                <div class="doc-title" style="text-align: center; margin-bottom: 15px;">
                    <h2 style="font-size: 15px; font-weight: 800; text-transform: uppercase; margin-bottom: 2px; color: #1e293b;">LAPORAN ANALISIS PREDIKSI STOK OBAT</h2>
                    <p id="docNumber" style="font-family: monospace; font-size: 10px; color: #64748b;">Nomor: ...</p>
                </div>

                <!-- Isi Dokumen -->
                <div class="doc-body">
                    <p style="margin-bottom: 10px; font-size: 11px; line-height: 1.4; text-align: justify; color: #334155;">
                        Yang bertanda tangan di bawah ini menerangkan bahwa telah dilakukan analisis prediksi kebutuhan stok obat menggunakan kecerdasan buatan berbasis metode <strong>Long Short-Term Memory (LSTM)</strong> dengan rincian teknis sebagai berikut:
                    </p>
                    
                    <h4 class="doc-section-title" style="font-size: 10px; font-weight: 800; color: #1e293b; border-left: 4px solid #1d9e75; padding-left: 10px; margin: 15px 0 8px 0;">1. PARAMETER TEKNIS & INFO OBAT</h4>
                    <table class="data-table-print" style="width: 100%; border-collapse: collapse; margin-bottom: 15px;">
                        <tbody>
                            <tr>
                                <td style="width: 20%; background: #f8fafc; font-size: 8px; font-weight: 700; color: #475569; padding: 8px; border: 1px solid #e2e8f0; text-transform: uppercase;">Nama Obat</td>
                                <td id="docObat" style="width: 30%; font-size: 11px; font-weight: 700; color: #1e293b; padding: 8px; border: 1px solid #e2e8f0;">-</td>
                                <td style="width: 25%; background: #f8fafc; font-size: 8px; font-weight: 700; color: #475569; padding: 8px; border: 1px solid #e2e8f0; text-transform: uppercase;">Tingkat Kesalahan (MAPE)</td>
                                <td id="docMape" style="width: 25%; font-size: 11px; font-weight: 800; color: #1e293b; padding: 8px; border: 1px solid #e2e8f0;">-</td>
                            </tr>
                            <tr>
                                <td style="background: #f8fafc; font-size: 8px; font-weight: 700; color: #475569; padding: 8px; border: 1px solid #e2e8f0; text-transform: uppercase;">Waktu Proses</td>
                                <td id="docDate" style="font-size: 10px; font-weight: 700; color: #1e293b; padding: 8px; border: 1px solid #e2e8f0;">-</td>
                                <td style="background: #f8fafc; font-size: 8px; font-weight: 700; color: #475569; padding: 8px; border: 1px solid #e2e8f0; text-transform: uppercase;">Akurasi Prediksi</td>
                                <td id="docAcc" style="font-size: 11px; font-weight: 800; color: #1e293b; padding: 8px; border: 1px solid #e2e8f0;">-</td>
                            </tr>
                            <tr>
                                <td style="background: #f8fafc; font-size: 8px; font-weight: 700; color: #475569; padding: 8px; border: 1px solid #e2e8f0; text-transform: uppercase;">Epochs Aktual</td>
                                <td id="docEpoch" style="font-size: 10px; font-weight: 700; color: #1e293b; padding: 8px; border: 1px solid #e2e8f0;">-</td>
                                <td style="background: #f8fafc; font-size: 8px; font-weight: 700; color: #475569; padding: 8px; border: 1px solid #e2e8f0; text-transform: uppercase;">Window Size</td>
                                <td id="docWindow" style="font-size: 10px; font-weight: 700; color: #1e293b; padding: 8px; border: 1px solid #e2e8f0;">-</td>
                            </tr>
                        </tbody>
                    </table>

                    <h4 class="doc-section-title" style="font-size: 10px; font-weight: 800; color: #1e293b; border-left: 4px solid #1d9e75; padding-left: 10px; margin: 15px 0 8px 0;">2. VISUALISASI TREN VALIDASI MODEL</h4>
                    <div class="chart-box" style="border: 1px solid #f1f5f9; border-radius: 12px; padding: 10px; height: 200px; margin-bottom: 5px;">
                        <canvas id="docChart"></canvas>
                    </div>
                    <div class="chart-legend" style="display: flex; justify-content: center; gap: 15px; margin-bottom: 15px; font-size: 8px; font-weight: 700; text-transform: uppercase; color: #64748b;">
                        <div><span style="border-top: 2px dashed #94a3b8; width: 15px; display: inline-block; margin-right: 5px; vertical-align: middle;"></span> ACTUAL</div>
                        <div><span style="border-top: 2px solid #0f172a; width: 15px; display: inline-block; margin-right: 5px; vertical-align: middle;"></span> PREDIKSI MODEL</div>
                    </div>

                    <h4 class="doc-section-title" style="font-size: 10px; font-weight: 800; color: #1e293b; border-left: 4px solid #1d9e75; padding-left: 10px; margin: 15px 0 8px 0;">3. TABULASI HASIL PREDIKSI KEBUTUHAN OBAT (MENDATANG)</h4>
                    <table class="data-table-print" style="width: 100%; border-collapse: collapse; margin-bottom: 5px;">
                        <thead>
                            <tr>
                                <th style="background: #f8fafc; padding: 8px; border: 1px solid #e2e8f0; font-size: 8px; color: #475569; text-align: center;">NO</th>
                                <th style="background: #f8fafc; padding: 8px; border: 1px solid #e2e8f0; font-size: 8px; color: #475569; text-align: left;">PERIODE (MINGGU MENDATANG)</th>
                                <th style="background: #f8fafc; padding: 8px; border: 1px solid #e2e8f0; font-size: 8px; color: #475569; text-align: right;">PREDIKSI KEBUTUHAN (PCS)</th>
                                <th style="background: #f8fafc; padding: 8px; border: 1px solid #e2e8f0; font-size: 8px; color: #475569; text-align: center;">CONFIDENCE</th>
                            </tr>
                        </thead>
                        <tbody id="docTableBody">
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="2" style="padding: 8px; border: 1px solid #e2e8f0; font-size: 10px; font-weight: 800; text-align: right;">Total Kebutuhan:</td>
                                <td id="docTotalPred" style="padding: 8px; border: 1px solid #e2e8f0; font-size: 11px; font-weight: 800; text-align: right;">0</td>
                                <td style="padding: 8px; border: 1px solid #e2e8f0;"></td>
                            </tr>
                        </tfoot>
                    </table>

                    <p style="margin-top:20px; font-size: 11px; line-height: 1.5; color: #475569;">Demikian laporan ini dibuat oleh sistem cerdas secara otomatis untuk dipergunakan sebagaimana mestinya sebagai bahan pertimbangan dalam pengambilan keputusan pengadaan obat.</p>
                </div>

                <!-- Tanda Tangan -->
                <div class="signatures" style="margin-top: 15px; display: flex; justify-content: space-between; padding: 0 20px;">
                    <div class="signature-box" style="text-align: center; width: 180px;">
                        <p style="font-size: 10px; margin-bottom: 40px; color: #334155;">Verifikasi Sistem,</p>
                        <div style="border-bottom: 1px solid #000; width: 100%; margin-bottom: 3px;"></div>
                        <p style="font-size: 10px; font-weight: 800; text-transform: uppercase; margin: 0;">AI PREDICT ENGINE</p>
                    </div>
                    
                    <div class="signature-box" style="text-align: center; width: 180px;">
                        <p id="docSignDate" style="font-size: 10px; margin-bottom: 40px; color: #334155;">Gresik, 8 Mei 2026</p>
                        <div style="border-bottom: 1px solid #000; width: 100%; margin-bottom: 3px;"></div>
                        <p style="font-size: 10px; font-weight: 800; text-transform: uppercase; margin: 0;">APOTEKER PENANGGUNG JAWAB</p>
                        <p style="font-size: 8px; color: #64748b; margin-top: 1px;">SIPA. 19920824 201503 1 002</p>
                    </div>
                </div>

                <div class="print-hidden" style="margin-top: 15px; text-align: center;">
                    <button class="btn btn-primary" onclick="window.print()">
                        <i data-lucide="printer"></i> Print Report
                    </button>
                </div>
            </div>
        </div>
    </div>
</main>
</div>

<script src="<?php echo BASE_URL; ?>/assets/js/main.js"></script>
<script>
let reportsData = [];
let chartInstance = null;
let miniCharts = {};

document.addEventListener('DOMContentLoaded', () => {
    loadHistory();
    
    // Search & Sort Event Listeners
    document.getElementById('reportSearch').addEventListener('input', renderGrid);
    document.getElementById('reportSort').addEventListener('change', renderGrid);
    
    // Filter Tab Event Listeners
    document.querySelectorAll('.filter-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            renderGrid();
        });
    });
});

async function loadHistory() {
    try {
        const data = await fetchAPI(BASE_URL + '/api/predictions.php?action=history&limit=50');
        reportsData = data.data || [];
        renderGrid();
    } catch (err) {
        console.error(err);
        showToast('Gagal memuat riwayat', 'error');
    }
}

function renderGrid() {
    const search = document.getElementById('reportSearch').value.toLowerCase();
    const sort = document.getElementById('reportSort').value;
    const filter = document.querySelector('.filter-tab.active').dataset.filter;
    
    let filtered = reportsData.filter(r => {
        const matchesSearch = r.nama_obat.toLowerCase().includes(search);
        
        let matchesFilter = true;
        if (filter === 'recent') {
            const oneWeekAgo = new Date();
            oneWeekAgo.setDate(oneWeekAgo.getDate() - 7);
            matchesFilter = new Date(r.created_at) > oneWeekAgo;
        } else if (filter === 'high') {
            matchesFilter = parseFloat(r.akurasi) >= 80;
        } else if (filter === 'low') {
            matchesFilter = parseFloat(r.akurasi) < 80;
        }
        
        return matchesSearch && matchesFilter;
    });
    
    // Sort
    if (sort === 'accuracy') {
        filtered.sort((a, b) => b.akurasi - a.akurasi);
    } else if (sort === 'name') {
        filtered.sort((a, b) => a.nama_obat.localeCompare(b.nama_obat));
    } else {
        filtered.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
    }
    
    const grid = document.getElementById('reportGrid');
    const empty = document.getElementById('emptyState');
    
    if (filtered.length === 0) {
        grid.innerHTML = '';
        empty.style.display = 'block';
        return;
    }
    
    empty.style.display = 'none';
    grid.innerHTML = filtered.map(r => {
        const date = new Date(r.created_at).toLocaleDateString('id-ID', {day:'2-digit', month:'long', year:'numeric'});
        const time = new Date(r.created_at).toLocaleTimeString('id-ID', {hour:'2-digit', minute:'2-digit'});
        const acc = parseFloat(r.akurasi).toFixed(1);
        
        return `
            <div class="report-card" onclick="selectReport(${r.id})">
                <div class="report-card-status">
                    <span class="status-indicator"></span>
                    <span class="status-text">Validated Result</span>
                </div>
                <div class="report-card-chart">
                    <canvas id="miniChart-${r.id}"></canvas>
                </div>
                <div class="report-card-info">
                    <div class="report-card-name">${r.nama_obat}</div>
                    <div class="report-card-meta">
                        <div class="meta-item">
                            <i data-lucide="calendar" style="width: 12px; height: 12px;"></i>
                            <span>${date}</span>
                        </div>
                        <div class="meta-item">
                            <i data-lucide="clock" style="width: 12px; height: 12px;"></i>
                            <span>${time}</span>
                        </div>
                        <div class="meta-item accuracy-tag">
                            <span>MAPE: <strong>${parseFloat(r.mape).toFixed(2)}%</strong></span>
                        </div>
                    </div>
                </div>
                <button class="report-card-btn">
                    <span>Buka Laporan</span>
                    <i data-lucide="chevron-right"></i>
                </button>
            </div>
        `;
    }).join('');
    
    // Initialize mini charts after rendering
    filtered.forEach(r => {
        const params = r.model_params ? JSON.parse(r.model_params) : {};
        renderMiniChart(r.id, params.validation_detail || []);
    });
    
    lucide.createIcons();
}

function renderMiniChart(id, validationData) {
    const ctx = document.getElementById(`miniChart-${id}`).getContext('2d');
    const labels = validationData.map(v => v.minggu);
    const actuals = validationData.map(v => v.aktual);
    const preds = validationData.map(v => v.prediksi);
    
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    data: actuals,
                    borderColor: '#94a3b8',
                    borderWidth: 1,
                    pointRadius: 1,
                    fill: false,
                    tension: 0.3
                },
                {
                    data: preds,
                    borderColor: '#0f172a',
                    borderWidth: 1.5,
                    pointRadius: 2,
                    pointBackgroundColor: '#0f172a',
                    fill: false,
                    tension: 0.3
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { enabled: false } },
            scales: { 
                x: { display: true, ticks: { display: true, font: { size: 8 } }, grid: { display: false } }, 
                y: { display: true, ticks: { display: true, font: { size: 8 } }, grid: { color: '#f1f5f9' } } 
            },
            layout: { padding: 5 }
        }
    });
}

function selectReport(id) {
    const report = reportsData.find(r => r.id === id);
    if (!report) return;
    
    document.getElementById('reportModal').style.display = 'block';
    document.body.style.overflow = 'hidden'; // Prevent scroll
    
    renderDocument(report);
}

function closeReportModal() {
    document.getElementById('reportModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('reportModal');
    if (event.target == modal) {
        closeReportModal();
    }
}

function renderDocument(report) {
    const params = report.model_params ? JSON.parse(report.model_params) : {};
    const predictions = report.nilai_prediksi ? JSON.parse(report.nilai_prediksi) : [];
    
    // Header Data
    const d = new Date(report.created_at);
    document.getElementById('docNumber').textContent = `Nomor: ${report.id}/ML-LSTM/ZAMZAM/${d.getFullYear()}`;
    document.getElementById('docDate').textContent = d.toLocaleString('id-ID', {day:'2-digit', month:'long', year:'numeric', hour:'2-digit', minute:'2-digit'});
    document.getElementById('docSignDate').textContent = `Gresik, ${d.toLocaleDateString('id-ID', {day:'numeric', month:'long', year:'numeric'})}`;
    document.getElementById('docObat').textContent = `${report.nama_obat}`;
    document.getElementById('docMape').textContent = parseFloat(report.mape).toFixed(2) + '%';
    document.getElementById('docAcc').textContent = parseFloat(report.akurasi).toFixed(1) + '%';
    
    document.getElementById('docEpoch').textContent = params.epochs_actual ? params.epochs_actual + ' Iterasi' : '-';
    document.getElementById('docWindow').textContent = params.window_size ? params.window_size + ' Minggu' : '-';
    
    // Table Data
    let tableHtml = '';
    let total = 0;
    const futureLabels = params.future_labels || Array.from({length: predictions.length}, (_, i) => 'Mg ' + (i+1));

    predictions.forEach((val, i) => {
        const rounded = Math.round(val);
        total += rounded;
        tableHtml += `
            <tr>
                <td style="padding: 10px; border: 1px solid #e2e8f0; text-align: center; font-size: 11px;">${i+1}</td>
                <td style="padding: 10px; border: 1px solid #e2e8f0; text-align: left; font-size: 11px; font-weight: 500;">${futureLabels[i]}</td>
                <td style="padding: 10px; border: 1px solid #e2e8f0; text-align: right; font-size: 12px; font-weight: 800;">${rounded}</td>
                <td style="padding: 10px; border: 1px solid #e2e8f0; text-align: center; font-size: 11px; color: #64748b;">${parseFloat(report.confidence).toFixed(1)}%</td>
            </tr>
        `;
    });
    document.getElementById('docTableBody').innerHTML = tableHtml;
    document.getElementById('docTotalPred').textContent = total;
    
    // Main Chart
    renderChart(params.validation_detail || []);
}

function renderChart(validationData) {
    if (chartInstance) chartInstance.destroy();
    
    const ctx = document.getElementById('docChart').getContext('2d');
    const labels = validationData.map(v => v.minggu);
    const actuals = validationData.map(v => v.aktual);
    const preds = validationData.map(v => v.prediksi);
    
    chartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Actual',
                    data: actuals,
                    borderColor: '#94a3b8',
                    borderWidth: 1.5,
                    borderDash: [5, 5],
                    pointRadius: 0,
                    fill: false,
                    tension: 0.2
                },
                {
                    label: 'Predicted',
                    data: preds,
                    borderColor: '#0f172a',
                    borderWidth: 2,
                    pointBackgroundColor: '#0f172a',
                    pointRadius: 3,
                    fill: false,
                    tension: 0.2
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 9 } } },
                y: { grid: { color: '#f1f5f9' }, min: 0, ticks: { font: { size: 9 } } }
            },
            layout: { padding: 5 }
        }
    });
}
</script>
</body>
</html>
