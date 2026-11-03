<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Actions\NotifyUsers;
use App\Modules\Tenancy\Actions\ReviewCompany;
use App\Modules\Tenancy\Models\Company;

/**
 * The contact's email is confirmed — by the link sent at registration, or at once when the platform confirms emails
 * itself (config app.auto_verify_emails, for tests and demonstrations). M0-06: the registration then reaches the
 * platform's review, or is approved on the spot (config app.auto_approve_signups).
 */
final class ConfirmEmail
{
    public function __construct(private readonly ReviewCompany $review, private readonly NotifyUsers $notify) {}

    public function handle(User $user): void
    {
        if ($user->email_verified_at !== null) {
            return;
        }
        $user->forceFill(['email_verified_at' => now()])->save();

        foreach (Company::query()->where('primary_contact_user_id', $user->id)->where('status', 'pending_verification')->get() as $company) {
            $company->forceFill(['status' => 'pending_approval', 'submitted_at' => now()])->save();
            if (config('app.auto_approve_signups')) {
                // Approved on the spot: the contact receives the usual approval email, the platform reads it in the audit.
                $this->review->approve($company, null);

                continue;
            }
            $this->notify->toPlatformPermission('platform.companies.review', 'company.pending_review', [
                'company' => $company->legal_name, 'type' => $company->company_type->value,
            ], '/platform/companies/'.$company->ulid);
        }
    }
}
