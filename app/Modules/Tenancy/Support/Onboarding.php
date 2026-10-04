<?php

namespace App\Modules\Tenancy\Support;

use App\Modules\Tenancy\Enums\CompanyType;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * M0-07: four-step start-up guide (sites, waste types, users, providers). A step is done when the data exists,
 * so progress can never disagree with reality; skipping is the only stored state and can always be resumed.
 */
final class Onboarding
{
    public const STEPS = ['sites', 'waste_types', 'users', 'providers'];

    /**
     * @return array{completed: bool, dismissed: bool, steps: list<array{key: string, done: bool, skipped: bool, count: int}>}
     */
    public static function state(Company $company): array
    {
        $state = $company->onboarding_state ?? [];
        $skipped = $state['skipped'] ?? [];

        $counts = [
            'sites' => DB::table('sites')->where('company_id', $company->id)->whereNull('deleted_at')->count(),
            'waste_types' => DB::table('waste_types')->where('company_id', $company->id)->whereNull('deleted_at')->count(),
            // The registering administrator does not count: the step is about bringing the team in.
            'users' => max(0, DB::table('company_users')->where('company_id', $company->id)->count() - 1)
                + DB::table('user_invitations')->where('company_id', $company->id)->where('status', 'pending')->count(),
            'providers' => DB::table('provider_partnerships')->where('company_id', $company->id)->where('status', 'active')->count(),
        ];

        $steps = array_map(fn (string $key) => [
            'key' => $key,
            'done' => $counts[$key] > 0,
            'skipped' => $counts[$key] === 0 && in_array($key, $skipped, true),
            'count' => $counts[$key],
        ], self::STEPS);

        return [
            // Providers have no start-up guide: their profile is built at registration.
            'completed' => $company->company_type !== CompanyType::Industrial
                || collect($steps)->every(fn (array $step) => $step['done'] || $step['skipped']),
            'dismissed' => (bool) ($state['dismissed'] ?? false),
            'steps' => $steps,
        ];
    }

    public static function skip(Company $company, string $step, bool $skipped): void
    {
        $state = $company->onboarding_state ?? [];
        $list = array_values(array_diff($state['skipped'] ?? [], [$step]));
        if ($skipped) {
            $list[] = $step;
        }

        $company->forceFill(['onboarding_state' => ['skipped' => $list] + $state])->save();
    }

    public static function dismiss(Company $company): void
    {
        $company->forceFill(['onboarding_state' => ['dismissed' => true] + ($company->onboarding_state ?? [])])->save();
    }
}
