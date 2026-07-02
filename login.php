<?php
/**
 * Login Page - Split-screen layout with authentication
 * Apotek Zam Zam
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
    exit;
}

$error = '';

// Handle login POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';

    // CSRF validation
    if (!validateCSRFToken($csrfToken)) {
        $error = 'Sesi tidak valid. Silakan coba lagi.';
    } elseif (empty($username) || empty($password)) {
        $error = 'Username dan password harus diisi.';
    } else {
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND status = 1 LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                loginUser($user);
                header('Location: ' . BASE_URL . '/pages/dashboard.php');
                exit;
            } else {
                $error = 'Username atau password salah.';
            }
        } catch (PDOException $e) {
            error_log("Login Error: " . $e->getMessage());
            $error = 'Terjadi kesalahan sistem. Silakan coba lagi.';
        }
    }
}

$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — <?php echo APP_NAME; ?></title>
    <meta name="description" content="Login ke <?php echo APP_DESCRIPTION; ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">

    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>

    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/main.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/components.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/redesign.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/auth.css">
</head>

<body>
    <div class="login-wrapper">
        <!-- Left Panel - Branding -->
        <div class="login-panel-left">
            <div class="orb"></div>
            <div class="orb"></div>
            <div class="orb"></div>

            <div class="login-brand">
                <div class="login-brand-icon" style="background:transparent; box-shadow:none; padding:0;">
                    <img src="<?php echo BASE_URL; ?>/assets/img/logo-white.png" alt="Logo"
                        style="height:64px; object-fit:contain;">
                </div>
                <h1><?php echo APP_NAME; ?></h1>
                <p>Sistem Prediksi Stok Obat Apotek<br>menggunakan Long Short-Term Memory</p>
            </div>

            <div class="login-features">
                <div class="login-feature-item">
                    <div class="login-feature-icon"><i data-lucide="brain-circuit"></i></div>
                    <span>Prediksi stok dengan LSTM</span>
                </div>
                <div class="login-feature-item">
                    <div class="login-feature-icon"><i data-lucide="bar-chart-3"></i></div>
                    <span>Dashboard analitik real-time</span>
                </div>
                <div class="login-feature-item">
                    <div class="login-feature-icon"><i data-lucide="shield-check"></i></div>
                    <span>Keamanan data terenkripsi</span>
                </div>
            </div>
        </div>

        <!-- Right Panel - Login Form -->
        <div class="login-panel-right">
            <div class="login-form-container">
                <div class="login-form-header">
                    <h2>Selamat Datang! 👋</h2>
                    <p>Masukkan kredensial untuk mengakses sistem</p>
                </div>

                <?php if ($error): ?>
                    <div class="login-error">
                        <i data-lucide="alert-circle"></i>
                        <span><?php echo e($error); ?></span>
                    </div>
                <?php endif; ?>

                <form class="login-form" method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

                    <div class="form-group">
                        <label class="form-label">Username</label>
                        <div class="input-with-icon">
                            <i data-lucide="user" class="input-icon"></i>
                            <input type="text" class="form-control" name="username" id="username"
                                placeholder="Masukkan username" required autofocus
                                value="<?php echo e($username ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Password</label>
                        <div class="input-with-icon">
                            <i data-lucide="lock" class="input-icon"></i>
                            <input type="password" class="form-control" name="password" id="password"
                                placeholder="Masukkan password" required>
                            <button type="button" class="toggle-password" onclick="togglePassword('password')">
                                <i data-lucide="eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-options">
                        <label class="remember-me">
                            <input type="checkbox" name="remember"> Ingat saya
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary btn-login">
                        <i data-lucide="log-in"></i> Masuk
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        if (window.lucide) lucide.createIcons();

        function togglePassword(id) {
            const input = document.getElementById(id);
            if (input) input.type = input.type === 'password' ? 'text' : 'password';
        }
    </script>
</body>

</html>