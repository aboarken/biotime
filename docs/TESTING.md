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
| Ownership and IDOR: only the mapped employee's code and the month are requested, rows of other employees hidden and logged; day statuses from the punches (present with or without a missing punch, checked in, absent, weekend; check-in before 12:00 and check-out from 12:00; punches outside the month asked for are skipped; My Punches counts by fingerprint / face, month and device; a weekend day with punches loses the weekend tag; configurable weekend days); month summaries (present / absent / missing-punch days, rate, average check-in and check-out, time in–out); punch details (verify type, device, serial, area); calculated values when a daily source is set; exceptions; cache per user and employee; one failing part does not hide the others; ranges over two months | `tests/Unit/AttendanceServiceTest.php` |
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
  with the Host header kept, 390 px, the four permissions, CSRF).

  ```bash
  BASE_URL=http://127.0.0.1:8090 ADMIN_PASS='…' FAKE_LOG=/path/to/fake.log OUT=tests/output node tests/e2e/admin-settings.mjs
  ```

- `tests/e2e/portal.mjs`: needs Registered users `sara` (custom field `employeeid` = `FIXTURE1`),
  `omar` (`omar@example.test`), `shared` (`shared@example.test`) and `nobody`, and the four
  permissions allowed for Registered. Checks the data sources in the settings form, the four menu
  item types (and that My Schedules, Attendance Reports and My Profile are gone), all four pages
  and day details (status 200, translated, noindex, no other employee's data, check-in and
  check-out with the device, present / missing punch / absent / weekend, no fields the 9.5 API does
  not provide, exceptions, chart, today card), My Punches (fingerprint, face, per month and per device;
  year lists from 2025), the date filter typed as DD/MM/YYYY, the removed pages answering 404, IDOR attempts,
  390 px without horizontal scrolling on every page, e-mail matching, the "could not be matched"
  page, the User Mappings list, duplicate refusal, manual mapping and Re-match.

  ```bash
  BASE_URL=http://127.0.0.1:8090 ADMIN_PASS='…' USER_PASS='…' OUT=tests/output node tests/e2e/portal.mjs
  ```

The stand-in server answers the API description like the real one (no OpenAPI; CoreJSON only
with `application/coreapi+json`, else 406; unknown paths a web page with HTTP 200).

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
      site (tables `#__biotime_settings`, `#__biotime_audit`, `#__biotime_usermap` and `#__biotime_cache` removed).
- [ ] Send one sample JSON answer each for the JWT login (token replaced by `…`) and for
      `/personnel/api/employees/?page=1&page_size=1` (personal values replaced).

### 0.4.9 – Styles scoped to the portal content
- [ ] With a template that adds `com-biotime` to `<body>` (e.g. Helix Ultimate): the site header, menus, modules and their pagination look the same on portal pages as on other pages; the portal pages look as before.
- [ ] Every portal page (and the "could not be matched" page) has `data-bt-root` on its content (browser inspector).

### 0.4.7 – Security and clean-up
- [ ] Log in to the administrator as a user with *Access Administration Interface* on the component but not Super User: `index.php?option=com_biotime&task=usermappings.display` answers 403.
- [ ] Users → Fields → employeeid: set *Editable In* to *Site and administrator* and allow Registered users to edit its value: an unmapped user's next visit is *not found* and the log says the field is editable. Restore the setting.
- [ ] Integration Settings: change the Server URL without typing the password → "type the password again". Type it → saved.
- [ ] My Attendance: `&status=checked_in` shows all days (no error); `&month=` a future month shows the current month; the → arrow is hidden on the current month.

### 0.4.6 – Speed
- [ ] After the update, the table `#__biotime_cache` exists (*System → Information → Database* or phpMyAdmin) and fills while the portal is used.
- [ ] `administrator/logs/com_biotime.php`: opening My Dashboard twice within 5 minutes reads ZKBioTime only once ("cached" on the second page line); a previous month opened again later the same day says "cached".
- [ ] Log out and in again: the pages are still "cached".
- [ ] Block ZKBioTime briefly after the daily check is due: one warning "Could not re-verify … trying again in 60 minutes", then pages are fast again (no new attempt for an hour).
- [ ] New punches appear within the Cache time.

