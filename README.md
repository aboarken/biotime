# Joomla ZKBioTime (`com_zkbiotime`)

**A read-only employee self-service attendance portal for ZKTeco ZKBioTime 9.5, inside Joomla 6.**

ZKBioTime is the single source of truth for employees and attendance. Joomla only reads and
displays; it never creates, edits, corrects or recalculates attendance data. When ZKBioTime cannot
provide a value, the portal shows "—" and says so instead of guessing.

## Status: Stage 1 of 6 (version 0.1.0)

The component is built in small stages. Each one is tested, released, and checked by the
administrator on the real ZKBioTime server before the next stage starts.

| Stage | Version | Content | Status |
|---|---|---|---|
| 1 | 0.1.0 | Connection and authentication: Integration Settings, encrypted credentials, Test Connection, API client, install/update/release process | **this version** |
| 2 | 0.2.0 | Employee mapping: `#__zkbiotime_usermap`, automatic e-mail matching, User Mappings page, "account could not be matched" page | next |
| 3 | 0.3.0 | Transactions: **My Attendance** with check-in/check-out and all punches | planned |
| 4 | 0.4.0 | Schedules: per-day schedule, **My Schedules**, **Attendance Calendar** | planned |
| 5 | 0.5.0 | Calculated attendance: statuses, late-in, early-out, worked hours, **My Dashboard** | planned |
| 6 | 0.6.0 | **Attendance Reports**, PDF/Excel exports, **My Profile** | planned |

Which ZKBioTime endpoint supplies each value, and whether it is verified on a real server, is in
[docs/API-MAPPING.md](docs/API-MAPPING.md). In 0.1.0 every endpoint is still **UNVERIFIED**.

## Requirements

| Requirement | Version |
|---|---|
| Joomla | 6.2 or later |
| PHP | 8.3 or later, with OpenSSL (AES-256-GCM) and cURL |
| Database | MySQL / MariaDB or PostgreSQL |
| ZKBioTime | 9.5, REST API reachable from the Joomla web server |

The installer refuses to install with a clear message when PHP, Joomla or OpenSSL is too old.

## Installation

