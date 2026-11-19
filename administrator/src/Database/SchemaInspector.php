<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Database;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Test Database: what the portal's database user can see, from the database's own catalogue: the
 * server version, whether the connection is encrypted, what the user is allowed to do, and the
 * names of tables and columns. The only rows read are settings without personal data: the shift
 * definitions (name, cycle, timetable per day number) and how many days ZKBioTime calculated in
 * the last 31 days (a count), to check the schedules the portal works out (0.6.2).
 *
 * Table names are those of ZKBioTime 9.5 as far as known (UNVERIFIED until checked on a real
 * server): the report lists what the server really has, so the next steps use real names.
 */
final class SchemaInspector
{
    /** Tables the portal will read first, and what for. */
    public const EXPECTED = [
        'iclock_transaction'   => 'punches',
        'personnel_employee'   => 'employees',
        'iclock_terminal'      => 'devices',
        'personnel_department' => 'departments',
        'personnel_position'   => 'positions',
        'personnel_area'       => 'areas',
        // My Leaves and My Approvals (1.1.0)
        'att_leave'            => 'leaves',
        'workflow_nodeinstance' => 'approvals',
    ];

    /** Tables whose columns are listed: attendance (schedules, time cards, leave, holidays), people, devices. */
    public const PREFIXES = ['att_', 'personnel_', 'iclock_', 'workflow_'];

    private const MAX_TABLES  = 600;
    private const MAX_COLUMNS = 120;

    /**
     * @return array{
     *     version: string,
     *     ssl: ?bool,
     *     ssl_detail: string,
     *     superuser: ?bool,
     *     role_read_only: ?bool,
     *     can_create: ?bool,
     *     writable: list<string>,
     *     safe: bool,
     *     tables: list<array{name: string, readable: bool, columns: list<array{0: string, 1: string}>}>,
     *     others: list<string>,
     *     expected: list<array{name: string, use: string, found: bool, readable: bool}>,
     *     hidden_names: bool
     * }
     *
     * @throws DbException
     */
    public function inspect(Query $q): array
    {
        $report = $q->engine === DbConfig::ENGINE_SQLSRV ? $this->sqlsrv($q) : $this->pgsql($q);

        $found = [];

        foreach ($report['all'] as $table) {
            $found[$table['name']] = $table;
        }

        $tables = [];
        $others = [];

        foreach ($report['all'] as $table) {
            if (\count($tables) >= self::MAX_TABLES) {
                break;
            }

            $listed = isset(self::EXPECTED[$table['name']]);

            foreach (self::PREFIXES as $prefix) {
                $listed = $listed || str_starts_with($table['name'], $prefix);
            }

            if (!$listed) {
                $others[] = $table['name'];

                continue;
            }

            $tables[] = [
                'name'     => $table['name'],
                'readable' => $table['readable'],
                'columns'  => \array_slice($report['columns']($table), 0, self::MAX_COLUMNS),
            ];
        }

        $expected = [];

        foreach (self::EXPECTED as $name => $use) {
            $expected[] = ['name' => $name, 'use' => $use, 'found' => isset($found[$name]), 'readable' => (bool) ($found[$name]['readable'] ?? false)];
        }

        $writable = array_values(array_map(static fn (array $t): string => $t['name'], array_filter($report['all'], static fn (array $t): bool => $t['writable'])));

        return [
            'version'        => $report['version'],
            'ssl'            => $report['ssl'],
            'ssl_detail'     => $report['ssl_detail'],
            'superuser'      => $report['superuser'],
            'role_read_only' => $report['role_read_only'],
            'can_create'     => $report['can_create'],
            'writable'       => $writable,
            // Safe: not an administrator and allowed to change no table.
            'safe'           => $report['superuser'] === false && $writable === [],
            'tables'         => $tables,
            'others'         => $others,
            'expected'       => $expected,
            'hidden_names'   => $report['hidden_names'],
            'shifts'         => $q->engine === DbConfig::ENGINE_PGSQL ? $this->shifts($q) : [],
            'calculated_days' => $q->engine === DbConfig::ENGINE_PGSQL ? $this->calculatedDays($q) : null,
        ];
    }

