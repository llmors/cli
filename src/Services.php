<?php

declare(strict_types=1);

namespace Llmor\Cli;

use Llmor\Cli\Auth\AccessTokenSigner;
use Llmor\Cli\Auth\SessionManager;
use Llmor\Cli\Auth\SessionStore;
use Llmor\Cli\Client\LlmorClient;
use Llmor\Cli\Config\Configuration;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Tiny, explicit service container wiring the auth and client layers for a
 * single resolved {@see Configuration}.
 *
 * Kept deliberately framework-free (no DI component) so the static binary stays
 * small. The HTTP client is injectable so tests can pass a `MockHttpClient`.
 */
final class Services
{
    public readonly HttpClientInterface $http;
    public readonly AccessTokenSigner $signer;
    public readonly SessionStore $store;
    public readonly SessionManager $sessions;
    public readonly LlmorClient $client;

    public function __construct(
        public readonly Configuration $config,
        ?HttpClientInterface $http = null,
    ) {
        $this->http = $http ?? HttpClient::create();
        $this->signer = new AccessTokenSigner();
        $this->store = new SessionStore($config->sessionFile());
        $this->sessions = new SessionManager($this->http, $this->signer, $this->store, $config);
        $this->client = new LlmorClient($this->http, $this->sessions, $this->signer, $config->host);
    }
}
