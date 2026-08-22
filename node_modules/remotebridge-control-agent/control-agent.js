#!/usr/bin/env node
/**
 * RemoteBridge control agent
 * -----------------------------------------------------------------------
 * Runs on the HOST machine (the one being remote-controlled) and is the
 * only part of this project that touches the real OS mouse/keyboard, and
 * (optionally) a real shell. The browser tab cannot do this itself —
 * browsers deliberately do not let a web page control the operating
 * system — so this small local process bridges the gap.
 *
 * On Windows, pointer/keyboard input is injected via a small PowerShell
 * helper (win-input.ps1) that calls user32.dll directly — no compiler, no
 * Python, no node-gyp required.
 *
 * Security model:
 *   - Binds to 127.0.0.1 by default. LAN binding is opt-in through
 *     RB_AGENT_HOST=0.0.0.0 and still requires the random token.
 *   - Requires a random token (printed on first run, saved to .token)
 *     that the host must paste into the RemoteBridge page before the
 *     browser tab is allowed to send it commands. This stops any other
 *     tab/website open in the same browser from silently commanding it.
 *   - By default the agent only moves the mouse / sends keystrokes — it
 *     does not read the screen, files, or run commands.
 *   - The remote console/shell (git-bash / cmd / bash) is a SEPARATE,
 *     opt-in capability. It is disabled unless you explicitly set
 *     RB_AGENT_ENABLE_CONSOLE=1 when starting the agent, because it lets
 *     the authenticated browser tab run arbitrary commands on this
 *     machine — a much bigger trust decision than mouse/keyboard input.
 *     Only turn it on if you specifically intend to give the connected
 *     viewer a command line, and only while that session is active.
 *
 * Only run this if you intend to let a specific, trusted remote person
 * control this computer, and only while that session is active. Close
 * this process (Ctrl+C) to immediately revoke all control (and kill any
 * running console session).
 *
 * Setup:
 *   cd agent
 *   npm install
 *   node control-agent.js
 *   # or, to also allow the remote console feature:
 *   RB_AGENT_ENABLE_CONSOLE=1 node control-agent.js   (macOS/Linux)
 *   set RB_AGENT_ENABLE_CONSOLE=1 && node control-agent.js   (Windows cmd)
 *
 * Then paste the printed token into the "Native control agent" field on
 * the Host tab of the RemoteBridge page and click Connect.
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { spawn } = require('child_process');
const { WebSocketServer } = require('ws');

const HOST = process.env.RB_AGENT_HOST || '127.0.0.1';
const PORT = Number(process.env.RB_AGENT_PORT || 8791);
const TOKEN_FILE = path.join(__dirname, '.token');
const PS_SCRIPT = path.join(__dirname, 'win-input.ps1');
const STOP_FILE = path.join(__dirname, '.stop-request');

// Remote console is opt-in — see the security note above.
const CONSOLE_ENABLED = /^(1|true|yes)$/i.test(process.env.RB_AGENT_ENABLE_CONSOLE || '');
// Explicit shell override, e.g. RB_AGENT_SHELL="C:\\Program Files\\Git\\bin\\bash.exe"
const SHELL_OVERRIDE = process.env.RB_AGENT_SHELL || '';
// Safety limits for the console channel.
const MAX_CONSOLE_INPUT_BYTES = 8192;
const MAX_CONSOLE_CLIENTS = 4;

function loadOrCreateToken() {
  if (fs.existsSync(TOKEN_FILE)) {
    return fs.readFileSync(TOKEN_FILE, 'utf8').trim();
  }
  const token = crypto.randomBytes(24).toString('hex');
  fs.writeFileSync(TOKEN_FILE, token, { mode: 0o600 });
  return token;
}

const TOKEN = loadOrCreateToken();

/* Graceful shutdown requested by the local PHP Host UI. The marker-file
 * mechanism avoids force-killing Node/PowerShell and lets the agent revoke
 * authenticated clients and terminate the remote shell cleanly. */
let shuttingDown = false;
function requestShutdown(reason = 'Agent stopped by Host UI') {
  if (shuttingDown) return;
  shuttingDown = true;
  console.log(`\\n${reason}`);
  stopShell();
  for (const ws of authenticatedSockets) {
    try { ws.send(JSON.stringify({ type: 'agent_stopped', message: reason })); } catch (_) {}
    try { ws.close(); } catch (_) {}
  }
  authenticatedSockets.clear();
  if (inputProc) {
    try { inputProc.kill(); } catch (_) {}
    inputProc = null;
    inputReady = false;
  }
  try { wss.close(); } catch (_) {}
  try { if (fs.existsSync(STOP_FILE)) fs.unlinkSync(STOP_FILE); } catch (_) {}
  setTimeout(() => process.exit(0), 100);
}

/** Sockets that have successfully authenticated with TOKEN. A Set (not just
 * a counter) so the console feature can broadcast output to every
 * authenticated tab, and so a socket can be dropped from tracking exactly
 * once even if 'close' fires more than once. */
