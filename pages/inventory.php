<?php
/**
 * Inventory Page - Manajemen Data Persediaan
 * Apotek Zam Zam
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();
$db = getDB();
$pageTitle = 'Manajemen Data Persediaan';

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<main class="main-content">
    <div class="page-header">
        <div>
            <h1>Manajemen Data Persediaan</h1>
            <p class="subtitle">Input manual atau impor data persediaan mingguan dari CSV</p>
        </div>
    </div>

    <!-- ═══════ IMPORT CSV/EXCEL PANEL ═══════ -->
    <div class="card mb-3 fade-in" id="importPanel">
        <div class="card-header" style="border-bottom:1px solid var(--border-color);padding-bottom:16px;margin-bottom:0;">
            <h3 style="margin: 0;"><i data-lucide="file-spreadsheet" class="icon-20 icon-inline"></i> Import Data Persediaan</h3>
        </div>

        <div class="param-config-section" style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; padding: 16px 20px; align-items: flex-start; margin-bottom: 0;">
            <!-- Left: Upload CSV -->
            <div class="param-config-left" style="display: flex; flex-direction: column;">
                <div style="margin-bottom: 12px;">
                    <h4 style="font-size: 0.85rem; color: var(--text-primary); margin: 0; display: flex; align-items: center; gap: 6px;">
                        <i data-lucide="cloud-upload" style="width: 16px; height: 16px; color: var(--primary);"></i> Upload CSV
                    </h4>
                </div>
                <div id="importDropZone" class="import-drop-zone" style="display: flex; flex-direction: column; justify-content: center; align-items: center; min-height: 240px; padding: 24px; margin-bottom: 0; border: 1.5px dashed var(--border-color); border-radius: var(--radius-md); background: rgba(248, 250, 252, 0.5); cursor: pointer; transition: all 0.2s ease;" onclick="document.getElementById('csvFileInput').click()" ondrop="handleDrop(event)" ondragover="handleDragOver(event)" ondragleave="handleDragLeave(event)">
                    <input type="file" id="csvFileInput" accept=".csv,.xlsx,.xls" class="d-none" onchange="handleFileSelect(this)">
                    <div id="importDropContent" style="text-align: center;">
                        <i data-lucide="cloud-upload" style="width: 28px; height: 28px; color: var(--text-muted); margin-bottom: 12px;"></i>
                        <p class="fw-600 mb-0" style="font-size: 0.85rem; color: var(--text-primary);">Klik atau drag & drop file</p>
                        <p class="text-xs text-muted mt-2">Format: CSV atau Excel</p>
                    </div>
                    <div id="importProcessing" class="d-none" style="text-align: center;">
                        <div class="spinner m-auto mb-2" style="width: 24px; height: 24px;"></div>
                        <p class="fw-600 text-xs" style="color: var(--text-primary);">Memproses...</p>
                    </div>
                </div>
                
                <div style="margin-top: 16px; padding: 16px; background: rgba(0,0,0,0.02); border-radius: var(--radius-sm); border: 1px solid var(--border-color);">
                    <h5 style="font-size: 0.8rem; margin: 0 0 8px 0; color: var(--text-primary); display: flex; align-items: center; gap: 6px;">
                        <i data-lucide="info" style="width:14px;height:14px;color:var(--primary);"></i> Format Kolom Template CSV
                    </h5>
                    <div style="font-size: 0.7rem; color: var(--text-muted); margin-bottom: 12px; line-height: 1.5; font-family: monospace; background: white; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px; overflow-x: auto;">
                        Minggu Ke, Tanggal Mulai, Tanggal Akhir, Nama Obat, Kategori, Stok Awal Minggu, Total Masuk, Total Keluar, Stok Akhir Minggu, Rata-rata Keluar/Hari
                    </div>
                    <button type="button" class="btn btn-secondary" onclick="downloadTemplate()" style="width: 100%; display:inline-flex; justify-content:center; align-items:center; gap:8px; height: 36px; font-size: 0.8rem; background: white;">
                        <i data-lucide="download" style="width:16px;height:16px;"></i> Download File Contoh CSV
                    </button>
                </div>

                <div id="importResult" class="param-info-card d-none" style="margin-top: 12px; padding: 10px 14px; background: rgba(29, 158, 117, 0.05); border: 1px solid rgba(29, 158, 117, 0.2); border-radius: var(--radius-sm);">
                    <p class="fw-600 text-xs" id="importResultText" style="margin: 0;"></p>
                </div>
            </div>

            <!-- Right: Input Manual -->
            <div class="param-config-right" style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 20px; display: flex; flex-direction: column; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
                <div style="margin-bottom: 16px;">
                    <h4 style="font-size: 0.85rem; color: var(--text-primary); margin: 0; display: flex; align-items: center; gap: 6px;">
                        <i data-lucide="pencil-line" style="width: 16px; height: 16px; color: var(--primary);"></i> Input Manual
                    </h4>
                </div>
                <form id="manualInputForm" onsubmit="submitManualInput(event)" style="display: flex; flex-direction: column; flex: 1;">
                    <div class="manual-input-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
                        <!-- Row 1 -->
                        <div class="manual-field" style="grid-column: span 2;">
                            <label class="form-label text-xs fw-600" style="font-size: 0.75rem; margin-bottom: 4px; color: var(--text-secondary);">Nama Obat</label>
                            <input type="text" class="form-control" id="manNamaObat" required placeholder="cth: Amoxicillin" list="obatListSuggestion" style="padding: 8px 12px; font-size: 0.8rem; height: 36px; border-radius: var(--radius-sm);">
                            <datalist id="obatListSuggestion"></datalist>
                        </div>
                        <!-- Row 2 -->
                        <div class="manual-field">
                            <label class="form-label text-xs fw-600" style="font-size: 0.75rem; margin-bottom: 4px; color: var(--text-secondary);">Kategori</label>
                            <input type="text" class="form-control" id="manKategori" placeholder="cth: Antibiotik" style="padding: 8px 12px; font-size: 0.8rem; height: 36px; border-radius: var(--radius-sm);">
                        </div>
                        <div class="manual-field">
                            <label class="form-label text-xs fw-600" style="font-size: 0.75rem; margin-bottom: 4px; color: var(--text-secondary);">Minggu Ke</label>
                            <input type="number" class="form-control" id="manMingguKe" min="1" required placeholder="1" style="padding: 8px 12px; font-size: 0.8rem; height: 36px; border-radius: var(--radius-sm);">
                        </div>
                        <!-- Row 3 -->
                        <div class="manual-field">
                            <label class="form-label text-xs fw-600" style="font-size: 0.75rem; margin-bottom: 4px; color: var(--text-secondary);">Tanggal Mulai</label>
                            <input type="date" class="form-control" id="manTglMulai" required style="padding: 8px 12px; font-size: 0.8rem; height: 36px; border-radius: var(--radius-sm);">
                        </div>
                        <div class="manual-field">
                            <label class="form-label text-xs fw-600" style="font-size: 0.75rem; margin-bottom: 4px; color: var(--text-secondary);">Tanggal Akhir</label>
                            <input type="date" class="form-control" id="manTglAkhir" required style="padding: 8px 12px; font-size: 0.8rem; height: 36px; border-radius: var(--radius-sm);">
                        </div>
                        <!-- Row 4 -->
                        <div class="manual-field">
                            <label class="form-label text-xs fw-600" style="font-size: 0.75rem; margin-bottom: 4px; color: var(--text-secondary);">Stok Awal</label>
                            <input type="number" class="form-control" id="manStokAwal" min="0" required placeholder="0" style="padding: 8px 12px; font-size: 0.8rem; height: 36px; border-radius: var(--radius-sm);">
                        </div>
                        <div class="manual-field">
                            <label class="form-label text-xs fw-600" style="font-size: 0.75rem; margin-bottom: 4px; color: var(--text-secondary);">Total Masuk</label>
                            <input type="number" class="form-control" id="manTotalMasuk" min="0" required placeholder="0" style="padding: 8px 12px; font-size: 0.8rem; height: 36px; border-radius: var(--radius-sm);">
                        </div>
                        <!-- Row 5 -->
                        <div class="manual-field">
                            <label class="form-label text-xs fw-600" style="font-size: 0.75rem; margin-bottom: 4px; color: var(--text-secondary);">Total Keluar</label>
                            <input type="number" class="form-control" id="manTotalKeluar" min="0" required placeholder="0" style="padding: 8px 12px; font-size: 0.8rem; height: 36px; border-radius: var(--radius-sm);">
                        </div>
                        <div class="manual-field">
                            <label class="form-label text-xs fw-600" style="font-size: 0.75rem; margin-bottom: 4px; color: var(--text-secondary);">Stok Akhir</label>
                            <input type="number" class="form-control" id="manStokAkhir" min="0" required placeholder="0" style="padding: 8px 12px; font-size: 0.8rem; height: 36px; border-radius: var(--radius-sm);">
                        </div>
                        <!-- Row 6 -->
                        <div class="manual-field" style="grid-column: span 2;">
                            <label class="form-label text-xs fw-600" style="font-size: 0.75rem; margin-bottom: 4px; color: var(--text-secondary);">Rata-rata Keluar/Hari</label>
                            <input type="number" class="form-control" id="manRataRata" min="0" step="0.01" required placeholder="0.00" style="padding: 8px 12px; font-size: 0.8rem; height: 36px; border-radius: var(--radius-sm);">
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:12px; margin-top: auto;">
                        <button type="submit" class="btn btn-primary" id="btnSubmitManual" style="flex: 1; justify-content: center; height: 38px; font-size: 0.85rem; font-weight: 600; box-shadow: 0 4px 12px rgba(29, 158, 117, 0.2);">
                            <i data-lucide="save" style="width:16px;height:16px;"></i> Simpan
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="resetManualForm()" style="height: 38px; padding: 0 14px;" title="Reset Form">
                            <i data-lucide="rotate-ccw" style="width:16px;height:16px;"></i>
                        </button>
                    </div>
                    <div id="manualResultMsg" class="text-xs fw-600" style="margin-top: 8px; text-align: center; min-height: 16px;"></div>
                </form>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-3 fade-in">
        <div class="flex-between" style="flex-wrap:wrap;gap:12px;">
            <div class="search-bar">
                <i data-lucide="search" class="search-icon"></i>
                <input type="text" id="searchInput" placeholder="Cari nama obat..." oninput="handleSearch()">
            </div>
            <div>
                <button type="button" class="btn btn-danger" onclick="deleteAllHistory()" style="display:flex; align-items:center; gap:8px;">
                    <i data-lucide="trash-2" style="width: 16px; height: 16px;"></i> Hapus Semua Data
                </button>
            </div>
        </div>
    </div>

    <!-- History Table -->
    <div class="card fade-in" id="tableHistorisContainer">
        <div class="card-header" style="border-bottom:1px solid var(--border-color);padding-bottom:16px;margin-bottom:16px;">
            <h3>Database Persediaan</h3>
        </div>
        <div class="table-container">
            <table class="data-table" id="historisTable">
                <thead>
                    <tr>
                        <th>Minggu Ke</th>
                        <th>Tanggal Mulai</th>
                        <th>Tanggal Akhir</th>
                        <th>Nama Obat</th>
                        <th>Kategori</th>
                        <th>Stok Awal</th>
                        <th>Total Masuk</th>
                        <th>Total Keluar</th>
                        <th>Stok Akhir</th>
                        <th>Rata-rata/Hari</th>
                        <th class="text-center" style="width: 60px;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="historisBody">
                    <tr><td colspan="11" class="text-center text-muted" style="padding:40px;">
                        <div class="spinner" style="margin:0 auto 12px;"></div>
                        Memuat data persediaan...
                    </td></tr>
                </tbody>
            </table>
        </div>
        <div id="paginationContainerHistoris"></div>
    </div>
</main>

</div><!-- end app-wrapper -->

<script src="<?php echo BASE_URL; ?>/assets/js/main.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/charts.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/PapaParse/5.4.1/papaparse.min.js"></script>

<script>
let currentPage = 1;
let currentHistoryData = [];
let currentEditId = null;
const searchDebounce = debounce(() => applyFilters(), 150);

function handleSearch() { searchDebounce(); }

document.addEventListener('DOMContentLoaded', () => {
    // Pindahkan modal/dropdown ke body agar tidak terkurung oleh stacking context parent
    ['actionDropdownMenu', 'modalConfirmDelete', 'modalActionHistory'].forEach(id => {
        const el = document.getElementById(id);
        if (el) document.body.appendChild(el);
    });

    // Sembunyikan dropdown saat klik di luar
    document.addEventListener('click', () => {
        const dd = document.getElementById('actionDropdownMenu');
        if (dd) dd.style.display = 'none';
    });

    loadHistory();
    loadObatSuggestions();
    if (window.lucide) lucide.createIcons();
});

function applyFilters() {
    currentPage = 1;
    loadHistory();
}

async function loadHistory(page = 1) {
    currentPage = page;
    const search = document.getElementById('searchInput').value;
    const params = buildQueryString({ action: 'get_history', page, search });

    try {
        const data = await fetchAPI(BASE_URL + '/api/inventory.php?' + params);
        renderHistoryTable(data.data);
        renderPagination(data.pagination, 'paginationContainerHistoris');
    } catch (err) {
        document.getElementById('historisBody').innerHTML =
            '<tr><td colspan="10" class="text-center text-muted" style="padding:40px;">Gagal memuat data historis</td></tr>';
    }
}

function renderHistoryTable(items) {
    const tbody = document.getElementById('historisBody');
    currentHistoryData = items || [];
    
    if (!items || items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="11" class="text-center text-muted" style="padding:40px;">Tidak ada data persediaan ditemukan</td></tr>';
        return;
    }

    tbody.innerHTML = items.map(item => {
        const masuk = Number(item.jumlah_masuk);
        const keluar = Number(item.jumlah_keluar);
        
        const masukStyle = masuk > 0 
            ? 'color: #059669; background: rgba(16, 185, 129, 0.1);' 
            : 'color: var(--text-main);';
            
        const keluarStyle = keluar > 0 
            ? 'color: #dc2626; background: rgba(239, 68, 68, 0.1);' 
            : 'color: var(--text-main);';

        return `
        <tr>
            <td class="text-center"><strong>${item.minggu_ke}</strong></td>
            <td>${escapeHtml(item.tanggal)}</td>
            <td>${escapeHtml(item.tanggal_akhir || item.tanggal)}</td>
            <td><span class="table-drug-name">${escapeHtml(item.nama_obat)}</span></td>
            <td>${escapeHtml(item.nama_kategori || '-')}</td>
            <td class="text-right">${Number(item.stok_awal).toLocaleString('id-ID')}</td>
            <td class="text-right fw-600" style="${masukStyle}">+${masuk.toLocaleString('id-ID')}</td>
            <td class="text-right fw-600" style="${keluarStyle}">-${keluar.toLocaleString('id-ID')}</td>
            <td class="text-right">${Number(item.stok_akhir).toLocaleString('id-ID')}</td>
            <td class="text-right cell-muted">${Number(item.rata_rata_keluar).toFixed(2)}</td>
            <td class="text-center" style="white-space: nowrap;">
                <button type="button" style="background:transparent; border:none; cursor:pointer; padding:4px;" onclick="toggleActionDropdown(event, ${item.id})" title="Pilih Aksi">
                    <i data-lucide="pencil" style="width: 18px; height: 18px; color: var(--primary);"></i>
                </button>
            </td>
        </tr>
        `;
    }).join('');
    
    if (window.lucide) lucide.createIcons();
}

function renderPagination(pag, containerId) {
    const container = document.getElementById(containerId);
    if (!pag || pag.total_pages <= 1) { container.innerHTML = ''; return; }

    let html = '<div class="pagination">';
    if (pag.current_page > 1)
        html += `<a href="#" onclick="loadHistory(${pag.current_page - 1});return false;">&laquo;</a>`;
    else
        html += '<span class="disabled">&laquo;</span>';

    const start = Math.max(1, pag.current_page - 2);
    const end = Math.min(pag.total_pages, pag.current_page + 2);

    if (start > 1) { html += `<a href="#" onclick="loadHistory(1);return false;">1</a>`; if (start > 2) html += '<span class="disabled">...</span>'; }
    for (let i = start; i <= end; i++) {
        if (i === pag.current_page) html += `<span class="active">${i}</span>`;
        else html += `<a href="#" onclick="loadHistory(${i});return false;">${i}</a>`;
    }
    if (end < pag.total_pages) { if (end < pag.total_pages - 1) html += '<span class="disabled">...</span>'; html += `<a href="#" onclick="loadHistory(${pag.total_pages});return false;">${pag.total_pages}</a>`; }

    if (pag.current_page < pag.total_pages)
        html += `<a href="#" onclick="loadHistory(${pag.current_page + 1});return false;">&raquo;</a>`;
    else
        html += '<span class="disabled">&raquo;</span>';

    html += '</div>';
    container.innerHTML = html;
}

let currentActionId = null;

function toggleActionDropdown(event, id) {
    event.stopPropagation();
    currentActionId = id;
    const dropdown = document.getElementById('actionDropdownMenu');
    if (!dropdown) return;
    
    // Posisi relatif terhadap tombol yang diklik
    const rect = event.currentTarget.getBoundingClientRect();
    dropdown.style.display = 'block';
    dropdown.style.top = (rect.bottom + window.scrollY + 4) + 'px';
    
    // Pastikan dropdown tidak keluar layar kanan
    let leftPos = rect.right + window.scrollX - 140;
    if (leftPos < 0) leftPos = rect.left + window.scrollX;
    dropdown.style.left = leftPos + 'px';
    
    if (window.lucide) lucide.createIcons();
}

function proceedToEdit() {
    if (!currentActionId) return;
    openActionModal(currentActionId);
}

function confirmDelete() {
    if (!currentActionId) return;
    const el = document.getElementById('modalConfirmDelete');
    el.style.display = 'block';
    if (window.lucide) lucide.createIcons();
}

function closeConfirmDelete() {
    document.getElementById('modalConfirmDelete').style.display = 'none';
}

async function executeDelete() {
    if (!currentActionId) return;
    
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    try {
        const res = await fetchAPI(BASE_URL + '/api/inventory.php', {
            method: 'POST',
            body: JSON.stringify({ action: 'delete_history', id: currentActionId, csrf_token: csrf })
        });
        showToast(res.message, 'success');
        closeConfirmDelete();
        loadHistory(currentPage);
    } catch (err) {
        showToast(err.message || 'Gagal menghapus data', 'error');
    }
}

function openActionModal(id) {
    const item = currentHistoryData.find(i => i.id == id);
    if(!item) return;
    
    document.getElementById('modalId').value = id;
    document.getElementById('modalNamaObat').value = item.nama_obat;
    document.getElementById('modalKategori').value = item.nama_kategori || '';
    document.getElementById('modalMingguKe').value = item.minggu_ke;
    document.getElementById('modalTglMulai').value = item.tanggal;
    document.getElementById('modalTglAkhir').value = item.tanggal_akhir || item.tanggal;
    document.getElementById('modalStokAwal').value = item.stok_awal;
    document.getElementById('modalTotalMasuk').value = item.jumlah_masuk;
    document.getElementById('modalTotalKeluar').value = item.jumlah_keluar;
    document.getElementById('modalStokAkhir').value = item.stok_akhir;
    document.getElementById('modalRataRata').value = item.rata_rata_keluar;
    
    document.getElementById('modalResultMsg').textContent = '';
    document.getElementById('modalActionHistory').style.display = 'block';
    if (window.lucide) lucide.createIcons();
}

function closeActionModal() {
    document.getElementById('modalActionHistory').style.display = 'none';
}

async function submitModalAction(e) {
    e.preventDefault();
    if (!confirm('Apakah data ini benar-benar ingin diubah/edit?')) return;

    const id = document.getElementById('modalId').value;
    const msgEl = document.getElementById('modalResultMsg');
    
    const row = {
        minggu_ke:        parseInt(document.getElementById('modalMingguKe').value) || 1,
        tanggal:          document.getElementById('modalTglMulai').value,
        tanggal_akhir:    document.getElementById('modalTglAkhir').value,
        stok_awal:        parseInt(document.getElementById('modalStokAwal').value) || 0,
        jumlah_masuk:     parseInt(document.getElementById('modalTotalMasuk').value) || 0,
        jumlah_keluar:    parseInt(document.getElementById('modalTotalKeluar').value) || 0,
        stok_akhir:       parseInt(document.getElementById('modalStokAkhir').value) || 0,
        rata_rata_keluar: parseFloat(document.getElementById('modalRataRata').value) || 0,
    };
    
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    try {
        const payload = { action: 'edit_history', id: id, ...row, csrf_token: csrf };
        const res = await fetchAPI(BASE_URL + '/api/inventory.php', {
            method: 'POST',
            body: JSON.stringify(payload)
        });
        
        showToast(res.message, 'success');
        closeActionModal();
        loadHistory(currentPage);
    } catch (err) {
        msgEl.textContent = `❌ ${err.message}`;
        msgEl.style.color = '#EF4444';
        showToast(err.message || 'Gagal memperbarui data', 'error');
    }
}

async function deleteAllHistory() {
    if (!confirm('PERINGATAN: Apakah Anda yakin ingin menghapus SEMUA data histori? Tindakan ini tidak dapat dibatalkan.')) return;
    
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    try {
        const res = await fetchAPI(BASE_URL + '/api/inventory.php', {
            method: 'POST',
            body: JSON.stringify({ action: 'delete_all_history', csrf_token: csrf })
        });
        showToast(res.message, 'success');
        loadHistory(1);
    } catch (err) {
        showToast(err.message || 'Gagal menghapus semua data', 'error');
    }
}

// ==========================================
// IMPORT LOGIC
// ==========================================
function toggleImportPanel() {
    const p = document.getElementById('importPanel');
    p.style.display = p.style.display === 'none' ? 'block' : 'none';
    if(p.style.display === 'block') p.scrollIntoView({behavior:'smooth'});
}

function handleDragOver(e) {
    e.preventDefault();
    e.currentTarget.style.borderColor = 'var(--primary)';
    e.currentTarget.style.background = 'rgba(16, 185, 129, 0.05)';
}

function handleDragLeave(e) {
    e.preventDefault();
    e.currentTarget.style.borderColor = '#d1d5db';
    e.currentTarget.style.background = 'transparent';
}

function handleDrop(e) {
    e.preventDefault();
    handleDragLeave(e);
    if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
        processFile(e.dataTransfer.files[0]);
    }
}

function handleFileSelect(input) {
    if (input.files && input.files.length > 0) {
        processFile(input.files[0]);
    }
}

function processFile(file) {
    const ext = file.name.split('.').pop().toLowerCase();
    
    document.getElementById('importDropContent').style.display = 'none';
    document.getElementById('importProcessing').style.display = 'block';
    document.getElementById('importResult').style.display = 'none';
    
    if (ext === 'csv') {
        parseCSV(file);
    } else if (ext === 'xlsx' || ext === 'xls') {
        showImportError('Untuk file Excel, silakan simpan sebagai CSV (Comma delimited) terlebih dahulu.');
    } else {
        showImportError('Format file tidak didukung. Harap gunakan CSV.');
    }
}

function parseCSV(file) {
    // Gunakan PapaParse untuk parsing CSV yang benar (menangani koma dalam quotes, encoding, dll)
    Papa.parse(file, {
        header: true,
        skipEmptyLines: true,
        dynamicTyping: false,
        complete: function(results) {
            if (!results.data || results.data.length === 0) {
                showImportError('File kosong atau hanya berisi header');
                return;
            }

            // Normalisasi nama kolom header ke lowercase untuk matching
            const rawData = results.data;
            const rows = rawData.map(rawRow => {
                // Buat objek dengan key lowercase
                const row = {};
                for (const key in rawRow) {
                    row[key.trim().toLowerCase()] = (rawRow[key] || '').toString().trim();
                }
                return mapRowToHistoris(row);
            }).filter(r => r.nama_obat !== '');

            if (rows.length === 0) {
                showImportError('Tidak ada data valid ditemukan. Pastikan kolom "Nama Obat" terisi dan nama obat cocok dengan database.');
                return;
            }

            importRowsHistoris(rows);
        },
        error: function(err) {
            showImportError('Gagal membaca file CSV: ' + err.message);
        }
    });
}

/**
 * Konversi format tanggal ke YYYY-MM-DD yang diterima MySQL.
 * Menangani format M/D/YYYY atau MM/DD/YYYY (export default Excel).
 */
