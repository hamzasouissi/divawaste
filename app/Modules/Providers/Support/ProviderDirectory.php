<?php

namespace App\Modules\Providers\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * M4-01 / M4-02: what an industrial may know about the published providers — contacts, accepted waste,
 * channels, accreditations with their expiry — and whether each one can be chosen for a pickup today.
 * Reads provider-owned tables without tenant scope on purpose, restricted to published profiles of active companies.
 */
final class ProviderDirectory
{
    public const EXPIRY_WARNING_DAYS = 30;

    /**
     * @param  array{search?: ?string, channel?: ?string, partners_only?: bool, hazardous?: ?bool}  $filter
     * @return Collection<int, array<string, mixed>>
     */
    public function list(int $industrialCompanyId, array $filter = []): Collection
    {
        $providers = DB::table('provider_profiles as p')->join('companies as c', 'c.id', '=', 'p.company_id')
            ->leftJoin('provider_partnerships as pp', fn ($join) => $join->on('pp.provider_company_id', '=', 'c.id')->where('pp.company_id', $industrialCompanyId))
            ->where('p.is_published', true)->where('c.status', 'active')
            ->when($filter['search'] ?? null, fn ($query, $value) => $query->where('c.legal_name', 'like', '%'.addcslashes($value, '%_\\').'%'))
            ->when($filter['partners_only'] ?? false, fn ($query) => $query->where('pp.status', 'active'))
            ->orderByRaw("pp.status = 'active' desc")->orderBy('c.legal_name')
            ->get(['c.id', 'c.ulid', 'c.legal_name', 'c.city', 'p.is_collector', 'p.is_transporter', 'p.is_recycler', 'p.is_eliminator',
                'p.description', 'p.service_area', 'p.public_contact_name', 'p.public_contact_email', 'p.public_contact_phone',
                'pp.ulid as partnership', 'pp.status as partnership_status']);

        $ids = $providers->pluck('id');
        $accepted = DB::table('provider_accepted_wastes as w')->join('treatment_channels as ch', 'ch.id', '=', 'w.treatment_channel_id')
            ->leftJoin('waste_families as f', 'f.id', '=', 'w.waste_family_id')->leftJoin('waste_catalog_items as i', 'i.id', '=', 'w.waste_catalog_item_id')
            ->whereIn('w.company_id', $ids)->where('w.is_active', true)
            ->get(['w.company_id', 'ch.code as channel', 'f.code as family', 'i.code as catalog_item', 'w.min_quantity_kg', 'w.notes'])->groupBy('company_id');
        $accreditations = DB::table('provider_accreditations as a')->join('accreditation_types as t', 't.id', '=', 'a.accreditation_type_id')
            ->whereIn('a.company_id', $ids)->where('a.review_status', 'approved')->whereNull('a.deleted_at')->orderBy('a.expires_on')
            ->get(['a.company_id', 'a.ulid', 't.code as type', 't.name', 't.covers_hazardous', 'a.reference_number', 'a.valid_from', 'a.expires_on'])->groupBy('company_id');

        $today = Carbon::today();

        return $providers->map(function (object $provider) use ($accepted, $accreditations, $today, $filter) {
            $list = ($accreditations[$provider->id] ?? collect())->map(function (object $row) use ($today) {
                $expires = Carbon::parse($row->expires_on);
                $days = (int) $today->diffInDays($expires, false);

                return [
                    'ulid' => $row->ulid, 'type' => $row->type, 'name' => json_decode((string) $row->name, true),
                    'covers_hazardous' => (bool) $row->covers_hazardous, 'reference_number' => $row->reference_number,
                    'valid_from' => $row->valid_from, 'expires_on' => $row->expires_on, 'days_left' => $days,
                    // "Expired" is computed from the dates, never stored.
                    'state' => match (true) {
                        $days < 0 || Carbon::parse($row->valid_from)->isAfter($today) => 'expired',
                        $days <= self::EXPIRY_WARNING_DAYS => 'expiring',
                        default => 'valid',
                    },
                ];
            })->values();
            $valid = $list->where('state', '!=', 'expired');
            $wastes = ($accepted[$provider->id] ?? collect());

            return [
                'ulid' => $provider->ulid,
                'legal_name' => $provider->legal_name,
                'city' => $provider->city,
                'roles' => array_keys(array_filter([
                    'collector' => $provider->is_collector, 'transporter' => $provider->is_transporter,
                    'recycler' => $provider->is_recycler, 'eliminator' => $provider->is_eliminator,
                ])),
                'description' => $provider->description,
                'service_area' => $provider->service_area,
                'contact' => ['name' => $provider->public_contact_name, 'email' => $provider->public_contact_email, 'phone' => $provider->public_contact_phone],
                'channels' => $wastes->pluck('channel')->unique()->values(),
                'accepted_wastes' => $wastes->map(fn (object $row) => [
                    'channel' => $row->channel, 'family' => $row->family, 'catalog_item' => $row->catalog_item,
                    'min_quantity_kg' => $row->min_quantity_kg, 'notes' => $row->notes,
                ])->values(),
                'accreditations' => $list,
                'accepts_hazardous' => $valid->contains('covers_hazardous', true),
                'partnership' => $provider->partnership === null ? null : ['ulid' => $provider->partnership, 'status' => $provider->partnership_status],
                // M4-02: a provider without a valid accreditation cannot be chosen.
                'selectable' => $valid->isNotEmpty() && $provider->partnership_status === 'active',
                'blocked_reason' => match (true) {
                    $valid->isEmpty() => 'no_valid_accreditation',
                    $provider->partnership_status !== 'active' => 'not_a_partner',
                    default => null,
                },
            ];
        })
            ->when($filter['channel'] ?? null, fn (Collection $rows, string $channel) => $rows->filter(fn (array $row) => $row['channels']->contains($channel)))
            ->when($filter['hazardous'] ?? false, fn (Collection $rows) => $rows->where('accepts_hazardous', true))
            ->values();
    }
}
