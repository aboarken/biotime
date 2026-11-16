# ZKBioTime API endpoint and field mapping

Every ZKBioTime call the component makes, with the fields it reads and its source. Fixed
endpoint paths are named in exactly one place: the constants at the top of
`administrator/src/Api/ZkbClient.php`. Field names read from transactions are constants in
`administrator/src/Attendance/AttendanceService.php`.

**Status key**

- **VERIFIED**: documented in the *BioTime 9.5 API User Manual (ZKTeco Middle East, 16 Aug 2024)* and, where noted, seen working on the real
  ZKBioTime 9.5 server.
- **UNVERIFIED**: not in that manual and not confirmed on the real server. Marked `UNVERIFIED`
  in the code too.
- **CONFIGURED**: no fixed endpoint in the code; the administrator enters the path, parameter
  names and field names from the server's `/api/docs/` page in Integration Settings.

## Server under test

| Item | Value |
|---|---|
| ZKBioTime version | 9.5 |
| Server | the school's ZKBioTime (port 5555), first checked 2026-11-15 from the administrator's log |
| Build number | **not recorded yet** (ZKBioTime *System → About*) |
| API manual | *BioTime 9.5 API User Manual (ZKTeco Middle East, 16 Aug 2024)*, 32 pages, received 2026-11-16 |
| `/api/docs/`, `/api/personnel_docs/` | read by Find Endpoints (CoreJSON): 10 personnel list endpoints |
| Diagnostics report | received 2026-11-16 (component 0.3.3) |

## What the BioTime 9.5 API offers

The *BioTime 9.5 API User Manual (ZKTeco Middle East, 16 Aug 2024)* documents exactly these APIs: login (JWT and General Auth Token, also for staff),
devices (`/iclock/api/terminals/`), employees, departments, areas, positions
(`/personnel/api/…`) and transactions (`/iclock/api/transactions/`). **It documents no API for
calculated attendance, time cards, schedules, reports or leave**, and the real server has none
either (Diagnostics, 2026-11-16). So since 0.4.0 the portal pages show only what this API gives:
punches, check-in and check-out, the device, area and verification of each punch, and the
devices themselves. Fields only ZKBioTime calculates (schedule, late-in, early-out, worked hours,
overtime) are no longer shown. The day's status (Present, with a Missing Punch tag when needed;
Checked In, Absent, Weekend) is told from the punches and the weekend days set in Integration Settings (see
*Day statuses* below). The Daily Attendance and Leave Sources stay **CONFIGURED** (empty by
default) for a ZKBioTime that offers such an endpoint; a calculated status from the daily
source is used instead of the punch-based one. Leave is no longer shown (0.4.2).

The client still reports anything unexpected instead of guessing:

- the list envelope is read as documented (`data`); `results` or a bare list are accepted **and
  logged**, so this document can be corrected;
- unknown or missing fields are never filled in: they show "—";
- an unreadable answer is the error *unreadable response*; the log names the keys received.

## Authentication — VERIFIED (JWT works on the real server)

| | JWT | General Auth Token |
|---|---|---|
| Method and path | `POST /jwt-api-token-auth/` | `POST /api-token-auth/` (only when no token is entered) |
| Request | JSON `{"username", "password"}` | same |
| Response fields used | `token`; its `exp` claim decides renewal (60 s early; 300 s assumed without `exp`) | `token` (renewed after 12 h or once after 401) |
| Later requests | `Authorization: JWT <token>` | `Authorization: Token <token>` |
| Errors | 400 (wrong credentials, `non_field_errors`), 401, 403 → *authentication* | same |
| Code | `ZkbClient::PATH_JWT_AUTH` | `ZkbClient::PATH_TOKEN_AUTH` |
| Source | BioTime 9.5 API User Manual (ZKTeco Middle East, 16 Aug 2024), 1.1 and 3.1 | same, 1.2 and 3.1 |

## Employees — VERIFIED (423 employees read on the real server)

| | |
|---|---|
| List | `GET /personnel/api/employees/?page=&page_size=` — `ZkbClient::PATH_EMPLOYEES` |
| Filter by code | `emp_code=<code>` — `ZkbClient::PARAM_EMP_CODE`; results are compared **exactly** (a filter may return partial matches). Other documented filters: `first_name`, `last_name`, `department`, `app_status` (not used) |
| One employee | `GET /personnel/api/employees/{id}/` — `ZkbClient::PATH_EMPLOYEE` |
| Used for | Test Connection (`count` only); matching by the Joomla `employeeid` field (filter by code); matching by e-mail (every page, exact compare); manual mapping; daily re-verification |
| Fields read for matching | `id`, `emp_code`, `email` |
| Never read or shown | passwords (`device_password`, `self_password`, a password hash in the documented answer), `card_no`, biometric templates (`fingerprint`, `face`, `palm`, `vl_face`, …), `photo`, national ID / `ssn`, `national`, device data, any field not listed above |
| Source | BioTime 9.5 API User Manual (ZKTeco Middle East, 16 Aug 2024), 5.1 (list) and 5.2 (one employee) |

