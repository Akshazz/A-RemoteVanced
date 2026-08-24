<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
[, $basePath] = rb_resolve_route($requestPath);

// Already signed in? Skip straight to the app instead of showing the form.
if (rb_is_authenticated()) {
    header('Location: ' . $basePath . '/index.php');
    exit;
}

$error = null;
$usernameValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usernameValue = trim((string)($_POST['username'] ?? ''));

    if (!rb_csrf_verify($_POST['csrf_token'] ?? null)) {
        // Most likely a stale form (session expired / reopened from cache).
        // Re-issue a token below and ask the person to try again, rather
        // than silently failing.
        $error = 'Your session expired. Please try again.';
    } else {
        $password = (string)($_POST['password'] ?? '');
        $result = rb_attempt_login(db(), $usernameValue, $password);

        if ($result['ok']) {
            header('Location: ' . $basePath . '/index.php');
            exit;
        }

        $error = match ($result['error']) {
            'missing' => 'Enter your username and password.',
            'rate_limited' => 'Too many attempts. Please wait a few minutes and try again.',
            default => 'Invalid username or password.',
        };
    }
}

$csrfToken = rb_csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title> ARV Control System | Login </title>
<link rel="icon" type="image/x-icon" href="logo/favicon.ico">
<link rel="icon" type="image/png" sizes="16x16" href="logo/favicon-16.png">
<link rel="icon" type="image/png" sizes="32x32" href="logo/favicon-32.png">
<link rel="apple-touch-icon" sizes="180x180" href="logo/apple-touch-icon-180.png">
<style>
  :root{font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
  *{box-sizing:border-box}
  body{
    margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
    background:radial-gradient(circle at 20% 10%,#15315a 0,#07101f 42%,#050a13 100%);
    color:#eaf1ff;
  }
  .box{
    width:100%;max-width:380px;background:rgba(14,27,47,.9);border:1px solid #253b5a;
    border-radius:18px;padding:34px;box-shadow:0 20px 70px rgba(0,0,0,.3);
  }
  h1{margin:0 0 4px;font-size:22px}
  .sub{color:#9db0ca;font-size:13px;margin:0 0 24px}
  label{display:block;font-size:12.5px;font-weight:700;color:#bcd0ee;margin-bottom:6px}
  .field{margin-bottom:16px}
  input{
    width:100%;height:44px;padding:0 13px;border-radius:10px;border:1px solid #253b5a;
    background:#07101c;color:#eaf1ff;font-size:14px;outline:none;
  }
  input:focus{border-color:#3a6fbb;box-shadow:0 0 0 2px rgba(43,109,232,.18)}
  button{
    width:100%;height:44px;margin-top:8px;border:0;border-radius:10px;background:#2b6de8;
    color:#fff;font-weight:700;font-size:14px;cursor:pointer;
  }
  button:hover{background:#3a7bf0}
  .error{
    margin-top:14px;padding:10px 12px;border-radius:10px;font-size:13px;
    background:#281b0d;border:1px solid #815827;color:#f2c58a;
  }
</style>
</head>
<body>
  <div class="box">
    <h1>RemoteBridge</h1>
    <p class="sub">Sign in to continue</p>
    <form method="post" action="">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
      <div class="field">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" autocomplete="username" required autofocus
               value="<?= htmlspecialchars($usernameValue, ENT_QUOTES) ?>">
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" autocomplete="current-password" required>
      </div>
      <button type="submit">Sign in</button>
      <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
      <?php endif; ?>
    </form>
  </div>
</body>
</html>
