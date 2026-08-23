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

/* -------------------- Navbar dropdowns (Network / Menu) -------------------- */

function rbCloseNavDropdowns() {
  document.querySelectorAll('.nav-dropdown-panel').forEach(p => p.classList.add('hidden'));
  document.querySelectorAll('.nav-dropdown').forEach(d => d.classList.remove('open'));
}

function rbToggleNavDropdown(wrapperId, event) {
  if (event) event.stopPropagation();
  const wrapper = document.getElementById(wrapperId);
  if (!wrapper) return;
  const panel = wrapper.querySelector('.nav-dropdown-panel');
  const willOpen = panel && panel.classList.contains('hidden');
  rbCloseNavDropdowns();
  if (willOpen) {
    panel.classList.remove('hidden');
    wrapper.classList.add('open');
    if (wrapperId === 'navNetworkDropdown') { rbRenderDeviceGrid(); rbRenderLocalUsersGrid(); }
  }
}

document.addEventListener('click', (e) => {
  if (!e.target.closest('.nav-dropdown')) rbCloseNavDropdowns();
});
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') rbCloseNavDropdowns();
});

/* -------------------- Network devices modal -------------------- */

function rbOpenNetworkModal(tab) {
  rbCloseNavDropdowns();
  const modal = document.getElementById('networkModal');
  if (!modal) return;
  modal.classList.remove('hidden');
  rbShowNetworkTab(tab || 'devices');
  rbRenderDeviceGrid();
  rbRenderLocalUsersGrid();
}

function rbCloseNetworkModal() {
  const modal = document.getElementById('networkModal');
  if (modal) modal.classList.add('hidden');
}

function rbShowNetworkTab(which) {
  const tabDevices = document.getElementById('networkTabDevices');
  const tabUsers = document.getElementById('networkTabUsers');
  const panelDevices = document.getElementById('networkPanelDevices');
  const panelUsers = document.getElementById('networkPanelUsers');
  if (!tabDevices || !tabUsers || !panelDevices || !panelUsers) return;
  tabDevices.classList.toggle('active', which === 'devices');
  tabUsers.classList.toggle('active', which === 'users');
  panelDevices.classList.toggle('hidden', which !== 'devices');
  panelUsers.classList.toggle('hidden', which !== 'users');
}

document.addEventListener('DOMContentLoaded', () => {
  const savedMode = localStorage.getItem('rb_setup_mode');
  const detectedMode = rbIsPrivateHost(location.hostname) ? 'local' : 'internet';
  rbSelectSetupMode(savedMode || detectedMode);
  if (!localStorage.getItem('rb_setup_dismissed')) rbOpenSetupModal();
});

/* -------------------- Local network devices sidebar -------------------- */
/* Network discovery is available directly from the local application. The
 * server performs the subnet sweep and returns the refreshed ARP/MAC table. */

