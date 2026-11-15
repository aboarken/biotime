<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Discovery;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Describes sample rows of an endpoint by field name and kind of value (date, time, number, ...),
 * never by the values themselves, and suggests the Daily Attendance or Leave field names.
 *
 * Only values of status-like fields (e.g. "Late", "Absent", "Approved") are listed, so the
 * administrator can map them; names, codes, e-mails and other personal values are never shown.
 */
final class FieldInspector
{
    public const TYPES = ['date', 'datetime', 'time', 'number', 'bool', 'text', 'empty'];

    /** Field names whose values may be shown (status texts). */
    private const STATUS_NAME = '/(^|[._])(status|state|result|exception|att_status|approval|approve|audit|category|leave_type|type)([._]|$)|status|approv/i';

    /** Field names whose values are never shown, even if they look like a status. */
    private const PERSONAL = '/name|mail|code|phone|mobile|tel|card|ssn|national|passport|address|password|pwd|birth|photo|finger|face|palm|template|gps|location|ip|token|key|nick|user|emp(_|$)|id$/i';

    /**
     * @param list<array<mixed>> $rows
     *
     * @return array<string, array{type: string, values: list<string>}> field path => description
     */
    public static function inspect(array $rows): array
    {
        $seen = [];

        foreach (\array_slice($rows, 0, 50) as $row) {
            if (\is_array($row)) {
                self::walk($row, '', 0, $seen);
            }
        }

        $out = [];

        foreach ($seen as $path => $info) {
            arsort($info['types']);
            $types = array_keys($info['types']);
            $type  = $types[0] === 'empty' && \count($types) > 1 ? $types[1] : $types[0];

            $values = [];

            if (preg_match(self::STATUS_NAME, $path) && !preg_match(self::PERSONAL, preg_replace('/^.*\./', '', $path) ?? $path) && \count($info['values']) <= 20) {
                $values = array_values(array_unique($info['values']));
                sort($values);
            }

            $out[$path] = ['type' => $type, 'values' => $values];
        }

        ksort($out);

        return $out;
    }