function formatDateToMySQL(dateStr) {
    if (!dateStr || dateStr === '') return '';

    // Sudah dalam format YYYY-MM-DD
    if (/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) return dateStr;

    // Format M/D/YYYY atau MM/DD/YYYY (default Excel CSV)
    const slashParts = dateStr.split('/');
    if (slashParts.length === 3) {
        const month = slashParts[0].padStart(2, '0');
        const day   = slashParts[1].padStart(2, '0');
        const year  = slashParts[2].length === 2 ? '20' + slashParts[2] : slashParts[2];
        return `${year}-${month}-${day}`;
    }

    // Format D-M-YYYY (format Indonesia dengan tanda hubung)
    const dashParts = dateStr.split('-');
    if (dashParts.length === 3 && dashParts[0].length <= 2) {
        const day   = dashParts[0].padStart(2, '0');
        const month = dashParts[1].padStart(2, '0');
        const year  = dashParts[2];
        return `${year}-${month}-${day}`;
    }

    return dateStr; // kembalikan apa adanya jika format tidak dikenal
}

function mapRowToHistoris(row) {
    // Helper: ambil nilai dari beberapa kemungkinan nama kolom
    const get = (...names) => {
        for (const n of names) {
            const val = row[n.toLowerCase()];
            if (val !== undefined && val !== '') return val;
        }
        return '';
    };

    const mingguKe    = parseInt(get('minggu ke', 'minggu_ke', 'minggu')) || 0;
    // Konversi tanggal dari format Excel (M/D/YYYY) ke MySQL (YYYY-MM-DD)
    const tanggalMulai = formatDateToMySQL(get('tanggal mulai', 'tanggal_mulai', 'tanggal mulai'));
    const tanggalAkhir = formatDateToMySQL(get('tanggal akhir', 'tanggal_akhir'));
    const namaObat    = get('nama obat', 'nama_obat', 'obat');
    const stokAwal    = parseInt(get('stok awal minggu', 'stok_awal_minggu', 'stok awal', 'stok_awal', 'awal')) || 0;
    const masuk       = parseInt(get('total masuk', 'total_masuk', 'jumlah_masuk', 'masuk')) || 0;
    const keluar      = parseInt(get('total keluar', 'total_keluar', 'jumlah_keluar', 'keluar')) || 0;
    const stokAkhir   = parseInt(get('stok akhir minggu', 'stok_akhir_minggu', 'stok akhir', 'stok_akhir', 'akhir')) || 0;
    const rataRata    = parseFloat(get('rata-rata keluar/hari', 'rata_rata_keluar/hari', 'rata_rata_keluar_hari', 'rata_rata_keluar', 'rata-rata', 'rata_rata')) || 0;

    return {
        minggu_ke:        mingguKe,
        nama_obat:        namaObat,
        tanggal:          tanggalMulai,   // backend memakai field 'tanggal'
        tanggal_mulai:    tanggalMulai,
        tanggal_akhir:    tanggalAkhir,
        stok_awal:        stokAwal,
        jumlah_masuk:     masuk,
        jumlah_keluar:    keluar,
        stok_akhir:       stokAkhir,
        rata_rata_keluar: rataRata,
    };
}

