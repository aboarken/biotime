# Reading ZKBioTime's database directly

The component reads everything from ZKBioTime's own PostgreSQL database, **read only**: punches,
employees, schedules, ZKBioTime's calculated attendance (late-in, early-out, worked hours,
overtime), leave and holidays. Since **1.0.0** this is the only source: the REST API integration
(server URL, API user, Test Connection, Find Endpoints, Daily Attendance Source) was removed.

0.5.0 added the connection and **Test Database**; 0.6.0 the portal pages read from it; 0.6.1
employees are matched from it too (`personnel_employee`: by the employee code field or by e-mail,
the daily re-check, and User Mappings).

What the pages then show, per day and per month:

| Value | Read from |
|---|---|
| Punches (time, state, device, area, fingerprint or face) | `iclock_transaction` |
| Schedule (timetable name, start and end) | ZKBioTime's calculation (`att_payloadbase` + `att_timeinterval`) where it exists; else the schedules: temporary schedule (`att_tempschedule`, `att_temporaryschedule`), then the employee's shift (`att_attschedule`), the attendance group's (`att_groupschedule`) or the department's (`att_departmentschedule`), with `att_attshift` + `att_shiftdetail` + `att_timeinterval`. So today and later days show their schedule too |
| Check-in and check-out | From the punches: check-in is the first punch before the cut-off, check-out the last punch from it. The cut-off is the end of the day's timetable check-in window (start + `att_timeinterval.in_above_margin`), or 12:00 on days without a timetable |
| Late in, early out, worked time | ZKBioTime's calculation, nothing is calculated by the portal: ZKBioTime 9.5 keeps it in `att_payloadpaycode` (pay codes *Late In* = L, *Early Out* = E, in `minutes`) and `att_payloadattcode` (*Worked Hours*), with the day's timetable in `att_payloadtimecard`; other builds in `att_payloadbase` (`late`, `early_leave`, `total_worked`). A day shows them once ZKBioTime has calculated it (Auto Calculate, e.g. nightly) |
| Leave | Leave pay codes (`att_payloadpaycode`, e.g. *Annual Leave*): the day shows the leave's name instead of Absent |
| Overtime | `att_payloadovertime.total_ot` |
| Absent, day off | `att_payloadbase` (`absent`, `day_off`) |
| Holidays (for everyone, the department or the attendance group) | `att_holiday`, `att_attemployee.group_id` |
| Department and position (My Dashboard) | `personnel_employee`, `personnel_department`, `personnel_position` |

