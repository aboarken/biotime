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
use BiotimePortal\Component\Biotime\Administrator\Attendance\SourceConfig;
use BiotimePortal\Component\Biotime\Administrator\Database\DbConfig;
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

        // Server URL, including its port (https://zkb.example.com:5555).
        $url = rtrim($text('server_url', 500), '/');

        try {
            $base                 = ClientConfig::buildBaseUrl($url);
            $values['server_url'] = $url;
        } catch (\InvalidArgumentException) {
            $errors[] = $url === '' ? 'COM_BIOTIME_SETTINGS_ERR_URL_REQUIRED' : 'COM_BIOTIME_SETTINGS_ERR_URL';
            $base     = '';
        }

        // The separate port field of 0.1.x is gone: the port is part of the URL.
        $values['server_port'] = '';

        // Connect via: another address for the TCP connection, same name for TLS and Host.
        try {
            $basePort              = (int) (parse_url($base, PHP_URL_PORT) ?: (str_starts_with(strtolower($url), 'https://') ? 443 : 80));
            $values['connect_via'] = ClientConfig::normalizeConnectVia(self::str($input['connect_via'] ?? ''), $basePort);
        } catch (\InvalidArgumentException) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_CONNECT_VIA';
            $values['connect_via'] = '';
        }

        $https = str_starts_with(strtolower($url), 'https://');

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

        // A stored password or token is only kept for the address it was entered for: when the
        // server URL or "connect via" changes, it must be typed again, so stored credentials can
        // never be sent to a new host by someone who does not know them.
        $moved = $current->serverUrl() !== ''
            && (($values['server_url'] ?? '') !== $current->serverUrl() || ($values['connect_via'] ?? '') !== $current->connectVia());

        if ($moved && $password === '' && $hasPassword && $current->hasPassword()) {
            $errors[]                   = 'COM_BIOTIME_SETTINGS_ERR_REENTER_PASSWORD';
            $values['api_password_enc'] = '';
        }

        if ($moved && $token === '' && $hasToken && $current->hasStaticToken()) {
            $errors[]                = 'COM_BIOTIME_SETTINGS_ERR_REENTER_TOKEN';
            $values['api_token_enc'] = '';
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

        $values['cache_minutes'] = (string) max(0, min(Settings::MAX_CACHE_MINUTES, self::int($input['cache_minutes'] ?? null) ?? Settings::DEFAULT_CACHE_MINUTES));

        if (self::int($input['cache_minutes'] ?? null) === null || (int) $input['cache_minutes'] > Settings::MAX_CACHE_MINUTES) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_CACHE';
        }

        $values['date_format'] = \in_array(self::str($input['date_format'] ?? ''), Settings::DATE_FORMATS, true) ? self::str($input['date_format']) : 'd/m/Y';
        $values['time_format'] = \in_array(self::str($input['time_format'] ?? ''), Settings::TIME_FORMATS, true) ? self::str($input['time_format']) : 'H:i';

        $zone = trim(self::str($input['server_timezone'] ?? ''));

        if ($zone !== '' && !\in_array($zone, \DateTimeZone::listIdentifiers(), true)) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_TIMEZONE';
            $zone     = '';
        }

        $values['server_timezone'] = $zone;

        // Weekend days: ISO weekdays 1–7 (none is allowed: every day is then a working day).
        $weekend = $input['weekend_days'] ?? '';
        $weekend = \is_array($weekend) ? array_map(static fn ($v): string => \is_scalar($v) ? (string) $v : '', $weekend) : self::str($weekend);
        $values['weekend_days'] = implode(',', Settings::parseWeekdays($weekend));

        if (\count(Settings::parseWeekdays($weekend)) === 7) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_WEEKEND';
        }

        // Employee mapping: custom user field name (lower case, as Joomla stores field names).
        $matchField = strtolower(trim(self::str($input['match_field'] ?? '')));

        if ($matchField !== '' && !preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/', $matchField)) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_MATCH_FIELD';
            $matchField = '';
        }

        $values['match_field'] = $matchField;

        // Employee mapping and modules.
        foreach (['auto_match', 'module_charts'] as $flag) {
            $values[$flag] = self::flag($input[$flag] ?? '0') ? '1' : '0';
        }

        // Data source for calculated attendance (paths, parameter and field names only).
        foreach (['daily'] as $kind) {
            $raw = [];

            foreach (['path', 'param_emp', 'param_from', 'param_to', 'date_format', 'extra', 'fields', 'duration_unit', 'status_map'] as $name) {
                $raw[$name] = mb_substr(self::str($input[$kind . '_' . $name] ?? ''), 0, 4000);
            }

            try {
                $source = SourceConfig::parse($kind, $raw);
            } catch (\InvalidArgumentException $e) {
                $errors[] = 'COM_BIOTIME_SETTINGS_' . strtoupper($kind) . '_' . (\in_array($e->getMessage(), ['ERR_PATH', 'ERR_BUILTIN', 'ERR_PARAM', 'ERR_EXTRA', 'ERR_FIELDS', 'ERR_FIELDS_REQUIRED', 'ERR_STATUS_MAP'], true) ? $e->getMessage() : 'ERR_LINE');

                continue;
            }

            $values[$kind . '_path']          = $source->path;
            $values[$kind . '_param_emp']     = $source->paramEmp;
            $values[$kind . '_param_from']    = $source->paramFrom;
            $values[$kind . '_param_to']      = $source->paramTo;
            $values[$kind . '_date_format']   = $source->dateFormat;
            $values[$kind . '_duration_unit'] = $source->durationUnit;
            $values[$kind . '_extra']         = trim(str_replace("\r", '', $raw['extra']));
            $values[$kind . '_fields']        = trim(str_replace("\r", '', $raw['fields']));
            $values[$kind . '_status_map']    = trim(str_replace("\r", '', $raw['status_map']));
        }

        $this->database($input, $current, $values, $errors);

        // New settings may mean new credentials or another server: log in afresh.
        $values['token_cache_enc']      = '';
        $values['token_cache_renew_at'] = '';

        if ($errors !== []) {
            throw new ValidationFailed(array_values(array_unique($errors)));
        }

        return $values;
    }

    /**
     * Direct read from ZKBioTime's database. Off ('' engine): the values are kept as typed, nothing
     * is required. On: every value is checked as DbConfig checks it, the password is required, and
     * a stored password is only kept for the server and database it was entered for.
     *
     * @param  array<string, mixed>   $input
     * @param  array<string, string>  $values
     * @param  list<string>           $errors
     */
    private function database(array $input, Settings $current, array &$values, array &$errors): void
    {
        $text   = static fn (string $name, int $max): string => mb_substr(trim(self::str($input[$name] ?? '')), 0, $max);
        $engine = self::str($input['db_engine'] ?? '');
        $on     = $engine !== '';

        if ($on && !\in_array($engine, DbConfig::ENGINES, true)) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_DB_ENGINE';
            $engine   = '';
        }

        $default = DbConfig::DEFAULT_PORTS[$engine] ?? DbConfig::DEFAULT_PORTS[DbConfig::ENGINE_PGSQL];
        $server  = $text('db_server', 300);
        $host    = '';
        $port    = $default;

        // Host and port in one field ("db.example.com:7496"), as the server URL of the API.
        if ($server !== '') {
            try {
                [$host, $port] = DbConfig::parseAddress($server, $default);
            } catch (\InvalidArgumentException) {
                $errors[] = 'COM_BIOTIME_SETTINGS_ERR_DB_SERVER';
            }
        } elseif ($on) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_DB_SERVER';
        }

        // Connect via: the IP address (and port) Joomla really connects to, e.g. the firewall's
        // public address, while the certificate is checked against the server's name.
        $via    = $text('db_connect_via', 60);
        $viaOut = '';

        if ($via !== '') {
            try {
                [$viaIp, $viaPort] = DbConfig::parseAddress($via, $port);

                if (filter_var($viaIp, FILTER_VALIDATE_IP) === false) {
                    $errors[] = 'COM_BIOTIME_SETTINGS_ERR_DB_VIA';
                } elseif ($engine === DbConfig::ENGINE_SQLSRV) {
                    $errors[] = 'COM_BIOTIME_SETTINGS_ERR_DB_VIA_SQLSRV';
                } else {
                    $viaOut = DbConfig::formatAddress($viaIp, $viaPort);
                }
            } catch (\InvalidArgumentException) {
                $errors[] = 'COM_BIOTIME_SETTINGS_ERR_DB_VIA';
            }
        }
        $name = $text('db_name', 63);
        $user = $text('db_user', 63);
        $ssl  = self::str($input['db_ssl'] ?? DbConfig::SSL_VERIFY);
        $ca   = $text('db_ca', 500);

        if ($name !== '' && !DbConfig::validName($name) || $on && $name === '') {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_DB_NAME';
        }

        if ($user !== '' && !DbConfig::validUser($user) || $on && $user === '') {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_DB_USER';
        } elseif (\in_array(strtolower($user), DbConfig::ADMIN_USERS, true)) {
            // The administrator accounts can change everything: never stored for the portal.
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_DB_ADMIN_USER';
        }

        if (!\in_array($ssl, DbConfig::SSL_MODES, true)) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_DB_SSL';
            $ssl      = DbConfig::SSL_VERIFY;
        }

        if ($ca !== '' && (!DbConfig::validPath($ca) || !($this->fileExists)($ca))) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_DB_CA';
        } elseif ($on && $engine === DbConfig::ENGINE_PGSQL && $ssl === DbConfig::SSL_VERIFY && $ca === '') {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_DB_CA_REQUIRED';
        }

        $values['db_engine'] = $engine;
        $values['db_host']   = $host;
        $values['db_port']   = (string) $port;
        $values['db_connect_via'] = $viaOut;
        $values['db_name']   = $name;
        $values['db_user']   = $user;
        $values['db_ssl']    = $ssl;
        $values['db_ca']     = $ca;

        // Password: as for the API password.
        $password = self::str($input['db_password'] ?? '');
        $has      = $current->hasDbPassword() && !self::flag($input['clear_db_password'] ?? '0');

        if (!$has) {
            $values['db_password_enc'] = '';
        }

        if ($password !== '') {
            if (\strlen($password) > 256 || preg_match('/[\x00-\x1F\x7F]/', $password)) {
                $errors[] = 'COM_BIOTIME_SETTINGS_ERR_DB_PASSWORD';
                $has      = true;
            } else {
                $encrypted = $this->encryptOrFail($password, \BiotimePortal\Component\Biotime\Administrator\Security\KeyVault::CONTEXT_DB_PASSWORD, $errors);

                if ($encrypted !== null) {
                    $values['db_password_enc'] = $encrypted;
                    $has                       = true;
                }
            }
        }

        $moved = $current->get('db_host') !== '' && (
            $engine !== $current->get('db_engine') || $host !== $current->get('db_host')
            || $values['db_port'] !== $current->get('db_port') || $viaOut !== $current->get('db_connect_via')
            || $name !== $current->get('db_name')
            || strtolower($user) !== strtolower($current->get('db_user'))
        );

        if ($moved && $password === '' && $has && $current->hasDbPassword()) {
            $errors[]                  = 'COM_BIOTIME_SETTINGS_ERR_REENTER_DB_PASSWORD';
            $values['db_password_enc'] = '';
            $has                       = false;
        }

        if ($on && !$has) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_DB_PASSWORD_REQUIRED';
        }

        // Where the pages read attendance. The database needs PostgreSQL (the queries are written
        // for it; SQL Server is only checked by Test Database).
        $source = self::str($input['data_source'] ?? 'api');

        if (!\in_array($source, ['api', 'database'], true)) {
            $source = 'api';
        }

        if ($source === 'database' && $engine !== DbConfig::ENGINE_PGSQL) {
            $errors[] = 'COM_BIOTIME_SETTINGS_ERR_DATA_SOURCE';
        }

        $unit = self::str($input['db_duration_unit'] ?? 'auto');

        $values['data_source']      = $source;
        $values['db_duration_unit'] = \in_array($unit, \BiotimePortal\Component\Biotime\Administrator\Database\AttendanceReader::UNITS, true) ? $unit : 'auto';
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
