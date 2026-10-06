<?php

namespace App\Modules\Common\Docs;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Strategy;

/**
 * The errors every route answers the same way (RFC 7807 `application/problem+json`, see ProblemDetails), limited to
 * the ones this route can actually produce.
 */
final class StandardErrors extends Strategy
{
    public function __invoke(ExtractedEndpointData $endpointData, array $settings = []): array
    {
        $source = RouteSource::of($endpointData->route);
        $problem = fn (int $status, string $title, string $code, array $extra = []) => json_encode(
            ['type' => 'about:blank', 'title' => $title, 'status' => $status, 'code' => $code] + $extra + ['request_id' => '01J9ZX5E8Q4T7M2K6R3W0V1YNB'], JSON_UNESCAPED_SLASHES);
        $documented = collect($endpointData->responses->all())->pluck('status')->map(fn ($status) => (int) $status)->all();
        $responses = [];

        if ($endpointData->metadata->authenticated) {
            $responses[] = ['status' => 401, 'description' => 'No valid session or token.', 'content' => $problem(401, 'Unauthorized', 'UNAUTHENTICATED')];
        }
        $permissions = $source?->permissions() ?? [];
        if ($endpointData->metadata->authenticated) {
            $responses[] = ['status' => 403, 'content' => $problem(403, 'Forbidden', 'FORBIDDEN'), 'description' => $permissions === []
                ? 'The account may not use this route (wrong kind of account, or company not active).'
                : 'The user does not hold '.implode(' or ', array_map(fn (string $permission) => "`{$permission}`", $permissions)).' (on the site concerned, for site permissions).'];
        }
        if ($endpointData->urlParameters !== []) {
            $responses[] = ['status' => 404, 'description' => 'Unknown identifier, or a record of another company: both answer the same way.', 'content' => $problem(404, 'Not Found', 'NOT_FOUND')];
        }
        $codes = $source?->businessErrors() ?? [];
        if ($codes !== []) {
            $responses[] = ['status' => 409, 'description' => 'A business rule refuses the action. Possible codes: '.implode(', ', array_map(fn (string $code) => "`{$code}`", $codes)).'.',
                'content' => $problem(409, 'Conflict', $codes[0], ['detail' => 'Human-readable explanation of the rule.'])];
        }
        if ($endpointData->bodyParameters !== [] || $endpointData->queryParameters !== [] || $source?->formRequest() !== null) {
            $field = (string) (array_key_first($endpointData->bodyParameters) ?? array_key_first($endpointData->queryParameters) ?? 'field');
            $responses[] = ['status' => 422, 'description' => 'A parameter is missing or invalid; `errors` names each one.',
                'content' => $problem(422, 'Unprocessable Content', 'VALIDATION_FAILED', ['detail' => 'The given data was invalid.', 'errors' => [$field => ['The '.str_replace(['.', '_'], ' ', $field).' field is invalid.']]])];
        }

        return array_values(array_filter($responses, fn (array $response) => ! in_array($response['status'], $documented, true)));
    }
}