function rbEsc(s) {
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

/* Tiles keep their own collapsed/expanded state across re-renders (the grid
 * re-renders every ~10s from polling), tracked here by a stable per-item key. */
let rbOpenDeviceTiles = new Set();
let rbOpenUserTiles = new Set();

function rbDeviceTile(d) {
  const name = rbEsc(d.hostname || ('Device ' + d.ip.split('.').pop()));
  const mac = rbEsc((d.mac || '').toUpperCase());
  const ip = rbEsc(d.ip);
  const type = d.type ? `<div class="drow"><span>Type</span><span>${rbEsc(d.type)}</span></div>` : '';
  const key = d.ip || name;
  const open = rbOpenDeviceTiles.has(key) ? ' open' : '';
  return `<div class="device-tile">
    <div class="dname"><span class="dot"></span>${name}</div>
    <details class="tile-details" data-tile-key="${rbEsc(key)}" ontoggle="rbTrackTileOpen(this, rbOpenDeviceTiles)"${open}>
      <summary>Details</summary>
      <div class="drow"><span>IP</span><span>${ip}</span></div>
      <div class="drow"><span>MAC</span><span>${mac || 'unknown'}</span></div>
      ${type}
    </details>
  </div>`;
}

function rbTrackTileOpen(detailsEl, store) {
  const key = detailsEl.dataset.tileKey;
  if (!key) return;
  if (detailsEl.open) store.add(key); else store.delete(key);
}

/* Each list (devices / local users) can be shown in up to three places at
 * once: the sidebar card, the navbar "Network" dropdown, and the full
 * "networkModal". We keep one cache per list and re-render every visible
 * target — applying that target's own search filter — whenever the cache
 * changes or a search box is typed into. */

let rbDevicesCache = [];
let rbLocalUsersCache = [];

function rbFilterQuery(inputId) {
  const el = document.getElementById(inputId);
  return el ? el.value.trim().toLowerCase() : '';
}

function rbMatchDevice(d, q) {
  if (!q) return true;
  return [d.ip, d.mac, d.hostname, d.type].some(v => v && String(v).toLowerCase().includes(q));
}

function rbMatchUser(u, q) {
  if (!q) return true;
  return [u.device_name, u.hostname, u.ip, u.mac, u.remote_id].some(v => v && String(v).toLowerCase().includes(q));
}

function rbRenderDeviceGrid() {
  const targets = [
    { gridId: 'deviceGrid', countId: 'deviceCount', searchId: 'deviceSearchInput' },
    { gridId: 'deviceGridModal', countId: 'deviceCountModal', searchId: 'deviceSearchModal' },
  ];
  const total = rbDevicesCache.length;
  targets.forEach(t => {
    const grid = document.getElementById(t.gridId);
    if (!grid) return;
    const countEl = document.getElementById(t.countId);
    const q = rbFilterQuery(t.searchId);
    const filtered = rbDevicesCache.filter(d => rbMatchDevice(d, q));
    if (countEl) {
      countEl.textContent = !total
        ? 'No devices currently visible'
        : (q ? `${filtered.length} of ${total} device${total === 1 ? '' : 's'} match “${rbEsc(q)}”`
              : `${total} device${total === 1 ? '' : 's'} visible`);
    }
    grid.innerHTML = filtered.length
      ? filtered.map(rbDeviceTile).join('')
      : (total
          ? '<div class="device-empty">No devices match your filter.</div>'
          : '<div class="device-empty">No active devices are currently visible. Click “Scan all devices” to refresh the LAN.</div>');
  });
  const badge = document.getElementById('navDeviceBadge');
  if (badge) {
    badge.textContent = total ? String(total) : '';
    badge.classList.toggle('hidden', !total);
  }
  const navCount = document.getElementById('navDeviceCount');
  if (navCount) navCount.textContent = total ? `${total} device${total === 1 ? '' : 's'} visible` : 'No devices currently visible';
}

function rbShowDeviceError(message) {
  ['deviceGrid', 'deviceGridModal'].forEach(id => {
    const grid = document.getElementById(id);
    if (grid) grid.innerHTML = `<div class="device-empty">${rbEsc(message)}</div>`;
  });
  ['deviceCount', 'deviceCountModal'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.textContent = '';
  });
  const navCount = document.getElementById('navDeviceCount');
  if (navCount) navCount.textContent = 'Unavailable';
}

async function rbLoadDevices() {
  if (!document.getElementById('deviceGrid') && !document.getElementById('deviceGridModal')) return;
  try {
    const data = await rbApi('/api/network/devices', { method: 'GET' });
    rbDevicesCache = data.devices || [];
    rbRenderDeviceGrid();
  } catch (e) {
    rbShowDeviceError(e.message);
  }
}

async function rbScanDevices() {
  const btns = [document.getElementById('btnScanDevices'), document.getElementById('btnScanDevicesModal')].filter(Boolean);
  if (!btns.length || btns[0].dataset.scanning === '1') return;

  btns.forEach(btn => { btn.dataset.scanning = '1'; btn.disabled = true; btn.textContent = 'Scanning all devices…'; });
  ['deviceGrid', 'deviceGridModal'].forEach(id => {
    const grid = document.getElementById(id);
    if (grid) grid.innerHTML = '<div class="device-empty">Scanning the detected local subnet. RemoteBridge remains usable while discovery runs…</div>';
  });

  try {
    const result = await rbApi('/api/network/scan', { method: 'POST' });
    rbDevicesCache = result.devices || [];
    rbRenderDeviceGrid();

    if (result.timed_out) {
      ['deviceGrid', 'deviceGridModal'].forEach(id => {
        const grid = document.getElementById(id);
        if (!grid) return;
        const note = document.createElement('div');
        note.className = 'device-empty';
        note.textContent = result.message || 'The scan timed out; partial results are shown.';
        grid.appendChild(note);
      });
    }
    await rbLoadLocalUsers();
  } catch (e) {
    rbShowDeviceError(`Network scan failed: ${e.message} — make sure PHP is allowed to execute the local scanner and that this page is running on the same computer as the network you want to scan.`);
  } finally {
    btns.forEach(btn => { btn.disabled = false; btn.dataset.scanning = '0'; btn.textContent = 'Scan all devices'; });
  }
}