    /**
     * Shift definitions (no personal data): name, cycle unit and length, and the timetable on each
     * day number of the cycle. At most 50 shifts.
     *
     * @return list<array{name: string, unit: int, cycle: int, days: string}>
     */
    private function shifts(Query $q): array
    {
        try {
            $rows = $q->rows(
                "SELECT sh.alias, sh.cycle_unit, sh.shift_cycle,
                        COALESCE(string_agg(d.day_index || '=' || COALESCE(t.alias, '?'), ', ' ORDER BY d.day_index), '') AS days
                   FROM att_attshift sh
                   LEFT JOIN att_shiftdetail d ON d.shift_id = sh.id
                   LEFT JOIN att_timeinterval t ON t.id = d.time_interval_id
                  GROUP BY sh.id, sh.alias, sh.cycle_unit, sh.shift_cycle
                  ORDER BY sh.id
                  LIMIT 50"
            );
        } catch (DbException) {
            return [];
        }

        return array_map(static fn (array $r): array => [
            'name'  => mb_substr(trim(strip_tags((string) $r['alias'])), 0, 50),
            'unit'  => (int) $r['cycle_unit'],
            'cycle' => (int) $r['shift_cycle'],
            'days'  => mb_substr(trim(strip_tags((string) $r['days'])), 0, 600),
        ], $rows);
    }

    /** How many employee-days ZKBioTime calculated in the last 31 days (a count only), or null. */
    private function calculatedDays(Query $q): ?int
    {
        // ZKBioTime 9.5 keeps its calculation in the time cards; other builds in att_payloadbase.
        $counts = [];

        foreach (['att_payloadtimecard', 'att_payloadbase'] as $table) {
            try {
                $counts[] = (int) $q->value("SELECT COUNT(*) FROM {$table} WHERE att_date >= CURRENT_DATE - 31");
            } catch (DbException) {
                // Not on this server.
            }
        }

        return $counts === [] ? null : max($counts);
    }

    /**
     * @return array<string, mixed>
     */
    private function pgsql(Query $q): array
    {
        $version = (string) $q->value('SHOW server_version');

        // pg_stat_ssl exists since PostgreSQL 9.5.
        try {
            $ssl = $q->rows('SELECT ssl, version FROM pg_stat_ssl WHERE pid = pg_backend_pid()')[0] ?? null;
        } catch (DbException) {
            $ssl = null;
        }

        $role = $q->rows("SELECT rolsuper, rolcreaterole, rolcreatedb, COALESCE(array_to_string(rolconfig, ','), '') AS config FROM pg_roles WHERE rolname = current_user")[0] ?? [];

        // The read-only default can also be set for this database only (ALTER ROLE … IN DATABASE,
        // or pgAdmin's Parameters tab with a database chosen): that setting is in pg_db_role_setting.
        try {
            $perDb = (string) $q->value(
                "SELECT COALESCE(string_agg(array_to_string(s.setconfig, ','), ','), '')
                   FROM pg_db_role_setting s
                  WHERE s.setrole = (SELECT oid FROM pg_roles WHERE rolname = current_user)
                    AND s.setdatabase = (SELECT oid FROM pg_database WHERE datname = current_database())"
            );
        } catch (DbException) {
            $perDb = '';
        }

        $create = $q->value("SELECT has_database_privilege(current_database(), 'CREATE') OR has_schema_privilege(current_schema(), 'CREATE')");

        // Names only, from the catalogue: visible even for tables the user may not read.
        $rows = $q->rows(
            "SELECT c.oid AS id, c.relname AS name,
                    has_table_privilege(c.oid, 'SELECT') AS readable,
                    has_table_privilege(c.oid, 'INSERT, UPDATE, DELETE, TRUNCATE') AS writable
               FROM pg_class c
               JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE n.nspname = current_schema() AND c.relkind IN ('r', 'p', 'v', 'm')
              ORDER BY c.relname"
        );

        $all = array_map(static fn (array $r): array => [
            'id'       => (int) $r['id'],
            'name'     => (string) $r['name'],
            'readable' => self::bool($r['readable']),
            'writable' => self::bool($r['writable']),
        ], $rows);

        $columns = static fn (array $t): array => array_map(
            static fn (array $c): array => [(string) $c['name'], (string) $c['type']],
            $q->rows(
                'SELECT a.attname AS name, format_type(a.atttypid, a.atttypmod) AS type
                   FROM pg_attribute a
                  WHERE a.attrelid = :id AND a.attnum > 0 AND NOT a.attisdropped
                  ORDER BY a.attnum',
                [':id' => $t['id']]
            )
        );

        return [
            'version'        => $version,
            'ssl'            => $ssl === null ? null : self::bool($ssl['ssl']),
            'ssl_detail'     => $ssl !== null && self::bool($ssl['ssl']) ? (string) $ssl['version'] : '',
            'superuser'      => $role === [] ? null : (self::bool($role['rolsuper']) || self::bool($role['rolcreaterole'])),
            'role_read_only' => $role === [] ? null : str_contains((string) $role['config'] . ',' . $perDb, 'default_transaction_read_only=on'),
            'can_create'     => self::bool($create) || ($role !== [] && self::bool($role['rolcreatedb'])),
            'all'            => $all,
            'columns'        => $columns,
            'hidden_names'   => false,
        ];
    }