## Transactions (punches) — VERIFIED

Verified 2026-11-15 on the real server: `GET /iclock/api/transactions/` with `page`, `page_size`,
`emp_code`, `start_time`, `end_time` answered HTTP 200 with the rows under `data` (16 punches for
November, 38 for October for one employee).

Fields seen in the rows (Find Endpoints check, 2026-11-15): `id`, `emp`, `emp_code`, `first_name`,
`last_name`, `department`, `position` (text), `punch_time` (date and time), `punch_state` (0, 255),
`punch_state_display` ("Check In", "Unknown"), `verify_type` (1, 15), `verify_type_display`
("Fingerprint", "Face"), `work_code`, `gps_location`, `area_alias`, `terminal_sn`,
`terminal_alias`, `temperature`, `is_mask`, `upload_time`. The portal reads only `emp_code`,
`punch_time`, `punch_state_display`, `punch_state`, `terminal_alias`, `area_alias` and, since
0.4.0, `verify_type_display` and `terminal_sn` (0.4.2: also `verify_type`).
State 255 ("Unknown") is common: see the check-in/check-out rule in `AttendanceService::checkTimes()`.


| | |
|---|---|
| Method and path | `GET /iclock/api/transactions/` — `ZkbClient::PATH_TRANSACTIONS` |
| Parameters | `emp_code` (the mapped employee), `start_time` and `end_time` (`YYYY-MM-DD HH:MM:SS`, first and last second of the month), `page`, `page_size` |
| Fields read | `emp_code` (ownership check), `punch_time`, `punch_state_display` (state text), `punch_state` (state code, used only without text: 0/4 in, 1/5 out, 2/3 break, ZKTeco convention), `terminal_alias` (device), `area_alias` (area), `verify_type_display` (how the employee was verified, e.g. Fingerprint, Face), `terminal_sn` (device serial number) |
| Used for | All punches of a day; check-in = first punch before 12:00 and check-out = last punch from 12:00 on (the time of day decides, not the recorded state; 0.4.3) **only** when the daily source does not provide them; the time between check-in and check-out; the day's status (below); days with punches; duplicate, incomplete and missing check-in/out exceptions; My Dashboard's averages and the devices used |
| Source | BioTime 9.5 API User Manual (ZKTeco Middle East, 16 Aug 2024), 9.1 (the URI cell there is misspelt `transctions`; its request examples and the real server use `transactions`). Documented filters: `page`, `page_size`, `emp_code`, `terminal_sn`, `start_time`, `end_time`; `punch_state` is a string (`"0"`) |

## Day statuses (from the punches) — portal rule

Without a calculated status from a daily source, each day's status is told from the punches only
(`AttendanceService::dayStatus()`); nothing else is calculated:

| Status | When |
|---|---|
| Present | any punch was recorded; when the check-in or the check-out is missing the day is also tagged **Missing Punch** (counted as present and as a missing-punch day) |
| Checked In / Checked Out | today, after the check-in / after the check-out |
| Weekend | a weekend day (Integration Settings → *Weekend days*, default Friday and Saturday) **without** punches. A weekend day with a punch is treated like any other day, so it shows Present instead |
| Absent | a past working day without any punch |
| — | today or a future working day without punches |

## My Punches — counting only

*My Punches* reads the same transactions as the other pages, one month at a time from January of
the chosen year (2025 or later) up to today, and counts the user's own punches by how they were
verified: `verify_type_display` containing "Face" or "Finger" (else `verify_type` 15 = face,
1 = fingerprint, as seen on the real server; anything else is "Other"), per month and per
`terminal_alias` (with `area_alias`). The device list `/iclock/api/terminals/` is no longer read
(0.4.2: Biometric Devices was replaced by My Punches).

## API description (Find Endpoints) — VERIFIED (CoreJSON)

Verified 2026-11-15 on the real server: `?format=openapi` → 404 (no OpenAPI);
`?format=corejson` → 406 when only `application/json` is accepted. The CoreJSON media type
(`application/coreapi+json`) is therefore requested for that form since 0.3.1. Unknown paths
answer HTTP 200 with a web page, which the portal reports as "not API data".

| | |
|---|---|
| Paths | `/api/docs/` and `/api/personnel_docs/` — `ZkbClient::DOC_PATHS` |
| Forms tried, in order | `?format=openapi` (OpenAPI / Swagger 2), then `?format=corejson` (Django REST framework CoreJSON) — `ZkbClient::DOC_FORMATS`. The next form is tried only after "not found", an unreadable answer or a refused format; the form that worked is logged. |
| Read | GET list endpoints (no `{id}`), their summary and query parameter names |
| Check | `GET <selected path>?page=1&page_size=20&<filters>`; only field names, kinds of values and status-like texts are shown |
| Source | BioTime 9.5 API User Manual (ZKTeco Middle East, 16 Aug 2024) (links `/api/docs/` and `/api/personnel_docs/`); CoreJSON verified on the real server |

## Diagnostics candidates — not on 9.5

