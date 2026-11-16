<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Attendance;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Settings\Settings;

/**
 * A read-only ZKBioTime endpoint (or saved report) chosen by the administrator for calculated daily
 * attendance and schedules ("daily") or for leave ("leave"), with the names of its query parameters
 * and of the fields the portal reads.
 *
 * Only a path, parameter names, fixed key=value parameters and field names can be configured:
 * never SQL, never a free-form query. Every value is checked against a strict pattern.
 */
final class SourceConfig
{
    /** Fields the portal can read from the daily source. emp_code and date are required. */
    public const DAILY_FIELDS = [
        'emp_code', 'date', 'schedule', 'sched_start', 'sched_end', 'break', 'schedule_note',
        'check_in', 'check_out', 'late', 'early', 'worked', 'overtime', 'status', 'leave',
    ];

    /** Fields the portal can read from the leave source. emp_code and start are required. */
    public const LEAVE_FIELDS = ['emp_code', 'start', 'end', 'type', 'duration', 'status'];

    public const DAILY_STATUSES = ['present', 'late', 'early', 'absent', 'leave', 'holiday', 'rest', 'incomplete', 'other'];

    public const LEAVE_STATUSES = ['approved', 'pending', 'rejected'];

    public const DATE_FORMATS = ['Y-m-d', 'Y-m-d H:i:s'];

    public const DURATION_UNITS = ['minutes', 'hours', 'seconds'];

    private const PATH       = '#^(/[A-Za-z0-9_-]+)+/$#';
    private const PARAM      = '/^[a-z][a-z0-9_]{0,39}$/';
    private const EXTRA_VAL  = '/^[A-Za-z0-9_.:,-]{1,100}$/';
    private const FIELD_PATH = '/^[A-Za-z_][A-Za-z0-9_]{0,59}(\.[A-Za-z0-9_]{1,60}){0,3}$/';

    /**
     * @param array<string, string> $extra     fixed query parameters (e.g. a saved report's id)
     * @param array<string, string> $fields    portal field => ZKBioTime field path (dots for nesting)
     * @param array<string, string> $statusMap lower-cased ZKBioTime value => portal status
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $path,
        public readonly string $paramEmp,
        public readonly string $paramFrom,
        public readonly string $paramTo,
        public readonly string $dateFormat,
        public readonly array $extra,
        public readonly array $fields,
        public readonly string $durationUnit,
        public readonly array $statusMap
    ) {
    }

    /**
     * @param 'daily'|'leave' $kind
     */
    public static function fromSettings(Settings $settings, string $kind): self
    {
        $get = static fn (string $name): string => $settings->get($kind . '_' . $name);

        try {
            return self::parse($kind, [
                'path'          => $get('path'),
                'param_emp'     => $get('param_emp'),
                'param_from'    => $get('param_from'),
                'param_to'      => $get('param_to'),
                'date_format'   => $get('date_format'),
                'extra'         => $get('extra'),
                'fields'        => $get('fields'),
                'duration_unit' => $get('duration_unit'),
                'status_map'    => $get('status_map'),
            ]);
        } catch (\InvalidArgumentException) {
            // Stored values are validated on save; anything unreadable means "not configured".
            return new self($kind, '', 'emp_code', 'start_date', 'end_date', 'Y-m-d', [], [], 'minutes', []);
        }
    }

