<?php

declare(strict_types=1);

namespace Xshellz;

use Xshellz\Exceptions\AuthException;

/**
 * Configuration resolution: explicit argument > environment > default.
 */
final class Config
{
    public const DEFAULT_API_URL = 'https://api.xshellz.com/v1';

    public const API_KEY_ENV = 'XSHELLZ_API_KEY';

    public const API_URL_ENV = 'XSHELLZ_API_URL';

    private function __construct()
    {
    }

    /**
     * Resolve the API key or throw a helpful AuthException.
     *
     * @throws AuthException
     */
    public static function resolveApiKey(?string $apiKey = null): string
    {
        $key = trim($apiKey ?? '');
        if ($key === '') {
            $key = trim((string) (getenv(self::API_KEY_ENV) ?: ''));
        }
        if ($key === '') {
            throw new AuthException(
                'No xShellz API key found. Pass apiKey: ... or set the '
                . self::API_KEY_ENV . ' environment variable. Create a personal '
                . 'access token with `read` and `write` scopes from your xShellz '
                . 'dashboard (Settings -> API tokens) or via POST /v1/auth/tokens.'
            );
        }

        return $key;
    }

    /**
     * Resolve the API base URL (no trailing slash).
     */
    public static function resolveApiUrl(?string $apiUrl = null): string
    {
        $url = trim($apiUrl ?? '');
        if ($url === '') {
            $url = trim((string) (getenv(self::API_URL_ENV) ?: ''));
        }
        if ($url === '') {
            $url = self::DEFAULT_API_URL;
        }

        return rtrim($url, '/');
    }
}