async function importRowsHistoris(rows) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;

    try {
        const res = await fetchAPI(BASE_URL + '/api/inventory.php', {
            method: 'POST',
            body: JSON.stringify({ 
                action: 'upload_history', 
                rows: rows,
                csrf_token: csrf 
            })
        });
        
        document.getElementById('importProcessing').style.display = 'none';
        document.getElementById('importDropContent').style.display = 'block';
        document.getElementById('csvFileInput').value = '';

        const resultEl = document.getElementById('importResult');
        const resultText = document.getElementById('importResultText');
        resultEl.style.display = 'block';
        resultText.innerHTML = `✅ ${res.message}`;
        resultText.style.color = 'var(--primary)';

        showToast(res.message, 'success');
        
        // Refresh table
        loadHistory(1);
    } catch (e) {
        document.getElementById('importProcessing').style.display = 'none';
        document.getElementById('importDropContent').style.display = 'block';
        document.getElementById('csvFileInput').value = '';
        
        const resultEl = document.getElementById('importResult');
        const resultText = document.getElementById('importResultText');
        resultEl.style.display = 'block';
        resultText.innerHTML = `❌ Gagal memproses data: ${e.message}`;
        resultText.style.color = '#EF4444';
        
        showToast(e.message || 'Gagal menyimpan data', 'error');
    }
}

