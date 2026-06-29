<?php

declare(strict_types=1);

namespace Llmor\Cli\Auth;

/**
 * Builds the `X-AccessToken` header value for an authenticated request.
 *
 * This mirrors the server-side validation in llmonrails
 * (`App\Auth\SessionManager::createAccessTokenSignature`): the signed payload
 * is `METHOD:REQUEST_URI:TIMESTAMP` where REQUEST_URI is the full path
 * including query string (without the host), HMAC-SHA256 signed with the
 * session secret. The result is a base64-encoded JSON document.
 *
 * Pure and side-effect free: the timestamp is injected so it can be tested
 * deterministically.
 */
final class AccessTokenSigner
{
    /**
     * @param string $method     HTTP method, upper-cased to match the wire request
     * @param string $requestUri Path + query string exactly as it will be sent
     */
    public function sign(string $method, string $requestUri, Session $session, int $timestamp): string
    {
        $payload = $method.':'.$requestUri.':'.$timestamp;
        $signature = \hash_hmac('sha256', $payload, $session->secret);

        $token = \json_encode([
            'token' => $session->token,
            'timestamp' => $timestamp,
            'signature' => $signature,
        ], \JSON_THROW_ON_ERROR);

        return \base64_encode($token);
    }
}
