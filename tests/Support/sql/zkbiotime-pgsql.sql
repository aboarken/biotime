-- TEST FIXTURE ONLY: the ZKBioTime 9.5 tables the portal reads, with the column names and types
-- from a real server's Test Database report (PostgreSQL 15), and invented sample data.
-- Used by tests/Unit/AttendanceReaderTest.php (BIOTIME_TEST_PG). Never run on a real server.
DROP TABLE IF EXISTS personnel_employee, personnel_department, personnel_position, att_attemployee,
  iclock_transaction, att_payloadbase, att_timeinterval, att_payloadovertime, att_holiday, att_attshift, att_shiftdetail,
  att_attschedule, att_groupschedule, att_departmentschedule, att_temporaryschedule, att_tempschedule,
  att_payloadtimecard, att_payloadpaycode, att_payloadattcode CASCADE;

CREATE TABLE personnel_department (id integer PRIMARY KEY, dept_code varchar(50), dept_name varchar(200));
CREATE TABLE personnel_position (id integer PRIMARY KEY, position_code varchar(50), position_name varchar(100));
CREATE TABLE personnel_employee (id integer PRIMARY KEY, emp_code varchar(20), first_name varchar(100), last_name varchar(100), email varchar(50),
  hire_date date, department_id integer, position_id integer);
CREATE TABLE att_attemployee (id integer PRIMARY KEY, emp_id integer, group_id integer);
CREATE TABLE iclock_transaction (id serial PRIMARY KEY, emp_code varchar(20), punch_time timestamptz, punch_state varchar(5),
  verify_type integer, terminal_sn varchar(50), terminal_alias varchar(50), area_alias varchar(100), emp_id integer);
CREATE TABLE att_timeinterval (id integer PRIMARY KEY, alias varchar(50), in_time time, in_ahead_margin integer, in_above_margin integer,
  out_ahead_margin integer, out_above_margin integer, duration integer, allow_late integer, allow_leave_early integer);
CREATE TABLE att_attshift (id integer PRIMARY KEY, alias varchar(50), cycle_unit smallint, shift_cycle integer);
CREATE TABLE att_shiftdetail (id integer PRIMARY KEY, in_time time, out_time time, day_index integer, shift_id integer, time_interval_id integer);
CREATE TABLE att_attschedule (id integer PRIMARY KEY, start_date date, end_date date, employee_id integer, shift_id integer);
CREATE TABLE att_groupschedule (id integer PRIMARY KEY, status smallint, start_date date, end_date date, group_id integer, shift_id integer);
CREATE TABLE att_departmentschedule (id integer PRIMARY KEY, status smallint, start_date date, end_date date, department_id integer, shift_id integer);
CREATE TABLE att_temporaryschedule (id integer PRIMARY KEY, status smallint, att_date date, employee_id integer, time_interval_id integer);
CREATE TABLE att_tempschedule (id integer PRIMARY KEY, att_date date, start_time timestamptz, end_time timestamptz, rule_flag smallint,
  work_type smallint, employee_id integer, time_interval_id integer);
CREATE TABLE att_payloadovertime (uuid varchar(36) PRIMARY KEY, total_ot integer);
CREATE TABLE att_payloadbase (uuid varchar(36) PRIMARY KEY, att_date date, weekday smallint, check_in timestamptz, check_out timestamptz,
  duration integer, duty_duration integer, work_day double precision, clock_in timestamptz, clock_out timestamptz, total_time integer,
  duty_worked integer, actual_worked integer, unscheduled integer, remaining integer, total_worked integer, late integer,
  early_leave integer, short integer, absent integer, leave integer, exception varchar(50), day_off smallint,
  break_time_id varchar(36), emp_id integer, overtime_id varchar(36), timetable_id integer);
-- ZKBioTime 9.5 keeps its calculation here (att_payloadbase stays empty on the real server).
CREATE TABLE att_payloadtimecard (id uuid PRIMARY KEY, att_date date, week integer, weekday integer, date_type smallint,
  time_table_alias varchar(50), check_in timestamptz, check_out timestamptz, work_day numeric(4,1), clock_in timestamptz,
  clock_out timestamptz, present smallint, full_attendance smallint, payload text, emp_id integer, time_table_id integer);
