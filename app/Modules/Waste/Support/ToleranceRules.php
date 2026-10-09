<?php

namespace App\Modules\Waste\Support;

use App\Modules\Common\Support\ReferenceCodes;
use Illuminate\Support\Facades\DB;

/**
 * Which tolerance applies to a waste type handled in a given way. The most precise rule wins:
 * type + channel, then the type alone, then the channel alone, then the rule of the company for every waste,
 * and last the percentage of the company settings (5 % by default, cahier M4-04) — a warning, never a block.
 */
final class ToleranceRules
{
    public const DEFAULT_WARNING_PCT = '5.00';

    public function __construct(private readonly ReferenceCodes $codes) {}

    /**
     * The rule as a snapshot: it is stored with the decision it produced.
     *
     * @return array{source: string, rule: string|null, warning_pct: string|null, blocking_pct: string|null, direction: string, basis: string, reference: string|null, channel: string|null}
     */
    public function resolve(int $companyId, ?int $wasteTypeId, ?int $channelId): array
    {
        $rules = DB::table('waste_tolerance_rules')->where('company_id', $companyId)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('waste_type_id')->when($wasteTypeId !== null, fn ($q) => $q->orWhere('waste_type_id', $wasteTypeId)))
            ->where(fn ($q) => $q->whereNull('treatment_channel_id')->when($channelId !== null, fn ($q) => $q->orWhere('treatment_channel_id', $channelId)))
            ->get();
        $find = fn (bool $typed, bool $channelled) => $rules->first(fn ($rule) => ($rule->waste_type_id !== null) === $typed && ($rule->treatment_channel_id !== null) === $channelled);

        foreach (['type_channel' => [true, true], 'type' => [true, false], 'channel' => [false, true], 'company' => [false, false]] as $source => [$typed, $channelled]) {
            $rule = $find($typed, $channelled);
            if ($rule !== null) {
                return [
                    'source' => $source, 'rule' => $rule->ulid,
                    'warning_pct' => $rule->warning_pct === null ? null : bcadd((string) $rule->warning_pct, '0', 2),
                    'blocking_pct' => $rule->blocking_pct === null ? null : bcadd((string) $rule->blocking_pct, '0', 2),
                    'direction' => $rule->direction, 'basis' => $rule->basis, 'reference' => $rule->reference,
                    'channel' => $this->codes->code('treatment_channels', $rule->treatment_channel_id),
                ];
            }
        }

        return $this->companyDefault($companyId);
    }

    /**
     * @return array{source: string, rule: null, warning_pct: string, blocking_pct: null, direction: string, basis: string, reference: null, channel: null}
     */
    public function companyDefault(int $companyId): array
    {
        $settings = json_decode((string) DB::table('companies')->where('id', $companyId)->value('settings'), true);
        $own = $settings['weight_variance_threshold_pct'] ?? null;

        return [
            'source' => $own === null ? 'default' : 'company_setting', 'rule' => null,
            'warning_pct' => bcadd((string) ($own ?? self::DEFAULT_WARNING_PCT), '0', 2), 'blocking_pct' => null,
            'direction' => 'both', 'basis' => 'operational', 'reference' => null, 'channel' => null,
        ];
    }
}
