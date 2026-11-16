# Joomla ZKBioTime
**A read-only employee self-service attendance portal for ZKTeco ZKBioTime 9.5, inside Joomla 6.**

Employees log in to Joomla and see their own attendance from ZKBioTime: each day's check-in and
check-out (with the device and how they were verified), the time between them, and whether the
day was Present (with a Missing Punch tag when one of the two is missing), Absent or a Weekend,
and how many times they punched by fingerprint and face, per month and per device.

ZKBioTime is the single source of truth. Joomla only reads and displays: it never creates,
edits or corrects attendance data. The pages show only what the ZKBioTime 9.5 API provides
(punches, with the device and verification of each); the day statuses follow a simple, documented rule on those punches.

Which ZKBioTime endpoint supplies each value, and whether it has been verified on a real server,
is in [docs/API-MAPPING.md](docs/API-MAPPING.md).

## Features

### Portal pages (four menu item types)

Create them under **Menus → Add New Menu Item → ZKBioTime**. The site's own menu is the
navigation; the pages have no navigation bar of their own. The menu item title is the page
heading and browser title, and *Page Heading*, *Browser Page Title* and *Show Page Heading*
are respected.

| Menu item type | What the user sees |
|---|---|
| **My Dashboard** | **Today**: check-in, check-out, the time between them and the status. The month's figures: days present (of the working days so far), days absent, missing-punch days, attendance rate, average check-in (and the earliest), average check-out (and the latest), average and total time between check-in and check-out, weekend days worked, punches; a Monthly Overview chart with the same figures as a table; the devices used most; Recent Activity (last 7 days, with the day name). Devices You Used shows each device used in the month with its fingerprint, face and total punches, and a total row. Every figure links to My Attendance with the matching filter. |
| **My Attendance** | One row per day: date (link to the day's details), day, **check-in** and **check-out** (each with its arrow, colour and the device and verification it was recorded with, or *Missing*), the time between them, the status and all punches of the day (time, state, verification, device, area). Weekend rows and today are shaded. Filter by month and year, status (present, missing punch, absent, weekend, weekend worked, exceptions, with or without punches) or a date. |
| **Attendance Calendar** | The month as a calendar; each day shows its status as colour **and** text, its check-in and check-out, and opens the day's details. Weekend days are shaded. Usable with keyboard and screen readers and on a 390 px phone. |
| **My Punches** | The employee's own punches for a year (from 2025), or one month of it: total punches, by **fingerprint** and by **face**, devices used and days with punches; a table **per month** with a total row (each month opens My Attendance), and a table **per device** (area, fingerprint, face, total, last used). Only the logged-in employee's punches. |

Day details (one day: check-in, check-out, time between, status, every punch with how it was
counted, exceptions) open from the Dashboard, Attendance and Calendar pages; they are not a
menu item type.

**Design.** The pages use the same design as the ServiceDesk Plus portal: summary cards, panels,
tables and coloured status pills, month navigation in the heading, and filters that apply as soon
as an option is chosen. The component styles everything itself
(`media/com_biotime/css/portal.css`), so the pages look the same on any site template.

**Day statuses** (from the punches, see [docs/API-MAPPING.md](docs/API-MAPPING.md#day-statuses-from-the-punches--portal-rule)):

| Status | When |
|---|---|
| Present | any punch was recorded. When the check-in or the check-out is missing, the day is also tagged **Missing Punch** (and the missing time shows *Missing*); it counts as Present and as a Missing Punch day. |
| Checked In / Checked Out | today, after the check-in (↘) / after the check-out (↗) |
| Absent | a past working day without any punch |
| Weekend | a weekend day without punches (Friday and Saturday by default, *Integration Settings → System → Weekend days*). A weekend day **with** a check-in or check-out loses the weekend tag and is shown like any other day. |

**Check-in** is the first punch **before 12:00** and **check-out** the last punch **from 12:00 on**,
whatever state the device recorded (a morning "Check Out" punch is a check-in, an afternoon
"Check In" punch a check-out). A day with punches only in the morning has no check-out, one with
punches only in the afternoon has no check-in: both show *Missing* and the Missing Punch tag.
Today shows **Checked In** (↘) after the check-in and **Checked Out** (↗) after the check-out.

**Dates** are shown as DD/MM/YYYY by default (*Integration Settings → System → Date format*), and
the date filter is a date picker that shows and sends DD/MM/YYYY. Year lists start at 2025.

**Exceptions** shown read-only (nothing is corrected): missing check-in, missing check-out,
incomplete punch record, duplicate punch.

**Times** are shown exactly as ZKBioTime recorded them, in the ZKBioTime server's time zone; a
page says so when the user's own Joomla time zone differs. Times are never shifted.

### Administrator pages

- **Integration Settings**: connection, credentials (encrypted), employee mapping, modules,
  the data source for calculated attendance, system settings; **Test Connection**,
  **Read Certificate Pin**, **Close**, **Options**.
- **Find Endpoints**: reads the API list from ZKBioTime, checks an endpoint (field names, kinds of
  values and status texts only) and saves it as the Daily Attendance Source.
  **Run Diagnostics** runs every check in one click and gives a report to copy (paths, results,
  field names and kinds only).
- **User Mappings**: every Joomla user mapped to a ZKBioTime employee, with search, status and
  source filters, and pages. **New** (map by employee code), **Confirm**, **Suspend**,
  **Re-match**. The status filter *No mapping yet* lists Joomla users that are not matched.

## Requirements

| Requirement | Version |
|---|---|
| Joomla | 6.2 or later |
| PHP | 8.3 or later, with OpenSSL (AES-256-GCM) and cURL |
| Database | MySQL / MariaDB or PostgreSQL |
| ZKBioTime | 9.5, REST API reachable from the Joomla web server |

The installer refuses to install with a clear message when PHP, Joomla or OpenSSL is too old.

## Installation and updates

1. Download `com_biotime.zip` from the latest release on the
   [Releases page](https://github.com/aboarken/joomla-biotime/releases).
2. Joomla Administrator → **System → Install → Extensions** → upload the package.
3. Open **Components → ZKBioTime → Integration Settings**.

Updates appear under **System → Update → Extensions** (update site "ZKBioTime"). Installing a
newer package over the existing one also works; settings and mappings are kept.

## Configuration

### 1. ZKBioTime API user

Create a ZKBioTime user for the portal with **read-only** permission for personnel and
attendance. The portal reads through this one user and enforces on the Joomla server that each
employee only ever sees their own records.

### 2. Integration Settings

| Section | Setting | Notes |
|---|---|---|
| Connection | **Server URL** | Including the port when it is not the standard one, e.g. `https://zkb.example.com:5555`. A warning is shown for plain `http`. |
| | Connect via | Optional; see *Server with an internal name* below. |
| | Connection timeout, Total timeout | Seconds; connection ≤ total. |
| Credentials | Authentication method | **JWT** (username + password exchanged for a short-lived token, renewed automatically) or **General Auth Token** (a token entered here, or obtained with username + password). |
| | API username, API password, General Auth Token | Encrypted (AES-256-GCM) before they are stored and never shown again: the page only says *Stored (encrypted)* or *Not set*. |
| TLS | Verify TLS certificate, CA bundle file, Certificate pin | Verification is on by default. |
| Employee Mapping | Employee code user field | Default `employeeid`; see *Employee mapping* below. |
| | Match by e-mail when the field is empty | On by default. |
| Modules | Charts | Turn the Monthly Overview bar on My Dashboard off. |
| Daily Attendance Source | API path, parameters, field names, status names | Where ZKBioTime's calculated attendance is read (its check-in, check-out and status are used); see *Calculated attendance* below. |
| System | Detailed logging | See *Troubleshooting*. |
| | Cache time (minutes) | 0–60, default 5; see *Cache*. |
| | Date format, Time format | How the portal shows dates and times. |
| | ZKBioTime server time zone | Default: Joomla's server time zone. |
| | Weekend days | Default Friday and Saturday. A weekend day without punches is *Weekend*, not *Absent*; with a punch it is shown like any other day. |
| | API page size, Safety maximum (pages) | Default 100 rows × 50 pages; pages say when results were cut off. |

Settings are stored in the component's own table `#__biotime_settings`, not in Joomla's
component options, because Joomla's Options page replaces every option on save and the secrets
must be encrypted first. *Options* holds only the permissions.

**Encryption secret.** Secrets are encrypted with a key derived from the environment variable
`BIOTIME_PORTAL_SECRET` (recommended, at least 32 random characters) or, when that is not set,
from the `secret` in Joomla's `configuration.php`. The page shows which one is in use. If it
changes, enter the API password (or token) again.

**Server with an internal name (Connect via).** When ZKBioTime's own name is only known inside
the company network and Joomla reaches it through a public address, enter the internal name as
*Server URL* and the public address as *Connect via*:

| Server URL | Connect via |
|---|---|
| `https://zkb.mysite.com` | `api.zkb.mysite.com` (or `host:port`, or `IP:port`) |

Joomla opens the connection to `api.zkb.mysite.com` but still checks the certificate against
`zkb.mysite.com` and sends `Host: zkb.mysite.com`, so the server's existing certificate stays
valid and no DNS change is needed on the Joomla server. *Read Certificate Pin* reads the
certificate through the same address. If the certificate is not signed by a public CA, add a
CA bundle or a certificate pin:

```bash
openssl x509 -in server.crt -pubkey -noout | openssl pkey -pubin -outform der | openssl dgst -sha256 -binary | base64
```

The pin is `sha256//` followed by that output.

**Test Connection** logs in and reads one row of the employee list; it reports the method, how
many employees the API user can read and when the token is renewed, or names the problem and
what to check. At most 20 tests per administrator and hour.

### 3. Employee mapping

Every Joomla user is mapped to exactly one ZKBioTime employee (`#__biotime_usermap`). On a
user's first visit:

1. **Employee code field (first choice).** If the Joomla user has the custom user field
   `employeeid` (Users → Fields; the name is a setting) filled in, it must equal exactly one
   employee's `emp_code`. If no employee has that code, the user is *not found*; e-mail is not
   tried, so a wrong code never maps someone else.
2. **E-mail (when the field is empty).** The one employee with exactly the same e-mail address
   (case-insensitive). Several matches are *ambiguous*, none is *not found*.

> **Important:** users must not be able to edit the `employeeid` field themselves. In the
> field's settings, allow editing only for administrators (*Users → Fields → employeeid →
> Permissions / Options*); otherwise a user could enter a colleague's code. Since 0.4.7 the
> portal checks this: while the field is shown and editable to a (non-administrator) user, that
> user is *not found* and a warning is logged. When the field (or, for an e-mail match, the
> e-mail address) changes, the mapping is **suspended** until an administrator confirms it. An
> unmatched user who changes their e-mail address and is then matched by it is also held as
> suspended for an administrator (0.4.7).

Unmatched users see "Your account could not be matched to a ZKBioTime employee — please contact
the System Administrator", not an error. In **User Mappings** an administrator can map them by
employee code, **Confirm** a suspended mapping, **Suspend** one, or **Re-match** (delete it so
matching runs again). One employee can never be mapped to two Joomla users. Mappings are
re-verified against ZKBioTime once a day. Every administrative change is recorded in
`#__biotime_audit`.

### 4. Calculated attendance (schedules, statuses, late-in, ...)

**ZKBioTime 9.5 has no API for these.** Its API manual (BioTime 9.5 API User Manual, ZKTeco
Middle East, 16 Aug 2024) documents login, devices, personnel and transactions (punches) only,
and a real 9.5 server confirmed it. On 9.5, leave this source empty: the portal shows punches,
check-in and check-out and the punch-based day status, and no schedule, late-in, early-out,
worked hours or overtime. Joomla never calculates those itself.

If your ZKBioTime does offer such an endpoint (BioTime Cloud or a later build; **Run
Diagnostics** in Find Endpoints tells you), the portal can read ZKBioTime's own check-in,
check-out and day status from an endpoint **you choose** from your server's `/api/docs/` page (or a saved ZKBioTime
report endpoint), under **Integration Settings → Daily Attendance Source**:

- **API path**, e.g. the path listed on `/api/docs/`;
- the names of the **employee**, **start date** and **end date** parameters (the portal fills in
  the logged-in employee's code and the month's dates itself), optional **fixed parameters**
  (e.g. a saved report's id: `name = value`, letters/digits only — never queries or SQL);
- **field names**, one per line `portal field = ZKBioTime field` (dots for nested fields), e.g.

  ```
  emp_code = emp_code
  date = att_date
  schedule = timetable_name
  sched_start = check_in
  sched_end = check_out
  check_in = clock_in
  check_out = clock_out
  late = late
  early = early_leave
  worked = actual_worked
  overtime = overtime
  status = attendance_status
  ```

  (The ZKBioTime names above are an example only: use the names your `/api/docs/` shows.)
- **status names**, one per line `ZKBioTime text = present | late | early | absent |
  holiday | rest | incomplete | other`, which decide colour and label.

**Easiest way: Find Endpoints** (*Components → ZKBioTime → Find Endpoints*, or the button on
Integration Settings). It reads the list of endpoints from your ZKBioTime, checks the one you
select with an employee code and dates, shows its field names, kinds of values and status texts
(never personal values), suggests all the lines above, and saves them as the Daily Attendance
Source after you review them. If your ZKBioTime publishes no readable API list, type the
path from `/api/docs/` there.

**Not sure which endpoint?** Click **Run Diagnostics** at the top of Find Endpoints, then
**Copy report** and send the text to whoever supports the portal. It checks the connection, the
API list, the punch endpoint and the known calculated-attendance endpoints from your Joomla
server, and contains no passwords, tokens, names, employee codes or times.

When a daily source is set, its check-in, check-out and calculated status are used instead of
the punch-based ones (a status mapped to late or early is shown as such). The pages still show
only check-in, check-out, the time between them and the status.

### 5. Permissions

All administrator pages need **core.admin** (Super User level) on the component.

Each portal page has its own permission. **None is granted by default.** Under
**Components → ZKBioTime → Options → Permissions**, set them to *Allowed* for the **Registered**
group (or the group your employees are in). Without them users get "You are not authorised"
(HTTP 403).

| Permission | Page |
|---|---|
| `zkb.dashboard.view` | My Dashboard |
| `zkb.attendance.view` | My Attendance (and day details) |
| `zkb.calendar.view` | Attendance Calendar (and day details) |
| `zkb.punches.view` | My Punches |

My Dashboard also opens day details. Updating from 0.3.x moves menu items of the removed My
Schedules, Attendance Reports and My Profile pages to the trash; updating from 0.4.0 or 0.4.1 turns
Biometric Devices menu items into My Punches and moves its permission to *View My Punches*.

## Cache and performance

What the portal reads from ZKBioTime is kept per user in the component's own table
`#__biotime_cache`, so My Dashboard, My Attendance, the Calendar, day details and My Punches share
it, and it survives logging out. Each entry belongs to one Joomla user and one employee code.

- **The current month** is kept for the *Cache time* (default 5 minutes), so a new punch appears
  within that time.
- **Past months** are kept for **24 hours**: their punches no longer change. (Only while the cache
  is on; *Cache time* 0 turns everything off.)
- Expired entries are deleted automatically; the table holds only what was read recently (a few KB
  per employee and month, a few MB for a whole school).
- **One connection per page load**: all requests of a page reuse the same HTTPS connection to
  ZKBioTime instead of a new handshake each time.
- **Daily employee check**: once a day each user's mapping is checked against ZKBioTime. If
  ZKBioTime does not answer, the check is tried again after an hour, not on every page.

Pages show "Updated hh:mm" (when ZKBioTime was read).

API calls per page load, for one month (cold cache; a warm cache makes **0** calls):

| Page | Calls |
|---|---|
| Any page, first visit of a user | Employee code field: 1 (employee list filtered by code). E-mail: one per page of the employee list. Then 0 while the mapping is active (1 per day to re-verify). |
| Login | 1 per token lifetime (the token is cached on the server, encrypted). |
| Dashboard, My Attendance, Calendar, day details | punches (+ daily source when set) = **1** on 9.5 for the current month, at most 2 (more only when a month has more rows than the page size). Past months: 0 for a day after the first read. |
| My Punches | 1 per month of the year up to today on the first load of the day; then only the current month. |

## Security

- The administrator pages (settings, user mappings, diagnostics) need **Super User** rights
  (`core.admin` on the component), for every task, not only the page itself (0.4.7).
- When the server URL or *Connect via* changes, the stored password or token must be typed
  again, so saved credentials are never sent to a new address (0.4.7).
- Every page needs a logged-in Joomla user and its own permission; every read takes the
  server-side mapping. Employee ids, codes or dates in a URL never choose whose data is shown;
  a record that is not the user's gives the same "not found" as a missing one.
- If ZKBioTime ignores an employee filter and returns other people's rows, they are dropped
  and a warning is logged.
- Credentials and tokens never reach the browser (no JavaScript, HTML, session or cookie).
- Every state-changing action is a POST with a CSRF token and a permission check; connection
  tests are limited per administrator and hour.
- Portal pages are `noindex, nofollow` and sent with `Cache-Control: no-store`.
- Input is checked against strict patterns and whitelists; all output is escaped.
- The integration is read-only: the only POST sent to ZKBioTime is the login.

## Troubleshooting

`administrator/logs/com_biotime.php` records each API call (method, path, query parameter
names, HTTP status, time), one line per page load saying what was read, hidden or skipped and
why, and every problem in plain words. Passwords, tokens, headers and personal data are never
written to it. With *Detailed logging* off, only warnings and errors are written.

| Message | Check |
|---|---|
| could not be reached | Server URL (with port), *Connect via*, firewall/VPN, timeouts, TLS settings; the log has the cURL reason |
| did not accept the portal's credentials | Username, password or token, authentication method |
| did not allow the portal to read this information | The API user's permissions in ZKBioTime |
| could not find what was asked for | Server URL must not contain an extra path; check the source paths against `/api/docs/` |
| sent an answer the portal could not read | The URL may point to a proxy or login page; the log lists the keys received |
| Your account could not be matched | **User Mappings**: the user's status (Not found / Ambiguous / Suspended) and employee code field |
| a working day shows Absent although the person was there | No punch was recorded that day (check the device in ZKBioTime), or the day should be a weekend day (*Weekend days*). Public holidays are not known to the portal. |

## Development

- Every change goes through a pull request; the maintainer merges.
- Tests: `composer install`, then `vendor/bin/phpunit` (no ZKBioTime server needed). Browser
  checks and the live checklist for the real server: [docs/TESTING.md](docs/TESTING.md).
- Each version: bump `<version>` in `biotime.xml` and `media/joomla.asset.json`, add
  `administrator/sql/updates/{mysql,postgresql}/<version>.sql` (a comment when nothing changes),
  add a `CHANGELOG.md` entry and `docs/release-notes/<version>.md`.

### Releases

The **Release package** workflow (`.github/workflows/release.yml`) lints PHP, runs the tests,
builds and checks `com_biotime.zip`, and writes `updates.xml` (with SHA-256, target platform
Joomla 6 and minimum PHP 8.3) and `changelog.xml` (from `CHANGELOG.md`). To publish:
*Actions → Release package → Run workflow* on `main` creates release `v<version>` with the three
files and the release notes.

## License

GNU General Public License version 2 or later. See [LICENSE](LICENSE).
