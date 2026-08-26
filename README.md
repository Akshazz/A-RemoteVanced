# RemoteBridge

RemoteBridge is a self-hosted remote support / remote desktop application. It's a PHP + MySQL web app that lets one browser tab ("viewer") connect to another browser tab ("host") over WebRTC — screen sharing, mouse/keyboard control, and an optional command console — plus a small optional native agent that injects real OS-level input on the host machine.

It's built to be run on your own server (or locally via XAMPP/MAMP) for trusted, consenting use — e.g. supporting a friend/relative's PC, or accessing your own machines remotely. It is **not** intended to be exposed to the public internet without further hardening, and the host/agent side must only be run on a machine you intend to let a specific trusted person control.

---

## How it works

RemoteBridge has three cooperating pieces:

1. **PHP/MySQL web application** (`index.php`, `login.php`, `admin/`) — serves the UI, stores user accounts, devices, and sessions, and acts as a **signaling server**: it relays the WebRTC offer/answer/ICE messages between the host and viewer browser tabs so they can establish a direct peer-to-peer connection. It also proxies remote console (shell) input/output between the viewer and the host's local agent.
2. **Browser app** (`public/app.js`) — runs in both the host's and the viewer's browser tab. The host tab shares its screen via `getDisplayMedia` and (if the native agent is running) forwards input events to it. The viewer tab receives the video stream and sends mouse/keyboard/console commands.
3. **Local control agent** (`agent/control-agent.js`) — an optional Node.js process that runs **on the host machine only**. Browsers can't control the OS directly, so this small local WebSocket server bridges the gap: it injects real mouse/keyboard input (Windows, via `win-input.ps1` calling `user32.dll`) and, if explicitly enabled, exposes a real shell (cmd/bash/git-bash) to the connected, authenticated browser tab.

### Typical flow
1. A host device registers itself and gets a 9-digit Remote ID (`/api/register`), then periodically checks in (`/api/heartbeat`).
2. A signed-in user on the viewer side enters that Remote ID and requests a session (`/api/session/create`).
3. The host is notified of the pending request (`/api/session/pending`) and accepts or declines (`/api/session/respond`).
4. Once accepted, both sides exchange WebRTC signaling messages through the server (`/api/signal`, `/api/signal/poll`) and establish a direct peer-to-peer screen-share/control connection.
5. Optionally, the host starts the local control agent so the viewer can move the real mouse/keyboard, and — only if explicitly enabled — run shell commands.
6. Either side can end the session (`/api/session/close`).

### Security model (built in)
- Every page and API action requires a signed-in account (session-based auth, bcrypt password hashes, CSRF token on the login form).
- Roles: `admin` (manage users, view audit log, change settings) vs `user`.
- All important events (logins, session creation/accept/close, user management, agent start/stop, network scans) are written to an `audit_log` table.
- Login attempts are rate-limited per IP+username.
- The **local control agent** binds to `127.0.0.1` by default and requires a random token (printed on first run / stored in `agent/.token`) before it will accept any command — so no other tab or site in the same browser can command it. LAN exposure (`RB_AGENT_HOST=0.0.0.0`) is opt-in and still token-gated.
- The agent's **remote console/shell** is a separate, opt-in capability (`RB_AGENT_ENABLE_CONSOLE=1`) — disabled by default because it lets the connected viewer run arbitrary commands on the host.
- Endpoints that spawn local OS processes (e.g. starting the console bridge) are restricted to `127.0.0.1` on the server side; the LAN device-discovery scan is restricted to clients on the same LAN as the server.
- `.htaccess` blocks direct web access to `config.php`, `database.php`, `.token`, log/SQL/env files, and the `includes/` folder.

---

## Setup

### Requirements
- PHP 8+ with `mysqli`
- MySQL/MariaDB
- Apache (or another server that reads `.htaccess`) with `mod_rewrite`, or any server configured to route unmatched requests to `index.php`
- Node.js (only needed if you want the optional local control agent)

### 1. Get the code running behind a web server
Point your web server's document root at the project folder (this is a normal PHP app — e.g. drop it into `htdocs/` if you're using XAMPP).

