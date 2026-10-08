<?php

namespace App\Modules\Production\Support;

use App\Modules\Production\Models\Article;
use App\Modules\Production\Models\CustomerOrder;
use App\Modules\Production\Models\ProductionClient;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionSupplier;
use App\Modules\Production\Models\RawMaterial;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;
use Throwable;

/**
 * Brings the production reference in from any spreadsheet. Nothing is assumed about the file: each target says which
 * pieces of information it needs, the user says in which column each one is (a first guess is made from the headings),
 * and every line is checked before anything is written.
 *
 * A line updates the record that has the same key (code, reference, number) or creates it. What a line points to — the
 * client of an order, the supplier of a material, the article of an OF — is created on the way when it does not exist,
 * so a single flat export of an ERP is enough. Importing the same file twice changes nothing the second time.
 */
final class ImportEngine
{
    /**
     * For each target: field => [type, required]. The order is the order of the columns in the template.
     *
     * @var array<string, array<string, array{string, bool}>>
     */
    public const TARGETS = [
        'clients' => ['code' => ['text', false], 'name' => ['text', true], 'country_code' => ['text', false], 'contact_name' => ['text', false], 'email' => ['text', false], 'phone' => ['text', false]],
        'suppliers' => ['code' => ['text', false], 'name' => ['text', true], 'country_code' => ['text', false], 'contact_name' => ['text', false], 'email' => ['text', false], 'phone' => ['text', false]],
        'materials' => ['reference' => ['text', true], 'name' => ['text', false], 'supplier' => ['text', false], 'unit' => ['text', false], 'color_label' => ['text', false],
            'grammage_gsm' => ['decimal', false], 'batch_number' => ['text', false], 'received_on' => ['date', false], 'batch_quantity' => ['decimal', false]],
        'articles' => ['reference' => ['text', true], 'name' => ['text', false], 'family' => ['text', false], 'client' => ['text', false], 'material' => ['text', false], 'quantity_per_piece' => ['decimal', false]],
        'orders' => ['order_number' => ['text', true], 'client' => ['text', true], 'article' => ['text', true], 'quantity' => ['integer', true], 'ordered_on' => ['date', false], 'due_on' => ['date', false]],
        'manufacturing_orders' => ['order_number' => ['text', true], 'article' => ['text', true], 'quantity_planned' => ['integer', true], 'quantity_produced' => ['integer', false],
            'customer_order' => ['text', false], 'client' => ['text', false], 'started_on' => ['date', false], 'finished_on' => ['date', false],
            'step' => ['text', false], 'site' => ['text', false], 'pieces_done' => ['integer', false], 'material' => ['text', false], 'consumed_quantity' => ['decimal', false], 'batch_number' => ['text', false]],
    ];

