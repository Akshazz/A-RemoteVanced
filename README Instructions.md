# RemoteBridge 2.0 — Native PHP Database Edition

This edition uses **native PHP `mysqli`** to connect to **MySQL/MariaDB**. SQLite has been removed.

## Requirements
- PHP 8.1+
- PHP `mysqli` extension
- MySQL 8+ or MariaDB 10.6+
- Browser with WebRTC support

## Database setup
Configure with environment variables:

- `RB_DB_HOST`
- `RB_DB_PORT`
- `RB_DB_NAME`
- `RB_DB_USER`
- `RB_DB_PASSWORD`

Or edit `config.php`.

Run the PHP migration system:

```bash
php database/migrate.php
```

It creates the MySQL/MariaDB database and applies versioned PHP migrations.

## Main program

`index.php` is the default/main application entry point. `server.php` remains only as a backward-compatible router.

## Remote desktop over a Remote ID (new)

The app now does what its schema was built for: consensual, browser-to-browser
remote desktop over WebRTC.

- **Host tab ("Share my screen")** — the browser registers itself and is
  assigned a 9-digit Remote ID (stored in `localStorage`, reused on reload).
  Click **Go online** to start accepting requests. When someone requests a
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

## Remote control (mouse & keyboard)

Viewing is browser-only, but *controlling* another computer requires
injecting real OS-level input — something a browser tab is deliberately not
allowed to do to your machine. So control works in two layers:

1. **WebRTC data channel** — while a session is active, the viewer's
   mouse/keyboard events (captured from the `<video>` element) are sent
   over a `control` data channel to the host's browser tab. This part is
   already peer-to-peer and needs nothing extra.
2. **Local control agent** (`agent/control-agent.js`) — a small Node.js
   process the **host** runs on their own machine. It listens only on
   `127.0.0.1:8791` and requires a random token (printed on first run,
   saved to `agent/.token`) before accepting any commands — so another tab
   or website can't quietly drive it. On Windows it injects input by
   calling `user32.dll` directly through a bundled PowerShell helper
   (`agent/win-input.ps1`) — **no compiler, Python, or node-gyp needed.**
   (macOS/Linux aren't supported by this build; they'd need a different
   native backend such as `robotjs` or `nut-js`.)

To enable control:
```bash
cd agent
npm install
node control-agent.js
```
Copy the printed token into the **Host tab → "Native control agent"**
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
