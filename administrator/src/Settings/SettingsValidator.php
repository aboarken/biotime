<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Settings;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Api\ClientConfig;
use BiotimePortal\Component\Biotime\Administrator\Api\Credentials;
use BiotimePortal\Component\Biotime\Administrator\Api\SslPin;
use BiotimePortal\Component\Biotime\Administrator\Security\KeyVault;
use BiotimePortal\Component\Biotime\Administrator\Security\KeyVaultException;

/**
 * Turns the posted Integration Settings form into the values to store. Pure: no Joomla, no
 * database, so every rule is covered by unit tests.
 *
 * - A new password or token is encrypted; an empty field keeps the stored one.
 * - "Remove stored password / token" deletes it.
 * - Every save empties the cached API token, so new credentials are used at once.
 * - All errors are collected and returned as language keys.
 */
final class SettingsValidator
{
    public const TOKEN_PATTERN = '/^[A-Za-z0-9._~+\/=-]{16,4096}$/';

    /** @var \Closure(string): bool */
    private \Closure $fileExists;

    /**
     * @param  \Closure(string, string): string  $encrypt     (plaintext, context) => ciphertext; may throw KeyVaultException
     * @param  (\Closure(string): bool)|null     $fileExists  Injected for tests
     */
    public function __construct(private readonly \Closure $encrypt, ?\Closure $fileExists = null)
    {
        $this->fileExists = $fileExists ?? static fn (string $path): bool => is_file($path) && is_readable($path);
    }

    /**
     * @param  array<string, mixed>  $input  Raw posted values (jform)
     *
     * @return array<string, string>  Values to store
     *
     * @throws ValidationFailed
     */
    public function validate(array $input, Settings $current): array
    {
        $errors = [];
        $values = [];
        $text   = static fn (string $name, int $max = 255): string => mb_substr(trim(self::str($input[$name] ?? '')), 0, $max + 1);

        // Server URL and port.
        $url  = $text('server_url', 500);
        $port = $text('server_port', 10);

        $urlPort = (int) (parse_url($url, PHP_URL_PORT) ?: 0);

        if ($port !== '' && (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535 || ($urlPort > 0 && (int) $port !== $urlPort))) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_PORT';
            $port     = '';
        }

        try {
            ClientConfig::buildBaseUrl($url, (int) $port);
            $values['server_url'] = rtrim($url, '/');
        } catch (\InvalidArgumentException) {
            $errors[] = $url === '' ? 'COM_BIOTIME_SETTINGS_ERR_URL_REQUIRED' : 'COM_BIOTIME_SETTINGS_ERR_URL';
        }

        $values['server_port'] = $port;

