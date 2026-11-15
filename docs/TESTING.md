# Testing

## 1. Automated tests (no ZKBioTime server needed)

ZKBioTime is replaced by a fake transport (`tests/Support/FakeTransport.php`), so the tests run
anywhere, including CI.

```bash
composer install
```

```bash
vendor/bin/phpunit
```

| Area | Test file |
|---|---|
| JWT and General Auth Token login, header format, token caching, renewal before expiry, one renewal after "unauthorised", static token, wrong credentials, no secrets in logs or dumps, connection test | `tests/Unit/ZkbClientAuthTest.php` |
| Error mapping for every kind, GET retry on network and server errors, no retry for login, paging (`next`, `count`, safety maximum, max items, `results` and bare-list fallbacks), path/query/id/emp_code validation, log lines without personal data | `tests/Unit/ZkbClientRequestTest.php` |
| URL and port, timeouts, certificate pin, redaction | `tests/Unit/ConnectionConfigTest.php` |
| AES-256-GCM encryption (round trip, context binding, tampering, wrong secret), secret source, rate limiter | `tests/Unit/SecurityTest.php` |
| Settings validation (every rule, secrets kept/removed/encrypted, no secrets in the form) | `tests/Unit/SettingsValidatorTest.php` |
| Every `COM_ZKBIOTIME_` string used exists; every error kind has a message naming ZKBioTime and a hint | `tests/Unit/LanguageTest.php` |

Tests for employee matching, ownership/IDOR, per-day schedules, status mapping, month summaries,
exports and the per-user cache are added with the stages that bring those features.

The GitHub workflow runs PHP lint and these tests on every pull request before building the package.

## 2. Browser check of the administrator pages

`tests/e2e/admin-settings.mjs` drives a real Joomla 6 site with Playwright. A small stand-in
server, `tests/e2e/fake-zkbiotime.php` (test fixture only, never deploy it), answers like the
documented API. It checks: no untranslated strings, the toolbar (Save, Test Connection, Read
Certificate Pin, Close, Options), validation messages, that no password or ciphertext reaches
the browser, a successful test, a wrong password, an unreachable server, no horizontal scrolling
at 390 px, the seven permissions, and that a task without a CSRF token is refused.

```bash
php -S 127.0.0.1:18081 tests/e2e/fake-zkbiotime.php
```

```bash
BASE_URL=http://127.0.0.1:8090 ADMIN_USER=admin ADMIN_PASS='…' OUT=tests/output node tests/e2e/admin-settings.mjs
```

For 0.1.0 this passed on Joomla 6.2.0 / PHP 8.3 with PostgreSQL 16 and with MariaDB 10.11:
install, install over itself (update), and uninstall (tables and files removed).

## 3. Live checklist on the real ZKBioTime 9.5 server (administrator)

Run the items for the version being released, note the result, and send the notes (no
passwords, tokens or personal data) with the pull request.

### Before the first test
- [ ] Record the ZKBioTime **build number** (ZKBioTime *System → About*) in `docs/API-MAPPING.md`.
- [ ] Export `/api/docs/` and `/api/personnel_docs/` (save the pages as HTML or PDF).
- [ ] Create a ZKBioTime user for the portal with **read-only** permission for personnel and
      attendance.

### 0.1.0 – Connection and authentication
- [ ] Install `com_zkbiotime.zip` on a Joomla 6.2 test site (*System → Install → Extensions*).
- [ ] *Components → ZKBioTime → Integration Settings*: enter URL, port, JWT, username, password; **Save**.
- [ ] Status shows *API password: Stored (encrypted)*; the password field is empty after saving.
- [ ] **Test Connection** → "Connected to ZKBioTime with JWT … The API user can read N employees."
      N should match the number of employees in ZKBioTime.
- [ ] Open `administrator/logs/com_zkbiotime.php`:
  - [ ] the line *list read from "data" (as documented)* — or note which form was logged;
  - [ ] the line *token renewed after …* — note whether it says *default lifetime used*;
  - [ ] no password, token or employee data anywhere in the file.
- [ ] Wrong password → "did not accept the portal's credentials".
- [ ] Wrong port or host → "could not be reached" within the connection timeout.
- [ ] If the server uses https: **Read Certificate Pin**, compare with the pin computed on the
      server (see README), save it, **Test Connection** again.
- [ ] Switch to *General Auth Token* (username + password, no token) → Test Connection.
- [ ] If ZKBioTime can issue a token in its UI: enter only the token → Test Connection.
- [ ] Token expiry: wait past the time shown in the success message, Test Connection again (still works).
- [ ] Remove the API user's personnel permission in ZKBioTime → Test Connection shows a permission error. Restore it.
- [ ] Stop ZKBioTime (or block the port) → "could not be reached". Start it again.
- [ ] Joomla: update by installing the same package again (settings kept); uninstall on a test
      site (tables `#__zkbiotime_settings` and `#__zkbiotime_audit` removed).
- [ ] Send one sample JSON answer each for the JWT login (token replaced by `…`) and for
      `/personnel/api/employees/?page=1&page_size=1` (personal values replaced).

### Later stages (filled in as they are built)
- Employee matching (single, ambiguous, none; manual mapping by emp_code; duplicates refused)
- Transactions; month check-in and check-out
- Daily schedules (different shifts on different days)
- Statuses; late-in and early-out; absences
- Reports and exports (only the user's own data)
- Security: one employee trying to open another's data by changing the URL
- API failure and token expiry during normal use