    /** Other headings under which a piece of information is commonly found in an export. */
    private const ALIASES = [
        'code' => ['code', 'id', 'identifiant'],
        'name' => ['nom', 'name', 'designation', 'libelle', 'raison sociale', 'description', 'label'],
        'reference' => ['reference', 'ref', 'code', 'code article', 'sku', 'item'],
        // The same word means different things in different files: a number is an OF in an OF file, an order in an order file.
        'manufacturing_orders.order_number' => ['of', 'n of', 'no of', 'num of', 'numero of', 'ordre de fabrication', 'ordre', 'n ordre', 'numero', 'number', 'n', 'no', 'num', 'mo', 'work order'],
        'orders.order_number' => ['commande', 'n commande', 'no commande', 'num commande', 'numero commande', 'order', 'order number', 'po', 'numero', 'number', 'n', 'no', 'num'],
        'customer_order' => ['commande', 'n commande', 'numero commande', 'order', 'order number', 'po', 'customer order'],
        'client' => ['client', 'customer', 'donneur d ordre', 'code client', 'nom client'],
        'supplier' => ['fournisseur', 'supplier', 'vendor', 'code fournisseur'],
        'article' => ['article', 'reference', 'ref', 'code article', 'modele', 'model', 'style', 'item', 'sku'],
        'material' => ['matiere', 'matiere premiere', 'tissu', 'material', 'fabric', 'ref matiere', 'reference matiere', 'code matiere'],
        'quantity' => ['quantite', 'qte', 'quantity', 'qty', 'pieces'],
        'quantity_planned' => ['quantite prevue', 'qte prevue', 'quantite lancee', 'qte lancee', 'planned', 'quantite', 'qte', 'quantity', 'qty'],
        'quantity_produced' => ['quantite produite', 'qte produite', 'produit', 'produced', 'realise', 'quantite realisee'],
        'quantity_per_piece' => ['consommation', 'conso', 'consommation par piece', 'consumption', 'per piece'],
        'consumed_quantity' => ['consommation', 'conso', 'quantite consommee', 'qte consommee', 'consumed', 'consumption'],
        'pieces_done' => ['pieces', 'pieces realisees', 'pieces done', 'quantite etape'],
        'ordered_on' => ['date', 'date commande', 'order date'],
        'due_on' => ['echeance', 'date livraison', 'livraison', 'due date', 'delivery date'],
        'started_on' => ['debut', 'date debut', 'lancement', 'date lancement', 'start', 'start date'],
        'finished_on' => ['fin', 'date fin', 'cloture', 'end', 'end date'],
        'received_on' => ['date reception', 'reception', 'received', 'date'],
        'batch_number' => ['lot', 'n lot', 'numero lot', 'lot fournisseur', 'lot tissu', 'lot matiere', 'n lot matiere', 'batch', 'batch number', 'rouleau', 'roll'],
        'batch_quantity' => ['quantite', 'qte', 'quantite recue', 'quantity', 'metrage'],
        'unit' => ['unite', 'unit', 'um'],
        'color_label' => ['couleur', 'coloris', 'color', 'colour', 'pantone'],
        'grammage_gsm' => ['grammage', 'gsm', 'g m2', 'poids'],
        'family' => ['famille', 'family', 'categorie', 'category', 'type'],
        'country_code' => ['pays', 'country', 'code pays'],
        'contact_name' => ['contact', 'interlocuteur', 'contact name'],
        'email' => ['email', 'e mail', 'mail', 'courriel'],
        'phone' => ['telephone', 'tel', 'phone', 'mobile', 'gsm'],
        'step' => ['etape', 'operation', 'phase', 'atelier', 'step', 'stage'],
        'site' => ['site', 'usine', 'unite de production', 'plant', 'factory'],
    ];

    private const UNITS = ['m' => 'm', 'metre' => 'm', 'metres' => 'm', 'meter' => 'm', 'ml' => 'm', 'kg' => 'kg', 'kilo' => 'kg', 'kilogramme' => 'kg', 'kilogram' => 'kg',
        'piece' => 'piece', 'pieces' => 'piece', 'pc' => 'piece', 'pcs' => 'piece', 'u' => 'piece', 'unite' => 'piece', 'unit' => 'piece'];

    /**
     * The first sheet of the file: its headings and its lines.
     *
     * @return array{headers: list<string>, rows: list<list<mixed>>}
     */
    public function read(UploadedFile $file): array
    {
        // Read with the spreadsheet library itself: values come raw (a date is a day count, a number a number).
        $type = match (strtolower($file->getClientOriginalExtension())) {
            'xlsx' => 'Xlsx', 'xls' => 'Xls', default => 'Csv',
        };
        $reader = IOFactory::createReader($type);
        if ($reader instanceof Csv) {
            // The separator (comma, semicolon, tab) is inferred; the encoding of old exports is often not UTF-8.
            $reader->setInputEncoding(Csv::guessEncoding($file->getRealPath(), 'CP1252'));
        }
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($file->getRealPath())->getSheet(0)->toArray(null, true, false, false);
        $sheet = array_values(array_filter($sheet, fn (array $row) => array_filter($row, fn ($cell) => $cell !== null && trim((string) $cell) !== '') !== []));
        if (count($sheet) < 2) {
            throw new RuntimeException('EMPTY');
        }

        return ['headers' => array_map(fn ($cell) => trim((string) $cell), array_shift($sheet)), 'rows' => array_map('array_values', $sheet)];
    }