        // Connect via: another address for the TCP connection, same name for TLS and Host.
        try {
            $basePort              = (int) $port ?: ($urlPort ?: (str_starts_with(strtolower($url), 'https://') ? 443 : 80));
            $values['connect_via'] = ClientConfig::normalizeConnectVia(self::str($input['connect_via'] ?? ''), $basePort);
        } catch (\InvalidArgumentException) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_CONNECT_VIA';
            $values['connect_via'] = '';
        }
        $https                 = str_starts_with(strtolower($url), 'https://');

        // Authentication.
        $method = self::str($input['auth_method'] ?? '');

        if (!\in_array($method, Credentials::METHODS, true)) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_METHOD';
            $method   = Credentials::METHOD_JWT;
        }

        $values['auth_method'] = $method;

        $username = $text('api_username', 150);

        if (mb_strlen($username) > 150 || preg_match('/[\x00-\x1F\x7F]/u', $username)) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_USERNAME';
            $username = '';
        }

        $values['api_username'] = $username;

        // Secrets are read raw (no trimming inside, no filters) and never echoed back.
        $password    = self::str($input['api_password'] ?? '');
        $token       = trim(self::str($input['api_token'] ?? ''));
        $hasPassword = $current->hasPassword() && !self::flag($input['clear_password'] ?? '0');
        $hasToken    = $current->hasStaticToken() && !self::flag($input['clear_token'] ?? '0');

        if (!$hasPassword) {
            $values['api_password_enc'] = '';
        }

        if (!$hasToken) {
            $values['api_token_enc'] = '';
        }

        if ($password !== '') {
            if (\strlen($password) > 256 || preg_match('/[\x00-\x1F\x7F]/', $password)) {
                $errors[] = 'COM_BIOTIME_SETTINGS_ERR_PASSWORD';
                // Entered but invalid: report that alone, not also "password required".
                $hasPassword = true;
            } else {
                $encrypted = $this->encryptOrFail($password, KeyVault::CONTEXT_PASSWORD, $errors);

                if ($encrypted !== null) {
                    $values['api_password_enc'] = $encrypted;
                    $hasPassword                = true;
                }
            }
        }

        if ($token !== '') {
            if (!preg_match(self::TOKEN_PATTERN, $token)) {
                $errors[] = 'COM_BIOTIME_SETTINGS_ERR_TOKEN';
                $hasToken = true;
            } else {
                $encrypted = $this->encryptOrFail($token, KeyVault::CONTEXT_STATIC_TOKEN, $errors);

                if ($encrypted !== null) {
                    $values['api_token_enc'] = $encrypted;
                    $hasToken                = true;
                }
            }
        }

        $login = $username !== '' && $hasPassword;

        if ($method === Credentials::METHOD_JWT && !$login) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_JWT_CREDENTIALS';
        }

        if ($method === Credentials::METHOD_TOKEN && !$login && !$hasToken) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_TOKEN_CREDENTIALS';
        }

        // Timeouts.
        $connect = self::int($input['connect_timeout'] ?? null);
        $total   = self::int($input['timeout'] ?? null);

        if ($connect === null || $connect < 1 || $connect > 60 || $total === null || $total < 1 || $total > 300) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_TIMEOUT_RANGE';
        } elseif ($connect > $total) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_TIMEOUTS';
        }

        $values['connect_timeout'] = (string) ($connect ?? 5);
        $values['timeout']         = (string) ($total ?? 30);

        // TLS.
        $values['verify_tls'] = self::flag($input['verify_tls'] ?? '1') ? '1' : '0';
        $caBundle             = $text('ca_bundle', 500);

        if ($caBundle !== '' && (preg_match('/[\x00-\x1F\x7F]/', $caBundle) || !($this->fileExists)($caBundle))) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_CA_BUNDLE';
        }

        $values['ca_bundle'] = $caBundle;

        try {
            $values['tls_pin'] = SslPin::normalize(self::str($input['tls_pin'] ?? ''));

            if ($values['tls_pin'] !== '' && !$https) {
                $errors[] = 'COM_BIOTIME_SETTINGS_ERR_PIN_HTTPS';
            }
        } catch (\InvalidArgumentException) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_PIN';
        }

        // System.
        $pageSize = self::int($input['page_size'] ?? null);
        $maxPages = self::int($input['max_pages'] ?? null);

        if ($pageSize === null || $pageSize < 1 || $pageSize > 1000) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_PAGE_SIZE';
        }

        if ($maxPages === null || $maxPages < 1 || $maxPages > 1000) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_MAX_PAGES';
        }

        $values['page_size']    = (string) ($pageSize ?? Settings::DEFAULT_PAGE_SIZE);
        $values['max_pages']    = (string) ($maxPages ?? Settings::DEFAULT_MAX_PAGES);
        $values['detailed_log'] = self::flag($input['detailed_log'] ?? '1') ? '1' : '0';

        // New settings may mean new credentials or another server: log in afresh.
        $values['token_cache_enc']      = '';
        $values['token_cache_renew_at'] = '';

        if ($errors !== []) {
            throw new ValidationFailed(array_values(array_unique($errors)));
        }

        return $values;
    }

    /**
     * @param  list<string>  $errors
     */
    private function encryptOrFail(#[\SensitiveParameter] string $plain, string $context, array &$errors): ?string
    {
        try {
            return ($this->encrypt)($plain, $context);
        } catch (KeyVaultException) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_NO_SECRET';

            return null;
        }
    }

    private static function str(mixed $value): string
    {
        return \is_string($value) || \is_int($value) ? (string) $value : '';
    }

    private static function int(mixed $value): ?int
    {
        $value = trim(self::str($value));

        return preg_match('/^[0-9]{1,6}$/', $value) ? (int) $value : null;
    }

    private static function flag(mixed $value): bool
    {
        return \in_array(self::str($value), ['1', 'on', 'true'], true);
    }
}