function showImportError(msg) {
    document.getElementById('importProcessing').style.display = 'none';
    document.getElementById('importDropContent').style.display = 'block';
    document.getElementById('csvFileInput').value = '';
    showToast(msg, 'error');
}

function downloadTemplate() {
    const csv = 'Minggu Ke,Tanggal Mulai,Tanggal Akhir,Nama Obat,Kategori,Stok Awal Minggu,Total Masuk,Total Keluar,Stok Akhir Minggu,Rata-rata Keluar/Hari\n1,2025-04-28,2025-05-04,AMLODIPIN,Antihipertensi,4420,0,230,4190,32.86\n1,2025-04-28,2025-05-04,CANDESARTAN,Antihipertensi,361,0,0,361,0';
    const filename = 'template_historis_persediaan.csv';
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
}

// ==========================================
// MANUAL INPUT
// ==========================================
// Load drug-name suggestions for autocomplete
let obatSuggestionsLoaded = false;
async function loadObatSuggestions() {
    if (obatSuggestionsLoaded) return;
    try {
        const data = await fetchAPI(BASE_URL + '/api/inventory.php?action=list&page=1');
        const datalist = document.getElementById('obatListSuggestion');
        if (data.data && datalist) {
            datalist.innerHTML = data.data.map(o => `<option value="${escapeHtml(o.nama_obat)}">`).join('');
            obatSuggestionsLoaded = true;
        }
    } catch (e) { /* silent */ }
}

