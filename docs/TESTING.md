# Testing

## 1. Automated tests (no ZKBioTime server needed)

ZKBioTime's database is replaced by `tests/Support/CatalogueQuery.php` (answers per SQL
fragment), so the tests run anywhere, including CI.

```bash
composer install
```

```bash
vendor/bin/phpunit
```

With a PostgreSQL loaded with `tests/Support/sql/zkbiotime-pgsql.sql` and its read-only user, the
`testRealPostgres` tests also run against it:

```bash
BIOTIME_TEST_PG='host:port:database:user:password' vendor/bin/phpunit
```

| Area | Test file |
|---|---|
| AES-256-GCM encryption (round trip, context binding, tampering, wrong secret), secret source, rate limiter | `tests/Unit/SecurityTest.php` |
| Settings validation (every rule, the database password kept/removed/encrypted and never kept for another server, no secrets in the form, no API settings left) | `tests/Unit/SettingsValidatorTest.php` |
| Database connection settings, read-only connection, Test Database report (names only) | `tests/Unit/DatabaseTest.php` |
| Matching by the `employeeid` field (exact, never falls back to e-mail), by e-mail (single, ambiguous, none), matching off, one employee never mapped twice, suspension when the field or e-mail changes, recheck time, daily re-verification (an unreachable database keeps the mapping and retries after an hour), administrator mapping by code, confirm / suspend / re-match | `tests/Unit/IdentityResolverTest.php`, `tests/Unit/DbEmployeeDirectoryTest.php` |
| Ownership and IDOR: only the mapped employee and the month are queried, punches of other employees hidden and logged; ZKBioTime's schedule and calculation per day (late-in, early-out, worked time, overtime, holidays, days off); day statuses from the punches (present with or without a missing punch, checked in, checked out, absent, weekend; check-in before the timetable's cut-off or 12:00); My Punches counts by fingerprint / face, month and device; configurable weekend days; month summaries; punch details; exceptions; cache per user and employee, past months kept a day; one failing part does not hide the others; database errors shown as portal messages; ranges over two months | `tests/Unit/AttendanceServiceTest.php` |
| The reader's queries (one employee, read only), units, time cards and pay codes of ZKBioTime 9.5, schedules from shifts and temporary schedules, holidays, devices | `tests/Unit/AttendanceReaderTest.php` |
| Every `COM_BIOTIME_` string used exists in the right language file (site, administrator, menu items); run-time keys; every error kind has a portal message naming ZKBioTime | `tests/Unit/LanguageTest.php` |

The GitHub workflow runs PHP lint and these tests on every pull request before building the package.

## 2. Browser checks on a real Joomla site

The Playwright scripts of earlier versions drove the portal against a stand-in for the REST API;
they were removed with the API in 0.11.0. Until scripts against the PostgreSQL fixture exist, use
the checklist below on a test site whose Integration Settings point to a PostgreSQL loaded with
`tests/Support/sql/zkbiotime-pgsql.sql`.

## 3. Live checklist on the real ZKBioTime 9.5 server (administrator)

Run the items for the version being released, note the result, and send the notes (no
passwords, tokens or personal data) with the pull request.

Items of versions before 0.11.0 that mention the API, Test Connection, Find Endpoints or the
Daily Attendance Source describe features removed in 0.11.0; they are kept as the record of
those releases.

### Before the first test
- [ ] Record the ZKBioTime **build number** (ZKBioTime *System → About*) in `docs/DATABASE.md`.
- [ ] Create the read-only database user for the portal (`docs/DATABASE.md`).

### 0.11.0 – Database only
- [ ] After the update from 0.10.0: Integration Settings has no Connection, Credentials, TLS or
      Daily Attendance Source sections, no Read attendance from, no Test Connection, Read
      Certificate Pin or Find Endpoints buttons; the Find Endpoints submenu is gone and
      `index.php?option=com_biotime&view=endpoints` answers 404.
- [ ] The database settings, timeouts and the stored database password are kept; Test Database
      still connects. `#__biotime_settings` has no `server_url`, `api_*`, `token_cache_*`,
      `daily_*` or `data_source` rows any more.
- [ ] The portal pages look as in 0.10.0 with the database (schedule, late-in, early-out, worked
      time, holidays); existing mappings still show the same employee.
- [ ] With the database engine set to Off, the portal pages say the connection is not set up yet.
- [ ] Stop the database (or block its port): the pages say ZKBioTime could not be reached; mapped
      users keep their mapping.


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

