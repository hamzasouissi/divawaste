<?php

namespace App\Modules\Tenancy\Actions;

use App\Modules\Common\Support\FileStorage;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserRoleAssignment;
use App\Modules\Identity\Notifications\VerifyEmailNotification;
use App\Modules\Identity\Support\LegalAcceptance;
use App\Modules\Providers\Models\ProviderAccreditation;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Tenancy\Enums\CompanyType;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\CompanyUser;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * M0-01 / M0-03 / M0-05 / M0-10: self-service registration of an industrial or a provider.
 * The company starts in `pending_verification`; confirming the contact's email sends it to platform review (M0-06).
 */
final class RegisterCompany
{
    public function __construct(private readonly TenantContext $context, private readonly FileStorage $files) {}

    /**
     * @param  array<string, mixed>  $input  validated RegisterCompanyRequest payload
     */
    public function handle(array $input, Request $request): Company
    {
        return DB::transaction(function () use ($input, $request) {
            $type = CompanyType::from($input['company_type']);
            $country = DB::table('countries')->where('iso2', strtoupper($input['company']['country']))
                ->first(['id', 'currency_code', 'default_timezone', 'default_locale']);

            $user = User::query()->create([
                'first_name' => $input['contact']['first_name'],
                'last_name' => $input['contact']['last_name'],
                'email' => $input['contact']['email'],
                'phone' => $input['contact']['phone'] ?? null,
                'password' => $input['contact']['password'],
                'locale' => $country->default_locale,
            ]);

            $company = new Company([
                'company_type' => $type,
                'legal_name' => $input['company']['legal_name'],
                'trade_name' => $input['company']['trade_name'] ?? null,
                'tax_id' => $input['company']['tax_id'],
                'country_id' => $country->id,
                'currency_code' => $country->currency_code,
                'timezone' => $country->default_timezone,
                'address_line1' => $input['company']['address_line1'] ?? null,
                'city' => $input['company']['city'] ?? null,
                'postal_code' => $input['company']['postal_code'] ?? null,
                'phone' => $input['company']['phone'] ?? null,
                'email' => $input['contact']['email'],
                'billing_email' => $input['contact']['email'],
            ]);
            $company->forceFill([
                'primary_contact_user_id' => $user->id,
                'settings' => ['requested_plan' => $input['plan'] ?? null],
            ])->save();

            $this->context->runAs($company, function () use ($company, $user, $type, $input) {
                CompanyUser::query()->create(['user_id' => $user->id, 'joined_at' => now()]);
                UserRoleAssignment::query()->create([
                    'user_id' => $user->id,
                    'role_id' => Role::query()->whereNull('company_id')
                        ->where('name', $type === CompanyType::Provider ? 'provider_admin' : 'client_admin')->valueOrFail('id'),
                    'site_id' => null,
                ]);

                $now = now();
                DB::table('company_textile_activities')->insert(DB::table('textile_activities')
                    ->whereIn('code', $input['company']['activities'] ?? [])->pluck('id')
                    ->map(fn ($id) => ['company_id' => $company->id, 'textile_activity_id' => $id, 'created_at' => $now])->all());

                if ($type === CompanyType::Provider) {
                    $this->createProviderRecords($company, $user, $input);
                }
            });

            LegalAcceptance::record(
                $user, $company->id,
                $type === CompanyType::Provider ? ['provider_terms', 'privacy'] : ['terms', 'privacy'],
                $type === CompanyType::Provider ? 'provider_registration' : 'registration',
                $request,
            );

            $user->notify(new VerifyEmailNotification);

            return $company;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function createProviderRecords(Company $company, User $user, array $input): void
    {
        $roles = $input['provider']['roles'];
        ProviderProfile::query()->create([
            'is_collector' => in_array('collector', $roles, true),
            'is_transporter' => in_array('transporter', $roles, true),
            'is_recycler' => in_array('recycler', $roles, true),
            'is_eliminator' => in_array('eliminator', $roles, true),
            'service_area' => $input['provider']['service_area'] ?? null,
            'public_contact_name' => $user->fullName(),
            'public_contact_email' => $user->email,
            'public_contact_phone' => $user->phone,
        ]);

        foreach ($input['provider']['accreditations'] as $accreditation) {
            $file = $this->files->store($accreditation['file'], $company, 'accreditations', $user->id, $company->id);

            ProviderAccreditation::query()->create([
                'accreditation_type_id' => DB::table('accreditation_types')->where('country_id', $company->country_id)
                    ->where('code', $accreditation['type'])->value('id'),
                'reference_number' => $accreditation['reference_number'],
                'issuing_authority' => $accreditation['issuing_authority'] ?? null,
                'valid_from' => $accreditation['valid_from'],
                'expires_on' => $accreditation['expires_on'],
                'stored_file_id' => $file->id,
            ]);
        }
    }
}
