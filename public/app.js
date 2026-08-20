/* RemoteBridge client — WebRTC screen sharing + remote control, paired via a
 * Remote ID. The server (index.php) only relays small signaling messages
 * (SDP offer/answer + ICE candidates). Video and control events both travel
 * peer-to-peer over WebRTC once connected.
 *
 * IMPORTANT: a browser tab cannot move the OS mouse or type into other
 * applications — that's a deliberate browser sandbox limit. Real input
 * injection on the host machine happens through a small local companion
 * agent (see /agent/control-agent.js) that the host runs and authorizes
 * with a token. Without that agent running, control events are received
 * but not applied. See README.md.
 */

const ICE_SERVERS_FALLBACK = [{ urls: 'stun:stun.l.google.com:19302' }];
let ICE_SERVERS = ICE_SERVERS_FALLBACK;
const POLL_MS = 2000;
let AGENT_WS_URL = null;
let AGENT_CONFIG = null;

// Presence is a user-controlled state. It survives a normal page refresh,
// but it changes only when the user presses Go online / Go offline.
let hostOnline = false;
try { hostOnline = localStorage.getItem('rb_host_online') === '1'; } catch (_) {}
let hostHeartbeatTimer = null;
let hostPendingPollTimer = null;

function rbIsPrivateHost(hostname) {
  if (hostname === 'localhost' || hostname === '127.0.0.1' || hostname === '::1') return true;
  const m = hostname.match(/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/);
  if (!m) return false;
  const [a, b] = [parseInt(m[1], 10), parseInt(m[2], 10)];
  return a === 10 || (a === 172 && b >= 16 && b <= 31) || (a === 192 && b === 168);
}

/** Detect whether this page is being served locally/LAN or over the public
 * internet, and whether a TURN relay is configured server-side, then reflect
 * that in the "Connection mode" badges at the top of the page. */
async function rbDetectConnectionMode() {
  const isLocal = rbIsPrivateHost(location.hostname);
  const badge = document.getElementById('connModeBadge');
  if (badge) {
    badge.className = 'badge ' + (isLocal ? 'local' : 'internet');
    badge.textContent = isLocal ? 'Local Network' : 'Internet (server-based)';
  }
  try {
    const data = await rbApi('/api/ice-servers', { method: 'GET' });
    if (Array.isArray(data.ice_servers) && data.ice_servers.length) ICE_SERVERS = data.ice_servers;
    const turnBadge = document.getElementById('turnBadge');
    if (turnBadge) {
      turnBadge.classList.remove('hidden');
      if (data.turn_source === 'custom') {
        turnBadge.className = 'badge local';
        turnBadge.textContent = 'TURN relay: your own server';
      } else if (data.turn_source === 'free-fallback') {
        turnBadge.className = 'badge internet';
        turnBadge.textContent = 'TURN relay: free shared (Open Relay)';
      } else if (!isLocal) {
        turnBadge.className = 'badge warn';
        turnBadge.textContent = 'TURN relay disabled — restrictive networks may fail to connect';
      } else {
        turnBadge.classList.add('hidden');
      }
    }
  } catch (e) { /* fall back to STUN-only */ }
}
rbDetectConnectionMode();

/* -------------------- Initial setup modal -------------------- */
/* First-run "Local vs Internet" quick setup + an advanced/manual guide,
 * shown once before the person dives into the rest of the page. Reopenable
 * any time via the "Setup guide" button in the navbar. This only stores a
 * preference/hint locally — actual connection mode stays auto-detected from
 * the URL (see rbDetectConnectionMode above), same as before. */

function rbShowSetupTab(which) {
  document.getElementById('setupTabQuick').classList.toggle('active', which === 'quick');
  document.getElementById('setupTabAdvanced').classList.toggle('active', which === 'advanced');
  document.getElementById('setupPanelQuick').classList.toggle('hidden', which !== 'quick');
  document.getElementById('setupPanelAdvanced').classList.toggle('hidden', which !== 'advanced');
}

function rbSelectSetupMode(mode) {
  localStorage.setItem('rb_setup_mode', mode);
  document.getElementById('setupOptionLocal').classList.toggle('selected', mode === 'local');
  document.getElementById('setupOptionInternet').classList.toggle('selected', mode === 'internet');
  const tip = document.getElementById('setupTip');
  tip.classList.remove('hidden');
  tip.textContent = mode === 'local'
    ? 'Tip: open this page at http://localhost:8080 (or this machine\'s 192.168.x.x address) on both computers. No TURN server needed — just click "Go online" and share the Remote ID.'
    : 'Tip: deploy index.php + MySQL to a public HTTPS host so both sides can reach it. A free shared TURN relay is used automatically — see the Advanced tab to configure your own for production use.';
}

function rbOpenSetupModal() {
  document.getElementById('setupModal').classList.remove('hidden');
}

function rbCloseSetupModal() {
  document.getElementById('setupModal').classList.add('hidden');
  const dontShow = document.getElementById('setupDontShow');
  if (dontShow && dontShow.checked) {
    localStorage.setItem('rb_setup_dismissed', '1');
  } else {
    localStorage.removeItem('rb_setup_dismissed');
  }
}

document.addEventListener('DOMContentLoaded', () => {
  const savedMode = localStorage.getItem('rb_setup_mode');
  const detectedMode = rbIsPrivateHost(location.hostname) ? 'local' : 'internet';
  rbSelectSetupMode(savedMode || detectedMode);
  if (!localStorage.getItem('rb_setup_dismissed')) rbOpenSetupModal();
});

/* -------------------- Local network devices sidebar -------------------- */
/* Reads this server's own ARP cache via /api/network/devices (local-machine
 * only, same as the agent endpoints) and is itself gated behind an access
 * code (checked server-side, session-based) before any device data loads. */

