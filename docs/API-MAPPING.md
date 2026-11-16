# ZKBioTime API endpoint and field mapping

Every ZKBioTime call the component makes, with the fields it reads and its source. Fixed
endpoint paths are named in exactly one place: the constants at the top of
`administrator/src/Api/ZkbClient.php`. Field names read from transactions are constants in
`administrator/src/Attendance/AttendanceService.php`; profile fields in
`administrator/src/Profile/ProfileService.php`.

**Status key**

- **VERIFIED**: confirmed against a real ZKBioTime 9.5 server (build number recorded below).
- **UNVERIFIED**: taken from the ZKBioTime API User Manual, not yet confirmed on the real
  server. Marked `UNVERIFIED` in the code too.
- **CONFIGURED**: no fixed endpoint in the code; the administrator enters the path, parameter
  names and field names from the server's `/api/docs/` page in Integration Settings.

## Server under test

| Item | Value |
|---|---|
| ZKBioTime version | 9.5 |
| Server | the school's ZKBioTime (port 5555), first checked 2026-11-15 from the administrator's log |
| Build number | **not recorded yet** (ZKBioTime *System → About*) |
| `/api/docs/` export received | no |
| `/api/personnel_docs/` export received | no |
| Sample JSON responses received | no |

## How this version was written

The build environment could reach neither the ZKBioTime server nor the API manual. So every
fixed endpoint below is **UNVERIFIED**, and the parts the published manual does not cover
(calculated attendance, per-day schedules, leave) are **CONFIGURED** by the administrator
instead of guessed. The client makes the first real connection tell us what is different:

- the list envelope is read as documented (`data`); `results` or a bare list are accepted **and
  logged**, so this document can be corrected;
- unknown or missing fields are never filled in: they show "—";
- an unreadable answer is the error *unreadable response*; the log names the keys received.

## Authentication — VERIFIED (login works; method not yet recorded)

| | JWT | General Auth Token |
|---|---|---|
| Method and path | `POST /jwt-api-token-auth/` | `POST /api-token-auth/` (only when no token is entered) |
| Request | JSON `{"username", "password"}` | same |
| Response fields used | `token`; its `exp` claim decides renewal (60 s early; 300 s assumed without `exp`) | `token` (renewed after 12 h or once after 401) |
| Later requests | `Authorization: JWT <token>` | `Authorization: Token <token>` |
| Errors | 400 (wrong credentials, `non_field_errors`), 401, 403 → *authentication* | same |
| Code | `ZkbClient::PATH_JWT_AUTH` | `ZkbClient::PATH_TOKEN_AUTH` |
| Source | ZKBioTime API User Manual, Authentication | same |

## Employees — UNVERIFIED

| | |
|---|---|
| List | `GET /personnel/api/employees/?page=&page_size=` — `ZkbClient::PATH_EMPLOYEES` |
| Filter by code | `emp_code=<code>` — `ZkbClient::PARAM_EMP_CODE`; results are compared **exactly** (a filter may return partial matches) |
| One employee | `GET /personnel/api/employees/{id}/` — `ZkbClient::PATH_EMPLOYEE` |
| Used for | Test Connection (`count` only); matching by the Joomla `employeeid` field (filter by code); matching by e-mail (every page, exact compare); manual mapping; daily re-verification; My Profile |
| Fields read for matching | `id`, `emp_code`, `email` |
| Fields read for My Profile (allow-list) | `emp_code`, `first_name`, `last_name`, `department.dept_name`, `position.position_name`, `area[].area_name`, `email`, `hire_date` |
| Never read or shown | passwords, `device_password`, card numbers, biometric templates (`fingerprint`, `face`, …), national ID / `ssn`, device data, any field not listed above |
| Source | ZKBioTime API User Manual, Personnel → Employee (to be confirmed on `/api/personnel_docs/`) |

## Transactions (punches) — VERIFIED

Verified 2026-11-15 on the real server: `GET /iclock/api/transactions/` with `page`, `page_size`,
`emp_code`, `start_time`, `end_time` answered HTTP 200 with the rows under `data` (16 punches for
November, 38 for October for one employee).

Fields seen in the rows (Find Endpoints check, 2026-11-15): `id`, `emp`, `emp_code`, `first_name`,
`last_name`, `department`, `position` (text), `punch_time` (date and time), `punch_state` (0, 255),
`punch_state_display` ("Check In", "Unknown"), `verify_type` (1, 15), `verify_type_display`
("Fingerprint", "Face"), `work_code`, `gps_location`, `area_alias`, `terminal_sn`,
`terminal_alias`, `temperature`, `is_mask`, `upload_time`. The portal reads only `emp_code`,
`punch_time`, `punch_state_display`, `punch_state`, `terminal_alias` and `area_alias`.
State 255 ("Unknown") is common: see the check-in/check-out rule in `AttendanceService::checkTimes()`.


| | |
|---|---|
| Method and path | `GET /iclock/api/transactions/` — `ZkbClient::PATH_TRANSACTIONS` |
| Parameters | `emp_code` (the mapped employee), `start_time` and `end_time` (`YYYY-MM-DD HH:MM:SS`, first and last second of the month), `page`, `page_size` |
| Fields read | `emp_code` (ownership check), `punch_time`, `punch_state_display` (state text), `punch_state` (state code, used only without text: 0/4 in, 1/5 out, 2/3 break, ZKTeco convention), `terminal_alias` (device), `area_alias` (area) |
| Used for | All punches of a day; check-in = first check-in punch and check-out = last check-out punch **only** when the daily source does not provide them (break punches ignored; without states: first and last of two or more punches); days with punches; duplicate, incomplete and missing check-in/out exceptions |
| Source | ZKBioTime API User Manual, Transaction |

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
| Source | ZKBioTime administrator's manual (pages `/api/docs/` and `/api/personnel_docs/`); format to be confirmed on the 9.5 server |

## Diagnostics candidates — UNVERIFIED

The 9.5 server under test lists only personnel endpoints in its API description (10, checked
2026-11-16): no attendance, report or leave endpoint, not even `/iclock/api/transactions/`,
which works. Diagnostics (*Find Endpoints*) therefore also checks these paths, reports only
whether they answer and their field names and kinds, and reads nothing from them otherwise:

| Path | Parameters sent | Source | Status |
|---|---|---|---|
| `/att/api/totalTimeCardReport/` | `start_date`, `end_date` (`YYYY-MM-DD`), `emp_code` when given, `page`, `page_size` | BioTimeCloud API User Manual 1.0 (Aug 2023), "Att API" | UNVERIFIED on 9.5 — `ZkbClient::DIAG_ATT_PATHS` |

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
| (record here) | | | not configured |

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

## List envelope and paging — UNVERIFIED

| Key | Use |
|---|---|
| `page`, `page_size` (query) | Page number from 1; rows per page (default 100) |
| `count` | Total rows; used when `next` is missing |
| `next` | Non-empty: another page exists |
| `data` | The rows (documented). Fallbacks, logged when used: `results`, or a bare JSON list |
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

- Until the daily source is configured: no schedules, statuses, late-in, early-out, worked hours
  or overtime ("—"), so the Dashboard shows only punch-based figures and My Schedules says
  schedules are not available.
- Leave appears only if the installed ZKBioTime offers it in its API and the leave source is set.
- Holidays and rest days are shown only as ZKBioTime's calculated status of a date.
