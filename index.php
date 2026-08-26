<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

// Main/default application entry point. API requests are kept in this file
// so the project can be deployed with Apache/Nginx without a separate router.
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
[$path, $basePath] = rb_resolve_route($requestPath);

/* ---------------------------------------------------------------------
 * Small helpers
 *
 * Auth, session, and rate-limit helpers (rb_json, rb_client_ip,
 * rb_audit_log, rb_get_current_user, rb_require_auth, rb_require_admin,
 * rb_attempt_login, ...) live in includes/bootstrap.php, shared with
 * login.php. Everything below is specific to routing requests in this file.
 * ------------------------------------------------------------------- */

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

/** Browser Remote IDs are intentionally 9 numeric digits. Older builds could
 * send the literal string "undefined" from stale localStorage; never accept
 * that value as a real device identity. */
function rb_valid_remote_id(string $id): bool {
    return (bool)preg_match('/^\d{9}$/', $id);
}

/** These endpoints spawn a local OS process, so they must never be reachable
 * over the network — only from the same machine the PHP server is running on. */
function rb_require_local(): void {
    $ip = rb_client_ip();
    if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
        rb_json(['error' => 'This action is only allowed from localhost'], 403);
    }
}

/** Network discovery is safe to expose to a browser on the same LAN as the
 * PHP server, because the scan is still executed by this server. It must not
 * be opened to arbitrary internet clients. */
