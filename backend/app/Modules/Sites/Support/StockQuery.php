<?php

namespace App\Modules\Sites\Support;

use App\Modules\Tenancy\TenantContext;
use App\Modules\Waste\Enums\LotStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * M2-07: stock is never stored. It is the sum of the lots physically on site (created, stored, awaiting pickup),
 * read through the `ix_waste_lots_stock` covering index, so it cannot drift from the lots themselves.
 */
final class StockQuery
{
    public const ON_SITE = [LotStatus::Created, LotStatus::Stored, LotStatus::AwaitingPickup];

    public function __construct(private readonly TenantContext $context) {}

    public function companyId(): int
    {
        return (int) $this->context->companyId();
    }

    /**
     * Lots currently on site for the company, optionally limited to some sites.
     *
     * @param  list<int>|null  $siteIds  null = every site
     */
    public function lots(?array $siteIds = null): Builder
    {
        return DB::table('waste_lots as l')
            ->where('l.company_id', $this->context->companyId())
            ->whereIn('l.status', array_map(fn (LotStatus $status) => $status->value, self::ON_SITE))
            ->whereNull('l.deleted_at')
            ->when($siteIds !== null, fn (Builder $query) => $query->whereIn('l.site_id', $siteIds));
    }

    /**
     * Current quantity for a threshold subject (site, optional zone, optional waste type).
     */
    public function quantity(int $siteId, ?int $zoneId, ?int $wasteTypeId): string
    {
        return (string) ($this->lots([$siteId])
            ->when($zoneId !== null, fn (Builder $query) => $query->where('l.zone_id', $zoneId))
            ->when($wasteTypeId !== null, fn (Builder $query) => $query->where('l.waste_type_id', $wasteTypeId))
            ->sum('l.net_weight_kg') ?: '0');
    }

    /**
     * The lots on site of a subject with what a limit may count them in: weight, declared quantity and its unit, the
     * conversions of the waste type, packaging and packages.
     *
     * @return Collection<int, \stdClass>
     */
    public function measures(int $siteId, ?int $zoneId, ?int $wasteTypeId): Collection
    {
        return $this->lots([$siteId])
            ->when($zoneId !== null, fn (Builder $query) => $query->where('l.zone_id', $zoneId))
            ->when($wasteTypeId !== null, fn (Builder $query) => $query->where('l.waste_type_id', $wasteTypeId))
            ->leftJoin('units as u', 'u.id', '=', 'l.unit_id')->leftJoin('waste_types as t', 't.id', '=', 'l.waste_type_id')
            ->leftJoin('packaging_types as p', 'p.id', '=', 'l.packaging_type_id')->orderBy('l.id')
            ->get(['l.lot_number', 'l.net_weight_kg as kg', 'l.weight_basis', 'l.quantity', 'u.code as unit', 't.density_kg_m3', 't.unit_weight_kg',
                'p.code as packaging', 'l.package_count as packages']);
    }

    /**
     * Lots on site within the same subject whose weight is not known: a limit in kilograms cannot count them.
     */
    public function unweighed(int $siteId, ?int $zoneId, ?int $wasteTypeId): int
    {
        return $this->lots([$siteId])
            ->when($zoneId !== null, fn (Builder $query) => $query->where('l.zone_id', $zoneId))
            ->when($wasteTypeId !== null, fn (Builder $query) => $query->where('l.waste_type_id', $wasteTypeId))
            ->whereNull('l.net_weight_kg')->count();
    }

    /**
     * The same lots, by number.
     *
     * @return list<string>
     */
    public function unweighedNumbers(int $siteId, ?int $zoneId, ?int $wasteTypeId): array
    {
        return $this->lots([$siteId])
            ->when($zoneId !== null, fn (Builder $query) => $query->where('l.zone_id', $zoneId))
            ->when($wasteTypeId !== null, fn (Builder $query) => $query->where('l.waste_type_id', $wasteTypeId))
            ->whereNull('l.net_weight_kg')->orderBy('l.id')->pluck('l.lot_number')->map(fn ($number) => (string) $number)->all();
    }
}