let localUsersPollTimer = null;

function rbLocalUserTile(u) {
  const mac = u.mac ? rbEsc(u.mac.toUpperCase()) : 'MAC unavailable';
  const me = u.remote_id && u.remote_id === myRemoteId ? '<span class="badge local" style="font-size:9px;padding:2px 6px">You</span>' : '';
  const remote = u.remote_id ? `<div class="uid">Remote ID: ${rbEsc(u.remote_id)} <button type="button" class="remote-copy-btn" onclick="rbCopyRemoteId('${rbEsc(u.remote_id)}', this)">Copy</button> ${me}</div>` : '<div class="uid">Local network device</div>';
  const host = u.hostname && u.hostname !== u.device_name ? ` · Host: ${rbEsc(u.hostname)}` : '';
  const seen = u.last_seen_at ? ` · Last seen: ${rbEsc(u.last_seen_at)}` : '';
  const key = u.remote_id || u.ip || u.device_name || u.hostname;
  const open = rbOpenUserTiles.has(key) ? ' open' : '';
  return `<div class="local-user-tile">
    <div class="uname"><span class="udot"></span>${rbEsc(u.device_name || u.hostname || 'Unknown device')}</div>
    ${remote}
    <details class="tile-details" data-tile-key="${rbEsc(key)}" ontoggle="rbTrackTileOpen(this, rbOpenUserTiles)"${open}>
      <summary>Details</summary>
      <div class="umeta">IP: ${rbEsc(u.ip || 'Unknown')} · MAC: ${mac}${host}<br>Status: <strong>Online</strong>${seen}</div>
    </details>
  </div>`;
}

function rbRenderLocalUsersGrid() {
  const targets = [
    { gridId: 'localUsersGrid', countId: 'localUserCount', searchId: 'userSearchInput' },
    { gridId: 'localUsersGridModal', countId: 'localUserCountModal', searchId: 'userSearchModal' },
  ];
  const total = rbLocalUsersCache.length;
  targets.forEach(t => {
    const grid = document.getElementById(t.gridId);
    if (!grid) return;
    const countEl = document.getElementById(t.countId);
    const q = rbFilterQuery(t.searchId);
    const filtered = rbLocalUsersCache.filter(u => rbMatchUser(u, q));
    if (countEl) {
      countEl.textContent = !total
        ? 'No local network devices detected'
        : (q ? `${filtered.length} of ${total} match “${rbEsc(q)}”`
              : `${total} local network device${total === 1 ? '' : 's'} detected`);
    }
    grid.innerHTML = filtered.length
      ? filtered.map(rbLocalUserTile).join('')
      : (total
          ? '<div class="device-empty">No devices match your filter.</div>'
          : '<div class="device-empty">No devices are currently visible on this local network. Click Scan all devices to refresh the network.</div>');
  });
  const navUserCount = document.getElementById('navUserCount');
  if (navUserCount) navUserCount.textContent = total ? `${total} local user${total === 1 ? '' : 's'} online` : 'No local users online';
}

async function rbLoadLocalUsers() {
  if (!document.getElementById('localUsersGrid') && !document.getElementById('localUsersGridModal')) return;
  try {
    const data = await rbApi('/api/network/users', { method: 'GET' });
    rbLocalUsersCache = data.users || [];
    rbRenderLocalUsersGrid();
  } catch (e) {
    ['localUsersGrid', 'localUsersGridModal'].forEach(id => {
      const grid = document.getElementById(id);
      if (grid) grid.innerHTML = `<div class="device-empty">${rbEsc(e.message)}</div>`;
    });
    ['localUserCount', 'localUserCountModal'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.textContent = '';
    });
  }
}

function rbStartLocalUsersPolling() {
  clearInterval(localUsersPollTimer);
  rbLoadDevices();
  rbLoadLocalUsers();
  localUsersPollTimer = setInterval(() => {
    rbLoadDevices();
    rbLoadLocalUsers();
  }, 10000);
}

rbStartLocalUsersPolling();