function rb_require_same_lan(): void {
    $client = rb_client_ip();
    if (in_array($client, ['127.0.0.1', '::1'], true)) return;
    if (!filter_var($client, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        rb_json(['error' => 'Network scan is available only from the local LAN'], 403);
    }
    $network = rb_local_network();
    if (!$network || !filter_var($network['first'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        rb_json(['error' => 'Could not determine the server LAN'], 500);
    }
    $ipLong = ip2long($client);
    $networkLong = ip2long($network['network']);
    $maskLong = ip2long($network['mask']);
    if ($ipLong === false || $networkLong === false || $maskLong === false || (($ipLong & $maskLong) !== ($networkLong & $maskLong))) {
        rb_json(['error' => 'Network scan is available only to clients on the same LAN as this server'], 403);
    }
}

function rb_pid_alive(int $pid): bool {
    if ($pid <= 0) return false;
    if (stripos(PHP_OS, 'WIN') === 0) {
        $out = shell_exec('tasklist /FI "PID eq ' . $pid . '" 2>NUL');
        return $out !== null && strpos($out, (string)$pid) !== false;
    }
    return posix_kill($pid, 0);
}

/** Whether something is actually listening on the agent's host:port.
 *
 * A PID existing (rb_pid_alive) is NOT proof the agent is actually up: on
 * Windows in particular, PIDs get recycled quickly, so a stale
 * agent-run.pid left over from a previous run (crash, reboot, `taskkill`
 * outside the app, etc.) can point at a completely unrelated process that
 * now happens to reuse the same PID. When that happens, /api/agent/start
 * previously trusted the stale PID, reported "already_running": true, and
 * never actually launched a new agent — so the page's "Start" button says
 * Running while nothing is listening on 8791 and Connect always fails.
 * Checking the port directly is the only reliable signal. */
function rb_port_open(string $host, int $port, float $timeoutSec = 0.35): bool {
    $target = ($host === '0.0.0.0' || $host === '' || $host === '::') ? '127.0.0.1' : $host;
    $conn = @stream_socket_client('tcp://' . $target . ':' . $port, $errno, $errstr, $timeoutSec);
    if ($conn) { fclose($conn); return true; }
    return false;
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

/** Public connection diagnostics. No credentials or database passwords are exposed. */
if ($path === '/api/agent/config' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $agent = $config['agent'] ?? [];
    $bindHost = (string)($agent['host'] ?? '127.0.0.1');
    $port = (int)($agent['port'] ?? 8791);
    $lanEnabled = $bindHost === '0.0.0.0' || $bindHost === '::' || $bindHost === '';
    rb_json([
        'host' => $bindHost,
        'port' => $port,
        'lan_enabled' => $lanEnabled,
        'protocol' => 'ws',
        'local_only' => !$lanEnabled,
        'page_host' => $_SERVER['HTTP_HOST'] ?? null,
    ]);
}

/** ICE server list for WebRTC — STUN always, plus TURN so peers behind
 * restrictive/symmetric NAT (common off-LAN) can still connect. Uses a
 * custom TURN server if one is configured, otherwise falls back to the
 * Open Relay Project's free shared TURN server so internet connections
 * work with zero setup. The client uses this (rather than a hardcoded
 * list) so switching a deployment between Local Network and Internet
 * mode is a server-side config change, not a code change. */
if ($path === '/api/ice-servers') {
    $servers = [['urls' => 'stun:stun.l.google.com:19302']];
    $turn = $config['turn'] ?? [];
    $turnSource = 'none';

    if (!empty($turn['url'])) {
        $servers[] = [
            'urls' => $turn['url'],
            'username' => $turn['username'],
            'credential' => $turn['credential'],
        ];
        $turnSource = 'custom';
    } elseif (!empty($turn['use_free_fallback'])) {
        // Open Relay Project (metered.ca) — free, shared, 20GB/month pool.
        // Public by design; not a secret. Runs on 80/443 to get through
        // most corporate firewalls too.
        foreach (['turn:openrelay.metered.ca:80', 'turn:openrelay.metered.ca:443', 'turns:openrelay.metered.ca:443'] as $u) {
            $servers[] = ['urls' => $u, 'username' => 'openrelayproject', 'credential' => 'openrelayproject'];
        }
        $turnSource = 'free-fallback';
    }

    rb_json(['ice_servers' => $servers, 'turn_source' => $turnSource]);
}

/* ---------------------------------------------------------------------
 * Auth — login/logout/me. Everything past this point that touches a
 * device, a session, or the network scanner requires a logged-in user.
 * ------------------------------------------------------------------- */

if ($path === '/api/auth/login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = rb_body();
    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');

    $result = rb_attempt_login(db(), $username, $password);
    if (!$result['ok']) {
        $errorsByCode = [
            'missing' => ['Username and password are required', 400],
            'rate_limited' => ['Too many attempts. Try again in a few minutes.', 429],
            'invalid' => ['Invalid username or password', 401],
        ];
        [$message, $code] = $errorsByCode[$result['error']] ?? ['Login failed', 400];
        rb_json(['error' => $message], $code);
    }

    rb_json(['ok' => true, 'user' => $result['user']]);
}

if ($path === '/api/auth/logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = rb_get_current_user();
    if ($user) rb_audit_log(db(), 'logout', null, ['username' => $user['username']]);
    $_SESSION = [];
    session_destroy();
    rb_json(['ok' => true]);
}

if ($path === '/api/auth/me' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = rb_get_current_user();
    if (!$user) rb_json(['authenticated' => false], 401);
    rb_json(['authenticated' => true, 'user' => $user]);
}

/* ---------------------------------------------------------------------
 * Admin — user management + audit log. Everything here requires the
 * admin role on top of being logged in.
 * ------------------------------------------------------------------- */

function rb_valid_username(string $u): bool {
    return (bool)preg_match('/^[A-Za-z0-9_.\-]{3,60}$/', $u);
}

if ($path === '/api/admin/users' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    rb_require_admin();
    $db = db();
    $rows = $db->query(
        'SELECT id, username, display_name, role, is_active, created_at, last_login_at FROM users ORDER BY created_at ASC'
    )->fetch_all(MYSQLI_ASSOC);
    rb_json(['users' => $rows]);
}

if ($path === '/api/admin/users/create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin = rb_require_admin();
    $body = rb_body();
    $username = trim((string)($body['username'] ?? ''));
    $displayName = trim((string)($body['display_name'] ?? '')) ?: $username;
    $password = (string)($body['password'] ?? '');
    $role = (string)($body['role'] ?? 'user');

    if (!rb_valid_username($username)) {
        rb_json(['error' => 'Username must be 3-60 characters: letters, numbers, dot, dash, underscore'], 400);
    }
    if (strlen($password) < 8) {
        rb_json(['error' => 'Password must be at least 8 characters'], 400);
    }
    if (!in_array($role, ['admin', 'user'], true)) {
        rb_json(['error' => 'Role must be admin or user'], 400);
    }

    $db = db();
    $chk = $db->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $chk->bind_param('s', $username);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows > 0) rb_json(['error' => 'That username is already taken'], 409);
    $chk->close();

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
    $stmt = $db->prepare('INSERT INTO users (username, display_name, password_hash, role, is_active) VALUES (?,?,?,?,1)');
    $stmt->bind_param('ssss', $username, $displayName, $hash, $role);
    $stmt->execute();
    $newId = (int)$stmt->insert_id;

    rb_audit_log($db, 'user_created', null, ['by' => $admin['username'], 'new_user' => $username, 'role' => $role]);
    rb_json(['ok' => true, 'id' => $newId]);
}

if ($path === '/api/admin/users/update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin = rb_require_admin();
    $body = rb_body();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) rb_json(['error' => 'id required'], 400);

    $db = db();
    $target = $db->prepare('SELECT id, username, role FROM users WHERE id = ? LIMIT 1');
    $target->bind_param('i', $id);
    $target->execute();
    $targetRow = $target->get_result()->fetch_assoc();
    if (!$targetRow) rb_json(['error' => 'User not found'], 404);

    $fields = [];
    $types = '';
    $values = [];

    if (array_key_exists('display_name', $body)) {
        $fields[] = 'display_name = ?';
        $types .= 's';
        $values[] = trim((string)$body['display_name']);
    }
    if (array_key_exists('role', $body)) {
        $role = (string)$body['role'];
        if (!in_array($role, ['admin', 'user'], true)) rb_json(['error' => 'Role must be admin or user'], 400);
        if ($id === $admin['id'] && $role !== 'admin') {
            rb_json(['error' => "You can't remove your own admin role"], 400);
        }
        $fields[] = 'role = ?';
        $types .= 's';
        $values[] = $role;
    }
    if (array_key_exists('is_active', $body)) {
        if ($id === $admin['id'] && empty($body['is_active'])) {
            rb_json(['error' => "You can't deactivate your own account"], 400);
        }
        $fields[] = 'is_active = ?';
        $types .= 'i';
        $values[] = !empty($body['is_active']) ? 1 : 0;
    }

    if (!$fields) rb_json(['error' => 'Nothing to update'], 400);

    $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?';
    $types .= 'i';
    $values[] = $id;
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();

    rb_audit_log($db, 'user_updated', null, ['by' => $admin['username'], 'target' => $targetRow['username'], 'changes' => array_keys($body)]);
    rb_json(['ok' => true]);
}

if ($path === '/api/admin/users/reset-password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin = rb_require_admin();
    $body = rb_body();
    $id = (int)($body['id'] ?? 0);
    $password = (string)($body['password'] ?? '');
    if ($id <= 0) rb_json(['error' => 'id required'], 400);
    if (strlen($password) < 8) rb_json(['error' => 'Password must be at least 8 characters'], 400);

    $db = db();
    $target = $db->prepare('SELECT id, username FROM users WHERE id = ? LIMIT 1');
    $target->bind_param('i', $id);
    $target->execute();
    $targetRow = $target->get_result()->fetch_assoc();
    if (!$targetRow) rb_json(['error' => 'User not found'], 404);

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
    $stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $stmt->bind_param('si', $hash, $id);
    $stmt->execute();

    rb_audit_log($db, 'password_reset', null, ['by' => $admin['username'], 'target' => $targetRow['username']]);
    rb_json(['ok' => true]);
}

if ($path === '/api/admin/audit-log' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    rb_require_admin();
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
    $db = db();
    $stmt = $db->prepare('SELECT event_type, remote_id, ip_address, details, created_at FROM audit_log ORDER BY id DESC LIMIT ?');
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    rb_json(['entries' => $rows]);
}

/* ---------------------------------------------------------------------
 * Device registration — every browser tab that opens the app gets (or
 * reuses) a Remote ID. This is how "connect using remote ID" works.
 * ------------------------------------------------------------------- */

if (!function_exists('rb_ensure_presence_schema')) {
    function rb_ensure_presence_schema(mysqli $db): void {
        $check = $db->query("SHOW COLUMNS FROM devices LIKE 'is_online'");
        $exists = $check && $check->num_rows > 0;
        if ($check) $check->close();
        if (!$exists) {
            $db->query("ALTER TABLE devices ADD COLUMN is_online TINYINT(1) NOT NULL DEFAULT 0 AFTER last_seen_at");
        }
    }
}

if ($path === '/api/register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth = rb_require_auth();
    $body = rb_body();
    $remoteId = rb_clean_id((string)($body['remote_id'] ?? ''));
    $deviceName = isset($body['device_name']) ? substr((string)$body['device_name'], 0, 255) : null;
    $db = db();
    rb_ensure_presence_schema($db);

    // Ignore invalid/stale IDs (including the literal "undefined") and issue
    // a fresh 9-digit identity instead. This keeps old browser storage from
    // poisoning the Remote ID displayed in the UI.
    if (!rb_valid_remote_id($remoteId)) {
        $remoteId = '';
    }

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
    // Registration identifies the browser only. It must NOT make the device
    // online automatically. Presence is controlled explicitly by the
    // Go online / Go offline button and its heartbeat.
    // Keep manual presence in its own persistent column. Re-registering on
    // a page refresh must NEVER reset an explicitly selected Online state.
    $stmt = $db->prepare(
        'INSERT INTO devices (user_id, remote_id, device_name, ip_address, last_seen_at, is_online)
         VALUES (?, ?, ?, ?, NULL, 0)
         ON DUPLICATE KEY UPDATE device_name = VALUES(device_name), ip_address = VALUES(ip_address), user_id = VALUES(user_id)'
    );
    $stmt->bind_param('isss', $auth['id'], $remoteId, $deviceName, $ip);
    $stmt->execute();

    rb_audit_log($db, 'device_registered', $remoteId, ['user_id' => $auth['id']]);

    $status = $db->prepare('SELECT is_online FROM devices WHERE remote_id = ? LIMIT 1');
    $status->bind_param('s', $remoteId);
    $status->execute();
    $statusRow = $status->get_result()->fetch_assoc();
    rb_json(['remote_id' => $remoteId, 'online' => !empty($statusRow['is_online'])]);
}

/** Lightweight heartbeat so a device shows as "online" while its tab is open. */
if ($path === '/api/heartbeat' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    rb_require_auth();
    $body = rb_body();
    $remoteId = rb_clean_id((string)($body['remote_id'] ?? ''));
    if (!rb_valid_remote_id($remoteId)) rb_json(['error' => 'valid remote_id required'], 400);
    $db = db();
    $ip = rb_client_ip();
    $stmt = $db->prepare('UPDATE devices SET ip_address = ?, last_seen_at = NOW(), is_online = 1 WHERE remote_id = ?');
    $stmt->bind_param('ss', $ip, $remoteId);
    $stmt->execute();
    rb_json(['ok' => true, 'online' => true]);
}

/** Explicitly mark a device offline. This is called only when the user
 * presses Go offline; page refresh/close deliberately does not call it. */
if ($path === '/api/offline' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    rb_require_auth();
    $body = rb_body();
    $remoteId = rb_clean_id((string)($body['remote_id'] ?? ''));
    if (!rb_valid_remote_id($remoteId)) rb_json(['error' => 'valid remote_id required'], 400);
    $db = db();
    $stmt = $db->prepare('UPDATE devices SET last_seen_at = NULL, is_online = 0 WHERE remote_id = ?');
    $stmt->bind_param('s', $remoteId);
    $stmt->execute();
    rb_json(['ok' => true, 'online' => false]);
}

/** Looks up the device name + last-seen for a Remote ID, so each side of a
 * session (or an incoming request) can show what device the other party is
 * using, not just their numeric ID. Local peers are cross-referenced against
 * this server's ARP cache so a same-LAN device can expose its MAC address.
 * Off-LAN / internet peers simply won't have a MAC. */
if ($path === '/api/device/lookup' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $remoteId = rb_clean_id((string)($_GET['remote_id'] ?? ''));
    if ($remoteId === '') rb_json(['error' => 'remote_id required'], 400);
    $db = db();
    $stmt = $db->prepare('SELECT remote_id, device_name, ip_address, last_seen_at, is_online FROM devices WHERE remote_id = ? LIMIT 1');
    $stmt->bind_param('s', $remoteId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) rb_json(['error' => 'Not found'], 404);

    $mac = null;
    $onLocalNetwork = false;
    if (!empty($row['ip_address'])) {
        foreach (rb_arp_table() as $arpEntry) {
            if ($arpEntry['ip'] === $row['ip_address']) {
                $mac = $arpEntry['mac'];
                $onLocalNetwork = true;
                break;
            }
        }
    }

    rb_json([
        'remote_id' => $row['remote_id'],
        'device_name' => $row['device_name'] ?: 'Unknown device',
        'ip_address' => $row['ip_address'],
        'mac_address' => $mac,
        'on_local_network' => $onLocalNetwork,
        'last_seen_at' => $row['last_seen_at'],
        'online' => !empty($row['is_online']),
    ]);
}

/** Recently-connected devices for the logged-in account — every browser/
 * computer that has registered a Remote ID under this user, most recently
 * active first. Shown in the left sidebar so someone using RemoteBridge from
 * several machines can tell them apart (and rename them, below). Scoped to
 * the caller's own devices; nothing here reveals other accounts' devices. */
if ($path === '/api/devices/recent' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $auth = rb_require_auth();
    $db = db();
    $stmt = $db->prepare(
        'SELECT remote_id, device_name, ip_address, last_seen_at, is_online, created_at
         FROM devices
         WHERE user_id = ?
         ORDER BY is_online DESC, (last_seen_at IS NULL), last_seen_at DESC, created_at DESC
         LIMIT 12'
    );
    $stmt->bind_param('i', $auth['id']);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    rb_json(['devices' => $rows, 'count' => count($rows)]);
}

/** Rename one of the caller's own devices (admins may rename any device).
 * This only ever touches the existing `device_name` column — it's the same
 * field already shown everywhere else (connection tiles, lookups, etc.). */
if ($path === '/api/devices/rename' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth = rb_require_auth();
    $body = rb_body();
    $remoteId = rb_clean_id((string)($body['remote_id'] ?? ''));
    if (!rb_valid_remote_id($remoteId)) rb_json(['error' => 'valid remote_id required'], 400);

    $name = trim((string)($body['device_name'] ?? ''));
    // Strip control/newline characters and collapse whitespace; this is a
    // display label, not free-form text.
    $name = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $name);
    $name = preg_replace('/\s+/', ' ', trim((string)$name));
    if ($name === '') rb_json(['error' => 'Device name is required'], 400);
    if (mb_strlen($name) > 80) $name = mb_substr($name, 0, 80);

    $db = db();
    $find = $db->prepare('SELECT user_id, device_name FROM devices WHERE remote_id = ? LIMIT 1');
    $find->bind_param('s', $remoteId);
    $find->execute();
    $row = $find->get_result()->fetch_assoc();
    if (!$row) rb_json(['error' => 'Device not found'], 404);

    $isOwner = $row['user_id'] !== null && (int)$row['user_id'] === (int)$auth['id'];
    if (!$isOwner && $auth['role'] !== 'admin') {
        rb_json(['error' => 'You can only rename your own devices'], 403);
    }

    $update = $db->prepare('UPDATE devices SET device_name = ? WHERE remote_id = ?');
    $update->bind_param('ss', $name, $remoteId);
    $update->execute();

    rb_audit_log($db, 'device_renamed', $remoteId, [
        'by_user_id' => $auth['id'],
        'previous_name' => $row['device_name'],
        'new_name' => $name,
    ]);

    rb_json(['ok' => true, 'remote_id' => $remoteId, 'device_name' => $name]);
}

/* ---------------------------------------------------------------------
 * Sessions — pairing between an initiator (viewer) and a target (host)
 * ------------------------------------------------------------------- */

if ($path === '/api/session/create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    rb_require_auth();
    $body = rb_body();
    $target = rb_clean_id((string)($body['target_remote_id'] ?? ''));
    $initiator = rb_clean_id((string)($body['initiator_remote_id'] ?? ''));
    if ($target === '' || $initiator === '') rb_json(['error' => 'target_remote_id and initiator_remote_id are required'], 400);
    if ($target === $initiator) rb_json(['error' => 'Cannot connect to your own Remote ID'], 400);

    $db = db();
    $chk = $db->prepare("SELECT remote_id FROM devices WHERE remote_id = ? AND is_online = 1");
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

    $touch = $db->prepare('UPDATE devices SET last_seen_at = NOW() WHERE remote_id = ? AND is_online = 1');
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
    rb_require_auth();
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
 * Native control agent — lets the page start/stop `cd agent && npm
 * install && node control-agent.js` itself, with output streamed back
 * to an in-page console, so the host never has to open a terminal.
 * Local-machine only: the agent binds to 127.0.0.1 and so does this.
 * ------------------------------------------------------------------- */

$rbAgentDir = __DIR__ . '/agent';
$rbAgentLog = $rbAgentDir . '/agent-run.log';
$rbAgentPidFile = $rbAgentDir . '/agent-run.pid';
$rbAgentIsWindows = stripos(PHP_OS, 'WIN') === 0;

if ($path === '/api/agent/start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    rb_require_local();
    rb_require_auth();

    $agentConfigPre = $config['agent'] ?? [];
    $preHost = (string)($agentConfigPre['host'] ?? '127.0.0.1');
    $prePort = (int)($agentConfigPre['port'] ?? 8791);

    if (is_file($rbAgentPidFile)) {
        $existingPid = (int)trim((string)file_get_contents($rbAgentPidFile));
        // Require BOTH a live PID and an actually-listening port. A PID alone
        // is not trustworthy (see rb_port_open() above) — trusting it here
        // is what previously made the UI report "already running" for an
        // agent that was not actually reachable.
        if (rb_pid_alive($existingPid) && rb_port_open($preHost, $prePort)) {
            rb_json(['ok' => true, 'already_running' => true, 'pid' => $existingPid]);
        }
        // Stale/incorrect PID file — remove it and fall through to start fresh.
        @unlink($rbAgentPidFile);
    }

    if (!is_dir($rbAgentDir)) rb_json(['error' => 'agent/ directory not found'], 500);

    file_put_contents($rbAgentLog, "=== starting: cd agent && npm install && node control-agent.js (" . date('c') . ") ===\n");

    $agentConfig = $config['agent'] ?? [];
    $agentHost = (string)($agentConfig['host'] ?? '127.0.0.1');
    $agentPort = (int)($agentConfig['port'] ?? 8791);

    if ($rbAgentIsWindows) {
        // cmd.exe does not treat single quotes as path quoting. The previous
        // launcher therefore failed on common XAMPP paths such as C:\xampp\htdocs.
        $safeHost = preg_replace('/[^A-Za-z0-9_.:\-]/', '', $agentHost) ?: '127.0.0.1';
        $agentDirCmd = '"' . str_replace('"', '', $rbAgentDir) . '"';
        $agentLogCmd = '"' . str_replace('"', '', $rbAgentLog) . '"';
        // Do not wrap the whole /C command in another pair of quotes: nested
        // quoted Windows paths would otherwise terminate cmd.exe's command
        // string early. The paths themselves remain quoted.
        $cmd = 'cmd.exe /D /C set RB_AGENT_HOST=' . $safeHost . '&& set RB_AGENT_PORT=' . $agentPort
             . '&& cd /d ' . $agentDirCmd
             . ' && if exist node_modules\.bin\node.cmd (node control-agent.js) else (npm install --no-audit --no-fund && node control-agent.js)'
             . ' 1>>' . $agentLogCmd . ' 2>&1';
    } else {
        // exec replaces the shell with node once npm install finishes, so the
        // PID we capture below stays valid for the whole lifetime of the agent.
        $cmd = 'cd ' . escapeshellarg($rbAgentDir)
             . ' && npm install >> ' . escapeshellarg($rbAgentLog) . ' 2>&1'
             . ' && RB_AGENT_HOST=' . escapeshellarg($agentHost) . ' RB_AGENT_PORT=' . $agentPort
             . ' exec node control-agent.js >> ' . escapeshellarg($rbAgentLog) . ' 2>&1';
    }

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($cmd, $descriptors, $pipes, $rbAgentDir);
    if (!is_resource($process)) rb_json(['error' => 'Failed to start process'], 500);
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $status = proc_get_status($process);
    $pid = (int)($status['pid'] ?? 0);
    file_put_contents($rbAgentPidFile, (string)$pid);

    rb_json(['ok' => true, 'pid' => $pid]);
}

/** Polled by the page's in-browser console to tail the agent's output. */
if ($path === '/api/agent/output' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    rb_require_local();
    rb_require_auth();
    $offset = max(0, (int)($_GET['offset'] ?? 0));

    $running = false;
    if (is_file($rbAgentPidFile)) {
        $agentConfigOut = $config['agent'] ?? [];
        $outHost = (string)($agentConfigOut['host'] ?? '127.0.0.1');
        $outPort = (int)($agentConfigOut['port'] ?? 8791);
        $pidFromFile = (int)trim((string)file_get_contents($rbAgentPidFile));
        // Same reasoning as /api/agent/start: only trust the PID once the
        // port is confirmed listening, otherwise a stale/reused PID makes
        // the console falsely claim the agent is still running.
        $running = rb_pid_alive($pidFromFile) && rb_port_open($outHost, $outPort);
    }

    $chunk = '';
    $newOffset = $offset;
    if (is_file($rbAgentLog)) {
        $size = filesize($rbAgentLog);
        if ($size !== false && $size > $offset) {
            $fh = fopen($rbAgentLog, 'rb');
            fseek($fh, $offset);
            $chunk = (string)fread($fh, $size - $offset);
            fclose($fh);
            $newOffset = $size;
        } elseif ($size !== false) {
            $newOffset = $size;
        }
    }

    rb_json(['running' => $running, 'chunk' => $chunk, 'offset' => $newOffset]);
}

if ($path === '/api/agent/stop' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    rb_require_local();
    rb_require_auth();
    $pid = is_file($rbAgentPidFile) ? (int)trim((string)file_get_contents($rbAgentPidFile)) : 0;
    if ($pid > 0 && rb_pid_alive($pid)) {
        if ($rbAgentIsWindows) {
            shell_exec('taskkill /T /F /PID ' . $pid . ' 2>NUL');
        } else {
            posix_kill($pid, SIGTERM);
        }
    }
    @unlink($rbAgentPidFile);
    rb_json(['ok' => true]);
}

/* ---------------------------------------------------------------------
 * Local network devices — reads this machine's ARP cache (IP + MAC of
 * everything it has recently talked to on the LAN) for the sidebar tile
 * view. Local-machine only, same reasoning as the agent endpoints above:
 * this reflects whoever is running the PHP server, not the browser's own
 * network, and a remote client has no business reading it.
 * ------------------------------------------------------------------- */

function rb_mac_looks_valid(string $mac): bool {
    return (bool)preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/i', $mac) && strtolower($mac) !== '00:00:00:00:00:00';
}

/** Hostname lookups are intentionally not performed during a scan. Reverse DNS
 * can block for many seconds on a LAN with no DNS server, making discovery
 * appear to freeze. Device names are therefore taken from native ARP/NetBIOS
 * output when the OS provides them; otherwise the UI uses the IP address. */
function rb_arp_table(): array {
    $devices = [];
    $isWindows = stripos(PHP_OS, 'WIN') === 0;

    if ($isWindows) {
        // Windows 10/11 keeps a richer neighbor table than `arp -a`, including
        // entries that are stale/reachable but not printed in the legacy ARP
        // format. Read both sources and merge them.
        $ps = 'powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command "Get-NetNeighbor -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object {$_.AddressFamily -eq 2 -and $_.LinkLayerAddress} | ForEach-Object {$_.IPAddress + [char]124 + $_.LinkLayerAddress + [char]124 + $_.State}" 2>NUL';
        $neighborOut = (string)shell_exec($ps);
        foreach (explode("\n", $neighborOut) as $line) {
            $parts = array_map('trim', explode('|', trim($line)));
            if (count($parts) < 2) continue;
            $ip = $parts[0];
            $mac = strtolower(str_replace('-', ':', $parts[1]));
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !rb_mac_looks_valid($mac)) continue;
            $devices[$ip] = ['ip' => $ip, 'mac' => $mac, 'type' => strtolower($parts[2] ?? '') === 'static' ? 'static' : 'dynamic'];
        }

        $out = shell_exec('arp -a 2>NUL') ?: '';
        foreach (explode("\n", $out) as $line) {
            if (preg_match('/^\s*(\d{1,3}(?:\.\d{1,3}){3})\s+([0-9a-fA-F-]{17})\s+(\S+)/', $line, $m)) {
                $mac = strtolower(str_replace('-', ':', $m[2]));
                if (!rb_mac_looks_valid($mac)) continue;
                $devices[$m[1]] = ['ip' => $m[1], 'mac' => $mac, 'type' => strtolower($m[3]) === 'static' ? 'static' : 'dynamic'];
            }
        }
    } elseif (is_readable('/proc/net/arp')) {
        // IP address / HW type / Flags / HW address / Mask / Device
        $lines = file('/proc/net/arp', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        array_shift($lines);
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) < 6) continue;
            [$ip, , $flags, $mac, , $dev] = $parts;
            if ($flags === '0x0' || !rb_mac_looks_valid($mac)) continue; // incomplete entry
            $devices[$ip] = ['ip' => $ip, 'mac' => strtolower($mac), 'iface' => $dev];
        }
    } else {
        // macOS / other BSD-ish unix
        $out = shell_exec('arp -a 2>/dev/null') ?: '';
        foreach (explode("\n", $out) as $line) {
            if (preg_match('/^(\S+)\s+\(([\d.]+)\)\s+at\s+([0-9a-fA-F:]{17}|\(incomplete\))/', $line, $m)) {
                if ($m[3] === '(incomplete)' || !rb_mac_looks_valid($m[3])) continue;
                $devices[$m[2]] = ['ip' => $m[2], 'mac' => strtolower($m[3]), 'hostname' => $m[1] !== '?' ? $m[1] : null];
            }
        }
    }

    $list = array_values($devices);
    foreach ($list as &$entry) {
        if (empty($entry['hostname'])) {
            $entry['hostname'] = 'Device ' . (string)$entry['ip'];
        }
    }
    unset($entry);
    usort($list, fn($a, $b) => (ip2long($a['ip']) ?: 0) <=> (ip2long($b['ip']) ?: 0));
    return $list;
}

