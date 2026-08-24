<?php
declare(strict_types=1);

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
$base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

if (empty($_SESSION['user_id'])) {
    header('Location: ' . $base . '/login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><title>Admin access required</title>'
       . '<body style="font-family:system-ui;background:#07111f;color:#eaf1ff;padding:40px">'
       . '<h1>Admin access required</h1><p>Only administrators can view this page.</p>'
       . '<p><a href="' . h($base . '/index.php') . '" style="color:#8fb4ff">Back to RemoteBridge</a></p></body>';
    exit;
}

$currentUsername = (string)($_SESSION['username'] ?? '');
$currentDisplayName = (string)($_SESSION['display_name'] ?? $currentUsername);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title> ARV Control System | Admin Panel </title>
<link rel="icon" type="image/x-icon" href="../logo/favicon.ico">
<link rel="icon" type="image/png" sizes="16x16" href="../logo/favicon-16.png">
<link rel="icon" type="image/png" sizes="32x32" href="../logo/favicon-32.png">
<link rel="apple-touch-icon" sizes="180x180" href="../logo/apple-touch-icon-180.png">
<style>
:root{font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#eaf1ff;background:#07101f}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;background:radial-gradient(circle at 20% 10%,#15315a 0,#07101f 42%,#050a13 100%)}
.nav{height:64px;border-bottom:1px solid #1c304b;background:rgba(8,20,37,.92);backdrop-filter:blur(10px);display:flex;align-items:center;justify-content:space-between;padding:0 28px;position:sticky;top:0;z-index:50}
.nav-left{display:flex;align-items:center;gap:14px}
.brand{font-weight:800;font-size:16px}
.sub,.muted{color:#9db0ca;font-size:13px}
.nav a.back{color:#bcd3f2;text-decoration:none;font-size:13.5px}
.nav a.back:hover{color:#fff}
.avatar{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,#2b6de8,#7b3fe4);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12.5px;color:#fff;flex-shrink:0}
.who{display:flex;align-items:center;gap:9px}
.who .name{font-size:13px;font-weight:700}
.badge{display:inline-block;font-size:10.5px;font-weight:700;letter-spacing:.3px;padding:2px 8px;border-radius:999px;border:1px solid #2c4262;background:#1e2e50;color:#a9c3ff}

.wrap{max-width:1000px;margin:32px auto 60px;padding:0 20px}
.card{border:1px solid #253b5a;border-radius:16px;background:rgba(14,27,47,.88);padding:26px;box-shadow:0 18px 60px rgba(0,0,0,.25);margin-bottom:20px}
h1{margin:0 0 4px;font-size:24px}
h2{font-size:16px;margin:0 0 6px}
p.lead{color:#9db0ca;margin:0 0 22px;font-size:14px}

.tabs{display:flex;gap:8px;margin-bottom:22px;flex-wrap:wrap}
.tab{padding:9px 16px;border-radius:10px;background:#0e1b2f;border:1px solid #253b5a;color:#cfe0f7;cursor:pointer;font-weight:600;font-size:13.5px}
.tab.active{background:#2b6de8;border-color:#2b6de8;color:#fff}
.panel{display:none}
.panel.active{display:block}

table{width:100%;border-collapse:collapse;font-size:13.5px}
th{text-align:left;color:#7f95b4;font-size:11px;letter-spacing:.4px;text-transform:uppercase;padding:8px 10px;border-bottom:1px solid #1e3049}
td{padding:10px;border-bottom:1px solid #16273f;vertical-align:middle}
tr:last-child td{border-bottom:0}
.role-pill{display:inline-block;font-size:10.5px;font-weight:700;padding:3px 9px;border-radius:999px;border:1px solid #2c4262}
.role-pill.admin{background:#1e2e50;border-color:#3a56a0;color:#a9c3ff}
.role-pill.user{background:#173822;border-color:#2f6b45;color:#8fe3a3}
.state-pill{display:inline-block;font-size:10.5px;font-weight:700;padding:3px 9px;border-radius:999px}
.state-pill.active{background:#173822;color:#8fe3a3}
.state-pill.inactive{background:#3a1414;color:#f29a9a}

.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
input,select{height:42px;padding:0 12px;border-radius:9px;border:1px solid #253b5a;background:#07101c;color:#eaf1ff;font-size:13.5px;outline:none}
input:focus,select:focus{border-color:#3a6fbb;box-shadow:0 0 0 2px rgba(43,109,232,.18)}
label{display:block;font-size:11.5px;font-weight:700;color:#9db0ca;margin-bottom:5px}
.field{min-width:160px;flex:1}
button{border:0;border-radius:9px;padding:10px 15px;background:#2b6de8;color:#fff;font-weight:700;cursor:pointer;font-size:13px}
button.secondary{background:#20324d}
button.danger{background:#a83a2f}
button.small{padding:6px 11px;font-size:12px}
button:disabled{opacity:.5;cursor:not-allowed}
.actions{display:flex;gap:6px;flex-wrap:wrap}
.add-user-form{margin-top:18px;padding-top:18px;border-top:1px solid #1e3049}
.msg{margin-top:12px;padding:10px 12px;border-radius:10px;font-size:13px;display:none}
.msg.ok{background:#0f2a1c;border:1px solid #2f6b45;color:#8fe3a3;display:block}
.msg.error{background:#281b0d;border:1px solid #815827;color:#f2c58a;display:block}
.meta{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:18px}
.meta div{background:#091727;border:1px solid #1b304c;border-radius:10px;padding:12px}
.meta strong{display:block;font-size:11px;color:#7f95b4;margin-bottom:4px}
.meta span{font-size:12.5px}
.notice{border:1px solid #31557d;background:#0b1c31;border-radius:12px;padding:14px;margin-top:18px;color:#c8d7ea;font-size:13.5px}
.log-line{font-family:ui-monospace,monospace;font-size:12px;padding:9px 10px;border-bottom:1px solid #16273f;color:#9db0ca}
.log-line .ev{color:#cfe0f7;font-weight:700}
.log-line .ip{color:#7f95b4}
.empty{color:#7f95b4;font-size:13px;padding:14px 4px}
.modal-overlay{position:fixed;inset:0;background:rgba(3,8,16,.7);display:flex;align-items:center;justify-content:center;z-index:100}
.modal-overlay.hidden{display:none}
.modal-box{background:#0d1d32;border:1px solid #253b5a;border-radius:16px;padding:26px;width:100%;max-width:360px}
.modal-box h3{margin:0 0 14px}
@media(max-width:650px){.meta{grid-template-columns:1fr}.nav{padding:0 14px}.sub{display:none}.who .name{display:none}table{font-size:12px}th:nth-child(4),td:nth-child(4){display:none}}
</style>
</head>
<body>
<header class="nav">
  <div class="nav-left">
    <div class="brand">RemoteBridge</div>
    <span class="sub">Admin</span>
  </div>
  <div class="row" style="gap:16px">
    <div class="who">
      <div class="avatar"><?= h(strtoupper(substr($currentDisplayName, 0, 1))) ?></div>
      <span class="name"><?= h($currentDisplayName) ?></span>
      <span class="badge">admin</span>
    </div>
    <a class="back" href="<?= h($base . '/index.php') ?>">← Back to RemoteBridge</a>
  </div>
</header>

<main class="wrap">
  <section class="card">
    <h1>Administration</h1>
    <p class="lead">Manage accounts, review activity, and see how network discovery works.</p>

    <div class="tabs">
      <div class="tab active" data-tab="users" onclick="rbShowAdminTab('users')">Users</div>
      <div class="tab" data-tab="audit" onclick="rbShowAdminTab('audit')">Audit log</div>
      <div class="tab" data-tab="network" onclick="rbShowAdminTab('network')">Network discovery</div>
    </div>

    <div id="panel-users" class="panel active">
      <table>
        <thead>
          <tr><th>User</th><th>Role</th><th>Status</th><th>Last login</th><th></th></tr>
        </thead>
        <tbody id="usersBody"><tr><td colspan="5" class="empty">Loading…</td></tr></tbody>
      </table>

      <div class="add-user-form">
        <h2>Add a user</h2>
        <form id="createUserForm">
          <div class="row">
            <div class="field">
              <label for="newUsername">Username</label>
              <input type="text" id="newUsername" required autocomplete="off">
            </div>
            <div class="field">
              <label for="newDisplayName">Display name</label>
              <input type="text" id="newDisplayName" autocomplete="off">
            </div>
            <div class="field" style="max-width:140px">
              <label for="newRole">Role</label>
              <select id="newRole">
                <option value="user">User</option>
                <option value="admin">Admin</option>
              </select>
            </div>
            <div class="field">
              <label for="newPassword">Password</label>
              <input type="text" id="newPassword" required minlength="8" placeholder="min 8 characters">
            </div>
            <button type="submit" style="align-self:flex-end;height:42px">Create user</button>
          </div>
        </form>
        <div id="createUserMsg" class="msg"></div>
      </div>
    </div>

    <div id="panel-audit" class="panel">
      <div class="row" style="justify-content:space-between;margin-bottom:12px">
        <p class="muted" style="margin:0">Most recent 100 events — logins, device registration, and network scans.</p>
        <button class="secondary small" onclick="rbLoadAuditLog()">Refresh</button>
      </div>
      <div id="auditBody"><div class="empty">Loading…</div></div>
    </div>

    <div id="panel-network" class="panel">
      <h2>Network Discovery</h2>
      <p class="muted">Devices on the local network can be discovered directly from the main RemoteBridge page — admins only.</p>
      <div class="meta">
        <div><strong>ACCESS</strong><span>Admin role required</span></div>
        <div><strong>DISCOVERY</strong><span>Full detected subnet sweep</span></div>
        <div><strong>RESULTS</strong><span>IP, MAC and hostname when available</span></div>
      </div>
      <h2 style="margin-top:22px">How it works</h2>
      <p class="muted">Click <strong>Scan all devices</strong> in the Devices on this network panel (visible to admins). RemoteBridge detects the server's local IPv4 subnet, probes the available host range, refreshes the server's ARP table, and displays the devices it can see.</p>
      <div class="notice"><strong>Restricted to admins and to the machine running the PHP server.</strong> The discovery API checks both the logged-in user's role and that the request originates from the same LAN as the server.</div>
    </div>
  </section>
</main>

<div id="resetPwModal" class="modal-overlay hidden">
  <div class="modal-box">
    <h3>Reset password</h3>
    <p class="muted" id="resetPwFor" style="margin-top:-8px"></p>
    <div class="field" style="margin-bottom:14px">
      <label for="resetPwValue">New password</label>
      <input type="text" id="resetPwValue" style="width:100%" minlength="8" placeholder="min 8 characters">
    </div>
    <div class="row" style="justify-content:flex-end">
      <button class="secondary" type="button" onclick="rbCloseResetModal()">Cancel</button>
      <button type="button" onclick="rbSubmitResetPassword()">Set password</button>
    </div>
    <div id="resetPwMsg" class="msg"></div>
  </div>
</div>

<script>
const RB_BASE = <?= json_encode($base, JSON_UNESCAPED_SLASHES) ?>;
const RB_CURRENT_USERNAME = <?= json_encode($currentUsername) ?>;
function rbUrl(p){ return RB_BASE + p; }

async function rbApi(path, opts) {
  const res = await fetch(rbUrl(path), Object.assign({ headers: { 'Content-Type': 'application/json' } }, opts));
  if (res.status === 401) { window.location.href = RB_BASE + '/login.php'; throw new Error('Authentication required'); }
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || `Request failed (${res.status})`);
  return data;
}

function rbShowAdminTab(name){
  document.querySelectorAll('.tab').forEach(t => t.classList.toggle('active', t.dataset.tab === name));
  document.querySelectorAll('.panel').forEach(p => p.classList.toggle('active', p.id === 'panel-' + name));
  if (name === 'audit') rbLoadAuditLog();
}

function rbEsc(s){ const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

function rbUserRow(u){
  const isSelf = u.username === RB_CURRENT_USERNAME;
  const lastLogin = u.last_login_at ? rbEsc(u.last_login_at) : '—';
  return `<tr>
    <td><strong>${rbEsc(u.display_name || u.username)}</strong><br><span class="muted">@${rbEsc(u.username)}${isSelf ? ' (you)' : ''}</span></td>
    <td><span class="role-pill ${u.role}">${rbEsc(u.role)}</span></td>
    <td><span class="state-pill ${u.is_active ? 'active' : 'inactive'}">${u.is_active ? 'Active' : 'Disabled'}</span></td>
    <td class="muted">${lastLogin}</td>
    <td>
      <div class="actions">
        <button class="secondary small" type="button" onclick="rbOpenResetModal(${u.id}, '${rbEsc(u.username)}')">Reset password</button>
        <button class="secondary small" type="button" ${isSelf ? 'disabled title="You can\'t change your own role"' : ''} onclick="rbToggleRole(${u.id}, '${u.role}')">${u.role === 'admin' ? 'Make user' : 'Make admin'}</button>
        <button class="danger small" type="button" ${isSelf ? 'disabled title="You can\'t disable your own account"' : ''} onclick="rbToggleActive(${u.id}, ${u.is_active ? 'true' : 'false'})">${u.is_active ? 'Disable' : 'Enable'}</button>
      </div>
    </td>
  </tr>`;
}

async function rbLoadUsers(){
  const body = document.getElementById('usersBody');
  try {
    const data = await rbApi('/api/admin/users', { method: 'GET' });
    body.innerHTML = data.users.length ? data.users.map(rbUserRow).join('') : '<tr><td colspan="5" class="empty">No users found.</td></tr>';
  } catch (e) {
    body.innerHTML = `<tr><td colspan="5" class="empty">${rbEsc(e.message)}</td></tr>`;
  }
}

async function rbToggleRole(id, currentRole){
  const role = currentRole === 'admin' ? 'user' : 'admin';
  try { await rbApi('/api/admin/users/update', { method: 'POST', body: JSON.stringify({ id, role }) }); await rbLoadUsers(); }
  catch (e) { alert(e.message); }
}

async function rbToggleActive(id, currentlyActive){
  try { await rbApi('/api/admin/users/update', { method: 'POST', body: JSON.stringify({ id, is_active: !currentlyActive }) }); await rbLoadUsers(); }
  catch (e) { alert(e.message); }
}

let resetPwUserId = null;
function rbOpenResetModal(id, username){
  resetPwUserId = id;
  document.getElementById('resetPwFor').textContent = 'For @' + username;
  document.getElementById('resetPwValue').value = '';
  document.getElementById('resetPwMsg').className = 'msg';
  document.getElementById('resetPwModal').classList.remove('hidden');
}
function rbCloseResetModal(){ document.getElementById('resetPwModal').classList.add('hidden'); }
async function rbSubmitResetPassword(){
  const msg = document.getElementById('resetPwMsg');
  const password = document.getElementById('resetPwValue').value;
  try {
    await rbApi('/api/admin/users/reset-password', { method: 'POST', body: JSON.stringify({ id: resetPwUserId, password }) });
    msg.textContent = 'Password updated.';
    msg.className = 'msg ok';
    setTimeout(rbCloseResetModal, 900);
  } catch (e) {
    msg.textContent = e.message;
    msg.className = 'msg error';
  }
}

document.getElementById('createUserForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const msg = document.getElementById('createUserMsg');
  const payload = {
    username: document.getElementById('newUsername').value.trim(),
    display_name: document.getElementById('newDisplayName').value.trim(),
    role: document.getElementById('newRole').value,
    password: document.getElementById('newPassword').value,
  };
  try {
    await rbApi('/api/admin/users/create', { method: 'POST', body: JSON.stringify(payload) });
    msg.textContent = 'User created.';
    msg.className = 'msg ok';
    e.target.reset();
    await rbLoadUsers();
  } catch (err) {
    msg.textContent = err.message;
    msg.className = 'msg error';
  }
});

function rbAuditLine(entry){
  let details = '';
  if (entry.details) {
    try { details = ' — ' + Object.entries(JSON.parse(entry.details)).map(([k,v]) => `${k}=${v}`).join(', '); } catch (e) {}
  }
  return `<div class="log-line"><span class="ev">${rbEsc(entry.event_type)}</span> · ${rbEsc(entry.created_at)} · <span class="ip">${rbEsc(entry.ip_address || '—')}</span>${rbEsc(details)}</div>`;
}

async function rbLoadAuditLog(){
  const body = document.getElementById('auditBody');
  body.innerHTML = '<div class="empty">Loading…</div>';
  try {
    const data = await rbApi('/api/admin/audit-log?limit=100', { method: 'GET' });
    body.innerHTML = data.entries.length ? data.entries.map(rbAuditLine).join('') : '<div class="empty">No audit events yet.</div>';
  } catch (e) {
    body.innerHTML = `<div class="empty">${rbEsc(e.message)}</div>`;
  }
}

rbLoadUsers();
</script>
</body></html>