async function submitManualInput(e) {
    e.preventDefault();

    const btn = document.getElementById('btnSubmitManual');
    const msgEl = document.getElementById('manualResultMsg');
    btn.disabled = true;
    msgEl.textContent = '';

    const row = {
        minggu_ke:        parseInt(document.getElementById('manMingguKe').value) || 1,
        nama_obat:        document.getElementById('manNamaObat').value.trim(),
        tanggal:          document.getElementById('manTglMulai').value,
        tanggal_mulai:    document.getElementById('manTglMulai').value,
        tanggal_akhir:    document.getElementById('manTglAkhir').value,
        stok_awal:        parseInt(document.getElementById('manStokAwal').value) || 0,
        jumlah_masuk:     parseInt(document.getElementById('manTotalMasuk').value) || 0,
        jumlah_keluar:    parseInt(document.getElementById('manTotalKeluar').value) || 0,
        stok_akhir:       parseInt(document.getElementById('manStokAkhir').value) || 0,
        rata_rata_keluar: parseFloat(document.getElementById('manRataRata').value) || 0,
    };

    if (!row.nama_obat) {
        msgEl.textContent = '❌ Nama Obat wajib diisi';
        msgEl.style.color = '#EF4444';
        btn.disabled = false;
        return;
    }

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;

    try {
        const res = await fetchAPI(BASE_URL + '/api/inventory.php', {
            method: 'POST',
            body: JSON.stringify({
                action: 'upload_history',
                rows: [row],
                csrf_token: csrf
            })
        });

        msgEl.textContent = `✅ ${res.message}`;
        msgEl.style.color = 'var(--primary)';
        showToast(res.message, 'success');
        resetManualForm();
        loadHistory(1);
    } catch (err) {
        msgEl.textContent = `❌ ${err.message}`;
        msgEl.style.color = '#EF4444';
        showToast(err.message || 'Gagal menyimpan data', 'error');
    } finally {
        btn.disabled = false;
    }
}