/** Find this server's real LAN IPv4 address even when the page is opened
 * through http://localhost. This is important because SERVER_ADDR is often
 * 127.0.0.1 in that case, which previously caused every remote LAN user to be
 * rejected by the local-network check. */
function rb_server_lan_ipv4(): ?string {
    $server = $_SERVER['SERVER_ADDR'] ?? '';
    if ($server && filter_var($server, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        && !in_array($server, ['127.0.0.1', '0.0.0.0'], true)) {
        return $server;
    }

    // Prefer the interface that owns the default route. This avoids selecting
    // VMware/VirtualBox/VPN adapters before the real Wi-Fi/Ethernet adapter.
    if (stripos(PHP_OS, 'WIN') === 0) {
        $ps = 'powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command '
            . '"$c=Get-NetIPConfiguration | Where-Object {$_.IPv4DefaultGateway -and $_.IPv4Address}; '
            . '$c | ForEach-Object {$_.IPv4Address | ForEach-Object {$_.IPAddress}}" 2>NUL';
        $out = (string)shell_exec($ps);
        foreach (preg_split('/\R/', trim($out)) ?: [] as $candidate) {
            $candidate = trim($candidate);
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                && !str_starts_with($candidate, '127.')) return $candidate;
        }

        // Older Windows/PowerShell installations may not expose
        // Get-NetIPConfiguration; keep ipconfig as a fallback.
        $out = (string)shell_exec('ipconfig 2>NUL');
        if (preg_match_all('/IPv4 Address[^:]*:\s*([0-9.]+)/i', $out, $m)) {
            foreach ($m[1] as $candidate) {
                if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                    && !str_starts_with($candidate, '127.')) return $candidate;
            }
        }
    } else {
        // Linux: ask the kernel which source address it would use for an
        // external route, which is a reliable way to select the active LAN NIC.
        $out = trim((string)shell_exec('ip -4 route get 1.1.1.1 2>/dev/null'));
        if (preg_match('/\bsrc\s+(\d{1,3}(?:\.\d{1,3}){3})\b/', $out, $m)) {
            if (filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                && !str_starts_with($m[1], '127.')) return $m[1];
        }
        $out = trim((string)shell_exec('hostname -I 2>/dev/null'));
        foreach (preg_split('/\s+/', $out) ?: [] as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                && !str_starts_with($candidate, '127.')) return $candidate;
        }
    }
    return null;
}