1. Download `com_zkbiotime.zip` from the latest release on the
   [Releases page](https://github.com/aboarken/com_biotime/releases).
2. Joomla Administrator → **System → Install → Extensions** → upload the package.
3. Open **Components → ZKBioTime → Integration Settings**.

Updates appear under **System → Update → Extensions** (update site "ZKBioTime"). Installing a
newer package over the existing one also works; settings are kept.

## Configuration

### ZKBioTime API user

Create a ZKBioTime user for the portal with **read-only** permission for personnel and
attendance. The portal reads data for all employees through this user and enforces, on the
Joomla server, that each employee only sees their own records.

### Integration Settings

| Section | Setting | Notes |
|---|---|---|
| Connection | Server URL, Port | e.g. `https://biotime.example.com` and `8081`. A warning is shown for plain `http`. |
| | Connection timeout, Total timeout | seconds; connection ≤ total |
| Credentials | Authentication method | **JWT** (username + password exchanged for a short-lived token, renewed automatically) or **General Auth Token** (a token entered here, or obtained with username + password) |
| | API username, API password, General Auth Token | Password and token are encrypted (AES-256-GCM) before they are stored and never shown again: the page only says *Stored (encrypted)* or *Not set*. |
| TLS | Verify TLS certificate | on by default |
| | CA bundle file | PEM file on the web server for a company CA |
| | Certificate pin (SPKI) | identifies the server by its public key (works with an IP address or a self-signed certificate) |
| System | Detailed logging | see *Troubleshooting* |
| | API page size, Safety maximum (pages) | default 100 rows × 50 pages; pages say when results were cut off |

Settings are stored in the component's own table `#__zkbiotime_settings`, not in Joomla's
component options, because Joomla's Options page replaces every option on save and the secrets
must be encrypted first. *Options* holds only the permissions.

**Encryption secret.** Secrets are encrypted with a key derived from the environment variable
`ZKBIOTIME_PORTAL_SECRET` (recommended, at least 32 random characters) or, when that is not set,
from the `secret` in Joomla's `configuration.php`. The page shows which one is in use. If it
changes, enter the API password (or token) again.

**Certificate pin.** *Read Certificate Pin* shows the key the server presents now. Compare it
with the value computed on the ZKBioTime server before saving it:

```bash
openssl x509 -in server.crt -pubkey -noout | openssl pkey -pubin -outform der | openssl dgst -sha256 -binary | base64
```

The pin is `sha256//` followed by that output.

**Test Connection** logs in with the saved settings and reads one row of the employee list. It
reports the authentication method, how many employees the API user can read, and when the token
is renewed; on failure it names the problem and what to check. It is limited to 20 tests per
administrator per hour.

### Permissions

All administrator pages need **core.admin** (Super User level) on the component.

The portal pages arriving in later stages each have their own permission. **None is granted by
default.** Under **Components → ZKBioTime → Options → Permissions**, set them to *Allowed* for
the **Registered** group (or the group your employees are in); without them, users get
"You are not authorised" (HTTP 403).

| Permission | Page |
|---|---|
| `zkb.dashboard.view` | My Dashboard |
| `zkb.attendance.view` | My Attendance |
| `zkb.calendar.view` | Attendance Calendar |
| `zkb.schedule.view` | My Schedules |
| `zkb.report.view` | Attendance Reports |
| `zkb.report.export` | PDF and Excel exports |
| `zkb.profile.view` | My Profile |

## Security

- Credentials and tokens never reach the browser (no JavaScript, HTML, session or cookie). The
  API token is cached server-side, encrypted, and renewed shortly before it expires; after an
  "unauthorised" answer it is renewed once, then the error is reported.
- Every state-changing action is a POST with a CSRF token and a permission check; settings
  changes and connection tests are recorded in `#__zkbiotime_audit`.
- Ids and employee codes are checked against strict patterns before they go into an API path
  or query.
- The integration is read-only: the only POST sent to ZKBioTime is the login.

## Troubleshooting

`administrator/logs/com_zkbiotime.php` records each API call (method, path, query parameter
names, HTTP status, time) and every problem in plain words. Passwords, tokens, headers and
personal data are never written to it. With *Detailed logging* off, only warnings and errors
are written.

| Message | Check |
|---|---|
| could not be reached | URL, port, firewall/VPN between the web server and ZKBioTime, timeouts, TLS settings; the log has the cURL reason |
| did not accept the portal's credentials | username, password or token, authentication method |
| did not allow the portal to read this information | the API user's permissions in ZKBioTime |
| could not find what was asked for | server URL must not contain an extra path; record the ZKBioTime build number |
| sent an answer the portal could not read | the URL may point to a proxy or login page; the log lists the keys received |

## Development

- Every change goes through a pull request; the maintainer merges.
- Automated tests: `composer install` then `vendor/bin/phpunit` — see [docs/TESTING.md](docs/TESTING.md),
  which also has the live checklist for the real server.
- Each version: bump `<version>` in `zkbiotime.xml` and `media/joomla.asset.json`, add
  `administrator/sql/updates/{mysql,postgresql}/<version>.sql` (a comment when nothing changes),
  add a `CHANGELOG.md` entry and `docs/release-notes/<version>.md`.

### Releases

The **Release package** workflow (`.github/workflows/release.yml`) lints PHP, runs the tests,
builds and checks `com_zkbiotime.zip`, and writes `updates.xml` (with SHA-256, target platform
Joomla 6 and minimum PHP 8.3) and `changelog.xml` (from `CHANGELOG.md`). On every pull request
they are kept as a workflow artifact. To publish: *Actions → Release package → Run workflow*
on `main` creates release `v<version>` with the three files and the release notes. Joomla sites
read `releases/latest/download/updates.xml` and `…/changelog.xml`.

## License

GNU General Public License version 2 or later. See [LICENSE](LICENSE).
