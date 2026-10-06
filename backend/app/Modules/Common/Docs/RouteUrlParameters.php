<?php

namespace App\Modules\Common\Docs;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Strategy;

/**
 * Identifiers in the URL are public ULIDs (or codes). With the demo dataset, the examples are real records so the
 * example responses are real too.
 */
final class RouteUrlParameters extends Strategy
{
    public function __invoke(ExtractedEndpointData $endpointData, array $settings = []): array
    {
        $parameters = [];
        foreach (array_keys($endpointData->urlParameters) as $name) {
            $example = DemoData::example((string) $name, $endpointData->uri);
            $parameters[$name] = [
                'name' => $name, 'type' => 'string', 'required' => true,
                'description' => DemoData::describe((string) $name),
                'example' => $example ?? $endpointData->urlParameters[$name]->example ?? '01J9ZX5E8Q4T7M2K6R3W0V1YNB',
            ];
        }

        return $parameters;
    }
}
