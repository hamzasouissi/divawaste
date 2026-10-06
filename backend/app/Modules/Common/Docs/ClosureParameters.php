<?php

namespace App\Modules\Common\Docs;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\ParsesValidationRules;
use Knuckles\Scribe\Extracting\Strategies\Strategy;

/**
 * Parameters validated inside a route closure (`$request->validate([...])`): query string for a GET, body otherwise.
 */
abstract class ClosureParameters extends Strategy
{
    use ParsesValidationRules;

    abstract protected function inQuery(): bool;

    public function __invoke(ExtractedEndpointData $endpointData, array $settings = []): ?array
    {
        $reads = in_array('GET', $endpointData->httpMethods, true) || in_array('DELETE', $endpointData->httpMethods, true);
        $rules = $reads === $this->inQuery() ? (RouteSource::of($endpointData->route)?->validationRules() ?? []) : [];
        if ($rules === []) {
            return [];
        }

        $parameters = $this->getParametersFromValidationRules($rules, array_fill_keys(array_keys($rules), ['description' => '']));
        if ($this->inQuery()) {
            // Filters are optional: an invented value would only make the example call fail or return nothing.
            foreach ($parameters as $name => $parameter) {
                $parameters[$name]['example'] = ($parameter['required'] ?? false) ? (DemoData::example((string) $name, $endpointData->uri) ?? $parameter['example'] ?? null) : null;
            }
        }

        return $this->normaliseArrayAndObjectParameters($parameters);
    }
}