    /**
     * Where each piece of information probably is: the column whose heading looks like the name of the field, in
     * French or in English.
     *
     * @param  list<string>  $headers
     * @return array<string, int|null>
     */
    public function suggest(string $target, array $headers): array
    {
        $normalized = array_map(self::normalize(...), $headers);
        $mapping = [];
        $taken = [];
        foreach (array_keys(self::TARGETS[$target]) as $field) {
            // The name of the field as the screens show it, in both languages, then the usual headings of an export.
            $names = array_map(self::normalize(...), [$field, (string) trans("imports.fields.{$target}.{$field}", [], 'fr'), (string) trans("imports.fields.{$target}.{$field}", [], 'en'), ...(self::ALIASES["{$target}.{$field}"] ?? self::ALIASES[$field] ?? [])]);
            // The most specific name wins: names are tried in order, each against the headings not yet used.
            $found = null;
            foreach ($names as $name) {
                $index = array_search($name, array_diff_key($normalized, array_flip($taken)), true);
                if ($name !== '' && $index !== false) {
                    $found = (int) $index;
                    break;
                }
            }
            $mapping[$field] = $found;
            if ($found !== null) {
                $taken[] = $found;
            }
        }

        return $mapping;
    }

    /**
     * @param  list<list<mixed>>  $rows
     * @param  array<string, int|null>  $mapping  field => column
     * @return array{total: int, created: int, updated: int, unchanged: int, also_created: array<string, int>, errors: list<array{line: int, message: string}>}
     */
    public function run(string $target, array $rows, array $mapping, bool $dryRun): array
    {
        $fields = self::TARGETS[$target];
        $result = ['total' => count($rows), 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'also_created' => [], 'errors' => []];

        DB::beginTransaction();
        try {
            foreach ($rows as $index => $row) {
                $line = $index + 2; // the heading is line 1
                try {
                    $values = [];
                    foreach ($fields as $field => [$type, $required]) {
                        $column = $mapping[$field] ?? null;
                        $values[$field] = $column === null ? null : $this->parse($row[$column] ?? null, $type, $field);
                        if ($required && $values[$field] === null) {
                            throw new RuntimeException(__('imports.errors.required', ['field' => __("imports.fields.{$target}.{$field}")]));
                        }
                    }
                    // One line fails alone: what it wrote before failing is undone, the others go on.
                    DB::transaction(function () use ($target, $values, &$result) {
                        // Counted only once the line has gone through: a line that fails created nothing.
                        $also = $result['also_created'];
                        $outcome = $this->{'import'.Str::studly($target)}($values, $also);
                        $result[$outcome]++;
                        $result['also_created'] = $also;
                    });
                } catch (Throwable $error) {
                    $known = $error instanceof RuntimeException && ! $error instanceof \PDOException;
                    $result['errors'][] = ['line' => $line, 'message' => $known ? $error->getMessage() : __('imports.errors.unexpected')];
                    if (! $known) {
                        report($error);
                    }
                }
            }
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $error) {
            DB::rollBack();
            throw $error;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $v
     * @param  array<string, int>  $also
     */
    private function importClients(array $v, array &$also): string
    {
        return $this->upsert(ProductionClient::class, ['code' => $v['code'] ?? $this->codeFrom($v['name'])], array_filter($v, fn ($value) => $value !== null));
    }

    /**
     * @param  array<string, mixed>  $v
     * @param  array<string, int>  $also
     */
    private function importSuppliers(array $v, array &$also): string
    {
        return $this->upsert(ProductionSupplier::class, ['code' => $v['code'] ?? $this->codeFrom($v['name'])], array_filter($v, fn ($value) => $value !== null));
    }

    /**
     * @param  array<string, mixed>  $v
     * @param  array<string, int>  $also
     */
    private function importMaterials(array $v, array &$also): string
    {
        $unit = $v['unit'] === null ? null : (self::UNITS[self::normalize($v['unit'])] ?? throw new RuntimeException(__('imports.errors.unit', ['value' => $v['unit']])));
        $existing = RawMaterial::query()->where('reference', $v['reference'])->first();
        $outcome = $this->upsert(RawMaterial::class, ['reference' => $v['reference']], array_filter([
            'name' => $v['name'] ?? ($existing === null ? $v['reference'] : null),
            'production_supplier_id' => $v['supplier'] === null ? null : $this->party(ProductionSupplier::class, $v['supplier'], 'suppliers', $also),
            'unit_id' => $unit === null && $existing !== null ? null : DB::table('units')->where('code', $unit ?? 'm')->value('id'),
            'color_label' => $v['color_label'], 'grammage_gsm' => $v['grammage_gsm'],
        ], fn ($value) => $value !== null));

        if ($v['batch_number'] !== null) {
            $material = RawMaterial::query()->where('reference', $v['reference'])->firstOrFail();
            $batch = array_filter(['received_on' => $v['received_on'], 'quantity' => $v['batch_quantity']], fn ($value) => $value !== null);
            $changed = $this->sync('raw_material_batches', ['raw_material_id' => $material->id, 'batch_number' => $v['batch_number']], $batch,
                ['company_id' => $material->company_id, 'ulid' => strtoupper((string) Str::ulid())]);
            $outcome = $outcome === 'unchanged' && $changed ? 'updated' : $outcome;
        }

        return $outcome;
    }

    /**
     * @param  array<string, mixed>  $v
     * @param  array<string, int>  $also
     */
    private function importArticles(array $v, array &$also): string
    {
        $existing = Article::query()->where('reference', $v['reference'])->first();
        $outcome = $this->upsert(Article::class, ['reference' => $v['reference']], array_filter([
            'name' => $v['name'] ?? ($existing === null ? $v['reference'] : null), 'family' => $v['family'],
            'production_client_id' => $v['client'] === null ? null : $this->party(ProductionClient::class, $v['client'], 'clients', $also),
        ], fn ($value) => $value !== null));

        // A line may also say what one piece consumes; several lines of the same article list several materials.
        if ($v['material'] !== null) {
            if ($v['quantity_per_piece'] === null) {
                throw new RuntimeException(__('imports.errors.required', ['field' => __('imports.fields.articles.quantity_per_piece')]));
            }
            $article = Article::query()->where('reference', $v['reference'])->firstOrFail();
            $materialId = RawMaterial::query()->where('reference', $v['material'])->value('id') ?? throw new RuntimeException(__('imports.errors.unknown', ['what' => __('imports.fields.articles.material'), 'value' => $v['material']]));
            $changed = $this->sync('article_materials', ['article_id' => $article->id, 'raw_material_id' => $materialId], ['quantity_per_piece' => $v['quantity_per_piece']], ['company_id' => $article->company_id]);
            $outcome = $outcome === 'unchanged' && $changed ? 'updated' : $outcome;
        }

        return $outcome;
    }

    /**
     * @param  array<string, mixed>  $v
     * @param  array<string, int>  $also
     */
    private function importOrders(array $v, array &$also): string
    {
        $clientId = $this->party(ProductionClient::class, $v['client'], 'clients', $also);
        $outcome = $this->upsert(CustomerOrder::class, ['order_number' => $v['order_number']], array_filter([
            'production_client_id' => $clientId, 'ordered_on' => $v['ordered_on'], 'due_on' => $v['due_on'],
        ], fn ($value) => $value !== null));
        $order = CustomerOrder::query()->where('order_number', $v['order_number'])->firstOrFail();
        $articleId = $this->article($v['article'], $clientId, $also);

        $changed = $this->sync('customer_order_lines', ['customer_order_id' => $order->id, 'article_id' => $articleId], ['quantity' => $v['quantity']], ['company_id' => $order->getAttribute('company_id')]);

        return $outcome === 'unchanged' && $changed ? 'updated' : $outcome;
    }

    /**
     * @param  array<string, mixed>  $v
     * @param  array<string, int>  $also
     */
    private function importManufacturingOrders(array $v, array &$also): string
    {
        $order = $v['customer_order'] === null ? null : CustomerOrder::query()->where('order_number', $v['customer_order'])->first();
        $clientId = $order !== null ? (int) $order->getAttribute('production_client_id') : ($v['client'] === null ? null : $this->party(ProductionClient::class, $v['client'], 'clients', $also));
        if ($v['customer_order'] !== null && $order === null) {
            // The order is not known yet: it is created with the client named on the line.
            $clientId ?? throw new RuntimeException(__('imports.errors.unknown', ['what' => __('imports.fields.manufacturing_orders.customer_order'), 'value' => $v['customer_order']]));
            $order = CustomerOrder::query()->create(['order_number' => $v['customer_order'], 'production_client_id' => $clientId, 'status' => 'open']);
            $also['orders'] = ($also['orders'] ?? 0) + 1;
        }
        $articleId = $this->article($v['article'], $clientId, $also);
        if ($order !== null) {
            DB::table('customer_order_lines')->insertOrIgnore(['company_id' => $order->getAttribute('company_id'), 'customer_order_id' => $order->id, 'article_id' => $articleId,
                'quantity' => $v['quantity_planned'], 'created_at' => now(), 'updated_at' => now()]);
        }

        $existing = ProductionOrder::query()->where('order_number', $v['order_number'])->first();
        $outcome = $this->upsert(ProductionOrder::class, ['order_number' => $v['order_number']], array_filter([
            'article_id' => $articleId, 'customer_order_id' => $order?->id, 'quantity_planned' => $v['quantity_planned'], 'quantity_produced' => $v['quantity_produced'],
            'started_on' => $v['started_on'], 'finished_on' => $v['finished_on'],
            // Dates say where the OF stands when the file has no status.
            'status' => $v['finished_on'] !== null ? 'completed' : ($v['started_on'] !== null ? 'in_progress' : ($existing === null ? 'planned' : null)),
        ], fn ($value) => $value !== null));
        $of = ProductionOrder::query()->where('order_number', $v['order_number'])->firstOrFail();
        $companyId = (int) $of->getAttribute('company_id');
        $touched = false;

        // A stage of the OF on a site: several lines of the same OF describe its route through the workshops.
        if ($v['step'] !== null) {
            $stepId = ProductionData::steps($companyId)->get(['id', 'code', 'name'])
                ->first(fn ($step) => in_array(self::normalize($v['step']), array_map(self::normalize(...), [$step->code, ...array_values((array) json_decode((string) $step->name, true))]), true))
                ?? throw new RuntimeException(__('imports.errors.unknown', ['what' => __('imports.fields.manufacturing_orders.step'), 'value' => $v['step']]));
            $stepId = (int) $stepId->id;
            $siteId = $v['site'] === null ? null : DB::table('sites')->where('company_id', $companyId)->where(fn ($query) => $query->where('code', $v['site'])->orWhere('name', $v['site']))->value('id');
            $siteId ??= throw new RuntimeException($v['site'] === null ? __('imports.errors.required', ['field' => __('imports.fields.manufacturing_orders.site')])
                : __('imports.errors.unknown', ['what' => __('imports.fields.manufacturing_orders.site'), 'value' => $v['site']]));
            $sequence = DB::table('production_order_steps')->where('production_order_id', $of->id)->where('production_step_id', $stepId)->value('sequence')
                ?? ((int) DB::table('production_order_steps')->where('production_order_id', $of->id)->max('sequence') + 1);
            $touched = $this->sync('production_order_steps', ['production_order_id' => $of->id, 'production_step_id' => $stepId],
                array_filter(['site_id' => $siteId, 'pieces_done' => $v['pieces_done']], fn ($value) => $value !== null), ['company_id' => $companyId, 'sequence' => $sequence]);
        }

        // What the OF consumed of a material: the quantity on the line replaces the one already known for it.
        if ($v['material'] !== null) {
            $materialId = RawMaterial::query()->where('reference', $v['material'])->value('id') ?? throw new RuntimeException(__('imports.errors.unknown', ['what' => __('imports.fields.manufacturing_orders.material'), 'value' => $v['material']]));
            $quantity = $v['consumed_quantity'] ?? throw new RuntimeException(__('imports.errors.required', ['field' => __('imports.fields.manufacturing_orders.consumed_quantity')]));
            $batchId = $v['batch_number'] === null ? null : (DB::table('raw_material_batches')->where('raw_material_id', $materialId)->where('batch_number', $v['batch_number'])->value('id')
                ?? throw new RuntimeException(__('imports.errors.unknown', ['what' => __('imports.fields.manufacturing_orders.batch_number'), 'value' => $v['batch_number']])));
            $touched = $this->sync('production_order_consumptions', ['production_order_id' => $of->id, 'raw_material_id' => $materialId, 'raw_material_batch_id' => $batchId],
                ['quantity' => $quantity], ['company_id' => $companyId]) || $touched;
        }

        return $outcome === 'unchanged' && $touched ? 'updated' : $outcome;
    }

    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $key
     * @param  array<string, mixed>  $attributes
     */
    private function upsert(string $model, array $key, array $attributes): string
    {
        $record = $model::query()->where($key)->first();
        if ($record === null) {
            $model::query()->create($key + $attributes);

            return 'created';
        }
        $record->fill($attributes);
        if (! $record->isDirty()) {
            return 'unchanged';
        }
        $record->save();

        return 'updated';
    }

    /**
     * A detail line (no model): inserted, or updated only when a value really differs. Says whether it wrote anything.
     *
     * @param  array<string, mixed>  $key
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $onCreate
     */
    private function sync(string $table, array $key, array $values, array $onCreate): bool
    {
        $query = DB::table($table);
        foreach ($key as $column => $value) {
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }
        $row = (array) ($query->first() ?? []);
        if ($row === []) {
            DB::table($table)->insert($key + $values + $onCreate + ['created_at' => now(), 'updated_at' => now()]);

            return true;
        }
        $changes = array_filter($values, fn ($value, string $column) => is_numeric($value) && is_numeric($row[$column] ?? null)
            ? bccomp((string) $value, (string) $row[$column], 4) !== 0 : (string) $value !== (string) ($row[$column] ?? ''), ARRAY_FILTER_USE_BOTH);
        if ($changes === []) {
            return false;
        }
        DB::table($table)->where('id', $row['id'])->update($changes + ['updated_at' => now()]);

        return true;
    }

    /**
     * A client or a supplier named by its code or its name; created when it is not known.
     *
     * @param  class-string<ProductionClient|ProductionSupplier>  $model
     * @param  array<string, int>  $also
     */
    private function party(string $model, string $value, string $kind, array &$also): int
    {
        $id = $model::query()->where('code', $value)->orWhere('name', $value)->value('id');
        if ($id === null) {
            $id = $model::query()->create(['code' => $this->codeFrom($value), 'name' => $value])->getKey();
            $also[$kind] = ($also[$kind] ?? 0) + 1;
        }

        return (int) $id;
    }

    /**
     * @param  array<string, int>  $also
     */
    private function article(string $reference, ?int $clientId, array &$also): int
    {
        $id = Article::query()->where('reference', $reference)->value('id');
        if ($id === null) {
            $id = Article::query()->create(['reference' => $reference, 'name' => $reference, 'production_client_id' => $clientId])->getKey();
            $also['articles'] = ($also['articles'] ?? 0) + 1;
        }

        return (int) $id;
    }

    private function codeFrom(string $name): string
    {
        return mb_substr(Str::upper(Str::slug($name, '-')) ?: Str::upper(Str::random(8)), 0, 40);
    }

    private function parse(mixed $cell, string $type, string $field): mixed
    {
        $text = trim((string) ($cell ?? ''));
        if ($text === '') {
            return null;
        }
        $label = fn () => __('imports.errors.format.'.$type, ['value' => $text]);

        return match ($type) {
            'integer' => preg_match('/^\d+([.,]0+)?$/', $number = preg_replace('/[\s\x{00A0}\x{202F}]/u', '', $text) ?? '') ? (int) $number : throw new RuntimeException($label()),
            'decimal' => is_numeric($number = str_replace(',', '.', preg_replace('/[\s\x{00A0}\x{202F}]/u', '', $text) ?? '')) && (float) $number >= 0 ? (string) round((float) $number, 4) : throw new RuntimeException($label()),
            'date' => $this->date($cell, $text) ?? throw new RuntimeException($label()),
            default => mb_substr($text, 0, 191),
        };
    }

    private function date(mixed $cell, string $text): ?string
    {
        // A date cell of a spreadsheet arrives as a day count.
        if (is_numeric($cell) && (float) $cell > 20000 && (float) $cell < 80000) {
            return ExcelDate::excelToDateTimeObject((float) $cell)->format('Y-m-d');
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'Y/m/d', 'd/m/y'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $text);
                if ($date !== null && $date->format($format) === $text) {
                    return $date->format('Y-m-d');
                }
            } catch (Throwable) {
                // Not this format: try the next one.
            }
        }

        return null;
    }

    private static function normalize(string $text): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($text)));
    }
}
