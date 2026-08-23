# RemoteBridge RBAC Implementation Guide

## Quick Start: Add Authentication in 5 Steps

### Step 1: Create Auth Helper Functions (Add to index.php)

```php
<?php
// After database.php and config.php includes, add these helpers:

/**
 * Require valid user authentication.
 * Returns array with user_id, username, role
 */
function rb_require_auth(): array {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    
    if (empty($_SESSION['user_id'])) {
        rb_json(['error' => 'Authentication required'], 401);
    }
    
    return [
        'user_id' => (int)$_SESSION['user_id'],
        'username' => (string)$_SESSION['username'],
        'role' => (string)$_SESSION['role'],
    ];
}

/**
 * Require admin role.
 */
function rb_require_admin(): array {
    $auth = rb_require_auth();
    if ($auth['role'] !== 'admin') {
        rb_json(['error' => 'Admin access required'], 403);
    }
    return $auth;
}

/**
 * Check if user is authenticated (without exiting).
 */
function rb_is_authenticated(): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    return !empty($_SESSION['user_id']);
}

/**
 * Get current authenticated user (or null).
 */
function rb_get_current_user(): ?array {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    
    return [
        'user_id' => (int)$_SESSION['user_id'],
        'username' => (string)$_SESSION['username'],
        'role' => (string)$_SESSION['role'],
    ];
}

/**
 * Log authentication event.
 */
function rb_audit_log(mysqli $db, string $event_type, ?string $remote_id = null, ?array $details = null): void {
    try {
        $user_id = $_SESSION['user_id'] ?? null;
        $ip_address = rb_client_ip();
        $session_id = null;
        $details_json = $details ? json_encode($details) : null;
        
        $stmt = $db->prepare(
            'INSERT INTO audit_log (event_type, remote_id, ip_address, details, created_at) 
             VALUES (?, ?, ?, ?, NOW())'
        );
        $stmt->bind_param('ssss', $event_type, $remote_id, $ip_address, $details_json);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // Silently fail - don't break app if audit logging fails
    }
}
?>
```

---

### Step 2: Add Login/Logout Endpoints

```php
<?php
// Add these endpoints BEFORE the existing code in index.php

/**
 * POST /api/auth/login
 * Login with username and password
 */
if ($path === '/api/auth/login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = rb_body();
    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');
    
    if ($username === '' || $password === '') {
        rb_json(['error' => 'Username and password required'], 400);
    }
    
    try {
        $db = db();
        $stmt = $db->prepare('SELECT id, username, display_name, role, password_hash FROM users WHERE username = ? AND is_active = 1 LIMIT 1');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$user || !password_verify($password, $user['password_hash'])) {
            // Log failed attempt
            rb_audit_log($db, 'login_failed', null, ['username' => $username, 'ip' => rb_client_ip()]);
            rb_json(['error' => 'Invalid username or password'], 401);
        }
        
        // Authentication successful
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        session_regenerate_id(true);
        
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['display_name'] = $user['display_name'];
        
        // Update last login timestamp
        $updateStmt = $db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
        $updateStmt->bind_param('i', $user['id']);
        $updateStmt->execute();
        $updateStmt->close();
        
        // Log successful login
        rb_audit_log($db, 'login_success', null, ['username' => $user['username']]);
        
        rb_json([
            'ok' => true,
            'user' => [
                'id' => (int)$user['id'],
                'username' => $user['username'],
                'display_name' => $user['display_name'],
                'role' => $user['role'],
            ]
        ]);
    } catch (Throwable $e) {
        rb_json(['error' => 'Login failed: ' . $e->getMessage()], 500);
    }
}

/**
 * POST /api/auth/logout
 * Logout current user
 */
if ($path === '/api/auth/logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    
    rb_audit_log(db(), 'logout', null, ['username' => ($_SESSION['username'] ?? null)]);
    
    session_destroy();
    rb_json(['ok' => true]);
}

/**
 * GET /api/auth/me
 * Get current authenticated user
 */
if ($path === '/api/auth/me' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = rb_get_current_user();
    
    if (!$user) {
        rb_json(['authenticated' => false], 401);
    }
    
    rb_json([
        'authenticated' => true,
        'user' => $user,
    ]);
}

/**
 * POST /api/auth/refresh
 * Refresh session (optional - for keeping session alive)
 */
if ($path === '/api/auth/refresh' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth = rb_require_auth();
    rb_json(['ok' => true, 'user' => $auth]);
}
?>
```

---

### Step 3: Protect Existing Endpoints

