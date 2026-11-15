<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZkbPortal\Component\Zkbiotime\Administrator\Api;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * All rows read from a list endpoint, up to the safety maximum.
 */
final class ListResult
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @param  bool                        $truncated  true when the safety maximum stopped reading
     *                                                 before the server's last page; pages must
     *                                                 then tell the user results were cut off
     */
    public function __construct(
        public readonly array $items,
        public readonly ?int $count,
        public readonly bool $truncated,
        public readonly int $pagesRead
    ) {
    }
}
