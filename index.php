<?php
declare(strict_types=1);

require __DIR__ . '/database.php';
$config = require __DIR__ . '/config.php';

// Main/default application entry point. API requests are kept in this file
// so the project can be deployed with Apache/Nginx without a separate router.
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$basePath = ($scriptDir === '/' || $scriptDir === '.') ? '' : rtrim($scriptDir, '/');
$path = $requestPath;
if ($basePath !== '' && str_starts_with($path, $basePath)) {
    $path = substr($path, strlen($basePath)) ?: '/';
}
if ($path[0] !== '/') $path = '/' . $path;

/* ---------------------------------------------------------------------
 * Small helpers
 * ------------------------------------------------------------------- */

function rb_json(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function rb_body(): array {
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** Keep remote IDs to a safe, predictable charset. */
function rb_clean_id(string $id): string {
    $id = preg_replace('/[^A-Za-z0-9\-]/', '', $id) ?? '';
    return substr($id, 0, 64);
}

function rb_client_ip(): ?string {
    return $_SERVER['REMOTE_ADDR'] ?? null;
}

/* ---------------------------------------------------------------------
 * Health / info
 * ------------------------------------------------------------------- */

if ($path === '/health') {
    try {
        $db = db();
        $row = $db->query('SELECT 1 AS ok')->fetch_assoc();
        rb_json(['ok' => (bool)($row['ok'] ?? false), 'database' => 'mysql', 'app' => $config['app']]);
    } catch (Throwable $e) {
        rb_json(['ok' => false, 'database' => 'unavailable'], 503);
    }
}

if ($path === '/api/info') {
    rb_json([
        'name' => $config['app']['name'],
        'version' => $config['app']['version'],
        'database' => 'MySQL/MariaDB via native PHP mysqli',
        'webrtc' => true,
        'entrypoint' => 'index.php',
    ]);
}

/* ---------------------------------------------------------------------
 * Device registration — every browser tab that opens the app gets (or
 * reuses) a Remote ID. This is how "connect using remote ID" works.
 * ------------------------------------------------------------------- */

if ($path === '/api/register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = rb_body();
    $remoteId = rb_clean_id((string)($body['remote_id'] ?? ''));
    $deviceName = isset($body['device_name']) ? substr((string)$body['device_name'], 0, 255) : null;
    $db = db();

    if ($remoteId === '') {
        // Generate a fresh, human-friendly 9-digit ID (AnyDesk-style), retrying on collision.
        do {
            $remoteId = (string)random_int(100000000, 999999999);
            $chk = $db->prepare('SELECT id FROM devices WHERE remote_id = ? LIMIT 1');
            $chk->bind_param('s', $remoteId);
            $chk->execute();
            $chk->store_result();
            $exists = $chk->num_rows > 0;
            $chk->close();
        } while ($exists);
    }

    $ip = rb_client_ip();
    $stmt = $db->prepare(
        'INSERT INTO devices (remote_id, device_name, ip_address, last_seen_at)
         VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE device_name = VALUES(device_name), ip_address = VALUES(ip_address), last_seen_at = NOW()'
    );
    $stmt->bind_param('sss', $remoteId, $deviceName, $ip);
    $stmt->execute();

    rb_json(['remote_id' => $remoteId]);
}

/** Lightweight heartbeat so a device shows as "online" while its tab is open. */
if ($path === '/api/heartbeat' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = rb_body();
    $remoteId = rb_clean_id((string)($body['remote_id'] ?? ''));
    if ($remoteId === '') rb_json(['error' => 'remote_id required'], 400);
    $db = db();
    $stmt = $db->prepare('UPDATE devices SET last_seen_at = NOW() WHERE remote_id = ?');
    $stmt->bind_param('s', $remoteId);
    $stmt->execute();
    rb_json(['ok' => true]);
}

/* ---------------------------------------------------------------------
 * Sessions — pairing between an initiator (viewer) and a target (host)
 * ------------------------------------------------------------------- */

if ($path === '/api/session/create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = rb_body();
    $target = rb_clean_id((string)($body['target_remote_id'] ?? ''));
    $initiator = rb_clean_id((string)($body['initiator_remote_id'] ?? ''));
    if ($target === '' || $initiator === '') rb_json(['error' => 'target_remote_id and initiator_remote_id are required'], 400);
    if ($target === $initiator) rb_json(['error' => 'Cannot connect to your own Remote ID'], 400);

    $db = db();
    $chk = $db->prepare("SELECT remote_id FROM devices WHERE remote_id = ? AND last_seen_at > (NOW() - INTERVAL 2 MINUTE)");
    $chk->bind_param('s', $target);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows === 0) rb_json(['error' => 'That Remote ID is not online'], 404);
    $chk->close();

    $sessionId = bin2hex(random_bytes(32));
    $ip = rb_client_ip();
    $ttl = (int)$config['session_ttl'];
    $stmt = $db->prepare(
        "INSERT INTO sessions (session_id, initiator_remote_id, target_remote_id, status, initiator_ip, expires_at)
         VALUES (?, ?, ?, 'pending', ?, DATE_ADD(NOW(), INTERVAL ? SECOND))"
    );
    $stmt->bind_param('ssssi', $sessionId, $initiator, $target, $ip, $ttl);
    $stmt->execute();

    rb_json(['session_id' => $sessionId, 'status' => 'pending']);
}

/** Host polls this to see incoming connection requests awaiting Accept/Decline. */
if ($path === '/api/session/pending' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $remoteId = rb_clean_id((string)($_GET['remote_id'] ?? ''));
    if ($remoteId === '') rb_json(['error' => 'remote_id required'], 400);
    $db = db();

    $touch = $db->prepare('UPDATE devices SET last_seen_at = NOW() WHERE remote_id = ?');
    $touch->bind_param('s', $remoteId);
    $touch->execute();

    $stmt = $db->prepare(
        "SELECT session_id, initiator_remote_id, status FROM sessions
         WHERE target_remote_id = ? AND status IN ('pending','connected') AND expires_at > NOW()
         ORDER BY created_at DESC LIMIT 10"
    );
    $stmt->bind_param('s', $remoteId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    rb_json(['sessions' => $rows]);
}

/** Viewer polls this to learn whether the host accepted / declined. */
if ($path === '/api/session/status' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $sessionId = preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['session_id'] ?? ''));
    if ($sessionId === '') rb_json(['error' => 'session_id required'], 400);
    $db = db();
    $stmt = $db->prepare('SELECT status FROM sessions WHERE session_id = ? LIMIT 1');
    $stmt->bind_param('s', $sessionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) rb_json(['error' => 'Session not found'], 404);
    rb_json(['status' => $row['status']]);
}

