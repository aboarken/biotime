<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZkbPortal\Component\Zkbiotime\Administrator\Api;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Decrypted API credentials. Lives only inside ZkbClient; never serialised, logged or rendered.
 *
 *  - jwt:   username + password are exchanged for a JWT ("Authorization: JWT <token>").
 *  - token: a General Auth Token ("Authorization: Token <token>"), either entered directly
 *           (static, cannot be renewed) or obtained with username + password.
 */
final class Credentials
{
    public const METHOD_JWT   = 'jwt';
    public const METHOD_TOKEN = 'token';

    public const METHODS = [self::METHOD_JWT, self::METHOD_TOKEN];

    public function __construct(
        public readonly string $method,
        public readonly string $username,
        #[\SensitiveParameter] private readonly string $password,
        #[\SensitiveParameter] private readonly string $staticToken = ''
    ) {
        if (!\in_array($method, self::METHODS, true)) {
            throw new \InvalidArgumentException('Unknown authentication method');
        }

        if ($method === self::METHOD_JWT && ($username === '' || $password === '')) {
            throw new \InvalidArgumentException('JWT authentication needs a username and password');
        }

        if ($method === self::METHOD_TOKEN && $staticToken === '' && ($username === '' || $password === '')) {
            throw new \InvalidArgumentException('Token authentication needs a token, or a username and password');
        }
    }

    public function password(): string
    {
        return $this->password;
    }

    public function staticToken(): string
    {
        return $this->method === self::METHOD_TOKEN ? $this->staticToken : '';
    }

    /** A static token cannot be renewed: an "unauthorised" answer is final. */
    public function canRenew(): bool
    {
        return $this->staticToken() === '';
    }

    /** Authorization header scheme for this method. */
    public function scheme(): string
    {
        return $this->method === self::METHOD_JWT ? 'JWT' : 'Token';
    }

    /** @return list<string> Values Redactor must remove. */
    public function secrets(): array
    {
        return array_values(array_filter([$this->password, $this->staticToken]));
    }

    public function __debugInfo(): array
    {
        return ['method' => $this->method, 'username' => $this->username, 'password' => '[redacted]', 'staticToken' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Credentials cannot be serialised');
    }
}
