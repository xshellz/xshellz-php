<?php

declare(strict_types=1);

namespace Xshellz\Exceptions;

/**
 * Authentication or authorization failed (HTTP 401/403).
 *
 * Raised for a missing/invalid API key, insufficient token scopes, account
 * verification requirements, and other access gates.
 */
class AuthException extends XshellzException
{
}
