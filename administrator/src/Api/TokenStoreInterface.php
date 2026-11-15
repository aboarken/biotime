<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Api;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Server-side cache for the API token. Tokens never leave the server (no session, cookie,
 * JavaScript or HTML).
 */
interface TokenStoreInterface
{
    public function load(): ?CachedToken;

    public function save(CachedToken $token): void;

    public function clear(): void;
}
