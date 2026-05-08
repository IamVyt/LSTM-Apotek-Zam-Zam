<?php
/**
 * Header Template - HTML Head with CSS/JS links
 * Apotek Zam Zam - Pharmacy Drug Inventory Prediction System
 */
require_once __DIR__ . '/functions.php';
$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($pageTitle ?? 'Dashboard'); ?> — <?php echo APP_NAME; ?></title>
    <meta name="description" content="<?php echo APP_DESCRIPTION; ?>">
    <meta name="csrf-token" content="<?php echo e($csrfToken); ?>">

    <!-- Google Fonts: Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>

    <!-- Application Styles -->
    <?php
    $cssBase = __DIR__ . '/../assets/css';
    $vMain   = file_exists("$cssBase/main.css")     ? filemtime("$cssBase/main.css")     : time();
    $vComp   = file_exists("$cssBase/components.css") ? filemtime("$cssBase/components.css") : time();
    $vRed    = file_exists("$cssBase/redesign.css") ? filemtime("$cssBase/redesign.css") : time();
    ?>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/main.css?v=<?= $vMain ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/components.css?v=<?= $vComp ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/redesign.css?v=<?= $vRed ?>">

    <script>const BASE_URL = '<?php echo BASE_URL; ?>';</script>
</head>
<body>
<div class="app-wrapper">
<!-- Mobile menu toggle button -->
<button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Toggle menu">
    <i data-lucide="menu"></i>
</button>