if ($path === '/api/session/respond' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = rb_body();
    $sessionId = preg_replace('/[^A-Za-z0-9]/', '', (string)($body['session_id'] ?? ''));
    $action = (string)($body['action'] ?? '');
    if ($sessionId === '' || !in_array($action, ['accept', 'reject'], true)) rb_json(['error' => 'Invalid request'], 400);

    $db = db();
    $ip = rb_client_ip();
    if ($action === 'accept') {
        $stmt = $db->prepare("UPDATE sessions SET status='connected', connected_at=NOW(), target_ip=? WHERE session_id=? AND status='pending'");
        $stmt->bind_param('ss', $ip, $sessionId);
    } else {
        $stmt = $db->prepare("UPDATE sessions SET status='closed', closed_at=NOW() WHERE session_id=? AND status='pending'");
        $stmt->bind_param('s', $sessionId);
    }
    $stmt->execute();
    rb_json(['ok' => true]);
}

if ($path === '/api/session/close' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = rb_body();
    $sessionId = preg_replace('/[^A-Za-z0-9]/', '', (string)($body['session_id'] ?? ''));
    if ($sessionId === '') rb_json(['error' => 'session_id required'], 400);
    $db = db();
    $stmt = $db->prepare("UPDATE sessions SET status='closed', closed_at=NOW() WHERE session_id=? AND status<>'closed'");
    $stmt->bind_param('s', $sessionId);
    $stmt->execute();
    rb_json(['ok' => true]);
}

/* ---------------------------------------------------------------------
 * Signaling — WebRTC offer/answer/ICE-candidate relay. Actual video
 * never touches this server; only this small handshake does.
 * ------------------------------------------------------------------- */

