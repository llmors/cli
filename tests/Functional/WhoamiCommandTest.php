<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Functional;

use Llmor\Cli\Command\Auth\WhoamiCommand;
use Llmor\Cli\Config\Configuration;
use Llmor\Cli\Services;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Exercises the full signed-request pipeline (session create -> sign-in ->
 * authenticated GET, and the transparent 401 re-authentication) against a
 * mocked HTTP transport.
 */
#[CoversClass(WhoamiCommand::class)]
#[CoversClass(Services::class)]
final class WhoamiCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir().'/llmor-fn-'.\uniqid('', true);
        \mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        @\unlink($this->dir.'/session.json');
        @\rmdir($this->dir);
    }

    public function testWhoamiAuthenticatesAndShowsUser(): void
    {
        $calls = [];
        $http = $this->mockApi($calls, userStatuses: [200]);
        $tester = $this->commandTester($http);

        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('admin@test.llmor', $tester->getDisplay());
        self::assertSame(
            ['/v1/auth/session', '/v1/auth/signin', '/v1/auth/session/user'],
            $calls,
            'Expected create-session, sign-in, then the authenticated request.',
        );
    }

    public function testWhoamiReauthenticatesOnExpiredSession(): void
    {
        $calls = [];
        // First authenticated call is rejected (expired session), second succeeds.
        $http = $this->mockApi($calls, userStatuses: [401, 200]);
        $tester = $this->commandTester($http);

        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('admin@test.llmor', $tester->getDisplay());
        self::assertSame(
            [
                '/v1/auth/session', '/v1/auth/signin', '/v1/auth/session/user',
                '/v1/auth/session', '/v1/auth/signin', '/v1/auth/session/user',
            ],
            $calls,
            'A 401 must trigger one full re-authentication and a single retry.',
        );
    }

    private function commandTester(MockHttpClient $http): CommandTester
    {
        $config = new Configuration('https://api.test', 'admin@test.llmor', 'pw', $this->dir);
        $services = new Services($config, $http);

        return new CommandTester(new WhoamiCommand($services->client));
    }

    /**
     * @param list<string> $calls        captures the request path order
     * @param list<int>    $userStatuses status codes to return for successive /session/user calls
     */
    private function mockApi(array &$calls, array $userStatuses): MockHttpClient
    {
        $userIndex = 0;

        $factory = function (string $method, string $url, array $options) use (&$calls, &$userIndex, $userStatuses): MockResponse {
            $path = (string) \parse_url($url, \PHP_URL_PATH);
            $calls[] = $path;

            $user = ['data' => ['id' => 1, 'email' => 'admin@test.llmor', 'firstname' => 'Admin', 'lastname' => 'User']];

            return match (true) {
                \str_ends_with($path, '/v1/auth/session') => new MockResponse(
                    (string) \json_encode(['token' => 'tok-'.\count($calls), 'secret' => 'sec']),
                    ['http_code' => 200],
                ),
                \str_ends_with($path, '/v1/auth/signin') => new MockResponse(
                    (string) \json_encode($user),
                    ['http_code' => 200],
                ),
                \str_ends_with($path, '/v1/auth/session/user') => new MockResponse(
                    ($status = $userStatuses[$userIndex++] ?? 200) === 200
                        ? (string) \json_encode($user)
                        : (string) \json_encode(['message' => 'Invalid access token']),
                    ['http_code' => $status],
                ),
                default => new MockResponse('{}', ['http_code' => 404]),
            };
        };

        return new MockHttpClient($factory, 'https://api.test');
    }
}
