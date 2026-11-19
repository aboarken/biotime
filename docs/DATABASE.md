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
| Leave requests (My Leaves, 1.1.0) | `workflow_workflowinstance` (the employee and the overall status) with `att_leave` (dates, days, reason, attachment's file name, leave type = `att_paycode`); each approval stage from `workflow_nodeinstance` (order, status, who decided, when, remark; `active` = the stage the request is at). Status 0 = pending, 1 = approved, 2 = rejected, 3 = revoked (UNVERIFIED: compare with ZKBioTime's own screen) |
| Requests to approve (My Approvals, 1.1.0) | Requests whose current stage (`workflow_nodeinstance.active`, not decided yet) lists one of the employee's approver roles (`workflow_workflownode_approver` + `personnel_employee_flow_role`) or was sent to them (`targeted`, `approver_employee_id`); never their own. Decided by them: stages with their `approver_employee_id` and an `approval_time` |

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
```

Then let it read **only the tables the portal uses** (1.0.10). ZKBioTime's other tables hold
login sessions, API tokens and settings the portal never needs, and ZKBioTime's user accounts
(`auth_user`) hold password hashes: of those, only eight columns are readable, never the password.

```sql
GRANT SELECT ON personnel_employee, personnel_department, personnel_position, personnel_resign,
  iclock_transaction, iclock_terminal, iclock_terminalemployee,
  att_attemployee, att_payloadbase, att_payloadtimecard, att_payloadpaycode, att_payloadattcode, att_payloadovertime,
  att_timeinterval, att_holiday, att_attshift, att_shiftdetail, att_attschedule, att_groupschedule,
  att_departmentschedule, att_temporaryschedule, att_tempschedule, auth_user_auth_dept
  TO joomla_portal;
GRANT SELECT (id, username, email, emp_pin, is_active, is_superuser, can_manage_all_dept, del_flag) ON auth_user TO joomla_portal;
```

**My Leaves and My Approvals** (1.1.0) also read the leave requests and their approval stages.
Still read only: requests are made and approved in ZKBioTime.

```sql
GRANT SELECT ON att_leave, att_paycode, workflow_workflowinstance, workflow_nodeinstance,
  workflow_workflownode_approver, personnel_employee_flow_role
  TO joomla_portal;
```

If PostgreSQL answers *relation "…" does not exist* (another ZKBioTime version), remove that
name from the list and run it again.

**Already set up with "all tables"** (before 1.0.10)? Narrow it, as `postgres`:

```sql
REVOKE SELECT ON ALL TABLES IN SCHEMA public FROM joomla_portal;
ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE SELECT ON TABLES FROM joomla_portal;
```

then run the `GRANT` statements above.

If Test Database says *read only by default: false*, the `default_transaction_read_only`
setting did not take: run `ALTER ROLE joomla_portal SET default_transaction_read_only = on;`
as `postgres` (it applies to new connections).

The user can read only those tables and change nothing: `default_transaction_read_only` refuses
every change, the component's connection is read only as well, and it sends nothing but `SELECT`.

Check it: register the server again in pgAdmin as `joomla_portal`, open the Query Tool on
`biotime` and run:

- `SELECT count(*) FROM iclock_transaction;`: a number;
- `CREATE TABLE test_x (i int);`: must fail (*cannot execute CREATE TABLE in a read-only transaction*);
- `SELECT password FROM auth_user LIMIT 1;`: must fail (*permission denied*);
- `SELECT count(*) FROM django_session;` (or any table not in the list): must fail (*permission denied*).

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