if ($path === '/api/signal' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = rb_body();
    $sessionId = preg_replace('/[^A-Za-z0-9]/', '', (string)($body['session_id'] ?? ''));
    $sender = rb_clean_id((string)($body['sender_remote_id'] ?? ''));
    $type = (string)($body['type'] ?? '');
    $payload = (string)($body['payload'] ?? '');

    if ($sessionId === '' || $sender === '' || !in_array($type, ['offer','answer','candidate','hello','close'], true)) {
        rb_json(['error' => 'Invalid signal'], 400);
    }
    $maxBytes = (int)$config['max_signal_bytes'];
    if (strlen($payload) > $maxBytes) rb_json(['error' => 'Payload too large'], 413);

    $db = db();
    $stmt = $db->prepare('INSERT INTO signals (session_id, sender_remote_id, message_type, payload) VALUES (?,?,?,?)');
    $stmt->bind_param('ssss', $sessionId, $sender, $type, $payload);
    $stmt->execute();
    rb_json(['ok' => true]);
}

/** Each side polls for messages sent by the *other* party. */
if ($path === '/api/signal/poll' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $sessionId = preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['session_id'] ?? ''));
    $remoteId = rb_clean_id((string)($_GET['remote_id'] ?? ''));
    $sinceId = (int)($_GET['since_id'] ?? 0);
    if ($sessionId === '' || $remoteId === '') rb_json(['error' => 'session_id and remote_id required'], 400);

    $db = db();
    $stmt = $db->prepare(
        'SELECT id, sender_remote_id, message_type, payload FROM signals
         WHERE session_id = ? AND sender_remote_id <> ? AND id > ?
         ORDER BY id ASC LIMIT 50'
    );
    $stmt->bind_param('ssi', $sessionId, $remoteId, $sinceId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    rb_json(['signals' => $rows]);
}

/* ---------------------------------------------------------------------
 * Everything else falls through to the app shell.
 * ------------------------------------------------------------------- */