/* -------------------- Recent devices (this account, left sidebar) -------------------- */
/* Every browser/computer that has registered a Remote ID under the logged-in
 * account, most recently active first. Backed by /api/devices/recent (own
 * devices only) and /api/devices/rename (own devices; admins may rename any
 * device). This is separate from the LAN discovery grid above — it reflects
 * *this account's* connection history, not what's physically visible on the
 * subnet. */

let rbRecentDevicesCache = [];
let rbRecentDevicesEditing = null; // remote_id currently being renamed, or null
let recentDevicesPollTimer = null;

function rbRecentDeviceTile(d) {
  const remoteId = String(d.remote_id);
  const name = rbEsc(d.device_name || 'Unnamed device');
  const isMe = remoteId === myRemoteId;
  const online = !!d.is_online;
  const meBadge = isMe ? '<span class="badge local" style="font-size:9px;padding:2px 6px;margin-left:4px">This device</span>' : '';
  const status = online ? '<span class="rd-status">Online</span>' : (d.last_seen_at ? `Last seen ${rbEsc(d.last_seen_at)}` : 'Never connected');
  const editing = rbRecentDevicesEditing === remoteId;

  const renameRow = editing ? `
    <div class="rd-rename-row">
      <input type="text" id="rdRenameInput-${rbEsc(remoteId)}" maxlength="80" value="${rbEsc(d.device_name || '')}" placeholder="Device name"
             onkeydown="if(event.key==='Enter'){event.preventDefault();rbRenameDeviceSubmit('${rbEsc(remoteId)}');} if(event.key==='Escape'){event.preventDefault();rbRenameDeviceCancel();}">
      <button class="secondary" type="button" onclick="rbRenameDeviceSubmit('${rbEsc(remoteId)}')">Save</button>
      <button class="secondary" type="button" onclick="rbRenameDeviceCancel()">Cancel</button>
    </div>
    <div id="rdRenameError-${rbEsc(remoteId)}" class="rd-rename-error hidden"></div>` : '';

  return `<div class="recent-device-tile">
    <div class="rdname-row">
      <div class="rdname${online ? ' online' : ''}"><span class="dot"></span><span class="rdname-text">${name}</span>${meBadge}</div>
      <button type="button" class="rd-rename-btn" title="Rename this device" aria-label="Rename this device" onclick="rbRenameDeviceStart('${rbEsc(remoteId)}')">✎</button>
    </div>
    <div class="rdmeta">ID: ${rbEsc(remoteId)} &middot; IP: ${rbEsc(d.ip_address || 'Unknown')} &middot; ${status}</div>
    ${renameRow}
  </div>`;
}

function rbRenderRecentDevices() {
  const list = document.getElementById('recentDeviceList');
  if (!list) return;
  const countEl = document.getElementById('recentDeviceCount');
  const total = rbRecentDevicesCache.length;
  if (countEl) countEl.textContent = total ? `${total} device${total === 1 ? '' : 's'}` : '';
  list.innerHTML = total
    ? rbRecentDevicesCache.map(rbRecentDeviceTile).join('')
    : '<div class="device-empty">No devices yet — this fills in once you connect from a browser.</div>';
  if (rbRecentDevicesEditing) {
    const input = document.getElementById(`rdRenameInput-${rbRecentDevicesEditing}`);
    if (input) { input.focus(); input.select(); }
  }
}

async function rbLoadRecentDevices() {
  if (!document.getElementById('recentDeviceList')) return;
  try {
    const data = await rbApi('/api/devices/recent', { method: 'GET' });
    rbRecentDevicesCache = data.devices || [];
    rbRenderRecentDevices();
  } catch (e) {
    const list = document.getElementById('recentDeviceList');
    if (list) list.innerHTML = `<div class="device-empty">${rbEsc(e.message)}</div>`;
    const countEl = document.getElementById('recentDeviceCount');
    if (countEl) countEl.textContent = '';
  }
}

function rbRenameDeviceStart(remoteId) {
  rbRecentDevicesEditing = String(remoteId);
  rbRenderRecentDevices();
}

function rbRenameDeviceCancel() {
  rbRecentDevicesEditing = null;
  rbRenderRecentDevices();
}