/** Determine the LAN network used by discovery. We prefer the real
 * interface netmask/prefix and fall back to the common /24 LAN layout. */
function rb_local_network(): ?array {
    $ip = rb_server_lan_ipv4();
    if (!$ip) return null;

    $prefixLength = null;
    if (stripos(PHP_OS, 'WIN') === 0) {
        // Ask Windows directly for the prefix attached to the selected IP.
        $safeIp = escapeshellarg($ip);
        $ps = 'powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command '
            . '"$x=Get-NetIPAddress -AddressFamily IPv4 -IPAddress ' . $safeIp . ' -ErrorAction SilentlyContinue; '
            . 'if($x){$x.PrefixLength}" 2>NUL';
        $prefixOut = trim((string)shell_exec($ps));
        if (preg_match('/\b(\d{1,2})\b/', $prefixOut, $m)) {
            $candidate = (int)$m[1];
            if ($candidate >= 1 && $candidate <= 30) $prefixLength = $candidate;
        }

        // Fallback for older Windows versions.
        if ($prefixLength === null) {
            $out = (string)shell_exec('ipconfig 2>NUL');
            $lines = preg_split('/\R/', $out) ?: [];
            $candidateIp = false;
            foreach ($lines as $line) {
                if (preg_match('/IPv4 Address[^:]*:\s*([0-9.]+)/i', $line, $m)) {
                    $candidateIp = $m[1] === $ip;
                    continue;
                }
                if ($candidateIp && preg_match('/Subnet Mask[^:]*:\s*([0-9.]+)/i', $line, $m)) {
                    $mask = $m[1];
                    $bits = 0;
                    foreach (explode('.', $mask) as $octet) $bits += substr_count(decbin((int)$octet), '1');
                    if ($bits >= 1 && $bits <= 30) { $prefixLength = $bits; break; }
                }
            }
        }
    } else {
        $out = (string)shell_exec('ip -4 addr show 2>/dev/null');
        if (preg_match_all('/inet\s+(\d{1,3}(?:\.\d{1,3}){3})\/(\d{1,2})\s+/m', $out, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                if ($row[1] === $ip) { $prefixLength = (int)$row[2]; break; }
            }
        }
    }
    if ($prefixLength === null) $prefixLength = 24;

    $ipLong = ip2long($ip);
    if ($ipLong === false) return null;
    $mask = $prefixLength === 0 ? 0 : ((-1 << (32 - $prefixLength)) & 0xffffffff);
    $networkLong = $ipLong & $mask;
    $broadcastLong = $networkLong | (~$mask & 0xffffffff);
    $first = $networkLong + 1;
    $last = $broadcastLong - 1;
    if ($prefixLength >= 31) { $first = $networkLong; $last = $broadcastLong; }

    $hostCount = max(1, $last - $first + 1);
    // A /16 or larger can contain tens of thousands of addresses. Keep the
    // web request bounded, while still scanning a useful 4094-host window.
    if ($hostCount > 4094) $last = $first + 4093;

    return [
        'ip' => $ip,
        'prefix' => $prefixLength,
        'network' => long2ip($networkLong),
        'broadcast' => long2ip($broadcastLong),
        'first' => long2ip($first),
        'last' => long2ip($last),
        'mask' => long2ip($mask),
        'cidr' => long2ip($networkLong) . '/' . $prefixLength,
        'host_count' => $hostCount,
    ];
}

if ($path === '/api/network/devices' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    rb_require_same_lan();
    rb_require_admin();
    $devices = rb_arp_table();
    rb_json(['devices' => $devices, 'count' => count($devices)]);
}

/** Return registered RemoteBridge users that are currently online and whose
 * address is local to this server. This is intentionally separate from the
 * ARP list: a browser user can be online even when the OS has not yet learned
 * the device's MAC address. */
function rb_is_local_user_ip(?string $ip): bool {
    if (!$ip) return false;
    if (in_array($ip, ['127.0.0.1', '::1'], true)) return true;
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;

    $serverIp = rb_server_lan_ipv4();
    if (!$serverIp) return false;

    // This application scans a /24 for its LAN device view, so use the same
    // practical boundary for local user presence.
    $a = explode('.', $serverIp);
    $b = explode('.', $ip);
    return count($a) === 4 && count($b) === 4 && $a[0] === $b[0] && $a[1] === $b[1] && $a[2] === $b[2];
}

if ($path === '/api/network/users' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    rb_require_same_lan();
    rb_require_admin();
    // This endpoint intentionally represents BOTH the network devices visible
    // to the server and the RemoteBridge users currently online on the same LAN.
    // ARP is not a complete list by itself: phones, Wi-Fi clients, VPN clients,
    // and devices behind client-isolation may not have an ARP entry. Registered
    // RemoteBridge users are therefore merged independently of ARP.
    $arp = rb_arp_table();
    $arpByIp = [];
    foreach ($arp as $entry) {
        if (!empty($entry['ip'])) $arpByIp[$entry['ip']] = $entry;
    }

    $db = db();
    $rows = $db->query(
        "SELECT remote_id, device_name, ip_address, last_seen_at, is_online
         FROM devices
         WHERE ip_address IS NOT NULL
           AND is_online = 1
         ORDER BY last_seen_at DESC
         LIMIT 500"
    )->fetch_all(MYSQLI_ASSOC);

    $remoteByIp = [];
    foreach ($rows as $row) {
        $ip = trim((string)($row['ip_address'] ?? ''));
        if ($ip !== '') $remoteByIp[$ip] = $row;
    }

    $users = [];
    foreach ($arp as $entry) {
        $ip = (string)$entry['ip'];
        $remote = $remoteByIp[$ip] ?? null;
        $users[] = [
            'remote_id' => $remote['remote_id'] ?? null,
            'device_name' => ($remote['device_name'] ?? '') ?: ($entry['hostname'] ?? 'Unknown device'),
            'hostname' => $entry['hostname'] ?? 'Unknown device',
            'ip' => $ip,
            'mac' => $entry['mac'] ?? null,
            'last_seen_at' => $remote['last_seen_at'] ?? null,
            'online' => true,
            'source' => $remote ? 'RemoteBridge + ARP' : 'ARP',
        ];
    }

    // If a RemoteBridge user is local but has not appeared in ARP yet, retain
    // the user row so it is not silently lost. Its MAC is legitimately unknown
    // until this server learns the client's layer-2 address.
    foreach ($rows as $row) {
        $ip = trim((string)($row['ip_address'] ?? ''));
        if ($ip === '' || isset($arpByIp[$ip])) continue;
        if (!rb_is_local_user_ip($ip)) continue;
        $users[] = [
            'remote_id' => $row['remote_id'],
            'device_name' => $row['device_name'] ?: 'RemoteBridge user',
            'hostname' => 'Not resolved',
            'ip' => $ip,
            'mac' => null,
            'last_seen_at' => $row['last_seen_at'],
            'online' => true,
            'source' => 'RemoteBridge',
        ];
    }

    usort($users, fn($a, $b) => (ip2long($a['ip']) ?: 0) <=> (ip2long($b['ip']) ?: 0));
    rb_json(['users' => $users, 'count' => count($users)]);
}

/** Runs a bounded local subnet discovery sweep.
 *
 * IMPORTANT: this scan is intentionally NOT detached. A previous version used
 * start /B, nohup, and fire-and-forget child processes. Those processes could
 * survive the browser request and continue scanning in the background.
 *
 * The scan is now owned by this HTTP request, has a hard timeout, and is
 * terminated before the endpoint returns. This keeps the rest of RemoteBridge
 * unaffected and guarantees that a completed/cancelled request cannot leave a
 * scanner running indefinitely.
 */
