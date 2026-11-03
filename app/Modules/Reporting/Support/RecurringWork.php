<?php

namespace App\Modules\Reporting\Support;

use App\Modules\Common\Exceptions\BusinessRuleViolation;
use App\Modules\Documents\Actions\DeclarationWorkflow;
use App\Modules\Identity\Models\User;
use App\Modules\Reporting\Jobs\GenerateReport;
use App\Modules\Reporting\Models\GeneratedReport;
use App\Modules\Reporting\Models\ReportSchedule;
use App\Modules\Sites\Models\Site;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Work the platform does by itself so that nobody has to remember it:
 * - R-03: each scheduled report is generated on the month before, for the person who asked, who is notified when ready;
 * - DE-01: in January, the declaration of the past year is drafted for every site that produced waste, to be reviewed
 *   and validated (an existing draft is recomputed; a validated one is left alone).
 */
final class RecurringWork
{
    public function __construct(private readonly TenantContext $context, private readonly DeclarationWorkflow $declarations) {}

    /** @return int reports queued */
    public function scheduledReports(?Carbon $today = null): int
    {
        $today ??= Carbon::today();
        $from = $today->copy()->subMonthNoOverflow()->startOfMonth();
        $to = $from->copy()->endOfMonth();
        $queued = 0;
        $companies = DB::table('report_schedules')->where('is_active', true)->distinct()->pluck('company_id');
        foreach (Company::query()->whereIn('id', $companies)->where('status', 'active')->get() as $company) {
            $this->context->runAs($company, function () use ($today, $from, $to, &$queued) {
                $schedules = ReportSchedule::query()->where('is_active', true)
                    ->where(fn ($query) => $query->whereNull('last_run_on')->orWhere('last_run_on', '<', $today->copy()->startOfMonth()))->get();
                foreach ($schedules as $schedule) {
                    $user = User::query()->find($schedule->user_id);
                    if ($user === null || ! $user->can('reports.export')) {
                        continue;
                    }
                    $report = GeneratedReport::query()->create([
                        'report_type' => $schedule->report_type, 'format' => $schedule->format, 'status' => 'queued',
                        'parameters' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'site' => null, 'site_ids' => null, 'lang' => $schedule->lang, 'scheduled' => true],
                        'requested_by_user_id' => $user->id, 'expires_at' => now()->addDays(30),
                    ]);
                    GenerateReport::dispatch($report->company_id, $report->id);
                    $schedule->forceFill(['last_run_on' => $today->toDateString()])->save();
                    $queued++;
                }
            });
        }

        return $queued;
    }

    /** @return int declarations drafted or recomputed */
    public function draftDeclarations(?Carbon $today = null): int
    {
        $year = ($today ?? Carbon::today())->year - 1;
        $drafted = 0;
        foreach (Company::query()->where('company_type', 'industrial')->where('status', 'active')->get() as $company) {
            // The declaration records who prepared it: the company's first administrator.
            $actor = User::query()->whereIn('id', DB::table('user_role_assignments as a')->join('roles as r', 'r.id', '=', 'a.role_id')
                ->where('a.company_id', $company->id)->where('r.name', 'client_admin')->select('a.user_id'))->orderBy('id')->first();
            if ($actor === null) {
                continue;
            }
            $this->context->runAs($company, function () use ($company, $year, $actor, &$drafted) {
                $sites = DB::table('waste_lots')->where('company_id', $company->id)->whereYear('generated_at', $year)->distinct()->pluck('site_id');
                foreach (Site::query()->whereIn('id', $sites)->get() as $site) {
                    $done = DB::table('annual_declarations')->where('site_id', $site->id)->where('reporting_year', $year)->whereIn('status', ['validated', 'submitted'])->exists();
                    if ($done) {
                        continue;
                    }
                    try {
                        $this->declarations->prepare($site, $year, $actor);
                        $drafted++;
                    } catch (BusinessRuleViolation) {
                        // No declaration format for this country yet, or already locked: nothing to draft.
                    }
                }
            });
        }

        return $drafted;
    }
}