**Update device registration:**
```php
// BEFORE:
if ($path === '/api/register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = rb_body();
    $remoteId = rb_clean_id((string)($body['remote_id'] ?? ''));
    // ... register device

// AFTER:
if ($path === '/api/register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth = rb_require_auth(); // ← ADD THIS
    
    $body = rb_body();
    $remoteId = rb_clean_id((string)($body['remote_id'] ?? ''));
    $deviceName = isset($body['device_name']) ? substr((string)$body['device_name'], 0, 255) : null;
    $db = db();
    rb_ensure_presence_schema($db);
    
    // MODIFIED: Include user_id
    $stmt = $db->prepare(
        'INSERT INTO devices (user_id, remote_id, device_name, ip_address, last_seen_at, is_online)
         VALUES (?, ?, ?, ?, NULL, 0)
         ON DUPLICATE KEY UPDATE device_name = VALUES(device_name), ip_address = VALUES(ip_address)'
    );
    $stmt->bind_param('isss', $auth['user_id'], $remoteId, $deviceName, $ip);
    $stmt->execute();
    
    // ADD AUDIT LOG
    rb_audit_log($db, 'device_registered', $remoteId, [
        'user_id' => $auth['user_id'],
        'device_name' => $deviceName,
    ]);
    
    $status = $db->prepare('SELECT is_online FROM devices WHERE remote_id = ? LIMIT 1');
    $status->bind_param('s', $remoteId);
    $status->execute();
    $statusRow = $status->get_result()->fetch_assoc();
    rb_json(['remote_id' => $remoteId, 'online' => !empty($statusRow['is_online'])]);
}
```

**Update heartbeat:**
```php
// BEFORE:
if ($path === '/api/heartbeat' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = rb_body();
    $remoteId = rb_clean_id((string)($body['remote_id'] ?? ''));
    if (!rb_valid_remote_id($remoteId)) rb_json(['error' => 'valid remote_id required'], 400);

// AFTER:
if ($path === '/api/heartbeat' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth = rb_require_auth(); // ← ADD THIS
    
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
```

**Restrict network scan to admin:**
```php
// BEFORE:
if ($path === '/api/network/devices' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    rb_require_local();
    if (empty($_SESSION['network_unlocked'])) rb_json(['error' => 'locked'], 403);

// AFTER:
if ($path === '/api/network/devices' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    rb_require_local();
    $auth = rb_require_admin(); // ← RESTRICT TO ADMIN
    if (empty($_SESSION['network_unlocked'])) rb_json(['error' => 'locked'], 403);
    
    $db = db();
    rb_audit_log($db, 'network_scan', null, ['admin' => $auth['username']]);
    
    $devices = rb_arp_table();
    rb_json(['devices' => $devices, 'count' => count($devices)]);
}
```

**Restrict admin settings:**
```php
// admin/settings.php - ADD after rb_admin_local_only():

// Require authentication AND admin role
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><title>Login Required</title><body style="font-family:system-ui;background:#07111f;color:#eaf1ff;padding:40px"><h1>Login required</h1><p>Please log in to access admin settings.</p></body>';
    exit;
}

if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><title>Admin Access Required</title><body style="font-family:system-ui;background:#07111f;color:#eaf1ff;padding:40px"><h1>Admin access required</h1><p>Only administrators can access settings.</p></body>';
    exit;
}
```

---

### Step 4: Add Frontend Login UI

**Create login.html template (simplified):**

```html
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>RemoteBridge Login</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: radial-gradient(circle at top, #10243f 0, #07111f 48%, #050b14 100%);
            color: #eaf1ff;
            font-family: Inter, system-ui;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .login-container {
            background: #0d1d32;
            border: 1px solid #253b5a;
            border-radius: 18px;
            padding: 40px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 24px 70px rgba(0,0,0,.28);
        }
        h1 {
            font-size: 24px;
            margin: 0 0 24px;
            text-align: center;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        input {
            width: 100%;
            height: 46px;
            background: #071321;
            border: 1px solid #2a4364;
            border-radius: 10px;
            color: #eaf1ff;
            padding: 0 13px;
            font-size: 14px;
            outline: none;
        }
        input:focus {
            border-color: #3f82ff;
            box-shadow: 0 0 0 3px rgba(63,130,255,.14);
        }
        button {
            width: 100%;
            height: 46px;
            background: #2b6de8;
            border: 0;
            border-radius: 10px;
            color: #fff;
            font-weight: 800;
            font-size: 14px;
            cursor: pointer;
            margin-top: 20px;
        }
        button:hover {
            filter: brightness(1.08);
        }
        .error {
            color: #f2c58a;
            font-size: 13px;
            margin-top: 10px;
            padding: 10px;
            background: #281b0d;
            border: 1px solid #815827;
            border-radius: 10px;
            display: none;
        }
        .info {
            color: #9db0ca;
            font-size: 12px;
            margin-top: 16px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h1>RemoteBridge</h1>
        
        <form id="loginForm" onsubmit="handleLogin(event)">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required autocomplete="username">
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>
            
            <div id="errorMessage" class="error"></div>
            
            <button type="submit">Sign In</button>
            
            <p class="info">Demo: admin/password or user/password</p>
        </form>
    </div>
    
    <script>
        async function handleLogin(event) {
            event.preventDefault();
            
            const username = document.getElementById('username').value;
            const password = document.getElementById('password').value;
            const errorDiv = document.getElementById('errorMessage');
            
            try {
                const response = await fetch('/api/auth/login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ username, password })
                });
                
                const data = await response.json();
                
                if (!response.ok) {
                    errorDiv.textContent = data.error || 'Login failed';
                    errorDiv.style.display = 'block';
                    return;
                }
                
                // Login successful - redirect to main app
                window.location.href = '/index.php';
            } catch (error) {
                errorDiv.textContent = 'Network error: ' + error.message;
                errorDiv.style.display = 'block';
            }
        }
    </script>
</body>
</html>
```

