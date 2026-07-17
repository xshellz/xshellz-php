<?php

declare(strict_types=1);

namespace Xshellz\Exceptions;

/**
 * Sandbox::getOrCreate() found an existing sandbox by name but no private key
 * for it: none was passed explicitly and the keystore has no entry (or the
 * keystore is disabled).
 *
 * The message says exactly where a key was expected. Recover by passing
 * privateKey: ... explicitly, placing the OpenSSH private key at the expected
 * keystore path, or kill()-ing the box and letting getOrCreate() recreate it.
 */
class MissingKeyException extends XshellzException
{
}
