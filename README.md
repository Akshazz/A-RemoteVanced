# RemoteBridge 2.0 — Native PHP Database Edition

This edition uses **native PHP `mysqli`** to connect to **MySQL/MariaDB**. SQLite has been removed.

## Requirements
- PHP 8.1+
- PHP `mysqli` extension
- MySQL 8+ or MariaDB 10.6+
- Browser with WebRTC support

## Database setup
All application database connections are centralized in **`config.php`**. You do not need to search through `index.php`, `database.php`, or the migration runner to change the database server.

For a normal XAMPP setup, edit only this block in `config.php`:

```php
'database' => [
    'host' => '127.0.0.1',
    'port' => 3306,
    'database' => 'a_remote',
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8mb4',
],

'agent' => [
    'host' => '127.0.0.1', // safest: same computer only
    'port' => 8791,
],
```

Environment variables are also supported and take priority over the values in `config.php`:

- `RB_DB_HOST`
- `RB_DB_PORT`
- `RB_DB_NAME`
- `RB_DB_USER`
- `RB_DB_PASSWORD`

Run the PHP migration system:

```bash
php database/migrate.php
```

It creates the MySQL/MariaDB database and applies versioned PHP migrations.

## Main program

`index.php` is the default/main application entry point. `server.php` remains only as a backward-compatible router.

## Connecting over the internet vs. on a local network

The page shows a **Connection mode** badge at the top, detected automatically from the URL you open it with:

- **Local Network** — you opened it at `localhost`, `127.0.0.1`, or a private LAN
  address (`192.168.x.x`, `10.x.x.x`, `172.16–31.x.x`). Both browsers reach
  this PHP+MySQL signaling server directly, and a public STUN server is
  enough for WebRTC to find a direct peer-to-peer path. No extra setup.
