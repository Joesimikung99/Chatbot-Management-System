<?php
define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/vendor/autoload.php';
use Dotenv\Dotenv;
use App\Helpers\Auth;
use App\Helpers\Response;
use App\Helpers\Database;
use App\Services\LogService;

$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Bangkok');

$pageTitle  = 'User Management';
$breadcrumb = ['Admin', 'Users'];
Auth::requirePermission('manage_users');

$db     = Database::getInstance();
$logger = new LogService();

// ── AJAX Handlers ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validateCsrf()) Response::error('CSRF failed', 403);
    $action = $_POST['action'] ?? '';

    if ($action === 'create_local') {
        $email    = trim($_POST['email'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $name     = trim($_POST['display_name'] ?? '');
        $role     = $_POST['role'] ?? 'viewer';
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) Response::error('Email and Password are required', 422);
        if (!in_array($role, ['super_admin','admin','viewer'])) Response::error('Invalid role', 422);
        if (!Auth::isSuperAdmin() && $role === 'super_admin') Response::error('Insufficient permission', 403);

        $exists = $db->fetch('SELECT id FROM admin_users WHERE email=? OR username=?', [$email, $username]);
        if ($exists) Response::error('Email หรือ Username นี้ถูกใช้แล้ว', 409);

        $hash    = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $perms   = json_encode(getDefaultPermissions($role));
        $newId   = $db->insert('admin_users', [
            'email'=>$email,'username'=>$username,'display_name'=>$name,
            'password'=>$hash,'role'=>$role,'auth_provider'=>'local',
            'is_active'=>1,'permissions'=>$perms,
        ]);
        $logger->logActivity(Auth::get('id'), 'user.create', 'admin_user', (int)$newId, null, compact('email','role'));
        Response::success(['id'=>$newId], 'User created');
    }

    if ($action === 'update_role') {
        $id   = (int)($_POST['id'] ?? 0);
        $role = $_POST['role'] ?? 'viewer';
        if (!Auth::isSuperAdmin() && $role === 'super_admin') Response::error('Insufficient permission', 403);
        if ($id === Auth::get('id')) Response::error('ไม่สามารถแก้ไข Role ของตัวเองได้', 422);
        $old = $db->fetch('SELECT role FROM admin_users WHERE id=?', [$id]);
        $db->update('admin_users', ['role'=>$role, 'permissions'=>json_encode(getDefaultPermissions($role))], ['id'=>$id]);
        $logger->logActivity(Auth::get('id'), 'user.role_change', 'admin_user', $id, $old, ['role'=>$role]);
        Response::success(null, 'Role updated');
    }

    if ($action === 'toggle_active') {
        $id  = (int)($_POST['id'] ?? 0);
        $val = (int)($_POST['is_active'] ?? 0);
        if ($id === Auth::get('id')) Response::error('ไม่สามารถ Deactivate ตัวเองได้', 422);
        $db->update('admin_users', ['is_active'=>$val], ['id'=>$id]);
        $logger->logActivity(Auth::get('id'), 'user.toggle', 'admin_user', $id, null, ['is_active'=>$val]);
        Response::success(null, 'Updated');
    }

    if ($action === 'reset_password') {
        if (!Auth::isSuperAdmin()) Response::error('Insufficient permission', 403);
        $id  = (int)($_POST['id'] ?? 0);
        $pwd = $_POST['new_password'] ?? '';
        if (strlen($pwd) < 8) Response::error('Password ต้องมีอย่างน้อย 8 ตัวอักษร', 422);
        $hash = password_hash($pwd, PASSWORD_BCRYPT, ['cost'=>12]);
        $db->update('admin_users', ['password'=>$hash], ['id'=>$id, 'auth_provider'=>'local']);
        $logger->logActivity(Auth::get('id'), 'user.reset_password', 'admin_user', $id);
        Response::success(null, 'Password reset');
    }

    if ($action === 'delete') {
        if (!Auth::isSuperAdmin()) Response::error('Insufficient permission', 403);
        $id = (int)($_POST['id'] ?? 0);
        if ($id === Auth::get('id')) Response::error('ไม่สามารถลบตัวเองได้', 422);
        $db->delete('admin_users', ['id'=>$id]);
        $logger->logActivity(Auth::get('id'), 'user.delete', 'admin_user', $id);
        Response::success(null, 'User deleted');
    }

    Response::error('Unknown action', 400);
}

function getDefaultPermissions(string $role): array {
    $all = ['view_dashboard'=>true,'view_conversations'=>true,'view_analytics'=>true,'view_token_usage'=>true,'manage_knowledge'=>true,'manage_models'=>true,'manage_users'=>true,'manage_settings'=>true,'export_data'=>true];
    if ($role==='super_admin') return $all;
    if ($role==='admin') { unset($all['manage_users']); return $all; }
    return ['view_dashboard'=>true,'view_conversations'=>true,'view_analytics'=>false,'view_token_usage'=>false,'manage_knowledge'=>false,'manage_models'=>false,'manage_users'=>false,'manage_settings'=>false,'export_data'=>false];
}

