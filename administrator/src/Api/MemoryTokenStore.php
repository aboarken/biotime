<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Api;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Token cache for one PHP request only (tests, and the connection test, which must log in afresh).
 */
final class MemoryTokenStore implements TokenStoreInterface
{
    private ?CachedToken $token = null;

    public function load(): ?CachedToken
    {
        return $this->token;
    }

    public function save(CachedToken $token): void
    {
        $this->token = $token;
    }

    public function clear(): void
    {
        $this->token = null;
    }
}
