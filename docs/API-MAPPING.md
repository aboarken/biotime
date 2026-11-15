# ZKBioTime API endpoint and field mapping

Every ZKBioTime call the component makes is listed here, with its source. All paths are named in
exactly one place in the code: the constants at the top of
`administrator/src/Api/ZkbClient.php`.

**Status key**

- **VERIFIED** – confirmed against a real ZKBioTime 9.5 server (build number recorded below).
- **UNVERIFIED** – taken from the ZKBioTime API User Manual, not yet confirmed on the real server.
  Marked `UNVERIFIED` in the code too.

## Server under test

| Item | Value |
|---|---|
| ZKBioTime version | 9.5 |
| Build number | **not recorded yet** – administrator: *System → About* in ZKBioTime |
| `/api/docs/` export received | no |
| `/api/personnel_docs/` export received | no |
| Sample JSON responses received | no |

## How this version was written

The development environment could not reach the ZKBioTime server, and could not download the
API manual (`https://www.zkteco.sa/download-file/3198` is blocked from the build environment).
So **every endpoint below is UNVERIFIED**. The client is written so that the first real
connection tells us what is different:

- the login, paging and list envelope are read as the manual describes; a list under `results`
  instead of `data`, or a bare list, is accepted **and logged**, so this document can be corrected;
- unknown or missing fields are never filled in; an unreadable answer is the error
  *unreadable response* and the log names the keys that were received;
- *Test Connection* reports which step failed (login or employee read).

## Calls used in 0.1.0 (Stage 1: connection and authentication)

### 1. JWT login — UNVERIFIED

| | |
|---|---|
| Method and path | `POST /jwt-api-token-auth/` |
| Request | JSON body `{"username": "…", "password": "…"}`, `Content-Type: application/json` |
| Response fields used | `token` (string). Its `exp` claim, when present, decides when the token is renewed (60 s early). Without `exp`, a 300 s lifetime is assumed. |
| Later requests | header `Authorization: JWT <token>` |
| Errors | HTTP 400 (wrong credentials, e.g. `non_field_errors`), 401 or 403 → *authentication* |
| Source | ZKBioTime API User Manual, Authentication (not accessible from the build environment; to be confirmed on `/api/docs/`) |
| Code | `ZkbClient::PATH_JWT_AUTH` |

### 2. General Auth Token login — UNVERIFIED

| | |
|---|---|
| Method and path | `POST /api-token-auth/` (only when no token is entered in Integration Settings) |
| Request | JSON body `{"username": "…", "password": "…"}` |
| Response fields used | `token` |
| Later requests | header `Authorization: Token <token>` |
| Expiry | none reported; renewed after 12 h, or once after an *unauthorised* answer. A token entered by hand is never renewed. |
| Source | ZKBioTime API User Manual, Authentication (to be confirmed) |
| Code | `ZkbClient::PATH_TOKEN_AUTH` |

### 3. Employee list (connection test only) — UNVERIFIED

| | |
|---|---|
| Method and path | `GET /personnel/api/employees/?page=1&page_size=1` |
| Parameters | `page`, `page_size` |
| Response fields used | `count` only. No employee field is read, stored, shown or logged in 0.1.0. |
| Source | ZKBioTime API User Manual, Personnel → Employee (to be confirmed on `/api/personnel_docs/`) |
| Code | `ZkbClient::PATH_EMPLOYEES` |

### List envelope and paging (all list endpoints) — UNVERIFIED

| Key | Use |
|---|---|
| `page`, `page_size` (query) | page number from 1; rows per page (Integration Settings, default 100) |
| `count` | total rows; used when `next` is missing |
| `next` | non-empty → another page exists |
| `data` | the rows (documented). Fallbacks, logged when used: `results`, or a bare JSON list. |
| `code`, `msg` | a non-zero `code` without `data` is treated as a refused request |

Reading stops at the last page, at an empty page, or at the safety maximum (Integration
Settings, default 50 pages). When the safety maximum stops it, the result is marked as cut off
and a warning is logged.

### Error mapping

| Answer | Kind | Message key |
|---|---|---|
| no connection, DNS, TLS, timeout | network | `COM_BIOTIME_ERROR_NETWORK` |
| 401 (after one renewal) | authentication | `COM_BIOTIME_ERROR_AUTH` |
| 403 | permission | `COM_BIOTIME_ERROR_PERMISSION` |
| 404, 405 | not found | `COM_BIOTIME_ERROR_NOT_FOUND` |
| 400, 422, other 4xx; `code` ≠ 0 | validation | `COM_BIOTIME_ERROR_VALIDATION` |
| 429 | rate limited | `COM_BIOTIME_ERROR_RATE_LIMITED` |
| 5xx (after one retry) | server error | `COM_BIOTIME_ERROR_SERVER` |
| not JSON, 3xx, unexpected shape | unreadable response | `COM_BIOTIME_ERROR_BAD_RESPONSE` |
| settings missing or undecryptable | not configured | `COM_BIOTIME_ERROR_NOT_CONFIGURED` |

## Data needed by later stages — endpoints not chosen yet

Nothing below is called by the code. Each row is filled in from the administrator's
`/api/docs/` and `/api/personnel_docs/` exports and one sample response per endpoint
(personal data removed), then marked VERIFIED after the stage is checked on the real server.
If ZKBioTime cannot provide a value, the portal shows "—" and the limitation is written here.

| Stage | Feature | Values needed | Endpoint | Status |
|---|---|---|---|---|
| 2 | Employee mapping | employee `id`, `emp_code`, e-mail; search by e-mail and by `emp_code` | Personnel → Employee (path above; filter parameters to confirm) | to confirm |
| 3 | Transactions | punch time, punch state, device/terminal and area name, filtered by employee and date range | Transaction API (named in the manual; path and filters to confirm) | to confirm |
| 4 | Schedules | schedule/shift applied **per date**, start/end, breaks, rest days, holidays, temporary schedules | to identify on `/api/docs/` (or a saved ZKBioTime report chosen by the administrator) | unknown |
| 5 | Calculated attendance | per-day status, late-in, early-out, worked hours, overtime, absence, leave | to identify on `/api/docs/` (or a saved report) | unknown |
| 5 | Leave | approved/pending/rejected leave, dates, type, duration | to identify on `/api/docs/` | unknown |
| 6 | Profile | employee code, name, department, position, area, e-mail, hire date | Personnel → Employee, Department, Position, Area | to confirm |

Fields that will **never** be read or shown: passwords, device passwords, biometric templates,
card numbers, national ID numbers, device credentials, and any field not listed in this file.
