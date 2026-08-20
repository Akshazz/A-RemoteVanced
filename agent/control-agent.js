#!/usr/bin/env node
/**
 * RemoteBridge control agent
 * -----------------------------------------------------------------------
 * Runs on the HOST machine (the one being remote-controlled) and is the
 * only part of this project that touches the real OS mouse/keyboard.
 * The browser tab cannot do this itself — browsers deliberately do not let
 * a web page control the operating system — so this small local process
 * bridges the gap.
 *
 * On Windows, input is injected via a small PowerShell helper
 * (win-input.ps1) that calls user32.dll directly — no compiler, no
 * Python, no node-gyp required.
 *
 * Security model:
 *   - Binds to 127.0.0.1 by default. LAN binding is opt-in through
 *     RB_AGENT_HOST=0.0.0.0 and still requires the random token.
 *   - Requires a random token (printed on first run, saved to .token)
 *     that the host must paste into the RemoteBridge page before the
 *     browser tab is allowed to send it commands. This stops any other
 *     tab/website open in the same browser from silently commanding it.
 *   - Only moves the mouse / sends keystrokes — it does not read the
 *     screen, files, or anything else on the machine.
 *
 * Only run this if you intend to let a specific, trusted remote person
 * control this computer, and only while that session is active. Close
 * this process (Ctrl+C) to immediately revoke all control.
 *
 * Setup:
 *   cd agent
 *   npm install
 *   node control-agent.js
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

function loadOrCreateToken() {
  if (fs.existsSync(TOKEN_FILE)) {
    return fs.readFileSync(TOKEN_FILE, 'utf8').trim();
  }
  const token = crypto.randomBytes(24).toString('hex');
  fs.writeFileSync(TOKEN_FILE, token, { mode: 0o600 });
  return token;
}

const TOKEN = loadOrCreateToken();
let authenticatedClients = 0;

/* ------------------------- Input backend ------------------------- */

let inputProc = null;
let inputReady = false;

function startInputBackend() {
  if (process.platform !== 'win32') {
    console.error(
      '\nThis agent currently only supports Windows (via user32.dll through PowerShell).\n' +
      'macOS/Linux support would need a different native backend (e.g. robotjs or nut-js)\n' +
      'and isn\'t included in this build.\n'
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
  if (!inputReady || !inputProc || !inputProc.stdin.writable) return;
  try {
    inputProc.stdin.write(JSON.stringify(cmd) + '\n');
  } catch (e) {
    console.error('Failed to send command to input backend:', e.message);
  }
}

startInputBackend();

/* ---------------------------- Server ---------------------------- */

const wss = new WebSocketServer({ host: HOST, port: PORT });
const displayHost = HOST === '0.0.0.0' ? '<server-LAN-IP>' : HOST;
console.log(`RemoteBridge control agent listening on ws://${displayHost}:${PORT}`);
console.log(`Agent bind address: ${HOST}:${PORT}`);
console.log(`Token (paste this into the RemoteBridge Host tab): ${TOKEN}`);
console.log('Press Ctrl+C to stop and revoke control at any time.\n');

wss.on('connection', (ws) => {
  let authed = false;
  ws.on('message', (raw) => {
    let msg;
    try { msg = JSON.parse(raw.toString()); } catch (_) { return; }

    if (!authed) {
      if (msg.type === 'auth' && msg.token === TOKEN) {
        authed = true;
        ws.send(JSON.stringify({ type: 'auth_ok' }));
        authenticatedClients += 1;
        console.log(`Control client authenticated (active: ${authenticatedClients}).`);
      } else {
        ws.send(JSON.stringify({ type: 'auth_fail' }));
        ws.close();
      }
      return;
    }
    applyCommand(msg);
  });
  ws.on('close', () => {
    if (authed) {
      authenticatedClients = Math.max(0, authenticatedClients - 1);
      console.log(`Control client disconnected (active: ${authenticatedClients}).`);
    }
  });
});

process.on('SIGINT', () => {
  console.log('\nStopping — all control revoked.');
  if (inputProc) inputProc.kill();
  process.exit(0);
});