Shift cycles (UNVERIFIED, check with Test Database → Shifts): `cycle_unit` 0 = days (day 0 = the
schedule's start date), 1 = weeks (day 0 = Sunday), 2 = months (day 0 = the 1st). If the schedule
shown for today does not match ZKBioTime's, send the Shifts lines of the Test Database summary.

Monthly totals: total late in, total early out, both together, days late and early, worked time,
overtime, holidays. ZKBioTime does not document the unit of its duration columns: *Duration
unit = Automatic* compares each day's scheduled duration with its start and end time; set it to
Minutes or Seconds only if late-in values look 60 times too large or too small.

Reading from the database needs PostgreSQL (the database installed with ZKBioTime).

## 1. On the ZKBioTime server: a read-only user

ZKBioTime's settings file `attsite.ini` (in the ZKBioTime folder, section `[DATABASE]`) names the
engine, database name and port. With the PostgreSQL installed with ZKBioTime: port `7496`,
database `biotime`.

Back up the database first. Then, in **pgAdmin**: right-click the `biotime` database →
**Query Tool**, paste, choose your own long password, press **F5**:

```sql
CREATE ROLE joomla_portal LOGIN PASSWORD 'choose-a-long-password';
ALTER ROLE joomla_portal SET default_transaction_read_only = on;
GRANT CONNECT ON DATABASE biotime TO joomla_portal;
GRANT USAGE ON SCHEMA public TO joomla_portal;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO joomla_portal;
-- Tables ZKBioTime creates later (e.g. after an upgrade) are readable as well:
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO joomla_portal;
```

If Test Database says *read only by default: false*, the `default_transaction_read_only`
setting did not take: run `ALTER ROLE joomla_portal SET default_transaction_read_only = on;`
as `postgres` (it applies to new connections).

The user can read every table and change nothing: `default_transaction_read_only` refuses every
change, and the component's own connection is read only as well. Which tables the portal reads
is decided in Joomla, not here.

Check it: register the server again in pgAdmin as `joomla_portal`, open the Query Tool on
`biotime` and run `SELECT count(*) FROM iclock_transaction;` (a number) and
`CREATE TABLE test_x (i int);` (must fail: *cannot execute CREATE TABLE in a read-only transaction*).

> All tables include ZKBioTime's own accounts (`auth_user`, with password hashes). The Manager
> Dashboard (1.0.8) needs eight of its columns to follow ZKBioTime's user permissions, never the
> password. Keep the table out and allow only those columns:
>
> ```sql
> REVOKE SELECT ON auth_user FROM joomla_portal;
> GRANT SELECT (id, username, email, emp_pin, is_active, is_superuser, can_manage_all_dept, del_flag) ON auth_user TO joomla_portal;
> ```
>
> Check it as `joomla_portal`: `SELECT password FROM auth_user LIMIT 1;` must fail with
> *permission denied*. `auth_user_auth_dept` (the departments of each user's data permissions)
> is covered by the grant on all tables.

## 2. Network: only the Joomla server, encrypted

- `postgresql.conf` (ZKBioTime's `pgsql\data` folder): `listen_addresses` must include the
  network address (or `'*'`), then restart ZKBioTime's database service.
- `pg_hba.conf`, one line for the Joomla server's outgoing IP address only:
  `hostssl  biotime  joomla_portal  <JOOMLA-IP>/32  scram-sha-256` (older servers: `md5`).
- Firewall: forward the database port **only from the Joomla server's IP**. Never open it to
  the internet.
- Your hosting must allow outgoing connections to that port; ask them for the server's outgoing IP.
- **SSL**: across the internet the connection must be encrypted. PostgreSQL needs `ssl = on` and a
  certificate (`ssl_cert_file`, `ssl_key_file`). Copy the certificate (or its CA) to the Joomla
  server for *Verify*; without it choose *Require* (encrypted, certificate not checked).

## 3. In Joomla

*Integration Settings → ZKBioTime Database*:

| Field | Example | Notes |
|---|---|---|
| Database | PostgreSQL | |
| Server | `zkb-server.example.local:7496` | Host and port in one field. With *Verify*: the name on the database certificate. |
| Connect via | `203.0.113.10:7496` | Optional: the firewall's **public IP** and port that Joomla really connects to; the certificate is still checked against *Server*. An IP address only (PostgreSQL cannot take a name here). Empty: Joomla connects to *Server* directly, so then enter the public address there. |
| Database name | `biotime` | |
| User / Password | `joomla_portal` | |
| Encryption | Verify / Require | *Verify* needs the certificate (or CA) file on the Joomla server. |

So the local name goes in *Server* and the public address in *Connect via*,
or simply the public address in *Server* when the certificate has that name or with *Require*.
Changing *Server* or *Connect via* asks for the password again.

Save, then **Test Database**. The report shows:

- server version, whether the connection is encrypted;
- whether the user is an administrator, read only by default, or may change any table;
- the punch, employee, device, department, position and area tables, readable or not;
- every attendance (`att_…`), personnel and device table with its column names;
- a summary to copy (names only) for the next step.

The PHP extension `pdo_pgsql` (PostgreSQL) or `pdo_sqlsrv` (SQL Server) must be enabled on the
Joomla server (*System → System Information → PHP Information*).

SQL Server is supported by the test but UNVERIFIED on a real ZKBioTime server.
