<?php

namespace App\Modules\Production\Support;

use App\Modules\Production\Models\Article;
use App\Modules\Production\Models\CustomerOrder;
use App\Modules\Production\Models\CustomerOrderInvoice;
use App\Modules\Production\Models\ProducedLot;
use App\Modules\Production\Models\ProductionClient;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderExpense;
use App\Modules\Production\Models\ProductionSupplier;
use App\Modules\Production\Models\RawMaterial;
use App\Modules\Production\Models\RawMaterialBatch;
use App\Modules\Tenancy\TenantContext;
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
 *
 * The targets that describe what happened (received lots, consumptions, produced lots, expenses, invoices) are stricter:
 * the material, the OF or the order they name must already exist, and a received lot named on a consumption must be a
 * lot of that material. An unknown one is an error on its line — nothing is created or chosen in its place.
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
        'materials' => ['reference' => ['text', true], 'name' => ['text', false], 'category' => ['text', false], 'supplier' => ['text', false], 'unit' => ['text', false], 'color_label' => ['text', false],
            'grammage_gsm' => ['decimal', false], 'width_cm' => ['decimal', false], 'batch_number' => ['text', false], 'received_on' => ['date', false], 'batch_quantity' => ['decimal', false]],
        'articles' => ['reference' => ['text', true], 'name' => ['text', false], 'family' => ['text', false], 'client' => ['text', false], 'material' => ['text', false], 'quantity_per_piece' => ['decimal', false]],
        'orders' => ['order_number' => ['text', true], 'client' => ['text', true], 'article' => ['text', true], 'quantity' => ['integer', true], 'unit_price' => ['decimal', false],
            'ordered_on' => ['date', false], 'due_on' => ['date', false]],
        'manufacturing_orders' => ['order_number' => ['text', true], 'article' => ['text', true], 'quantity_planned' => ['integer', true], 'quantity_produced' => ['integer', false],
            'customer_order' => ['text', false], 'client' => ['text', false], 'started_on' => ['date', false], 'finished_on' => ['date', false],
            'step' => ['text', false], 'site' => ['text', false], 'pieces_done' => ['integer', false], 'material' => ['text', false], 'consumed_quantity' => ['decimal', false], 'batch_number' => ['text', false]],
        'received_lots' => ['material' => ['text', true], 'batch_number' => ['text', true], 'supplier' => ['text', false], 'supplier_batch_reference' => ['text', false], 'received_on' => ['date', false],
            'batch_quantity' => ['decimal', false], 'unit_price' => ['decimal', false], 'total_amount' => ['decimal', false], 'delivery_cost' => ['decimal', false], 'currency' => ['text', false],
            'delivery_note' => ['text', false], 'invoice_reference' => ['text', false], 'external_reference' => ['text', false]],
        'consumptions' => ['manufacturing_order' => ['text', true], 'material' => ['text', true], 'consumed_quantity' => ['decimal', true], 'batch_number' => ['text', false],
            'step' => ['text', false], 'site' => ['text', false], 'consumed_on' => ['date', false]],
        'produced_lots' => ['lot_number' => ['text', true], 'manufacturing_order' => ['text', true], 'quantity' => ['integer', true], 'produced_on' => ['date', false], 'status' => ['text', false],
            'delivered_on' => ['date', false], 'step' => ['text', false], 'site' => ['text', false], 'external_reference' => ['text', false]],
        'expenses' => ['manufacturing_order' => ['text', true], 'reference' => ['text', true], 'description' => ['text', true], 'amount' => ['decimal', true], 'category' => ['text', false],
            'incurred_on' => ['date', false], 'supplier' => ['text', false], 'currency' => ['text', false]],
        'invoices' => ['number' => ['text', true], 'customer_order' => ['text', true], 'amount' => ['decimal', true], 'kind' => ['text', false], 'issued_on' => ['date', false],
            'manufacturing_order' => ['text', false], 'currency' => ['text', false]],
    ];

    /** Pieces of information that are money: imported only by who may see amounts. */
    public const MONEY_FIELDS = ['unit_price', 'total_amount', 'delivery_cost', 'currency', 'amount'];

    /** Targets that are nothing but money. */
    public const MONEY_TARGETS = ['expenses', 'invoices'];

    /** What a cell of each column looks like, shown next to the column in the wizard. */
    public const EXAMPLES = [
        'reference' => 'DEN-12OZ', 'batch_number' => 'R-2026-0412', 'supplier' => 'Tissages du Nord', 'supplier_batch_reference' => 'TN-88541', 'received_on' => '12/03/2026',
        'batch_quantity' => '1250,5', 'unit_price' => '8,450', 'total_amount' => '10566,725', 'delivery_cost' => '120', 'currency' => 'TND', 'delivery_note' => 'BL-4471',
        'invoice_reference' => 'FA-2026-118', 'manufacturing_order' => 'OF-2026-014', 'material' => 'DEN-12OZ', 'consumed_quantity' => '842,3', 'consumed_on' => '18/03/2026',
        'lot_number' => 'LF-2026-014-A', 'quantity' => '1200', 'produced_on' => '25/03/2026', 'status' => 'livré', 'delivered_on' => '28/03/2026', 'step' => 'Coupe', 'site' => 'NAB',
        'description' => 'Délavage en sous-traitance', 'amount' => '1850', 'category' => 'sous-traitance', 'incurred_on' => '20/03/2026', 'number' => 'FV-2026-0231', 'customer_order' => 'CMD-7781',
        'kind' => 'facture', 'issued_on' => '30/03/2026', 'order_number' => 'OF-2026-014', 'article' => 'JEAN-501', 'client' => 'Maison Dupont', 'quantity_planned' => '1200',
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
        'manufacturing_order' => ['of', 'n of', 'no of', 'num of', 'numero of', 'ordre de fabrication', 'ordre', 'mo', 'work order'],
        'received_lots.material' => ['matiere', 'matiere premiere', 'mp', 'reference', 'ref', 'ref matiere', 'reference matiere', 'code matiere', 'tissu', 'material', 'fabric'],
        'supplier_batch_reference' => ['lot fournisseur', 'ref lot fournisseur', 'reference fournisseur', 'n lot fournisseur', 'supplier batch', 'supplier lot'],
        'unit_price' => ['prix unitaire', 'pu', 'pu ht', 'prix', 'prix ht', 'unit price', 'price'],
        'total_amount' => ['montant', 'montant ht', 'total', 'total ht', 'valeur', 'amount', 'total amount'],
        'amount' => ['montant', 'montant ht', 'total', 'total ht', 'amount'],
        'delivery_cost' => ['transport', 'frais de transport', 'frais de livraison', 'frais d approche', 'delivery cost', 'freight'],
        'currency' => ['devise', 'monnaie', 'currency'],
        'delivery_note' => ['bl', 'bon de livraison', 'n bl', 'delivery note'],
        'invoice_reference' => ['facture', 'n facture', 'facture fournisseur', 'invoice', 'supplier invoice'],
        'external_reference' => ['reference externe', 'ref externe', 'id erp', 'external reference', 'external id'],
        'lot_number' => ['lot fabrique', 'n lot fabrique', 'lot', 'n lot', 'numero lot', 'lot produit', 'produced lot', 'batch'],
        'produced_lots.quantity' => ['quantite', 'qte', 'quantite fabriquee', 'pieces', 'quantity', 'qty'],
        'produced_on' => ['date de fabrication', 'date fabrication', 'fabrique le', 'date', 'production date'],
        'delivered_on' => ['date de livraison', 'livre le', 'livraison', 'delivery date'],
        'consumed_on' => ['date de consommation', 'date consommation', 'date', 'consumption date'],
        'incurred_on' => ['date', 'date de la depense', 'expense date'],
        'issued_on' => ['date', 'date facture', 'date d emission', 'invoice date'],
        'status' => ['statut', 'etat', 'status'],
        'category' => ['categorie', 'type', 'nature', 'famille', 'category'],
        'description' => ['description', 'libelle', 'designation', 'objet'],
        'expenses.reference' => ['piece', 'n piece', 'justificatif', 'reference', 'ref', 'facture', 'n facture', 'document'],
        'number' => ['n facture', 'numero facture', 'facture', 'numero', 'n', 'invoice number', 'number'],
        'kind' => ['type', 'nature', 'kind'],
        'unit' => ['unite', 'unit', 'um'],
        'color_label' => ['couleur', 'coloris', 'color', 'colour', 'pantone'],
        'grammage_gsm' => ['grammage', 'gsm', 'g m2', 'poids'],
        'width_cm' => ['laize', 'largeur', 'width', 'laize cm', 'largeur cm'],
        'family' => ['famille', 'family', 'categorie', 'category', 'type'],
        'country_code' => ['pays', 'country', 'code pays'],
        'contact_name' => ['contact', 'interlocuteur', 'contact name'],
        'email' => ['email', 'e mail', 'mail', 'courriel'],
        'phone' => ['telephone', 'tel', 'phone', 'mobile', 'gsm'],
        'step' => ['etape', 'operation', 'phase', 'atelier', 'step', 'stage'],
        'site' => ['site', 'usine', 'unite de production', 'plant', 'factory'],
    ];

    /** Words a file uses for a status, a category or a kind of document → the value kept. */
    private const WORDS = [
        'status' => ['produced' => 'produced', 'fabrique' => 'produced', 'produit' => 'produced', 'termine' => 'produced', 'delivered' => 'delivered', 'livre' => 'delivered',
            'expedie' => 'delivered', 'shipped' => 'delivered', 'cancelled' => 'cancelled', 'canceled' => 'cancelled', 'annule' => 'cancelled'],
        'category' => ['subcontracting' => 'subcontracting', 'soustraitance' => 'subcontracting', 'externalservice' => 'external_service', 'prestation' => 'external_service',
            'service' => 'external_service', 'purchase' => 'purchase', 'achat' => 'purchase', 'transport' => 'transport', 'labour' => 'labour', 'labor' => 'labour', 'maindoeuvre' => 'labour',
            'mo' => 'labour', 'other' => 'other', 'autre' => 'other', 'divers' => 'other'],
        'kind' => ['invoice' => 'invoice', 'facture' => 'invoice', 'creditnote' => 'credit_note', 'avoir' => 'credit_note'],
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
            'color_label' => $v['color_label'], 'grammage_gsm' => $v['grammage_gsm'], 'width_cm' => $v['width_cm'], 'category' => $v['category'],
        ], fn ($value) => $value !== null));

        if ($v['batch_number'] !== null) {
            $material = RawMaterial::query()->where('reference', $v['reference'])->firstOrFail();
            $batch = array_filter(['received_on' => $v['received_on'], 'quantity' => $v['batch_quantity']], fn ($value) => $value !== null);
            $changed = $this->sync('raw_material_batches', ['raw_material_id' => $material->id, 'batch_number' => $v['batch_number']], $batch,
                // A new lot is delivered, unless said otherwise, by the supplier of its material; an existing lot keeps its own.
                ['company_id' => $material->company_id, 'ulid' => strtoupper((string) Str::ulid()), 'production_supplier_id' => $material->production_supplier_id, 'import_source' => 'import']);
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

        $changed = $this->sync('customer_order_lines', ['customer_order_id' => $order->id, 'article_id' => $articleId], array_filter(['quantity' => $v['quantity'], 'unit_price' => $v['unit_price']], fn ($value) => $value !== null), ['company_id' => $order->getAttribute('company_id')]);

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
     * A delivery of a material: its supplier, its quantity and what it cost. The material must exist.
     *
     * @param  array<string, mixed>  $v
     * @param  array<string, int>  $also
     */
    private function importReceivedLots(array $v, array &$also): string
    {
        $material = $this->known(RawMaterial::class, 'reference', $v['material'], 'received_lots.material');
        $existing = RawMaterialBatch::query()->where('raw_material_id', $material->id)->where('batch_number', $v['batch_number'])->first();
        $quantity = $v['batch_quantity'] ?? ($existing?->getAttribute('quantity') === null ? null : (string) $existing->getAttribute('quantity'));
        $amounts = ProductionFinance::reconcile($quantity, $v['unit_price'], $v['total_amount']) ?? throw new RuntimeException(__('imports.errors.amount_mismatch'));
        $priced = $amounts['unit_price'] !== null || $amounts['total_amount'] !== null || $v['delivery_cost'] !== null;

        return $this->upsert(RawMaterialBatch::class, ['raw_material_id' => $material->id, 'batch_number' => $v['batch_number']], array_filter([
            // Named on the line, or — for a new lot only — the supplier the material proposes.
            'production_supplier_id' => $v['supplier'] !== null ? $this->party(ProductionSupplier::class, $v['supplier'], 'suppliers', $also) : ($existing === null ? $material->getAttribute('production_supplier_id') : null),
            'supplier_batch_reference' => $v['supplier_batch_reference'], 'received_on' => $v['received_on'], 'quantity' => $v['batch_quantity'],
            'unit_price' => $v['unit_price'] === null && $v['total_amount'] === null ? null : $amounts['unit_price'],
            'total_amount' => $v['unit_price'] === null && $v['total_amount'] === null ? null : $amounts['total_amount'],
            'delivery_cost' => $v['delivery_cost'],
            'currency_code' => $priced ? ($this->currency($v['currency']) ?? ($existing?->getAttribute('currency_code') === null ? $this->companyCurrency() : null)) : null,
            'delivery_note' => $v['delivery_note'], 'invoice_reference' => $v['invoice_reference'], 'external_reference' => $v['external_reference'],
            'import_source' => $existing === null ? 'import' : null,
        ], fn ($value) => $value !== null));
    }

    /**
     * What an OF took of a material, from which received lot. One line per OF, material, lot and stage; a line without
     * a lot stays "lot non précisé".
     *
     * @param  array<string, mixed>  $v
     * @param  array<string, int>  $also
     */
    private function importConsumptions(array $v, array &$also): string
    {
        $of = $this->known(ProductionOrder::class, 'order_number', $v['manufacturing_order'], 'consumptions.manufacturing_order');
        $material = $this->known(RawMaterial::class, 'reference', $v['material'], 'consumptions.material');
        $batchId = $v['batch_number'] === null ? null : (RawMaterialBatch::query()->where('raw_material_id', $material->id)->where('batch_number', $v['batch_number'])->value('id')
            ?? throw new RuntimeException(__('imports.errors.unknown_lot', ['lot' => $v['batch_number'], 'material' => $v['material']])));
        $companyId = (int) $of->getAttribute('company_id');
        $stepId = $v['step'] === null ? null : $this->step($companyId, $v['step'], 'consumptions.step');
        $exists = DB::table('production_order_consumptions')->where('production_order_id', $of->id)->where('raw_material_id', $material->id)
            ->where(fn ($query) => $batchId === null ? $query->whereNull('raw_material_batch_id') : $query->where('raw_material_batch_id', $batchId))
            ->where(fn ($query) => $stepId === null ? $query->whereNull('production_step_id') : $query->where('production_step_id', $stepId))->exists();
        $changed = $this->sync('production_order_consumptions', ['production_order_id' => $of->id, 'raw_material_id' => $material->id, 'raw_material_batch_id' => $batchId, 'production_step_id' => $stepId],
            array_filter(['quantity' => $v['consumed_quantity'], 'site_id' => $v['site'] === null ? null : $this->site($companyId, $v['site'], 'consumptions.site'), 'consumed_on' => $v['consumed_on']],
                fn ($value) => $value !== null), ['company_id' => $companyId]);

        return ! $exists ? 'created' : ($changed ? 'updated' : 'unchanged');
    }

    /**
     * Finished goods made by an OF. The client and the order are those of the OF: the line cannot say others.
     *
     * @param  array<string, mixed>  $v
     * @param  array<string, int>  $also
     */
    private function importProducedLots(array $v, array &$also): string
    {
        $of = $this->known(ProductionOrder::class, 'order_number', $v['manufacturing_order'], 'produced_lots.manufacturing_order');
        $companyId = (int) $of->getAttribute('company_id');
        $existing = ProducedLot::query()->where('lot_number', $v['lot_number'])->first();
        if ($existing !== null && (int) $existing->getAttribute('production_order_id') !== (int) $of->id) {
            throw new RuntimeException(__('imports.errors.lot_of_other_order', ['lot' => $v['lot_number']]));
        }
        $status = $v['status'] === null ? ($v['delivered_on'] !== null ? 'delivered' : null) : $this->word('status', $v['status'], 'produced_lots.status');
        $outcome = $this->upsert(ProducedLot::class, ['lot_number' => $v['lot_number']], array_filter([
            'production_order_id' => $of->id, 'quantity' => $v['quantity'], 'produced_on' => $v['produced_on'], 'status' => $status, 'delivered_on' => $v['delivered_on'],
            'production_step_id' => $v['step'] === null ? null : $this->step($companyId, $v['step'], 'produced_lots.step'),
            'site_id' => $v['site'] === null ? null : $this->site($companyId, $v['site'], 'produced_lots.site'),
            'external_reference' => $v['external_reference'], 'unit_id' => $existing === null ? DB::table('units')->where('code', 'piece')->value('id') : null,
            'import_source' => $existing === null ? 'import' : null,
        ], fn ($value) => $value !== null));
        // The pieces made by the OF are the sum of its lots.
        $of->forceFill(['quantity_produced' => (int) DB::table('produced_lots')->where('production_order_id', $of->id)->where('status', '!=', 'cancelled')->sum('quantity')])->save();

        return $outcome;
    }

    /**
     * A cost of an OF beside its materials. The document reference identifies it: the same reference on the same OF is
     * the same expense, so a file imported twice does not count it twice.
     *
     * @param  array<string, mixed>  $v
     * @param  array<string, int>  $also
     */
    private function importExpenses(array $v, array &$also): string
    {
        $of = $this->known(ProductionOrder::class, 'order_number', $v['manufacturing_order'], 'expenses.manufacturing_order');
        $existing = ProductionOrderExpense::query()->where('production_order_id', $of->id)->where('reference', $v['reference'])->first();

        return $this->upsert(ProductionOrderExpense::class, ['production_order_id' => $of->id, 'reference' => $v['reference']], array_filter([
            'description' => $v['description'], 'amount' => $v['amount'], 'incurred_on' => $v['incurred_on'],
            'category' => $v['category'] === null ? ($existing === null ? 'other' : null) : $this->word('category', $v['category'], 'expenses.category'),
            'production_supplier_id' => $v['supplier'] === null ? null : $this->party(ProductionSupplier::class, $v['supplier'], 'suppliers', $also),
            'currency_code' => $this->currency($v['currency']) ?? ($existing === null ? $this->companyCurrency() : null), 'import_source' => $existing === null ? 'import' : null,
        ], fn ($value) => $value !== null));
    }

    /**
     * An invoice or a credit note issued for an order, optionally for one of its OFs.
     *
     * @param  array<string, mixed>  $v
     * @param  array<string, int>  $also
     */
    private function importInvoices(array $v, array &$also): string
    {
        $order = $this->known(CustomerOrder::class, 'order_number', $v['customer_order'], 'invoices.customer_order');
        $of = $v['manufacturing_order'] === null ? null : $this->known(ProductionOrder::class, 'order_number', $v['manufacturing_order'], 'invoices.manufacturing_order');
        if ($of !== null && (int) $of->getAttribute('customer_order_id') !== (int) $order->id) {
            throw new RuntimeException(__('imports.errors.order_of_other', ['of' => $v['manufacturing_order'], 'order' => $v['customer_order']]));
        }
        $existing = CustomerOrderInvoice::query()->where('number', $v['number'])->first();
        if ($existing !== null && (int) $existing->getAttribute('customer_order_id') !== (int) $order->id) {
            throw new RuntimeException(__('imports.errors.invoice_of_other_order', ['number' => $v['number']]));
        }

        return $this->upsert(CustomerOrderInvoice::class, ['number' => $v['number']], array_filter([
            'customer_order_id' => $order->id, 'production_order_id' => $of?->id, 'amount' => $v['amount'], 'issued_on' => $v['issued_on'],
            'kind' => $v['kind'] === null ? ($existing === null ? 'invoice' : null) : $this->word('kind', $v['kind'], 'invoices.kind'),
            'currency_code' => $this->currency($v['currency']) ?? ($existing === null ? ($order->getAttribute('currency_code') ?? $this->companyCurrency()) : null),
            'import_source' => $existing === null ? 'import' : null,
        ], fn ($value) => $value !== null));
    }

    /**
     * A record the line depends on: it must already exist. Nothing is created in its place.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $model
     * @return T
     */
    private function known(string $model, string $column, string $value, string $field): Model
    {
        return $model::query()->where($column, $value)->first() ?? throw new RuntimeException(__('imports.errors.unknown', ['what' => __("imports.fields.{$field}"), 'value' => $value]));
    }

    private function step(int $companyId, string $value, string $field): int
    {
        $step = ProductionData::steps($companyId)->get(['id', 'code', 'name'])
            ->first(fn ($step) => in_array(self::normalize($value), array_map(self::normalize(...), [$step->code, ...array_values((array) json_decode((string) $step->name, true))]), true))
            ?? throw new RuntimeException(__('imports.errors.unknown', ['what' => __("imports.fields.{$field}"), 'value' => $value]));

        return (int) $step->id;
    }

    private function site(int $companyId, string $value, string $field): int
    {
        return (int) (DB::table('sites')->where('company_id', $companyId)->where(fn ($query) => $query->where('code', $value)->orWhere('name', $value))->value('id')
            ?? throw new RuntimeException(__('imports.errors.unknown', ['what' => __("imports.fields.{$field}"), 'value' => $value])));
    }

    private function word(string $kind, string $value, string $field): string
    {
        return self::WORDS[$kind][self::normalize($value)] ?? throw new RuntimeException(__('imports.errors.unknown', ['what' => __("imports.fields.{$field}"), 'value' => $value]));
    }

    private function currency(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return DB::table('currencies')->where('code', Str::upper(trim($value)))->value('code') ?? throw new RuntimeException(__('imports.errors.currency', ['value' => $value]));
    }

    private function companyCurrency(): string
    {
        return (string) DB::table('companies')->where('id', app(TenantContext::class)->companyId())->value('currency_code');
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
