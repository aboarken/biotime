<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZkbPortal\Component\Zkbiotime\Administrator\Service;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Psr\Log\LoggerInterface;
use ZkbPortal\Component\Zkbiotime\Administrator\Api\ClientConfig;
use ZkbPortal\Component\Zkbiotime\Administrator\Api\Credentials;
use ZkbPortal\Component\Zkbiotime\Administrator\Api\TokenStoreInterface;
use ZkbPortal\Component\Zkbiotime\Administrator\Api\Transport\JoomlaHttpTransport;
use ZkbPortal\Component\Zkbiotime\Administrator\Api\Transport\TransportInterface;
use ZkbPortal\Component\Zkbiotime\Administrator\Api\ZkbClient;
use ZkbPortal\Component\Zkbiotime\Administrator\Api\ZkbException;
use ZkbPortal\Component\Zkbiotime\Administrator\Security\KeyVault;
use ZkbPortal\Component\Zkbiotime\Administrator\Security\KeyVaultException;
use ZkbPortal\Component\Zkbiotime\Administrator\Security\SecretProvider;
use ZkbPortal\Component\Zkbiotime\Administrator\Settings\DbTokenStore;
use ZkbPortal\Component\Zkbiotime\Administrator\Settings\Settings;
use ZkbPortal\Component\Zkbiotime\Administrator\Settings\SettingsRepository;

/**
 * Builds ZkbClient from the stored settings. Decrypted secrets exist only inside the client.
 */
final class ZkbClientFactory
{
    private ?KeyVault $vault = null;

    /**
     * @param  (\Closure(ClientConfig): TransportInterface)|null  $transportFactory  Injected for tests
     * @param  (\Closure(KeyVault): TokenStoreInterface)|null     $tokenStoreFactory Injected for tests
     */
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly SecretProvider $secrets,
        private readonly LoggerInterface $logger,
        private readonly ?\Closure $transportFactory = null,
        private readonly ?\Closure $tokenStoreFactory = null
    ) {
    }

    /**
     * @param  TokenStoreInterface|null  $tokens  Another token store (Test Connection uses a fresh one)
     *
     * @throws ZkbException  NOT_CONFIGURED when the settings are incomplete or cannot be decrypted
     */
    public function create(?TokenStoreInterface $tokens = null): ZkbClient
    {
        $settings = $this->settings->load();

        if (!$settings->isConfigured()) {
            throw new ZkbException(ZkbException::NOT_CONFIGURED, 'ZKBioTime connection is not configured');
        }

        try {
            $config = new ClientConfig(
                $settings->serverUrl(),
                $settings->serverPort(),
                $settings->timeout(),
                $settings->connectTimeout(),
                $settings->verifyTls(),
                $settings->caBundle(),
                $settings->tlsPin()
            );
        } catch (\InvalidArgumentException $e) {
            throw new ZkbException(ZkbException::NOT_CONFIGURED, 'ZKBioTime server URL is invalid', 0, '', $e);
        }

        try {
            $credentials = $this->credentials($settings);
        } catch (KeyVaultException $e) {
            $this->logger->error('The stored ZKBioTime password or token could not be decrypted; the encryption secret may have changed. Enter them again in Integration Settings.');

            throw new ZkbException(ZkbException::NOT_CONFIGURED, 'Stored credentials cannot be decrypted', 0, '', $e);
        } catch (\InvalidArgumentException $e) {
            throw new ZkbException(ZkbException::NOT_CONFIGURED, 'ZKBioTime credentials are incomplete', 0, '', $e);
        }

        $transport = $this->transportFactory !== null ? ($this->transportFactory)($config) : new JoomlaHttpTransport($config);
        $tokens  ??= $this->tokenStoreFactory !== null
            ? ($this->tokenStoreFactory)($this->vault())
            : new DbTokenStore($this->settings, $this->vault(), $this->logger);

        return new ZkbClient($transport, $config, $credentials, $tokens, $this->logger, $settings->pageSize(), $settings->maxPages());
    }

    public function vault(): KeyVault
    {
        return $this->vault ??= new KeyVault($this->secrets->getSecret());
    }

    public function secretSource(): string
    {
        return $this->secrets->getSource();
    }

    public function secretEnvTooShort(): bool
    {
        return $this->secrets->envTooShort();
    }

    private function credentials(Settings $settings): Credentials
    {
        $password = $settings->hasPassword() ? $this->vault()->decrypt($settings->passwordCiphertext(), KeyVault::CONTEXT_PASSWORD) : '';
        $token    = $settings->authMethod() === Credentials::METHOD_TOKEN && $settings->hasStaticToken()
            ? $this->vault()->decrypt($settings->staticTokenCiphertext(), KeyVault::CONTEXT_STATIC_TOKEN)
            : '';

        return new Credentials($settings->authMethod(), $settings->username(), $password, $token);
    }
}
