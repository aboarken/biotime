-- TEST FIXTURE ONLY: the ZKBioTime 9.5 tables the portal reads, with the column names and types
-- from a real server's Test Database report (PostgreSQL 15), and invented sample data.
-- Used by tests/Unit/AttendanceReaderTest.php (BIOTIME_TEST_PG). Never run on a real server.
DROP TABLE IF EXISTS personnel_employee, personnel_department, personnel_position, att_attemployee,
  iclock_transaction, att_payloadbase, att_timeinterval, att_payloadovertime, att_holiday CASCADE;

CREATE TABLE personnel_department (id integer PRIMARY KEY, dept_code varchar(50), dept_name varchar(200));
CREATE TABLE personnel_position (id integer PRIMARY KEY, position_code varchar(50), position_name varchar(100));
CREATE TABLE personnel_employee (id integer PRIMARY KEY, emp_code varchar(20), first_name varchar(100), last_name varchar(100), email varchar(50),
  hire_date date, department_id integer, position_id integer);
CREATE TABLE att_attemployee (id integer PRIMARY KEY, emp_id integer, group_id integer);
CREATE TABLE iclock_transaction (id serial PRIMARY KEY, emp_code varchar(20), punch_time timestamptz, punch_state varchar(5),
  verify_type integer, terminal_sn varchar(50), terminal_alias varchar(50), area_alias varchar(100), emp_id integer);
CREATE TABLE att_timeinterval (id integer PRIMARY KEY, alias varchar(50), in_time time, duration integer);
CREATE TABLE att_payloadovertime (uuid varchar(36) PRIMARY KEY, total_ot integer);
CREATE TABLE att_payloadbase (uuid varchar(36) PRIMARY KEY, att_date date, weekday smallint, check_in timestamptz, check_out timestamptz,
  duration integer, duty_duration integer, work_day double precision, clock_in timestamptz, clock_out timestamptz, total_time integer,
  duty_worked integer, actual_worked integer, unscheduled integer, remaining integer, total_worked integer, late integer,
  early_leave integer, short integer, absent integer, leave integer, exception varchar(50), day_off smallint,
  break_time_id varchar(36), emp_id integer, overtime_id varchar(36), timetable_id integer);
CREATE TABLE att_holiday (id integer PRIMARY KEY, alias varchar(50), start_date date, duration_day smallint, end_date date,
  att_group_id integer, department_id integer);

INSERT INTO personnel_department VALUES (10, 'IT', 'Information Technology'), (11, 'HR', 'Human Resources');
INSERT INTO personnel_position VALUES (20, 'DEV', 'Developer');
INSERT INTO personnel_employee VALUES (1, 'E1', 'Alice', 'A', 'a@example.com', '2024-09-01', 10, 20), (2, 'E2', 'Bob', 'B', 'b@example.com', NULL, 11, NULL);
INSERT INTO att_attemployee VALUES (1, 1, 5);
INSERT INTO att_timeinterval VALUES (100, 'Day 07:30-15:30', '07:30', 480);
-- Server time zone Asia/Riyadh (UTC+3): stored in UTC, shown in local time.
INSERT INTO iclock_transaction (emp_code, punch_time, punch_state, verify_type, terminal_alias, area_alias, emp_id) VALUES
  ('E1', '2026-11-02 07:42:10+03', '0', 15, 'Main Gate', 'HQ', 1),
  ('E1', '2026-11-02 15:05:00+03', '1', 1, 'Main Gate', 'HQ', 1),
  ('E1', '2026-11-03 07:31:00+03', '0', 15, 'Main Gate', 'HQ', 1),
  ('E2', '2026-11-02 07:00:00+03', '0', 1, 'Main Gate', 'HQ', 2),
  ('E1', '2026-10-31 23:59:00+03', '0', 1, 'Main Gate', 'HQ', 1);
INSERT INTO att_payloadovertime VALUES ('ot-1', 1800);
-- Durations in seconds (480 min = 28800 s), as the unit check expects; late 12 min, early 25 min.
INSERT INTO att_payloadbase (uuid, att_date, check_in, check_out, duration, clock_in, clock_out, total_worked, late, early_leave, absent, leave, day_off, emp_id, overtime_id, timetable_id) VALUES
  ('b1', '2026-11-02', '2026-11-02 07:30+03', '2026-11-02 15:30+03', 28800, '2026-11-02 07:42:10+03', '2026-11-02 15:05+03', 26570, 730, 1500, 0, 0, 0, 1, 'ot-1', 100),
  ('b2', '2026-11-04', '2026-11-04 07:30+03', '2026-11-04 15:30+03', 28800, NULL, NULL, 0, 0, 0, 28800, 0, 0, 1, NULL, 100),
  ('b3', '2026-11-06', NULL, NULL, 0, NULL, NULL, 0, 0, 0, 0, 0, 1, 1, NULL, NULL),
  ('b9', '2026-11-02', '2026-11-02 07:30+03', '2026-11-02 15:30+03', 28800, '2026-11-02 07:00+03', NULL, 0, 0, 0, 0, 0, 0, 2, NULL, 100);
INSERT INTO att_holiday VALUES (1, 'National Day', '2026-11-08', 2, NULL, NULL, NULL), (2, 'HR Day', '2026-11-12', 1, '2026-11-12', NULL, 11),
  (3, 'Group Day', '2026-11-15', 1, NULL, 5, NULL);
