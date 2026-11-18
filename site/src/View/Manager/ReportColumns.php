<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\View\Manager;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Paper of the Manager Dashboard report (Export PDF, 1.0.11): always A4 landscape (1.0.14). A page
 * holds the employee column, the three totals and up to LANDSCAPE date columns side by side; a
 * longer period is cut into balanced tables of at most LANDSCAPE dates each (31 days: three
 * tables of 11, 11 and 9).
 */
final class ReportColumns
{
    public const LANDSCAPE = 12;

    /**
     * @param  list<string> $dates
     * @return list<list<string>> the dates of each table
     */
    public static function of(array $dates): array
    {
        if (\count($dates) <= self::LANDSCAPE) {
            return [$dates];
        }

        $tables = (int) ceil(\count($dates) / self::LANDSCAPE);

        return array_chunk($dates, (int) ceil(\count($dates) / $tables));
    }
}