function rbEsc(s) {
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function rbDeviceTile(d) {
  const name = rbEsc(d.hostname || ('Device ' + d.ip.split('.').pop()));
  const mac = rbEsc((d.mac || '').toUpperCase());
  const ip = rbEsc(d.ip);
  return `<div class="device-tile">
    <div class="dname"><span class="dot"></span>${name}</div>
    <div class="drow"><span>IP</span><span>${ip}</span></div>
    <div class="drow"><span>MAC</span><span>${mac || 'unknown'}</span></div>
  </div>`;
}

function rbShowDeviceLockState(unlocked) {
  document.getElementById('deviceLocked').classList.toggle('hidden', unlocked);
  document.getElementById('deviceUnlocked').classList.toggle('hidden', !unlocked);
}

async function rbCheckDeviceLock() {
  try {
    const data = await rbApi('/api/network/status', { method: 'GET' });
    rbShowDeviceLockState(!!data.unlocked);
    if (data.unlocked) { rbLoadDevices(); rbLoadLocalUsers(); }
  } catch (e) { rbShowDeviceLockState(false); }
}

async function rbUnlockDevices() {
  const codeInput = document.getElementById('networkAccessCode');
  const errEl = document.getElementById('networkUnlockError');
  errEl.classList.add('hidden');
  try {
    await rbApi('/api/network/unlock', { method: 'POST', body: JSON.stringify({ code: codeInput.value.trim() }) });
    codeInput.value = '';
    rbShowDeviceLockState(true);
    rbLoadDevices();
    rbLoadLocalUsers();
  } catch (e) {
    errEl.textContent = e.message;
    errEl.classList.remove('hidden');
  }
}

async function rbLockDevices() {
  try { await rbApi('/api/network/lock', { method: 'POST' }); } catch (e) {}
  rbShowDeviceLockState(false);
  const grid = document.getElementById('localUsersGrid');
  const count = document.getElementById('localUserCount');
  if (grid) grid.innerHTML = '<div class="device-empty">Locked.</div>';
  if (count) count.textContent = '';
}

async function rbLoadDevices() {
  const grid = document.getElementById('deviceGrid');
  const countEl = document.getElementById('deviceCount');
  try {
    const data = await rbApi('/api/network/devices', { method: 'GET' });
    const devices = data.devices || [];
    countEl.textContent = devices.length ? `${devices.length} device${devices.length === 1 ? '' : 's'} seen` : '';
    grid.innerHTML = devices.length
      ? devices.map(rbDeviceTile).join('')
      : '<div class="device-empty">No devices in the ARP cache yet. Try "Scan network".</div>';
  } catch (e) {
    if (e.message === 'locked') { rbShowDeviceLockState(false); return; }
    grid.innerHTML = `<div class="device-empty">${e.message}</div>`;
    countEl.textContent = '';
  }
}

let rbNetworkScanTimer = null;

async function rbScanDevices() {
  const btn = document.getElementById('btnScanDevices');
  const grid = document.getElementById('deviceGrid');
  if (!btn || btn.dataset.scanning === '1') return;

  btn.dataset.scanning = '1';
  btn.disabled = true;
  btn.textContent = 'Scanning…';
  if (grid) grid.innerHTML = '<div class="device-empty">Scanning the local network…</div>';

  try {
    // The server owns the scan and enforces a hard timeout. There is no
    // browser polling loop and, importantly, no detached/background scanner.
    const result = await rbApi('/api/network/scan', { method: 'POST' });
    const devices = result.devices || [];
    const countEl = document.getElementById('deviceCount');
    if (countEl) countEl.textContent = devices.length
      ? `${devices.length} device${devices.length === 1 ? '' : 's'} seen`
      : 'No devices detected';
    if (grid) grid.innerHTML = devices.length
      ? devices.map(rbDeviceTile).join('')
      : '<div class="device-empty">No devices were detected on the local network.</div>';

    if (result.timed_out && grid) {
      const note = document.createElement('div');
      note.className = 'device-empty';
      note.textContent = 'Scan stopped safely at the time limit. Partial results are shown.';
      grid.appendChild(note);
    }
    await rbLoadLocalUsers();
  } catch (e) {
    if (e.message === 'locked') rbShowDeviceLockState(false);
    else if (grid) grid.innerHTML = `<div class="device-empty">${rbEsc(e.message)}</div>`;
  } finally {
    btn.disabled = false;
    btn.dataset.scanning = '0';
    btn.textContent = 'Scan network';
    if (rbNetworkScanTimer) {
      clearInterval(rbNetworkScanTimer);
      rbNetworkScanTimer = null;
    }
  }
}

let localUsersPollTimer = null;

function rbLocalUserTile(u) {
  const mac = u.mac ? rbEsc(u.mac.toUpperCase()) : 'MAC unavailable';
  const me = u.remote_id && u.remote_id === myRemoteId ? '<span class="badge local" style="font-size:9px;padding:2px 6px">You</span>' : '';
  const remote = u.remote_id ? `<div class="uid">Remote ID: ${rbEsc(u.remote_id)} <button type="button" class="remote-copy-btn" onclick="rbCopyRemoteId('${rbEsc(u.remote_id)}', this)">Copy</button> ${me}</div>` : '<div class="uid">Local network device</div>';
  const host = u.hostname && u.hostname !== u.device_name ? ` · Host: ${rbEsc(u.hostname)}` : '';
  const seen = u.last_seen_at ? ` · Last seen: ${rbEsc(u.last_seen_at)}` : '';
  return `<div class="local-user-tile">
    <div class="uname"><span class="udot"></span>${rbEsc(u.device_name || u.hostname || 'Unknown device')}</div>
    ${remote}
    <div class="umeta">IP: ${rbEsc(u.ip || 'Unknown')} · MAC: ${mac}${host}<br>Status: <strong>Online</strong>${seen}</div>
  </div>`;
}

async function rbLoadLocalUsers() {
  const grid = document.getElementById('localUsersGrid');
  const count = document.getElementById('localUserCount');
  if (!grid || !count) return;
  try {
    const data = await rbApi('/api/network/users', { method: 'GET' });
    const users = data.users || [];
    count.textContent = users.length ? `${users.length} local network device${users.length === 1 ? '' : 's'} detected` : 'No local network devices detected';
    grid.innerHTML = users.length ? users.map(rbLocalUserTile).join('') : '<div class="device-empty">No devices are currently visible on this local network. Click Scan network to refresh the ARP table.</div>';
  } catch (e) {
    if (e.message === 'locked') {
      grid.innerHTML = '<div class="device-empty">Unlock the network panel to view local users.</div>';
      count.textContent = '';
      return;
    }
    grid.innerHTML = `<div class="device-empty">${rbEsc(e.message)}</div>`;
    count.textContent = '';
  }
}

function rbStartLocalUsersPolling() {
  clearInterval(localUsersPollTimer);
  rbLoadLocalUsers();
  localUsersPollTimer = setInterval(rbLoadLocalUsers, 10000);
}

rbCheckDeviceLock().then?.(() => {});
rbStartLocalUsersPolling();

function rbLog(msg) {
  const el = document.getElementById('log');
  const line = `[${new Date().toLocaleTimeString()}] ${msg}`;
  el.textContent = (el.textContent === 'Ready.' ? '' : el.textContent + '\n') + line;
  el.scrollTop = el.scrollHeight;
}

async function rbApi(path, opts) {
  const res = await fetch(rbUrl(path), Object.assign({ headers: { 'Content-Type': 'application/json' } }, opts));
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || `Request failed (${res.status})`);
  return data;
}

