<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Settings;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Api\Credentials;

/**
 * Immutable view of the settings stored in #__biotime_settings (name/value rows).
 *
 * Settings live in the component's own table, not in com_config params: com_config replaces all
 * params on save, and the password and token must be encrypted before they are stored.
 */
final class Settings
{
    public const DEFAULT_PAGE_SIZE = 100;
    public const DEFAULT_MAX_PAGES = 50;

    public const DEFAULTS = [
        // Connection
        'server_url'      => '',
        'server_port'     => '',
        'auth_method'     => Credentials::METHOD_JWT,
        'api_username'    => '',
        'api_password_enc' => '',
        'api_token_enc'   => '',
        'connect_timeout' => '5',
        'timeout'         => '30',
        'verify_tls'      => '1',
        'ca_bundle'       => '',
        'tls_pin'         => '',
        'connect_via'     => '',
        // System
        'page_size'       => '100',
        'max_pages'       => '50',
        'detailed_log'    => '1',
        // Internal: the cached API token (encrypted) and when to renew it. Never in the form.
        'token_cache_enc' => '',
        'token_cache_renew_at' => '',
    ];

    /** Names never sent to the browser. */
    public const SECRET_NAMES = ['api_password_enc', 'api_token_enc', 'token_cache_enc', 'token_cache_renew_at'];

    /** @param array<string, string> $values */
    public function __construct(private readonly array $values = [])
    {
    }

    public function get(string $name): string
    {
        return $this->values[$name] ?? self::DEFAULTS[$name] ?? '';
    }

    public function serverUrl(): string
    {
        return $this->get('server_url');
    }

    public function serverPort(): int
    {
        $port = (int) $this->get('server_port');

        return $port >= 1 && $port <= 65535 ? $port : 0;
    }

    public function authMethod(): string
    {
        $method = $this->get('auth_method');

        return \in_array($method, Credentials::METHODS, true) ? $method : Credentials::METHOD_JWT;
    }

    public function username(): string
    {
        return $this->get('api_username');
    }

    public function passwordCiphertext(): string
    {
        return $this->get('api_password_enc');
    }

    public function hasPassword(): bool
    {
        return $this->passwordCiphertext() !== '';
    }

    public function staticTokenCiphertext(): string
    {
        return $this->get('api_token_enc');
    }

    public function hasStaticToken(): bool
    {
        return $this->staticTokenCiphertext() !== '';
    }

    /** Whether enough is stored to try a connection. */
    public function isConfigured(): bool
    {
        if ($this->serverUrl() === '') {
            return false;
        }

        $login = $this->username() !== '' && $this->hasPassword();

        return $this->authMethod() === Credentials::METHOD_JWT ? $login : ($this->hasStaticToken() || $login);
    }

    public function connectTimeout(): int
    {
        return max(1, min(60, $this->timeout(), (int) $this->get('connect_timeout')));
    }

    public function timeout(): int
    {
        return max(1, min(300, (int) $this->get('timeout')));
    }

    public function verifyTls(): bool
    {
        return $this->get('verify_tls') !== '0';
    }

    public function caBundle(): string
    {
        return $this->get('ca_bundle');
    }

    public function tlsPin(): string
    {
        return $this->get('tls_pin');
    }

    /** "host", "host:port" or "IP:port" to connect to instead of the server URL's host; '' = direct. */
    public function connectVia(): string
    {
        return $this->get('connect_via');
    }

    public function pageSize(): int
    {
        return max(1, min(1000, (int) $this->get('page_size') ?: self::DEFAULT_PAGE_SIZE));
    }

    public function maxPages(): int
    {
        return max(1, min(1000, (int) $this->get('max_pages') ?: self::DEFAULT_MAX_PAGES));
    }

    public function detailedLog(): bool
    {
        return $this->get('detailed_log') !== '0';
    }

    public function isHttps(): bool
    {
        return str_starts_with(strtolower($this->serverUrl()), 'https://');
    }

    /**
     * Values safe to show in the form: no ciphertext, no cached token.
     *
     * @return array<string, string>
     */
    public function toFormData(): array
    {
        $data = array_merge(self::DEFAULTS, $this->values);

        foreach (self::SECRET_NAMES as $name) {
            unset($data[$name]);
        }

        return $data;
    }

    public function __debugInfo(): array
    {
        return $this->toFormData() + [
            'api_password_enc' => $this->hasPassword() ? '[set]' : '',
            'api_token_enc'    => $this->hasStaticToken() ? '[set]' : '',
        ];
    }
}
