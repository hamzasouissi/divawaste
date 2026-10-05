<?php

namespace App\Modules\Common\Docs;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Real records of the demo dataset for the API documentation: an identifier for each URL parameter and, for the
 * example responses, an account allowed to read it. With any other database the examples fall back to placeholders
 * and no route is called.
 */
final class DemoData
{
    /** URL parameter => [table, column holding the public identifier]. */
    private const TABLES = [
        'lot' => ['waste_lots', 'ulid'], 'pickup' => ['pickups', 'ulid'], 'site' => ['sites', 'ulid'], 'zone' => ['zones', 'ulid'],
        'wasteType' => ['waste_types', 'ulid'], 'invoice' => ['invoices', 'ulid'], 'document' => ['documents', 'ulid'],
        'manifest' => ['hazardous_waste_manifests', 'ulid'], 'declaration' => ['annual_declarations', 'ulid'], 'report' => ['generated_reports', 'ulid'],
        'threshold' => ['stock_thresholds', 'ulid'], 'invitation' => ['user_invitations', 'ulid'], 'partnership' => ['provider_partnerships', 'ulid'],
        'accreditation' => ['provider_accreditations', 'ulid'], 'payment' => ['payments', 'ulid'], 'tag' => ['lot_tags', 'tag_value'],
    ];

    private const DESCRIPTIONS = [
        'token' => 'Token of the invitation link received by email.', 'tag' => 'Value read from the QR code of a lot label.',
        'step' => 'Key of a step of the set-up wizard.', 'code' => 'Stable code of the reference record.', 'role' => 'Name of the role.',
        'company' => 'Public identifier (ULID) of the company.', 'user' => 'Public identifier (ULID) of the user.',
    ];

    public static function describe(string $parameter): string
    {
        return self::DESCRIPTIONS[$parameter] ?? 'Public identifier (ULID) of the '.strtolower((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $parameter)).'.';
    }

    public static function available(): bool
    {
        try {
            return app()->environment('demo') && DB::table('users')->where('email', 'admin@divawaste.test')->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Some validation rules are scoped to the current company (unique codes): reading them needs one to be set.
     */
    public static function enterCompany(): void
    {
        $context = app(TenantContext::class);
        if ($context->hasTenant() || $context->isSystem()) {
            return;
        }
        try {
            $company = Company::query()->orderByRaw('legal_name = ? desc', ['Textile du Cap Bon'])->orderBy('id')->first();
        } catch (Throwable) {
            $company = null;
        }
        if ($company !== null) {
            $context->set($company);
        }
    }

    public static function example(string $parameter, string $uri): ?string
    {
        if (! self::available()) {
            return null;
        }
        // A period that contains the demo data.
        if (in_array($parameter, ['from', 'to', 'year'], true)) {
            return ['from' => now()->startOfYear()->toDateString(), 'to' => now()->toDateString(), 'year' => now()->format('Y')][$parameter];
        }
        if ($parameter === 'code') {
            return str_contains($uri, 'plans') ? 'per_site' : DB::table('waste_catalog_items')->orderBy('id')->value('code');
        }
        if ($parameter === 'company') {
            return DB::table('companies')->where('status', 'pending_approval')->orderBy('id')->value('ulid');
        }
        if ($parameter === 'user') {
            return DB::table('users')->where('email', 'leila@capbon.test')->value('ulid');
        }
        if (! isset(self::TABLES[$parameter])) {
            return null;
        }

        [$table, $column] = self::TABLES[$parameter];
        // The provider sees the pickups addressed to it; everything else is read by the company that owns the record.
        $provider = str_contains($uri, 'provider/');
        $preferred = self::companyId($provider ? 'karim@sahel-recyclage.test' : 'amira@capbon.test');
        $row = DB::table($table)
            ->when($provider && $parameter === 'pickup', fn ($query) => $query->where('provider_company_id', $preferred))
            ->orderByRaw('company_id = ? desc', [$preferred])->orderBy('id')->first([$column.' as value', 'company_id']);

        return $row === null ? null : (string) $row->value;
    }

    /**
     * Before an example call: signs the request with a token of the demo account that owns what the route reads.
     * Runs inside the transaction of the call, so the token disappears with it.
     */
    public static function authenticate(Request $request, ExtractedEndpointData $endpoint): void
    {
        if (! self::available() || ! $endpoint->metadata->authenticated) {
            return;
        }

        $uri = $endpoint->uri;
        [$email, $companyId] = match (true) {
            str_contains($uri, 'platform/') => ['admin@divawaste.test', null],
            str_contains($uri, 'provider/') => ['karim@sahel-recyclage.test', self::companyId('karim@sahel-recyclage.test')],
            default => self::ownerOf($endpoint),
        };
        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            return;
        }

        $token = $user->createToken('api-docs', ['*'], now()->addMinutes(5));
        $token->accessToken->forceFill(['company_id' => $companyId])->save();
        $request->headers->set('Authorization', 'Bearer '.$token->plainTextToken);
        $request->server->set('HTTP_AUTHORIZATION', 'Bearer '.$token->plainTextToken);
        // Each call is a new request: forget who made the previous one.
        app('auth')->forgetGuards();
        app()->forgetScopedInstances();
    }

    /**
     * After an example call: a file (PDF, Excel, image) is documented by its type, not embedded in the documentation.
     */
    public static function summariseFile(Request $request, ExtractedEndpointData $endpoint, mixed $response): void
    {
        if (! $response instanceof Response || $response->getStatusCode() >= 300) {
            return;
        }
        $type = (string) $response->headers->get('Content-Type');
        if ($type === '' || str_contains($type, 'json')) {
            return;
        }

        $summary = '(file: '.trim(explode(';', $type)[0]).')';
        if ($response instanceof StreamedResponse) {
            $response->setCallback(function () use ($summary): void {
                echo $summary;
            });
        } elseif (! $response instanceof BinaryFileResponse) {
            $response->setContent($summary);
        }
    }

    /**
     * @return array{string, int|null}
     */
    private static function ownerOf(ExtractedEndpointData $endpoint): array
    {
        foreach ($endpoint->urlParameters as $name => $parameter) {
            [$table, $column] = self::TABLES[$name] ?? [null, null];
            $companyId = $table === null ? null : DB::table($table)->where($column, $parameter->example)->value('company_id');
            $email = $companyId === null ? null : DB::table('users')->join('companies', 'companies.primary_contact_user_id', '=', 'users.id')->where('companies.id', $companyId)->value('users.email');
            if ($email !== null) {
                return [(string) $email, (int) $companyId];
            }
        }

        return ['amira@capbon.test', self::companyId('amira@capbon.test')];
    }

    private static function companyId(string $email): ?int
    {
        $id = DB::table('company_users')->join('users', 'users.id', '=', 'company_users.user_id')->where('users.email', $email)->value('company_users.company_id');

        return $id === null ? null : (int) $id;
    }
}
