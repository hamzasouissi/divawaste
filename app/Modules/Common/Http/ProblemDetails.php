<?php

namespace App\Modules\Common\Http;

use App\Modules\Common\Exceptions\BusinessRuleViolation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * RFC 9457 problem+json rendering for every API error (blueprint §22.1).
 */
final class ProblemDetails
{
    public static function render(Throwable $e, Request $request): JsonResponse
    {
        [$status, $code, $detail, $errors] = self::describe($e);

        $body = array_filter([
            'type' => 'about:blank',
            'title' => JsonResponse::$statusTexts[$status] ?? 'Error',
            'status' => $status,
            'code' => $code,
            'detail' => $detail,
            'errors' => $errors,
            'request_id' => Context::get('request_id'),
        ], fn ($value) => $value !== null);

        $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];

        return new JsonResponse($body, $status, $headers + ['Content-Type' => 'application/problem+json']);
    }

    /**
     * @return array{int, string, ?string, ?array<string, list<string>>}
     */
    private static function describe(Throwable $e): array
    {
        return match (true) {
            $e instanceof BusinessRuleViolation => [409, $e->errorCode, $e->getMessage(), null],
            $e instanceof ValidationException => [422, 'VALIDATION_FAILED', 'The given data was invalid.', $e->errors()],
            $e instanceof AuthenticationException => [401, 'UNAUTHENTICATED', null, null],
            $e instanceof AuthorizationException => [403, 'FORBIDDEN', null, null],
            // Never reveal whether a record of another tenant exists.
            $e instanceof ModelNotFoundException => [404, 'NOT_FOUND', null, null],
            $e instanceof HttpExceptionInterface => [
                $e->getStatusCode(),
                Str::upper(Str::snake(JsonResponse::$statusTexts[$e->getStatusCode()] ?? 'error')),
                null,
                null,
            ],
            default => [500, 'SERVER_ERROR', config('app.debug') ? $e->getMessage() : null, null],
        };
    }
}
