<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Tests\Support;

use BiotimePortal\Component\Biotime\Administrator\Api\ClientConfig;
use BiotimePortal\Component\Biotime\Administrator\Api\Credentials;
use BiotimePortal\Component\Biotime\Administrator\Api\MemoryTokenStore;
use BiotimePortal\Component\Biotime\Administrator\Api\TokenStoreInterface;
use BiotimePortal\Component\Biotime\Administrator\Api\ZkbClient;

/**
 * Builds a ZkbClient around FakeTransport with a controllable clock.
 */
final class ClientBuilder
{
    public const PASSWORD = 'S3cret-Pass!word';

    public FakeTransport $transport;

    public ArrayLogger $logger;

    public TokenStoreInterface $tokens;

    public int $now = 1_800_000_000;

    public function __construct()
    {
        $this->transport = new FakeTransport();
        $this->logger    = new ArrayLogger();
        $this->tokens    = new MemoryTokenStore();
    }

    public function client(?Credentials $credentials = null, int $pageSize = 100, int $maxPages = 50): ZkbClient
    {
        return new ZkbClient(
            $this->transport,
            new ClientConfig('https://biotime.example.com', 8443),
            $credentials ?? new Credentials(Credentials::METHOD_JWT, 'apiuser', self::PASSWORD),
            $this->tokens,
            $this->logger,
            $pageSize,
            $maxPages,
            fn (): int => $this->now,
            0
        );
    }

    /** A JWT whose "exp" claim is $exp (signature is not checked by the client). */
    public static function jwt(int $exp, string $tag = 'a'): string
    {
        $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

        return $b64('{"alg":"HS256","typ":"JWT"}') . '.' . $b64(json_encode(['user_id' => 1, 'exp' => $exp, 'tag' => $tag])) . '.' . $b64('signature-' . $tag);
    }
}