### 2. Configure the database connection
Edit `config.php` (or set the equivalent `RB_DB_*` environment variables — env vars take priority):
```php
'database' => [
    'host' => '127.0.0.1',
    'port' => 3306,
    'database' => 'remote_bridge',
    'username' => 'root',
    'password' => '',
],
```

### 3. Create the database and run migrations
```bash
php database/migrate.php
```
This creates the database (if it doesn't exist) and applies every migration in `database/migrations/`, creating the `users`, `devices`, `sessions`, `signals`, `audit_log`, `transfer_history`, and `app_settings` tables. (`database/remote_bridge-OFFICIAL.sql` is a full schema+seed dump you can import directly instead, if you prefer.)

### 4. Sign in
Visit the app in your browser — you'll land on `login.php`. Sign in with an admin account (create one via the SQL seed/migration, or directly in the `users` table with a bcrypt `password_hash`). From the admin panel (`admin/settings.php`) you can then create additional user/admin accounts, toggle roles, disable accounts, and browse the audit log.

### 5. (Optional) Set up the local control agent — on the *host* machine only
Only install this on a machine you intend to let a specific, trusted remote person control:
```bash
cd agent
npm install
node control-agent.js
# To also allow the remote console/shell feature (opt-in, bigger trust decision):
RB_AGENT_ENABLE_CONSOLE=1 node control-agent.js      # macOS/Linux
set RB_AGENT_ENABLE_CONSOLE=1 && node control-agent.js   # Windows cmd
```
On first run it prints a random token (also saved to `agent/.token`). Paste that token into the "Native control agent" field on the Host tab of the RemoteBridge page and click Connect. Real mouse/keyboard input injection currently only works on Windows (via PowerShell/`user32.dll`); the remote console works cross-platform if enabled. Closing the agent process immediately revokes control.

---

## Main functions / features

| Area | What it does |
|---|---|
| **Authentication** (`/api/auth/*`, `login.php`) | Session login/logout, CSRF-protected login form, "who am I" check, rate-limited attempts |
| **Admin panel** (`/api/admin/*`, `admin/settings.php`) | List/create/update users, reset passwords, toggle admin/user role, enable/disable accounts, view the audit log |
| **Device registration** (`/api/register`, `/api/heartbeat`, `/api/offline`, `/api/device/lookup`, `/api/devices/recent`, `/api/devices/rename`) | A host machine registers to get a 9-digit Remote ID, sends periodic heartbeats so it shows as online, and can be looked up/renamed/recently-connected-to |
| **Remote sessions** (`/api/session/*`) | Create a connection request to a Remote ID, host accepts/declines, poll session status, close a session |
| **WebRTC signaling** (`/api/signal`, `/api/signal/poll`, `/api/ice-servers`) | Relays SDP offer/answer and ICE candidates between host and viewer so they can form a direct peer-to-peer connection; supplies STUN/TURN server info |
| **Local control agent bridge** (`/api/agent/*`) | Starts/stops/monitors the local Node.js agent process and streams its console output back to the viewer (localhost-only) |
| **Network discovery** (`/api/network/devices`, `/api/network/users`, `/api/network/scan`) | Sweeps the server's local subnet (via `network-scan.sh`/`network-scan.ps1`) to help find other devices/RemoteBridge instances on the same LAN |
| **Control agent** (`agent/control-agent.js`) | Injects real mouse/keyboard input on the host (Windows) and, if enabled, exposes a remote shell — gated by a local token and (for the shell) an explicit opt-in flag |

---

## Project structure
```
index.php              Main app entry point + almost all JSON API routes
login.php               Sign-in page
admin/settings.php      Admin panel (users, roles, audit log)
includes/bootstrap.php  Shared auth/session/CSRF/audit-log helpers
config.php               App + database + agent configuration
database.php             MySQL connection helper
public/app.js             Browser-side app (host + viewer UI, WebRTC logic)
agent/control-agent.js   Optional local Node.js control agent
agent/win-input.ps1       Windows input-injection helper (user32.dll)
agent/network-scan.sh/.ps1  LAN ping-sweep helpers used by network discovery
database/migrate.php      Migration runner
database/migrations/      Individual schema migrations
database/remote_bridge-OFFICIAL.sql  Full schema + seed dump
review/                    Prior RBAC code-review documents (historical)
```

---

## Notes
- Keep `config.php`, `database.php`, and `agent/.token` out of version control and off the public web (already blocked by `.htaccess`).
- Only run the control agent on machines whose owner has agreed to be remotely controlled, and only while a session is actually active.
- The `review/` folder contains an earlier code review that flagged missing authentication/authorization; that has since been implemented (see `includes/bootstrap.php` and the `/api/admin/*`, `/api/auth/*` routes) — the review docs are kept for historical reference.


## Kali / VMware Security Lab

The project now includes an **admin-only, localhost-only Security Lab** at
`/admin/security_lab.php`. It is designed for authorized testing and pairs
with the existing defensive Security Center.

### Features
- VMware Workstation `vmrun` start/status/stop controls for a configured Kali VM.
- Optional SSH execution into Kali using an SSH private key.
- Restricted Kali operational console presets (`uname`, `ip`, routes, sockets,
  `whoami`, and Nmap localhost validation).
- Nmap profiles: quick, service, safe NSE, and an explicitly confirmed
  vulnerability-lab profile.
- Every offensive run requires a scope that was explicitly marked authorized.
- Run history and audit-log events.
- No general-purpose remote shell is exposed by the Security Lab UI.

### Windows/XAMPP environment

Set these environment variables before starting Apache/PHP:

```text
RB_SECURITY_LAB_ENABLED=1
RB_VMWARE_VMRUN=C:\Program Files (x86)\VMware\VMware Workstation\vmrun.exe
RB_KALI_VMX=C:\VMs\Kali-Linux\Kali-Linux.vmx
RB_KALI_SSH_HOST=127.0.0.1
RB_KALI_SSH_PORT=22
RB_KALI_SSH_USER=kali
RB_KALI_SSH_KEY=C:\Users\YOURUSER\.ssh\kali_lab
RB_SECURITY_LAB_TIMEOUT=45
```

Use **either** SSH configuration or VMware guest credentials. SSH keys are
preferred because passwords do not need to be placed in process arguments.

If using VMware guest execution instead:

```text
RB_KALI_GUEST_USER=kali
RB_KALI_GUEST_PASSWORD=CHANGE_ME
```

Do not commit real passwords, SSH private keys, `.env` files, or VM snapshots
to source control.

### Kali prerequisites

Inside the Kali VM, install the authorized assessment tools:

```bash
sudo apt update
sudo apt install -y nmap openssh-server
sudo systemctl enable --now ssh
```

For the optional web assessment workflow, install Nikto separately and add
its integration only after validating the lab network boundary.

### Scope model

1. Open **Security Center → Authorized scope**.
2. Add an IP/hostname that you own or have explicit permission to assess.
3. Tick the authorization confirmation.
4. Open **Kali / VMware Lab**.
5. Start the Kali VM and verify connectivity.
6. Run a bounded Nmap profile.
7. Review the output and defensive findings.

The vulnerability-lab profile is intentionally gated behind an additional
confirmation because vulnerability NSE scripts can be more intrusive than
the normal defensive audit.

### VMware note

The application controls an existing VMware VM; it does not create or modify
VMware virtual hardware automatically. Create/import the Kali VM in VMware
Workstation first, then point `RB_KALI_VMX` to its `.vmx` file.

### Security Lab readiness workflow

After applying the database migrations, open `admin/security_lab.php` as an admin.
The lab now exposes a readiness check that verifies the Kali guest identity,
network configuration, Nmap availability, and SSH service before an assessment.
It also provides target DNS resolution, bounded Nmap result parsing, run duration,
open-port summaries, and a 100-run history table.

If the lab reports that VMware or SSH is not configured, fix the environment
variables first rather than trying to run the assessment. The application keeps
all scan targets tied to an explicitly authorized Security Center scope.
