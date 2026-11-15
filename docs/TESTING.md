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
| Server URL with port, Connect via, timeouts, certificate pin, redaction | `tests/Unit/ConnectionConfigTest.php` |
| AES-256-GCM encryption (round trip, context binding, tampering, wrong secret), secret source, rate limiter | `tests/Unit/SecurityTest.php` |
| Settings validation (every rule, secrets kept/removed/encrypted, no secrets in the form, data sources, no free-form queries, 0.1.x port merged into the URL) | `tests/Unit/SettingsValidatorTest.php` |
| Matching by the `employeeid` field (exact, never falls back to e-mail), by e-mail (single, ambiguous, none), matching off, one employee never mapped twice, suspension when the field or e-mail changes, recheck time, daily re-verification, administrator mapping by code, confirm / suspend / re-match | `tests/Unit/IdentityResolverTest.php` |
| Ownership and IDOR: only the mapped employee's code and the month are requested, rows of other employees hidden and logged; per-day schedules; status mapping; month summaries; nothing calculated without the daily source; exceptions; leave on/off; cache per user and employee; one failing part does not hide the others; ranges over two months; exports contain only the user's data (Excel and PDF); profile allow-list | `tests/Unit/AttendanceServiceTest.php` |
| Find Endpoints: OpenAPI and CoreJSON descriptions, list endpoints only, attendance first, the order the description forms are tried, parameter suggestions, field inspection without personal values, field and status suggestions that form a valid source, login paths refused | `tests/Unit/DiscoveryTest.php` |
| Every `COM_BIOTIME_` string used exists in the right language file (site, administrator, menu items); run-time keys; every error kind has a message naming ZKBioTime | `tests/Unit/LanguageTest.php` |

The GitHub workflow runs PHP lint and these tests on every pull request before building the package.

## 2. Browser checks on a real Joomla site

Two Playwright scripts drive a real Joomla 6 site. A small stand-in server,
`tests/e2e/fake-zkbiotime.php` (test fixture only, never deploy it), answers like the documented
API with invented fixture data.

```bash
php -S 127.0.0.1:18081 tests/e2e/fake-zkbiotime.php
```

- `tests/e2e/admin-settings.mjs`: Integration Settings (toolbar, validation, no password or
  ciphertext in the browser, Test Connection success / wrong password / unreachable, Connect via
  with the Host header kept, 390 px, the seven permissions, CSRF).

  ```bash
  BASE_URL=http://127.0.0.1:8090 ADMIN_PASS='…' FAKE_LOG=/path/to/fake.log OUT=tests/output node tests/e2e/admin-settings.mjs
  ```