const authenticatedSockets = new Set();

/* ------------------------- Pointer/keyboard input backend ------------------------- */

let inputProc = null;
let inputReady = false;

function startInputBackend() {
  if (process.platform !== 'win32') {
    console.error(
      '\nMouse/keyboard control currently only supports Windows (via user32.dll through PowerShell).\n' +
      'macOS/Linux support would need a different native backend (e.g. robotjs or nut-js)\n' +
      'and isn\'t included in this build. The remote console feature below still works on\n' +
      'macOS/Linux if you enable it.\n'
    );
    return;
  }
  inputProc = spawn('powershell.exe', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', PS_SCRIPT], {
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  inputProc.on('spawn', () => { inputReady = true; console.log('Input backend ready (PowerShell/user32.dll).'); });
  inputProc.on('error', (e) => { inputReady = false; console.error('Failed to start input backend:', e.message); });
  inputProc.on('exit', (code) => { inputReady = false; console.error(`Input backend exited (code ${code}).`); });
  inputProc.stderr.on('data', (d) => console.error('[win-input]', d.toString().trim()));
}

function applyCommand(cmd) {
  if (!inputReady || !inputProc || !inputProc.stdin.writable || !cmd || typeof cmd !== 'object') return;

  // Only the small input protocol used by the browser is accepted. Do not
  // forward arbitrary JSON to the PowerShell backend.
  const type = String(cmd.type || '');
  if (!['move', 'button', 'wheel', 'key'].includes(type)) return;

  const safe = { type };
  if (type === 'move' || type === 'button') {
    const x = Number(cmd.x), y = Number(cmd.y);
    if (!Number.isFinite(x) || !Number.isFinite(y) || x < 0 || x > 1 || y < 0 || y > 1) return;
    safe.x = x;
    safe.y = y;
  }
  if (type === 'button') {
    const button = Number(cmd.button);
    const action = String(cmd.action || '');
    if (![0, 1, 2].includes(button) || !['down', 'up'].includes(action)) return;
    safe.button = button;
    safe.action = action;
  }
  if (type === 'wheel') {
    const deltaY = Number(cmd.deltaY);
    if (!Number.isFinite(deltaY) || Math.abs(deltaY) > 10000) return;
    safe.deltaY = deltaY;
  }
  if (type === 'key') {
    const key = String(cmd.key || '');
    const action = String(cmd.action || '');
    if (key.length === 0 || key.length > 32 || !['down', 'up'].includes(action)) return;
    safe.key = key;
    safe.action = action;
  }

  try {
    inputProc.stdin.write(JSON.stringify(safe) + '\n');
  } catch (e) {
    console.error('Failed to send command to input backend:', e.message);
  }
}

startInputBackend();

/* ------------------------------ Remote console ------------------------------ */
/* A single shared shell process (git-bash / bash / cmd, whichever is found)
 * that every authenticated tab's console panel is attached to. Output is
 * broadcast to all authenticated sockets; any of them can type input. This
 * mirrors "one host, one screen" — if you want isolated sessions per
 * viewer, run separate agent instances on different ports instead. */

let shellProc = null;
let shellStarting = false;

/** Look for Git for Windows' bash.exe in the usual install locations before
 * falling back to cmd.exe. Returns {cmd, args} for child_process.spawn. */
function resolveShell() {
  if (SHELL_OVERRIDE) return { cmd: SHELL_OVERRIDE, args: ['-i'] };

  if (process.platform === 'win32') {
    const candidates = [
      process.env.ProgramFiles && path.join(process.env.ProgramFiles, 'Git', 'bin', 'bash.exe'),
      process.env['ProgramFiles(x86)'] && path.join(process.env['ProgramFiles(x86)'], 'Git', 'bin', 'bash.exe'),
      process.env.LOCALAPPDATA && path.join(process.env.LOCALAPPDATA, 'Programs', 'Git', 'bin', 'bash.exe'),
    ].filter(Boolean);
    for (const candidate of candidates) {
      if (fs.existsSync(candidate)) return { cmd: candidate, args: ['-i'] };
    }
    // No Git Bash found — fall back to the standard Windows shell.
    return { cmd: 'cmd.exe', args: [] };
  }

  const shell = process.env.SHELL || '/bin/bash';
  return { cmd: shell, args: ['-i'] };
}

function broadcastConsole(msg) {
  const payload = JSON.stringify(msg);
  for (const ws of authenticatedSockets) {
    if (ws.readyState === ws.OPEN) {
      try { ws.send(payload); } catch (_) { /* ignore a single bad send */ }
    }
  }
}

function startShell() {
  if (shellProc || shellStarting) return;
  shellStarting = true;
  const { cmd, args } = resolveShell();
  try {
    shellProc = spawn(cmd, args, {
      cwd: process.env.USERPROFILE || process.env.HOME || __dirname,
      env: process.env,
      stdio: ['pipe', 'pipe', 'pipe'],
    });
  } catch (e) {
    shellStarting = false;
    broadcastConsole({ type: 'console_error', message: `Failed to start shell (${cmd}): ${e.message}` });
    return;
  }

  shellStarting = false;
  console.log(`Remote console started (${cmd}).`);
  broadcastConsole({ type: 'console_started', shell: cmd });

  shellProc.stdout.on('data', (chunk) => broadcastConsole({ type: 'console_output', data: chunk.toString('utf8') }));
  shellProc.stderr.on('data', (chunk) => broadcastConsole({ type: 'console_output', data: chunk.toString('utf8') }));
  shellProc.on('exit', (code) => {
    console.log(`Remote console exited (code ${code}).`);
    broadcastConsole({ type: 'console_exit', code });
    shellProc = null;
  });
  shellProc.on('error', (e) => {
    broadcastConsole({ type: 'console_error', message: e.message });
    shellProc = null;
  });
}

function stopShell() {
  if (!shellProc) return;
  try {
    if (process.platform === 'win32') {
      spawn('taskkill', ['/T', '/F', '/PID', String(shellProc.pid)]);
    } else {
      shellProc.kill('SIGTERM');
    }
  } catch (_) { /* best effort */ }
  shellProc = null;
  broadcastConsole({ type: 'console_exit', code: null });
}

function writeShellInput(data) {
  if (!shellProc || !shellProc.stdin.writable) return;
  const text = String(data ?? '');
  if (text.length === 0) return;
  if (Buffer.byteLength(text, 'utf8') > MAX_CONSOLE_INPUT_BYTES) {
    broadcastConsole({ type: 'console_error', message: 'Input rejected: exceeds per-message size limit.' });
    return;
  }
  try {
    shellProc.stdin.write(text);
  } catch (e) {
    broadcastConsole({ type: 'console_error', message: `Failed to write to shell: ${e.message}` });
  }
}

function handleConsoleMessage(cmd) {
  if (!CONSOLE_ENABLED) {
    broadcastConsole({ type: 'console_error', message: 'Remote console is disabled on this agent. Restart it with RB_AGENT_ENABLE_CONSOLE=1 to allow it.' });
    return;
  }
  switch (cmd.type) {
    case 'console_start':
      if (authenticatedSockets.size > MAX_CONSOLE_CLIENTS) return; // simple abuse guard
      startShell();
      break;
    case 'console_input':
      writeShellInput(cmd.data);
      break;
    case 'console_stop':
      stopShell();
      break;
  }
}

/* ---------------------------- Server ---------------------------- */

const wss = new WebSocketServer({ host: HOST, port: PORT });

const stopWatcher = setInterval(() => {
  if (!shuttingDown && fs.existsSync(STOP_FILE)) {
    requestShutdown('Agent stopped from the RemoteBridge Host interface');
  }
}, 300);


const displayHost = HOST === '0.0.0.0' ? '<server-LAN-IP>' : HOST;
console.log(`RemoteBridge control agent listening on ws://${displayHost}:${PORT}`);
console.log(`Agent bind address: ${HOST}:${PORT}`);
console.log(`Token (paste this into the RemoteBridge Host tab): ${TOKEN}`);
console.log(`Remote console: ${CONSOLE_ENABLED ? 'ENABLED — the connected viewer can run commands if the host checks "Allow remote console"' : 'disabled (set RB_AGENT_ENABLE_CONSOLE=1 to allow it)'}`);
console.log('Press Ctrl+C to stop and revoke control at any time.\n');

wss.on('connection', (ws) => {
  let authed = false;
  ws.on('message', (raw) => {
    let msg;
    try { msg = JSON.parse(raw.toString()); } catch (_) { return; }

    if (!authed) {
      if (msg.type === 'auth' && msg.token === TOKEN) {
        authed = true;
        ws.send(JSON.stringify({ type: 'auth_ok', console_enabled: CONSOLE_ENABLED }));
        authenticatedSockets.add(ws);
        console.log(`Control client authenticated (active: ${authenticatedSockets.size}).`);
      } else {
        ws.send(JSON.stringify({ type: 'auth_fail' }));
        ws.close();
      }
      return;
    }

    if (typeof msg.type === 'string' && msg.type.startsWith('console_')) {
      handleConsoleMessage(msg);
    } else {
      applyCommand(msg);
    }
  });
  ws.on('close', () => {
    if (authenticatedSockets.delete(ws)) {
      console.log(`Control client disconnected (active: ${authenticatedSockets.size}).`);
      // Nobody left to see or type into the console — tear it down so it
      // doesn't keep running unattended.
      if (authenticatedSockets.size === 0) stopShell();
    }
  });
});

process.on('SIGINT', () => requestShutdown('Agent stopped from local console'));
process.on('SIGTERM', () => requestShutdown('Agent stopped by system request'));
process.on('exit', () => {
  try { clearInterval(stopWatcher); } catch (_) {}
  try { if (fs.existsSync(STOP_FILE)) fs.unlinkSync(STOP_FILE); } catch (_) {}
});