/* ------------------- Device-info lookup (shared) ------------------- */
/* Used on both sides of a session so each party can see not just the other
 * side's Remote ID, but what device it is, its IP, and — when it's genuinely
 * reachable on this server's own local network (and the Devices sidebar is
 * unlocked) — its MAC address too. */

const rbDeviceInfoCache = new Map();

async function rbGetDeviceInfo(remoteId) {
  if (rbDeviceInfoCache.has(remoteId)) return rbDeviceInfoCache.get(remoteId);
  let info;
  try {
    const data = await rbApi(`/api/device/lookup?remote_id=${encodeURIComponent(remoteId)}`, { method: 'GET' });
    info = {
      deviceName: data.device_name || 'Unknown device',
      ip: data.ip_address || null,
      mac: data.mac_address || null,
      onLocalNetwork: !!data.on_local_network,
    };
  } catch (e) {
    info = { deviceName: 'Unknown device', ip: null, mac: null, onLocalNetwork: false };
  }
  rbDeviceInfoCache.set(remoteId, info);
  return info;
}

/** Renders the small "Remote ID / device / IP / MAC" tile used both for the
 * host's list of connected viewers and the viewer's own connection info. */
function rbConnectionTile(remoteId, info, extraLabel) {
  const macText = info.mac
    ? `MAC: ${rbEsc(info.mac.toUpperCase())}`
    : `<span class="mac-missing">MAC: unavailable${info.ip ? ' (not on this server\u2019s local network)' : ''}</span>`;
  return `
    <div class="connected-tile">
      <div class="cinfo">
        <span class="cid"><span class="dot"></span>${rbEsc(remoteId)} <button type="button" class="remote-copy-btn" onclick="rbCopyRemoteId('${rbEsc(remoteId)}', this)">Copy</button></span>
        <span class="cdevice">Device: ${rbEsc(info.deviceName)}</span>
        <span class="cmeta">IP: ${rbEsc(info.ip || 'Unknown')} &middot; ${macText}</span>
      </div>
      <span class="muted" style="font-size:12px">${extraLabel || 'Connected'}${info.onLocalNetwork ? ' &middot; Local network' : ''}</span>
    </div>`;
}


async function rbCopyRemoteId(remoteId = myRemoteId, button = null) {
  const raw = String(remoteId || '').replace(/\s+/g, '');
  if (!rbIsValidRemoteId(raw)) return;
  const el = button || document.getElementById('btnCopyRemoteId');
  let copied = false;

  // Clipboard API works in secure contexts. LAN deployments are often plain
  // HTTP, so always keep a synchronous textarea fallback for those cases.
  try {
    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
      await navigator.clipboard.writeText(raw);
      copied = true;
    }
  } catch (_) {}

  if (!copied) {
    const ta = document.createElement('textarea');
    ta.value = raw;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    ta.style.top = '0';
    ta.style.width = '1px';
    ta.style.height = '1px';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    ta.setSelectionRange(0, raw.length);
    try { copied = document.execCommand('copy'); } catch (_) { copied = false; }
    ta.remove();
  }

  if (el) {
    const old = el.textContent;
    el.textContent = copied ? 'Copied' : 'Select & Copy';
    if (copied) el.disabled = true;
    setTimeout(() => { el.textContent = old || 'Copy'; el.disabled = false; }, 1400);
  }
  return copied;
}

function rbUpdatePresenceButton() {
  const btn = document.getElementById('btnShareScreen');
  if (!btn) return;
  const dot = btn.querySelector('.presence-dot');
  const label = btn.querySelector('.presence-label');
  if (dot) { dot.classList.toggle('online', hostOnline); dot.classList.toggle('offline', !hostOnline); }
  if (label) label.textContent = hostOnline ? 'Go offline' : 'Go online';
  btn.setAttribute('aria-pressed', hostOnline ? 'true' : 'false');
}

/* --------------------------- Identity --------------------------- */

/** Only accept the Remote ID format generated by /api/register.  Older
 * builds could accidentally persist the literal string "undefined" in
 * localStorage, which then came back through the API and was rendered as
 * the user's Remote ID. Treat every invalid/stale value as empty so the
 * server can issue a fresh ID. */
function rbIsValidRemoteId(value) {
  return /^\d{9}$/.test(String(value || '').trim());
}

