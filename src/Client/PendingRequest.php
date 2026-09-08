<?php

declare(strict_types=1);

namespace Llmor\Cli\Client;

use Llmor\Cli\Client\Exception\ApiException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * An in-flight request whose body has not been read yet.
 *
 * A chat turn is one long POST that blocks until the model is done, while the live
 * output arrives on a *separate* socket. Single-threaded PHP therefore has to advance
 * both: {@see pump()} gives the HTTP transfer a slice of time without committing to
 * waiting for the whole response, so the caller can interleave it with reading the relay.
 *
 * Unlike {@see LlmorClient::request()} there is no transparent 401 retry here. Re-sending
 * a POST that may already have been accepted would double-post the user's message; the
 * session is refreshed by the request that precedes every turn, so a 401 at this point is
 * a genuine failure and surfaces as one.
 */
final class PendingRequest
{
    private ?ApiResponse $result = null;

    /**
     * @param callable(string, int): array<string, mixed>       $decode
     * @param callable(int, array<string, mixed>): ApiException $mapError
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly ResponseInterface $response,
        private $decode,
        private $mapError,
    ) {
    }

    /**
     * Give the transfer up to $timeout seconds of attention, then return.
     *
     * Returns true once the response is complete and {@see result()} will not block.
     */
    public function pump(float $timeout = 0.0): bool
    {
        if (null !== $this->result) {
            return true;
        }

        try {
            foreach ($this->http->stream([$this->response], $timeout) as $chunk) {
                if ($chunk->isTimeout()) {
                    return false;
                }

                if ($chunk->isLast()) {
                    return true;
                }
            }
        } catch (HttpExceptionInterface $e) {
            // surface transport failures from result(), so every caller has one place
            // to catch them rather than two
            throw new ApiException('Unable to reach the llmor API: '.$e->getMessage(), 0, [], $e);
        }

        return false;
    }

    /**
     * Block until the response is complete, then map it the way {@see LlmorClient} does.
     *
     * @throws ApiException on a transport failure or a non-2xx status
     */
    public function result(): ApiResponse
    {
        if (null !== $this->result) {
            return $this->result;
        }

        try {
            $status = $this->response->getStatusCode();
            $content = $this->response->getContent(false);
        } catch (HttpExceptionInterface $e) {
            throw new ApiException('Unable to reach the llmor API: '.$e->getMessage(), 0, [], $e);
        }

        $body = ($this->decode)($content, $status);

        if ($status < 200 || $status >= 300) {
            throw ($this->mapError)($status, $body);
        }

        return $this->result = new ApiResponse($status, $body);
    }
}