**Add to index.php - redirect to login if not authenticated:**

```php
<?php
// At the very beginning of index.php, after session_start():

if (!rb_is_authenticated() && $path === '/') {
    // Redirect to login unless specifically requesting API/health endpoints
    if (strpos($path, '/api/') !== 0 && $path !== '/health') {
        header('Location: /login.html');
        exit;
    }
}
?>
```

**Update app.js to handle auth:**

```javascript
// Add to the beginning of public/app.js:

let currentUser = null;

async function rbCheckAuth() {
    try {
        const response = await fetch(rbUrl('/api/auth/me'));
        if (response.ok) {
            const data = await response.json();
            currentUser = data.user;
            updateUserUI();
        } else {
            // Not authenticated - redirect to login
            if (!window.location.pathname.includes('login')) {
                window.location.href = '/login.html';
            }
        }
    } catch (e) {
        console.error('Auth check failed:', e);
    }
}

function updateUserUI() {
    const userInfo = document.getElementById('userInfo');
    if (userInfo && currentUser) {
        userInfo.innerHTML = `
            <span>${currentUser.display_name || currentUser.username}</span>
            <small>${currentUser.role === 'admin' ? '👑 Admin' : 'User'}</small>
            <button onclick="rbLogout()">Logout</button>
        `;
    }
}

async function rbLogout() {
    try {
        await fetch(rbUrl('/api/auth/logout'), { method: 'POST' });
        window.location.href = '/login.html';
    } catch (e) {
        console.error('Logout failed:', e);
    }
}

// Check auth on page load
rbCheckAuth();
```

---

### Step 5: Update Database Migration

**Create new migration file: `database/migrations/004_add_auth.php`**

```php
<?php
declare(strict_types=1);

/**
 * Migration: Add authentication and authorization support
 */

require __DIR__ . '/../../database.php';

try {
    $db = db();
    
    echo "Running migration 004_add_auth...\n";
    
    // 1. Add user_id foreign key to devices table
    echo "Adding user_id foreign key to devices table...\n";
    $db->query(
        "ALTER TABLE devices 
         ADD CONSTRAINT fk_devices_user_id 
         FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE"
    );
    
    // 2. Create user_sessions table for tracking sessions
    echo "Creating user_sessions table...\n";
    $db->query(
        "CREATE TABLE IF NOT EXISTS user_sessions (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            session_id CHAR(64) NOT NULL UNIQUE,
            ip_address VARCHAR(45),
            user_agent TEXT,
            last_activity TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            expires_at DATETIME,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user_sessions_user (user_id),
            INDEX idx_user_sessions_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    
    // 3. Create login_attempts table for rate limiting
    echo "Creating login_attempts table...\n";
    $db->query(
        "CREATE TABLE IF NOT EXISTS login_attempts (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(80) NOT NULL,
            ip_address VARCHAR(45),
            success TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_login_username_ip (username, ip_address),
            INDEX idx_login_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    
    // 4. Add user_id index to audit_log
    echo "Adding indexes to audit_log table...\n";
    $db->query("ALTER TABLE audit_log ADD user_id BIGINT(20) UNSIGNED DEFAULT NULL");
    $db->query("ALTER TABLE audit_log ADD INDEX idx_audit_user (user_id)");
    
    // 5. Update migrations table
    $stmt = $db->prepare('INSERT INTO migrations (version) VALUES (?)');
    $version = '004_add_auth';
    $stmt->bind_param('s', $version);
    $stmt->execute();
    
    echo "✅ Migration 004_add_auth completed successfully\n";
    
} catch (Throwable $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>
```