    /**
     * SQL Server. UNVERIFIED on a real ZKBioTime server. Its catalogue shows only the tables the user
     * has a permission on, so tables not granted yet are not listed (hidden_names).
     *
     * @return array<string, mixed>
     */
    private function sqlsrv(Query $q): array
    {
        $version = (string) $q->value("SELECT CAST(SERVERPROPERTY('ProductVersion') AS nvarchar(128))");

        // Needs VIEW SERVER STATE on some versions: unknown without it.
        try {
            $encrypt = $q->value('SELECT encrypt_option FROM sys.dm_exec_connections WHERE session_id = @@SPID');
        } catch (DbException) {
            $encrypt = null;
        }

        $role = $q->rows(
            "SELECT IS_SRVROLEMEMBER('sysadmin') AS sysadmin, IS_ROLEMEMBER('db_owner') AS owner,
                    IS_ROLEMEMBER('db_datawriter') AS writer, IS_ROLEMEMBER('db_ddladmin') AS ddl,
                    HAS_PERMS_BY_NAME(DB_NAME(), 'DATABASE', 'CREATE TABLE') AS can_create"
        )[0] ?? [];

        $rows = $q->rows(
            "SELECT o.object_id AS id, o.name AS name,
                    HAS_PERMS_BY_NAME(QUOTENAME(s.name) + '.' + QUOTENAME(o.name), 'OBJECT', 'SELECT') AS readable,
                    CASE WHEN HAS_PERMS_BY_NAME(QUOTENAME(s.name) + '.' + QUOTENAME(o.name), 'OBJECT', 'INSERT') = 1
                           OR HAS_PERMS_BY_NAME(QUOTENAME(s.name) + '.' + QUOTENAME(o.name), 'OBJECT', 'UPDATE') = 1
                           OR HAS_PERMS_BY_NAME(QUOTENAME(s.name) + '.' + QUOTENAME(o.name), 'OBJECT', 'DELETE') = 1
                         THEN 1 ELSE 0 END AS writable
               FROM sys.objects o
               JOIN sys.schemas s ON s.schema_id = o.schema_id
              WHERE o.type IN ('U', 'V') AND o.is_ms_shipped = 0 AND s.name = SCHEMA_NAME()
              ORDER BY o.name"
        );

        $all = array_map(static fn (array $r): array => [
            'id'       => (int) $r['id'],
            'name'     => (string) $r['name'],
            'readable' => self::bool($r['readable']),
            'writable' => self::bool($r['writable']),
        ], $rows);

        $columns = static fn (array $t): array => array_map(
            static fn (array $c): array => [(string) $c['name'], (string) $c['type']],
            $q->rows(
                'SELECT c.name AS name, TYPE_NAME(c.user_type_id) AS type FROM sys.columns c WHERE c.object_id = :id ORDER BY c.column_id',
                [':id' => $t['id']]
            )
        );

        $admin = $role === [] ? null : (self::bool($role['sysadmin']) || self::bool($role['owner']) || self::bool($role['writer']) || self::bool($role['ddl']));

        return [
            'version'        => $version,
            'ssl'            => $encrypt === null ? null : strtoupper((string) $encrypt) === 'TRUE',
            'ssl_detail'     => '',
            'superuser'      => $admin,
            // SQL Server has no read-only login setting; ApplicationIntent=ReadOnly is sent instead.
            'role_read_only' => null,
            'can_create'     => $role === [] ? null : self::bool($role['can_create']),
            'all'            => $all,
            'columns'        => $columns,
            'hidden_names'   => true,
        ];
    }

    private static function bool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
