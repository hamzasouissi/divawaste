<?php

namespace App\Modules\Documents\Support;

use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * M5-01: chronological waste register. Nothing is typed in: each line is a lot, with where it was produced
 * and — once it has left — who took it, when, how much was received and what was done with it.
 * Lots produced by a split or a grouping appear with their origin so the weights can be reconciled.
 */
final class WasteRegister
{
    public const HEADINGS = [
        'Date', 'N° de lot', 'Site', 'Zone', 'Type de déchet', 'Code déchet', 'Dangereux', 'Poids (kg)', 'Origine', 'Statut',
        'N° enlèvement', 'Date d\'enlèvement', 'Prestataire', 'Poids reçu (kg)', 'Filière',
    ];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  list<int>|null  $siteIds
     */
    public function query(Carbon $from, Carbon $to, ?array $siteIds, ?bool $hazardous = null): Builder
    {
        return DB::table('waste_lots as l')
            ->join('sites as s', 's.id', '=', 'l.site_id')->join('zones as z', 'z.id', '=', 'l.zone_id')->join('waste_types as t', 't.id', '=', 'l.waste_type_id')
            ->leftJoin('regulatory_waste_codes as rc', 'rc.id', '=', 'l.regulatory_waste_code_id')
            // The line of the pickup that actually took the lot (a refused or cancelled pickup leaves no exit).
            ->leftJoin('pickup_lots as pl', fn ($join) => $join->on('pl.waste_lot_id', '=', 'l.id')->whereIn('pl.line_status', ['loaded', 'received', 'treated']))
            ->leftJoin('pickups as p', 'p.id', '=', 'pl.pickup_id')
            ->leftJoin('companies as pc', 'pc.id', '=', 'p.provider_company_id')
            ->leftJoin('treatment_channels as ch', 'ch.id', '=', 'pl.actual_treatment_channel_id')
            ->where('l.company_id', $this->context->companyId())->whereNull('l.deleted_at')
            ->where('l.generated_at', '>=', $from)->where('l.generated_at', '<', $to->copy()->addDay()->startOfDay())
            ->when($siteIds !== null, fn (Builder $query) => $query->whereIn('l.site_id', $siteIds))
            ->when($hazardous !== null, fn (Builder $query) => $query->where('l.is_hazardous', $hazardous))
            ->orderBy('l.generated_at')->orderBy('l.id')
            ->select(['l.ulid', 'l.generated_at', 'l.lot_number', 's.name as site', 'z.name as zone', 't.name as waste_type', 'rc.code as regulatory_code',
                'l.is_hazardous', 'l.net_weight_kg', 'l.origin_type', 'l.status', 'p.pickup_number', 'p.collected_at', 'pc.legal_name as provider',
                'pl.received_weight_kg', 'ch.code as channel', 'ch.name as channel_name']);
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(object $row): array
    {
        return [
            'lot' => $row->ulid,
            'generated_at' => Carbon::parse($row->generated_at, 'UTC')->toIso8601ZuluString(),
            'lot_number' => $row->lot_number,
            'site' => $row->site,
            'zone' => $row->zone,
            'waste_type' => $row->waste_type,
            'regulatory_code' => $row->regulatory_code,
            'is_hazardous' => (bool) $row->is_hazardous,
            'net_weight_kg' => (string) $row->net_weight_kg,
            'origin_type' => $row->origin_type,
            'status' => $row->status,
            'pickup_number' => $row->pickup_number,
            'collected_at' => $row->collected_at === null ? null : Carbon::parse($row->collected_at, 'UTC')->toIso8601ZuluString(),
            'provider' => $row->provider,
            'received_weight_kg' => $row->received_weight_kg === null ? null : (string) $row->received_weight_kg,
            'channel' => $row->channel,
        ];
    }

    /**
     * Flat line for the PDF and Excel exports, dates in the company's time zone.
     *
     * @return list<string|float|null>
     */
    public static function exportLine(object $row, string $timezone): array
    {
        $date = fn (?string $value) => $value === null ? null : Carbon::parse($value, 'UTC')->timezone($timezone)->format('d/m/Y H:i');

        return [
            $date($row->generated_at), $row->lot_number, $row->site, $row->zone, $row->waste_type, $row->regulatory_code,
            $row->is_hazardous ? 'Oui' : 'Non', (float) $row->net_weight_kg, __('exports.origin.'.$row->origin_type), __('exports.lot_status.'.$row->status),
            $row->pickup_number, $date($row->collected_at), $row->provider,
            $row->received_weight_kg === null ? null : (float) $row->received_weight_kg,
            $row->channel_name === null ? null : (json_decode((string) $row->channel_name, true)['fr'] ?? $row->channel),
        ];
    }
}
