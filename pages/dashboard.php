<?php
/**
 * Dashboard Page
 * Apotek Zam Zam (LSTM Focused)
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();
$db = getDB();
$pageTitle = 'Dashboard';

// ── Stat Queries ──
// Total Data Historis
$stmtTotal = $db->query("SELECT COUNT(*) FROM data_historis");
$totalData = (int) $stmtTotal->fetchColumn();

// Total Prediksi Dilakukan
$stmtPred = $db->query("SELECT COUNT(*) FROM prediksi_lstm");
$totalPrediksi = (int) $stmtPred->fetchColumn();

// Prediksi akurasi rata-rata
$stmtAkurasi = $db->query("SELECT COALESCE(AVG(akurasi), 0) FROM prediksi_lstm WHERE akurasi > 0");
$avgAkurasi = round((float) $stmtAkurasi->fetchColumn(), 1);

// Chart data: 8 minggu terakhir DARI DATA TERSEDIA (bukan dari hari ini)
// Lebih robust: kalau data lampau (tahun lalu), chart tetap tampil
$stmtChart = $db->query("SELECT tanggal AS tgl, SUM(jumlah_keluar) AS total
                          FROM data_historis
                          GROUP BY tanggal
                          ORDER BY tgl DESC
                          LIMIT 8");
$chartData = array_reverse($stmtChart->fetchAll());  // urutkan kronologis (lama → baru)
$chartLabels = array_map(fn($r) => 'Mg ' . date('W', strtotime($r['tgl'])), $chartData);
$chartValues = array_map(fn($r) => (int) $r['total'], $chartData);

// Tabel Data Historis Terbaru
$stmtHistory = $db->query("SELECT h.*, o.nama_obat, o.kode_obat, k.nama_kategori 
                           FROM data_historis h 
                           JOIN obat o ON h.obat_id = o.id 
                           LEFT JOIN kategori_obat k ON o.kategori = k.id
                           ORDER BY h.tanggal DESC LIMIT 10");
$recentHistory = $stmtHistory->fetchAll();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<main class="main-content">
    <div class="page-header">
        <div>
            <h1>Ringkasan Dashboard</h1>
            <p class="subtitle">Ikhtisar data persediaan obat dan performa sistem prediksi LSTM.</p>
        </div>
        <div class="page-actions">
            <span class="date-label"><?php echo date('l, d F Y'); ?></span>
        </div>
    </div>

    <!-- Stat Cards -->
    <div class="grid grid-3 stagger mb-3">
        <div class="stat-card">
            <div class="stat-card-header">
                <span class="stat-card-label">Total Data Persediaan</span>
                <div class="stat-card-icon icon-blue">
                    <i data-lucide="database"></i>
                </div>
            </div>
            <div class="stat-card-value"><?php echo number_format($totalData); ?></div>
            <div class="stat-card-change positive">
                <i data-lucide="activity" class="icon-14"></i> Baris historis
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-card-header">
                <span class="stat-card-label">Total Hasil Prediksi</span>
                <div class="stat-card-icon icon-purple">
                    <i data-lucide="line-chart"></i>
                </div>
            </div>
            <div class="stat-card-value"><?php echo number_format($totalPrediksi); ?></div>
            <div class="stat-card-change positive">
                <i data-lucide="zap" class="icon-14"></i> Prediksi berhasil
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-card-header">
                <span class="stat-card-label">Rata-rata Akurasi Model</span>
                <div class="stat-card-icon icon-green">
                    <i data-lucide="brain-circuit"></i>
                </div>
            </div>
            <div class="stat-card-value"><?php echo $avgAkurasi; ?>%</div>
            <div class="stat-card-change positive">
                <i data-lucide="sparkles" class="icon-14"></i> LSTM Optimization
            </div>
        </div>
    </div>

    <!-- Charts & Action Row -->
    <div class="grid grid-2-1 mb-3">
        <!-- Consumption Chart -->
        <div class="card fade-in">
            <div class="card-header">
                <h3>📊 Tren Persediaan Obat</h3>
            </div>
            <div class="chart-container" style="height:260px;">
                <?php if (empty($chartData)): ?>
                    <div class="empty-state">
                        <p>Belum ada data tersedia.</p>
                    </div>
                <?php else: ?>
                    <canvas id="dashboardChart"></canvas>
                <?php endif; ?>
            </div>
        </div>

        <!-- Fast Action Panel -->
        <div class="card fade-in">
            <div class="card-header">
                <h3>⚡ Aksi Cepat</h3>
            </div>
            <div class="text-center" style="padding:20px 16px;">
                <div class="text-sm text-muted mb-1">Akurasi Sistem</div>
                <?php if ($avgAkurasi > 0): ?>
                    <div class="fw-800" style="font-size:2.5rem;color:var(--primary);line-height:1;margin-bottom:16px;">
                        <?php echo $avgAkurasi; ?>%
                    </div>
                    <p class="text-sm text-muted" style="line-height:1.6;margin-bottom:24px;">
                        Rata-rata akurasi dari <?php echo number_format($totalPrediksi); ?> prediksi yang telah dijalankan.
                    </p>
                <?php else: ?>
                    <div class="fw-800" style="font-size:1.8rem;color:#94a3b8;line-height:1.2;margin-bottom:16px;">
                        Belum Ada Data
                    </div>
                    <p class="text-sm text-muted" style="line-height:1.6;margin-bottom:24px;">
                        Jalankan prediksi untuk melihat statistik akurasi sistem.
                    </p>
                <?php endif; ?>
                <a href="<?php echo BASE_URL; ?>/pages/predictions.php" class="btn btn-primary w-full">
                    <i data-lucide="play-circle"></i> Mulai Prediksi Baru
                </a>
            </div>
        </div>
    </div>

    <!-- History Table -->
    <div class="card fade-in mb-3">
        <div class="card-header">
            <h3>📑 Tabel Data Historis</h3>
            <a href="<?php echo BASE_URL; ?>/pages/inventory.php" class="btn btn-sm btn-secondary">
                Kelola Data <i data-lucide="arrow-right" class="icon-14"></i>
            </a>
        </div>
        <div class="table-container">
            <table class="data-table">
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
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentHistory as $item): ?>
                        <tr>
                            <td class="text-center"><strong><?php echo e($item['minggu_ke']); ?></strong></td>
                            <td><?php echo e($item['tanggal']); ?></td>
                            <td><?php echo e($item['tanggal_akhir'] ?? $item['tanggal']); ?></td>
                            <td><span class="table-drug-name"><?php echo e($item['nama_obat']); ?></span></td>
                            <td><?php echo e($item['nama_kategori'] ?? '-'); ?></td>
                            <td class="text-right"><?php echo number_format((int)$item['stok_awal']); ?></td>
                            <?php 
                                $masuk = (int)$item['jumlah_masuk'];
                                $keluar = (int)$item['jumlah_keluar'];
                                $masukStyle = $masuk > 0 ? 'color: #059669; background: rgba(16, 185, 129, 0.1);' : 'color: var(--text-main);';
                                $keluarStyle = $keluar > 0 ? 'color: #dc2626; background: rgba(239, 68, 68, 0.1);' : 'color: var(--text-main);';
                            ?>
                            <td class="text-right fw-600" style="<?php echo $masukStyle; ?>">+<?php echo number_format($masuk); ?></td>
                            <td class="text-right fw-600" style="<?php echo $keluarStyle; ?>">-<?php echo number_format($keluar); ?></td>
                            <td class="text-right fw-600"><strong><?php echo number_format((int)$item['stok_akhir']); ?></strong></td>
                            <td class="text-right text-muted"><?php echo number_format((float)$item['rata_rata_keluar'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($recentHistory)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted" style="padding:40px;">Belum ada data historis</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>

</div><!-- end app-wrapper -->

<script src="<?php echo BASE_URL; ?>/assets/js/main.js"></script>
<?php if (!empty($chartData)): ?>
<script src="<?php echo BASE_URL; ?>/assets/js/charts.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (window.lucide) lucide.createIcons();
    
    // Setup dashboard chart matching the layout
    const ctx = document.getElementById('dashboardChart');
    if (ctx) {
        const canvasCtx = ctx.getContext('2d');
        const gradDash = canvasCtx.createLinearGradient(0, 0, 0, 360);
        gradDash.addColorStop(0, 'rgba(59, 130, 246, 0.4)'); // Blue 500
        gradDash.addColorStop(1, 'rgba(59, 130, 246, 0.0)');

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chartLabels); ?>,
                datasets: [{
                    label: 'Tren Persediaan (Keluar)',
                    data: <?php echo json_encode($chartValues); ?>,
                    borderColor: '#3b82f6',
                    backgroundColor: gradDash,
                    borderWidth: 3,
                    pointBackgroundColor: '#3b82f6',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 0,
                    pointHoverRadius: 6,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(0,0,0,0.04)', borderDash: [] },
                        border: { display: false }
                    },
                    x: {
                        grid: { display: false },
                        border: { display: false }
                    }
                }
            }
        });
    }
});
</script>
<?php endif; ?>
</body>
</html>