function rbStoredRemoteId() {
  try {
    const stored = String(localStorage.getItem('rb_remote_id') || '').trim();
    if (rbIsValidRemoteId(stored)) return stored;
    if (stored) localStorage.removeItem('rb_remote_id');
  } catch (_) {}
  return '';
}

let myRemoteId = rbStoredRemoteId();

function rbSetRemoteIdDisplay(remoteId) {
  const el = document.getElementById('myRemoteId');
  if (!el) return;
  if (!rbIsValidRemoteId(remoteId)) {
    el.textContent = 'Connecting…';
    el.classList.add('remoteid-loading');
    return;
  }
  el.classList.remove('remoteid-loading');
  el.textContent = String(remoteId).replace(/(\d{3})(?=\d)/g, '$1 ');
}

async function rbEnsureRegistered() {
  rbSetRemoteIdDisplay(myRemoteId);
  const data = await rbApi('/api/register', {
    method: 'POST',
    body: JSON.stringify({ remote_id: rbIsValidRemoteId(myRemoteId) ? myRemoteId : '', device_name: navigator.platform || 'Browser' }),
  });

  const assignedId = String(data && data.remote_id || '').trim();
  if (!rbIsValidRemoteId(assignedId)) {
    throw new Error('Registration returned an invalid Remote ID.');
  }

  myRemoteId = assignedId;
  // The server is the source of truth for manual presence. This prevents a
  // page refresh, browser restore, or localStorage loss from forcing Offline.
  hostOnline = data && data.online === true;
  try { localStorage.setItem('rb_remote_id', myRemoteId); } catch (_) {}
  try { localStorage.setItem('rb_host_online', hostOnline ? '1' : '0'); } catch (_) {}
  rbSetRemoteIdDisplay(myRemoteId);
  rbUpdatePresenceButton();
  if (hostOnline) {
    rbStartPresenceHeartbeat();
    clearInterval(hostPendingPollTimer);
    hostPendingPollTimer = setInterval(rbPollIncoming, POLL_MS);
    rbPollIncoming();
  }
  return myRemoteId;
}

let rbPresenceTimer = null;
function rbStartPresenceHeartbeat() {
  clearInterval(rbPresenceTimer);
  const beat = () => {
    if (!hostOnline || !rbIsValidRemoteId(myRemoteId)) return;
    rbApi('/api/heartbeat', { method: 'POST', body: JSON.stringify({ remote_id: myRemoteId }) }).catch(() => {});
  };
  beat();
  rbPresenceTimer = setInterval(beat, 20000);
}

function rbStopPresenceHeartbeat() {
  clearInterval(rbPresenceTimer);
  rbPresenceTimer = null;
}

async function rbSetServerOffline() {
  if (!rbIsValidRemoteId(myRemoteId)) return;
  try { await rbApi('/api/offline', { method: 'POST', body: JSON.stringify({ remote_id: myRemoteId }) }); } catch (_) {}
}

/** Initialize after the document exists. This also prevents the identity
 * request from racing the page UI and gives us one safe place to recover
 * from stale localStorage values left by older builds. */
async function rbInitIdentity() {
  rbSetRemoteIdDisplay(myRemoteId);
  let lastError = null;
  for (let attempt = 1; attempt <= 5; attempt++) {
    try {
      await rbEnsureRegistered();
      return;
    } catch (e) {
      lastError = e;
      rbLog(`Remote ID registration attempt ${attempt}/5 failed: ${e.message}`);
      if (attempt < 5) await new Promise(resolve => setTimeout(resolve, Math.min(1000 * attempt, 4000)));
    }
  }
  const el = document.getElementById('myRemoteId');
  if (el) {
    el.textContent = rbIsValidRemoteId(myRemoteId) ? myRemoteId.replace(/(\d{3})(?=\d)/g, '$1 ') : 'Unavailable';
    el.classList.remove('remoteid-loading');
  }
  const copy = document.getElementById('btnCopyRemoteId');
  if (copy) copy.disabled = !rbIsValidRemoteId(myRemoteId);
  rbLog('Remote ID registration could not be completed.' + (lastError ? ' ' + lastError.message : ''));
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', rbInitIdentity, { once: true });
} else {
  rbInitIdentity();
}

/* --------------------- Native control agent (host) --------------------- */
/* Optional local companion process that actually injects mouse/keyboard
 * input into the OS. The browser tab talks to it over a loopback-only
 * WebSocket and must present the token shown in the agent's terminal. */

let agentSocket = null;
let agentConnected = false;
let agentConnecting = false;

function rbAgentStatus(text) {
  const el = document.getElementById('agentStatus');
  if (el) el.textContent = text;
}

function rbSetAgentTokenState(token) {
  const badge = document.getElementById('agentTokenState');
  if (!badge) return;
  const valid = /^[A-Za-z0-9_-]{32,128}$/.test(token || '');
  badge.className = 'badge ' + (valid ? 'local' : 'warn');
  badge.textContent = valid ? `Token: ${token.length} chars` : 'Token: waiting';
}

function rbToggleAgentTokenVisibility(button) {
  const el = document.getElementById('agentToken');
  const btn = button || document.getElementById('btnToggleAgentToken');
  if (!el) return;

  // Keep the same DOM input, value, dimensions, and theme in both states.
  // Only the browser's password/text rendering mode changes. Read/Copy and
  // automatic token detection never call this function, so they cannot
  // unexpectedly reveal or resize the field.
  const wasHidden = el.type === 'password';
  const selectionStart = el.selectionStart;
  const selectionEnd = el.selectionEnd;
  el.type = wasHidden ? 'text' : 'password';
  if (selectionStart !== null && selectionEnd !== null) {
    try { el.setSelectionRange(selectionStart, selectionEnd); } catch (_) {}
  }
  if (btn) btn.textContent = wasHidden ? 'Hide token' : 'Show token';
}

