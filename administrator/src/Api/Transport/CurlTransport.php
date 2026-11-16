<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Api\Transport;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Api\ClientConfig;

/**
 * HTTP transport over one cURL handle that stays open for the whole page load, so the TCP and TLS
 * connection to ZKBioTime is opened once and reused by every request (cURL keeps it alive), instead
 * of a new handshake per request. The security options are the same as before: certificate and
 * host checks, CA bundle, certificate pin and "connect via"; redirects are never followed.
 */
final class CurlTransport implements TransportInterface
{
    /** @var \CurlHandle|null */
    private $handle = null;

    public function __construct(private readonly ClientConfig $config)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body): TransportResponse
    {
        if (!\in_array($method, ['GET', 'POST'], true)) {
            throw new \InvalidArgumentException('Unsupported HTTP method');
        }

        $handle = $this->handle();
        $lines  = [];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        // Per-request options. The connection options stay as set when the handle was created.
        curl_setopt_array($handle, [
            CURLOPT_URL        => $url,
            CURLOPT_HTTPHEADER => $lines,
        ]);

        if ($method === 'POST') {
            curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body ?? '']);
        } else {
            curl_setopt($handle, CURLOPT_HTTPGET, true);
        }

        $response = curl_exec($handle);

        if ($response === false) {
            $error = curl_error($handle);
            $code  = curl_errno($handle);

            // A broken handle is not reused: the next request starts a fresh connection.
            $this->close();

            // cURL error texts never contain request headers. The text is still shortened and
            // cleaned here, and ZkbClient removes secrets again before logging.
            throw new TransportException('Could not reach ZKBioTime (' . self::describe($error, $code) . ': ' . self::detail($error) . ')');
        }

        return new TransportResponse((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), (string) $response);
    }

    public function __destruct()
    {
        $this->close();
    }

    /** @return \CurlHandle */
    private function handle()
    {
        if ($this->handle !== null) {
            return $this->handle;
        }

        $handle = curl_init();

        if ($handle === false) {
            throw new TransportException('Could not reach ZKBioTime (network error: cURL could not start)');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_CONNECTTIMEOUT => $this->config->connectTimeout,
            CURLOPT_TIMEOUT        => $this->config->timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => $this->config->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->config->verifyTls ? 2 : 0,
            CURLOPT_TCP_KEEPALIVE  => 1,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'Joomla ZKBioTime portal',
        ];

        if (\defined('CURLOPT_REDIR_PROTOCOLS')) {
            $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        $connectTo = $this->config->connectToEntry();

        if ($connectTo !== null) {
            // Open the connection to the "connect via" address (e.g. the firewall's public name)
            // while SNI, the certificate name check and the Host header keep the server URL's host.
            $options[CURLOPT_CONNECT_TO] = [$connectTo];
        }

        if ($this->config->caBundle !== '') {
            $options[CURLOPT_CAINFO] = $this->config->caBundle;
        }

        if ($this->config->isPinned()) {
            // The pinned public key identifies the server instead of a CA and host name, so an IP
            // address or a self-signed certificate works. cURL aborts before any data (including
            // the credentials) is sent if the server's key differs.
            $options[CURLOPT_PINNEDPUBLICKEY] = $this->config->pinnedPublicKey;
            $options[CURLOPT_SSL_VERIFYPEER]  = false;
            $options[CURLOPT_SSL_VERIFYHOST]  = 0;
        }

        curl_setopt_array($handle, $options);

        return $this->handle = $handle;
    }

    private function close(): void
    {
        if ($this->handle !== null) {
            curl_close($this->handle);
            $this->handle = null;
        }
    }

    /**
     * The cURL error text, one line, at most 200 characters, without any query string.
     */
    private static function detail(string $error): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $error) ?? '';
        $text = preg_replace('/\?[^\s\'"]*/u', '', $text) ?? '';
        $text = trim($text);

        return $text !== '' ? mb_substr($text, 0, 200) : 'no details';
    }

    private static function describe(string $error, int $code): string
    {
        $message = strtolower($error);

        return match (true) {
            $code === CURLE_OPERATION_TIMEDOUT || str_contains($message, 'timed out')         => 'timeout',
            str_contains($message, 'pinned')                                                 => 'certificate pin mismatch',
            str_contains($message, 'ssl') || str_contains($message, 'certificate')           => 'TLS/certificate error',
            $code === CURLE_COULDNT_RESOLVE_HOST || str_contains($message, 'resolve')         => 'host not found',
            $code === CURLE_COULDNT_CONNECT || str_contains($message, 'refused')              => 'connection failed',
            default                                                                           => 'network error',
        };
    }
}
