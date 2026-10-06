<?php

namespace App\Modules\Common\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A domain rule rejected the operation (rendered as 409 problem+json with a stable code).
 */
final class BusinessRuleViolation extends ConflictHttpException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
