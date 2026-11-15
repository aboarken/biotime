# Changelog

All notable changes to `com_biotime` are documented in this file.
Versions follow the component manifest (`biotime.xml`) and use [Semantic Versioning](https://semver.org/).

## [0.2.0] - 2026-11-15

### Added
- **Six portal pages** (menu item types under *Menus → Add New Menu Item → ZKBioTime*): **My Dashboard** (summary cards, Monthly Overview chart with the same figures as a table, Recent Activity), **My Attendance** (one row per day with the schedule applied on that date, scheduled start and end, check-in, check-out, late-in, early-out, worked hours, overtime, status, all punches and exceptions; month, status and date filters), **Attendance Calendar** (status as colour and text; keyboard and phone friendly), **My Schedules** (month and week), **Attendance Reports** (a month or a range of up to 93 days; late-in, early-out, absence, overtime, leave and exception reports; **PDF and Excel downloads**) and **My Profile** (read only, allow-listed fields). Day details open from these pages.
- **Employee mapping** (`#__biotime_usermap`): users are matched by the Joomla custom user field **employeeid** (= ZKBioTime emp_code) first, then by exact e-mail; one employee is never mapped to two users; a changed field or e-mail suspends the mapping. Unmatched users see a friendly "could not be matched" page.
- **User Mappings** page: search, status and source filters, *No mapping yet* report, New (map by employee code), Confirm, Suspend, Re-match; every change audited.
- **Daily Attendance Source** and **Leave Source** in Integration Settings: the administrator chooses the read-only ZKBioTime endpoint (or saved report) for calculated attendance, per-day schedules and leave, with parameter and field names and status names. No queries or SQL can be entered. Without it, those values show "—"; nothing is calculated by Joomla.
- Modules (charts, exports, leave), cache time per user (default 5 minutes, "Updated hh:mm" on pages), date and time formats, ZKBioTime server time zone (times are never shifted), longest report range and exports per user and hour.
- Ownership checks on every read: rows of other employees are dropped and logged; URL values never choose the employee.
- One log line per page load saying what was read, hidden or skipped.
- Bundled TCPDF (LGPL-3.0-or-later, DejaVu Sans with Arabic) for PDF exports.

### Changed
- **Server URL and port are one field**, e.g. `https://zkb.example.com:5555`. A port saved separately by 0.1.x is merged into the URL on update.

## [0.1.1] - 2026-11-15

### Added
- **Connect via** (*Integration Settings → Connection*), as in the ServiceDesk Plus component: for a ZKBioTime server whose own name (e.g. `zkb.example.com`, the name on its certificate) is only known inside the company network, while Joomla reaches it through a public address (e.g. `api.zkb.example.com`). Joomla opens the connection to the *Connect via* address (host, host:port or IP:port) but keeps the server URL's name for the certificate check and the Host header, so the existing certificate stays valid. *Test Connection* and *Read Certificate Pin* use the same path.

### Changed
- The source moved to the GitHub repository `aboarken/biotime`. Joomla now checks `https://github.com/aboarken/biotime/releases/latest/download/updates.xml` for updates and reads the changelog from the same release. The extension is still `com_biotime`.

## [0.1.0] - 2026-11-15

### Added
- **Stage 1: connection and authentication.** First version, for checking the connection to the real ZKBioTime 9.5 server. There are no portal pages yet.
- **Integration Settings** (*Components → ZKBioTime*): server URL and port, connection and total timeouts, JWT or General Auth Token, API username and password, optional token, TLS verification, CA bundle, certificate pin, detailed logging, API page size and safety maximum. Every value is validated on save with a clear message.
- **Encrypted credentials:** the API password and token are encrypted with AES-256-GCM (key from `BIOTIME_PORTAL_SECRET`, or Joomla's configuration secret) and never shown again; the page says *Stored (encrypted)* or *Not set* and which secret is in use.
- **Test Connection** logs in and reads one employee row: it reports the method, how many employees the API user can read and when the token is renewed, or names the problem and what to check. **Read Certificate Pin** shows the server's key for pinning. **Close** returns to the administrator dashboard and turns red on hover.
- **API client**, the only code that talks to ZKBioTime: token cached server-side and renewed before it expires, one renewal after "unauthorised", paging with a safety maximum, one retry of reads after network or server errors, nine error kinds with friendly messages naming ZKBioTime, strict id checks, and a log without credentials, tokens or personal data (`administrator/logs/com_biotime.php`).
- **Permissions** for the six portal pages and exports (not granted by default); administrator pages need *core.admin*.
- MySQL/MariaDB and PostgreSQL support; install script checks PHP 8.3, Joomla 6.0 and OpenSSL; update server and changelog for Joomla.
- `docs/API-MAPPING.md` (every endpoint, still UNVERIFIED) and `docs/TESTING.md` (automated tests and the live checklist).
