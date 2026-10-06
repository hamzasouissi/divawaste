<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Global reference data defined by the cahier (idempotent: upsert on natural keys).
 *
 * Deliberately NOT seeded until validated sources exist (blueprint §24):
 * tax_rates (P-10), regulatory_waste_codes (P-21), accreditation_types (P-14),
 * legal_documents (legal text), subscription_plan_prices (P-07).
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->upsert('currencies', 'code', [
            ['code' => 'TND', 'name' => $this->t('Dinar tunisien', 'Tunisian dinar'), 'minor_unit' => 3, 'symbol' => 'DT'],
            ['code' => 'MAD', 'name' => $this->t('Dirham marocain', 'Moroccan dirham'), 'minor_unit' => 2, 'symbol' => 'DH'],
            ['code' => 'EUR', 'name' => $this->t('Euro', 'Euro'), 'minor_unit' => 2, 'symbol' => '€'],
        ]);

        $this->upsert('countries', 'iso2', [
            $this->country('TN', 'TUN', 'Tunisie', 'Tunisia', 'TND', 'Africa/Tunis', '+216', 'Matricule fiscal', 'Tax ID', 'TN', 'ANGed', true),
            $this->country('MA', 'MAR', 'Maroc', 'Morocco', 'MAD', 'Africa/Casablanca', '+212', 'ICE', 'ICE', 'MA', null, false),
            $this->country('FR', 'FRA', 'France', 'France', 'EUR', 'Europe/Paris', '+33', 'SIREN', 'SIREN', 'EU_LOW', null, false),
            $this->country('BE', 'BEL', 'Belgique', 'Belgium', 'EUR', 'Europe/Brussels', '+32', 'Numéro d\'entreprise (BCE)', 'Enterprise number', 'EU_LOW', null, false),
        ]);

        $this->upsert('textile_activities', 'code', $this->vocabulary([
            'spinning' => ['Filature', 'Spinning'],
            'weaving' => ['Tissage', 'Weaving'],
            'knitting' => ['Tricotage', 'Knitting'],
            'dyeing' => ['Teinture', 'Dyeing'],
            'finishing' => ['Ennoblissement / finissage', 'Finishing'],
            'garment_making' => ['Confection', 'Garment making'],
            'washing' => ['Délavage', 'Washing'],
            'printing' => ['Impression', 'Printing'],
            'other' => ['Autre', 'Other'],
        ]));

        $this->upsert('zone_types', 'code', $this->vocabulary([
            'cutting' => ['Coupe', 'Cutting'],
            'sewing' => ['Confection', 'Sewing'],
            'dyeing' => ['Teinture', 'Dyeing'],
            'finishing' => ['Finissage', 'Finishing'],
            'warehouse' => ['Magasin', 'Warehouse'],
            'waste_storage' => ['Aire de stockage déchets', 'Waste storage area', ['is_waste_storage' => true]],
            'spinning' => ['Filature', 'Spinning'],
            'weaving' => ['Tissage', 'Weaving'],
            'knitting' => ['Tricotage', 'Knitting'],
            'other' => ['Autre', 'Other'],
        ]));

        $this->upsert('packaging_types', 'code', $this->vocabulary([
            'bag' => ['Sac', 'Bag'],
            'bale' => ['Balle', 'Bale'],
            'big_bag' => ['Big-bag', 'Big bag'],
            'drum' => ['Fût', 'Drum'],
            'box' => ['Carton', 'Box'],
            'pallet' => ['Palette', 'Pallet'],
            'container' => ['Conteneur', 'Container'],
            'bulk' => ['Vrac', 'Bulk'],
        ]));

        $this->upsert('units', 'code', [
            ['code' => 'kg', 'name' => $this->t('Kilogramme', 'Kilogram'), 'symbol' => 'kg', 'dimension' => 'mass', 'factor_to_base' => '1', 'is_base' => true],
            ['code' => 't', 'name' => $this->t('Tonne', 'Tonne'), 'symbol' => 't', 'dimension' => 'mass', 'factor_to_base' => '1000', 'is_base' => false],
            ['code' => 'm3', 'name' => $this->t('Mètre cube', 'Cubic metre'), 'symbol' => 'm³', 'dimension' => 'volume', 'factor_to_base' => '1', 'is_base' => true],
            ['code' => 'l', 'name' => $this->t('Litre', 'Litre'), 'symbol' => 'l', 'dimension' => 'volume', 'factor_to_base' => '0.001', 'is_base' => false],
            ['code' => 'piece', 'name' => $this->t('Pièce', 'Piece'), 'symbol' => 'pc', 'dimension' => 'count', 'factor_to_base' => '1', 'is_base' => true],
        ]);

        $this->upsert('waste_families', 'code', $this->vocabulary([
            'textile_fibre' => ['Fibres et fils textiles', 'Textile fibres and yarns', ['is_textile' => true]],
            'textile_product' => ['Produits textiles', 'Textile products', ['is_textile' => true]],
            'packaging' => ['Emballages', 'Packaging'],
            'chemical_sludge' => ['Produits chimiques et boues', 'Chemicals and sludges'],
            'oil' => ['Huiles', 'Oils'],
            'metal' => ['Métaux', 'Metals'],
            'other' => ['Autres', 'Other'],
        ]));

        $this->upsert('materials', 'code', $this->vocabulary([
            'CO' => ['Coton', 'Cotton', ['material_class' => 'natural']],
            'LI' => ['Lin', 'Linen', ['material_class' => 'natural']],
            'WO' => ['Laine', 'Wool', ['material_class' => 'natural']],
            'CV' => ['Viscose', 'Viscose', ['material_class' => 'artificial']],
            'PES' => ['Polyester', 'Polyester', ['material_class' => 'synthetic']],
            'PA' => ['Polyamide', 'Polyamide', ['material_class' => 'synthetic']],
            'PAN' => ['Acrylique', 'Acrylic', ['material_class' => 'synthetic']],
            'EL' => ['Élasthanne', 'Elastane', ['material_class' => 'synthetic']],
            'OTHER' => ['Autre', 'Other', ['material_class' => 'other']],
        ]));

        $this->upsert('color_families', 'code', $this->vocabulary([
            'white' => ['Blanc', 'White', ['hex_color' => '#FFFFFF']],
            'ecru' => ['Écru', 'Ecru', ['hex_color' => '#F3EBD3']],
            'undyed' => ['Non teint', 'Undyed', ['hex_color' => '#EDE6DA']],
            'black' => ['Noir', 'Black', ['hex_color' => '#000000']],
            'grey' => ['Gris', 'Grey', ['hex_color' => '#8C8C8C']],
            'navy' => ['Marine', 'Navy', ['hex_color' => '#1F2A44']],
            'blue' => ['Bleu', 'Blue', ['hex_color' => '#2F6FD0']],
            'red' => ['Rouge', 'Red', ['hex_color' => '#C62828']],
            'pink' => ['Rose', 'Pink', ['hex_color' => '#E91E63']],
            'yellow' => ['Jaune', 'Yellow', ['hex_color' => '#F2C230']],
            'orange' => ['Orange', 'Orange', ['hex_color' => '#EF7D22']],
            'green' => ['Vert', 'Green', ['hex_color' => '#2E7D32']],
            'brown' => ['Marron', 'Brown', ['hex_color' => '#6D4C41']],
            'beige' => ['Beige', 'Beige', ['hex_color' => '#D8C3A5']],
            'multicolor' => ['Multicolore', 'Multicolour'],
            'mixed' => ['Mélangé', 'Mixed'],
        ]));

        $this->upsert('treatment_channels', 'code', $this->vocabulary([
            'reuse' => ['Réutilisation', 'Reuse', ['is_valorization' => true, 'hierarchy_rank' => 1]],
            'recycling' => ['Recyclage', 'Recycling', ['is_valorization' => true, 'hierarchy_rank' => 2]],
            'energy_recovery' => ['Valorisation énergétique', 'Energy recovery', ['is_valorization' => true, 'hierarchy_rank' => 3]],
            'incineration' => ['Incinération sans valorisation', 'Incineration', ['is_valorization' => false, 'hierarchy_rank' => 4]],
            'other_treatment' => ['Autre traitement', 'Other treatment', ['is_valorization' => false, 'hierarchy_rank' => 4]],
            'landfill' => ['Enfouissement', 'Landfill', ['is_valorization' => false, 'is_landfill' => true, 'hierarchy_rank' => 5]],
        ]));

        $this->seedCatalog();

        $this->upsert('subscription_plans', 'code', [[
            'code' => 'trial',
            'name' => $this->t('Essai gratuit 30 jours', '30-day free trial'),
            'billing_model' => 'trial',
            'is_trial' => true,
            'trial_days' => 30,
            'is_public' => true,
            'sort_order' => 0,
        ]]);
    }

    /**
     * The 14 default waste types of the cahier (M1-03). Regulatory codes are linked once validated (P-21).
     */
    private function seedCatalog(): void
    {
        $family = DB::table('waste_families')->pluck('id', 'code');
        $kg = DB::table('units')->where('code', 'kg')->value('id');

        $items = [
            'cutting_scraps' => ['Chutes de coupe', 'Cutting scraps', 'textile_product'],
            'selvedges' => ['Lisières', 'Selvedges', 'textile_product'],
            'yarn_waste' => ['Déchets de fil', 'Yarn waste', 'textile_fibre'],
            'garment_rejects' => ['Rebuts de confection', 'Garment rejects', 'textile_product'],
            'roll_ends' => ['Fins de rouleaux', 'Roll ends', 'textile_product'],
            'downgraded_pieces' => ['Pièces déclassées', 'Downgraded pieces', 'textile_product'],
            'cardboard' => ['Cartons', 'Cardboard', 'packaging'],
            'cones' => ['Cônes', 'Cones', 'packaging'],
            'plastic_films_bags' => ['Films et sacs plastique', 'Plastic films and bags', 'packaging'],
            'etp_sludge' => ['Boues de STEP', 'Wastewater treatment sludge', 'chemical_sludge'],
            'dye_residues' => ['Résidus de teinture', 'Dye residues', 'chemical_sludge'],
            'oils' => ['Huiles', 'Oils', 'oil'],
            'needles' => ['Aiguilles', 'Needles', 'metal'],
            'metals' => ['Métaux', 'Metals', 'metal'],
        ];

        $rows = [];
        $order = 0;
        foreach ($items as $code => [$fr, $en, $familyCode]) {
            $rows[] = [
                'code' => $code,
                'name' => $this->t($fr, $en),
                'waste_family_id' => $family[$familyCode],
                'default_unit_id' => $kg,
                // Provisional: only oils flagged hazardous until codes are validated (P-21).
                'default_is_hazardous' => $code === 'oils',
                'sort_order' => $order += 10,
            ];
        }

        $this->upsert('waste_catalog_items', 'code', $rows);
    }

    /**
     * @param  array<string, array{0: string, 1: string, 2?: array<string, mixed>}>  $entries
     * @return list<array<string, mixed>>
     */
    private function vocabulary(array $entries): array
    {
        $rows = [];
        $order = 0;
        foreach ($entries as $code => $entry) {
            $rows[] = ['code' => $code, 'name' => $this->t($entry[0], $entry[1]), 'sort_order' => $order += 10] + ($entry[2] ?? []);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function country(string $iso2, string $iso3, string $fr, string $en, string $currency, string $tz, string $phone, string $taxFr, string $taxEn, string $codeSystem, ?string $authority, bool $signup): array
    {
        return [
            'iso2' => $iso2, 'iso3' => $iso3, 'name' => $this->t($fr, $en), 'currency_code' => $currency,
            'default_locale' => 'fr', 'default_timezone' => $tz, 'phone_prefix' => $phone,
            'tax_id_label' => $this->t($taxFr, $taxEn), 'waste_code_system' => $codeSystem,
            'regulatory_authority_name' => $authority, 'is_signup_enabled' => $signup,
        ];
    }

    private function t(string $fr, string $en): string
    {
        return (string) json_encode(['fr' => $fr, 'en' => $en], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Insert or update rows on their natural key; all rows of a table must share the same columns.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function upsert(string $table, string $key, array $rows): void
    {
        $columns = array_unique(array_merge(...array_map('array_keys', $rows)));
        $now = now();

        $rows = array_map(function (array $row) use ($columns, $now) {
            foreach ($columns as $column) {
                // Missing flags default to false (NOT NULL columns); anything else to NULL.
                $row[$column] ??= str_starts_with($column, 'is_') ? false : null;
            }

            return $row + ['created_at' => $now, 'updated_at' => $now];
        }, $rows);

        $update = array_values(array_diff([...$columns, 'updated_at'], [$key]));
        DB::table($table)->upsert($rows, [$key], $update);
    }
}