if ($path !== '/' && $path !== '/index.php') {
    rb_json(['error' => 'Not found'], 404);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="description" content="RemoteBridge — web-based remote desktop over WebRTC">
<title>RemoteBridge</title>
<style>
:root{font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#eaf1ff;background:#07101f}
*{box-sizing:border-box}body{margin:0;min-height:100vh;background:radial-gradient(circle at 20% 10%,#15315a 0,#07101f 42%,#050a13 100%)}
main{width:min(1120px,calc(100% - 32px));margin:40px auto 60px}
.top{display:flex;justify-content:space-between;gap:20px;align-items:center;margin-bottom:24px;flex-wrap:wrap}
h1{margin:0;font-size:clamp(26px,5vw,42px)}.sub{color:#9db0ca;margin:6px 0 0}
.status{border:1px solid #2c4262;background:#0e1b2f;border-radius:999px;padding:9px 14px;font-size:13px;white-space:nowrap}
.tabs{display:flex;gap:8px;margin-bottom:18px}
.tab{padding:10px 18px;border-radius:11px;background:#0e1b2f;border:1px solid #253b5a;color:#cfe0f7;cursor:pointer;font-weight:600}
.tab.active{background:#2b6de8;border-color:#2b6de8;color:#fff}
.card{background:rgba(14,27,47,.88);border:1px solid #253b5a;border-radius:20px;padding:24px;box-shadow:0 20px 70px rgba(0,0,0,.25);margin-bottom:18px}
.card h2{margin:0 0 6px}.muted{color:#9db0ca;font-size:14px}
.remoteid{font-family:ui-monospace,monospace;font-size:32px;letter-spacing:2px;background:#07101c;border:1px solid #1e3049;border-radius:12px;padding:14px 18px;margin:14px 0;text-align:center}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
input[type=text]{flex:1;min-width:220px;padding:12px 14px;border-radius:11px;border:1px solid #253b5a;background:#07101c;color:#eaf1ff;font-family:ui-monospace,monospace;font-size:18px;letter-spacing:1px}
button{border:0;border-radius:11px;padding:11px 16px;background:#2b6de8;color:white;font-weight:700;cursor:pointer}
button.secondary{background:#20324d}button.danger{background:#c0392b}button:disabled{opacity:.5;cursor:not-allowed}
.request{display:flex;justify-content:space-between;align-items:center;gap:10px;background:#0a1729;border:1px solid #1b304c;border-radius:12px;padding:12px 16px;margin-top:10px}
video{width:100%;border-radius:14px;background:#000;border:1px solid #1e3049;display:block}
.hidden{display:none !important}
.log{margin-top:14px;padding:13px;border-radius:12px;background:#07101c;border:1px solid #1e3049;color:#9db0ca;font-family:ui-monospace,monospace;font-size:12.5px;max-height:160px;overflow:auto;white-space:pre-wrap}
@media(max-width:760px){.top{flex-direction:column;align-items:flex-start}}
</style>
</head>
<body>
<main>
  <div class="top">
    <div><h1>RemoteBridge</h1><p class="sub">Web-based remote desktop over WebRTC — no install, connect with a Remote ID</p></div>
    <div id="status" class="status">Checking database…</div>
  </div>

  <div class="tabs">
    <button class="tab active" id="tabHost" onclick="rbShowTab('host')">Share my screen</button>
    <button class="tab" id="tabViewer" onclick="rbShowTab('viewer')">Connect to a Remote ID</button>
  </div>

  <section id="panelHost" class="card">
    <h2>Your Remote ID</h2>
    <p class="muted">Share this ID with the person who needs to connect to <em>this</em> computer. Nothing is shared until you click Accept below.</p>
    <div id="myRemoteId" class="remoteid">…</div>
    <div class="row">
      <button id="btnShareScreen" class="secondary" onclick="rbToggleSharePresence()">Go online</button>
    </div>
    <div id="incomingRequests"></div>
    <div id="hostPreviewWrap" class="hidden" style="margin-top:16px">
      <p class="muted">You are sharing your screen:</p>
      <video id="hostPreview" autoplay muted playsinline></video>
      <label class="row" style="margin-top:12px;cursor:pointer">
        <input type="checkbox" id="allowControl"> Allow the connected viewer to control this mouse &amp; keyboard
      </label>
      <div class="row" style="margin-top:10px"><button class="danger" onclick="rbStopHosting()">Stop sharing</button></div>
    </div>

    <div class="card" style="margin-top:18px;padding:18px">
      <h2 style="font-size:18px">Native control agent (required for remote control)</h2>
      <p class="muted">A browser tab can't move your OS mouse or type into other apps by itself. To allow real control, run the small local agent below and connect it here. Only do this if you trust the person who will be controlling this computer.</p>
      <div class="code">cd agent &amp;&amp; npm install &amp;&amp; node control-agent.js</div>
      <div class="row" style="margin-top:10px">
        <input type="text" id="agentToken" placeholder="Paste token printed by the agent">
        <button class="secondary" onclick="rbConnectAgent()">Connect</button>
      </div>
      <p id="agentStatus" class="muted" style="margin-top:8px">Native control agent: not connected</p>
    </div>
  </section>

  <section id="panelViewer" class="card hidden">
    <h2>Connect to a Remote ID</h2>
    <p class="muted">Enter the Remote ID shown on the other computer, then request a connection. The other side must accept before you see anything.</p>
    <div class="row">
      <input type="text" id="targetRemoteId" placeholder="e.g. 384920571" maxlength="64">
      <button id="btnConnect" onclick="rbConnectToRemote()">Connect</button>
    </div>
    <div id="viewerState" class="muted" style="margin-top:12px"></div>
    <div id="viewerVideoWrap" class="hidden" style="margin-top:16px">
      <video id="viewerVideo" autoplay playsinline></video>
      <div class="row" style="margin-top:10px"><button class="danger" onclick="rbDisconnect()">Disconnect</button></div>
    </div>
  </section>

  <section class="card">
    <h2>Activity log</h2>
    <div id="log" class="log">Ready.</div>
  </section>
</main>
<script>
const RB_BASE = <?= json_encode($basePath, JSON_UNESCAPED_SLASHES) ?>;
function rbUrl(p){ return RB_BASE + p; }
async function checkHealth(){
  const s=document.getElementById('status');
  try{const r=await fetch(rbUrl('/health'),{cache:'no-store'});const j=await r.json();
    s.textContent=j.ok?'Database online':'Database unavailable';
  }catch(e){s.textContent='Server unavailable';}
}
checkHealth();
function rbShowTab(which){
  document.getElementById('tabHost').classList.toggle('active', which==='host');
  document.getElementById('tabViewer').classList.toggle('active', which==='viewer');
  document.getElementById('panelHost').classList.toggle('hidden', which!=='host');
  document.getElementById('panelViewer').classList.toggle('hidden', which!=='viewer');
}
</script>
<script src="<?= htmlspecialchars($basePath) ?>/public/app.js"></script>
</body>
</html>
