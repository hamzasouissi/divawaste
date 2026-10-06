<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Reference data the cahier needs to make the MVP flows usable but which has no validated source yet
 * (blueprint §24: P-07 prices, P-10 taxes, P-14 accreditation types, P-21 waste codes, legal texts).
 *
 * Everything here is PROVISIONAL and must be reviewed by Diva Software before production:
 * - waste codes follow the European Waste List numbering that the Tunisian catalogue (décret 2000-2339) mirrors;
 * - VAT 19 % and the 1 TND stamp duty are the Tunisian rates in force when this was written;
 * - plans are created without prices (the price grid is an open point of the cahier, §7);
 * - legal documents are versioned placeholders: the acceptance mechanism is real, the legal text is not written.
 *
 * Idempotent: natural keys are upserted, existing rows are not duplicated.
 */
class ProvisionalReferenceSeeder extends Seeder
{
    public const LEGAL_VERSION = '0.1-provisoire';

    public function run(): void
    {
        $tn = (int) DB::table('countries')->where('iso2', 'TN')->value('id');
        $now = now();

        $this->seedTaxes($tn, $now);
        $this->seedAccreditationTypes($tn, $now);
        $codes = $this->seedWasteCodes($now);
        $this->linkCatalog($codes);
        $this->seedPlans($now);
        $this->seedLegalDocuments($now);
    }