    /**
     * Suggested "portal field = ZKBioTime field" lines, by field names and kinds.
     *
     * @param 'daily'|'leave' $kind
     * @param array<string, array{type: string, values: list<string>}> $fields
     *
     * @return array<string, string> portal field => field path
     */
    public static function suggestFields(string $kind, array $fields): array
    {
        $rules = $kind === 'daily' ? [
            'emp_code'      => [['/(^|\.)emp_code$/', '/emp_code/', '/(employee|emp)\.(code|pin)$/'], ['text', 'number']],
            'date'          => [['/(^|\.)att_date$/', '/(^|\.)(date|att_day|work_date|day)$/', '/date/'], ['date', 'datetime']],
            'schedule'      => [['/timetable(_name)?$/', '/shift(_name)?$/', '/schedule(_name)?$/', '/timetable|shift|schedule/'], ['text']],
            'sched_start'   => [['/(timetable|shift|schedule|duty).*(start|in)/', '/(^|_)(in_time|on_duty|start_time|begin_time)$/'], ['time', 'datetime']],
            'sched_end'     => [['/(timetable|shift|schedule|duty).*(end|out)/', '/(^|_)(out_time|off_duty|end_time)$/'], ['time', 'datetime']],
            'check_in'      => [['/(clock|punch|actual|first).*_?in$/', '/check_?in$/', '/(^|_)in$/'], ['time', 'datetime']],
            'check_out'     => [['/(clock|punch|actual|last).*_?out$/', '/check_?out$/', '/(^|_)out$/'], ['time', 'datetime']],
            'late'          => [['/late/'], ['number', 'time']],
            'early'         => [['/early/'], ['number', 'time']],
            'worked'        => [['/(work|worked|actual|duty)_?(time|hours?|mins?|minutes|duration)$/', '/worked|work_time|total_time/'], ['number', 'time']],
            'overtime'      => [['/overtime/', '/(^|[._])ot($|_)/'], ['number', 'time']],
            'break'         => [['/break/'], ['text', 'time', 'number']],
            'status'        => [['/(^|[._])(att_status|status|state|result)$/', '/status/'], ['text']],
            'leave'         => [['/leave/'], ['text']],
        ] : [
            'emp_code' => [['/(^|\.)emp_code$/', '/emp_code/', '/(employee|emp)\.(code|pin)$/'], ['text', 'number']],
            'start'    => [['/(^|_)(start|from|begin)(_date|_time)?$/', '/start|begin|from/'], ['date', 'datetime']],
            'end'      => [['/(^|_)(end|to|finish)(_date|_time)?$/', '/end|finish/'], ['date', 'datetime']],
            'type'     => [['/(leave_type|category|type)(_name)?$/', '/type|category/'], ['text']],
            'duration' => [['/(days|hours|duration|length)$/', '/days|hours|duration/'], ['number', 'text', 'time']],
            'status'   => [['/(approval|approve|audit)(_status)?$/', '/status|state|approv/'], ['text', 'number']],
        ];

        $used = [];
        $out  = [];

        foreach ($rules as $portal => [$patterns, $types]) {
            foreach ($patterns as $pattern) {
                foreach ($fields as $path => $info) {
                    if (isset($used[$path]) || !preg_match($pattern, strtolower($path)) || !\in_array($info['type'], [...$types, 'empty'], true)) {
                        continue;
                    }

                    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,59}(\.[A-Za-z0-9_]{1,60}){0,3}$/', $path)) {
                        continue;
                    }

                    $out[$portal] = $path;
                    $used[$path]  = true;

                    continue 3;
                }
            }
        }

        return $out;
    }

    /**
     * Suggested status lines for the values found, by keywords; "other" when unclear.
     *
     * @param 'daily'|'leave' $kind
     * @param list<string>    $values
     *
     * @return array<string, string> ZKBioTime text => portal status
     */
    public static function suggestStatuses(string $kind, array $values): array
    {
        $out = [];

        foreach ($values as $value) {
            $v = mb_strtolower($value);

            $out[$value] = $kind === 'daily'
                ? match (true) {
                    (bool) preg_match('/absen/', $v)                         => 'absent',
                    (bool) preg_match('/early|leave early/', $v)             => 'early',
                    (bool) preg_match('/late/', $v)                          => 'late',
                    (bool) preg_match('/leave|vacation|sick/', $v)           => 'leave',
                    (bool) preg_match('/holiday/', $v)                       => 'holiday',
                    (bool) preg_match('/weekend|rest|off|day off/', $v)      => 'rest',
                    (bool) preg_match('/miss|incomplete|no (in|out)/', $v)   => 'incomplete',
                    (bool) preg_match('/normal|present|on ?time|ok|attend/', $v) => 'present',
                    default                                                  => 'other',
                }
                : match (true) {
                    (bool) preg_match('/approv|accept|pass/', $v) => 'approved',
                    (bool) preg_match('/reject|refus|deny|denied|fail/', $v) => 'rejected',
                    default => 'pending',
                };
        }

        return $out;
    }

    /**
     * @param array<string, array{types: array<string, int>, values: list<string>}> $seen
     */
    private static function walk(array $row, string $prefix, int $depth, array &$seen): void
    {
        foreach ($row as $key => $value) {
            if (!\is_string($key) || !preg_match('/^[A-Za-z0-9_]{1,60}$/', $key)) {
                continue;
            }

            $path = $prefix === '' ? $key : $prefix . '.' . $key;

            if (\is_array($value)) {
                if (!array_is_list($value) && $depth < 3) {
                    self::walk($value, $path, $depth + 1, $seen);
                }

                continue;
            }

            $type = self::type($value);
            $seen[$path]['types'][$type] = ($seen[$path]['types'][$type] ?? 0) + 1;
            $seen[$path]['values'] ??= [];

            if ($type === 'text' || $type === 'number') {
                $text = trim((string) $value);

                if ($text !== '' && mb_strlen($text) <= 40 && \count($seen[$path]['values']) <= 20 && !\in_array($text, $seen[$path]['values'], true)) {
                    $seen[$path]['values'][] = $text;
                }
            }
        }
    }

    private static function type(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'empty';
        }

        if (\is_bool($value)) {
            return 'bool';
        }

        if (\is_int($value) || \is_float($value)) {
            return 'number';
        }

        $v = trim((string) $value);

        return match (true) {
            (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)                       => 'date',
            (bool) preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{1,2}:\d{2}/', $v)       => 'datetime',
            (bool) preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $v)                  => 'time',
            is_numeric($v)                                                       => 'number',
            default                                                              => 'text',
        };
    }
}
