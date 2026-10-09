<?php

namespace App\Modules\Common\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A domain rule rejected the operation (rendered as 409 problem+json with a stable code).
 */
final class BusinessRuleViolation extends ConflictHttpException
{
    /**
     * @param  array<string, mixed>  $context  figures behind the refusal, for a client that wants to show them
     */
    public function __construct(public readonly string $errorCode, string $message, public readonly array $context = [])
    {
        parent::__construct($message);
    }
}
