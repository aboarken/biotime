<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Export;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects


/**
 * A report ready to be written as PDF or Excel: already translated, formatted text only.
 */
final class ReportTable
{
    /**
     * @param list<array{0: string, 1: string}> $summary  label => value
     * @param list<string>                      $headers
     * @param list<list<string>>                $rows
     * @param list<string>                      $notes    limitations shown under the table
     */
    public function __construct(
        public readonly string $title,
        public readonly string $subtitle,
        public readonly array $summary,
        public readonly array $headers,
        public readonly array $rows,
        public readonly array $notes = []
    ) {
    }
}
