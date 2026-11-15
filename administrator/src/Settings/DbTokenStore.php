<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Settings;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Psr\Log\LoggerInterface;
use BiotimePortal\Component\Biotime\Administrator\Api\CachedToken;
use BiotimePortal\Component\Biotime\Administrator\Api\TokenStoreInterface;
use BiotimePortal\Component\Biotime\Administrator\Security\KeyVault;
use BiotimePortal\Component\Biotime\Administrator\Security\KeyVaultException;

/**
 * Keeps the API token in #__biotime_settings, encrypted, shared by all page loads so ZKBioTime
 * is not asked for a new token on every request. It never reaches a session, cookie or page.
 */
final class DbTokenStore implements TokenStoreInterface
{
    public function __construct(
        private readonly SettingsRepository $repository,
        private readonly KeyVault $vault,
        private readonly LoggerInterface $logger
    ) {
    }

    public function load(): ?CachedToken
    {
        $settings = $this->repository->load();
        $cipher   = $settings->get('token_cache_enc');
        $renewAt  = (int) $settings->get('token_cache_renew_at');

        if ($cipher === '' || $renewAt <= 0) {
            return null;
        }

        try {
            return new CachedToken($this->vault->decrypt($cipher, KeyVault::CONTEXT_TOKEN_CACHE), $renewAt);
        } catch (KeyVaultException) {
            // The encryption secret changed: log in again.
            return null;
        }
    }

    public function save(CachedToken $token): void
    {
        try {
            $this->repository->save([
                'token_cache_enc'      => $this->vault->encrypt($token->token, KeyVault::CONTEXT_TOKEN_CACHE),
                'token_cache_renew_at' => (string) $token->renewAt,
            ]);
        } catch (\Throwable) {
            // Not fatal: the token is used for this page load and requested again next time.
            $this->logger->warning('The ZKBioTime API token could not be cached; it will be requested again on the next page load.');
        }
    }

    public function clear(): void
    {
        try {
            $this->repository->save(['token_cache_enc' => '', 'token_cache_renew_at' => '']);
        } catch (\Throwable) {
            $this->logger->warning('The cached ZKBioTime API token could not be removed.');
        }
    }
}