async function rbRenameDeviceSubmit(remoteId) {
  const input = document.getElementById(`rdRenameInput-${remoteId}`);
  const errEl = document.getElementById(`rdRenameError-${remoteId}`);
  const name = input ? input.value.trim() : '';
  if (!name) {
    if (errEl) { errEl.textContent = 'Enter a device name.'; errEl.classList.remove('hidden'); }
    return;
  }
  try {
    const data = await rbApi('/api/devices/rename', {
      method: 'POST',
      body: JSON.stringify({ remote_id: remoteId, device_name: name }),
    });
    rbRecentDevicesEditing = null;
    rbDeviceInfoCache.delete(remoteId); // cached device-info lookups may now be stale
    rbLog(`Renamed device ${remoteId} to "${data.device_name}".`);
    await rbLoadRecentDevices();
  } catch (e) {
    if (errEl) { errEl.textContent = e.message; errEl.classList.remove('hidden'); }
  }
}

function rbStartRecentDevicesPolling() {
  clearInterval(recentDevicesPollTimer);
  rbLoadRecentDevices();
  recentDevicesPollTimer = setInterval(() => {
    if (rbRecentDevicesEditing) return; // don't yank focus out from under someone typing
    rbLoadRecentDevices();
  }, 15000);
}

rbStartRecentDevicesPolling();

function rbLog(msg) {
  const el = document.getElementById('log');
  const line = `[${new Date().toLocaleTimeString()}] ${msg}`;
  el.textContent = (el.textContent === 'Ready.' ? '' : el.textContent + '\n') + line;
  el.scrollTop = el.scrollHeight;
}