function rb_run_bounded_process(string $command, int $timeoutMs = 5000): array {
    $pipes = [];
    $proc = @proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, __DIR__);

    if (!is_resource($proc)) {
        return ['ok' => false, 'timed_out' => false];
    }

    @fclose($pipes[0]);
    $startedAt = microtime(true);
    $timedOut = false;

    // Non-blocking reads prevent a full stderr/stdout pipe from deadlocking
    // the request on unusual Windows/PHP configurations.
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    while (true) {
        $status = proc_get_status($proc);
        if (!$status['running']) break;

        if ((microtime(true) - $startedAt) * 1000 >= $timeoutMs) {
            $timedOut = true;
            @proc_terminate($proc, 15);
            usleep(100000);
            $status = proc_get_status($proc);
            if ($status['running']) @proc_terminate($proc, 9);
            break;
        }
        usleep(50000);
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    @fclose($pipes[1]);
    @fclose($pipes[2]);
    $exitCode = @proc_close($proc);

    return [
        'ok' => !$timedOut && $exitCode === 0,
        'timed_out' => $timedOut,
        'exit_code' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

if ($path === '/api/network/scan' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    rb_require_same_lan();
    $netAuth = rb_require_admin();
    rb_audit_log(db(), 'network_scan', null, ['admin' => $netAuth['username']]);

    $network = rb_local_network();
    if (!$network) rb_json(['error' => 'Could not determine the local network to scan'], 500);

    $isWindows = stripos(PHP_OS, 'WIN') === 0;
    // The helper probes the complete detected host range. Keep the HTTP job
    // bounded so discovery cannot hang or interfere with the rest of the app.
    $scanTimeoutMs = 15000;
    $result = ['ok' => false, 'timed_out' => false];

    if ($isWindows) {
        $script = __DIR__ . DIRECTORY_SEPARATOR . 'agent' . DIRECTORY_SEPARATOR . 'network-scan.ps1';
        if (!is_readable($script)) rb_json(['error' => 'Network scan helper is missing'], 500);
        $cmd = 'powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File '
             . escapeshellarg($script)
             . ' -First ' . escapeshellarg($network['first'])
             . ' -Last ' . escapeshellarg($network['last']);
        $result = rb_run_bounded_process($cmd, $scanTimeoutMs);
    } else {
        $script = __DIR__ . DIRECTORY_SEPARATOR . 'agent' . DIRECTORY_SEPARATOR . 'network-scan.sh';
        if (!is_readable($script)) rb_json(['error' => 'Network scan helper is missing'], 500);
        $cmd = 'sh ' . escapeshellarg($script)
             . ' ' . escapeshellarg($network['first'])
             . ' ' . escapeshellarg($network['last']);
        $result = rb_run_bounded_process($cmd, $scanTimeoutMs);
    }

    // ARP contains the layer-2 neighbors learned by the host. The sweep above
    // refreshes it across the detected subnet, then the API returns the current
    // valid IPv4/MAC entries. Do not report a failed helper process as a
    // successful scan: that made the UI look like discovery worked when the
    // PHP/Windows host could not execute the scanner.
    $devices = rb_arp_table();
    if (!$result['ok'] && !$result['timed_out']) {
        $detail = trim((string)($result['stderr'] ?? ''));
        if ($detail === '') $detail = 'The network scan helper could not be executed by PHP.';
        rb_json([
            'ok' => false,
            'subnet' => $network['cidr'],
            'host_range' => $network['first'] . ' - ' . $network['last'],
            'devices' => $devices,
            'count' => count($devices),
            'error' => $detail,
        ], 500);
    }

    rb_json([
        'ok' => true,
        'started' => true,
        'completed' => !$result['timed_out'],
        'timed_out' => $result['timed_out'],
        'subnet' => $network['cidr'],
        'host_range' => $network['first'] . ' - ' . $network['last'],
        'devices' => $devices,
        'count' => count($devices),
        'message' => $result['timed_out']
            ? 'The scan reached its time limit; devices discovered so far are shown.'
            : 'Network scan completed.'
    ]);
}

/* ---------------------------------------------------------------------
 * Everything else falls through to the app shell.
 * ------------------------------------------------------------------- */

if ($path !== '/' && $path !== '/index.php') {
    rb_json(['error' => 'Not found'], 404);
}

if (!rb_is_authenticated()) {
    header('Location: ' . $basePath . '/login.php');
    exit;
}
$rbCurrentUser = rb_get_current_user();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="description" content="RemoteBridge — web-based remote desktop over WebRTC">
<title> ARV Control System </title>
<link rel="icon" type="image/x-icon" href="logo/favicon.ico">
<link rel="icon" type="image/png" sizes="16x16" href="logo/favicon-16.png">
<link rel="icon" type="image/png" sizes="32x32" href="logo/favicon-32.png">
<link rel="apple-touch-icon" sizes="180x180" href="logo/apple-touch-icon-180.png">
<style>
:root{font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#eaf1ff;background:#07101f;--navbar-h:64px;--sb-left:300px;--sb-right:320px}
*{box-sizing:border-box}body{margin:0;min-height:100vh;padding-top:var(--navbar-h);background:radial-gradient(circle at 20% 10%,#15315a 0,#07101f 42%,#050a13 100%)}

/* ---- Fixed top navbar ---- */
.navbar{position:fixed;top:0;left:0;right:0;height:var(--navbar-h);z-index:200;display:flex;justify-content:space-between;gap:16px;align-items:center;padding:0 22px;background:rgba(9,18,34,.92);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border-bottom:1px solid #1f3454}
.navbar-brand{display:flex;flex-direction:column;justify-content:center;min-width:0}
.navbar-brand h1{margin:0;font-size:19px;line-height:1.2;white-space:nowrap}
.navbar-brand .sub{color:#8fa2c0;margin:1px 0 0;font-size:11.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

h1{margin:0;font-size:clamp(26px,5vw,42px)}.sub{color:#9db0ca;margin:6px 0 0}
.status{border:1px solid #2c4262;background:#0e1b2f;border-radius:999px;padding:9px 14px;font-size:13px;white-space:nowrap;flex-shrink:0}
.tabs{display:flex;gap:8px;margin-bottom:18px}
.tab{padding:10px 18px;border-radius:11px;background:#0e1b2f;border:1px solid #253b5a;color:#cfe0f7;cursor:pointer;font-weight:600}
.tab.active{background:#2b6de8;border-color:#2b6de8;color:#fff}
.card{background:rgba(14,27,47,.88);border:1px solid #253b5a;border-radius:20px;padding:24px;box-shadow:0 20px 70px rgba(0,0,0,.25);margin-bottom:18px}
.card h2{margin:0 0 6px}.muted{color:#9db0ca;font-size:14px}
.remoteid{font-family:ui-monospace,monospace;font-size:32px;letter-spacing:2px;background:#07101c;border:1px solid #1e3049;border-radius:12px;padding:14px 18px;margin:14px 0;text-align:center}
.remoteid-row{display:flex;align-items:stretch;gap:0;margin:14px 0}.remoteid-shell{position:relative;flex:1;min-width:0}.remoteid-shell .remoteid{margin:0;padding-right:110px}.remoteid-copy{position:absolute;right:8px;top:8px;bottom:8px;min-width:82px;padding:8px 14px}.presence-btn{display:inline-flex;align-items:center;gap:8px}.presence-dot{width:9px;height:9px;border-radius:50%;display:inline-block;box-shadow:0 0 0 2px rgba(255,255,255,.08)}.presence-dot.online{background:#79f2a0;box-shadow:0 0 0 2px rgba(121,242,160,.15),0 0 8px rgba(121,242,160,.45)}.presence-dot.offline{background:#ef5350;box-shadow:0 0 0 2px rgba(239,83,80,.16),0 0 8px rgba(239,83,80,.32)}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
input[type=text],input[type=password]{flex:1;min-width:220px;padding:12px 14px;border-radius:11px;border:1px solid #253b5a;background:#07101c;color:#eaf1ff;font-family:ui-monospace,monospace;font-size:18px;letter-spacing:1px;outline:none;box-shadow:none;appearance:none;-webkit-appearance:none}
input[type=text]:focus,input[type=password]:focus{border-color:#3a6fbb;box-shadow:0 0 0 2px rgba(43,109,232,.18);background:#07101c;color:#eaf1ff}
input[type=text]::placeholder,input[type=password]::placeholder{color:#6f83a3;opacity:1}
.agent-token-input{flex:1 1 420px;min-width:280px;width:100%;height:46px}
.agent-token-input[type=password]{-webkit-text-security:disc}
.agent-token-input[type=text]{-webkit-text-security:none}
button{border:0;border-radius:11px;padding:11px 16px;background:#2b6de8;color:white;font-weight:700;cursor:pointer}
button.secondary{background:#20324d}button.danger{background:#c0392b}button:disabled{opacity:.5;cursor:not-allowed}
.icon-btn{display:inline-flex;align-items:center;gap:8px}
.icon-btn svg{flex-shrink:0}
.request{display:flex;justify-content:space-between;align-items:center;gap:10px;background:#0a1729;border:1px solid #1b304c;border-radius:12px;padding:12px 16px;margin-top:10px;flex-wrap:wrap}
.request .req-meta{display:flex;flex-direction:column;gap:2px}
video{width:100%;border-radius:14px;background:#000;border:1px solid #1e3049;display:block}
.hidden{display:none !important}
.log{margin-top:14px;padding:13px;border-radius:12px;background:#07101c;border:1px solid #1e3049;color:#9db0ca;font-family:ui-monospace,monospace;font-size:12.5px;max-height:calc(100vh - var(--navbar-h) - 130px);overflow:auto;white-space:pre-wrap}
.term-wrap{margin-top:12px;border-radius:12px;border:1px solid #1e3049;background:#050b14;overflow:hidden}
.term-bar{display:flex;justify-content:space-between;align-items:center;padding:8px 12px;background:#0a1729;border-bottom:1px solid #1e3049;font-size:12px;color:#9db0ca}
.term-bar .dots{display:flex;gap:6px}
.term-bar .dot{width:10px;height:10px;border-radius:50%;background:#33456a}
.term-bar button{padding:4px 10px;font-size:12px;border-radius:7px}
.term{margin:0;padding:14px;min-height:260px;max-height:480px;overflow:auto;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px;line-height:1.55;color:#8fe3a3;white-space:pre-wrap;resize:vertical}
.term,.log{scrollbar-width:thin;scrollbar-color:#2b6de8 #0a1729}
.term::-webkit-scrollbar,.log::-webkit-scrollbar{width:10px;height:10px}
.term::-webkit-scrollbar-track,.log::-webkit-scrollbar-track{background:#0a1729;border-radius:8px}
.term::-webkit-scrollbar-thumb,.log::-webkit-scrollbar-thumb{background:#2b4a7a;border-radius:8px;border:2px solid #0a1729}
.term::-webkit-scrollbar-thumb:hover,.log::-webkit-scrollbar-thumb:hover{background:#2b6de8}
.badge{display:inline-block;font-size:11px;font-weight:700;letter-spacing:.3px;padding:3px 9px;border-radius:999px;border:1px solid #2c4262}
.badge.local{background:#173822;border-color:#2f6b45;color:#8fe3a3}
.badge.internet{background:#1e2e50;border-color:#3a56a0;color:#a9c3ff}
.badge.warn{background:#3a2412;border-color:#8a5a1f;color:#f2c58a}

/* ---- Layout shell: fixed navbar + two fixed sidebars + centered main ---- */
.layout{padding:24px var(--sb-right) 60px var(--sb-left);min-height:calc(100vh - var(--navbar-h))}
main{max-width:900px;margin:0 auto;width:100%}
.sidebar{position:fixed;top:var(--navbar-h);bottom:0;overflow:auto;padding:20px 18px;z-index:90}
.sidebar-left{left:0;width:var(--sb-left);border-right:1px solid #16273f}
.sidebar-right{right:0;width:var(--sb-right);border-left:1px solid #16273f}
.sidebar .card{padding:18px;margin-bottom:0}
.sidebar h2{font-size:16px}
.sidebar,.sidebar::-webkit-scrollbar{scrollbar-width:thin;scrollbar-color:#2b6de8 #0a1729}
.sidebar::-webkit-scrollbar{width:8px}
.sidebar::-webkit-scrollbar-track{background:transparent}
.sidebar::-webkit-scrollbar-thumb{background:#2b4a7a;border-radius:8px}

.device-grid{display:grid;grid-template-columns:1fr;gap:10px;margin-top:14px;padding-right:2px}
.device-tile{background:#0a1729;border:1px solid #1b304c;border-radius:12px;padding:11px 13px}
.device-tile .dname{font-weight:700;font-size:13.5px;display:flex;align-items:center;gap:6px}
.device-tile .dname .dot{width:7px;height:7px;border-radius:50%;background:#3fbf6f;flex-shrink:0}
.device-tile .drow{display:flex;justify-content:space-between;gap:8px;font-family:ui-monospace,monospace;font-size:12px;color:#9db0ca;margin-top:5px}
.device-tile .drow span:last-child{color:#cfe0f7}
.device-empty{color:#9db0ca;font-size:13px;padding:14px 4px}

/* ---- Collapsible "Details" dropdown inside device/user tiles ---- */
.tile-details{margin-top:8px}
.tile-details summary{cursor:pointer;list-style:none;display:flex;align-items:center;gap:5px;font-size:11.5px;font-weight:700;color:#8fa2c0;user-select:none;padding:2px 0}
.tile-details summary::-webkit-details-marker{display:none}
.tile-details summary::before{content:'▸';display:inline-block;font-size:10px;color:#5f7aa8;transition:transform .15s}
.tile-details[open] summary::before{transform:rotate(90deg)}
.tile-details summary:hover{color:#cfe0f7}
.tile-details[open]{padding-bottom:2px}

/* ---- Connected-session tiles (host & viewer) showing remote ID + device ---- */
.connected-list{display:grid;gap:10px;margin-top:14px}
.connected-tile{background:#0f2a1c;border:1px solid #2f6b45;border-radius:12px;padding:11px 13px;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
.connected-tile .cinfo{display:flex;flex-direction:column;gap:2px;min-width:0}
.connected-tile .cid{font-family:ui-monospace,monospace;font-weight:700;font-size:13.5px;color:#eaf1ff;display:flex;align-items:center;gap:6px;flex-wrap:wrap}.remote-copy-btn{padding:3px 7px;font-size:10.5px;border-radius:7px;background:#20324d;color:#cfe0f7;border:1px solid #2c4262}.remote-copy-btn:hover{background:#29415f}
.connected-tile .cid .dot{width:7px;height:7px;border-radius:50%;background:#3fbf6f;flex-shrink:0}
.connected-tile .cdevice{font-size:12px;color:#8fe3a3}
.connected-tile .cmeta{font-size:11.5px;color:#9db0ca;font-family:ui-monospace,monospace;letter-spacing:.2px}
.connected-tile .cmeta .mac-missing{color:#6f84a3;font-style:italic}

.agent-meta-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:9px}
.connection-diagnostics{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;padding-top:10px;border-top:1px solid #1b304c;color:#9db0ca;font-size:12px}
.connection-diagnostics span{background:#0a1729;border:1px solid #1b304c;border-radius:8px;padding:6px 9px}
.local-users-section{margin-top:16px}
.section-divider{height:1px;background:#1b304c;margin:16px 0}
.local-users-head{display:flex;justify-content:space-between;align-items:flex-start;gap:8px}
.local-users-head h3{margin:0;font-size:14px}
.local-users-grid{display:grid;gap:9px;margin-top:10px}
.local-user-tile{background:#0f2a1c;border:1px solid #2f6b45;border-radius:11px;padding:10px 11px}
.local-user-tile .uname{font-weight:700;font-size:13px;display:flex;align-items:center;gap:6px}
.local-user-tile .udot{width:7px;height:7px;border-radius:50%;background:#3fbf6f;flex-shrink:0}
.local-user-tile .uid{font-family:ui-monospace,monospace;font-size:12px;color:#eaf1ff;margin-top:3px;display:flex;align-items:center;gap:7px;flex-wrap:wrap}.local-user-tile .uid .remote-copy-btn{margin-left:2px}
.local-user-tile .umeta{font-family:ui-monospace,monospace;font-size:11px;color:#9db0ca;margin-top:4px;word-break:break-word}

.recent-device-list{display:grid;gap:9px;margin-top:12px}
.recent-device-tile{background:#0a1729;border:1px solid #1b304c;border-radius:11px;padding:10px 11px}
.recent-device-tile .rdname-row{display:flex;align-items:center;justify-content:space-between;gap:8px}
.recent-device-tile .rdname{font-weight:700;font-size:13px;display:flex;align-items:center;gap:6px;min-width:0}
.recent-device-tile .rdname span.dot{width:7px;height:7px;border-radius:50%;background:#455a80;flex-shrink:0}
.recent-device-tile .rdname.online span.dot{background:#3fbf6f}
.recent-device-tile .rdname .rdname-text{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.recent-device-tile .rdmeta{font-family:ui-monospace,monospace;font-size:11px;color:#9db0ca;margin-top:5px;word-break:break-word}
.recent-device-tile .rdmeta .rd-status{font-family:Inter,system-ui,sans-serif;color:#7fd99a}
.rd-rename-btn{background:transparent;border:1px solid #2c4262;color:#9db0ca;border-radius:7px;padding:3px 6px;line-height:1;flex-shrink:0}
.rd-rename-btn:hover{border-color:#3a5680;color:#cfe0f7;background:#0e1b2f}
.rd-rename-row{display:flex;gap:6px;margin-top:8px}
.rd-rename-row input{flex:1;min-width:0;padding:6px 9px;border-radius:8px;border:1px solid #253b5a;background:#07101c;color:#eaf1ff;font-size:12px;outline:none}
.rd-rename-row input:focus{border-color:#3a6fbb;box-shadow:0 0 0 2px rgba(43,109,232,.18)}
.rd-rename-row button{padding:6px 10px;font-size:11.5px;border-radius:8px}
.rd-rename-error{color:#f2a3a3;font-size:11px;margin-top:5px}

/* ---- Navbar "Setup guide" reopen button ---- */
.navbar-actions{display:flex;align-items:center;gap:10px;flex-shrink:0}
.link-btn{background:transparent;border:1px solid #2c4262;color:#cfe0f7;padding:8px 13px;font-size:12.5px;font-weight:600;border-radius:9px;white-space:nowrap}
.link-btn:hover{border-color:#3a5680;background:#0e1b2f}
.navbar-divider{width:1px;height:26px;background:#1f3454;flex-shrink:0;margin:0 2px}

/* ---- Navbar dropdowns (Network / Menu) ---- */
.nav-dropdown{position:relative}
.nav-dropdown-btn{display:inline-flex;align-items:center;gap:6px}
.nav-dropdown-btn .caret{font-size:10px;opacity:.8;transition:transform .15s}
.nav-dropdown.open .nav-dropdown-btn .caret{transform:rotate(180deg)}
.nav-badge{background:#2b6de8;color:#fff;font-size:10px;font-weight:800;padding:1px 6px;border-radius:999px;line-height:1.5;min-width:16px;text-align:center;display:inline-block}
.nav-dropdown-panel{position:absolute;top:calc(100% + 10px);right:0;min-width:230px;background:#0c1c33;border:1px solid #253b5a;border-radius:14px;box-shadow:0 24px 60px rgba(0,0,0,.45);padding:10px;z-index:250}
.nav-dropdown-panel.hidden{display:none !important}
.nav-dropdown-item{display:block;width:100%;text-align:left;background:transparent;border:0;color:#cfe0f7;padding:9px 10px;border-radius:9px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none}
.nav-dropdown-item:hover{background:#132340}
.nav-dropdown-status{display:flex;align-items:center;gap:8px;padding:8px 10px;margin-bottom:4px;border-radius:9px;background:#07101c;border:1px solid #1e3049;font-size:12px;color:#9db0ca}
.nav-dropdown-divider{height:1px;background:#1f3454;margin:6px 2px}
.nav-network-panel{width:300px}
.nav-network-panel .row{margin-top:10px}
.nav-network-panel .row button{flex:1;font-size:12px;padding:9px 10px}

/* ---- Shared filter/search inputs (device & user lists) ---- */
.filter-input{width:100%;padding:9px 12px;border-radius:10px;border:1px solid #253b5a;background:#07101c;color:#eaf1ff;font-family:Inter,system-ui,sans-serif;font-size:12.5px;outline:none;margin:2px 0 0;appearance:none;-webkit-appearance:none}
.filter-input::placeholder{color:#6f83a3;opacity:1}
.filter-input:focus{border-color:#3a6fbb;box-shadow:0 0 0 2px rgba(43,109,232,.18)}
.filter-input::-webkit-search-cancel-button{filter:invert(.6)}
.device-grid-modal{grid-template-columns:repeat(auto-fill,minmax(230px,1fr))}
.local-users-grid-modal{grid-template-columns:repeat(auto-fill,minmax(260px,1fr))}
.user-chip{display:flex;align-items:center;gap:9px;background:#0e1b2f;border:1px solid #253b5a;border-radius:999px;padding:5px 14px 5px 6px;white-space:nowrap}
.user-avatar{width:26px;height:26px;border-radius:50%;background:linear-gradient(135deg,#2b6de8,#7b3fe4);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:11.5px;color:#fff;flex-shrink:0}
.user-chip .uname{font-size:12.5px;font-weight:700;color:#eaf1ff}
.role-chip{font-size:9.5px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;padding:2px 8px;border-radius:999px;border:1px solid #2c4262}
.role-chip.admin{background:#1e2e50;border-color:#3a56a0;color:#a9c3ff}
.role-chip.user{background:#173822;border-color:#2f6b45;color:#8fe3a3}
.logout-btn{background:#20324d;color:#eaf1ff;padding:8px 14px;font-size:12.5px;border-radius:9px}
.logout-btn:hover{background:#28405f}
@media(max-width:760px){.user-chip .uname{display:none}.navbar-divider{display:none}}

/* ---- Initial setup modal (Local vs Internet + advanced manual) ---- */
.modal-overlay{position:fixed;inset:0;background:rgba(4,9,18,.72);backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px);display:flex;align-items:center;justify-content:center;z-index:300;padding:20px}
.modal-overlay.hidden{display:none !important}
.modal-box{background:#0c1c33;border:1px solid #253b5a;border-radius:20px;max-width:720px;width:100%;max-height:88vh;overflow:auto;box-shadow:0 30px 90px rgba(0,0,0,.5)}
.modal-box,.modal-box::-webkit-scrollbar{scrollbar-width:thin;scrollbar-color:#2b6de8 #0a1729}
.modal-box::-webkit-scrollbar{width:8px}
.modal-box::-webkit-scrollbar-thumb{background:#2b4a7a;border-radius:8px}
.modal-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;padding:22px 24px 0}
.modal-head h2{margin:0;font-size:20px}
.modal-head p{margin:6px 0 0;color:#9db0ca;font-size:13px}
.modal-close{background:transparent;border:1px solid #253b5a;color:#cfe0f7;width:32px;height:32px;border-radius:9px;padding:0;font-size:18px;line-height:1;flex-shrink:0}
.modal-close:hover{border-color:#3a5680}
.modal-tabs{display:flex;gap:8px;padding:18px 24px 0}
.modal-tab{padding:9px 14px;border-radius:10px;background:transparent;border:1px solid #253b5a;color:#9db0ca;font-weight:600;font-size:13px;cursor:pointer}
.modal-tab.active{background:#16283f;border-color:#2b6de8;color:#eaf1ff}
.modal-body{padding:18px 24px 4px}
.setup-options{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media(max-width:560px){.setup-options{grid-template-columns:1fr}}
.setup-option{background:#0a1729;border:2px solid #1b304c;border-radius:14px;padding:16px;cursor:pointer;text-align:left}
.setup-option:hover{border-color:#2b4a7a}
.setup-option.selected{border-color:#2b6de8;background:#0f2140}
.setup-option h3{margin:0 0 8px;font-size:15px;display:flex;align-items:center;gap:9px}
.setup-option p{margin:0;color:#9db0ca;font-size:12.5px;line-height:1.55}
.setup-option .check{width:18px;height:18px;border-radius:50%;border:2px solid #33456a;flex-shrink:0;box-sizing:border-box}
.setup-option.selected .check{border-color:#2b6de8;background:#2b6de8;box-shadow:inset 0 0 0 3px #0f2140}
.setup-tip{margin-top:14px;padding:12px 14px;border-radius:12px;background:#0a1729;border:1px solid #1b304c;font-size:12.5px;color:#a9c3ff;line-height:1.5}
.guide-steps{counter-reset:step;list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:12px}
.guide-steps li{counter-increment:step;background:#0a1729;border:1px solid #1b304c;border-radius:12px;padding:12px 14px 12px 44px;position:relative;font-size:13px;color:#cfe0f7;line-height:1.6}
.guide-steps li::before{content:counter(step);position:absolute;left:12px;top:12px;width:22px;height:22px;border-radius:50%;background:#2b6de8;color:#fff;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center}
.guide-steps code{background:#050b14;padding:1px 6px;border-radius:5px;font-size:12px;font-family:ui-monospace,monospace}
.modal-foot{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:16px 24px 24px;flex-wrap:wrap}
.modal-foot label{display:flex;align-items:center;gap:6px;font-size:12.5px;color:#9db0ca;cursor:pointer}

@media(max-width:980px){
  :root{--sb-left:0px;--sb-right:0px}
  .layout{padding-left:16px;padding-right:16px;display:flex;flex-direction:column;gap:18px}
  .sidebar{position:static;width:100%;height:auto;overflow:visible;padding:0;border:0}
  .log{max-height:260px}
  main{max-width:100%}
}
@media(max-width:640px){
  .navbar{padding:0 14px}
  .navbar-brand .sub{display:none}
  .modal-head{flex-wrap:wrap}
  .modal-tabs{flex-wrap:wrap}
}
</style>
</head>
<body>
<header class="navbar">
  <div class="navbar-brand">
    <h1>RemoteBridge</h1>
    <p class="sub">Web-based remote desktop over WebRTC — no install, connect with a Remote ID</p>
  </div>
  <div class="navbar-actions">
    <?php if ($rbCurrentUser['role'] === 'admin'): ?>
    <div class="nav-dropdown" id="navNetworkDropdown">
      <button type="button" class="link-btn nav-dropdown-btn" onclick="rbToggleNavDropdown('navNetworkDropdown', event)">
        Network <span id="navDeviceBadge" class="nav-badge hidden"></span> <span class="caret">▾</span>
      </button>
      <div class="nav-dropdown-panel nav-network-panel hidden" id="navNetworkPanel">
        <div id="navDeviceCount" class="muted" style="font-size:12px">Loading…</div>
        <div id="navUserCount" class="muted" style="font-size:12px;margin-top:2px"></div>
        <div class="row">
          <button type="button" class="secondary" onclick="rbOpenNetworkModal('devices'); rbScanDevices();">Scan all devices</button>
          <button type="button" class="secondary" onclick="rbOpenNetworkModal('devices')">View details</button>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <div class="nav-dropdown" id="navMenuDropdown">
      <button type="button" class="link-btn nav-dropdown-btn" onclick="rbToggleNavDropdown('navMenuDropdown', event)">Menu <span class="caret">▾</span></button>
      <div class="nav-dropdown-panel hidden" id="navMenuPanel">
        <div class="nav-dropdown-status"><span id="status">Checking database…</span></div>
        <button type="button" class="nav-dropdown-item" onclick="rbCloseNavDropdowns(); rbOpenSetupModal();">Setup guide</button>
        <?php if ($rbCurrentUser['role'] === 'admin'): ?>
        <a class="nav-dropdown-item" href="<?= htmlspecialchars($basePath . '/admin/settings.php', ENT_QUOTES) ?>">Admin settings</a>
        <a class="nav-dropdown-item" href="<?= htmlspecialchars($basePath . '/admin/security.php', ENT_QUOTES) ?>">Security Center</a>
        <?php endif; ?>
        <div class="nav-dropdown-divider"></div>
        <button type="button" class="nav-dropdown-item" onclick="rbLogout()">Log out</button>
      </div>
    </div>
    <div class="navbar-divider"></div>
    <div class="user-chip">
      <div class="user-avatar"><?= htmlspecialchars(strtoupper(substr($rbCurrentUser['display_name'], 0, 1)), ENT_QUOTES) ?></div>
      <span class="uname"><?= htmlspecialchars($rbCurrentUser['display_name'], ENT_QUOTES) ?></span>
      <span class="role-chip <?= $rbCurrentUser['role'] === 'admin' ? 'admin' : 'user' ?>"><?= htmlspecialchars($rbCurrentUser['role'], ENT_QUOTES) ?></span>
    </div>
  </div>
</header>

<div id="setupModal" class="modal-overlay hidden">
  <div class="modal-box">
    <div class="modal-head">
      <div>
        <h2>Welcome to RemoteBridge</h2>
        <p>A quick setup before you start — pick how you'll be connecting, or open the advanced manual below.</p>
      </div>
      <button class="modal-close" onclick="rbCloseSetupModal()" aria-label="Close">&times;</button>
    </div>

    <div class="modal-tabs">
      <button class="modal-tab active" id="setupTabQuick" onclick="rbShowSetupTab('quick')">Quick setup</button>
      <button class="modal-tab" id="setupTabAdvanced" onclick="rbShowSetupTab('advanced')">Advanced / manual setup</button>
    </div>

    <div class="modal-body">
      <div id="setupPanelQuick">
        <div class="setup-options">
          <div class="setup-option" id="setupOptionLocal" onclick="rbSelectSetupMode('local')">
            <h3><span class="check"></span> Local Network</h3>
            <p>Both computers are on the same Wi-Fi/router, or this is the same machine. Open this page at <code>localhost</code>, <code>127.0.0.1</code>, or a private LAN address like <code>192.168.x.x</code>. Nothing extra to configure — a public STUN server is enough for the connection to find a direct path.</p>
          </div>
          <div class="setup-option" id="setupOptionInternet" onclick="rbSelectSetupMode('internet')">
            <h3><span class="check"></span> Internet (server-based)</h3>
            <p>The two computers are on different networks. Deploy <code>index.php</code> + MySQL to a publicly reachable host over HTTPS so both sides can reach it. A TURN relay is used automatically for the video/control path — free shared by default, or your own for production.</p>
          </div>
        </div>
        <div id="setupTip" class="setup-tip hidden"></div>
      </div>

      <div id="setupPanelAdvanced" class="hidden">
        <ol class="guide-steps">
          <li><strong>Configure the database.</strong> All PHP database connections use the central <code>config.php</code> file. For a normal XAMPP setup, edit only the <code>database</code> block there: <code>host</code>, <code>port</code>, <code>database</code>, <code>username</code>, and <code>password</code>. You do not need to edit <code>index.php</code>, <code>database.php</code>, or the migration runner for connection changes.</li>
          <li><strong>Run the migrations.</strong> From the project root: <code>php database/migrate.php</code> — this creates the MySQL/MariaDB database and applies the schema. A ready-made SQL dump is also available at <code>database/remote_bridge.sql</code>.</li>
          <li><strong>Start the server.</strong> <code>php -S 0.0.0.0:8080 index.php</code>, then open <code>http://127.0.0.1:8080/</code>. Running under XAMPP/Apache in a subfolder works too — the frontend detects the install path automatically.</li>
          <li><strong>Set up TURN for internet use (optional).</strong> A free shared TURN relay (Open Relay Project) is used automatically with zero setup. For production or heavy use, set <code>RB_TURN_URL</code>, <code>RB_TURN_USERNAME</code>, and <code>RB_TURN_CREDENTIAL</code> in <code>config.php</code>, or set <code>RB_DISABLE_FREE_TURN=1</code> to turn the free fallback off.</li>
          <li><strong>Enable remote control (optional).</strong> On the host side, under the "Share my screen" tab → "Native control agent", click <strong>Run server</strong> (or run <code>cd agent && npm install && node control-agent.js</code> yourself), paste the token it prints, and click Connect. Only do this for someone you trust — once granted, control stays active until you uncheck it.</li>
          <li><strong>Scan the local network.</strong> The "Devices on this network" panel can scan the detected LAN subnet directly. No access code is required.</li>
        </ol>
      </div>
    </div>

    <div class="modal-foot">
      <label><input type="checkbox" id="setupDontShow" checked> Don't show this again</label>
      <button onclick="rbCloseSetupModal()">Continue to RemoteBridge</button>
    </div>
  </div>
</div>

<div class="layout">
<?php if ($rbCurrentUser['role'] === 'admin'): ?>
<div id="networkModal" class="modal-overlay hidden">
  <div class="modal-box">
    <div class="modal-head">
      <div>
        <h2>Devices on this network</h2>
        <p>Scan the complete detected local subnet from this server. Active devices are discovered through ICMP/ARP and displayed with their IP, MAC, and hostname when available.</p>
      </div>
      <button class="modal-close" type="button" onclick="rbCloseNetworkModal()" aria-label="Close">×</button>
    </div>
    <div class="modal-tabs">
      <button class="modal-tab active" id="networkTabDevices" type="button" onclick="rbShowNetworkTab('devices')">Devices</button>
      <button class="modal-tab" id="networkTabUsers" type="button" onclick="rbShowNetworkTab('users')">Users connected locally</button>
    </div>
    <div class="modal-body">
      <div id="networkPanelDevices">
        <input type="search" id="deviceSearchModal" class="filter-input" placeholder="Filter by IP, MAC, hostname or type…" oninput="rbRenderDeviceGrid()">
        <div class="row" style="margin-top:10px">
          <button id="btnScanDevicesModal" class="secondary" type="button" onclick="rbScanDevices()">Scan all devices</button>
          <button class="secondary" type="button" onclick="rbLoadDevices()">Refresh</button>
        </div>
        <div id="deviceCountModal" class="muted" style="margin-top:8px;font-size:12.5px"></div>
        <div id="deviceGridModal" class="device-grid device-grid-modal"><div class="device-empty">Loading…</div></div>
      </div>
      <div id="networkPanelUsers" class="hidden">
        <input type="search" id="userSearchModal" class="filter-input" placeholder="Filter by name, IP, MAC or Remote ID…" oninput="rbRenderLocalUsersGrid()">
        <div class="row" style="margin-top:10px">
          <button class="secondary" type="button" onclick="rbLoadLocalUsers()">Refresh</button>
        </div>
        <div id="localUserCountModal" class="muted" style="margin-top:8px;font-size:12.5px"></div>
        <div id="localUsersGridModal" class="local-users-grid local-users-grid-modal"><div class="device-empty">Loading…</div></div>
      </div>
    </div>
    <div class="modal-foot">
      <span class="muted" style="font-size:12px">Refreshes automatically every 10 seconds while this page is open.</span>
      <button class="secondary" type="button" onclick="rbCloseNetworkModal()">Close</button>
    </div>
  </div>
</div>
<?php endif; ?>

<aside class="sidebar sidebar-left">
  <div class="card">
    <h2>Activity log</h2>
    <div id="log" class="log">Ready.</div>
  </div>
  <div class="card">
    <h2>Recent devices</h2>
    <p class="muted" style="font-size:12.5px">Devices recorded for your account in the database — no live network scan runs for this list. Most recent first. Rename any of them to tell them apart.</p>
    <div id="recentDeviceCount" class="muted" style="margin-top:6px;font-size:12.5px"></div>
    <div id="recentDeviceList" class="recent-device-list"><div class="device-empty">Loading…</div></div>
  </div>
</aside>

<main>
  <section class="card" id="connModeCard">
    <h2>Connection mode</h2>
    <p class="muted">Where this page is served from decides how far a connection can reach. This is detected automatically from the URL you opened.</p>
    <div class="row" style="margin-top:10px">
      <span id="connModeBadge" class="badge local">Detecting…</span>
      <span id="turnBadge" class="badge warn hidden">TURN relay not configured</span>
    </div>

    <div class="row" style="margin-top:16px;align-items:stretch">
      <div class="card" style="flex:1;min-width:260px;padding:16px;margin:0">
        <h2 style="font-size:16px">Local Network</h2>
        <p class="muted">You're on <code>localhost</code>/<code>127.0.0.1</code> or a private LAN address. Both browsers reach this PHP+MySQL signaling server directly (same machine or same Wi-Fi/router). Video/control still travel peer-to-peer over WebRTC using a public STUN server — no TURN relay is needed here.</p>
      </div>
      <div class="card" style="flex:1;min-width:260px;padding:16px;margin:0">
        <h2 style="font-size:16px">Internet (server-based)</h2>
        <p class="muted">To connect two computers on <em>different</em> networks, deploy <code>index.php</code> + MySQL to a publicly reachable host (a VPS/domain over HTTPS) so both sides can reach the same signaling API. A TURN relay is used automatically for the actual video/control path — the free Open Relay Project by default, or your own via <code>RB_TURN_URL</code> in <code>config.php</code> for anything beyond light/testing use.</p>
      </div>
    </div>
  </section>

  <div class="tabs">
    <button class="tab active" id="tabHost" onclick="rbShowTab('host')">Share my screen</button>
    <button class="tab" id="tabViewer" onclick="rbShowTab('viewer')">Connect to a Remote ID</button>
  </div>


  <section id="panelHost" class="card">
    <h2>Your Remote ID</h2>
    <p class="muted">Share this ID with the person who needs to connect to <em>this</em> computer. Nothing is shared until you click Accept below.</p>
    <div class="remoteid-row">
      <div class="remoteid-shell">
        <div id="myRemoteId" class="remoteid">…</div>
        <button id="btnCopyRemoteId" class="secondary remoteid-copy" type="button" onclick="rbCopyRemoteId()" aria-label="Copy Remote ID">Copy</button>
      </div>
    </div>
    <div class="row">
      <button id="btnShareScreen" class="secondary presence-btn" onclick="rbToggleSharePresence()"><span class="presence-dot offline" aria-hidden="true"></span><span class="presence-label">Go online</span></button>
    </div>
    <div id="incomingRequests"></div>
    <div id="connectedViewers" class="connected-list"></div>
    <div id="hostPreviewWrap" class="hidden" style="margin-top:16px">
      <p class="muted">You are sharing your screen:</p>
      <video id="hostPreview" autoplay muted playsinline></video>
      <label class="row" style="margin-top:12px;cursor:pointer">
        <input type="checkbox" id="allowControl"> Allow the connected viewer to control this mouse &amp; keyboard
      </label>
      <label class="row" style="margin-top:8px;cursor:pointer">
        <input type="checkbox" id="allowConsole" disabled> Allow the connected viewer to open a <strong>remote console</strong> (advanced — runs real commands on this computer)
      </label>
      <p id="consoleAvailabilityHint" class="muted" style="font-size:12.5px;margin:4px 0 0 26px">Connect the native control agent above to enable this.</p>
      <div class="row" style="margin-top:10px"><button class="danger" onclick="rbStopHosting()">Stop sharing</button></div>
    </div>

    <div class="card" style="margin-top:18px;padding:18px">
      <h2 style="font-size:18px">Native control agent (required for remote control)</h2>
      <p class="muted">A browser tab can't move your OS mouse or type into other apps by itself. To allow real control, start the small local agent below — the page runs it for you, no terminal needed. Only do this if you trust the person who will be controlling this computer.</p>
      <div class="row">
        <button id="btnRunServer" class="secondary" onclick="rbStartAgentServer()">Run server</button>
        <button id="btnStopServer" class="secondary hidden" onclick="rbStopAgentServer()">Stop server</button>
        <span id="agentServerState" class="muted">Not running</span>
      </div>
      <div class="term-wrap">
        <div class="term-bar">
          <span class="dots"><span class="dot"></span><span class="dot"></span><span class="dot"></span> agent/control-agent.js</span>
          <button class="secondary" onclick="document.getElementById('agentConsole').textContent=''">Clear</button>
        </div>
        <pre id="agentConsole" class="term" aria-live="polite">$ cd agent &amp;&amp; npm install &amp;&amp; node control-agent.js
Click "Run server" to start — output streams here.

[info] Agent events will appear here. Multiple authentication/disconnect lines usually mean browser tabs or reconnects; they do not mean the agent restarted.</pre>
      </div>
      <div class="row" style="margin-top:10px">
        <input class="agent-token-input" type="password" id="agentToken" placeholder="Token from command-line output" autocomplete="off" spellcheck="false" inputmode="text" aria-label="Native control agent token">
        <button class="secondary" type="button" onclick="rbExtractAgentToken()">Read token</button>
        <button class="secondary" type="button" onclick="rbCopyAgentToken()">Copy token</button>
        <button class="secondary" type="button" onclick="rbConnectAgent()">Connect</button>
      </div>
      <div class="agent-meta-row">
        <span id="agentTokenState" class="badge warn">Token: waiting</span>
        <span id="agentEndpointState" class="badge local">Agent: detecting…</span>
        <button id="btnToggleAgentToken" class="link-btn" type="button" onclick="rbToggleAgentTokenVisibility(this)">Show token</button>
      </div>
      <p class="muted" style="font-size:12.5px;margin-top:8px">The complete token is detected automatically from the command-line output. <strong>Read token</strong> updates only the token value and never changes its hidden/visible state. <strong>Copy token</strong> copies the full value without revealing it.</p>
      <p id="agentStatus" class="muted" style="margin-top:8px">Native control agent: not connected</p>
      <div id="agentDiagnostics" class="connection-diagnostics" aria-live="polite">
        <span>PHP signaling: checking…</span><span>Agent endpoint: checking…</span>
      </div>

      <div class="term-wrap" style="margin-top:14px">
        <div class="term-bar">
          <span class="dots"><span class="dot"></span><span class="dot"></span><span class="dot"></span> remote console (read-only mirror of what the viewer runs)</span>
          <button class="secondary" onclick="document.getElementById('hostConsoleLog').textContent=''">Clear</button>
        </div>
        <pre id="hostConsoleLog" class="term" aria-live="polite">Nothing yet. This fills in only while "Allow remote console" is checked above and the viewer opens one.</pre>
      </div>
    </div>
  </section>

  <section id="panelViewer" class="card hidden">
    <h2>Connect to a Remote ID</h2>
    <p class="muted">Enter the Remote ID shown on the other computer, then request a connection. The other side must accept before you see anything.</p>
    <div class="row">
      <input type="text" id="targetRemoteId" placeholder="e.g. 384920571" maxlength="64">
      <button id="btnConnect" class="icon-btn" onclick="rbConnectToRemote()">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z"/></svg>
        Connect
      </button>
    </div>
    <div id="viewerState" class="muted" style="margin-top:12px"></div>
    <div id="viewerConnInfo" class="connected-list hidden"></div>
    <div id="viewerVideoWrap" class="hidden" style="margin-top:16px">
      <video id="viewerVideo" autoplay playsinline></video>
      <div class="row" style="margin-top:10px"><button class="danger" onclick="rbDisconnect()">Disconnect</button></div>

      <div class="card" style="margin-top:18px;padding:18px">
        <h2 style="font-size:16px">Remote console</h2>
        <p class="muted" style="font-size:12.5px">Only works if the host has checked "Allow the connected viewer to open a remote console" on their side, and started their agent with the console feature enabled. Runs git-bash/bash on the host if available, otherwise the platform default shell.</p>
        <div class="row" style="margin-top:8px">
          <button id="btnConsoleStart" class="secondary" disabled onclick="rbConsoleStart()">Start console</button>
          <button id="btnConsoleStop" class="secondary" disabled onclick="rbConsoleStop()">Stop console</button>
        </div>
        <div class="term-wrap" style="margin-top:10px">
          <div class="term-bar">
            <span class="dots"><span class="dot"></span><span class="dot"></span><span class="dot"></span> remote shell</span>
            <button class="secondary" onclick="document.getElementById('viewerConsoleLog').textContent=''">Clear</button>
          </div>
          <pre id="viewerConsoleLog" class="term" aria-live="polite">Click "Start console" once connected.</pre>
        </div>
        <div class="row" style="margin-top:10px">
          <input type="text" id="consoleInput" placeholder="Type a command and press Enter" disabled
                 onkeydown="if(event.key==='Enter'){event.preventDefault();rbConsoleSendLine();}">
          <button id="btnConsoleSend" class="secondary" disabled onclick="rbConsoleSendLine()">Send</button>
        </div>
      </div>
    </div>
  </section>


</main>

<aside class="sidebar sidebar-right">
  <?php if ($rbCurrentUser['role'] === 'admin'): ?>
  <div class="card">
    <h2>Devices on this network</h2>
    <p class="muted" style="font-size:12.5px">Scan the complete detected local subnet from this server. Active devices are discovered through ICMP/ARP and displayed with their IP, MAC, and hostname when available.</p>

    <div id="deviceUnlocked">
      <input type="search" id="deviceSearchInput" class="filter-input" placeholder="Filter by IP, MAC, hostname or type…" oninput="rbRenderDeviceGrid()">
      <div class="row" style="margin-top:10px">
        <button id="btnScanDevices" class="secondary" onclick="rbOpenNetworkModal('devices'); rbScanDevices();">Scan all devices</button>
        <button class="secondary" onclick="rbOpenNetworkModal('devices'); rbLoadDevices();">Refresh</button>
      </div>
      <div id="deviceCount" class="muted" style="margin-top:8px;font-size:12.5px"></div>
      <div id="deviceGrid" class="device-grid"><div class="device-empty">Loading…</div></div>

      <div class="local-users-section">
        <div class="section-divider"></div>
        <div class="local-users-head">
          <div>
            <h3>Users connected locally</h3>
            <p class="muted" style="font-size:12px;margin:2px 0 0">All RemoteBridge users currently active on this LAN, merged with devices visible to this server.</p>
          </div>
          <button class="secondary" type="button" onclick="rbOpenNetworkModal('users'); rbLoadLocalUsers();">Refresh</button>
        </div>
        <input type="search" id="userSearchInput" class="filter-input" placeholder="Filter by name, IP, MAC or Remote ID…" oninput="rbRenderLocalUsersGrid()" style="margin-top:10px">
        <div id="localUserCount" class="muted" style="margin-top:8px;font-size:12.5px"></div>
        <div id="localUsersGrid" class="local-users-grid"><div class="device-empty">Loading…</div></div>
      </div>
    </div>
  </div>
  <?php else: ?>
  <div class="card">
    <h2>Devices on this network</h2>
    <p class="muted" style="font-size:12.5px">Network discovery is restricted to administrators.</p>
  </div>
  <?php endif; ?>
</aside>
</div>
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
async function rbLogout(){
  try { await fetch(rbUrl('/api/auth/logout'), { method: 'POST' }); } catch (e) {}
  window.location.href = RB_BASE + '/login.php';
}
function rbShowTab(which){
  document.getElementById('tabHost').classList.toggle('active', which==='host');
  document.getElementById('tabViewer').classList.toggle('active', which==='viewer');
  document.getElementById('panelHost').classList.toggle('hidden', which!=='host');
  document.getElementById('panelViewer').classList.toggle('hidden', which!=='viewer');
}
</script>
<script src="<?= htmlspecialchars($basePath) ?>/public/app.js?v=20260823-remote-fix"></script>
</body>
</html>