async function rbLoadAgentConfig() {
  const badge = document.getElementById('agentEndpointState');
  const diag = document.getElementById('agentDiagnostics');
  try {
    const data = await rbApi('/api/agent/config', { method: 'GET' });
    AGENT_CONFIG = data;
    const pageHost = location.hostname || '127.0.0.1';
    const host = data.lan_enabled ? pageHost : '127.0.0.1';
    AGENT_WS_URL = `ws://${host}:${Number(data.port || 8791)}`;
    const label = data.lan_enabled
      ? `Agent: LAN ${host}:${data.port}`
      : `Agent: local ${host}:${data.port}`;
    if (badge) {
      badge.className = 'badge ' + (data.lan_enabled ? 'local' : 'warn');
      badge.textContent = label;
    }
    if (diag) {
      diag.innerHTML = `<span>PHP signaling: <strong>available</strong></span><span>Agent endpoint: <strong>${rbEsc(AGENT_WS_URL)}</strong></span><span>${data.lan_enabled ? 'LAN control enabled' : 'Local-only control'}</span>`;
    }
    return data;
  } catch (e) {
    AGENT_WS_URL = `ws://127.0.0.1:8791`;
    if (badge) { badge.className = 'badge warn'; badge.textContent = 'Agent: config unavailable'; }
    if (diag) diag.innerHTML = `<span>PHP signaling: <strong>unavailable</strong></span><span>Agent endpoint: <strong>ws://127.0.0.1:8791</strong></span>`;
    return null;
  }
}

function rbConnectAgent() {
  const token = (document.getElementById('agentToken')?.value || '').trim();
  rbSetAgentTokenState(token);
  if (!/^[A-Za-z0-9_-]{32,128}$/.test(token)) {
    rbAgentStatus('Token is missing or incomplete. Click Read token and use the complete value.');
    return;
  }
  if (!AGENT_WS_URL) {
    rbAgentStatus('Detecting the native agent endpoint…');
    rbLoadAgentConfig().then(() => rbConnectAgent());
    return;
  }

  // Do not create multiple WebSocket connections when Connect is clicked
  // repeatedly or while an earlier connection is still handshaking.
  if (agentSocket && agentSocket.readyState === WebSocket.OPEN) {
    agentConnected = true;
    rbAgentStatus('Native control agent: connected');
    return;
  }
  if (agentSocket && agentSocket.readyState === WebSocket.CONNECTING) {
    agentConnecting = true;
    rbAgentStatus('Native control agent: connecting…');
    return;
  }

  localStorage.setItem('rb_agent_token', token);
  agentConnecting = true;
  agentConnected = false;
  rbAgentStatus('Native control agent: connecting…');

  const socket = new WebSocket(AGENT_WS_URL);
  agentSocket = socket;
  socket.onopen = () => {
    if (agentSocket !== socket) return;
    socket.send(JSON.stringify({ type: 'auth', token }));
  };
  socket.onmessage = (ev) => {
    try {
      const msg = JSON.parse(ev.data);
      if (msg.type === 'auth_ok') {
        if (agentSocket !== socket) return;
        agentConnecting = false;
        agentConnected = true;
        rbAgentStatus('Native control agent: connected');
      } else if (msg.type === 'auth_fail') {
        if (agentSocket !== socket) return;
        agentConnecting = false;
        agentConnected = false;
        rbAgentStatus('Native control agent: wrong token');
        socket.close();
      }
    } catch (_) {}
  };
  socket.onclose = () => {
    if (agentSocket !== socket) return;
    agentConnecting = false;
    agentConnected = false;
    rbAgentStatus('Native control agent: not connected');
  };
  socket.onerror = () => {
    if (agentSocket !== socket) return;
    agentConnecting = false;
    agentConnected = false;
    rbAgentStatus('Native control agent: connection error');
  };
}

/* --- Run the agent process itself from the page (no terminal needed) --- */
/* This only works when the page is being served on 127.0.0.1 — the server
 * refuses the request otherwise, since it spawns a real local process. */

let agentServerPollTimer = null;
let agentServerOffset = 0;

function rbExtractAgentToken() {
  const consoleEl = document.getElementById('agentConsole');
  const tokenEl = document.getElementById('agentToken');
  if (!consoleEl || !tokenEl) return '';

  // The native agent prints a stable, explicit token line. Keep the match
  // intentionally strict so we never accidentally copy a PID or log value.
  const match = consoleEl.textContent.match(/Token\s*\(paste this into the RemoteBridge Host tab\):\s*([A-Za-z0-9_-]{32,128})/i);
  if (!match) return '';

  const token = match[1].trim();
  // Never change the input visibility here. Read token only updates the value;
  // the user controls visibility with Show token / Hide token.
  tokenEl.value = token;
  localStorage.setItem('rb_agent_token', token);
  rbSetAgentTokenState(token);
  rbAgentStatus('Complete token read from command-line output. Click Connect to authenticate.');
  return token;
}

async function rbCopyAgentToken() {
  const tokenEl = document.getElementById('agentToken');
  if (!tokenEl) return;
  let token = tokenEl.value.trim();
  if (!token) token = rbExtractAgentToken();
  rbSetAgentTokenState(token);
  if (!token) {
    rbAgentStatus('No token found. Start the agent or click Read token after the token appears.');
    return;
  }

  try {
    await navigator.clipboard.writeText(token);
  } catch (_) {
    // Fallback copy without changing the token's hidden/visible state.
    const wasHidden = tokenEl.type === 'password';
    const originalStart = tokenEl.selectionStart;
    const originalEnd = tokenEl.selectionEnd;
    tokenEl.focus();
    tokenEl.select();
    document.execCommand('copy');
    if (originalStart !== null && originalEnd !== null) {
      try { tokenEl.setSelectionRange(originalStart, originalEnd); } catch (_) {}
    }
    if (wasHidden && tokenEl.type !== 'password') tokenEl.type = 'password';
  }
  rbAgentStatus('Token copied to clipboard.');
}