async function rbApi(path, opts) {
  const url = rbUrl(path);
  const res = await fetch(url, Object.assign({ headers: { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' } }, opts));
  if (res.status === 401 && !path.startsWith('/api/auth/')) {
    // Session expired or was never established — bounce to login rather
    // than surfacing a confusing "Authentication required" error inline.
    window.location.href = RB_BASE + '/login.php';
    throw new Error('Authentication required');
  }
  const contentType = res.headers.get('content-type') || '';
  const data = contentType.includes('application/json') ? await res.json().catch(() => ({})) : {};
  if (!res.ok) {
    const detail = data.error || (res.status === 404 ? `API route not found: ${url}` : `Request failed (${res.status})`);
    throw new Error(detail);
  }
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
      rbLoadRecentDevices();
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
        agentConsoleEnabled = !!msg.console_enabled;
        rbAgentStatus('Native control agent: connected — remote mouse/keyboard ready');
        rbLog('Native control agent connected. Remote mouse/keyboard control is ready.');
        rbUpdateConsoleAvailability();
      } else if (msg.type === 'auth_fail') {
        if (agentSocket !== socket) return;
        agentConnecting = false;
        agentConnected = false;
        rbAgentStatus('Native control agent: wrong token');
        socket.close();
      } else if (typeof msg.type === 'string' && msg.type.startsWith('console_')) {
        // Output from the shell running on THIS (host) machine. Mirror it
        // into the host's own on-page console log for visibility, and relay
        // it down the WebRTC control channel to whichever viewer(s) are
        // currently connected, so they see the output they asked for.
        rbHostConsoleAppend(msg);
        hostSessions.forEach((state) => {
          if (state.consoleChannel && state.consoleChannel.readyState === 'open') {
            try { state.consoleChannel.send(JSON.stringify(msg)); } catch (_) {}
          }
        });
      }
    } catch (_) {}
  };
  socket.onclose = () => {
    if (agentSocket !== socket) return;
    agentConnecting = false;
    agentConnected = false;
    agentConsoleEnabled = false;
    rbAgentStatus('Native control agent: not connected');
    rbUpdateConsoleAvailability();
  };
  socket.onerror = () => {
    if (agentSocket !== socket) return;
    agentConnecting = false;
    agentConnected = false;
    agentConsoleEnabled = false;
    rbAgentStatus('Native control agent: connection error');
    rbUpdateConsoleAvailability();
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
  rbAgentStatus('Agent token detected. Connecting…');
  // Starting the local agent should finish the setup automatically. The
  // explicit Connect button remains available for manual/re-authentication.
  setTimeout(() => rbConnectAgent(), 50);
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
  rbLoadAgentConfig().then(() => {
    if (savedAgentToken && /^[A-Za-z0-9_-]{32,128}$/.test(savedAgentToken)) rbConnectAgent();
  });
  // Also recover a token if the agent console was populated by a restored
  // page state before this script finishes initializing.
  rbExtractAgentToken();
});

function rbForwardControlToAgent(cmd) {
  const allow = document.getElementById('allowControl')?.checked;
  if (!allow) return; // host has not granted control permission
  if (!agentConnected || !agentSocket || agentSocket.readyState !== WebSocket.OPEN) {
    // Do not spam the log for every mousemove. The status badge already shows
    // the actual agent state; one warning is enough for a disconnected host.
    if (!rbForwardControlToAgent.warned) {
      rbForwardControlToAgent.warned = true;
      rbLog('Remote control is enabled, but the native control agent is not connected. Start the agent and click Connect.');
    }
    return;
  }
  rbForwardControlToAgent.warned = false;
  try { agentSocket.send(JSON.stringify(cmd)); } catch (_) {}
}

/* ------------------------- Remote console (host side) ------------------------- */
/* A viewer's console_* messages arrive over the same 'control' data channel
 * as mouse/keyboard events (see pc.ondatachannel below) and are routed here
 * instead of rbForwardControlToAgent, gated by a SEPARATE checkbox from
 * mouse/keyboard control since running commands is a much bigger grant of
 * trust than moving the pointer. */

let agentConsoleEnabled = false; // whether the connected agent process was started with RB_AGENT_ENABLE_CONSOLE=1

function rbUpdateConsoleAvailability() {
  const hint = document.getElementById('consoleAvailabilityHint');
  const checkbox = document.getElementById('allowConsole');
  if (!hint || !checkbox) return;
  if (!agentConnected) {
    hint.textContent = 'Connect the native control agent above to enable this.';
    checkbox.disabled = true;
  } else if (!agentConsoleEnabled) {
    hint.textContent = 'The running agent was not started with RB_AGENT_ENABLE_CONSOLE=1, so this is unavailable. See the README.';
    checkbox.disabled = true;
  } else {
    hint.textContent = 'The viewer will be able to run real commands on this computer while checked.';
    checkbox.disabled = false;
  }
}

function rbForwardConsoleToAgent(cmd) {
  const allow = document.getElementById('allowConsole')?.checked;
  if (!allow) return; // host has not granted remote-console permission
  if (!agentConnected || !agentSocket || agentSocket.readyState !== WebSocket.OPEN) return;
  agentSocket.send(JSON.stringify(cmd));
}

/** Mirrors console output into the host's own page so the person sitting at
 * the keyboard can always see exactly what the remote viewer is running. */
function rbHostConsoleAppend(msg) {
  const el = document.getElementById('hostConsoleLog');
  if (!el) return;
  if (msg.type === 'console_started') el.textContent += `\n$ [remote console started: ${msg.shell}]\n`;
  else if (msg.type === 'console_output') el.textContent += msg.data;
  else if (msg.type === 'console_exit') el.textContent += `\n[remote console closed]\n`;
  else if (msg.type === 'console_error') el.textContent += `\n[error] ${msg.message}\n`;
  el.scrollTop = el.scrollHeight;
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
  // Chrome/Edge require screen capture to run in a secure context. localhost
  // is allowed, but an ordinary http://192.168.x.x page is not. Give the host
  // a precise message instead of silently leaving the request pending.
  if (!window.isSecureContext && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1' && location.hostname !== '::1') {
    rbLog('Remote access cannot start from an insecure HTTP page. Open this host page over HTTPS (or localhost for local testing).');
    alert('Remote access needs HTTPS on the host computer. Open RemoteBridge over https://, or use http://localhost when the host and server are the same computer.');
    return;
  }
  if (!navigator.mediaDevices || typeof navigator.mediaDevices.getDisplayMedia !== 'function') {
    rbLog('This browser does not provide screen capture. Use a current Chrome or Edge browser.');
    alert('Screen capture is unavailable in this browser. Please use current Chrome or Edge.');
    return;
  }
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

  const state = { pc, sinceId: 0, controlChannel: null, consoleChannel: null, remoteId: initiatorId, deviceInfo };
  hostSessions.set(sessionId, state);
  rbRenderConnectedViewers();

  // The viewer creates both data channels (it's the offerer); we just
  // receive them here and wire up incoming events.
  //  - 'control': mouse/keyboard, unreliable/unordered — dropping a stale
  //    pointer position is fine and keeps latency low.
  //  - 'console': remote-console I/O, reliable/ordered — dropping bytes of
  //    a typed command or its output would corrupt the session, so this is
  //    a separate channel with normal (reliable) delivery.
  pc.ondatachannel = (ev) => {
    if (ev.channel.label === 'control') {
      state.controlChannel = ev.channel;
      ev.channel.onmessage = (msgEv) => {
        let cmd;
        try { cmd = JSON.parse(msgEv.data); } catch (_) { return; }
        rbForwardControlToAgent(cmd);
      };
    } else if (ev.channel.label === 'console') {
      state.consoleChannel = ev.channel;
      ev.channel.onmessage = (msgEv) => {
        let cmd;
        try { cmd = JSON.parse(msgEv.data); } catch (_) { return; }
        rbForwardConsoleToAgent(cmd);
      };
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
let viewerConsoleChannel = null;
let viewerConsoleStarted = false;
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

  // Separate, reliable channel for the remote console. Unlike 'control'
  // above, dropped bytes here would corrupt commands/output, so this one
  // uses ordered/reliable delivery (the WebRTC default).
  viewerConsoleChannel = pc.createDataChannel('console', { ordered: true });
  viewerConsoleChannel.onopen = () => {
    rbLog('Console channel open.');
    const btn = document.getElementById('btnConsoleStart');
    if (btn) btn.disabled = false;
  };
  viewerConsoleChannel.onclose = () => {
    rbLog('Console channel closed.');
    viewerConsoleStarted = false;
    rbSetConsoleUiState('closed');
  };
  viewerConsoleChannel.onmessage = (msgEv) => {
    let msg;
    try { msg = JSON.parse(msgEv.data); } catch (_) { return; }
    rbViewerConsoleHandle(msg);
  };

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
  viewerConsoleChannel = null;
  viewerConsoleStarted = false;
  rbSetConsoleUiState('closed');
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

/* ------------------------- Remote console (viewer side) ------------------------- */

/** Reflects channel/session state onto the console panel's buttons so the
 * viewer can't click Start before a channel exists or Send before a shell
 * is actually running. 'closed' | 'ready' | 'running'. */
function rbSetConsoleUiState(state) {
  const btnStart = document.getElementById('btnConsoleStart');
  const btnStop = document.getElementById('btnConsoleStop');
  const input = document.getElementById('consoleInput');
  const btnSend = document.getElementById('btnConsoleSend');
  if (!btnStart || !btnStop || !input || !btnSend) return;
  const channelOpen = !!(viewerConsoleChannel && viewerConsoleChannel.readyState === 'open');
  btnStart.disabled = !channelOpen || state === 'running';
  btnStop.disabled = state !== 'running';
  input.disabled = state !== 'running';
  btnSend.disabled = state !== 'running';
}

function rbConsoleLog(text) {
  const el = document.getElementById('viewerConsoleLog');
  if (!el || !text) return;
  el.textContent += text;
  el.scrollTop = el.scrollHeight;
}

function rbViewerConsoleHandle(msg) {
  if (msg.type === 'console_started') {
    viewerConsoleStarted = true;
    rbSetConsoleUiState('running');
    rbConsoleLog(`\n$ [remote console started: ${msg.shell}]\n`);
  } else if (msg.type === 'console_output') {
    rbConsoleLog(msg.data);
  } else if (msg.type === 'console_exit') {
    viewerConsoleStarted = false;
    rbSetConsoleUiState('ready');
    rbConsoleLog('\n[remote console closed]\n');
  } else if (msg.type === 'console_error') {
    rbConsoleLog(`\n[error] ${msg.message}\n`);
    if (!viewerConsoleStarted) rbSetConsoleUiState('ready');
  }
}

function rbConsoleStart() {
  if (!viewerConsoleChannel || viewerConsoleChannel.readyState !== 'open') return;
  document.getElementById('viewerConsoleLog').textContent = '';
  viewerConsoleChannel.send(JSON.stringify({ type: 'console_start' }));
}

function rbConsoleSendLine() {
  const input = document.getElementById('consoleInput');
  if (!input || !viewerConsoleChannel || viewerConsoleChannel.readyState !== 'open') return;
  const line = input.value;
  if (line.length === 0) return;
  viewerConsoleChannel.send(JSON.stringify({ type: 'console_input', data: line + '\n' }));
  input.value = '';
}

function rbConsoleStop() {
  if (!viewerConsoleChannel || viewerConsoleChannel.readyState !== 'open') return;
  viewerConsoleChannel.send(JSON.stringify({ type: 'console_stop' }));
  viewerConsoleStarted = false;
  rbSetConsoleUiState('ready');
}
