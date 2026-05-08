<?php
/**
 * Helper Functions
 * Apotek Zam Zam - Pharmacy Drug Inventory Prediction System
 */

/**
 * Escape output for XSS prevention
 */
function e(string $string): string {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * Format number as Indonesian Rupiah
 */
function formatRupiah($number): string {
    return 'Rp ' . number_format((float)$number, 0, ',', '.');
}

/**
 * Format date to Indonesian locale
 */
function formatDate(string $date, string $format = 'd M Y'): string {
    $months = [
        'Jan' => 'Jan', 'Feb' => 'Feb', 'Mar' => 'Mar',
        'Apr' => 'Apr', 'May' => 'Mei', 'Jun' => 'Jun',
        'Jul' => 'Jul', 'Aug' => 'Agt', 'Sep' => 'Sep',
        'Oct' => 'Okt', 'Nov' => 'Nov', 'Dec' => 'Des'
    ];
    $formatted = date($format, strtotime($date));
    return str_replace(array_keys($months), array_values($months), $formatted);
}

/**
 * Format datetime to relative time string
 */
function timeAgo(string $datetime): string {
    $now = new DateTime();
    $past = new DateTime($datetime);
    $diff = $now->diff($past);

    if ($diff->y > 0) return $diff->y . ' tahun lalu';
    if ($diff->m > 0) return $diff->m . ' bulan lalu';
    if ($diff->d > 0) return $diff->d . ' hari lalu';
    if ($diff->h > 0) return $diff->h . ' jam lalu';
    if ($diff->i > 0) return $diff->i . ' menit lalu';
    return 'Baru saja';
}

/**
 * Get stock status badge based on current vs minimum stock
 */
function getStatusBadge(int $stok, int $minimum): array {
    if ($stok <= 0) return ['label' => 'Habis', 'class' => 'badge-kritis'];
    if ($stok <= $minimum) return ['label' => 'Kritis', 'class' => 'badge-kritis'];
    if ($stok <= $minimum * 1.5) return ['label' => 'Waspada', 'class' => 'badge-waspada'];
    return ['label' => 'Aman', 'class' => 'badge-aman'];
}

/**
 * Get progress bar color class based on percentage
 */
function getProgressColor(float $percentage): string {
    if ($percentage <= 30) return 'fill-red';
    if ($percentage <= 70) return 'fill-amber';
    return 'fill-green';
}

/**
 * Calculate pagination data
 */
function paginate(int $total, int $perPage, int $currentPage): array {
    $totalPages = max(1, ceil($total / $perPage));
    $currentPage = max(1, min($currentPage, $totalPages));
    $offset = ($currentPage - 1) * $perPage;
    return [
        'total'        => $total,
        'per_page'     => $perPage,
        'current_page' => $currentPage,
        'total_pages'  => $totalPages,
        'offset'       => $offset,
    ];
}

/**
 * Render pagination HTML
 */
function renderPagination(array $pagination, string $baseUrl): string {
    if ($pagination['total_pages'] <= 1) return '';

    $html = '<div class="pagination">';
    $page = $pagination['current_page'];
    $total = $pagination['total_pages'];
    $separator = strpos($baseUrl, '?') !== false ? '&' : '?';

    // Previous
    if ($page > 1) {
        $html .= '<a href="' . $baseUrl . $separator . 'page=' . ($page - 1) . '">&laquo;</a>';
    } else {
        $html .= '<span class="disabled">&laquo;</span>';
    }

    // Page numbers
    $start = max(1, $page - 2);
    $end = min($total, $page + 2);

    if ($start > 1) {
        $html .= '<a href="' . $baseUrl . $separator . 'page=1">1</a>';
        if ($start > 2) $html .= '<span class="disabled">...</span>';
    }

    for ($i = $start; $i <= $end; $i++) {
        if ($i == $page) {
            $html .= '<span class="active">' . $i . '</span>';
        } else {
            $html .= '<a href="' . $baseUrl . $separator . 'page=' . $i . '">' . $i . '</a>';
        }
    }

    if ($end < $total) {
        if ($end < $total - 1) $html .= '<span class="disabled">...</span>';
        $html .= '<a href="' . $baseUrl . $separator . 'page=' . $total . '">' . $total . '</a>';
    }

    // Next
    if ($page < $total) {
        $html .= '<a href="' . $baseUrl . $separator . 'page=' . ($page + 1) . '">&raquo;</a>';
    } else {
        $html .= '<span class="disabled">&raquo;</span>';
    }

    $html .= '</div>';
    return $html;
}

/**
 * Send JSON response and exit
 */
function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Generate unique drug code
 */
function generateKodeObat(): string {
    return 'OBT-' . strtoupper(substr(uniqid(), -6));
}

/**
 * Generate CSRF token
 */
function generateCSRFToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token
 */
function validateCSRFToken(?string $token): bool {
    if (empty($token) || empty($_SESSION['csrf_token'])) return false;
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Get setting value from database
 */
function getSetting(PDO $db, string $key, string $default = ''): string {
    $stmt = $db->prepare("SELECT value FROM pengaturan WHERE `key` = ?");
    $stmt->execute([$key]);
    $result = $stmt->fetch();
    return $result ? $result['value'] : $default;
}

/**
 * Set setting value in database
 */
function setSetting(PDO $db, string $key, string $value): void {
    $stmt = $db->prepare("INSERT INTO pengaturan (`key`, value, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()");
    $stmt->execute([$key, $value]);
}

/**
 * Sanitize and validate integer input
 */
function inputInt(string $key, int $default = 0): int {
    return isset($_GET[$key]) ? (int)$_GET[$key] : (isset($_POST[$key]) ? (int)$_POST[$key] : $default);
}

/**
 * Sanitize string input
 */
function inputString(string $key, string $default = ''): string {
    $value = $_GET[$key] ?? $_POST[$key] ?? $default;
    return trim($value);
}