function rbAgentConsoleAppend(text) {
  if (!text) return;
  const el = document.getElementById('agentConsole');
  el.classList.remove('hidden');
  el.textContent += text;
  el.scrollTop = el.scrollHeight;
  // Populate the connection field as soon as the command-line token appears.
  rbExtractAgentToken();
}

async function rbStartAgentServer() {
  const btnRun = document.getElementById('btnRunServer');
  const btnStop = document.getElementById('btnStopServer');
  const stateEl = document.getElementById('agentServerState');
  const consoleEl = document.getElementById('agentConsole');
  consoleEl.textContent = '';
  agentServerOffset = 0;
  btnRun.disabled = true;
  stateEl.textContent = 'Starting…';
  try {
    const data = await rbApi('/api/agent/start', { method: 'POST' });
    if (data.already_running) {
      rbAgentConsoleAppend('(agent process was already running, pid ' + data.pid + ')\n');
    }
    stateEl.textContent = 'Running (pid ' + data.pid + ')';
    btnRun.classList.add('hidden');
    btnStop.classList.remove('hidden');
    rbLog('Started local control agent process.');
    clearInterval(agentServerPollTimer);
    agentServerPollTimer = setInterval(rbPollAgentServerOutput, 1000);
    rbPollAgentServerOutput();
  } catch (e) {
    stateEl.textContent = 'Failed to start: ' + e.message;
    btnRun.disabled = false;
  }
}

async function rbPollAgentServerOutput() {
  try {
    const data = await rbApi(`/api/agent/output?offset=${agentServerOffset}`, { method: 'GET' });
    agentServerOffset = data.offset;
    rbAgentConsoleAppend(data.chunk);
    const stateEl = document.getElementById('agentServerState');
    if (!data.running) {
      clearInterval(agentServerPollTimer);
      stateEl.textContent = 'Stopped';
      document.getElementById('btnRunServer').classList.remove('hidden');
      document.getElementById('btnRunServer').disabled = false;
      document.getElementById('btnStopServer').classList.add('hidden');
    }
  } catch (_) { /* transient network errors: ignore */ }
}

async function rbStopAgentServer() {
  try {
    await rbApi('/api/agent/stop', { method: 'POST' });
  } catch (_) {}
  clearInterval(agentServerPollTimer);
  rbAgentConsoleAppend('\n=== stopped ===\n');
  document.getElementById('agentServerState').textContent = 'Stopped';
  document.getElementById('btnRunServer').classList.remove('hidden');
  document.getElementById('btnRunServer').disabled = false;
  document.getElementById('btnStopServer').classList.add('hidden');
  rbLog('Stopped local control agent process.');
}

const savedAgentToken = localStorage.getItem('rb_agent_token');
document.addEventListener('DOMContentLoaded', () => {
  if (savedAgentToken && document.getElementById('agentToken')) {
    document.getElementById('agentToken').value = savedAgentToken;
    rbSetAgentTokenState(savedAgentToken);
  }
  rbLoadAgentConfig();
  // Also recover a token if the agent console was populated by a restored
  // page state before this script finishes initializing.
  rbExtractAgentToken();
});

function rbForwardControlToAgent(cmd) {
  const allow = document.getElementById('allowControl')?.checked;
  if (!allow) return; // host has not granted control permission
  if (!agentConnected || !agentSocket || agentSocket.readyState !== WebSocket.OPEN) return;
  agentSocket.send(JSON.stringify(cmd));
}

/* ----------------------------- Host ------------------------------ */

let hostStream = null;
const hostSessions = new Map(); // session_id -> { pc, pollTimer, sinceId, controlChannel }

async function rbToggleSharePresence() {
  hostOnline = !hostOnline;
  try { localStorage.setItem('rb_host_online', hostOnline ? '1' : '0'); } catch (_) {}
  const btn = document.getElementById('btnShareScreen');
  if (hostOnline) {
    btn.classList.remove('secondary');
    rbStartPresenceHeartbeat();
    rbLog('You are online. Waiting for connection requests…');
    clearInterval(hostPendingPollTimer);
    hostPendingPollTimer = setInterval(rbPollIncoming, POLL_MS);
    rbPollIncoming();
  } else {
    btn.classList.add('secondary');
    rbStopPresenceHeartbeat();
    clearInterval(hostPendingPollTimer);
    hostPendingPollTimer = null;
    document.getElementById('incomingRequests').innerHTML = '';
    await rbSetServerOffline();
    rbLog('You are offline.');
  }
  rbUpdatePresenceButton();
}


async function rbPollIncoming() {
  try {
    const data = await rbApi(`/api/session/pending?remote_id=${encodeURIComponent(myRemoteId)}`, { method: 'GET' });
    const container = document.getElementById('incomingRequests');
    const pending = data.sessions.filter(s => s.status === 'pending' && !hostSessions.has(s.session_id));
    container.innerHTML = '';
    for (const s of pending) {
      const info = await rbGetDeviceInfo(s.initiator_remote_id);
      const div = document.createElement('div');
      div.className = 'request';
      div.innerHTML = `<span class="req-meta"><span>Connection request from <strong>${rbEsc(s.initiator_remote_id)}</strong></span><span class="muted" style="font-size:12px">Device: ${rbEsc(info.deviceName)}</span></span>`;
      const btnWrap = document.createElement('span');
      btnWrap.className = 'row';
      const acceptBtn = document.createElement('button');
      acceptBtn.textContent = 'Accept';
      acceptBtn.onclick = () => rbAcceptSession(s.session_id, s.initiator_remote_id, info);
      const declineBtn = document.createElement('button');
      declineBtn.textContent = 'Decline';
      declineBtn.className = 'secondary';
      declineBtn.onclick = async () => {
        await rbApi('/api/session/respond', { method: 'POST', body: JSON.stringify({ session_id: s.session_id, action: 'reject' }) });
        rbLog(`Declined request from ${s.initiator_remote_id}`);
        rbPollIncoming();
      };
      btnWrap.appendChild(acceptBtn);
      btnWrap.appendChild(declineBtn);
      div.appendChild(btnWrap);
      container.appendChild(div);
    }
  } catch (e) { /* transient network errors: ignore */ }
}

