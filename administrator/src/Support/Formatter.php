<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Support;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Attendance\Day;

/**
 * Turns attendance values into display text. Missing values are always "—". Times are shown as
 * ZKBioTime recorded them, in the ZKBioTime server's time zone: they are never shifted.
 */
final class Formatter
{
    public const DASH = '—';

    /**
     * @param \Closure(string): string $translate language key => text
     */
    public function __construct(
        private readonly string $dateFormat,
        private readonly string $timeFormat,
        private readonly \Closure $translate
    ) {
    }

    public function date(?string $ymd): string
    {
        if ($ymd === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
            return self::DASH;
        }

        $d = new \DateTimeImmutable($ymd);

        // Month names come from Joomla's language files (e.g. NOVEMBER_SHORT).
        if (str_contains($this->dateFormat, 'M')) {
            $text = $d->format(str_replace('M', '\\%\\%', $this->dateFormat));

            return str_replace('%%', $this->t(strtoupper($d->format('F')) . '_SHORT'), $text);
        }

        return $d->format($this->dateFormat);
    }

    public function time(?string $his): string
    {
        if ($his === null || !preg_match('/^(\d{2}):(\d{2}):(\d{2})$/', $his)) {
            return self::DASH;
        }

        $text = (new \DateTimeImmutable('2000-01-01 ' . $his))->format($this->timeFormat);

        return str_replace(['AM', 'PM'], [$this->t('COM_BIOTIME_AM'), $this->t('COM_BIOTIME_PM')], $text);
    }

    /** Minutes as "H:MM" (e.g. 8:05), or "—". */
    public function duration(?int $minutes): string
    {
        return $minutes === null ? self::DASH : \sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** ISO weekday 1–7 as a translated name. */
    public function weekday(int $n, bool $short = false): string
    {
        $names = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'];
        $name  = $names[max(1, min(7, $n)) - 1];

        return $this->t($short ? substr($name, 0, 3) : $name);
    }

    public function monthName(int $month): string
    {
        $names = ['JANUARY', 'FEBRUARY', 'MARCH', 'APRIL', 'MAY', 'JUNE', 'JULY', 'AUGUST', 'SEPTEMBER', 'OCTOBER', 'NOVEMBER', 'DECEMBER'];

        return $this->t($names[max(1, min(12, $month)) - 1]);
    }

    /** The day's status: the portal label for a mapped status, ZKBioTime's own text otherwise. */
    public function status(Day $day): string
    {
        if ($day->status === null) {
            return self::DASH;
        }

        if ($day->status === 'other') {
            return $day->statusRaw ?? self::DASH;
        }

        return $this->t('COM_BIOTIME_STATUS_' . strtoupper($day->status));
    }

    public function exception(string $code): string
    {
        return $this->t('COM_BIOTIME_EXCEPTION_' . strtoupper($code));
    }

    public function text(?string $value): string
    {
        return $value === null || $value === '' ? self::DASH : $value;
    }

    public function t(string $key): string
    {
        return ($this->translate)($key);
    }
}
