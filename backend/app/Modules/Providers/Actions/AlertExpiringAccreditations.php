<?php

namespace App\Modules\Providers\Actions;

use App\Modules\Notifications\Actions\NotifyUsers;
use App\Modules\Providers\Support\ProviderDirectory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * M4-02: daily scheduled task. 30 days before an approved accreditation expires — and again once it has expired —
 * the provider and every industrial working with it are told, once each. An accreditation already replaced by a
 * later approved one of the same type is not reported.
 */
final class AlertExpiringAccreditations
{
    public function __construct(private readonly NotifyUsers $notify) {}

    /**
     * @return array{expiring: int, expired: int}
     */
    public function handle(): array
    {
        $today = Carbon::today();

        return [
            'expiring' => $this->alert('notified_30d_at', 'accreditation.expiring_soon', $today,
                fn ($query) => $query->whereDate('a.expires_on', '>=', $today)->whereDate('a.expires_on', '<=', $today->copy()->addDays(ProviderDirectory::EXPIRY_WARNING_DAYS))),
            'expired' => $this->alert('notified_expired_at', 'accreditation.expired', $today,
                fn ($query) => $query->whereDate('a.expires_on', '<', $today)),
        ];
    }

    private function alert(string $flag, string $type, Carbon $today, callable $window): int
    {
        $rows = DB::table('provider_accreditations as a')->join('companies as c', 'c.id', '=', 'a.company_id')
            ->where('a.review_status', 'approved')->whereNull('a.deleted_at')->whereNull("a.{$flag}")->where('c.status', 'active')
            ->tap($window)
            ->whereNotExists(fn ($query) => $query->from('provider_accreditations as newer')
                ->whereColumn('newer.company_id', 'a.company_id')->whereColumn('newer.accreditation_type_id', 'a.accreditation_type_id')
                ->where('newer.review_status', 'approved')->whereNull('newer.deleted_at')->whereColumn('newer.expires_on', '>', 'a.expires_on')
                ->whereDate('newer.expires_on', '>', $today->copy()->addDays(ProviderDirectory::EXPIRY_WARNING_DAYS)))
            ->get(['a.id', 'a.company_id', 'a.reference_number', 'a.expires_on', 'c.legal_name']);

        foreach ($rows as $row) {
            $params = [
                'provider' => $row->legal_name, 'reference' => $row->reference_number,
                'date' => Carbon::parse($row->expires_on)->format('d/m/Y'),
                'days' => max(0, (int) $today->diffInDays(Carbon::parse($row->expires_on), false)),
            ];

            $this->notify->toCompanyPermission((int) $row->company_id, 'provider.accreditations.manage', $type, $params, '/provider/accreditations');
            foreach (DB::table('provider_partnerships')->where('provider_company_id', $row->company_id)->where('status', 'active')->pluck('company_id') as $industrialId) {
                $this->notify->toCompanyPermission((int) $industrialId, 'providers.partnerships.manage', $type, $params, '/providers');
            }

            // Flag set per row, after its notifications: a crash in the middle never loses an alert.
            DB::table('provider_accreditations')->where('id', $row->id)->update([$flag => now()]);
        }

        return $rows->count();
    }
}
