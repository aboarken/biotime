<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\View\Manager;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Paper of the Manager Dashboard report (Export PDF, 1.0.11). An A4 page holds the employee
 * column, the three totals and PORTRAIT date columns upright, or LANDSCAPE sideways. Portrait
 * when every date fits; otherwise landscape, the dates in balanced tables of at most LANDSCAPE
 * dates each (31 days: three tables of 11, 11 and 9).
 */
final class ReportColumns
{
    public const PORTRAIT = 6;

    public const LANDSCAPE = 12;

    /**
     * @param  list<string> $dates
     * @return array{0: bool, 1: list<list<string>>} landscape, the dates of each table
     */
    public static function of(array $dates): array
    {
        if (\count($dates) <= self::PORTRAIT) {
            return [false, [$dates]];
        }

        $tables = (int) ceil(\count($dates) / self::LANDSCAPE);

        return [true, array_chunk($dates, (int) ceil(\count($dates) / $tables))];
    }
}
