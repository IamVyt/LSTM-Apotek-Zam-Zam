<?php
/**
 * Users API - CRUD for user management (admin only)
 * Apotek Zam Zam
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
requireAPIRole(['admin']);

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'list';
        if ($action === 'get') {
            getUser($db);
        } else {
            listUsers($db);
        }
    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $action = $input['action'] ?? '';

        switch ($action) {
            case 'add':
                addUser($db, $input);
                break;
            case 'edit':
                editUser($db, $input);
                break;
            case 'delete':
                deleteUser($db, $input);
                break;
            case 'toggle_status':
                toggleStatus($db, $input);
                break;
            default:
                jsonResponse(['success' => false, 'message' => 'Action tidak valid'], 400);
        }
    } else {
        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }
} catch (Exception $e) {
    error_log("Users API Error: " . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Internal server error'], 500);
}

function listUsers(PDO $db): void {
    $stmt = $db->prepare("SELECT id, username, email, full_name, role, status, created_at FROM users ORDER BY created_at DESC");
    $stmt->execute();
    $users = $stmt->fetchAll();

    jsonResponse(['success' => true, 'data' => $users]);
}

function getUser(PDO $db): void {
    $id = inputInt('id');
    $stmt = $db->prepare("SELECT id, username, email, full_name, role, status, created_at FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonResponse(['success' => false, 'message' => 'User tidak ditemukan'], 404);
    }

    jsonResponse(['success' => true, 'data' => $user]);
}

function addUser(PDO $db, array $input): void {
    $required = ['username', 'email', 'password', 'full_name', 'role'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            jsonResponse(['success' => false, 'message' => "Field {$field} diperlukan"], 400);
        }
    }

    // Check unique username
    $check = $db->prepare("SELECT id FROM users WHERE username = ?");
    $check->execute([$input['username']]);
    if ($check->fetch()) {
        jsonResponse(['success' => false, 'message' => 'Username sudah digunakan'], 409);
    }

    // Check unique email
    $check2 = $db->prepare("SELECT id FROM users WHERE email = ?");
    $check2->execute([$input['email']]);
    if ($check2->fetch()) {
        jsonResponse(['success' => false, 'message' => 'Email sudah digunakan'], 409);
    }

    $validRoles = ['admin', 'apoteker', 'asisten'];
    if (!in_array($input['role'], $validRoles)) {
        jsonResponse(['success' => false, 'message' => 'Role tidak valid'], 400);
    }

    $hashedPassword = password_hash($input['password'], PASSWORD_BCRYPT);

    $stmt = $db->prepare("INSERT INTO users (username, email, password, full_name, role, status, created_at)
                           VALUES (?, ?, ?, ?, ?, 1, NOW())");
    $stmt->execute([
        $input['username'],
        $input['email'],
        $hashedPassword,
        $input['full_name'],
        $input['role']
    ]);

    jsonResponse(['success' => true, 'message' => 'User berhasil ditambahkan', 'data' => ['id' => (int)$db->lastInsertId()]]);
}

function editUser(PDO $db, array $input): void {
    $id = (int)($input['id'] ?? 0);
    if (!$id) {
        jsonResponse(['success' => false, 'message' => 'ID diperlukan'], 400);
    }

    $current = $db->prepare("SELECT * FROM users WHERE id = ?");
    $current->execute([$id]);
    $user = $current->fetch();

    if (!$user) {
        jsonResponse(['success' => false, 'message' => 'User tidak ditemukan'], 404);
    }

    // Check unique username (exclude current)
    if (!empty($input['username']) && $input['username'] !== $user['username']) {
        $check = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $check->execute([$input['username'], $id]);
        if ($check->fetch()) {
            jsonResponse(['success' => false, 'message' => 'Username sudah digunakan'], 409);
        }
    }

    $stmt = $db->prepare("UPDATE users SET username = ?, email = ?, full_name = ?, role = ? WHERE id = ?");
    $stmt->execute([
        $input['username'] ?? $user['username'],
        $input['email'] ?? $user['email'],
        $input['full_name'] ?? $user['full_name'],
        $input['role'] ?? $user['role'],
        $id
    ]);

    // Update password if provided
    if (!empty($input['password'])) {
        $hashed = password_hash($input['password'], PASSWORD_BCRYPT);
        $stmtPwd = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmtPwd->execute([$hashed, $id]);
    }

    jsonResponse(['success' => true, 'message' => 'User berhasil diperbarui']);
}

function deleteUser(PDO $db, array $input): void {
    $id = (int)($input['id'] ?? 0);
    if (!$id) {
        jsonResponse(['success' => false, 'message' => 'ID diperlukan'], 400);
    }

    // Don't allow deleting self
    if ($id === (int)$_SESSION['user_id']) {
        jsonResponse(['success' => false, 'message' => 'Tidak dapat menghapus akun sendiri'], 400);
    }

    $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$id]);

    jsonResponse(['success' => true, 'message' => 'User berhasil dihapus']);
}

function toggleStatus(PDO $db, array $input): void {
    $id = (int)($input['id'] ?? 0);
    if (!$id) {
        jsonResponse(['success' => false, 'message' => 'ID diperlukan'], 400);
    }

    if ($id === (int)$_SESSION['user_id']) {
        jsonResponse(['success' => false, 'message' => 'Tidak dapat mengubah status akun sendiri'], 400);
    }

    $stmt = $db->prepare("UPDATE users SET status = IF(status = 1, 0, 1) WHERE id = ?");
    $stmt->execute([$id]);

    jsonResponse(['success' => true, 'message' => 'Status user berhasil diubah']);
}
