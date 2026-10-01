<?php

namespace App\Modules\Tenancy;

/**
 * Every database table must be classified (blueprint §3.2). Enforced by SchemaClassificationTest:
 * add each new table here in the same change as its migration.
 */
final class TableClassification
{
    public const ROOT = ['companies'];

    /** company_id NOT NULL + BelongsToCompany */
    public const TENANT = [
        'company_textile_activities', 'company_users', 'number_sequences', 'sites', 'zones',
        'user_role_assignments', 'user_invitations', 'company_subscriptions', 'invoices',
        'invoice_items', 'payments', 'waste_types', 'stock_thresholds', 'devices', 'sync_operations', 'waste_lots',
        'waste_lot_compositions', 'lot_tags', 'waste_lot_operations', 'waste_lot_lineage', 'waste_lot_events',
        // Provider-owned, read by industrials through the directory.
        'provider_profiles', 'provider_accepted_wastes', 'provider_accreditations', 'provider_partnerships',
        // Party-shared: owner company_id + provider/transporter columns.
        'pickups', 'pickup_lots',
    ];

    /** company_id NULL = platform row */
    public const MIXED = ['roles', 'stored_files'];

    public const GLOBAL = [
        'currencies', 'countries', 'tax_rates', 'textile_activities', 'legal_documents', 'zone_types',
        'packaging_types', 'units', 'waste_families', 'materials', 'color_families', 'treatment_channels',
        'regulatory_waste_codes', 'waste_catalog_items', 'accreditation_types', 'subscription_plans',
        'subscription_plan_prices', 'permissions', 'role_has_permissions',
    ];

    public const SYSTEM = [
        'users', 'password_reset_tokens', 'personal_access_tokens', 'legal_acceptances',
        'invoice_number_sequences', 'model_has_roles', 'model_has_permissions', 'failed_jobs', 'job_batches',
        'migrations',
    ];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [...self::ROOT, ...self::TENANT, ...self::MIXED, ...self::GLOBAL, ...self::SYSTEM];
    }
}
