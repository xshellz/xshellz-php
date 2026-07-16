<?php

declare(strict_types=1);

namespace Xshellz\Exceptions;

/**
 * The account's sandbox quota or plan entitlement blocks the operation.
 *
 * The control plane returns HTTP 403 both when the plan's concurrent sandbox
 * limit is reached ("agent shell limit") and when the plan does not include
 * sandboxes at all. On the free tier the limit is 1 concurrent box - use
 * Sandbox::list() + Sandbox::connect() to attach to the existing one instead
 * of creating a new box.
 */
class QuotaException extends XshellzException
{
}
