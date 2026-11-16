# Joomla ZKBioTime
**A read-only employee self-service attendance portal for ZKTeco ZKBioTime 9.5, inside Joomla 6.**

Employees log in to Joomla and see their own attendance from ZKBioTime: monthly check-in and
check-out times, the schedule applied on each day, late arrivals, early departures, absences,
leave, and reports they can download as PDF or Excel.

ZKBioTime is the single source of truth. Joomla only reads and displays: it never creates,
edits, corrects or recalculates attendance data. When ZKBioTime does not provide a value, the
portal shows "—" and says so instead of guessing.

Which ZKBioTime endpoint supplies each value, and whether it has been verified on a real server,
is in [docs/API-MAPPING.md](docs/API-MAPPING.md).

## Features

### Portal pages (six menu item types)

Create them under **Menus → Add New Menu Item → ZKBioTime**. The site's own menu is the
navigation; the pages have no navigation bar of their own. The menu item title is the page
heading and browser title, and *Page Heading*, *Browser Page Title* and *Show Page Heading*
are respected.

| Menu item type | What the employee sees |
|---|---|
| **My Dashboard** | Summary cards (working days, present days, late-in occurrences and time, early-out occurrences and time, absence days, leave days, worked hours, overtime), a Monthly Overview chart with the same figures as a table, and Recent Activity. Every figure links to My Attendance with the matching filter. Each part loads on its own. |
| **My Attendance** | One row per day: date (link to the day's details), day, the schedule applied on that date, scheduled start and end, check-in, check-out, late-in, early-out, worked hours, overtime and status, plus all punches of the day (time, state, device, area) and exceptions. Filter by month and year, status, or a date. |
| **Attendance Calendar** | The month as a calendar; each day shows its status as colour **and** text and opens the day's details. Usable with keyboard and screen readers and on a 390 px phone. |
| **My Schedules** | The schedule ZKBioTime applied on each date (month list and one week): shift, start and end, breaks, rest days, holidays, changed schedules. |
| **Attendance Reports** | A month or a custom date range (at most 93 days by default): summary, late-in, early-out, absence and overtime reports, leave summary, attendance exceptions and all days. **Download PDF** and **Download Excel**. |
| **My Profile** | The employee's own ZKBioTime record, read only: employee code, name, department, position, area, e-mail and hire date. Nothing else is ever read (no passwords, card numbers, biometric data, national ID or device data). |

Day details (one day: schedule, times, all punches, exceptions, leave) open from the
Attendance, Calendar, Schedules and Reports pages; they are not a menu item type.

**Exceptions** shown read-only (nothing is corrected): missing check-in, missing check-out,
incomplete punch record, duplicate punch, late arrival, early departure, missing schedule.

**Times** are shown exactly as ZKBioTime recorded them, in the ZKBioTime server's time zone; a
page says so when the user's own Joomla time zone differs. Times are never shifted.

### Administrator pages

- **Integration Settings**: connection, credentials (encrypted), employee mapping, modules,
  data sources for calculated attendance and leave, system settings; **Test Connection**,
  **Read Certificate Pin**, **Close**, **Options**.
- **Find Endpoints**: reads the API list from ZKBioTime, checks an endpoint (field names, kinds of
  values and status texts only) and saves it as the Daily Attendance or Leave Source.
- **User Mappings**: every Joomla user mapped to a ZKBioTime employee, with search, status and
  source filters, and pages. **New** (map by employee code), **Confirm**, **Suspend**,
  **Re-match**. The status filter *No mapping yet* lists Joomla users that are not matched.

## Requirements

| Requirement | Version |
|---|---|
| Joomla | 6.2 or later |
| PHP | 8.3 or later, with OpenSSL (AES-256-GCM), cURL and zip (Excel exports) |
| Database | MySQL / MariaDB or PostgreSQL |
| ZKBioTime | 9.5, REST API reachable from the Joomla web server |

The installer refuses to install with a clear message when PHP, Joomla or OpenSSL is too old.

## Installation and updates

1. Download `com_biotime.zip` from the latest release on the
   [Releases page](https://github.com/aboarken/biotime/releases).
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
| Modules | Charts, Exports, Leave information | Turn parts of the pages off. A turned-off feature shows a friendly "not available" message. |
| Daily Attendance Source | API path, parameters, field names, status names | Where ZKBioTime's calculated attendance and per-day schedules are read; see *Calculated attendance* below. |
| Leave Source | API path, parameters, field names, approval names | Where leave records are read, if your ZKBioTime offers them. |
| System | Detailed logging | See *Troubleshooting*. |
| | Cache time (minutes) | 0–60, default 5; see *Cache*. |
| | Date format, Time format | How the portal and exports show dates and times. |
| | ZKBioTime server time zone | Default: Joomla's server time zone. |
| | Longest report range (days), Exports per user and hour | Default 93 days and 10 exports. |
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
> Permissions / Options*); otherwise a user could enter a colleague's code. When the field (or,
> for an e-mail match, the e-mail address) changes, the mapping is **suspended** until an
> administrator confirms it.