/** Renders the list of currently-connected viewers — Remote ID, device, IP,
 * and (when they're genuinely on this server's own local network) MAC
 * address — under the host's "Your Remote ID" panel. Supports more than one
 * active viewer session at a time since hostSessions is keyed by session_id. */
function rbRenderConnectedViewers() {
  const container = document.getElementById('connectedViewers');
  if (!container) return;
  const entries = Array.from(hostSessions.values());
  if (!entries.length) { container.innerHTML = ''; return; }
  container.innerHTML = entries.map(s => rbConnectionTile(s.remoteId, s.deviceInfo || {})).join('');
}

async function rbAcceptSession(sessionId, initiatorId, deviceInfo) {
  try {
    hostStream = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: false });
  } catch (e) {
    rbLog('Screen share permission denied.');
    return;
  }
  await rbApi('/api/session/respond', { method: 'POST', body: JSON.stringify({ session_id: sessionId, action: 'accept' }) });
  document.getElementById('incomingRequests').innerHTML = '';
  document.getElementById('hostPreviewWrap').classList.remove('hidden');
  document.getElementById('hostPreview').srcObject = hostStream;
  rbLog(`Accepted ${initiatorId} (${(deviceInfo && deviceInfo.deviceName) || 'Unknown device'}). Setting up connection…`);

  const pc = new RTCPeerConnection({ iceServers: ICE_SERVERS });
  hostStream.getTracks().forEach(track => pc.addTrack(track, hostStream));
  hostStream.getVideoTracks()[0].onended = () => rbStopHosting();

  const state = { pc, sinceId: 0, controlChannel: null, remoteId: initiatorId, deviceInfo };
  hostSessions.set(sessionId, state);
  rbRenderConnectedViewers();

  // The viewer creates the "control" data channel (it's the offerer); we
  // just receive it here and wire up incoming input events.
  pc.ondatachannel = (ev) => {
    if (ev.channel.label !== 'control') return;
    state.controlChannel = ev.channel;
    ev.channel.onmessage = (msgEv) => {
      let cmd;
      try { cmd = JSON.parse(msgEv.data); } catch (_) { return; }
      rbForwardControlToAgent(cmd);
    };
  };

  pc.onicecandidate = (ev) => {
    if (ev.candidate) {
      rbApi('/api/signal', { method: 'POST', body: JSON.stringify({
        session_id: sessionId, sender_remote_id: myRemoteId, type: 'candidate', payload: JSON.stringify(ev.candidate),
      }) }).catch(() => {});
    }
  };
  pc.onconnectionstatechange = () => rbLog(`Connection state: ${pc.connectionState}`);

  state.pollTimer = setInterval(async () => {
    try {
      const data = await rbApi(`/api/signal/poll?session_id=${sessionId}&remote_id=${myRemoteId}&since_id=${state.sinceId}`, { method: 'GET' });
      for (const sig of data.signals) {
        state.sinceId = Math.max(state.sinceId, sig.id);
        if (sig.message_type === 'offer') {
          await pc.setRemoteDescription({ type: 'offer', sdp: sig.payload });
          const answer = await pc.createAnswer();
          await pc.setLocalDescription(answer);
          await rbApi('/api/signal', { method: 'POST', body: JSON.stringify({
            session_id: sessionId, sender_remote_id: myRemoteId, type: 'answer', payload: answer.sdp,
          }) });
        } else if (sig.message_type === 'candidate') {
          try { await pc.addIceCandidate(JSON.parse(sig.payload)); } catch (_) {}
        } else if (sig.message_type === 'close') {
          rbStopHosting();
        }
      }
    } catch (_) {}
  }, POLL_MS);
}

function rbStopHosting() {
  if (hostStream) hostStream.getTracks().forEach(t => t.stop());
  hostStream = null;
  document.getElementById('hostPreviewWrap').classList.add('hidden');
  hostSessions.forEach((state, sessionId) => {
    clearInterval(state.pollTimer);
    state.pc.close();
    rbApi('/api/session/close', { method: 'POST', body: JSON.stringify({ session_id: sessionId }) }).catch(() => {});
  });
  hostSessions.clear();
  rbRenderConnectedViewers();
  rbLog('Stopped sharing.');
}

/* ---------------------------- Viewer ------------------------------ */

let viewerPc = null;
let viewerSessionId = null;
let viewerTargetId = null;
let viewerPollTimer = null;
let viewerSinceId = 0;
let viewerStatusTimer = null;
let viewerControlChannel = null;
let viewerLastMove = 0;

async function rbConnectToRemote() {
  const target = document.getElementById('targetRemoteId').value.replace(/\s+/g, '');
  if (!target) return;
  viewerTargetId = target;
  const stateEl = document.getElementById('viewerState');
  document.getElementById('btnConnect').disabled = true;
  try {
    const data = await rbApi('/api/session/create', { method: 'POST', body: JSON.stringify({
      target_remote_id: target, initiator_remote_id: myRemoteId,
    }) });
    viewerSessionId = data.session_id;
    stateEl.textContent = 'Request sent. Waiting for the other side to accept…';
    rbLog(`Requested connection to ${target}`);

    viewerStatusTimer = setInterval(async () => {
      try {
        const s = await rbApi(`/api/session/status?session_id=${viewerSessionId}`, { method: 'GET' });
        if (s.status === 'connected') {
          clearInterval(viewerStatusTimer);
          stateEl.textContent = 'Accepted — connecting…';
          rbStartViewerPeer(viewerSessionId);
        } else if (s.status === 'closed' || s.status === 'expired') {
          clearInterval(viewerStatusTimer);
          stateEl.textContent = 'Request declined or expired.';
          document.getElementById('btnConnect').disabled = false;
        }
      } catch (_) {}
    }, POLL_MS);
  } catch (e) {
    stateEl.textContent = e.message;
    document.getElementById('btnConnect').disabled = false;
  }
}

