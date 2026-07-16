<?php

declare(strict_types=1);

namespace Xshellz\Exceptions;

/**
 * A command executed with run(timeoutSeconds: ...) exceeded its deadline.
 */
class CommandTimeoutException extends XshellzException
{
}