### 0.4.5 – Date picker months, no "Unknown"
- [ ] My Attendance → Date: open the calendar, click ← to go to the previous month: it stays open; choose a day there and the page shows that day.
- [ ] With October selected in the Month list, the date calendar opens on October.
- [ ] Punch details (My Attendance and day details) never show "Unknown".

### 0.4.4 – Clean-up
- [ ] After the update: *Integration Settings* has no Leave Source section and no Leave information switch; Find Endpoints offers only *Save as Daily Attendance Source*.
- [ ] My Dashboard → Recent Activity: a **Note** column shows *! Missing Punch* for days with a missing check-in or check-out.

### 0.4.3 – Check-in before 12:00, check-out from 12:00, date picker
- [ ] A day whose only punch is in the afternoon shows it under **Check-Out** (check-in *Missing*); a morning punch recorded as "Check Out" shows under **Check-In**.
- [ ] Today shows Checked In (↘) after the morning punch and Checked Out (↗) after an afternoon punch; both are in the calendar legend.
- [ ] My Attendance → Date: clicking opens a calendar; choosing a day shows DD/MM/YYYY and filters at once; the field cannot be typed into.
- [ ] The browser tab reads "My Attendance - Site name" (or as set in Site Name in Page Titles), never with "|".
- [ ] Dashboard: Devices You Used has Fingerprint, Face, Total and a total row; Recent Activity has a Day column.

### 0.4.2 – Missing Punch, My Punches, DD/MM/YYYY
- [ ] After the update: a Biometric Devices menu item now opens **My Punches**; *Options → Permissions* shows *View My Punches* with the same groups allowed.
- [ ] My Attendance: a day with one punch shows that time under Check-In (or Check-Out for a check-out punch), *Missing* under the other, and **Present** plus **Missing Punch**. No *Incomplete* and no *Leave* anywhere.
- [ ] My Dashboard: 10 cards (6 then 4), all the same size; Monthly Overview counts that day under Present and under Missing Punch; Devices You Used lists every device used in the month.
- [ ] My Attendance: 8 cards (5 then 3), all the same size; the date filter takes 05/11/2026 as 5 November.
- [ ] Year lists start at 2025; dates show as DD/MM/YYYY.
- [ ] My Punches: compare a month's fingerprint and face counts with ZKBioTime's transactions for the same employee.

### 0.3.0 – Find Endpoints and punch states
- [ ] *Find Endpoints → Read the API list*: the list appears (or the message says none is readable; note which).
- [ ] Select the daily attendance (time card) endpoint, check it with a real employee code and one week: compare the field names and status texts with ZKBioTime, then save it.
- [ ] A day with only a check-out punch shows the time under Check-Out and *Missing check-in*.
- [ ] Record in `docs/API-MAPPING.md` which description form worked (from the log).

### 0.2.0 – Employee mapping and portal

Preparation
- [ ] Users → Fields: the `employeeid` field exists for users and **only administrators can edit it**.
- [ ] Options → Permissions: the four portal permissions are *Allowed* for Registered.
- [ ] Menus: one menu item of each of the four types.
- [ ] Integration Settings → Daily Attendance Source (only if ZKBioTime offers calculated
      attendance): path, parameters and field names from `/api/docs/`.

Employee matching
- [ ] A user with `employeeid` filled in is matched to that employee (User Mappings: Active, Automatic).
- [ ] A user without the field is matched by e-mail; a user whose e-mail two employees share is *Ambiguous*; an unknown e-mail is *Not found*. Both see the "could not be matched" page.
- [ ] Map an unmatched user by employee code (New); try to map a second user to the same employee (refused).
- [ ] Change a matched user's `employeeid` → the mapping is *Suspended*; Confirm restores it.
- [ ] Re-match deletes the mapping; the next visit matches again.

Attendance (compare every value with ZKBioTime for the same employee and month)
- [ ] My Attendance: check-in and check-out per day; all punches with device and area.
- [ ] Attendance Calendar: colours and labels; day details; keyboard (Tab/Enter) and a phone.

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
