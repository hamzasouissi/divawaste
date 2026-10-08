<?php

namespace App\Modules\Production\Support;

use App\Modules\Common\Support\ReferenceCodes;
use App\Modules\Production\Models\Article;
use App\Modules\Production\Models\CustomerOrder;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\RawMaterial;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What the API says about the production reference: each record with the names of what it points to, so a screen
 * never has to look them up (and never receives an internal id).
 */
final class ProductionData
{
    /** How a raw material is counted when consumed. */
    public const MATERIAL_UNITS = ['m', 'kg', 'piece'];

    public function __construct(private readonly ReferenceCodes $codes) {}

    /**
     * Stages a company may use: the defaults and its own.
     */
    public static function steps(int $companyId): Builder
    {
        return DB::table('production_steps')->where(fn (Builder $query) => $query->whereNull('company_id')->orWhere('company_id', $companyId));
    }

    /**
     * @return array<string, mixed>
     */
    public function material(RawMaterial $material): array
    {
        return [
            'ulid' => $material->ulid, 'reference' => $material->reference, 'name' => $material->name,
            'supplier' => $this->named('production_suppliers', $material->production_supplier_id),
            'unit' => $this->codes->code('units', $material->unit_id),
            'color_family' => $this->codes->code('color_families', $material->color_family_id), 'color_label' => $material->color_label,
            'grammage_gsm' => $material->grammage_gsm, 'width_cm' => $material->width_cm, 'composition' => $material->composition ?? [],
            'notes' => $material->notes, 'is_active' => $material->is_active,
            'batches' => DB::table('raw_material_batches')->where('raw_material_id', $material->id)->orderByDesc('received_on')->orderByDesc('id')
                ->get(['ulid', 'batch_number', 'received_on', 'quantity', 'notes'])->map(fn ($batch) => (array) $batch),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function article(Article $article): array
    {
        return [
            'ulid' => $article->ulid, 'reference' => $article->reference, 'name' => $article->name, 'family' => $article->family,
            'client' => $this->named('production_clients', $article->production_client_id),
            'notes' => $article->notes, 'is_active' => $article->is_active,
            // What one piece is expected to consume.
            'materials' => DB::table('article_materials as am')->join('raw_materials as m', 'm.id', '=', 'am.raw_material_id')->where('am.article_id', $article->id)->orderBy('am.id')
                ->get(['m.ulid', 'm.reference', 'm.name', 'm.unit_id', 'am.quantity_per_piece'])
                ->map(fn ($row) => ['material' => $row->ulid, 'reference' => $row->reference, 'name' => $row->name, 'unit' => $this->codes->code('units', (int) $row->unit_id),
                    'quantity_per_piece' => (string) $row->quantity_per_piece]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function order(CustomerOrder $order): array
    {
        $lines = DB::table('customer_order_lines as l')->join('articles as a', 'a.id', '=', 'l.article_id')->where('l.customer_order_id', $order->id)->orderBy('l.id')
            ->get(['a.ulid', 'a.reference', 'a.name', 'l.quantity']);

        return [
            'ulid' => $order->ulid, 'order_number' => $order->order_number, 'client' => $this->named('production_clients', $order->production_client_id),
            'ordered_on' => $order->ordered_on?->toDateString(), 'due_on' => $order->due_on?->toDateString(), 'status' => $order->status, 'notes' => $order->notes,
            'lines' => $lines->map(fn ($line) => ['article' => $line->ulid, 'reference' => $line->reference, 'name' => $line->name, 'quantity' => (int) $line->quantity]),
            'quantity' => (int) $lines->sum('quantity'),
            'manufacturing_orders' => DB::table('production_orders')->where('customer_order_id', $order->id)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function manufacturingOrder(ProductionOrder $of, bool $detailed = false): array
    {
        $article = DB::table('articles')->where('id', $of->article_id)->first(['ulid', 'reference', 'name', 'production_client_id']);
        $order = $of->customer_order_id === null ? null : DB::table('customer_orders')->where('id', $of->customer_order_id)->first(['ulid', 'order_number', 'production_client_id']);
        $steps = DB::table('production_order_steps as s')->join('production_steps as p', 'p.id', '=', 's.production_step_id')->join('sites', 'sites.id', '=', 's.site_id')
            ->where('s.production_order_id', $of->id)->orderBy('s.sequence')
            ->get(['p.code', 'p.name', 'sites.ulid as site', 'sites.name as site_name', 's.pieces_done', 's.started_on', 's.finished_on'])
            ->map(fn ($step) => ['step' => $step->code, 'name' => json_decode((string) $step->name, true), 'site' => $step->site, 'site_name' => $step->site_name,
                'pieces_done' => $step->pieces_done === null ? null : (int) $step->pieces_done, 'started_on' => $step->started_on, 'finished_on' => $step->finished_on]);

        $data = [
            'ulid' => $of->ulid, 'order_number' => $of->order_number,
            'article' => ['ulid' => $article->ulid, 'reference' => $article->reference, 'name' => $article->name],
            'customer_order' => $order === null ? null : ['ulid' => $order->ulid, 'order_number' => $order->order_number],
            // The client of the order, or the client the article is made for.
            'client' => $this->named('production_clients', $order->production_client_id ?? $article->production_client_id),
            'quantity_planned' => $of->quantity_planned, 'quantity_produced' => $of->quantity_produced,
            'started_on' => $of->started_on?->toDateString(), 'finished_on' => $of->finished_on?->toDateString(), 'status' => $of->status, 'notes' => $of->notes,
            'steps' => $steps,
        ];
        if (! $detailed) {
            return $data;
        }

        $consumed = DB::table('production_order_consumptions as c')->join('raw_materials as m', 'm.id', '=', 'c.raw_material_id')
            ->leftJoin('raw_material_batches as b', 'b.id', '=', 'c.raw_material_batch_id')->where('c.production_order_id', $of->id)->orderBy('c.id')
            ->get(['m.id', 'm.ulid', 'm.reference', 'm.name', 'm.unit_id', 'b.ulid as batch', 'b.batch_number', 'c.quantity']);
        $planned = DB::table('article_materials as am')->join('raw_materials as m', 'm.id', '=', 'am.raw_material_id')->where('am.article_id', $of->article_id)->orderBy('am.id')
            ->get(['m.id', 'm.ulid', 'm.reference', 'm.name', 'm.unit_id', 'am.quantity_per_piece']);

        return $data + [
            'consumptions' => $consumed->map(fn ($row) => ['material' => $row->ulid, 'reference' => $row->reference, 'name' => $row->name, 'unit' => $this->codes->code('units', (int) $row->unit_id),
                'batch' => $row->batch, 'batch_number' => $row->batch_number, 'quantity' => (string) $row->quantity]),
            // Expected for the planned quantity, next to what was really consumed: the gap is where waste hides.
            'planned_consumption' => $planned->map(fn ($row) => ['material' => $row->ulid, 'reference' => $row->reference, 'name' => $row->name, 'unit' => $this->codes->code('units', (int) $row->unit_id),
                'planned' => bcmul((string) $row->quantity_per_piece, (string) $of->quantity_planned, 3),
                'consumed' => (string) $consumed->where('id', $row->id)->reduce(fn (string $sum, $line) => bcadd($sum, (string) $line->quantity, 3), '0.000')]),
        ];
    }

    /**
     * @return array{ulid: string, code: string, name: string}|null
     */
    private function named(string $table, ?int $id): ?array
    {
        $row = $id === null ? null : DB::table($table)->where('id', $id)->first(['ulid', 'code', 'name']);

        return $row === null ? null : (array) $row;
    }
}