    private function seedTaxes(int $tn, mixed $now): void
    {
        DB::table('tax_rates')->upsert([
            ['country_id' => $tn, 'tax_type' => 'vat', 'code' => 'TN_VAT_STD', 'name' => $this->t('TVA 19 %', 'VAT 19%'),
                'rate_pct' => '19.000', 'fixed_amount' => null, 'currency_code' => null, 'applies_to' => 'subscription',
                'valid_from' => '2018-01-01', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['country_id' => $tn, 'tax_type' => 'stamp_duty', 'code' => 'TN_STAMP', 'name' => $this->t('Droit de timbre', 'Stamp duty'),
                'rate_pct' => null, 'fixed_amount' => '1.000', 'currency_code' => 'TND', 'applies_to' => 'subscription',
                'valid_from' => '2022-01-01', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ], ['code', 'valid_from'], ['name', 'rate_pct', 'fixed_amount', 'currency_code', 'is_active', 'updated_at']);
    }

    private function seedAccreditationTypes(int $tn, mixed $now): void
    {
        $types = [
            'collection_transport' => ['Autorisation de collecte et transport de déchets non dangereux', 'Non-hazardous waste collection and transport permit', false],
            'collection_transport_hazardous' => ['Autorisation de collecte et transport de déchets dangereux', 'Hazardous waste collection and transport permit', true],
            'recycling_recovery' => ['Autorisation de recyclage et valorisation', 'Recycling and recovery permit', false],
            'hazardous_treatment' => ['Autorisation de traitement de déchets dangereux', 'Hazardous waste treatment permit', true],
            'disposal' => ['Autorisation d\'élimination / mise en décharge', 'Disposal / landfill permit', false],
        ];

        DB::table('accreditation_types')->upsert(array_map(fn (string $code, array $type) => [
            'country_id' => $tn, 'code' => $code, 'name' => $this->t($type[0], $type[1]), 'covers_hazardous' => $type[2],
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ], array_keys($types), $types), ['country_id', 'code'], ['name', 'covers_hazardous', 'updated_at']);
    }

    /**
     * @return array<string, int> code => id
     */
    private function seedWasteCodes(mixed $now): array
    {
        // [code, french label, english label, hazardous]
        $codes = [
            ['04 02 09', 'Matériaux composites (textile imprégné, élastomère, plastomère)', 'Composite materials (impregnated textile, elastomer, plastomer)', false],
            ['04 02 10', 'Matières organiques issues de produits naturels (graisse, cire)', 'Organic matter from natural products (grease, wax)', false],
            ['04 02 14', 'Déchets de finissage contenant des solvants organiques', 'Finishing wastes containing organic solvents', true],
            ['04 02 15', 'Déchets de finissage sans solvants organiques', 'Finishing wastes without organic solvents', false],
            ['04 02 16', 'Teintures et pigments contenant des substances dangereuses', 'Dyestuffs and pigments containing dangerous substances', true],
            ['04 02 17', 'Teintures et pigments sans substances dangereuses', 'Dyestuffs and pigments without dangerous substances', false],
            ['04 02 19', 'Boues de traitement in situ des effluents contenant des substances dangereuses', 'On-site effluent treatment sludges containing dangerous substances', true],
            ['04 02 20', 'Boues de traitement in situ des effluents sans substances dangereuses', 'On-site effluent treatment sludges without dangerous substances', false],
            ['04 02 21', 'Fibres textiles non ouvrées', 'Wastes from unprocessed textile fibres', false],
            ['04 02 22', 'Fibres textiles ouvrées', 'Wastes from processed textile fibres', false],
            ['13 02 05', 'Huiles moteur, de boîte de vitesses et de lubrification non chlorées', 'Non-chlorinated engine, gear and lubricating oils', true],
            ['15 01 01', 'Emballages en papier/carton', 'Paper and cardboard packaging', false],
            ['15 01 02', 'Emballages en matières plastiques', 'Plastic packaging', false],
            ['20 01 40', 'Métaux', 'Metals', false],
        ];

        DB::table('regulatory_waste_codes')->upsert(array_map(fn (array $code) => [
            'code_system' => 'TN', 'code' => $code[0], 'level' => 3, 'label' => $this->t($code[1], $code[2]),
            'is_hazardous' => $code[3], 'is_selectable' => true, 'created_at' => $now, 'updated_at' => $now,
        ], $codes), ['code_system', 'code'], ['label', 'is_hazardous', 'updated_at']);

        return DB::table('regulatory_waste_codes')->where('code_system', 'TN')->pluck('id', 'code')->all();
    }

    /**
     * Default regulatory code, hazard flag and usual channel of the 14 catalogue items (M1-03 / M1-04).
     *
     * @param  array<string, int>  $codes
     */
    private function linkCatalog(array $codes): void
    {
        $channel = DB::table('treatment_channels')->pluck('id', 'code');

        $defaults = [
            'cutting_scraps' => ['04 02 22', 'recycling'],
            'selvedges' => ['04 02 22', 'recycling'],
            'yarn_waste' => ['04 02 22', 'recycling'],
            'garment_rejects' => ['04 02 22', 'reuse'],
            'roll_ends' => ['04 02 22', 'reuse'],
            'downgraded_pieces' => ['04 02 22', 'reuse'],
            'cardboard' => ['15 01 01', 'recycling'],
            'cones' => ['15 01 02', 'recycling'],
            'plastic_films_bags' => ['15 01 02', 'recycling'],
            'etp_sludge' => ['04 02 20', 'landfill'],
            'dye_residues' => ['04 02 16', 'other_treatment'],
            'oils' => ['13 02 05', 'energy_recovery'],
            'needles' => ['20 01 40', 'recycling'],
            'metals' => ['20 01 40', 'recycling'],
        ];

        foreach ($defaults as $item => [$code, $channelCode]) {
            DB::table('waste_catalog_items')->where('code', $item)->update([
                'default_regulatory_waste_code_id' => $codes[$code],
                'default_is_hazardous' => in_array($code, ['04 02 16', '13 02 05'], true),
                'default_treatment_channel_id' => $channel[$channelCode],
            ]);
        }
    }

    private function seedPlans(mixed $now): void
    {
        DB::table('subscription_plans')->upsert([
            ['code' => 'per_site', 'name' => $this->t('Abonnement par site', 'Per-site subscription'), 'billing_model' => 'per_site',
                'is_trial' => false, 'trial_days' => null, 'is_public' => true, 'is_active' => true, 'sort_order' => 10,
                'created_at' => $now, 'updated_at' => $now],
            ['code' => 'per_volume', 'name' => $this->t('Abonnement par volume', 'Per-volume subscription'), 'billing_model' => 'per_volume',
                'is_trial' => false, 'trial_days' => null, 'is_public' => true, 'is_active' => true, 'sort_order' => 20,
                'created_at' => $now, 'updated_at' => $now],
        ], ['code'], ['name', 'billing_model', 'sort_order', 'updated_at']);
    }

    private function seedLegalDocuments(mixed $now): void
    {
        $documents = [
            'terms' => 'Conditions générales d\'utilisation',
            'privacy' => 'Politique de protection des données',
            'provider_terms' => 'Conditions applicables aux prestataires',
        ];

        foreach ($documents as $type => $title) {
            $html = "<h1>{$title}</h1><p>Version provisoire : le texte juridique définitif est à fournir par Diva Software.</p>";
            DB::table('legal_documents')->upsert([[
                'document_type' => $type, 'version' => self::LEGAL_VERSION, 'locale' => 'fr', 'title' => $title,
                'content_html' => $html, 'content_sha256' => hash('sha256', $html), 'published_at' => $now,
                'requires_reacceptance' => true, 'created_at' => $now, 'updated_at' => $now,
            ]], ['document_type', 'version', 'locale'], ['title', 'content_html', 'content_sha256', 'updated_at']);
        }
    }

    private function t(string $fr, string $en): string
    {
        return (string) json_encode(['fr' => $fr, 'en' => $en], JSON_UNESCAPED_UNICODE);
    }
}
