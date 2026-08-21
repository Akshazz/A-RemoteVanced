<?php
declare(strict_types=1);

session_start();
require dirname(__DIR__) . '/database.php';
$config = require dirname(__DIR__) . '/config.php';

function rb_admin_local_only(): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><title>Admin settings unavailable</title><body style="font-family:system-ui;background:#07111f;color:#eaf1ff;padding:40px"><h1>Admin settings are local-only</h1><p>Open this page from the same computer running the RemoteBridge PHP server.</p></body>';
        exit;
    }
}
rb_admin_local_only();

$db = null;
$error = '';
$success = '';
$generatedCode = null;

try {
    $db = db();
    $db->query("CREATE TABLE IF NOT EXISTS app_settings (
        setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
        setting_value TEXT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
    $error = 'Database is unavailable. Run database/migrate.php first, then reload this page.';
}

function rb_setting(mysqli $db, string $key): ?string {
    $stmt = $db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return is_array($row) && array_key_exists('setting_value', $row) ? (string)$row['setting_value'] : null;
}

function rb_save_setting(mysqli $db, string $key, string $value): void {
    $stmt = $db->prepare('INSERT INTO app_settings(setting_key, setting_value) VALUES(?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db instanceof mysqli && $error === '') {
    $action = (string)($_POST['action'] ?? 'save');
    try {
        if ($action === 'generate') {
            $code = bin2hex(random_bytes(8));
            rb_save_setting($db, 'network_access_code', $code);
            $generatedCode = $code;
            $success = 'A new Network Access Code was generated and saved. Use the code shown below to unlock the network device panel.';
        } else {
            $code = trim((string)($_POST['network_access_code'] ?? ''));
            if ($code === '') {
                throw new RuntimeException('Enter a Network Access Code, or use Generate new code.');
            }
            if (strlen($code) < 6 || strlen($code) > 128) {
                throw new RuntimeException('The Network Access Code must be between 6 and 128 characters.');
            }
            rb_save_setting($db, 'network_access_code', $code);
            $success = 'Network Access Code saved successfully.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$current = ($db instanceof mysqli && $error === '') ? rb_setting($db, 'network_access_code') : null;
$hasCode = is_string($current) && $current !== '';
$fallbackConfigured = !empty($config['network']['access_code']);
$base = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/settings.php'))), '/');
if ($base === '/' || $base === '.') $base = '';
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>RemoteBridge — Admin Settings</title>
<style>
:root{color-scheme:dark;font-family:Inter,system-ui,-apple-system,Segoe UI,sans-serif;background:#07111f;color:#eaf1ff}
*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top,#10243f 0,#07111f 48%,#050b14 100%);min-height:100vh}
.nav{height:68px;border-bottom:1px solid #1b304c;background:#09172a;display:flex;align-items:center;justify-content:space-between;padding:0 24px;gap:15px}.brand{font-weight:800;font-size:18px}.sub{display:block;font-size:11px;color:#8297b5;font-weight:500;margin-top:2px}.nav a{color:#cfe0f7;text-decoration:none;border:1px solid #2c4262;border-radius:9px;padding:8px 12px;font-size:12.5px;font-weight:700}.nav a:hover{background:#0e1b2f;border-color:#3a5680}
.wrap{max-width:900px;margin:40px auto;padding:0 20px}.card{background:#0d1d32;border:1px solid #253b5a;border-radius:18px;padding:24px;box-shadow:0 24px 70px rgba(0,0,0,.28)}h1{font-size:24px;margin:0 0 7px}h2{font-size:16px;margin:0 0 8px}.muted{color:#9db0ca;line-height:1.55;font-size:13px}.row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.field{margin-top:22px}.label{display:block;font-size:13px;font-weight:700;margin-bottom:8px}input{width:100%;height:46px;background:#071321;border:1px solid #2a4364;border-radius:10px;color:#eaf1ff;padding:0 13px;font:600 14px ui-monospace,SFMono-Regular,Consolas,monospace;outline:none}input:focus{border-color:#3f82ff;box-shadow:0 0 0 3px rgba(63,130,255,.14)}button{height:42px;border:0;border-radius:10px;background:#2b6de8;color:#fff;font-weight:800;padding:0 16px;cursor:pointer}button.secondary{background:#172b46;border:1px solid #2c4262;color:#cfe0f7}button:hover{filter:brightness(1.08)}.status{display:inline-flex;padding:6px 10px;border-radius:999px;font-size:12px;background:#102b1d;border:1px solid #2f6b45;color:#75e5a0}.warn{background:#352816;border-color:#815827;color:#f2c58a}.notice{margin-top:16px;padding:12px 14px;border-radius:10px;background:#091727;border:1px solid #1b304c;font-size:12.5px;color:#a9c3ff}.ok{border-color:#2f6b45;color:#8fe3a3;background:#0d2419}.err{border-color:#815827;color:#f2c58a;background:#281b0d}.generated{margin-top:16px;padding:14px;border:1px solid #355985;border-radius:12px;background:#0a192c}.generated>div:first-child{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}.generated strong{display:block;font-size:13px}.generated span,.generated small{color:#9db0ca;font-size:12px}.generated-row{display:flex;gap:10px;margin-top:10px}.generated-row input{flex:1;min-width:0}.generated-row button{flex:0 0 auto}.divider{height:1px;background:#1b304c;margin:24px 0}.meta{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:18px}.meta div{background:#091727;border:1px solid #1b304c;border-radius:10px;padding:11px}.meta strong{display:block;font-size:11px;color:#7f95b4;margin-bottom:4px}.meta span{font-size:12.5px}@media(max-width:650px){.meta{grid-template-columns:1fr}.wrap{margin:20px auto}.nav{padding:0 14px}.sub{display:none}}
@media(max-width:650px){.generated-row{flex-direction:column}.generated-row button{width:100%}}
</style>
</head>
<body>
<header class="nav"><div><div class="brand">RemoteBridge</div><span class="sub">Administration / Network security</span></div><a href="<?=h($base . '/index.php')?>">← Back to RemoteBridge</a></header>
<main class="wrap">
<section class="card">
<h1>Admin Settings</h1>
<p class="muted">Configure the <strong>Network Access Code</strong> used to unlock <em>Devices on this network</em>. Changes are stored in the RemoteBridge database, so you no longer need to edit <code>config.php</code>.</p>
<?php if ($success): ?><div class="notice ok"><?=h($success)?></div><?php endif; ?>
<?php if ($generatedCode !== null): ?>
<div class="generated" aria-live="polite">
  <div><strong>Generated Network Access Code</strong><span>Use this code in <em>Devices on this network</em>.</span></div>
  <div class="generated-row">
    <input id="generated_code" type="text" readonly value="<?=h($generatedCode)?>" aria-label="Generated Network Access Code">
    <button type="button" id="copy_generated">Copy code</button>
  </div>
  <small>This code is shown now because it was just generated. Keep it private.</small>
</div>
<?php endif; ?>
<?php if ($error): ?><div class="notice err"><?=h($error)?></div><?php endif; ?>
<div class="meta">
  <div><strong>STORAGE</strong><span><?= $hasCode ? 'Database setting' : ($fallbackConfigured ? 'config.php / environment fallback' : 'Generated fallback') ?></span></div>
  <div><strong>ACCESS</strong><span>Local computer only</span></div>
  <div><strong>STATUS</strong><span class="status"><?= $hasCode ? 'Configured' : 'Fallback active' ?></span></div>
</div>
<div class="field">
  <label class="label" for="network_access_code">Network Access Code</label>
  <form method="post" class="row" autocomplete="off">
    <input id="network_access_code" name="network_access_code" type="password" minlength="6" maxlength="128" placeholder="Enter a new access code" value="">
    <button type="submit" name="action" value="save">Save code</button>
    <button type="submit" name="action" value="generate" class="secondary">Generate new code</button>
  </form>
  <p class="muted">For security, the currently saved code is never displayed on this page. Saving or generating a code replaces the previous database value.</p>
</div>
<div class="divider"></div>
<h2>How to use it</h2>
<p class="muted">After saving, return to the main page, open <strong>Devices on this network</strong>, enter the new code, and click <strong>Unlock</strong>. Existing unlocked browser sessions remain unlocked until you click Lock or the session expires.</p>
<div class="notice">This admin settings page is intentionally <strong>localhost-only</strong> because changing the network access code controls access to IP/MAC discovery data. Open it on the same PC that runs PHP/XAMPP.</div>
</section>
</main>
<script>
const copyBtn=document.getElementById('copy_generated');
const generated=document.getElementById('generated_code');
if(copyBtn&&generated){copyBtn.addEventListener('click',async()=>{try{await navigator.clipboard.writeText(generated.value);copyBtn.textContent='Copied';setTimeout(()=>copyBtn.textContent='Copy code',1600)}catch(e){generated.focus();generated.select();document.execCommand('copy');copyBtn.textContent='Copied';setTimeout(()=>copyBtn.textContent='Copy code',1600)}})}
</script>
</body>
</html>
