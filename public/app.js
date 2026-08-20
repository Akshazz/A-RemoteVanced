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

const ICE_SERVERS = [{ urls: 'stun:stun.l.google.com:19302' }];
const POLL_MS = 2000;
const AGENT_WS_URL = 'ws://127.0.0.1:8791';

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

/* --------------------------- Identity --------------------------- */

let myRemoteId = localStorage.getItem('rb_remote_id') || '';

async function rbEnsureRegistered() {
  const data = await rbApi('/api/register', {
    method: 'POST',
    body: JSON.stringify({ remote_id: myRemoteId, device_name: navigator.platform || 'Browser' }),
  });
  myRemoteId = data.remote_id;
  localStorage.setItem('rb_remote_id', myRemoteId);
  document.getElementById('myRemoteId').textContent = myRemoteId.replace(/(\d{3})(?=\d)/g, '$1 ');
  return myRemoteId;
}
rbEnsureRegistered().catch(e => rbLog('Registration failed: ' + e.message));

/* --------------------- Native control agent (host) --------------------- */
/* Optional local companion process that actually injects mouse/keyboard
 * input into the OS. The browser tab talks to it over a loopback-only
 * WebSocket and must present the token shown in the agent's terminal. */

let agentSocket = null;
let agentConnected = false;

function rbAgentStatus(text) {
  const el = document.getElementById('agentStatus');
  if (el) el.textContent = text;
}

function rbConnectAgent() {
  const token = (document.getElementById('agentToken')?.value || '').trim();
  if (!token) { rbAgentStatus('Enter the token shown in the agent terminal first.'); return; }
  localStorage.setItem('rb_agent_token', token);
  try {
    agentSocket = new WebSocket(AGENT_WS_URL);
  } catch (e) {
    rbAgentStatus('Could not open connection to local agent.');
    return;
  }
  agentSocket.onopen = () => {
    agentSocket.send(JSON.stringify({ type: 'auth', token }));
  };
  agentSocket.onmessage = (ev) => {
    try {
      const msg = JSON.parse(ev.data);
      if (msg.type === 'auth_ok') { agentConnected = true; rbAgentStatus('Native control agent: connected'); }
      else if (msg.type === 'auth_fail') { agentConnected = false; rbAgentStatus('Native control agent: wrong token'); agentSocket.close(); }
    } catch (_) {}
  };
  agentSocket.onclose = () => { agentConnected = false; rbAgentStatus('Native control agent: not connected'); };
  agentSocket.onerror = () => { agentConnected = false; rbAgentStatus('Native control agent: not connected'); };
}

const savedAgentToken = localStorage.getItem('rb_agent_token');
document.addEventListener('DOMContentLoaded', () => {
  if (savedAgentToken && document.getElementById('agentToken')) {
    document.getElementById('agentToken').value = savedAgentToken;
  }
});

function rbForwardControlToAgent(cmd) {
  const allow = document.getElementById('allowControl')?.checked;
  if (!allow) return; // host has not granted control permission
  if (!agentConnected || !agentSocket || agentSocket.readyState !== WebSocket.OPEN) return;
  agentSocket.send(JSON.stringify(cmd));
}

/* ----------------------------- Host ------------------------------ */

let hostOnline = false;
let hostHeartbeatTimer = null;
let hostPendingPollTimer = null;
let hostStream = null;
const hostSessions = new Map(); // session_id -> { pc, pollTimer, sinceId, controlChannel }

function rbToggleSharePresence() {
  hostOnline = !hostOnline;
  const btn = document.getElementById('btnShareScreen');
  if (hostOnline) {
    btn.textContent = 'Go offline';
    btn.classList.remove('secondary');
    rbLog('You are online. Waiting for connection requests…');
    hostHeartbeatTimer = setInterval(() => {
      rbApi('/api/heartbeat', { method: 'POST', body: JSON.stringify({ remote_id: myRemoteId }) }).catch(() => {});
    }, 20000);
    hostPendingPollTimer = setInterval(rbPollIncoming, POLL_MS);
    rbPollIncoming();
  } else {
    btn.textContent = 'Go online';
    btn.classList.add('secondary');
    clearInterval(hostHeartbeatTimer);
    clearInterval(hostPendingPollTimer);
    document.getElementById('incomingRequests').innerHTML = '';
    rbLog('You are offline.');
  }
}

async function rbPollIncoming() {
  try {
    const data = await rbApi(`/api/session/pending?remote_id=${encodeURIComponent(myRemoteId)}`, { method: 'GET' });
    const container = document.getElementById('incomingRequests');
    const pending = data.sessions.filter(s => s.status === 'pending' && !hostSessions.has(s.session_id));
    container.innerHTML = '';
    pending.forEach(s => {
      const div = document.createElement('div');
      div.className = 'request';
      div.innerHTML = `<span>Connection request from <strong>${s.initiator_remote_id}</strong></span>`;
      const acceptBtn = document.createElement('button');
      acceptBtn.textContent = 'Accept';
      acceptBtn.onclick = () => rbAcceptSession(s.session_id, s.initiator_remote_id);
      const declineBtn = document.createElement('button');
      declineBtn.textContent = 'Decline';
      declineBtn.className = 'secondary';
      declineBtn.onclick = async () => {
        await rbApi('/api/session/respond', { method: 'POST', body: JSON.stringify({ session_id: s.session_id, action: 'reject' }) });
        rbLog(`Declined request from ${s.initiator_remote_id}`);
        rbPollIncoming();
      };
      div.appendChild(acceptBtn);
      div.appendChild(declineBtn);
      container.appendChild(div);
    });
  } catch (e) { /* transient network errors: ignore */ }
}

async function rbAcceptSession(sessionId, initiatorId) {
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
  rbLog(`Accepted ${initiatorId}. Setting up connection…`);

  const pc = new RTCPeerConnection({ iceServers: ICE_SERVERS });
  hostStream.getTracks().forEach(track => pc.addTrack(track, hostStream));
  hostStream.getVideoTracks()[0].onended = () => rbStopHosting();

  const state = { pc, sinceId: 0, controlChannel: null };
  hostSessions.set(sessionId, state);

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
  rbLog('Stopped sharing.');
}

/* ---------------------------- Viewer ------------------------------ */

let viewerPc = null;
let viewerSessionId = null;
let viewerPollTimer = null;
let viewerSinceId = 0;
let viewerStatusTimer = null;
let viewerControlChannel = null;
let viewerLastMove = 0;

async function rbConnectToRemote() {
  const target = document.getElementById('targetRemoteId').value.replace(/\s+/g, '');
  if (!target) return;
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
  document.getElementById('viewerState').textContent = 'Disconnected.';
  document.getElementById('btnConnect').disabled = false;
  if (viewerSessionId) {
    rbApi('/api/session/close', { method: 'POST', body: JSON.stringify({ session_id: viewerSessionId }) }).catch(() => {});
  }
  viewerSessionId = null;
}
