<?php

namespace App\Modules\Production\Support;

use App\Modules\Common\Support\ReferenceCodes;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\RawMaterial;
use App\Modules\Tenancy\Rules\TenantExists;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Waste\Models\WasteLot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Where a waste lot comes from in production: its manufacturing order, the stage it was produced at, the raw material
 * (and delivery) it is made of. Everything is optional, and what can be deduced is — the stage from the zone the lot
 * is created in, the material from the manufacturing order — so that an operator chooses one thing, the OF.
 */
final class LotOrigin
{
    public const FIELDS = ['production_order_id', 'production_step_id', 'raw_material_id', 'raw_material_batch_id'];

    public function __construct(private readonly TenantContext $context, private readonly ReferenceCodes $codes) {}

    /**
     * Validation of the origin of a lot, shared by the online form, the offline synchronisation and the correction.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'production_order' => ['nullable', 'string', new TenantExists(ProductionOrder::class)],
            'production_step' => ['nullable', 'string', Rule::exists('production_steps', 'code')
                ->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', app(TenantContext::class)->companyId()))],
            'raw_material' => ['nullable', 'string', new TenantExists(RawMaterial::class)],
            'raw_material_batch' => ['nullable', 'string', 'ulid'],
        ];
    }

    /**
     * The four columns of the lot, from what was given and what can be deduced.
     *
     * @param  array{production_order?: string|null, production_step?: string|null, raw_material?: string|null, raw_material_batch?: string|null}  $input
     * @return array{production_order_id: int|null, production_step_id: int|null, raw_material_id: int|null, raw_material_batch_id: int|null}
     */
    public function resolve(array $input, int $siteId, int $zoneId): array
    {
        $companyId = $this->context->companyId();
        $orderId = isset($input['production_order']) ? (int) ProductionOrder::query()->where('ulid', strtoupper($input['production_order']))->value('id') : null;
        $materialId = isset($input['raw_material']) ? (int) RawMaterial::query()->where('ulid', strtoupper($input['raw_material']))->value('id') : null;
        $stepId = isset($input['production_step']) ? (int) ProductionData::steps($companyId)->where('code', $input['production_step'])->value('id') : null;

        // The stage is the one the zone stands for (a lot created in the cutting workshop comes from cutting); failing
        // that, the only stage the OF has on this site.
        if ($stepId === null) {
            $zoneType = $this->codes->code('zone_types', DB::table('zones')->where('id', $zoneId)->value('zone_type_id'));
            $stepId = $zoneType === null ? null : ProductionData::steps($companyId)->where('code', $zoneType)->where('is_active', true)->value('id');
            if ($stepId === null && $orderId !== null) {
                $onSite = DB::table('production_order_steps')->where('production_order_id', $orderId)->where('site_id', $siteId)->pluck('production_step_id');
                $stepId = $onSite->count() === 1 ? (int) $onSite->first() : null;
            }
        }
        // The material is the one the OF works with, when it works with a single one.
        if ($materialId === null && $orderId !== null) {
            $materials = $this->materialsOf($orderId);
            $materialId = count($materials) === 1 ? $materials[0] : null;
        }

        $batchId = null;
        if (isset($input['raw_material_batch'])) {
            $batchId = $materialId === null ? null : DB::table('raw_material_batches')->where('raw_material_id', $materialId)->where('ulid', strtoupper($input['raw_material_batch']))->value('id');
            if ($batchId === null) {
                throw ValidationException::withMessages(['raw_material_batch' => [__('validation.exists', ['attribute' => 'raw material batch'])]]);
            }
        }

        return ['production_order_id' => $orderId, 'production_step_id' => $stepId === null ? null : (int) $stepId,
            'raw_material_id' => $materialId, 'raw_material_batch_id' => $batchId === null ? null : (int) $batchId];
    }

    /**
     * What a lot inherits from its raw material when the form did not say otherwise: what it is made of and its colour.
     *
     * @return array{composition: list<array{material: string, pct: string}>|null, color_family_id: int|null, color_label: string|null, grammage_gsm: string|null}|null
     */
    public function fromMaterial(?int $materialId): ?array
    {
        $material = $materialId === null ? null : RawMaterial::query()->find($materialId);

        return $material === null ? null : ['composition' => $material->composition, 'color_family_id' => $material->getAttribute('color_family_id'),
            'color_label' => $material->getAttribute('color_label'), 'grammage_gsm' => $material->getAttribute('grammage_gsm')];
    }