Unmatched users see "Your account could not be matched to a ZKBioTime employee — please contact
the System Administrator", not an error. In **User Mappings** an administrator can map them by
employee code, **Confirm** a suspended mapping, **Suspend** one, or **Re-match** (delete it so
matching runs again). One employee can never be mapped to two Joomla users. Mappings are
re-verified against ZKBioTime once a day. Every administrative change is recorded in
`#__biotime_audit`.

### 4. Calculated attendance (schedules, statuses, late-in, ...)

ZKBioTime's published API manual documents employees and transactions (punches). Calculated
attendance — the schedule applied on each date, status, late-in, early-out, worked hours,
overtime — and leave depend on what your ZKBioTime 9.5 build offers. So the portal reads them
from an endpoint **you choose** from your server's `/api/docs/` page (or a saved ZKBioTime
report endpoint), under **Integration Settings → Daily Attendance Source** and **Leave Source**:

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
- **status names**, one per line `ZKBioTime text = present | late | early | absent | leave |
  holiday | rest | incomplete | other`, which decide colour and label.

**Easiest way: Find Endpoints** (*Components → ZKBioTime → Find Endpoints*, or the button on
Integration Settings). It reads the list of endpoints from your ZKBioTime, checks the one you
select with an employee code and dates, shows its field names, kinds of values and status texts
(never personal values), suggests all the lines above, and saves them as the Daily Attendance or
Leave Source after you review them. If your ZKBioTime publishes no readable API list, type the
path from `/api/docs/` there.

Until the daily source is set, the portal shows punches and "—" for schedule, status, late-in,
early-out, worked hours and overtime, with a note saying so. Check-in and check-out then come from
the punches by their state: the first check-in punch and the last check-out punch (break punches
ignored), so a check-out is never shown as check-in.
Nothing is calculated by Joomla. Only counting of values ZKBioTime returned is done (e.g. the
number of days with status Late, the sum of late-in minutes).

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
| `zkb.schedule.view` | My Schedules (and day details) |
| `zkb.report.view` | Attendance Reports |
| `zkb.report.export` | PDF and Excel downloads |
| `zkb.profile.view` | My Profile |

## Cache and performance

Each data type (punches, calculated attendance, leave) is read once per user and month and
kept in the user's own session for the cache time (default 5 minutes), so My Dashboard, My
Attendance, the Calendar, My Schedules, day details and Reports share it. Pages show
"Updated hh:mm". With cache time 0 every page reads ZKBioTime again, which makes the Dashboard
slow.

API calls per page load, for one month (cold cache; a warm cache makes **0** calls):

| Page | Calls |
|---|---|
| Any page, first visit of a user | Employee code field: 1 (employee list filtered by code). E-mail: one per page of the employee list. Then 0 while the mapping is active (1 per day to re-verify). |
| Login | 1 per token lifetime (the token is cached on the server, encrypted). |
| Dashboard, My Attendance, Calendar, My Schedules, day details | punches + daily source + leave source = **3** (more only when a month has more rows than the page size). |
| Attendance Reports, exports | 3 per month the range touches (at most 4 months for 93 days). |
| My Profile | 1 |

## Security

- Every page needs a logged-in Joomla user and its own permission; every read takes the
  server-side mapping. Employee ids, codes or dates in a URL never choose whose data is shown;
  a record that is not the user's gives the same "not found" as a missing one.
- If ZKBioTime ignores an employee filter and returns other people's rows, they are dropped
  and a warning is logged.
- Credentials and tokens never reach the browser (no JavaScript, HTML, session or cookie).
- Every state-changing action is a POST with a CSRF token and a permission check; exports are
  limited per user and hour; connection tests per administrator and hour.
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
| values show "—" | The daily attendance source is not set, or a field name is not mapped |

## Development

- Every change goes through a pull request; the maintainer merges.
- Tests: `composer install`, then `vendor/bin/phpunit` (no ZKBioTime server needed). Browser
  checks and the live checklist for the real server: [docs/TESTING.md](docs/TESTING.md).
- PDF exports use the bundled TCPDF (LGPL-3.0-or-later) in `administrator/lib/tcpdf`, pruned to
  DejaVu Sans (Latin, Arabic and more); `build/update-tcpdf.sh` refreshes it.
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

GNU General Public License version 2 or later. See [LICENSE](LICENSE). The bundled TCPDF is
LGPL-3.0-or-later (`administrator/lib/tcpdf/LICENSE.TXT`).
