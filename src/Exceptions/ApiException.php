<?php

declare(strict_types=1);

namespace Xshellz\Exceptions;

/**
 * Any other non-success API response (4xx/5xx).
 */
class ApiException extends XshellzException
{
    /**
     * @param int $statusCode The HTTP status code.
     * @param mixed $body The parsed JSON body if the response was JSON, else the raw text.
     */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly mixed $body = null,
    ) {
        parent::__construct($message);
    }
}