- **Internet (server-based)** — you opened it at a public domain/IP. For two
  computers on *different* networks to find each other, `index.php` + MySQL
  need to be deployed somewhere both sides can reach (a VPS or hosting
  provider, over HTTPS). STUN alone often isn't enough here — mobile
  carriers, offices, and some home routers use symmetric NAT or firewalls
  that only a **TURN relay** can get through.

  By default this now works with **zero setup**: if no TURN server is
  configured, `GET /api/ice-servers` automatically falls back to the
  [Open Relay Project](https://www.openrelayproject.org)'s free, shared TURN
  server (20GB/month pool, public credentials — fine for testing/personal
  use). The badge shows "TURN relay: free shared (Open Relay)" when this is
  active. For anything production or high-traffic, run your own and it'll
  be preferred automatically:

  ```bash
  export RB_TURN_URL=turn:turn.example.com:3478
  export RB_TURN_USERNAME=your-username
  export RB_TURN_CREDENTIAL=your-credential
  ```

  or edit the `turn` block in `config.php`. To disable the free fallback
  entirely (STUN-only, or your own TURN only), set `RB_DISABLE_FREE_TURN=1`.
  `public/app.js` fetches whichever set is active on load, so there's
  nothing to hardcode per-deployment. Any standard TURN server works if you
  bring your own (e.g. [coturn](https://github.com/coturn/coturn),
  self-hosted or via a managed TURN provider).

## Devices on this network (sidebar)

The right-hand sidebar lists devices from this **server machine's own ARP
cache** — IP and MAC address, as tiles, refreshed on load. This only shows
devices the machine running `index.php` has recently exchanged packets
with (its normal ARP table), not a full network inventory. Click **"Scan
network"** to have the server ping-sweep its own `/24` first (a few
seconds, best-effort) so more of the LAN shows up before re-reading the ARP
cache.

Like the agent start/stop endpoints, `/api/network/devices` and
`/api/network/scan` only respond to requests from `127.0.0.1`/`::1` — they
run real local commands (`arp`, `ping`), so they're refused from any other
client IP regardless of deployment mode.

### Access control

The sidebar is locked by default. On first use, a random access code is
generated and saved to `data/network-sidebar.token` on the server — read it
from there (you need filesystem access to the machine anyway) and paste it
into the sidebar's "Unlock" field, or set `RB_NETWORK_ACCESS_CODE` yourself
to pick a fixed one. Unlocking is remembered per browser session (PHP
session, not just client-side) until you click "Lock" or the session ends;
`/api/network/devices` and `/api/network/scan` both refuse to run while
locked, even from localhost.

## Remote desktop over a Remote ID (new)

The app now does what its schema was built for: consensual, browser-to-browser
remote desktop over WebRTC.

- **Host tab ("Share my screen")** — the browser registers itself and is
  assigned a 9-digit Remote ID (stored in `localStorage`, reused on reload).
  Click **Go online** to start accepting requests. Online/offline presence is fully manual: refreshing the page does not disconnect or force the host offline, and the selected state is restored after refresh. Click **Go offline** to stop accepting requests and immediately mark the Remote ID offline. When someone requests a
  connection, you see their Remote ID and must click **Accept** before
  `getDisplayMedia()` even runs — nothing is shared silently.
- **Viewer tab ("Connect to a Remote ID")** — enter the other side's Remote
  ID and click **Connect**. Once accepted, a `<video>` element shows their
  screen live.
- The PHP server only relays the small WebRTC signaling handshake (SDP
  offer/answer + ICE candidates) through the `signals` table and a short-poll
  API (`/api/session/*`, `/api/signal*`). The actual video stream is
  peer-to-peer via WebRTC and never touches the server.
- Uses Google's public STUN server for NAT traversal. If both sides are
  behind restrictive/symmetric NATs, a TURN server is needed for the
  connection to succeed — not included here; add one in
  `public/app.js` (`ICE_SERVERS`) if you hit connection failures across
  networks.

This is meant for **authorized, consensual** access — e.g. helping a
coworker or accessing your own second machine. It is not a silent/unattended
access tool: the host must click Accept for every session.

## Native control agent connection

The Native Control Agent now uses the `agent` block in `config.php` for its
bind address and port. You normally only need the default:

```php
'agent' => [
    'host' => '127.0.0.1',
    'port' => 8791,
],
```

This is **local-only** and is the recommended setting. If you intentionally
want another computer on the same LAN to connect directly to the host's
control agent, change the bind address to `0.0.0.0` and keep the random token
requirement enabled:

```php
'agent' => [
    'host' => '0.0.0.0',
    'port' => 8791,
],
```

Restart the agent after changing this setting. The browser automatically uses
the current page hostname when LAN binding is enabled, so you do not need to
hardcode a `192.168.x.x` address in `public/app.js`.

The Host tab now provides:
- automatic complete-token extraction from the agent console;
- **Read token** to rescan the command output;
- **Copy token** to copy the full token value;
- token-length validation so truncated/incomplete values cannot be submitted;
- a Show/Hide token control;
- agent endpoint diagnostics showing local-only vs LAN mode;
- PHP signaling-server status and WebSocket endpoint information.

The PHP page can start the agent only from localhost because starting a local
OS process from an arbitrary LAN browser would be unsafe. Start it from the
host computer's localhost page (or from a terminal), then connect from the
LAN browser using the generated token.

## Users connected locally

The **Users connected locally** panel appears directly below **Devices on this
network** after the network sidebar is unlocked. It is backed by the
`devices` table and a heartbeat, rather than only the operating system ARP
cache, so an active browser user can appear even when the server has not
learned a MAC address for that device yet.

A user is considered online when the server has seen a heartbeat within the
last two minutes. The list shows Remote ID, device name, IP address, MAC when
available, and last-seen time. The browser refreshes this list every 10
seconds.

Every opened RemoteBridge page now sends a lightweight heartbeat every 20
seconds, so viewers are included in local-user presence even when they are not
sharing their screen.

## Remote control (mouse & keyboard)

Viewing is browser-only, but *controlling* another computer requires
injecting real OS-level input — something a browser tab is deliberately not
allowed to do to your machine. So control works in two layers:

1. **WebRTC data channel** — while a session is active, the viewer's
   mouse/keyboard events (captured from the `<video>` element) are sent
   over a `control` data channel to the host's browser tab. This part is
   already peer-to-peer and needs nothing extra.
2. **Local control agent** (`agent/control-agent.js`) — a small Node.js
   process the **host** runs on their own machine. It listens on
   `127.0.0.1:8791` by default (or the `agent.host`/`agent.port` values from
   `config.php`) and requires a random token (printed on first run,
   saved to `agent/.token`) before accepting any commands — so another tab
   or website can't quietly drive it. On Windows it injects input by
   calling `user32.dll` directly through a bundled PowerShell helper
   (`agent/win-input.ps1`) — **no compiler, Python, or node-gyp needed.**
   (macOS/Linux aren't supported by this build; they'd need a different
   native backend such as `robotjs` or `nut-js`.)

To enable control, either run it yourself:
```bash
cd agent
npm install
node control-agent.js
```
or click **"Run server"** under Host tab → "Native control agent" — the page
runs `cd agent && npm install && node control-agent.js` for you via a local
PHP endpoint (`/api/agent/start`) and streams the output into an in-page
console, so you never have to open a terminal. This only works when the
PHP server itself is reached at `127.0.0.1`/`::1`, since it spawns a real
process on whatever machine is running it — the endpoint refuses any other
client IP. Either way, copy the printed token into the **Host tab → "Native control agent"**
field in the browser and click Connect. Then, once a viewer is connected,
check **"Allow the connected viewer to control this mouse & keyboard"**.
Unchecking it (or closing the agent process) revokes control immediately.

**Only run the agent, and only check the control box, for someone you
actually want driving your computer right now.** There is no way to
partially scope control — once granted, the viewer can move the mouse and
type anywhere on the host's screen for as long as the checkbox stays
checked.

## Run

```bash
php database/migrate.php
php -S 0.0.0.0:8080 index.php
```

Open `http://127.0.0.1:8080/`.

## Database tables
- `migrations`
- `devices`
- `sessions`
- `signals`
- `transfer_history`
- `audit_log`

No `.sqlite` file is required or used.

## Security
The MAC-derived Remote ID is an identifier, not a secret. Use separate high-entropy pairing/authorization tokens. Production deployments should use HTTPS, authentication, rate limiting, and TURN where needed.

## Database SQL

A complete native MySQL/MariaDB schema is included at:

`database/schema.sql`

You can import it directly with MySQL/MariaDB, for example:

`mysql -u root -p < database/schema.sql`

The PHP migration runner is also available at `database/migrate.php`.


## Running under XAMPP/Apache in a subfolder

If the project is extracted to `htdocs/A-RemoteVanced`, open:

`http://localhost/A-RemoteVanced/`

The included `.htaccess` routes `/A-RemoteVanced/health` and `/A-RemoteVanced/api/info` back to `index.php`. The frontend also detects the installation folder automatically, so it does not incorrectly request `http://localhost/health`.

The `ws://127.0.0.1:5500//ws` message is from a Live Server/browser reload extension. It is not a RemoteBridge WebRTC connection and is not required by this PHP project. Disable Live Server/reload.js for this project, or simply ignore that separate console message.

## Local network users and devices

The **Users connected locally** panel below **Devices on this network** now shows the full set of devices currently visible to the server through its local ARP table. Each row includes the device name/hostname when reverse DNS can resolve it, local IP address, MAC address, and whether it is also registered with RemoteBridge. A registered RemoteBridge user is matched by IP and shows its Remote ID.

Important: a LAN server cannot reliably determine a human user's identity from IP/MAC alone. Devices that are visible through ARP are therefore presented as network devices; RemoteBridge identity is shown only when that device has registered with the application. A MAC can be unavailable for a recently registered user until the server learns that client's ARP entry.

Use **Scan network** to refresh the server's ARP cache before loading the local-user/device list.


## Network Access Code administration

Use `admin/settings.php` from localhost to save or generate the code used to unlock the Devices on this network panel. The preferred value is stored in the `app_settings` database table; config.php/environment and the legacy token file remain fallbacks.
