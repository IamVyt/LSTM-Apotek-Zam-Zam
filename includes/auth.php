<?php
/**
 * Authentication & Session Management
 * Apotek Zam Zam - Pharmacy Drug Inventory Prediction System
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';

/**
 * Require user to be logged in, redirect to login if not
 */
function requireLogin(): void {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

/**
 * Check if user has one of the allowed roles
 */
function checkRole(array $allowedRoles): void {
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowedRoles)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><title>403 - Akses Ditolak</title></head>';
        echo '<body style="font-family:sans-serif;text-align:center;padding:60px;">';
        echo '<h1 style="color:#991B1B;">403 — Akses Ditolak</h1>';
        echo '<p>Anda tidak memiliki izin untuk mengakses halaman ini.</p>';
        echo '<a href="' . BASE_URL . '/pages/dashboard.php" style="color:#1D9E75;">Kembali ke Dashboard</a>';
        echo '</body></html>';
        exit;
    }
}

/**
 * Check if user is currently logged in
 */
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

/**
 * Get current user session data
 */
function getCurrentUser(): array {
    return [
        'id'       => $_SESSION['user_id'] ?? null,
        'username' => $_SESSION['username'] ?? null,
        'nama'     => $_SESSION['nama'] ?? null,
        'role'     => $_SESSION['role'] ?? null,
        'email'    => $_SESSION['email'] ?? null,
    ];
}

/**
 * Login user - set session variables
 */
function loginUser(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user_id']  = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['nama']     = $user['full_name'];
    $_SESSION['role']     = $user['role'];
    $_SESSION['email']    = $user['email'];
    $_SESSION['login_time'] = time();
}

/**
 * Logout user - destroy session
 */
function logoutUser(): void {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

/**
 * Validate API authentication (for AJAX requests)
 */
function requireAPIAuth(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
}

/**
 * Check API role
 */
function requireAPIRole(array $roles): void {
    requireAPIAuth();
    if (!in_array($_SESSION['role'], $roles)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }
}