    /**
     * The whole chain behind a lot, for its page: OF → order → client, article, stage, material → supplier.
     *
     * @return array<string, mixed>|null null when the lot says nothing about its origin
     */
    public function describe(WasteLot $lot): ?array
    {
        $ids = $lot->only(self::FIELDS);
        if (array_filter($ids, fn ($id) => $id !== null) === []) {
            return null;
        }
        $of = $ids['production_order_id'] === null ? null : DB::table('production_orders as o')->join('articles as a', 'a.id', '=', 'o.article_id')
            ->leftJoin('customer_orders as c', 'c.id', '=', 'o.customer_order_id')
            ->leftJoin('production_clients as cl', fn ($join) => $join->on('cl.id', '=', DB::raw('COALESCE(c.production_client_id, a.production_client_id)')))
            ->where('o.id', $ids['production_order_id'])
            ->first(['o.ulid', 'o.order_number', 'a.reference', 'a.name', 'c.order_number as customer_order', 'cl.name as client']);
        $material = $ids['raw_material_id'] === null ? null : DB::table('raw_materials as m')->leftJoin('production_suppliers as s', 's.id', '=', 'm.production_supplier_id')
            ->where('m.id', $ids['raw_material_id'])->first(['m.ulid', 'm.reference', 'm.name', 's.name as supplier']);
        $step = $ids['production_step_id'] === null ? null : DB::table('production_steps')->where('id', $ids['production_step_id'])->first(['code', 'name']);

        return [
            'production_order' => $of === null ? null : ['ulid' => $of->ulid, 'order_number' => $of->order_number],
            'article' => $of === null ? null : ['reference' => $of->reference, 'name' => $of->name],
            'customer_order' => $of?->customer_order,
            'client' => $of?->client,
            'step' => $step === null ? null : ['code' => $step->code, 'name' => json_decode((string) $step->name, true)],
            'raw_material' => $material === null ? null : ['ulid' => $material->ulid, 'reference' => $material->reference, 'name' => $material->name],
            'supplier' => $material?->supplier,
            'batch' => $ids['raw_material_batch_id'] === null ? null : (array) DB::table('raw_material_batches')->where('id', $ids['raw_material_batch_id'])->first(['ulid', 'batch_number']),
        ];
    }

    /**
     * The manufacturing orders a lot can be attached to: not finished, and either without stages or with one on a
     * site the user may work on. Only what is needed to choose one — no client, no quantities.
     *
     * @param  list<int>|null  $siteIds  null = every site
     * @return list<array<string, mixed>>
     */
    public function openOrders(?array $siteIds): array
    {
        $orders = DB::table('production_orders as o')->join('articles as a', 'a.id', '=', 'o.article_id')
            ->where('o.company_id', $this->context->companyId())->whereIn('o.status', ['planned', 'in_progress'])
            ->when($siteIds !== null, fn ($query) => $query->where(fn ($q) => $q
                ->whereNotExists(fn ($sub) => $sub->from('production_order_steps as s')->whereColumn('s.production_order_id', 'o.id'))
                ->orWhereExists(fn ($sub) => $sub->from('production_order_steps as s')->whereColumn('s.production_order_id', 'o.id')->whereIn('s.site_id', $siteIds))))
            ->orderByRaw("o.status = 'in_progress' desc")->orderByDesc('o.id')->limit(300)
            ->get(['o.id', 'o.ulid', 'o.order_number', 'a.reference', 'a.name']);
        $steps = DB::table('production_order_steps as s')->join('production_steps as p', 'p.id', '=', 's.production_step_id')->join('sites', 'sites.id', '=', 's.site_id')
            ->whereIn('s.production_order_id', $orders->pluck('id'))->orderBy('s.sequence')->get(['s.production_order_id', 'p.code', 'sites.ulid as site'])->groupBy('production_order_id');

        return $orders->map(fn ($order) => [
            'ulid' => $order->ulid, 'order_number' => $order->order_number, 'article' => ['reference' => $order->reference, 'name' => $order->name],
            'steps' => ($steps[$order->id] ?? collect())->map(fn ($step) => ['step' => $step->code, 'site' => $step->site])->values(),
            'materials' => DB::table('raw_materials')->whereIn('id', $this->materialsOf((int) $order->id))->orderBy('reference')->get(['ulid', 'reference', 'name'])->map(fn ($row) => (array) $row),
        ])->all();
    }

    /**
     * The materials an OF works with: what it consumed, or what its article is expected to consume.
     *
     * @return list<int>
     */
    private function materialsOf(int $orderId): array
    {
        $consumed = DB::table('production_order_consumptions')->where('production_order_id', $orderId)->distinct()->pluck('raw_material_id');
        $materials = $consumed->isNotEmpty() ? $consumed : DB::table('article_materials')
            ->where('article_id', DB::table('production_orders')->where('id', $orderId)->value('article_id'))->pluck('raw_material_id');

        return $materials->map(fn ($id) => (int) $id)->values()->all();
    }
}
