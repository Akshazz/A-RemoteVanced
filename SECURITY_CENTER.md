# RemoteBridge Advanced Security Center

This version is integrated directly into the existing RemoteBridge project.

## Open

After installing the project under XAMPP:

- `http://localhost/A-RemoteVanced/admin/security.php`

The main RemoteBridge Menu and Admin Settings page now include a **Security Center** link.

## Reused existing data

The Security Center reads the existing:

- `users`
- `devices`
- `audit_log`

It adds only security-specific tables:

- `security_scopes`
- `security_scans`
- `security_findings`
- `security_scan_ports`

The tables are created automatically when an administrator first opens the Security Center.

## Capabilities

- RemoteBridge device inventory
- Online/offline status from the existing `devices` table
- Authorized security scope management
- Bounded TCP service checks
- HTTP security-header checks
- TLS certificate expiry checks
- Severity-ranked findings
- Finding remediation text
- Resolve findings
- Security scan history
- Security actions written to the existing `audit_log`

## Safety

The scanner is intentionally defensive. It does not implement exploit delivery, credential attacks, password cracking, persistence, stealth/evasion, shell execution, payload deployment, DoS/DDoS, or data exfiltration.

Only scan systems you own or are explicitly authorized to assess.
