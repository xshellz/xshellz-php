<?php

declare(strict_types=1);

namespace Xshellz;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Xshellz\Exceptions\ApiException;
use Xshellz\Exceptions\AuthException;
use Xshellz\Exceptions\QuotaException;
use Xshellz\Exceptions\XshellzException;

/**
 * Thin, Bearer-authenticated JSON client for the xShellz control plane
 * (https://api.xshellz.com/v1) with typed error mapping.
 */
final class ApiClient
{
    private const USER_AGENT = 'xshellz-php/0.2.0';

    /**
     * 403 message fragments emitted by the control plane's guard chain. All
     * guards abort(403, message); quota/entitlement are distinguished by
     * message text.
     */
    private const QUOTA_FRAGMENTS = [
        'agent shell limit',
        'plan does not include agent shells',
    ];

    public readonly string $apiUrl;

    private readonly Client $client;

    /**
     * @param callable|null $handler Guzzle handler override (tests inject a MockHandler stack here).
     *
     * @throws AuthException When no API key can be resolved.
     */
    public function __construct(
        ?string $apiKey = null,
        ?string $apiUrl = null,
        float $timeout = 120.0,
        ?callable $handler = null,
    ) {
        $this->apiUrl = Config::resolveApiUrl($apiUrl);

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . Config::resolveApiKey($apiKey),
                'Accept' => 'application/json',
                'User-Agent' => self::USER_AGENT,
            ],
            'timeout' => $timeout,
            'http_errors' => false,
        ];
        if ($handler !== null) {
            $options['handler'] = $handler instanceof HandlerStack
                ? $handler
                : HandlerStack::create($handler);
        }

        $this->client = new Client($options);
    }

    /**
     * Issue a request; return the decoded JSON body or throw a typed error.
     *
     * @param array<string, mixed>|null $json
     *
     * @throws AuthException
     * @throws QuotaException
     * @throws ApiException
     */
    public function request(string $method, string $path, ?array $json = null): mixed
    {
        $options = [];
        if ($json !== null) {
            $options['json'] = $json;
        }

        $response = $this->client->request($method, $this->apiUrl . $path, $options);

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();

        if ($status >= 400) {
            throw self::mapError($status, $raw);
        }

        if ($raw === '') {
            return null;
        }

        return json_decode($raw, true);
    }

    /**
     * @throws AuthException
     * @throws QuotaException
     * @throws ApiException
     */
    public function get(string $path): mixed
    {
        return $this->request('GET', $path);
    }

    /**
     * @param array<string, mixed>|null $json
     *
     * @throws AuthException
     * @throws QuotaException
     * @throws ApiException
     */
    public function post(string $path, ?array $json = null): mixed
    {
        return $this->request('POST', $path, $json);
    }

    /**
     * @param array<string, mixed>|null $json
     *
     * @throws AuthException
     * @throws QuotaException
     * @throws ApiException
     */
    public function put(string $path, ?array $json = null): mixed
    {
        return $this->request('PUT', $path, $json);
    }

    /**
     * @throws AuthException
     * @throws QuotaException
     * @throws ApiException
     */
    public function delete(string $path): mixed
    {
        return $this->request('DELETE', $path);
    }

    private static function mapError(int $status, string $raw): XshellzException
    {
        $body = json_decode($raw, true);
        if ($body === null) {
            $body = $raw;
        }

        $message = '';
        $errorCode = '';
        if (is_array($body)) {
            $message = (string) ($body['message'] ?? '');
            $errorCode = (string) ($body['error'] ?? '');
        }
        if ($message === '') {
            $message = $raw !== '' ? $raw : "HTTP {$status}";
        }

        if ($status === 401) {
            return new AuthException(
                'Authentication failed (401): the API key is missing, invalid, '
                . 'expired, or revoked. Create a personal access token with `read` '
                . 'and `write` scopes from your xShellz dashboard (Settings -> API '
                . "tokens) or via POST /v1/auth/tokens. Server said: {$message}"
            );
        }

        if ($status === 403) {
            $lowered = strtolower($message);
            foreach (self::QUOTA_FRAGMENTS as $fragment) {
                if (str_contains($lowered, $fragment)) {
                    return new QuotaException(
                        "{$message} Tip: on the free tier only one sandbox may exist "
                        . 'at a time - use Sandbox::list() and Sandbox::connect() to '
                        . 'attach to the existing box, or kill() it first.'
                    );
                }
            }
            if ($errorCode === 'verification_required') {
                return new AuthException("Account verification required (403): {$message}");
            }

            return new AuthException("Forbidden (403): {$message}");
        }

        if ($status === 429) {
            return new ApiException(
                'Rate limited (429): ' . ($message !== '' ? $message : 'too many requests')
                . ' - sandbox creation is throttled to 10 requests/minute.',
                statusCode: $status,
                body: $body,
            );
        }

        return new ApiException("HTTP {$status}: {$message}", statusCode: $status, body: $body);
    }
}
