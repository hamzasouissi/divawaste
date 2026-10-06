<?php

namespace App\Modules\Tenancy\Exceptions;

use LogicException;

final class CrossTenantWriteException extends LogicException
{
    public static function for(string $model): self
    {
        return new self("Tenant mismatch or company_id change on [{$model}].");
    }
}