- `tests/e2e/portal.mjs`: needs Registered users `sara` (custom field `employeeid` = `FIXTURE1`),
  `omar` (`omar@example.test`), `shared` (`shared@example.test`) and `nobody`, and the seven
  permissions allowed for Registered. Checks the data sources in the settings form, the six menu
  item types, all six pages and day details (status 200, translated, noindex, no other
  employee's data, schedule per day, late-in / early-out from ZKBioTime, exceptions, chart,
  profile allow-list), PDF and Excel downloads (only the user's data), CSRF refusal, IDOR
  attempts, 390 px without horizontal scrolling on every page, e-mail matching, the "could not be
  matched" page, the User Mappings list, duplicate refusal, manual mapping and Re-match.

  ```bash
  BASE_URL=http://127.0.0.1:8090 ADMIN_PASS='…' USER_PASS='…' OUT=tests/output node tests/e2e/portal.mjs
  ```

0.3.0 also checks Find Endpoints (read the list, select, check, no employee codes shown, save
as Daily Attendance Source) and a day with only a check-out punch (shown under Check-Out, with
*Missing check-in*): 88 portal checks passed on PostgreSQL, and the update from the published
0.2.0 on MariaDB.

0.2.0 passed both on Joomla 6.2.0 / PHP 8.3 with PostgreSQL 16 (fresh install; 74 portal checks)
and the install, update and uninstall checks on MariaDB 10.11 (update from the published 0.1.1:
schema 0.2.0, the separate port merged into the server URL, mapping table created).

## 3. Live checklist on the real ZKBioTime 9.5 server (administrator)

Run the items for the version being released, note the result, and send the notes (no
passwords, tokens or personal data) with the pull request.

### Before the first test
- [ ] Record the ZKBioTime **build number** (ZKBioTime *System → About*) in `docs/API-MAPPING.md`.
- [ ] Export `/api/docs/` and `/api/personnel_docs/` (save the pages as HTML or PDF).
- [ ] Create a ZKBioTime user for the portal with **read-only** permission for personnel and
      attendance.

### 0.1.0 – Connection and authentication
- [ ] Install `com_biotime.zip` on a Joomla 6.2 test site (*System → Install → Extensions*).
- [ ] *Components → ZKBioTime → Integration Settings*: enter URL, port, JWT, username, password; **Save**.
- [ ] Status shows *API password: Stored (encrypted)*; the password field is empty after saving.
- [ ] **Test Connection** → "Connected to ZKBioTime with JWT … The API user can read N employees."
      N should match the number of employees in ZKBioTime.
- [ ] Open `administrator/logs/com_biotime.php`:
  - [ ] the line *list read from "data" (as documented)* — or note which form was logged;
  - [ ] the line *token renewed after …* — note whether it says *default lifetime used*;
  - [ ] no password, token or employee data anywhere in the file.
- [ ] Wrong password → "did not accept the portal's credentials".
- [ ] Wrong port or host → "could not be reached" within the connection timeout.
- [ ] *(0.1.1)* Internal name: Server URL `https://zkb.mysite.com`, Connect via `api.zkb.mysite.com`
      (and a port if the public one differs) → Test Connection succeeds; Read Certificate Pin shows
      the certificate of `zkb.mysite.com`. With Connect via emptied, the same URL fails when the
      Joomla server cannot resolve the internal name.
- [ ] If the server uses https: **Read Certificate Pin**, compare with the pin computed on the
      server (see README), save it, **Test Connection** again.
- [ ] Switch to *General Auth Token* (username + password, no token) → Test Connection.
- [ ] If ZKBioTime can issue a token in its UI: enter only the token → Test Connection.
- [ ] Token expiry: wait past the time shown in the success message, Test Connection again (still works).
- [ ] Remove the API user's personnel permission in ZKBioTime → Test Connection shows a permission error. Restore it.
- [ ] Stop ZKBioTime (or block the port) → "could not be reached". Start it again.
- [ ] Joomla: update by installing the same package again (settings kept); uninstall on a test
      site (tables `#__biotime_settings` and `#__biotime_audit` removed).
- [ ] Send one sample JSON answer each for the JWT login (token replaced by `…`) and for
      `/personnel/api/employees/?page=1&page_size=1` (personal values replaced).

### 0.3.0 – Find Endpoints and punch states
- [ ] *Find Endpoints → Read the API list*: the list appears (or the message says none is readable; note which).
- [ ] Select the daily attendance (time card) endpoint, check it with a real employee code and one week: compare the field names and status texts with ZKBioTime, then save it.
- [ ] A day with only a check-out punch shows the time under Check-Out and *Missing check-in*.
- [ ] Record in `docs/API-MAPPING.md` which description form worked (from the log).

### 0.2.0 – Employee mapping and portal

Preparation
- [ ] Users → Fields: the `employeeid` field exists for users and **only administrators can edit it**.
- [ ] Options → Permissions: the seven portal permissions are *Allowed* for Registered.
- [ ] Menus: one menu item of each of the six types.
- [ ] Integration Settings → Daily Attendance Source: path, parameters and field names from
      `/api/docs/`; status names. Record the endpoint and field names in `docs/API-MAPPING.md`.
- [ ] Leave Source, if ZKBioTime offers leave in its API.

Employee matching
- [ ] A user with `employeeid` filled in is matched to that employee (User Mappings: Active, Automatic).
- [ ] A user without the field is matched by e-mail; a user whose e-mail two employees share is *Ambiguous*; an unknown e-mail is *Not found*. Both see the "could not be matched" page.
- [ ] Map an unmatched user by employee code (New); try to map a second user to the same employee (refused).
- [ ] Change a matched user's `employeeid` → the mapping is *Suspended*; Confirm restores it.
- [ ] Re-match deletes the mapping; the next visit matches again.

Attendance (compare every value with ZKBioTime for the same employee and month)
- [ ] My Attendance: check-in and check-out per day; all punches with device and area.
- [ ] Daily schedules: a month where the employee has **different shifts on different days**; each date shows its own schedule, start and end.
- [ ] Statuses: present, late, early, absent, leave, holiday, rest day as ZKBioTime calculates them.
- [ ] Late-in and early-out durations equal ZKBioTime's; worked hours and overtime too.
- [ ] Absences and days without punches are shown as ZKBioTime classifies them.
- [ ] Attendance Calendar: colours and labels; day details; keyboard (Tab/Enter) and a phone.
- [ ] My Schedules: month and week views; rest days and holidays.
- [ ] Attendance Reports: a month and a 93-day range; PDF and Excel contain the same values and only this employee.
- [ ] My Profile: the allowed fields only.

Security
- [ ] Logged in as employee A, change URLs (`month`, `date`, add `emp_code=`/`employee_id=` of employee B): only A's data is ever shown.
- [ ] A user without permissions gets "You are not authorised" (403); a guest is sent to login.
- [ ] `administrator/logs/com_biotime.php`: one line per page; no passwords, tokens, names or e-mail addresses.

API failure and token expiry
- [ ] Stop ZKBioTime: pages show "ZKBioTime could not be reached", the rest of the page still works; start it again.
- [ ] Wait past the token lifetime: pages keep working (token renewed once).
- [ ] Cache time 0 and 5: "Updated hh:mm" changes as expected; a month loads in a few seconds with a warm cache.

Joomla
- [ ] Update from 0.1.x: settings kept; a separate port is now part of the Server URL.
- [ ] Uninstall on a test site: `#__biotime_settings`, `#__biotime_audit` and `#__biotime_usermap` removed.
