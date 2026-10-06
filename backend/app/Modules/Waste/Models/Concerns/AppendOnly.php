<?php

namespace App\Modules\Waste\Models\Concerns;

use App\Modules\Common\Exceptions\BusinessRuleViolation;

/**
 * Traceability records are written once and never updated or deleted.
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        $refuse = fn () => throw new BusinessRuleViolation('APPEND_ONLY', 'Traceability history cannot be modified.');
        static::updating($refuse);
        static::deleting($refuse);
    }
}
