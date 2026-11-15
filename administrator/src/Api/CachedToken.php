<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZkbPortal\Component\Zkbiotime\Administrator\Api;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * An API token and the moment it must be renewed (already "shortly before" its real expiry).
 */
final class CachedToken
{
    public function __construct(
        #[\SensitiveParameter] public readonly string $token,
        public readonly int $renewAt
    ) {
    }

    public function isUsable(int $now): bool
    {
        return $this->token !== '' && $now < $this->renewAt;
    }

    public function __debugInfo(): array
    {
        return ['token' => '[redacted]', 'renewAt' => $this->renewAt];
    }
}