    /**
     * Validate raw settings values. Throws with a language-key suffix as message on the first error.
     *
     * @param 'daily'|'leave'       $kind
     * @param array<string, string> $raw
     *
     * @throws \InvalidArgumentException  message: ERR_PATH, ERR_PARAM, ERR_EXTRA, ERR_FIELDS, ERR_FIELDS_REQUIRED, ERR_STATUS_MAP
     */
    public static function parse(string $kind, array $raw): self
    {
        if (!\in_array($kind, ['daily', 'leave'], true)) {
            throw new \InvalidArgumentException('ERR_KIND');
        }

        $path = trim($raw['path'] ?? '');

        if ($path !== '' && (!preg_match(self::PATH, $path) || \in_array($path, ['/jwt-api-token-auth/', '/api-token-auth/'], true) || str_contains($path, '..'))) {
            throw new \InvalidArgumentException('ERR_PATH');
        }

        // The punch and employee lists hold no calculated attendance or leave: the portal reads
        // them itself.
        if ($path !== '' && \BiotimePortal\Component\Biotime\Administrator\Api\ZkbClient::isBuiltInPath($path)) {
            throw new \InvalidArgumentException('ERR_BUILTIN');
        }

        $params = [];

        foreach (['param_emp' => 'emp_code', 'param_from' => 'start_date', 'param_to' => 'end_date'] as $name => $default) {
            $value = trim($raw[$name] ?? '') ?: $default;

            if (!preg_match(self::PARAM, $value)) {
                throw new \InvalidArgumentException('ERR_PARAM');
            }

            $params[$name] = $value;
        }

        $dateFormat = \in_array($raw['date_format'] ?? '', self::DATE_FORMATS, true) ? $raw['date_format'] : 'Y-m-d';
        $unit       = \in_array($raw['duration_unit'] ?? '', self::DURATION_UNITS, true) ? $raw['duration_unit'] : 'minutes';

        $extra = [];

        foreach (self::lines($raw['extra'] ?? '') as [$key, $value]) {
            if (!preg_match(self::PARAM, $key) || !preg_match(self::EXTRA_VAL, $value) || \in_array($key, ['page', 'page_size', ...array_values($params)], true)) {
                throw new \InvalidArgumentException('ERR_EXTRA');
            }

            $extra[$key] = $value;
        }

        if (\count($extra) > 10) {
            throw new \InvalidArgumentException('ERR_EXTRA');
        }

        $allowed = $kind === 'daily' ? self::DAILY_FIELDS : self::LEAVE_FIELDS;
        $fields  = [];

        foreach (self::lines($raw['fields'] ?? '') as [$key, $value]) {
            if (!\in_array($key, $allowed, true) || !preg_match(self::FIELD_PATH, $value)) {
                throw new \InvalidArgumentException('ERR_FIELDS');
            }

            $fields[$key] = $value;
        }

        $required = $kind === 'daily' ? ['emp_code', 'date'] : ['emp_code', 'start'];

        if ($path !== '' && array_diff($required, array_keys($fields)) !== []) {
            throw new \InvalidArgumentException('ERR_FIELDS_REQUIRED');
        }

        $statuses  = $kind === 'daily' ? self::DAILY_STATUSES : self::LEAVE_STATUSES;
        $statusMap = [];

        foreach (self::lines($raw['status_map'] ?? '') as [$key, $value]) {
            $value = strtolower($value);

            if ($key === '' || mb_strlen($key) > 100 || !\in_array($value, $statuses, true)) {
                throw new \InvalidArgumentException('ERR_STATUS_MAP');
            }

            $statusMap[mb_strtolower($key)] = $value;
        }

        return new self($kind, $path, $params['param_emp'], $params['param_from'], $params['param_to'], $dateFormat, $extra, $fields, $unit, $statusMap);
    }

    public function isConfigured(): bool
    {
        return $this->path !== '' && isset($this->fields['emp_code']);
    }

    public function has(string $field): bool
    {
        return isset($this->fields[$field]);
    }

    /**
     * Read a mapped field from one ZKBioTime row. Nested values use dots ("department.dept_name").
     * Returns null when the field is not mapped or not present. Arrays and objects are never
     * returned as text.
     *
     * @param array<mixed> $row
     */
    public function value(array $row, string $field): ?string
    {
        if (!isset($this->fields[$field])) {
            return null;
        }

        $value = $row;

        foreach (explode('.', $this->fields[$field]) as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (!\is_scalar($value)) {
            return null;
        }

        $text = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags((string) $value)) ?? '');

        return $text === '' ? null : mb_substr($text, 0, 200);
    }

    /**
     * A duration field in minutes, or null. Accepts numbers in the configured unit and "HH:MM"
     * or "HH:MM:SS" text, exactly as ZKBioTime returned them; nothing is recalculated.
     *
     * @param array<mixed> $row
     */
    public function minutes(array $row, string $field): ?int
    {
        return self::toMinutes($this->value($row, $field), $this->durationUnit);
    }

    public static function toMinutes(?string $value, string $unit): ?int
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/^(\d{1,4}):([0-5]\d)(?::([0-5]\d))?$/', $value, $m)) {
            return (int) $m[1] * 60 + (int) $m[2];
        }

        if (!is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        $minutes = match ($unit) {
            'hours'   => $number * 60,
            'seconds' => $number / 60,
            default   => $number,
        };

        return $minutes < 0 ? null : (int) round($minutes);
    }

    /** Portal status for a ZKBioTime status text; "other" when the administrator has not mapped it. */
    public function status(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        return $this->statusMap[mb_strtolower($raw)] ?? 'other';
    }

    /**
     * "key = value" lines (also "key: value"); blank lines and lines starting with # are skipped.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function lines(string $text): array
    {
        $out = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!preg_match('/^(.+?)\s*[=:]\s*(.+)$/u', $line, $m)) {
                throw new \InvalidArgumentException('ERR_LINE');
            }

            $out[] = [trim($m[1]), trim($m[2])];
        }

        return \array_slice($out, 0, 60);
    }
}
