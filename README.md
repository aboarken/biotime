# Joomla ZKBioTime
**A read-only employee self-service attendance portal for ZKTeco ZKBioTime 9.5, inside Joomla 6.**

Employees log in to Joomla and see their own attendance from ZKBioTime: each day's check-in and
check-out (with the device and how they were verified), the time between them, and whether the
day was Present, Absent or a Weekend,
and how many times they punched by fingerprint and face, per month and per device.

ZKBioTime is the single source of truth. Joomla only reads and displays: it never creates,
edits or corrects attendance data. Everything is read from **ZKBioTime's own PostgreSQL
database, read only**: the punches (with the device and verification of each), the employees,
each day's schedule and ZKBioTime's own calculation (late-in, early-out, worked time, overtime,
holidays, leave, days off). Since 1.0.0 the ZKBioTime REST API is no longer used.

Which table supplies each value is in [docs/DATABASE.md](docs/DATABASE.md).

## Features

### Portal pages (four menu item types)

Create them under **Menus → Add New Menu Item → ZKBioTime**. The site's own menu is the
navigation; the pages have no navigation bar of their own. The menu item title is the page
heading and browser title, and *Page Heading*, *Browser Page Title* and *Show Page Heading*
are respected.

| Menu item type | What the user sees |
|---|---|
| **My Dashboard** | The school year so far: **from 1 August** (this year's, or last year's before August) **to today**, with no month selection. Under the heading, one table row: department, position, employee ID, name and the period shown. **Today**: check-in, check-out, late in, early out and the status. Cards, 5 per row: days present (of the working days so far), days absent, missing morning punch, missing afternoon punch, holidays, weekend days worked, attendance rate, average check-in (and the earliest), average check-out (and the latest), punches, total late in, total early out, late in + early out, worked time, overtime. **Statistics** (charts of the same figures): days so far (donut), days per month, late in and early out per month, average check-in and check-out per month (a column from check-in to check-out), and circles for missing punches, weekend work and late or early days. Every card with a filter opens My Attendance for the same range. |
| **My Attendance** | One row per day: date (link to the day's details), day, the schedule, **check-in** and **check-out** (each with its arrow, colour and the device and verification it was recorded with, or *Missing*), late in, early out, worked time and the status; the day's punches are on its details page. Weekend rows and today are shaded. Filter by month and year, status (present, missing punch, absent, weekend, weekend worked, late in, early out, holiday, exceptions, with or without punches) or From – To dates below the month (a date alone: From that date to today). Dashboard figures open it for the same range. |
| **Attendance Calendar** | The month as a calendar; each day shows its status as colour **and** text, its check-in and check-out, and opens the day's details. Weekend days are shaded. Usable with keyboard and screen readers and on a 390 px phone. |
| **My Punches** | The employee's own punches for a year (from 2025), or one month of it: total punches, by **fingerprint** and by **face**, devices used and days with punches; a table **per month** with a total row (each month opens My Attendance), and a table **per device** (area, fingerprint, face, total, last used). Only the logged-in employee's punches. |
| **Manager Dashboard** | For managers (permission *View Manager Dashboard*): every employee of the departments ZKBioTime names the manager *Department Manager* of, and of the departments below them (not those who have resigned); someone who manages no department sees a "no access" message. Filters: department, employee, status, From – To (today by default, up to 31 days). Cards: **Workforce** and the My Attendance figures for everyone shown. Below, one row per employee with a column per date (that day's check-in and check-out), then Late In, Early Out and Total for the period. |

Day details (one day: check-in, check-out, time between, status, every punch with how it was
counted, exceptions) open from the Dashboard, Attendance and Calendar pages; they are not a
menu item type.

**Design.** The pages use the same design as the ServiceDesk Plus portal: summary cards, panels,
tables and coloured status pills, month navigation in the heading, and filters that apply as soon
as an option is chosen. The component styles everything itself
(`media/com_biotime/css/portal.css`), so the pages look the same on any site template.

**Day statuses** (holidays, leave and days off from ZKBioTime's calculation; otherwise from the punches):

| Status | When |
|---|---|
| Present | any punch was recorded. When the check-in or the check-out is missing, the missing time shows *Missing*; it counts as Present and as a missing morning or afternoon punch. |
| Checked In / Checked Out | today, after the check-in (↘) / after the check-out (↗) |
| Absent | a past working day without any punch |
| Holiday | a holiday of ZKBioTime (for everyone, the department or the attendance group), without punches |
| Leave | leave as ZKBioTime calculated it (e.g. *Annual Leave*, shown by its name), without punches |
| Rest Day | a day off in the employee's schedule, without punches |
| Weekend | a weekend day without punches (Friday and Saturday by default, *Integration Settings → System → Weekend days*). A weekend day **with** a check-in or check-out loses the weekend tag and is shown like any other day. |

**Check-in** is the first punch **before the cut-off** and **check-out** the last punch **from the
cut-off on**: the end of the check-in window of the day's timetable, or 12:00 on a day without
one, whatever state the device recorded (a morning "Check Out" punch is a check-in, an afternoon
"Check In" punch a check-out). A day with punches only in the morning has no check-out, one with
punches only in the afternoon has no check-in: both show *Missing*.
Today shows **Checked In** (↘) after the check-in and **Checked Out** (↗) after the check-out.

**Dates** are shown as DD/MM/YYYY by default (*Integration Settings → System → Date format*), and
the date filter is a date picker that shows and sends DD/MM/YYYY. Year lists start at 2025.

**Exceptions** shown read-only (nothing is corrected): missing check-in, missing check-out,
incomplete punch record, duplicate punch.

**Times** are shown exactly as ZKBioTime recorded them, in the ZKBioTime server's time zone; a
page says so when the user's own Joomla time zone differs. Times are never shifted.

### Administrator pages

- **Integration Settings**: the connection to ZKBioTime's database (password encrypted), employee
  mapping, modules, system settings; **Test Database**, **Close**, **Options**.
- **User Mappings**: every Joomla user mapped to a ZKBioTime employee, with search, status and
  source filters, and pages. **New** (map by employee code), **Confirm**, **Suspend**,
  **Re-match**. The status filter *No mapping yet* lists Joomla users that are not matched.

## Requirements

| Requirement | Version |
|---|---|
| Joomla | 6.2 or later |
| PHP | 8.3 or later, with OpenSSL (AES-256-GCM) and pdo_pgsql |
| Database | MySQL / MariaDB or PostgreSQL |
| ZKBioTime | 9.5 on PostgreSQL, its database reachable from the Joomla web server (docs/DATABASE.md) |

The installer refuses to install with a clear message when PHP, Joomla or OpenSSL is too old.

## Installation and updates

1. Download `com_biotime.zip` from the latest release on the
   [Releases page](https://github.com/aboarken/joomla-biotime/releases).
2. Joomla Administrator → **System → Install → Extensions** → upload the package.
3. Open **Components → ZKBioTime → Integration Settings**.

Updates appear under **System → Update → Extensions** (update site "ZKBioTime"). Installing a
newer package over the existing one also works; settings and mappings are kept.

## Configuration

### 1. Read-only database user

Create a PostgreSQL user for the portal that may only read ZKBioTime's tables (step by step in
[docs/DATABASE.md](docs/DATABASE.md)). The portal reads through this one user and enforces on
the Joomla server that each employee only ever sees their own records.

### 2. Integration Settings

| Section | Setting | Notes |
|---|---|---|
| ZKBioTime Database | Database, Server, Connect via, Database name, User, Password, Encryption (SSL), CA file | See [docs/DATABASE.md](docs/DATABASE.md). The password is encrypted (AES-256-GCM) before it is stored and never shown again: the page only says *Stored (encrypted)* or *Not set*. The portal pages need PostgreSQL. |
| | Duration unit | Automatic by default. |
| | Connection timeout, Query timeout | Seconds; connection ≤ query. |
| Employee Mapping | Employee code user field | Default `employeeid`; see *Employee mapping* below. |
| | Match by e-mail when the field is empty | On by default. |
| Modules | Charts | No longer used since 0.9.0 (My Dashboard has no Monthly Overview). |
| System | Detailed logging | See *Troubleshooting*. |
| | Cache time (minutes) | 0–60, default 5; see *Cache*. |
| | Date format, Time format | How the portal shows dates and times. |
| | ZKBioTime server time zone | Default: Joomla's server time zone. |
| | Weekend days | Default Friday and Saturday. A weekend day without punches is *Weekend*, not *Absent*; with a punch it is shown like any other day. |

Settings are stored in the component's own table `#__biotime_settings`, not in Joomla's
component options, because Joomla's Options page replaces every option on save and the database
password must be encrypted first. *Options* holds only the permissions.

**Encryption secret.** The database password is encrypted with a key derived from the
environment variable `BIOTIME_PORTAL_SECRET` (recommended, at least 32 random characters) or,
when that is not set, from the `secret` in Joomla's `configuration.php`. The page shows which one
is in use. If it changes, enter the database password again.

**Test Database** connects with the saved settings and reports the server version, whether the
connection is encrypted, whether the user can only read, and which tables it may read (names
only, never data). At most 20 tests per administrator and hour.

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
re-verified against ZKBioTime's database once a day. Every administrative change is recorded in
`#__biotime_audit`.

### 4. Calculated attendance (schedules, late-in, early-out, ...)

Each day's schedule, late-in, early-out, worked time, overtime, holidays, leave and days off are
ZKBioTime's own calculation, read from its database; Joomla never calculates them. A day shows
late-in and early-out once ZKBioTime has calculated it (Auto Calculate, e.g. nightly); today and
later days show their schedule from the employee's shift. Details:
[docs/DATABASE.md](docs/DATABASE.md).

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
| `zkb.manager.view` | Manager Dashboard (give it only to the managers' group) |
| `zkb.manager.all` | Manager Dashboard shows every department, not only those the user manages in ZKBioTime (administrators and HR; Super Users always have it) |

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
- **One connection per page load**: all queries of a page share one read-only connection to
  ZKBioTime's database, opened when first needed.
- **Daily employee check**: once a day each user's mapping is checked against ZKBioTime. If
  ZKBioTime does not answer, the check is tried again after an hour, not on every page.

Pages show "Updated hh:mm" (when ZKBioTime was read).

Database reads per page load, for one month (cold cache; a warm cache reads **nothing**):

| Page | Reads |
|---|---|
| Any page, first visit of a user | Employee code field: 1 query by code. E-mail: 1 query of the employees with an e-mail address. Then none while the mapping is active (1 per day to re-verify). |
| Dashboard, My Attendance, Calendar, day details | the punches and the day rows of each month shown. Past months: none for a day after the first read. |
| My Punches | each month of the year up to today on the first load of the day; then only the current month. |

## Security

- The administrator pages (settings, user mappings, diagnostics) need **Super User** rights
  (`core.admin` on the component), for every task, not only the page itself (0.4.7).
- When the database server, *Connect via*, database or user changes, the stored password must be
  typed again, so it is never sent to a new address.
- Every page needs a logged-in Joomla user and its own permission; every read takes the
  server-side mapping. Employee ids, codes or dates in a URL never choose whose data is shown;
  a record that is not the user's gives the same "not found" as a missing one.
- Every query filters by the one employee; punches of anyone else would still be dropped and a
  warning logged.
- The database password never reaches the browser (no JavaScript, HTML, session or cookie).
- Every state-changing action is a POST with a CSRF token and a permission check; database
  tests are limited per administrator and hour.
- Portal pages are `noindex, nofollow` and sent with `Cache-Control: no-store`.
- Input is checked against strict patterns and whitelists; all output is escaped.
- The integration is read-only: the database connection is opened read only, and the user should only be allowed to read (Test Database checks it).

## Troubleshooting

`administrator/logs/com_biotime.php` records one line per page load saying what was read, hidden
or skipped and why, and every problem in plain words (with the database server's message).
Passwords and personal data are never written to it. With *Detailed logging* off, only warnings and errors are written.

| Message | Check |
|---|---|
| is not set up yet | Integration Settings → ZKBioTime Database: PostgreSQL, server, user and password saved; the encryption secret unchanged |
| could not be reached | Server and *Connect via*, firewall, SSL settings, timeouts; **Test Database** names the problem and the log has the database server's message |
| did not accept the portal's credentials | The database user and password (and `pg_hba.conf`) |
| is not available right now | The database name, and that the read-only user may read the tables (**Test Database**) |
| could not find what was asked for | No (or more than one) employee in ZKBioTime has the user's mapped employee code |
| Your account could not be matched | **User Mappings**: the user's status (Not found / Ambiguous / Suspended) and employee code field |
| a working day shows Absent although the person was there | No punch was recorded that day (check the device in ZKBioTime), or the day should be a weekend day (*Weekend days*). A holiday shows as Holiday only when it is set up in ZKBioTime. |

## Development

- Every change goes through a pull request; the maintainer merges.
- Tests: `composer install`, then `vendor/bin/phpunit` (no ZKBioTime server needed). The live
  checklist for the real server: [docs/TESTING.md](docs/TESTING.md).
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
