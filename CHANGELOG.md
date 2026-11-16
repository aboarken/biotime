# Changelog

All notable changes to `com_biotime` are documented in this file.
Versions follow the component manifest (`biotime.xml`) and use [Semantic Versioning](https://semver.org/).

## [0.4.6] - 2026-11-16

### Changed
- **Faster pages.**
  - **Past months are kept for 24 hours**: their punches no longer change, so after the first read of the day, moving to a past month or opening My Punches does not ask ZKBioTime again. The current month still refreshes after the *Cache time* (default 5 minutes), so new punches appear as quickly as before.
  - **Saved data in its own table** `#__biotime_cache` instead of the user's session: it survives logging out and is no longer loaded with the session on every page of the site. Expired entries are deleted automatically. Updating creates the table; the old session data is removed on each user's next visit.
  - **One connection per page load**: all requests of a page reuse one HTTPS connection to ZKBioTime (one handshake instead of one per request).

### Fixed
- **Every page slow when ZKBioTime did not answer the daily employee check.** The failed check was retried on every page load, and each attempt waited for ZKBioTime. It is now tried again after an hour.

## [0.4.5] - 2026-11-16

### Fixed
- **Date picker months**: clicking ← or → in the My Attendance date picker closed it, so it always reopened on the current month. It now stays open while you move between months, and opens on the month the page shows (e.g. October when October is selected) when no date is set.
- **"Unknown" removed from punch details**: devices record many punches with the state "Unknown" (state 255); that word is no longer shown in the punch list or the day details (the punch still counts as check-in or check-out by its time).

## [0.4.4] - 2026-11-16

### Added
- My Dashboard → **Recent Activity**: a **Note** column at the end shows *! Missing Punch* for days whose check-in or check-out is missing (the Status column keeps the status only).

### Removed
- **Leave Source** and the **Leave information** switch (*Integration Settings*), and *Save as Leave Source* in Find Endpoints: leave has not been shown since 0.4.2, but a configured leave source was still read from ZKBioTime on every page. Updating deletes the stored leave settings. "leave" is no longer a daily status or daily field.
- Code and strings left over from removed features: the leave record class, punch-state direction detection (the time of day decides check-in and check-out since 0.4.3), unused view helpers, the "Use for" list in Find Endpoints, unused styles (lists, pills, panel footers), the leave endpoint of the test server, and outdated items in the testing checklist.

## [0.4.3] - 2026-11-16

### Changed
- **Check-in before 12:00, check-out from 12:00.** The time of day now decides, whatever state the device recorded: the first punch before 12:00 is the check-in and the last punch from 12:00 on is the check-out. A day with only an afternoon punch shows it under Check-Out (check-in *Missing*), never under Check-In.
- **Checked Out**: today shows Checked In (↘) after the check-in and Checked Out (↗) after the check-out, with the same arrows as the times; both are in the calendar legend.
- **Date picker** on My Attendance: the date field opens a month calendar (in the portal's design, DD/MM/YYYY, weeks from Monday, keyboard friendly) instead of being typed into; choosing a day filters at once.
- **Page titles** always join the site name with " - " (as set in *Site Name in Page Titles*), never with "|".
- My Dashboard: **Devices You Used** shows fingerprint, face and total punches per device with a total row; **Recent Activity** has a Day column (e.g. Sunday).
- Day details: the punch table marks only the punches counted as check-in and check-out.

## [0.4.2] - 2026-11-16

### Changed
- **Missing Punch instead of Incomplete.** A day with any punch is **Present**. When its check-in or check-out is missing it also gets the amber **Missing Punch** tag, and the missing time shows *Missing*. The first punch of the day is the check-in even when it is the only one; a lone check-out punch stays a check-out (check-in *Missing*). The Monthly Overview and the cards count such a day as Present **and** as a Missing Punch day.
- **My Punches replaces Biometric Devices**: the logged-in employee's own punches for a year (or one month): totals by fingerprint and by face, per month with a total row, and per device. Updating turns Biometric Devices menu items into My Punches and moves the *View Biometric Devices* permission to *View My Punches*. The device list (`/iclock/api/terminals/`) is no longer read.
- My Dashboard: 10 summary cards (6, then 4), all the same size (Total Time In-Out has its own card); Devices You Used lists every device used in the selected month.
- My Attendance: 8 summary cards (5, then 3), all the same size.
- Dates are shown as **DD/MM/YYYY** by default (sites on the old default YYYY-MM-DD switch on update); the My Attendance date filter is typed as DD/MM/YYYY.
- Year lists start at 2025, and month navigation stops at January 2025.

### Removed
- The **Leave** tag, legend entry, filter and card. A working day without punches is Absent.

### Fixed
- Punches outside the month asked for (from a server that ignores the time filter) are skipped, so they are never counted twice.

## [0.4.1] - 2026-11-16

### Changed
- **New portal design**, the same as the ServiceDesk Plus portal: summary cards with a coloured edge, white panels with a title row, tables with small upper-case headings, coloured status pills (green present, amber incomplete, red absent, grey weekend, teal checked in, blue leave), a filter bar and month navigation (← month →) in the page heading. The portal styles everything itself, so the pages look the same on any site template.
- **Filters apply at once**: choosing a month, year, status, health or location (or a date) updates the page; there is no Show button (it is still there without JavaScript).
- My Dashboard: Monthly Overview as coloured tiles with a bar showing the month at a glance, Devices You Used beside it, and Recent Activity with a "View all" link. My Attendance: the summary cards filter the list. Biometric Devices: the health cards filter the list. The calendar colours each day by its status and, on phones, shows a status symbol explained by the legend.

## [0.4.0] - 2026-11-16

### Added
- **Biometric Devices** (new menu item type, permission *View Biometric Devices*): every ZKBioTime device from `/iclock/api/terminals/` with its name and model, location (area), **health** (Online: contact within 10 minutes; Delayed: within 24 hours; Offline: longer; Unknown), last activity, and the users, fingerprints, faces and punches stored on it. Counts per health (click to filter) and a location filter. The menu item option *Show technical details* adds serial number, IP address and firmware. Needs no employee mapping and shows no employee data.
- **Day statuses from the punches**: *Present* (check-in and check-out), *Checked In* (today), *Incomplete* (check-in or check-out missing), *Absent* (a past working day without any punch), *Weekend* (a weekend day without punches) and *Leave*. A weekend day with a check-in or check-out loses the weekend tag.
- **Weekend days** setting (*Integration Settings → System*), default Friday and Saturday.
- My Dashboard: a **Today** card (check-in, check-out, time between, status); days attended of the working days so far, days absent, incomplete days, attendance rate, average check-in (with the earliest) and check-out (with the latest), average and total time between check-in and check-out, weekend days worked, punches, and the devices used most.
- Every punch now also shows how the employee was verified (`verify_type_display`, e.g. Fingerprint, Face); the device serial number is read too.

### Changed
- **Check-in and check-out are clearer** on every page: each has its own arrow and colour, the device and verification it was recorded with, and *Missing* when the day has the other one only. The calendar shows both times in each day. The day details mark which punch counted as check-in and check-out.
- My Dashboard, My Attendance, the Attendance Calendar and day details show **only what the ZKBioTime 9.5 API provides**: the schedule, scheduled start and end, break, late-in, early-out, worked hours and overtime are no longer shown. My Attendance shows a whole month on one page.

### Removed
- **My Schedules**, **Attendance Reports** (with the PDF and Excel downloads) and **My Profile**: ZKBioTime 9.5 has no schedule or report API. Their menu items are moved to the trash on update, their files are deleted, and the permissions *View My Schedules*, *View Attendance Reports*, *Export Attendance Reports* and *View My Profile* are gone. The settings *Exports*, *Exports per user and hour* and *Longest report range* are removed. The bundled TCPDF library is no longer needed and is removed (smaller package).

## [0.3.4] - 2026-11-16

### Changed
- The GitHub repository was renamed to `aboarken/joomla-biotime`. The manifest's author URL, changelog URL and update server now use `https://github.com/aboarken/joomla-biotime/releases/latest/download/`. Sites on an older version keep working: GitHub redirects the old `aboarken/biotime` URLs, and installing this version stores the new update server address.

## [0.3.3] - 2026-11-16

### Added
- **Diagnostics** (*Find Endpoints*, at the top): one button runs every check from the Joomla server: the connection, the API list, the punch endpoint and the calculated-attendance endpoints (those in the API list plus `/att/api/totalTimeCardReport/`, from ZKTeco's BioTimeCloud API manual, unverified on 9.5). It shows one plain-text report with a **Copy report** button. The report holds only paths, results (HTTP status and ZKBioTime's message), row counts, field names, kinds of values and status texts: no credentials, tokens, names, employee codes or times. Super User only, POST with CSRF token, counted in the 60 checks per hour, audited.

### Changed
- When the API list holds no attendance, report or leave endpoints (as on the school's server, which lists only personnel endpoints), Find Endpoints says so instead of "listed first".

## [0.3.2] - 2026-11-16

### Fixed
- **Check-out missing with "Unknown" punches.** Terminals that record punches as *Check In* (state 0) and *Unknown* (state 255), as on the school's server, showed no check-out when calculated times were not available. Unknown punches are now used where no explicit punch exists: check-in = the first check-in punch (else the first unknown punch that has a later punch), check-out = the last check-out punch after it (else the last unknown punch after it). A lone check-out is still never shown as check-in; a lone unknown punch is shown under neither and listed as incomplete.

### Changed
- The punch list (`/iclock/api/transactions/`) and the employee list can no longer be saved as the Daily Attendance or Leave Source: the portal already reads them, and they hold no calculated attendance. Find Endpoints says so instead of offering to save them.
- `docs/API-MAPPING.md`: the transaction fields seen on the real server are recorded.

## [0.3.1] - 2026-11-15

### Fixed
- **Find Endpoints could not read the API list** ("ZKBioTime did not accept the request"): the server publishes it as CoreJSON (`/api/docs/?format=corejson`) and refuses the request with 406 when only plain JSON is accepted. The CoreJSON media type (`application/coreapi+json`) is now requested.

### Changed
- Find Endpoints shows ZKBioTime's own answer (HTTP status and message) when reading the list or checking an endpoint fails, and explains a web page returned instead of data: the path probably does not exist on that server.
- `docs/API-MAPPING.md`: login and the transaction list are verified on a real ZKBioTime server.

## [0.3.0] - 2026-11-15

### Added
- **Find Endpoints** (*Components → ZKBioTime → Find Endpoints*, and a button on Integration Settings): reads the list of API endpoints from your ZKBioTime (`/api/docs/`, `/api/personnel_docs/`), lists attendance, report and leave endpoints first, and checks the one you select with an employee code and dates. It shows the field names, the kind of value in each (date, time, number, …) and the status texts found, never names, codes or other personal values, then suggests the field and status lines. **Save as Daily Attendance Source** or **Save as Leave Source** stores them after you review them. Only reading (GET) requests are sent; the page needs Super User rights, every action is a POST with a CSRF token, limited to 60 per hour and audited.

### Fixed
- **A check-out was shown under Check-In** when ZKBioTime's calculated times were not available and a day had only a check-out punch. Check-in and check-out are now taken from the punches by their state (check-in, check-out, overtime in/out; break punches are ignored): the first check-in punch and the last check-out punch. A day with only a check-out shows Check-In "—" and the exception *Missing check-in*. A single punch of unknown state is shown under neither and listed as an incomplete punch record.
- A test that should catch missing language strings per page was not checking any file; it now does, and the two strings it found missing on Find Endpoints were added.

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
