# RemoteBridge

## XAMPP
1. Extract this folder to `C:\xampp\htdocs\A-RemoteVanced`.
2. Create/import the `remote_bridge` database. The included `database\remote_bridge.sql` is schema-only; alternatively run `php database\migrate.php`.
3. Confirm `config.php` matches your MySQL/MariaDB credentials.
4. Open `http://localhost/A-RemoteVanced/`.

## Node control agent (optional, Windows)
From the project root:
```bash
npm install
npm run start:agent
```
The agent is optional and is only needed for native mouse/keyboard control.

## Notes
- Runtime agent tokens and `node_modules` are intentionally not packaged.
- `.htaccess` blocks runtime secrets/configuration files and SQL/log/backup files from HTTP access.
- The application was syntax-checked with PHP and Node.js after repair.
