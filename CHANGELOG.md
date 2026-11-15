# Changelog

All notable changes to `com_biotime` are documented in this file.
Versions follow the component manifest (`biotime.xml`) and use [Semantic Versioning](https://semver.org/).

## [0.1.1] - 2026-11-15

### Added
- **Connect via** (*Integration Settings → Connection*), as in the ServiceDesk Plus component: for a ZKBioTime server whose own name (e.g. `zkb.example.com`, the name on its certificate) is only known inside the company network, while Joomla reaches it through a public address (e.g. `api.zkb.example.com`). Joomla opens the connection to the *Connect via* address (host, host:port or IP:port) but keeps the server URL's name for the certificate check and the Host header, so the existing certificate stays valid. *Test Connection* and *Read Certificate Pin* use the same path.

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
