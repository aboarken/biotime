<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Api\Transport;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\Http\Http;
use Joomla\Http\HttpFactory;
use BiotimePortal\Component\Biotime\Administrator\Api\ClientConfig;

/**
 * Transport backed by the Joomla Framework HTTP client (cURL adapter only).
 */
final class JoomlaHttpTransport implements TransportInterface
{
    private ?Http $http = null;

    public function __construct(private readonly ClientConfig $config)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body): TransportResponse
    {
        $http    = $this->http();
        $timeout = $this->config->timeout;

        try {
            $response = match ($method) {
                'GET'   => $http->get($url, $headers, $timeout),
                'POST'  => $http->post($url, $body ?? '', $headers, $timeout),
                default => throw new \InvalidArgumentException('Unsupported HTTP method'),
            };
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // cURL error texts never contain request headers. The text is still shortened and
            // cleaned here, and ZkbClient removes secrets again before logging.
            throw new TransportException('Could not reach ZKBioTime (' . $this->describe($e) . ': ' . self::detail($e) . ')', 0, $e);
        }

        return new TransportResponse($response->getStatusCode(), (string) $response->getBody());
    }

    private function http(): Http
    {
        if ($this->http !== null) {
            return $this->http;
        }

        $curl = [
            CURLOPT_CONNECTTIMEOUT => $this->config->connectTimeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => $this->config->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->config->verifyTls ? 2 : 0,
        ];

        $connectTo = $this->config->connectToEntry();

        if ($connectTo !== null) {
            // Open the connection to the "connect via" address (e.g. the firewall's public name)
            // while SNI, the certificate name check and the Host header keep the server URL's host.
            $curl[CURLOPT_CONNECT_TO] = [$connectTo];
        }

        if ($this->config->isPinned()) {
            // The pinned public key identifies the server instead of a CA and host name, so an IP
            // address or a self-signed certificate works. cURL aborts before any data (including
            // the credentials) is sent if the server's key differs.
            $curl[CURLOPT_PINNEDPUBLICKEY] = $this->config->pinnedPublicKey;
            $curl[CURLOPT_SSL_VERIFYPEER]  = false;
            $curl[CURLOPT_SSL_VERIFYHOST]  = 0;
        }

        $options = [
            'follow_location' => false,
            'transport.curl'  => $curl,
        ];

        if ($this->config->caBundle !== '') {
            $options['curl.certpath'] = $this->config->caBundle;
        }

        $this->http = (new HttpFactory())->getHttp($options, ['curl']);

        return $this->http;
    }

    /**
     * The cURL error text, one line, at most 200 characters, without any query string.
     */
    private static function detail(\Throwable $e): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $e->getMessage()) ?? '';
        $text = preg_replace('/\?[^\s\'"]*/u', '', $text) ?? '';
        $text = trim($text);

        return $text !== '' ? mb_substr($text, 0, 200) : 'no details';
    }

    private function describe(\Throwable $e): string
    {
        $message = strtolower($e->getMessage());

        return match (true) {
            str_contains($message, 'timed out') || str_contains($message, 'timeout') => 'timeout',
            str_contains($message, 'pinned')                                          => 'certificate pin mismatch',
            str_contains($message, 'ssl') || str_contains($message, 'certificate')    => 'TLS/certificate error',
            str_contains($message, 'resolve')                                         => 'host not found',
            str_contains($message, 'refused') || str_contains($message, 'connect')    => 'connection failed',
            default                                                                   => 'network error',
        };
    }
}