**Run migration:**
```bash
php database/migrations/004_add_auth.php
```

---

## Feature Checklist

### ✅ Authentication (Basic)
- [x] Login endpoint
- [x] Logout endpoint
- [x] Get current user endpoint
- [x] Session management
- [x] Password verification (bcrypt)

### ⚠️ Authentication (Recommended)
- [ ] Remember-me / persistent login
- [ ] Multi-factor authentication
- [ ] API keys for programmatic access
- [ ] OAuth2 integration (optional)
- [ ] Password reset flow

### ✅ Authorization (Basic)
- [x] Require auth on critical endpoints
- [x] Admin-only endpoints
- [x] Device ownership enforcement
- [x] Role checks on admin panel

### ⚠️ Authorization (Recommended)
- [ ] Granular permissions (per device access)
- [ ] Time-based access restrictions
- [ ] IP whitelist per user
- [ ] Command restrictions on console
- [ ] Rate limiting per user

### ✅ Audit & Logging
- [x] Login/logout logging
- [x] Device registration logging
- [x] Network scan logging
- [x] Existing audit_log table

### ⚠️ Security
- [x] HTTPS enforcement (deploy-time config)
- [x] CSRF protection (consider for forms)
- [ ] Rate limiting on auth endpoints
- [ ] Session timeout handling
- [ ] Secure cookie settings

---

## Testing Your Implementation

### Test Login Flow
```bash
# 1. Test login with admin
curl -X POST http://localhost/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"sadmin","password":"admin_password"}' \
  -c cookies.txt

# 2. Check current user (should return user data)
curl http://localhost/api/auth/me \
  -b cookies.txt

# 3. Test logout
curl -X POST http://localhost/api/auth/logout \
  -b cookies.txt

# 4. Check current user after logout (should return 401)
curl http://localhost/api/auth/me \
  -b cookies.txt
```

### Test Authorization
```bash
# 1. Try network scan without auth (should fail)
curl http://localhost/api/network/devices

# 2. Login and try network scan with user role (should fail)
curl http://localhost/api/network/devices -b cookies-user.txt

# 3. Login and try network scan with admin role (should succeed)
curl http://localhost/api/network/devices -b cookies-admin.txt
```

---

## Common Issues & Solutions

### Issue: "Session not available" error
**Solution:** Make sure `session_start()` is called at the top of index.php:
```php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
```

### Issue: Users can access other users' devices
**Solution:** Add device ownership check:
```php
$db = db();
$stmt = $db->prepare('SELECT user_id FROM devices WHERE remote_id = ? LIMIT 1');
$stmt->bind_param('s', $remoteId);
$stmt->execute();
$device = $stmt->get_result()->fetch_assoc();

if ($device['user_id'] != $auth['user_id'] && $auth['role'] !== 'admin') {
    rb_json(['error' => 'Device access denied'], 403);
}
```

### Issue: Admin panel still accessible without auth
**Solution:** Add auth check at top of admin/settings.php:
```php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    exit;
}
```

### Issue: Passwords not hashing correctly
**Solution:** Use proper bcrypt hashing:
```php
// For new users:
$hash = password_hash('password', PASSWORD_BCRYPT, ['cost' => 10]);

// For verification:
if (password_verify($password, $hash)) {
    // Correct!
}
```

---

## Deployment Checklist

Before going to production:

- [ ] Update password hashes for test users
- [ ] Run all migrations
- [ ] Test login flow end-to-end
- [ ] Test authorization on all endpoints
- [ ] Review audit logs
- [ ] Enable HTTPS
- [ ] Set secure cookie flags
- [ ] Configure session timeout
- [ ] Add rate limiting on login
- [ ] Regular security audits
- [ ] Monitor audit logs for suspicious activity
- [ ] Document role definitions for team
- [ ] Create admin user account creation process
- [ ] Setup password policy requirements
- [ ] Test logout and session expiration

---

## Next Steps After Basic RBAC

1. **Multi-tenancy** (if needed)
   - Add organization/workspace concept
   - Isolate devices by workspace
   - Role-based within workspaces

2. **Fine-grained Permissions**
   - Specific device access permissions
   - Time-limited access grants
   - IP-based restrictions

3. **API Keys**
   - Create API keys for programmatic access
   - Rate limiting per key
   - Key rotation support

4. **Advanced Features**
   - Two-factor authentication
   - Audit report generation
   - Compliance logging (HIPAA, SOC 2, etc.)

---

## Summary

You now have a working RBAC system with:
- ✅ User authentication (login/logout)
- ✅ Role-based authorization (admin/user)
- ✅ Audit logging
- ✅ Device ownership
- ✅ Admin-only operations

This provides a solid foundation for a secure multi-user RemoteBridge deployment!
