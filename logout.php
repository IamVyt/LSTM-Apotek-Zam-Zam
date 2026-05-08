<?php
/**
 * Logout - Destroy session and redirect to login
 * Apotek Zam Zam
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';

logoutUser();
header('Location: ' . BASE_URL . '/login.php');
exit;
