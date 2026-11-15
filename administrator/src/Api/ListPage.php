<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZkbPortal\Component\Zkbiotime\Administrator\Api;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * One page of a ZKBioTime list response.
 */
final class ListPage
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @param  ?int                        $count    Total rows the server reports, when it does
     * @param  bool                        $hasNext  Whether the server says another page exists
     */
    public function __construct(
        public readonly array $items,
        public readonly ?int $count,
        public readonly bool $hasNext
    ) {
    }
}
