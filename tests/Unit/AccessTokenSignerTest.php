<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Unit;

use Llmor\Cli\Auth\AccessTokenSigner;
use Llmor\Cli\Auth\Session;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AccessTokenSigner::class)]
final class AccessTokenSignerTest extends TestCase
{
    public function testSignatureMatchesServerSideAlgorithm(): void
    {
        $signer = new AccessTokenSigner();
        $session = new Session(token: 'sess-token', secret: 'super-secret', signedIn: true);
        $timestamp = 1_700_000_000;
        $method = 'GET';
        $uri = '/v1/auth/session/user';

        $header = $signer->sign($method, $uri, $session, $timestamp);

        $json = \base64_decode($header, true);
        if (!\is_string($json)) {
            self::fail('Access token is not valid base64.');
        }

        $decoded = \json_decode($json, true);
        self::assertIsArray($decoded);

        // Independently recompute the signature exactly as the server does:
        // hash_hmac('sha256', "METHOD:REQUEST_URI:TIMESTAMP", secret)
        $expectedSignature = \hash_hmac('sha256', "{$method}:{$uri}:{$timestamp}", 'super-secret');

        self::assertSame('sess-token', $decoded['token']);
        self::assertSame($timestamp, $decoded['timestamp']);
        self::assertSame($expectedSignature, $decoded['signature']);
    }

    public function testSignatureDependsOnUriAndMethod(): void
    {
        $signer = new AccessTokenSigner();
        $session = new Session('t', 's', true);

        $a = $signer->sign('GET', '/v1/a', $session, 100);
        $b = $signer->sign('POST', '/v1/a', $session, 100);
        $c = $signer->sign('GET', '/v1/b', $session, 100);

        self::assertNotSame($a, $b, 'Different HTTP methods must produce different tokens.');
        self::assertNotSame($a, $c, 'Different URIs must produce different tokens.');
    }
}