async function rbStartViewerPeer(sessionId) {
  const pc = new RTCPeerConnection({ iceServers: ICE_SERVERS });
  viewerPc = pc;
  pc.addTransceiver('video', { direction: 'recvonly' });

  // We are the offerer, so we create the control channel; the host receives
  // it via pc.ondatachannel on its side.
  viewerControlChannel = pc.createDataChannel('control', { ordered: true, maxRetransmits: 0 });
  viewerControlChannel.onopen = () => rbLog('Control channel open.');
  viewerControlChannel.onclose = () => rbLog('Control channel closed.');

  pc.ontrack = (ev) => {
    document.getElementById('viewerVideoWrap').classList.remove('hidden');
    const video = document.getElementById('viewerVideo');
    video.srcObject = ev.streams[0];
    document.getElementById('viewerState').textContent = 'Connected.';
    rbAttachControlCapture(video);

    // Show which Remote ID / device / IP / MAC we ended up connected to.
    const infoEl = document.getElementById('viewerConnInfo');
    if (infoEl && viewerTargetId) {
      rbGetDeviceInfo(viewerTargetId).then(info => {
        infoEl.classList.remove('hidden');
        infoEl.innerHTML = rbConnectionTile(viewerTargetId, info);
      });
    }
  };
  pc.onicecandidate = (ev) => {
    if (ev.candidate) {
      rbApi('/api/signal', { method: 'POST', body: JSON.stringify({
        session_id: sessionId, sender_remote_id: myRemoteId, type: 'candidate', payload: JSON.stringify(ev.candidate),
      }) }).catch(() => {});
    }
  };
  pc.onconnectionstatechange = () => rbLog(`Connection state: ${pc.connectionState}`);

  const offer = await pc.createOffer();
  await pc.setLocalDescription(offer);
  await rbApi('/api/signal', { method: 'POST', body: JSON.stringify({
    session_id: sessionId, sender_remote_id: myRemoteId, type: 'offer', payload: offer.sdp,
  }) });

  viewerSinceId = 0;
  viewerPollTimer = setInterval(async () => {
    try {
      const data = await rbApi(`/api/signal/poll?session_id=${sessionId}&remote_id=${myRemoteId}&since_id=${viewerSinceId}`, { method: 'GET' });
      for (const sig of data.signals) {
        viewerSinceId = Math.max(viewerSinceId, sig.id);
        if (sig.message_type === 'answer') {
          await pc.setRemoteDescription({ type: 'answer', sdp: sig.payload });
        } else if (sig.message_type === 'candidate') {
          try { await pc.addIceCandidate(JSON.parse(sig.payload)); } catch (_) {}
        } else if (sig.message_type === 'close') {
          rbDisconnect();
        }
      }
    } catch (_) {}
  }, POLL_MS);
}

/** Capture mouse/keyboard on the viewer's <video> element and relay them
 * as normalized (0..1) control commands over the WebRTC data channel. */
function rbAttachControlCapture(video) {
  video.tabIndex = 0; // make it focusable so keydown/up fire on it
  video.style.cursor = 'crosshair';

  function normPos(ev) {
    const rect = video.getBoundingClientRect();
    return {
      x: Math.min(1, Math.max(0, (ev.clientX - rect.left) / rect.width)),
      y: Math.min(1, Math.max(0, (ev.clientY - rect.top) / rect.height)),
    };
  }
  function send(cmd) {
    if (viewerControlChannel && viewerControlChannel.readyState === 'open') {
      viewerControlChannel.send(JSON.stringify(cmd));
    }
  }

  video.addEventListener('mousemove', (ev) => {
    const now = performance.now();
    if (now - viewerLastMove < 33) return; // ~30fps cap
    viewerLastMove = now;
    send({ type: 'move', ...normPos(ev) });
  });
  video.addEventListener('mousedown', (ev) => {
    video.focus();
    send({ type: 'button', action: 'down', button: ev.button, ...normPos(ev) });
    ev.preventDefault();
  });
  video.addEventListener('mouseup', (ev) => {
    send({ type: 'button', action: 'up', button: ev.button, ...normPos(ev) });
    ev.preventDefault();
  });
  video.addEventListener('contextmenu', (ev) => ev.preventDefault());
  video.addEventListener('wheel', (ev) => {
    send({ type: 'wheel', deltaX: ev.deltaX, deltaY: ev.deltaY });
    ev.preventDefault();
  }, { passive: false });
  video.addEventListener('keydown', (ev) => {
    send({ type: 'key', action: 'down', key: ev.key, code: ev.code });
    ev.preventDefault();
  });
  video.addEventListener('keyup', (ev) => {
    send({ type: 'key', action: 'up', key: ev.key, code: ev.code });
    ev.preventDefault();
  });
}

function rbDisconnect() {
  clearInterval(viewerPollTimer);
  clearInterval(viewerStatusTimer);
  if (viewerPc) viewerPc.close();
  viewerPc = null;
  viewerControlChannel = null;
  document.getElementById('viewerVideoWrap').classList.add('hidden');
  document.getElementById('viewerConnInfo').classList.add('hidden');
  document.getElementById('viewerConnInfo').innerHTML = '';
  document.getElementById('viewerState').textContent = 'Disconnected.';
  document.getElementById('btnConnect').disabled = false;
  viewerTargetId = null;
  if (viewerSessionId) {
    rbApi('/api/session/close', { method: 'POST', body: JSON.stringify({ session_id: viewerSessionId }) }).catch(() => {});
  }
  viewerSessionId = null;
}
