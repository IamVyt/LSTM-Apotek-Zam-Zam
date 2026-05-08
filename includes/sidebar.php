<?php
/**
 * Sidebar Navigation Component
 * Apotek Zam Zam - Pharmacy Drug Inventory Prediction System
 */
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$user = getCurrentUser();
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">
            <div class="logo-icon" style="background:transparent; box-shadow:none; padding:0; width:36px; height:36px; display:flex; align-items:center; justify-content:center;">
                <img src="<?php echo BASE_URL; ?>/assets/img/logo-white.png" alt="Logo" style="width:26px; height:26px; object-fit:contain; border-radius:4px;">
            </div>
            <div class="logo-text">
                <span class="logo-title">Apotek Zam Zam</span>
                <span class="logo-subtitle">Prediksi LSTM</span>
            </div>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section">
            <span class="nav-section-title">MENU UTAMA</span>
            <a href="<?php echo BASE_URL; ?>/pages/dashboard.php" class="nav-link <?php echo $currentPage === 'dashboard' ? 'active' : ''; ?>">
                <i data-lucide="layout-dashboard"></i>
                <span>Dashboard</span>
            </a>
            <a href="<?php echo BASE_URL; ?>/pages/inventory.php" class="nav-link <?php echo $currentPage === 'inventory' ? 'active' : ''; ?>">
                <i data-lucide="database"></i>
                <span>Manajemen Data</span>
            </a>
            <a href="<?php echo BASE_URL; ?>/pages/predictions.php" class="nav-link <?php echo $currentPage === 'predictions' ? 'active' : ''; ?>">
                <i data-lucide="brain-circuit"></i>
                <span>Prediksi LSTM</span>
                <span class="nav-badge-ai">AI</span>
            </a>
            <a href="<?php echo BASE_URL; ?>/pages/reports.php" class="nav-link <?php echo $currentPage === 'reports' ? 'active' : ''; ?>">
                <i data-lucide="bar-chart-3"></i>
                <span>Laporan</span>
            </a>
        </div>

        <div class="nav-section">
            <span class="nav-section-title">PENGATURAN</span>
            <a href="<?php echo BASE_URL; ?>/pages/settings.php" class="nav-link <?php echo $currentPage === 'settings' ? 'active' : ''; ?>">
                <i data-lucide="settings"></i>
                <span>Pengaturan</span>
            </a>
        </div>
    </nav>

    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="user-avatar">
                <?php echo strtoupper(substr($user['nama'] ?? 'U', 0, 1)); ?>
            </div>
            <div class="user-info">
                <span class="user-name"><?php echo e($user['nama'] ?? 'User'); ?></span>
                <span class="user-role"><?php echo e(ucfirst($user['role'] ?? 'user')); ?></span>
            </div>
            <a href="<?php echo BASE_URL; ?>/logout.php" class="logout-btn" title="Logout">
                <i data-lucide="log-out"></i>
            </a>
        </div>
    </div>
</aside>
