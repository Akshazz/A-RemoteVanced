# RemoteBridge

## XAMPP
1. Extract this folder to `C:\xampp\htdocs\A-RemoteVanced`.
2. Create/import the `remote_bridge` database. The included `database\remote_bridge.sql` is schema-only; alternatively run `php database\migrate.php`.
3. Confirm `config.php` matches your MySQL/MariaDB credentials.
4. Open `http://localhost/A-RemoteVanced/`.

## Node control agent (optional)
From the project root:
```bash
npm install
npm run start:agent
```
The agent is optional and is only needed for native mouse/keyboard control (Windows only)
and/or the remote console feature (Windows/macOS/Linux). It prints a one-time token —
paste that into the "Native control agent" box on the Host tab and click Connect.

## Remote console (git-bash / bash / cmd)
The control agent can also give the connected viewer a real command line on your
machine — like git-bash on Windows, or `bash`/`sh` on macOS/Linux. This is **off by
default** because it's a much bigger grant of trust than mouse/keyboard input: it lets
the remote person run arbitrary commands, not just click things.

To turn it on:
1. Start the agent with the feature enabled:
   ```bash
   # macOS/Linux
   RB_AGENT_ENABLE_CONSOLE=1 npm run start:agent
   # Windows (cmd)
   set RB_AGENT_ENABLE_CONSOLE=1 && npm run start:agent
   # Windows (PowerShell)
   $env:RB_AGENT_ENABLE_CONSOLE=1; npm run start:agent
   ```
2. On the Host tab, connect the agent as usual, then check **"Allow the connected
   viewer to open a remote console"** (separate from, and in addition to, the mouse/
   keyboard checkbox).
3. The viewer sees a "Remote console" panel once connected and can click **Start
   console** to get a shell. Everything the viewer runs is also mirrored, read-only,
   into the host's own page, so the person at the keyboard can always see what's
   being executed.
4. On Windows the agent looks for Git Bash (`bash.exe`) in the usual install
   locations and uses it automatically if present, otherwise it falls back to
   `cmd.exe`. You can force a specific shell with `RB_AGENT_SHELL=/path/to/shell`.

Closing the agent (Ctrl+C), unchecking the host's checkbox, or disconnecting the
viewer all stop the remote shell. Console output/input travels over its own reliable
WebRTC data channel, separate from the low-latency/unreliable one used for mouse
movement, so typed commands and their output can't be silently dropped.

## Login (required)

The app now requires a login before it does anything — registering a device,
starting a session, or scanning the network. Two accounts already exist in
`database/remote_bridge.sql`: **sadmin** (admin) and **sadmin1** (user), but
their passwords are unknown bcrypt hashes from the original dump. Set real
passwords for them before first use:

```bash
php -r "echo password_hash('choose-a-strong-password', PASSWORD_BCRYPT), PHP_EOL;"
```

Then update the database with the hash it prints:

```sql
UPDATE users SET password_hash = '<hash from above>' WHERE username = 'sadmin';
UPDATE users SET password_hash = '<hash from above>' WHERE username = 'sadmin1';
```

To add more accounts later, insert into `users` the same way (`role` is
`admin` or `user`).

**What logging in changes:**
- Every device/session/signaling endpoint now requires a logged-in user
  (`/api/register`, `/api/heartbeat`, `/api/session/*`, the native agent
  start/stop/output endpoints).
- Network discovery (`/api/network/devices`, `/api/network/users`,
  `/api/network/scan`) and `admin/settings.php` are restricted to the
  `admin` role, on top of the existing same-machine/same-LAN checks.
- Logins, logouts, and network scans are written to `audit_log`.
- Login attempts are rate-limited (8 tries per IP+username per 5 minutes).

This is a "quick fix" tier of auth — session-based, no device-ownership
enforcement beyond the `user_id` column, no MFA/password-reset flow. Treat
it as enough for a trusted LAN, not as production-grade for internet
exposure. See `review/RBAC_IMPLEMENTATION_GUIDE.md` and
`review/CODE_REVIEW_RBAC.md` for what a fuller implementation (per-device
ownership checks, MFA, password reset, dedicated session/rate-limit tables)
would add.

## Notes
- Runtime agent tokens and `node_modules` are intentionally not packaged.
- `.htaccess` blocks runtime secrets/configuration files and SQL/log/backup files from HTTP access.
- The application was syntax-checked with PHP and Node.js after these changes.
