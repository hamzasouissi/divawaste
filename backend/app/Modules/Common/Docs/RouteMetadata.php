<?php

namespace App\Modules\Common\Docs;

use Illuminate\Support\Str;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Strategy;

/**
 * Group (the module), title (from the route name), description (the comment above the route and the permissions
 * it checks) and whether the route needs a session or a token (its middleware).
 */
final class RouteMetadata extends Strategy
{
    private const VERBS = ['index' => 'List', 'show' => 'Get', 'store' => 'Create', 'update' => 'Update', 'destroy' => 'Delete'];

    private const GROUPS = [
        'Identity' => 'Sign-in, account security, users, roles and invitations (M0).',
        'Tenancy' => 'Registration of a company, its profile, set-up wizard, and the platform review of accounts (M0).',
        'Billing' => 'Subscription, invoices and bank transfers of a client; plan activation and transfer review by the platform (M0-04, M7-04).',
        'Platform' => 'Back office of the SaaS operator: overview, plans and prices, invoices of every client, platform activity.',
        'Catalog' => 'Waste types of a company and the reference catalogue (M1).',
        'Sites' => 'Sites, zones, stock and stock thresholds (M0, M3).',
        'Waste' => 'Waste lots: creation, label, weighing, storage, split and grouping (M2).',
        'Sync' => 'Workshop application: device registration, reference data and replay of the operations recorded offline (M2-08).',
        'Providers' => 'Provider directory, partnerships, accepted waste and accreditations (M4).',
        'Pickups' => 'Pickups from request to closing, on the industrial and on the provider side (M5).',
        'Documents' => 'Register, documents, certificates, tracking slips and annual declarations (M6).',
        'Reporting' => 'Dashboard, production volumes and generated reports (M7).',
        'Notifications' => 'In-app notifications and notification preferences (M7-01).',
        'Audit' => 'Audit trail of a company (M7-02).',
        'Common' => 'Reference data shared by every screen.',
    ];

    public function __invoke(ExtractedEndpointData $endpointData, array $settings = []): array
    {
        // First extractor to run for a route: the others may read rules that need a company.
        DemoData::enterCompany();
        $source = RouteSource::of($endpointData->route);
        $name = (string) $endpointData->route?->getName();
        $segments = $name === '' ? [] : explode('.', $name);
        $action = (string) array_pop($segments);
        $subject = str_replace(['-', '_'], ' ', implode(' ', $segments));
        $title = $name === '' ? '' : trim((self::VERBS[$action] ?? Str::ucfirst(str_replace(['-', '_'], ' ', $action))).' '.$subject);

        $permissions = $source?->permissions() ?? [];
        $description = array_filter([
            $source?->comment(),
            $permissions === [] ? null : 'Permission'.(count($permissions) > 1 ? 's' : '').': '.implode(', ', array_map(fn (string $permission) => "`{$permission}`", $permissions)).'.',
        ]);
        $module = $source?->module() ?? 'Application';

        return [
            'groupName' => $module,
            'groupDescription' => self::GROUPS[$module] ?? '',
            'title' => Str::ucfirst($title),
            'description' => implode("\n\n", $description),
            'authenticated' => collect($endpointData->route?->gatherMiddleware() ?? [])->contains(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'auth:')),
        ];
    }
}
