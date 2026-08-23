<?php
declare(strict_types=1);

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
$base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>RemoteBridge — Network Settings</title>
<style>
:root{font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#eaf1ff;background:#07101f}*{box-sizing:border-box}body{margin:0;min-height:100vh;background:radial-gradient(circle at 20% 10%,#15315a 0,#07101f 42%,#050a13 100%)}.nav{height:64px;border-bottom:1px solid #1c304b;background:#081425;display:flex;align-items:center;justify-content:space-between;padding:0 28px}.brand{font-weight:800}.sub,.muted{color:#9db0ca;font-size:13px}.nav a{color:#bcd3f2;text-decoration:none}.wrap{max-width:820px;margin:40px auto;padding:0 20px}.card{border:1px solid #294668;border-radius:16px;background:#0a192c;padding:26px;box-shadow:0 18px 60px rgba(0,0,0,.25)}h1{margin:0 0 8px;font-size:22px}h2{font-size:16px;margin:26px 0 8px}.notice{border:1px solid #31557d;background:#0b1c31;border-radius:12px;padding:14px;margin-top:18px;color:#c8d7ea}.meta{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:18px}.meta div{background:#091727;border:1px solid #1b304c;border-radius:10px;padding:12px}.meta strong{display:block;font-size:11px;color:#7f95b4;margin-bottom:4px}.meta span{font-size:12.5px}@media(max-width:650px){.meta{grid-template-columns:1fr}.nav{padding:0 14px}.sub{display:none}.wrap{margin:20px auto}}
</style>
</head>
<body>
<header class="nav"><div><div class="brand">RemoteBridge</div><span class="sub">Administration / Network discovery</span></div><a href="<?=h($base . '/index.php')?>">← Back to RemoteBridge</a></header>
<main class="wrap"><section class="card">
<h1>Network Discovery</h1>
<p class="muted">The Network Access Code has been removed from this build. Devices on the local network can now be discovered directly from the main RemoteBridge page.</p>
<div class="meta">
  <div><strong>ACCESS</strong><span>Direct local discovery</span></div>
  <div><strong>DISCOVERY</strong><span>Full detected subnet sweep</span></div>
  <div><strong>RESULTS</strong><span>IP, MAC and hostname when available</span></div>
</div>
<h2>How it works</h2>
<p class="muted">Click <strong>Scan all devices</strong> in the Devices on this network panel. RemoteBridge detects the server's local IPv4 subnet, probes the available host range, refreshes the server's ARP table, and displays the devices it can see.</p>
<div class="notice"><strong>No access code is required.</strong> The discovery API remains restricted to the same machine that runs the RemoteBridge PHP server because it launches a local network scan.</div>
</section></main>
</body></html>