function resetManualForm() {
    document.getElementById('manualInputForm').reset();
    document.getElementById('manualResultMsg').textContent = '';
}
</script>
    <!-- Modals -->
    <style>
    @keyframes invPopIn {
        0% { transform: translate(-50%, -50%) scale(0.9); opacity: 0; }
        100% { transform: translate(-50%, -50%) scale(1); opacity: 1; }
    }
    .inv-overlay {
        display: none;
        position: fixed;
        z-index: 99999;
        left: 0; top: 0;
        width: 100vw; height: 100vh;
        background-color: rgba(0,0,0,0.5);
    }
    .inv-popup {
        position: fixed;
        top: 50%; left: 50%;
        transform: translate(-50%, -50%);
        background-color: var(--card-bg, #fff);
        padding: 24px;
        border-radius: 12px;
        border: none !important;
        outline: none !important;
        box-shadow: none !important;
        animation: invPopIn 0.25s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards;
    }
    </style>

    <!-- Dropdown Menu Aksi -->
    <div id="actionDropdownMenu" style="display:none; position:absolute; background:var(--card-bg); border:1px solid var(--border-color); border-radius:8px; z-index:99990; min-width:140px; overflow:hidden;">
        <button type="button" onclick="proceedToEdit()" style="display:flex; align-items:center; gap:8px; width:100%; text-align:left; padding:10px 16px; border:none; background:transparent; cursor:pointer; color:var(--text-main); font-size:0.875rem; border-bottom:1px solid var(--border-color);">
            <i data-lucide="edit" style="width:14px;height:14px;color:var(--primary);"></i> Edit Manual
        </button>
        <button type="button" onclick="confirmDelete()" style="display:flex; align-items:center; gap:8px; width:100%; text-align:left; padding:10px 16px; border:none; background:transparent; cursor:pointer; color:#EF4444; font-size:0.875rem;">
            <i data-lucide="trash-2" style="width:14px;height:14px;"></i> Hapus Data
        </button>
    </div>

    <!-- Modal Konfirmasi Hapus -->
    <div class="inv-overlay" id="modalConfirmDelete">
        <div class="inv-popup" style="width:90%; max-width:400px; text-align:center;">
            <div style="width:48px; height:48px; border-radius:50%; background-color:rgba(239, 68, 68, 0.1); display:flex; justify-content:center; align-items:center; margin:0 auto 16px;">
                <i data-lucide="alert-triangle" style="width:24px; height:24px; color:#EF4444;"></i>
            </div>
            <h4 style="margin-bottom:12px; font-weight:600; color:var(--text-main);">Konfirmasi Hapus</h4>
            <p style="margin-bottom:24px; color:var(--text-muted); font-size:0.9rem;">Apakah Anda yakin ingin menghapus data persediaan ini? Tindakan ini tidak dapat dibatalkan.</p>
            <div style="display:flex; justify-content:center; gap:12px;">
                <button class="btn btn-secondary" style="flex:1;" onclick="closeConfirmDelete()">Batal</button>
                <button class="btn btn-danger" style="flex:1;" onclick="executeDelete()">Hapus Data</button>
            </div>
        </div>
    </div>

    <!-- Modal Edit History -->
    <div class="inv-overlay" id="modalActionHistory">
        <div class="inv-popup" style="width:90%; max-width:640px;">
            <div class="flex-between" style="border-bottom:1px solid var(--border-color); padding-bottom:16px; margin-bottom:20px;">
                <h3 style="margin:0; display:flex; align-items:center; gap:8px;"><i data-lucide="edit" class="icon-inline"></i> Edit Data Histori</h3>
                <button onclick="closeActionModal()" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:var(--text-muted);">&times;</button>
            </div>
            
            <form id="modalActionForm" onsubmit="submitModalAction(event)">
                <input type="hidden" id="modalId">
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom:24px;">
                    <div style="grid-column: span 2;">
                        <label class="form-label text-xs fw-600">Nama Obat</label>
                        <input type="text" class="form-control" id="modalNamaObat" readonly style="background:rgba(0,0,0,0.02);">
                    </div>
                    <div>
                        <label class="form-label text-xs fw-600">Kategori</label>
                        <input type="text" class="form-control" id="modalKategori" readonly style="background:rgba(0,0,0,0.02);">
                    </div>
                    <div>
                        <label class="form-label text-xs fw-600">Minggu Ke</label>
                        <input type="number" class="form-control" id="modalMingguKe" min="1" required>
                    </div>
                    <div>
                        <label class="form-label text-xs fw-600">Tanggal Mulai</label>
                        <input type="date" class="form-control" id="modalTglMulai" required>
                    </div>
                    <div>
                        <label class="form-label text-xs fw-600">Tanggal Akhir</label>
                        <input type="date" class="form-control" id="modalTglAkhir" required>
                    </div>
                    <div>
                        <label class="form-label text-xs fw-600">Stok Awal</label>
                        <input type="number" class="form-control" id="modalStokAwal" min="0" required>
                    </div>
                    <div>
                        <label class="form-label text-xs fw-600">Total Masuk</label>
                        <input type="number" class="form-control" id="modalTotalMasuk" min="0" required>
                    </div>
                    <div>
                        <label class="form-label text-xs fw-600">Total Keluar</label>
                        <input type="number" class="form-control" id="modalTotalKeluar" min="0" required>
                    </div>
                    <div>
                        <label class="form-label text-xs fw-600">Stok Akhir</label>
                        <input type="number" class="form-control" id="modalStokAkhir" min="0" required>
                    </div>
                    <div style="grid-column: span 2;">
                        <label class="form-label text-xs fw-600">Rata-rata Keluar/Hari</label>
                        <input type="number" class="form-control" id="modalRataRata" min="0" step="0.01" required>
                    </div>
                </div>
                
                <div style="display:flex; justify-content:flex-end; align-items:center;">
                    <div style="display:flex; gap:12px;">
                        <button type="button" class="btn btn-secondary" onclick="closeActionModal()">Batal</button>
                        <button type="submit" class="btn btn-primary" style="display:flex; align-items:center; gap:6px;">
                            <i data-lucide="save" style="width:16px;height:16px;"></i> Simpan Perubahan
                        </button>
                    </div>
                </div>
                <div id="modalResultMsg" class="text-xs fw-600" style="margin-top: 12px; text-align: center;"></div>
            </form>
        </div>
    </div>
</body>
</html>