CREATE TABLE att_payloadpaycode (id uuid PRIMARY KEY, att_date date, pay_code_alias varchar(50), pay_code_symbol varchar(20),
  duration integer, minutes numeric(8,1), emp_id integer, pay_code_id integer);
CREATE TABLE att_payloadattcode (id uuid PRIMARY KEY, att_date date, att_code_alias varchar(50), att_code_symbol varchar(20),
  duration integer, minutes numeric(8,1), emp_id integer, att_code_id integer);
CREATE TABLE att_holiday (id integer PRIMARY KEY, alias varchar(50), start_date date, duration_day smallint, end_date date,
  att_group_id integer, department_id integer);

INSERT INTO personnel_department VALUES (10, 'IT', 'Information Technology'), (11, 'HR', 'Human Resources');
INSERT INTO personnel_position VALUES (20, 'DEV', 'Developer');
INSERT INTO personnel_employee VALUES (1, 'E1', 'Alice', 'A', 'a@example.com', '2024-09-01', 10, 20), (2, 'E2', 'Bob', 'B', 'b@example.com', NULL, 11, NULL);
INSERT INTO att_attemployee VALUES (1, 1, 5);
INSERT INTO att_timeinterval VALUES (100, 'Day 07:30-15:30', '07:30', 60, 240, 240, 240, 480, 5, 5),
  (101, 'Exam duty', '09:00', 30, 60, 60, 60, 240, 0, 0);
-- A weekly shift Sunday to Thursday (day_index 0-4) for Alice from Sunday 1 November; Bob's department works Saturdays too.
INSERT INTO att_attshift VALUES (7, 'Week Sun-Thu', 1, 1), (8, 'HR week', 1, 1);
INSERT INTO att_shiftdetail VALUES (1, NULL, NULL, 0, 7, 100), (2, NULL, NULL, 1, 7, 100), (3, NULL, NULL, 2, 7, 100), (4, NULL, NULL, 3, 7, 100),
  (5, NULL, NULL, 4, 7, 100), (6, NULL, NULL, 6, 8, 100);
INSERT INTO att_attschedule VALUES (1, '2026-11-01', '2026-12-31', 1, 7);
INSERT INTO att_departmentschedule VALUES (1, 0, '2026-01-01', '2026-12-31', 11, 8);
INSERT INTO att_tempschedule VALUES (1, '2026-11-23', '2026-11-23 09:00+03', '2026-11-23 13:00+03', 0, 0, 1, 101);
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

-- Tuesday 3 November as ZKBioTime 9.5 calculates it: time card, Late In 7 minutes (420 s), Early Out 0, worked hours;
-- 24 November Annual Leave.
INSERT INTO att_timeinterval VALUES (102, 'IN07:30-OUT15:30', '07:30', 60, 240, 240, 240, 480, 0, 0);
INSERT INTO att_payloadtimecard (id, att_date, time_table_alias, check_in, check_out, emp_id, time_table_id) VALUES
  ('00000000-0000-0000-0000-000000000003', '2026-11-03', 'IN07:30-OUT15:30', '2026-11-03 07:30+03', '2026-11-03 15:30+03', 1, 102);
INSERT INTO att_payloadpaycode VALUES
  ('00000000-0000-0000-0000-000000000031', '2026-11-03', 'Late In', 'L', 420, 7.0, 1, 1),
  ('00000000-0000-0000-0000-000000000032', '2026-11-03', 'Early Out', 'E', 0, 0.0, 1, 2),
  ('00000000-0000-0000-0000-000000000033', '2026-11-24', 'Annual Leave', NULL, 28800, 480.0, 1, 3),
  ('00000000-0000-0000-0000-000000000039', '2026-11-03', 'Late In', 'L', 3600, 60.0, 2, 1);
INSERT INTO att_payloadattcode VALUES
  ('00000000-0000-0000-0000-000000000034', '2026-11-03', 'Worked Hours', NULL, 26400, 440.0, 1, 1);
