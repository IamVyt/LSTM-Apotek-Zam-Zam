<?php
/**
 * Settings Page - Tabs: Profil, LSTM Config, Users, Notifications
 * Apotek Zam Zam
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();
$db = getDB();
$pageTitle = 'Pengaturan';

$isAdmin = ($_SESSION['role'] === 'admin');

// Load current settings
$namaApotek = getSetting($db, 'nama_apotek', 'Apotek Zam Zam');
$alamatApotek = getSetting($db, 'alamat_apotek', '');
$telpApotek = getSetting($db, 'telp_apotek', '');
$emailApotek = getSetting($db, 'email_apotek', '');


$notifStokKritis = getSetting($db, 'notif_stok_kritis', '1');
$notifPrediksi = getSetting($db, 'notif_prediksi', '1');
$notifKadaluarsa = getSetting($db, 'notif_kadaluarsa', '1');

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<main class="main-content">
    <div class="page-header">
        <div>
            <h1>Pengaturan</h1>
            <p class="subtitle">Kelola konfigurasi sistem dan akun</p>
        </div>
    </div>

    <!-- Tabs -->
    <div class="tabs">
        <button class="tab-btn active" data-tab="profil" onclick="switchTab('profil')">
            <i data-lucide="building-2" class="icon-16 icon-inline"></i> Profil Apotek
        </button>

        <?php if ($isAdmin): ?>
        <button class="tab-btn" data-tab="users" onclick="switchTab('users')">
            <i data-lucide="users" class="icon-16 icon-inline"></i> Manajemen User
        </button>
        <?php endif; ?>
        <button class="tab-btn" data-tab="notifikasi" onclick="switchTab('notifikasi')">
            <i data-lucide="bell" class="icon-16 icon-inline"></i> Notifikasi
        </button>
    </div>

    <!-- Tab: Profil Apotek -->
    <div class="tab-content active" id="tab-profil">
        <div class="card fade-in">
            <div class="card-header">
                <h3>Informasi Apotek</h3>
            </div>
            <form id="profilForm" onsubmit="saveSettings(event, 'profil')">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Nama Apotek</label>
                        <input type="text" class="form-control" name="nama_apotek" value="<?php echo e($namaApotek); ?>" placeholder="Nama Apotek">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email_apotek" value="<?php echo e($emailApotek); ?>" placeholder="email@apotek.com">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Telepon</label>
                        <input type="text" class="form-control" name="telp_apotek" value="<?php echo e($telpApotek); ?>" placeholder="0812-xxxx-xxxx">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Alamat</label>
                        <input type="text" class="form-control" name="alamat_apotek" value="<?php echo e($alamatApotek); ?>" placeholder="Alamat lengkap">
                    </div>
                </div>
                <div style="margin-top:16px;">
                    <button type="submit" class="btn btn-primary"><i data-lucide="save"></i> Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>


    <?php if ($isAdmin): ?>
    <!-- Tab: User Management -->
    <div class="tab-content" id="tab-users">
        <div class="card fade-in">
            <div class="card-header">
                <h3>Daftar User</h3>
                <button class="btn btn-primary btn-sm" onclick="openUserModal()">
                    <i data-lucide="user-plus"></i> Tambah User
                </button>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Nama</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="usersBody">
                        <tr><td colspan="6" class="text-center text-muted" style="padding:32px;">Memuat...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Tab: Notifikasi -->
    <div class="tab-content" id="tab-notifikasi">
        <div class="card fade-in">
            <div class="card-header">
                <h3>Pengaturan Notifikasi</h3>
            </div>
            <form id="notifForm" onsubmit="saveSettings(event, 'notifikasi')">
                <div style="display:flex;flex-direction:column;gap:20px;">
                    <div class="flex-between" style="padding:12px 0;border-bottom:1px solid var(--border-color);">
                        <div>
                            <div class="fw-600">Notifikasi Stok Kritis</div>
                            <div class="text-sm text-muted">Tampilkan peringatan saat stok obat di bawah minimum</div>
                        </div>
                        <label class="switch">
                            <input type="checkbox" name="notif_stok_kritis" value="1" <?php echo $notifStokKritis === '1' ? 'checked' : ''; ?>>
                            <span class="switch-slider"></span>
                        </label>
                    </div>
                    <div class="flex-between" style="padding:12px 0;border-bottom:1px solid var(--border-color);">
                        <div>
                            <div class="fw-600">Notifikasi Prediksi Selesai</div>
                            <div class="text-sm text-muted">Tampilkan notifikasi setelah prediksi LSTM selesai</div>
                        </div>
                        <label class="switch">
                            <input type="checkbox" name="notif_prediksi" value="1" <?php echo $notifPrediksi === '1' ? 'checked' : ''; ?>>
                            <span class="switch-slider"></span>
                        </label>
                    </div>
                    <div class="flex-between" style="padding:12px 0;">
                        <div>
                            <div class="fw-600">Peringatan Kadaluarsa</div>
                            <div class="text-sm text-muted">Notifikasi obat mendekati tanggal kadaluarsa</div>
                        </div>
                        <label class="switch">
                            <input type="checkbox" name="notif_kadaluarsa" value="1" <?php echo $notifKadaluarsa === '1' ? 'checked' : ''; ?>>
                            <span class="switch-slider"></span>
                        </label>
                    </div>
                </div>
                <div style="margin-top:24px;">
                    <button type="submit" class="btn btn-primary"><i data-lucide="save"></i> Simpan Notifikasi</button>
                </div>
            </form>
        </div>
    </div>
</main>

<!-- User Modal -->
<div class="modal-overlay" id="userModal">
    <div class="modal">
        <div class="modal-header">
            <h3 id="userModalTitle">Tambah User</h3>
            <button class="modal-close" onclick="closeModal('userModal')"><i data-lucide="x"></i></button>
        </div>
        <div class="modal-body">
            <form id="userForm">
                <input type="hidden" id="userId" name="id">
                <input type="hidden" id="userAction" name="action" value="add">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Nama Lengkap *</label>
                        <input type="text" class="form-control" id="userFullName" name="full_name" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Username *</label>
                        <input type="text" class="form-control" id="userUsername" name="username" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Email *</label>
                        <input type="email" class="form-control" id="userEmail" name="email" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Role *</label>
                        <select class="form-control" id="userRole" name="role" required>
                            <option value="asisten">Asisten</option>
                            <option value="apoteker">Apoteker</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Password <span id="pwdHint" class="text-muted">(wajib untuk user baru)</span></label>
                    <input type="password" class="form-control" id="userPassword" name="password">
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('userModal')">Batal</button>
            <button class="btn btn-primary" onclick="saveUser()"><i data-lucide="save"></i> Simpan</button>
        </div>
    </div>
</div>

</div><!-- end app-wrapper -->

<script src="<?php echo BASE_URL; ?>/assets/js/main.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    <?php if ($isAdmin): ?>loadUsers();<?php endif; ?>
});

// ── Save Settings ──
async function saveSettings(e, section) {
    e.preventDefault();
    const formMap = { profil: 'profilForm', notifikasi: 'notifForm' };
    const form = document.getElementById(formMap[section]);
    if (!form) return;

    const formData = new FormData(form);
    const settings = {};
    formData.forEach((val, key) => { settings[key] = val; });

    // Handle unchecked checkboxes for notifications
    if (section === 'notifikasi') {
        ['notif_stok_kritis', 'notif_prediksi', 'notif_kadaluarsa'].forEach(key => {
            if (!settings[key]) settings[key] = '0';
        });
    }

    try {
        // Save each setting individually via a simple POST
        const response = await fetchAPI(BASE_URL + '/api/settings.php', {
            method: 'POST',
            body: JSON.stringify({ settings, csrf_token: document.querySelector('meta[name="csrf-token"]')?.content })
        });
        showToast('Pengaturan berhasil disimpan!', 'success');
    } catch (err) {
        showToast(err.message || 'Gagal menyimpan', 'error');
    }
}

// ── User Management ──
async function loadUsers() {
    try {
        const data = await fetchAPI(BASE_URL + '/api/users.php?action=list');
        const tbody = document.getElementById('usersBody');
        if (!data.data || data.data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">Tidak ada user</td></tr>';
            return;
        }
        tbody.innerHTML = data.data.map(u => `
            <tr>
                <td class="fw-600">${escapeHtml(u.full_name)}</td>
                <td>${escapeHtml(u.username)}</td>
                <td class="text-sm">${escapeHtml(u.email)}</td>
                <td><span class="badge badge-purple">${escapeHtml(u.role)}</span></td>
                <td><span class="badge ${u.status == 1 ? 'badge-aman' : 'badge-kritis'}">${u.status == 1 ? 'Aktif' : 'Nonaktif'}</span></td>
                <td>
                    <div class="action-cell">
                        <button class="btn btn-icon btn-ghost" onclick="editUser(${u.id})" title="Edit"><i data-lucide="pencil"></i></button>
                        <button class="btn btn-icon btn-ghost" onclick="deleteUser(${u.id}, '${escapeHtml(u.full_name)}')" title="Hapus" style="color:#EF4444;"><i data-lucide="trash-2"></i></button>
                    </div>
                </td>
            </tr>
        `).join('');
        if (window.lucide) lucide.createIcons();
    } catch (err) { console.error(err); }
}

function openUserModal() {
    document.getElementById('userModalTitle').textContent = 'Tambah User';
    document.getElementById('userAction').value = 'add';
    document.getElementById('userId').value = '';
    document.getElementById('userForm').reset();
    document.getElementById('userPassword').required = true;
    document.getElementById('pwdHint').textContent = '(wajib untuk user baru)';
    openModal('userModal');
}

async function editUser(id) {
    try {
        const res = await fetchAPI(BASE_URL + '/api/users.php?action=get&id=' + id);
        const u = res.data;
        document.getElementById('userModalTitle').textContent = 'Edit User';
        document.getElementById('userAction').value = 'edit';
        document.getElementById('userId').value = u.id;
        document.getElementById('userFullName').value = u.full_name;
        document.getElementById('userUsername').value = u.username;
        document.getElementById('userEmail').value = u.email;
        document.getElementById('userRole').value = u.role;
        document.getElementById('userPassword').required = false;
        document.getElementById('userPassword').value = '';
        document.getElementById('pwdHint').textContent = '(kosongkan jika tidak diubah)';
        openModal('userModal');
    } catch (err) { showToast('Gagal memuat data user', 'error'); }
}

async function saveUser() {
    const form = document.getElementById('userForm');
    if (!form.checkValidity()) { form.reportValidity(); return; }

    const data = getFormData('userForm');
    try {
        const res = await fetchAPI(BASE_URL + '/api/users.php', {
            method: 'POST',
            body: JSON.stringify(data)
        });
        showToast(res.message, 'success');
        closeModal('userModal');
        loadUsers();
    } catch (err) { showToast(err.message || 'Gagal menyimpan', 'error'); }
}

async function deleteUser(id, name) {
    if (!confirmAction(`Hapus user "${name}"?`)) return;
    try {
        const res = await fetchAPI(BASE_URL + '/api/users.php', {
            method: 'POST',
            body: JSON.stringify({ action: 'delete', id })
        });
        showToast(res.message, 'success');
        loadUsers();
    } catch (err) { showToast(err.message || 'Gagal menghapus', 'error'); }
}
</script>
</body>
</html>