// ── Load Users ─────────────────────────────────────────────────────────
$users = $db->fetchAll('SELECT * FROM admin_users ORDER BY created_at DESC');

// Load bot assignments per user
$botAssignments = $db->fetchAll('
    SELECT bu.user_id, bu.role AS bot_role, b.id AS bot_id, b.name AS bot_name, b.slug AS bot_slug
    FROM bot_users bu
    INNER JOIN bots b ON b.id = bu.bot_id
    ORDER BY b.name
');
$userBotMap = [];
foreach ($botAssignments as $ba) {
    $userBotMap[(int)$ba['user_id']][] = $ba;
}
$stats = [
    'total'     => count($users),
    'active'    => count(array_filter($users, fn($u)=>$u['is_active'])),
    'microsoft' => count(array_filter($users, fn($u)=>$u['auth_provider']==='microsoft')),
    'local'     => count(array_filter($users, fn($u)=>$u['auth_provider']==='local')),
];

function timeAgo(string $dt): string {
    $diff = time()-strtotime($dt);
    if ($diff < 60) return 'เมื่อกี้';
    if ($diff < 3600) return floor($diff/60).'ม. ที่แล้ว';
    if ($diff < 86400) return floor($diff/3600).'ชม. ที่แล้ว';
    return floor($diff/86400).'ว. ที่แล้ว';
}

require __DIR__ . '/layouts/header.php';
?>
<meta name="csrf-token" content="<?= Auth::csrfToken() ?>">

<div x-data="userManager()">

  <!-- ── Stats Row ──────────────────────────────────────────────────── -->
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <?php foreach ([
      ['label'=>'Total Users','value'=>$stats['total'],'color'=>'indigo'],
      ['label'=>'Active','value'=>$stats['active'],'color'=>'emerald'],
      ['label'=>'Microsoft Login','value'=>$stats['microsoft'],'color'=>'blue'],
      ['label'=>'Local Login','value'=>$stats['local'],'color'=>'slate'],
    ] as $s): ?>
    <div class="bg-white rounded-2xl p-5 border border-slate-100 card-hover">
      <p class="text-xs text-slate-500 mb-1"><?= $s['label'] ?></p>
      <p class="text-3xl font-bold text-slate-800"><?= $s['value'] ?></p>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ── Action Bar ─────────────────────────────────────────────────── -->
  <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <input type="text" x-model="search" placeholder="🔍 ค้นหาชื่อหรือ Email..."
           class="px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none w-64">
    <div class="flex gap-2">
      <select x-model="filterRole" class="px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
        <option value="">ทุก Role</option>
        <option value="super_admin">Super Admin</option>
        <option value="admin">Admin</option>
        <option value="viewer">Viewer</option>
      </select>
      <button @click="createModal=true"
              class="flex items-center gap-2 px-4 py-2.5 rounded-xl bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 transition-colors">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
        </svg>
        เพิ่ม User
      </button>
    </div>
  </div>

  <!-- ── Users Table ────────────────────────────────────────────────── -->
  <div class="bg-white rounded-2xl border border-slate-100 overflow-hidden">
    <table class="w-full text-sm">
      <thead class="bg-slate-50 border-b border-slate-100">
        <tr>
          <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">User</th>
          <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Email</th>
          <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">Role</th>
          <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider hidden lg:table-cell">Login</th>
          <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden xl:table-cell">Bots</th>
          <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden lg:table-cell">Last Login</th>
          <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">Active</th>
          <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-50">
        <?php foreach ($users as $u):
          $isMe   = $u['id'] == Auth::get('id');
          $initials = strtoupper(substr($u['display_name'] ?? $u['email'], 0, 1));
        ?>
        <tr class="hover:bg-slate-50 transition-colors" style="<?= !$u['is_active'] ? 'opacity:.55;' : '' ?>">
          <td class="px-5 py-3.5">
            <div class="flex items-center gap-3">
              <?php if ($u['avatar_url']): ?>
                <img src="<?= htmlspecialchars($u['avatar_url']) ?>" class="w-9 h-9 rounded-xl object-cover" alt="">
              <?php else: ?>
                <div class="w-9 h-9 rounded-xl flex items-center justify-center text-white text-sm font-bold"
                     style="background: linear-gradient(135deg,#4f46e5,#7c3aed);">
                  <?= $initials ?>
                </div>
              <?php endif; ?>
              <div>
                <p class="font-semibold text-slate-800">
                  <?= htmlspecialchars($u['display_name'] ?? $u['username'] ?? '—') ?>
                  <?php if ($isMe): ?><span class="ml-1.5 text-[10px] px-1.5 py-0.5 bg-indigo-100 text-indigo-600 rounded-md font-medium">ฉัน</span><?php endif; ?>
                </p>
                <p class="text-xs text-slate-400 md:hidden"><?= htmlspecialchars($u['email']) ?></p>
              </div>
            </div>
          </td>
          <td class="px-5 py-3.5 hidden md:table-cell text-slate-600 text-xs"><?= htmlspecialchars($u['email']) ?></td>
          <td class="px-5 py-3.5 text-center">
            <?php $roleBadge = ['super_admin'=>'badge-super_admin','admin'=>'badge-admin','viewer'=>'badge-viewer']; ?>
            <span class="px-2.5 py-1 rounded-lg text-xs font-semibold <?= $roleBadge[$u['role']] ?? 'badge-viewer' ?>">
              <?= match($u['role']){'super_admin'=>'Super Admin','admin'=>'Admin',default=>'Viewer'} ?>
            </span>
          </td>
          <td class="px-5 py-3.5 text-center hidden lg:table-cell">
            <?php if ($u['auth_provider']==='microsoft'): ?>
              <span class="flex items-center justify-center gap-1 text-xs text-blue-600">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 23 23" class="w-4 h-4"><rect x="1" y="1" width="10" height="10" fill="#f25022"/><rect x="12" y="1" width="10" height="10" fill="#7fba00"/><rect x="1" y="12" width="10" height="10" fill="#00a4ef"/><rect x="12" y="12" width="10" height="10" fill="#ffb900"/></svg>
                Microsoft
              </span>
            <?php else: ?>
              <span class="text-xs text-slate-500">🔑 Local</span>
            <?php endif; ?>
          </td>
          <td class="px-5 py-3.5 hidden xl:table-cell">
            <?php
            $uBots = $userBotMap[(int)$u['id']] ?? [];
            if ($u['role'] === 'super_admin'): ?>
              <span class="text-[10px] px-1.5 py-0.5 rounded bg-purple-50 text-purple-600 font-medium">All Bots</span>
            <?php elseif (empty($uBots)): ?>
              <span class="text-xs text-slate-400">-</span>
            <?php else: ?>
              <div class="flex flex-wrap gap-1">
                <?php foreach (array_slice($uBots, 0, 3) as $ub): ?>
                <span class="text-[10px] px-1.5 py-0.5 rounded font-medium
                  <?= match($ub['bot_role']) { 'owner' => 'bg-purple-50 text-purple-700', 'editor' => 'bg-blue-50 text-blue-700', default => 'bg-slate-100 text-slate-600' } ?>">
                  <?= htmlspecialchars($ub['bot_name']) ?>
                </span>
                <?php endforeach; ?>
                <?php if (count($uBots) > 3): ?>
                <span class="text-[10px] px-1.5 py-0.5 rounded bg-slate-100 text-slate-500">+<?= count($uBots) - 3 ?></span>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </td>
          <td class="px-5 py-3.5 hidden lg:table-cell text-xs text-slate-500">
            <?= $u['last_login_at'] ? timeAgo($u['last_login_at']) : 'ยังไม่เคย' ?>
          </td>
          <td class="px-5 py-3.5 text-center">
            <button onclick="toggleActive(<?= $u['id'] ?>, <?= $u['is_active'] ? 0 : 1 ?>)"
                    <?= $isMe ? 'disabled title="ไม่สามารถ Deactivate ตัวเอง"' : '' ?>
                    class="relative inline-flex h-6 w-11 rounded-full transition-colors <?= $u['is_active'] ? 'bg-emerald-500' : 'bg-slate-200' ?> <?= $isMe ? 'opacity-50 cursor-not-allowed' : '' ?>">
              <span class="inline-block h-5 w-5 transform rounded-full bg-white shadow transition-transform mt-0.5 <?= $u['is_active'] ? 'translate-x-5' : 'translate-x-0.5' ?>"></span>
            </button>
          </td>
          <td class="px-5 py-3.5 text-center">
            <div class="flex items-center justify-center gap-1">
              <?php if (!$isMe): ?>
              <button onclick="openRoleModal(<?= htmlspecialchars(json_encode($u)) ?>)"
                      class="p-1.5 rounded-lg text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 transition-colors" title="แก้ไข Role">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
              </button>
              <?php if ($u['auth_provider']==='local'): ?>
              <button onclick="openResetPwd(<?= $u['id'] ?>)"
                      class="p-1.5 rounded-lg text-slate-400 hover:text-amber-600 hover:bg-amber-50 transition-colors" title="Reset Password">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
              </button>
              <?php endif; ?>
              <?php if (Auth::isSuperAdmin()): ?>
              <button onclick="deleteUser(<?= $u['id'] ?>, '<?= addslashes($u['display_name'] ?? $u['email']) ?>')"
                      class="p-1.5 rounded-lg text-slate-400 hover:text-red-600 hover:bg-red-50 transition-colors" title="ลบ User">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
              </button>
              <?php endif; ?>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- ── Create User Modal ──────────────────────────────────────────── -->
  <div x-show="createModal" x-transition class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm" @click="createModal=false"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md p-6" @click.stop>
      <div class="flex items-center justify-between mb-5">
        <h2 class="text-lg font-bold text-slate-800">เพิ่ม User ใหม่ (Local)</h2>
        <button @click="createModal=false" class="text-slate-400 hover:text-slate-600">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
      <form @submit.prevent="createUser()" class="space-y-4">
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Display Name</label>
            <input type="text" x-model="newUser.display_name" required class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Username</label>
            <input type="text" x-model="newUser.username" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Email</label>
          <input type="email" x-model="newUser.email" required class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Password</label>
          <input type="password" x-model="newUser.password" minlength="8" required class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Role</label>
          <select x-model="newUser.role" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
            <option value="viewer">Viewer</option>
            <option value="admin">Admin</option>
            <?php if (Auth::isSuperAdmin()): ?><option value="super_admin">Super Admin</option><?php endif; ?>
          </select>
        </div>
        <div x-show="createError" class="p-3 bg-red-50 border border-red-200 rounded-xl text-sm text-red-700" x-text="createError"></div>
        <div class="flex gap-3 mt-2">
          <button type="button" @click="createModal=false" class="flex-1 py-2.5 rounded-xl border border-slate-200 text-sm font-medium text-slate-600 hover:bg-slate-50">ยกเลิก</button>
          <button type="submit" :disabled="creating" class="flex-1 py-2.5 rounded-xl bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 disabled:opacity-60">
            <span x-text="creating ? 'กำลังสร้าง...' : 'สร้าง User'"></span>
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;

function userManager() {
  return {
    search: '', filterRole: '',
    createModal: false, creating: false, createError: '',
    newUser: { display_name:'', username:'', email:'', password:'', role:'viewer' },

    async createUser() {
      this.creating = true; this.createError = '';
      const fd = new FormData(); fd.append('action','create_local'); fd.append('_csrf_token', CSRF);
      Object.entries(this.newUser).forEach(([k,v]) => fd.append(k,v));
      const res = await fetch('', { method:'POST', body:fd });
      const data = await res.json();
      if (data.success) { location.reload(); }
      else { this.createError = data.message; this.creating = false; }
    }
  }
}

function post(data) {
  const fd = new FormData(); fd.append('_csrf_token', CSRF);
  Object.entries(data).forEach(([k,v]) => fd.append(k,v));
  return fetch('', {method:'POST', body:fd}).then(r=>r.json());
}

async function toggleActive(id, val) {
  await post({action:'toggle_active', id, is_active:val}); location.reload();
}

async function openRoleModal(user) {
  const { value: role } = await Swal.fire({
    title: `เปลี่ยน Role สำหรับ ${user.display_name || user.email}`,
    input: 'select', inputOptions: { viewer: 'Viewer', admin: 'Admin', super_admin: 'Super Admin' },
    inputValue: user.role, showCancelButton: true, confirmButtonColor: '#4f46e5', cancelButtonColor: '#94a3b8',
    confirmButtonText: 'บันทึก', cancelButtonText: 'ยกเลิก', reverseButtons: true
  });
  if (role) {
    const d = await post({action:'update_role', id:user.id, role});
    d.success ? (Noti.success('เปลี่ยน Role สำเร็จ'), setTimeout(() => location.reload(), 1000)) : Noti.error(d.message);
  }
}

async function openResetPwd(id) {
  const pwd = await Noti.prompt('กรอก Password ใหม่ (อย่างน้อย 8 ตัวอักษร)', '', 'password');
  if (pwd && pwd.length >= 8) {
    const d = await post({action:'reset_password', id, new_password:pwd});
    d.success ? Noti.success('Reset Password สำเร็จ') : Noti.error(d.message);
  } else if (pwd) { Noti.warning('Password ต้องมีอย่างน้อย 8 ตัวอักษร'); }
}

async function deleteUser(id, name) {
  if (!await Noti.confirm(`ยืนยันลบ User: ${name}?\n\nการกระทำนี้ไม่สามารถกู้คืนได้`)) return;
  const d = await post({action:'delete', id});
  d.success ? (Noti.success('ลบสำเร็จ'), setTimeout(() => location.reload(), 1000)) : Noti.error(d.message);
}
</script>

<?php require __DIR__ . '/layouts/footer.php'; ?>
