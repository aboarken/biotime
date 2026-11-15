<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Api;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Connection settings for the ZKBioTime server. Contains no secrets.
 */
final class ClientConfig
{
    /** scheme://host[:port][/path] with no trailing slash. */
    public readonly string $baseUrl;

    /** Normalised pin list ("sha256//...;sha256//..."), or '' when not pinning. */
    public readonly string $pinnedPublicKey;

    /**
     * "host:port" to open the TCP connection to instead of the server URL's host, or ''.
     * The server URL's host is still used for the certificate check (SNI) and the Host header,
     * e.g. server URL https://zkb.example.com reached through the public api.zkb.example.com.
     */
    public readonly string $connectVia;

    /**
     * @param  string  $serverUrl  e.g. "https://biotime.example.com" or "http://10.0.0.5"
     * @param  int     $port        0 = the port in the URL, or the scheme's default
     * @param  string  $connectVia  "host", "host:port" or "IP:port" to connect to instead; '' = the server URL
     */
    public function __construct(
        string $serverUrl,
        int $port = 0,
        public readonly int $timeout = 30,
        public readonly int $connectTimeout = 5,
        public readonly bool $verifyTls = true,
        public readonly string $caBundle = '',
        string $pinnedPublicKey = '',
        string $connectVia = ''
    ) {
        $this->baseUrl         = self::buildBaseUrl($serverUrl, $port);
        $this->pinnedPublicKey = SslPin::normalize($pinnedPublicKey);
        $this->connectVia      = self::normalizeConnectVia($connectVia, $this->port());

        if ($timeout < 1 || $timeout > 300 || $connectTimeout < 1 || $connectTimeout > 60 || $connectTimeout > $timeout) {
            throw new \InvalidArgumentException('Invalid timeout values');
        }

        if ($this->pinnedPublicKey !== '' && !$this->isHttps()) {
            throw new \InvalidArgumentException('Certificate pinning needs an https server URL');
        }
    }

    public function isPinned(): bool
    {
        return $this->pinnedPublicKey !== '';
    }

    public function isHttps(): bool
    {
        return str_starts_with($this->baseUrl, 'https://');
    }

    public function host(): string
    {
        return (string) parse_url($this->baseUrl, PHP_URL_HOST);
    }

    public function port(): int
    {
        $port = parse_url($this->baseUrl, PHP_URL_PORT);

        return $port ? (int) $port : ($this->isHttps() ? 443 : 80);
    }

    /**
     * cURL CURLOPT_CONNECT_TO entry "url-host:url-port:via-host:via-port", or null.
     */
    public function connectToEntry(): ?string
    {
        if ($this->connectVia === '') {
            return null;
        }

        return $this->host() . ':' . $this->port() . ':' . $this->connectVia;
    }

    /**
     * Accepts "host", "host:port", "1.2.3.4:8080" or "[::1]:8080"; the port defaults to the server
     * URL's port. Returns "host:port", or '' for empty input.
     *
     * @throws \InvalidArgumentException
     */
    public static function normalizeConnectVia(string $value, int $defaultPort): string
    {
        $value = strtolower(trim($value));

        if ($value === '') {
            return '';
        }

        if (!preg_match('/^(\[[0-9a-f:.]+\]|[a-z0-9.-]+)(?::([0-9]{1,5}))?$/', $value, $m)) {
            throw new \InvalidArgumentException('Connect via must be host or host:port');
        }

        $host = $m[1];
        $port = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : $defaultPort;
        $bare = trim($host, '[]');

        $isIp   = filter_var($bare, FILTER_VALIDATE_IP) !== false;
        $isName = !str_starts_with($host, '[')
            && preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $host) === 1;

        if ((!$isIp && !$isName) || ($host !== $bare && !filter_var($bare, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) || $port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Connect via must be host or host:port');
        }

        return $host . ':' . $port;
    }

    /**
     * Combine the server URL and the port field into scheme://host[:port][/path].
     *
     * @throws \InvalidArgumentException
     */
    public static function buildBaseUrl(string $url, int $port = 0): string
    {
        $url   = trim($url);
        $parts = parse_url($url);

        if ($url === '' || $parts === false || empty($parts['host']) || empty($parts['scheme'])) {
            throw new \InvalidArgumentException('Server URL must be an absolute http(s) URL');
        }

        $scheme = strtolower($parts['scheme']);

        if (!\in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Server URL must use http or https');
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Server URL must not contain credentials, a query or a fragment');
        }

        $path = rtrim($parts['path'] ?? '', '/');

        if ($path !== '' && !preg_match('#^(/[A-Za-z0-9._~-]+)+$#', $path)) {
            throw new \InvalidArgumentException('Server URL path contains invalid characters');
        }

        if ($port < 0 || $port > 65535) {
            throw new \InvalidArgumentException('Port must be between 1 and 65535');
        }

        $urlPort = isset($parts['port']) ? (int) $parts['port'] : 0;

        if ($port > 0 && $urlPort > 0 && $port !== $urlPort) {
            throw new \InvalidArgumentException('The port field and the port in the server URL differ');
        }

        $port    = $port ?: $urlPort;
        $default = $scheme === 'https' ? 443 : 80;
        $host    = strtolower($parts['host']);

        return $scheme . '://' . $host . ($port > 0 && $port !== $default ? ':' . $port : '') . $path;
    }
}
