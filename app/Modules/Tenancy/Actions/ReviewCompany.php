<?php

namespace App\Modules\Tenancy\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Actions\StartTrial;
use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Enums\CompanyStatus;
use App\Modules\Tenancy\Enums\CompanyType;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Notifications\CompanyStatusNotification;
use Illuminate\Support\Facades\DB;

/**
 * M0-06: platform decisions on an account. Each decision is audited and emailed to the company's main contact.
 */
final class ReviewCompany
{
    public function __construct(private readonly StartTrial $startTrial) {}

    public function approve(Company $company, User $reviewer): Company
    {
        return $this->decide($company, [CompanyStatus::PendingApproval], CompanyStatus::Active, 'company_approved', null, function (Company $company) use ($reviewer) {
            $company->forceFill(['approved_at' => now(), 'approved_by_user_id' => $reviewer->id]);

            if ($company->company_type === CompanyType::Industrial) {
                // M0-03: every formula starts with the 30-day trial; the requested plan is kept for the conversion.
                $this->startTrial->handle($company, $reviewer);
            } else {
                DB::table('provider_profiles')->where('company_id', $company->id)
                    ->update(['is_published' => true, 'published_at' => now(), 'updated_at' => now()]);
            }
        }, $reviewer);
    }

    public function reject(Company $company, string $reason, User $reviewer): Company
    {
        return $this->decide($company, [CompanyStatus::PendingApproval], CompanyStatus::Rejected, 'company_rejected', $reason,
            fn (Company $company) => $company->forceFill(['rejected_at' => now(), 'rejected_by_user_id' => $reviewer->id, 'rejection_reason' => $reason]), $reviewer);
    }

    public function suspend(Company $company, string $reason, User $reviewer): Company
    {
        return $this->decide($company, [CompanyStatus::Active], CompanyStatus::Suspended, 'company_suspended', $reason,
            fn (Company $company) => $company->forceFill(['suspended_at' => now(), 'suspended_by_user_id' => $reviewer->id, 'suspension_reason' => $reason]), $reviewer);
    }

    public function reactivate(Company $company, User $reviewer): Company
    {
        return $this->decide($company, [CompanyStatus::Suspended], CompanyStatus::Active, 'company_reactivated', null,
            fn (Company $company) => $company->forceFill(['suspended_at' => null, 'suspended_by_user_id' => null, 'suspension_reason' => null]), $reviewer);
    }

    /**
     * @param  list<CompanyStatus>  $from
     * @param  callable(Company): mixed  $apply
     */
    private function decide(Company $company, array $from, CompanyStatus $to, string $event, ?string $reason, callable $apply, User $reviewer): Company
    {
        $company = DB::transaction(function () use ($company, $from, $to, $event, $reason, $apply, $reviewer) {
            $company = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            if (! in_array($company->status, $from, true)) {
                throw new BusinessRuleViolation('INVALID_COMPANY_TRANSITION', "A {$company->status->value} company cannot become {$to->value}.");
            }

            $previous = $company->status;
            $apply($company);
            $company->forceFill(['status' => $to])->save();

            AuditLogger::record([
                'company_id' => $company->id, 'actor_user_id' => $reviewer->id, 'event' => $event, 'tags' => ['platform_review'],
                'auditable_type' => 'companies', 'auditable_id' => $company->id, 'auditable_ulid' => $company->ulid,
                'old_values' => ['status' => $previous->value], 'new_values' => array_filter(['status' => $to->value, 'reason' => $reason]),
            ]);

            return $company;
        });

        $contact = $company->primary_contact_user_id === null ? null : User::query()->find($company->primary_contact_user_id);
        $contact?->notify(new CompanyStatusNotification($event, $company->legal_name, $reason));

        return $company;
    }
}
