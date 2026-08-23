<?php
declare(strict_types=1);

/* Shared bootstrap for every PHP entry point in this app (index.php,
 * login.php, admin/settings.php, ...). Loads config + the database
 * connection, starts the session with consistent cookie settings, and
 * defines the authentication helpers that more than one entry point needs.
 *
 * Route-specific helpers (request parsing, rb_json, ID validation, LAN
 * checks, etc.) stay in the files that actually route requests — this file
 * is deliberately just "who is logged in, and how do we check that".
 */

require_once __DIR__ . '/../database.php';
$config = require __DIR__ . '/../config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    // Secure-ish defaults for a locally-hosted app. httponly stops JS from
    // reading the cookie; samesite=Lax is enough since every request here
    // is same-site (fetch calls and normal form posts to this same app).
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Best-effort JSON response helper. Only used by JSON API endpoints —
 * login.php renders HTML instead and never calls this. */
function rb_json(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function rb_client_ip(): ?string {
    return $_SERVER['REMOTE_ADDR'] ?? null;
}

/** Best-effort audit trail. Never breaks the request if logging fails. */
function rb_audit_log(mysqli $db, string $eventType, ?string $remoteId = null, ?array $details = null): void {
    try {
        $ip = rb_client_ip();
        $detailsJson = $details !== null ? json_encode($details) : null;
        $stmt = $db->prepare(
            'INSERT INTO audit_log (event_type, remote_id, ip_address, details, created_at) VALUES (?,?,?,?,NOW())'
        );
        $stmt->bind_param('ssss', $eventType, $remoteId, $ip, $detailsJson);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // Audit logging is best-effort only.
    }
}

/* ---------------------------------------------------------------------
 * Login rate limiting
 *
 * Very small fixed-window limiter, backed by app_settings so it survives
 * across requests without needing a new table. Keyed by IP+username so one
 * bad actor can't lock out everyone, and one username can't be hammered
 * from many IPs without also slowing down.
 * ------------------------------------------------------------------- */

function rb_login_attempt_allowed(mysqli $db, string $key, int $maxAttempts = 8, int $windowSeconds = 300): bool {
    $settingKey = 'login_attempts:' . hash('sha256', $key);
    $stmt = $db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
    $stmt->bind_param('s', $settingKey);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $data = $row ? json_decode((string)$row['setting_value'], true) : null;
    $now = time();
    $attempts = is_array($data) ? array_filter($data['attempts'] ?? [], fn($t) => $t > $now - $windowSeconds) : [];

    return count($attempts) < $maxAttempts;
}

function rb_login_attempt_record(mysqli $db, string $key, int $windowSeconds = 300): void {
    $settingKey = 'login_attempts:' . hash('sha256', $key);
    $stmt = $db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
    $stmt->bind_param('s', $settingKey);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $now = time();
    $data = $row ? json_decode((string)$row['setting_value'], true) : null;
    $attempts = is_array($data) ? array_filter($data['attempts'] ?? [], fn($t) => $t > $now - $windowSeconds) : [];
    $attempts[] = $now;
    $json = json_encode(['attempts' => array_values($attempts)]);

    $upsert = $db->prepare(
        'INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $upsert->bind_param('ss', $settingKey, $json);
    $upsert->execute();
}

/* ---------------------------------------------------------------------
 * Authentication / authorization
 *
 * Session-based login backed by the `users` table (bcrypt password_hash +
 * role). Nothing below trusts the client for identity — everything reads
 * from $_SESSION, which PHP keeps server-side.
 * ------------------------------------------------------------------- */

/** Current logged-in user, or null. Never exits. */
function rb_get_current_user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    return [
        'id' => (int)$_SESSION['user_id'],
        'username' => (string)$_SESSION['username'],
        'display_name' => (string)($_SESSION['display_name'] ?? $_SESSION['username']),
        'role' => (string)$_SESSION['role'],
    ];
}

function rb_is_authenticated(): bool {
    return !empty($_SESSION['user_id']);
}

/** Require any logged-in user. Ends the request with 401 JSON if not.
 * (Only used by JSON API routes in index.php — login.php checks
 * rb_is_authenticated() directly since it doesn't speak JSON.) */
function rb_require_auth(): array {
    $user = rb_get_current_user();
    if (!$user) rb_json(['error' => 'Authentication required'], 401);
    return $user;
}

/** Require an admin. Ends the request with 401/403 JSON if not. */
function rb_require_admin(): array {
    $user = rb_require_auth();
    if ($user['role'] !== 'admin') {
        rb_json(['error' => 'Admin access required'], 403);
    }
    return $user;
}

/* ---------------------------------------------------------------------
 * CSRF protection for plain HTML form posts (login.php). JSON API routes
 * don't need this — they're same-origin fetch calls, not browser-submitted
 * forms, so they're not vulnerable to cross-site form submission the same
 * way a <form method="post"> page is. */

function rb_csrf_token(): string {
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function rb_csrf_verify(?string $token): bool {
    return !empty($_SESSION['csrf_token']) && is_string($token) && $token !== ''
        && hash_equals($_SESSION['csrf_token'], $token);
}

/** Verify a username/password against the `users` table and, on success,
 * establish the session. Shared by the JSON login API (/api/auth/login) and
 * the plain server-rendered login.php form, so both get identical rate
 * limiting, hashing, and audit-log behavior — the only difference is how
 * each caller presents the result.
 *
 * Returns ['ok' => true, 'user' => [...]] on success, or
 * ['ok' => false, 'error' => 'missing'|'rate_limited'|'invalid'] on failure. */
function rb_attempt_login(mysqli $db, string $username, string $password): array {
    $username = trim($username);
    if ($username === '' || $password === '') {
        return ['ok' => false, 'error' => 'missing'];
    }

    $rateKey = rb_client_ip() . '|' . strtolower($username);
    if (!rb_login_attempt_allowed($db, $rateKey)) {
        return ['ok' => false, 'error' => 'rate_limited'];
    }

    $stmt = $db->prepare('SELECT id, username, display_name, role, password_hash FROM users WHERE username = ? AND is_active = 1 LIMIT 1');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        rb_login_attempt_record($db, $rateKey);
        rb_audit_log($db, 'login_failed', null, ['username' => $username]);
        return ['ok' => false, 'error' => 'invalid'];
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['display_name'] = $user['display_name'];
    $_SESSION['role'] = $user['role'];

    $upd = $db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
    $upd->bind_param('i', $user['id']);
    $upd->execute();

    rb_audit_log($db, 'login_success', null, ['username' => $user['username']]);
    return ['ok' => true, 'user' => rb_get_current_user()];
}

/** Detect the installation base path (e.g. "/A-RemoteVanced" when this
 * project lives in htdocs/A-RemoteVanced) directly from the request URL, and
 * split it from the app-relative route. Shared by index.php (which routes
 * many paths) and login.php (which only needs $basePath for its links and
 * redirect target).
 *
 * SCRIPT_NAME is NOT reliable here: under the .htaccess rewrite rule,
 * different Apache/PHP builds report SCRIPT_NAME as either the physical
 * script path or the pre-rewrite request path, and which one you get can
 * differ between the initial page load and later /api/* calls. The request
 * URL itself is always accurate, so locate this app's own known route
 * markers inside it and split there. */
function rb_resolve_route(string $requestPath, array $extraMarkers = []): array {
    $routeMarkers = array_merge(['/index.php/', '/index.php', '/login.php', '/health', '/api/'], $extraMarkers);
    $path = null;
    $basePath = '';
    foreach ($routeMarkers as $marker) {
        $pos = strpos($requestPath, $marker);
        if ($pos !== false) {
            $basePath = rtrim(substr($requestPath, 0, $pos), '/');
            $path = substr($requestPath, $pos);
            break;
        }
    }
    if ($path === null) {
        $basePath = rtrim($requestPath, '/');
        $path = '/';
    }
    if ($path === '/index.php') {
        $path = '/';
    } elseif (str_starts_with($path, '/index.php/')) {
        $path = substr($path, strlen('/index.php')) ?: '/';
    }
    if ($path[0] !== '/') $path = '/' . $path;
    return [$path, $basePath];
}
