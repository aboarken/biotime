<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Database;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * How to reach ZKBioTime's own database for reading. Every value is checked here, so nothing an
 * administrator types can add options to the connection string.
 *
 * SSL modes:
 *  - verify:  encrypted, and the server certificate is checked (PostgreSQL: against the CA file,
 *             with the host name; SQL Server: against the system's trusted certificates);
 *  - require: encrypted, the certificate is not checked (protects against listening, not against
 *             a server pretending to be yours);
 *  - off:     not encrypted. Only for a database on the same network as Joomla.
 *
 * Connect via (PostgreSQL only): the IP address (and port) the connection is really opened to,
 * e.g. the firewall's public address, while the certificate is still checked against the host
 * name, e.g. the server's local name. As "connect via" for the API. libpq takes an IP address
 * only here (hostaddr), never a name.
 */
final class DbConfig
{
    public const ENGINE_PGSQL  = 'pgsql';
    public const ENGINE_SQLSRV = 'sqlsrv';
    public const ENGINES       = [self::ENGINE_PGSQL, self::ENGINE_SQLSRV];

    public const SSL_VERIFY  = 'verify';
    public const SSL_REQUIRE = 'require';
    public const SSL_OFF     = 'off';
    public const SSL_MODES   = [self::SSL_VERIFY, self::SSL_REQUIRE, self::SSL_OFF];

    /** Ports when none is given: the PostgreSQL installed with ZKBioTime, and SQL Server. */
    public const DEFAULT_PORTS = [self::ENGINE_PGSQL => 7496, self::ENGINE_SQLSRV => 1433];

    /** Administrator accounts that must never be used by the portal. */
    public const ADMIN_USERS = ['postgres', 'sa'];

    public function __construct(
        public readonly string $engine,
        public readonly string $host,
        public readonly int $port,
        public readonly string $database,
        public readonly string $user,
        #[\SensitiveParameter] private readonly string $password,
        public readonly string $ssl = self::SSL_VERIFY,
        public readonly string $caFile = '',
        public readonly int $connectTimeout = 5,
        public readonly int $queryTimeout = 30,
        public readonly string $viaIp = '',
        public readonly int $viaPort = 0
    ) {
        if (!\in_array($engine, self::ENGINES, true)) {
            throw new \InvalidArgumentException('engine');
        }

        if (!self::validHost($host)) {
            throw new \InvalidArgumentException('host');
        }

        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('port');
        }

        if (!self::validName($database)) {
            throw new \InvalidArgumentException('database');
        }

        if (!self::validUser($user)) {
            throw new \InvalidArgumentException('user');
        }

        if (!\in_array($ssl, self::SSL_MODES, true)) {
            throw new \InvalidArgumentException('ssl');
        }

        if ($caFile !== '' && !self::validPath($caFile)) {
            throw new \InvalidArgumentException('ca');
        }

        if ($engine === self::ENGINE_PGSQL && $ssl === self::SSL_VERIFY && $caFile === '') {
            throw new \InvalidArgumentException('ca');
        }

        if ($viaIp !== '' && ($engine !== self::ENGINE_PGSQL || filter_var($viaIp, FILTER_VALIDATE_IP) === false)
            || $viaPort < 0 || $viaPort > 65535 || $viaIp === '' && $viaPort !== 0) {
            throw new \InvalidArgumentException('via');
        }
    }

    /**
     * "host:port", "host", "[IPv6]:port" or an IPv6 address alone; the default port when none.
     *
     * @return array{0: string, 1: int}
     *
     * @throws \InvalidArgumentException
     */
    public static function parseAddress(string $address, int $defaultPort): array
    {
        $address = strtolower(trim($address));

        if (preg_match('/^\[([0-9a-f:.]+)\](?::(\d{1,5}))?$/', $address, $m)) {
            [$host, $port] = [$m[1], isset($m[2]) ? (int) $m[2] : $defaultPort];
        } elseif (substr_count($address, ':') > 1) {
            [$host, $port] = [$address, $defaultPort];
        } elseif (preg_match('/^([^:]+)(?::(\d{1,5}))?$/', $address, $m)) {
            [$host, $port] = [$m[1], isset($m[2]) ? (int) $m[2] : $defaultPort];
        } else {
            throw new \InvalidArgumentException('address');
        }

        if (!self::validHost($host) || $port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('address');
        }

        return [$host, $port];
    }

    /** "host:port" for the form ("[IPv6]:port"). */
    public static function formatAddress(string $host, int $port): string
    {
        return (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $port;
    }

    public static function validHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return \strlen($host) <= 253
            && (bool) preg_match('/^[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/', $host);
    }

    public static function validName(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_][A-Za-z0-9_$-]{0,62}$/', $name);
    }

    public static function validUser(string $user): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.@-]{0,62}$/', $user);
    }

    /** A file path without control characters, quotes or ";" (which PDO would turn into a space). */
    public static function validPath(string $path): bool
    {
        return \strlen($path) <= 500 && !preg_match('/[\x00-\x1F\x7F;\'"\\\\]/', $path) && $path === trim($path);
    }

    public function password(): string
    {
        return $this->password;
    }

    /** The PDO data source name; no credentials (they are passed to PDO separately). */
    public function dsn(): string
    {
        if ($this->engine === self::ENGINE_SQLSRV) {
            $parts = [
                'Server=' . (str_contains($this->host, ':') ? '[' . $this->host . ']' : $this->host) . ',' . $this->port,
                'Database=' . $this->database,
                'LoginTimeout=' . $this->connectTimeout,
                'Encrypt=' . ($this->ssl === self::SSL_OFF ? 'no' : 'yes'),
                'TrustServerCertificate=' . ($this->ssl === self::SSL_VERIFY ? 'no' : 'yes'),
                // Read only: never sends a change, and is routed to a read-only replica if there is one.
                'ApplicationIntent=ReadOnly',
                'APP=Joomla ZKBioTime portal',
            ];

            return 'sqlsrv:' . implode(';', $parts);
        }

        $parts = [
            'host=' . self::quote($this->host),
            'port=' . ($this->viaIp !== '' && $this->viaPort > 0 ? $this->viaPort : $this->port),
            'dbname=' . self::quote($this->database),
            'connect_timeout=' . $this->connectTimeout,
            'sslmode=' . match ($this->ssl) {
                self::SSL_VERIFY  => 'verify-full',
                self::SSL_REQUIRE => 'require',
                default           => 'disable',
            },
            'application_name=' . self::quote('Joomla ZKBioTime portal'),
            // Every transaction of this connection is read only, whatever the user may do, and no
            // query runs longer than the query timeout.
            'options=' . self::quote('-c default_transaction_read_only=on -c statement_timeout=' . ($this->queryTimeout * 1000)),
        ];

        if ($this->viaIp !== '') {
            // Connect to this IP address; the certificate is still checked against host.
            $parts[] = 'hostaddr=' . self::quote($this->viaIp);
        }

        if ($this->caFile !== '' && $this->ssl !== self::SSL_OFF) {
            $parts[] = 'sslrootcert=' . self::quote($this->caFile);
        }

        return 'pgsql:' . implode(';', $parts);
    }

    /** @return array<int, mixed> */
    public function pdoOptions(): array
    {
        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
            \PDO::ATTR_TIMEOUT            => $this->connectTimeout,
        ];

        if ($this->engine === self::ENGINE_SQLSRV) {
            unset($options[\PDO::ATTR_EMULATE_PREPARES]);

            if (\defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')) {
                $options[\constant('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')] = $this->queryTimeout;
            }
        }

        return $options;
    }

    /** PHP extension the engine needs. */
    public function extension(): string
    {
        return $this->engine === self::ENGINE_SQLSRV ? 'pdo_sqlsrv' : 'pdo_pgsql';
    }

    /** libpq connection value in single quotes (backslash and quote escaped). */
    private static function quote(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    public function __debugInfo(): array
    {
        return ['engine' => $this->engine, 'host' => $this->host, 'port' => $this->port, 'database' => $this->database, 'user' => $this->user, 'password' => '[hidden]', 'ssl' => $this->ssl];
    }
}
