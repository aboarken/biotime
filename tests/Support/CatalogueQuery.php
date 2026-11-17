<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Tests\Support;

use BiotimePortal\Component\Biotime\Administrator\Database\Query;

/** Database answers from a list of [SQL fragment => rows]; anything else fails the test. */
final class CatalogueQuery extends Query
{
    /** @var list<string> */
    public array $sent = [];

    /** @param array<string, list<array<string, mixed>>|\Closure> $answers */
    public function __construct(string $engine, private readonly array $answers)
    {
        parent::__construct(null, $engine);
    }

    public function rows(string $sql, array $params = []): array
    {
        $this->sent[] = $sql;

        foreach ($this->answers as $fragment => $rows) {
            if (str_contains($sql, $fragment)) {
                return $rows instanceof \Closure ? $rows($params) : $rows;
            }
        }

        throw new \LogicException('Unexpected query: ' . $sql);
    }
}