### 0.10.0 – Statistics, employee row, Attendance Management System
- [ ] My Dashboard has no Recent Activity; Statistics shows 5 charts (database): Days so far, Days per month, Late in and early out per month, Average check-in and check-out per month (floating columns, no lines), and the circles (one ring per figure, "of N working days" below), with the same totals as the cards. Hovering a bar, slice or point shows its value.
- [ ] With the API: 4 charts in 2 rows (no late/early chart).
- [ ] Under the heading, a table row: Department, Position, Employee ID, Name, Period "From 01/08/2026 to <today>". On a phone it shows one line per column.
- [ ] The note under the pages reads "Your schedule, late in, early out ... counts as a check-out." and "Weekend days: Friday, Saturday. They show as Weekend unless you punched that day."
- [ ] The note under My Dashboard and My Attendance reads "… come from the Attendance Management System; late-in and early-out appear once the system has calculated the day …". No portal page shows "ZKBioTime".
- [ ] My Attendance with From 01/08 and To today lists every day on one page (no page links).

### 0.9.0 – School year dashboard, From – To
- [ ] My Dashboard has no month arrows or month/year selection; under the heading: department · position · employee ID · name, then "From 01/08/2026 to <today>". Before August, the range starts on 1 August of last year.
- [ ] No Monthly Overview and no Your Devices panel on My Dashboard.
- [ ] Cards are 5 per row (database: 3 full rows) and include Holidays and Weekend Days Worked; the totals cover 1 August to today and match My Attendance for the same From – To.
- [ ] Clicking a card (e.g. Days Absent) opens My Attendance with From 01/08 – To today and that status.
- [ ] My Attendance: From and To are on a second line below Month, Year and Status. Choosing From alone shows that date to today; From + To shows that range (the table title shows the dates); choosing a Month clears both.

### 0.8.0 – Your Devices, Monthly Overview
- [ ] My Dashboard → Your Devices lists every device you have punched on or are registered on; devices not used this month show 0.
- [ ] Monthly Overview shows Present, Absent, Weekend, Holiday and Weekend Days Worked only; the bar has no orange or pink part.
- [ ] A day with only a check-in (or check-out) shows Present without a Missing Punch tag in My Attendance, the day details and the calendar (no "!", no legend entry); *Missing* still shows in the empty column.
- [ ] My Attendance has no Punches column; clicking a date opens the day details with its punches.

### 0.7.0 – Past months after an update, device names
- [ ] Right after updating, a past month (e.g. October) shows Late In and Early Out (not the copy cached by the previous version).
- [ ] Device names show without "Biometric - " everywhere (My Attendance punch details, day details, Devices You Used, My Punches).

### 0.6.2 – Schedules from shifts, cut-off from the timetable
- [ ] Today and the rest of the month show each day's schedule; it matches the employee's shift in ZKBioTime (Test Database → Shifts lists the day numbers). A temporary schedule for a date wins.
- [ ] A morning punch after the timetable's End Check-In counts as a check-out; on a day without a timetable the cut-off is 12:00.
- [ ] Late In and Early Out appear only on days ZKBioTime has calculated and equal its Time Card values.
- [ ] Weekend rows are blue; holiday rows sky blue.

### 0.6.1 – Database only
- [ ] With Read attendance from: Database, empty the API Server URL and save: accepted. A user with no mapping is matched by e-mail or the employee code field; User Mappings → map by code finds the employee by name.
- [ ] Existing mappings still show the same employee.

### 0.6.0 – Pages from the database
- [ ] Integration Settings → Database → Read attendance from: Database, save. My Dashboard shows the department and position, today's schedule, Late In and Early Out; the second row of cards shows Total Late In, Total Early Out, Late In + Early Out, Worked Time.
- [ ] Compare one month with ZKBioTime's Time Card / Transaction report for the same employee: schedule, late in, early out and worked time per day match. If late in is 60 times too large or small, set Duration unit.
- [ ] A public holiday shows as Holiday (not Absent); a day off in the schedule as Rest Day.
- [ ] My Attendance filters Late In, Early Out and Holiday list only those days.
- [ ] Cards Missing Morning Punch (no check-in) and Missing Afternoon Punch (no check-out) count those days and open My Attendance filtered to them.
- [ ] Read attendance from: API again: the pages look as in 0.5.1.

### 0.5.1 – Database Server and Connect via
- [ ] Integration Settings → Database: Server `local-name:7496` and Connect via `<public IP>:<port>` with Verify → Test Database connects, encrypted; the report shows `local-name:7496 → <public IP>:<port>`.
- [ ] Connect via with a name instead of an IP is refused; with SQL Server it is refused.

### 0.5.0 – Database (direct read)
- [ ] Integration Settings → Database: `postgres` as user is refused; Verify without a CA file is refused; changing the host without typing the password again is refused.
- [ ] Test Database with the read-only user (docs/DATABASE.md): connected, encrypted (with SSL on), not an administrator, read only by default, no table it may change; the punch, employee and device tables readable; the summary lists att_ tables with columns, no data.
- [ ] Test Database with a wrong password, a closed port and SSL off on the server: each shows its own message and hint.
- [ ] Developers: `BIOTIME_TEST_PG=host:port:database:user:password vendor/bin/phpunit --filter testRealPostgres` against a PostgreSQL with that read-only user.

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
- [ ] Record which description form worked (from the log).

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
