<?php

namespace App\Modules\Tenancy\Exceptions;

use LogicException;

/**
 * A tenant-owned model was queried or written with no tenant context: fail closed.
 */
final class MissingTenantContextException extends LogicException
{
    public static function for(string $model): self
    {
        return new self("No tenant context while accessing tenant model [{$model}].");
    }
}