The 9.5 server under test lists only personnel endpoints in its API description (10, checked
2026-11-16): no attendance, report or leave endpoint, not even `/iclock/api/transactions/`,
which works. Diagnostics (*Find Endpoints*) therefore also checks these paths, reports only
whether they answer and their field names and kinds, and reads nothing from them otherwise:

| Path | Parameters sent | Source | Status |
|---|---|---|---|
| `/att/api/totalTimeCardReport/` | `start_date`, `end_date` (`YYYY-MM-DD`), `emp_code` when given, `page`, `page_size` | BioTimeCloud API User Manual 1.0 (Aug 2023), "Att API" | **Not in the 9.5 manual; not on the real 9.5 server** (web page instead of data, 2026-11-16) — `ZkbClient::DIAG_ATT_PATHS`, kept for BioTime Cloud and later builds |

## Calculated daily attendance and schedules — CONFIGURED

*Integration Settings → Daily Attendance Source.* The administrator picks a read-only endpoint
(or saved report) from `/api/docs/`. The portal sends `GET <path>?<employee param>=<code>&
<start param>=<first day>&<end param>=<last day>&page=&page_size=` plus any fixed parameters.

| Portal field | Meaning | Required |
|---|---|---|
| `emp_code` | Employee code of the row (ownership check) | yes |
| `date` | Attendance date | yes |
| `schedule` | Schedule / shift / timetable applied **on that date** | |
| `sched_start`, `sched_end` | Scheduled start and end | |
| `break` | Break periods (shown as text) | |
| `schedule_note` | Temporary or changed schedule marker (shown as text) | |
| `check_in`, `check_out` | ZKBioTime's first check-in / last check-out | |
| `late`, `early`, `worked`, `overtime` | Durations (unit set in the settings; `HH:MM` also read) | |
| `status` | ZKBioTime's calculated status, mapped to present / late / early / absent / leave / holiday / rest / incomplete / other | |
| `leave` | Leave on that day (text) | |

Values not mapped show "—". Joomla never calculates them. Rows whose `emp_code` differs from the
mapped employee are dropped and logged.

| Server | Endpoint chosen | Field names | Status |
|---|---|---|---|
| School's ZKBioTime 9.5 | none: BioTime 9.5 has no such API | — | not available |

## Leave — CONFIGURED

*Integration Settings → Leave Source.* Same request form as the daily source.

| Portal field | Meaning | Required |
|---|---|---|
| `emp_code` | Employee code (ownership check) | yes |
| `start`, `end` | Leave dates | `start` |
| `type` | Leave type | |
| `duration` | Duration (unit set in the settings, or shown as text) | |
| `status` | Approval status, mapped to approved / pending / rejected | |

No leave requests and no approval actions: read only.

## List envelope and paging — VERIFIED

| Key | Use |
|---|---|
| `page`, `page_size` (query) | Page number from 1; rows per page (default 100) |
| `count` | Total rows; used when `next` is missing |
| `next` | Non-empty: another page exists |
| `data` | The rows (documented; the manual's example also has an empty `results` next to it). Fallbacks, logged when used: `results`, or a bare JSON list |
| `code`, `msg` | A non-zero `code` without `data` is treated as a refused request |

Reading stops at the last page, at an empty page, or at the safety maximum (default 50 pages);
then the page says results were cut off and a warning is logged.

## Error mapping

| Answer | Kind | Message key |
|---|---|---|
| No connection, DNS, TLS, timeout | network | `COM_BIOTIME_ERROR_NETWORK` |
| 401 (after one renewal) | authentication | `COM_BIOTIME_ERROR_AUTH` |
| 403 | permission | `COM_BIOTIME_ERROR_PERMISSION` |
| 404, 405 | not found | `COM_BIOTIME_ERROR_NOT_FOUND` |
| 400, 422, other 4xx; `code` ≠ 0 | validation | `COM_BIOTIME_ERROR_VALIDATION` |
| 429 | rate limited | `COM_BIOTIME_ERROR_RATE_LIMITED` |
| 5xx (after one retry) | server error | `COM_BIOTIME_ERROR_SERVER` |
| Not JSON, 3xx, unexpected shape | unreadable response | `COM_BIOTIME_ERROR_BAD_RESPONSE` |
| Settings missing or undecryptable | not configured | `COM_BIOTIME_ERROR_NOT_CONFIGURED` |

## Known limitations

- ZKBioTime 9.5 has no API for calculated attendance, schedules or leave (see *What the BioTime
  9.5 API offers*). So the pages show no schedules, late-in, early-out, worked hours or overtime,
  and My Schedules, Attendance Reports and My Profile were removed in 0.4.0. The day statuses are
  the portal's punch rule above, not ZKBioTime's calculated statuses; holidays are not known and
  a public holiday without punches shows as Absent. The time between check-in and check-out is
  the difference of the two punches, not ZKBioTime's worked hours.
- Leave appears only if the installed ZKBioTime offers it in its API and the leave source is set.
- Holidays and rest days are shown only as ZKBioTime's calculated status of a date.
