<?php

namespace App\Modules\CustomViews\Support;

use App\Modules\Identity\Authorization\PermissionCatalog;
use App\Modules\Identity\Enums\PermissionScope;
use App\Modules\Tenancy\TableClassification;

/**
 * Builds DivaBI's application package for DivaWaste: which tables custom views may read and under which row rules.
 *
 * The rules are derived from the code that already governs the data, so they cannot drift from the screens:
 * - TableClassification says which tables belong to a company (company_id), which mix platform rows, which are shared
 *   reference data and which are system tables (never readable);
 * - PermissionCatalog says whether the permission that opens a table is a site permission, in which case the rows are
 *   limited to the user's sites, or the table is refused to users whose permission does not cover every site when it
 *   has no site column to filter on;
 * - config/divabi.php lists the tables that may be read at all (anything else is closed) with their labels.
 *
 * Framework-free on purpose (plain arrays in, plain array out): `php artisan divabi:package` calls it with the live
 * schema, the tests call it with any column list.
 */
final class DivaBiPackage
{
    /**
     * @param  array<string, mixed>  $config  config('divabi')
     * @param  callable(string): list<string>  $columns  column names of a table
     * @return array<string, mixed>
     */
    public static function build(array $config, callable $columns): array
    {
        $permissions = PermissionCatalog::permissions();
        $entities = [];

        foreach (TableClassification::all() as $table) {
            if ($table === 'migrations') {
                continue;
            }
            $definition = $config['entities'][$table] ?? null;
            if ($definition === null || in_array($table, TableClassification::SYSTEM, true)) {
                $entities[$table] = ['access' => 'deny'];

                continue;
            }

            $cols = $columns($table);
            $permission = $definition['permission'] ?? null;
            $rows = [];
            if (in_array($table, TableClassification::ROOT, true)) {
                $rows[] = ['type' => 'tenant_is_self', 'column' => 'id'];
            } elseif (in_array($table, TableClassification::TENANT, true)) {
                $rows[] = ['type' => 'tenant', 'column' => 'company_id'];
            } elseif (in_array($table, TableClassification::MIXED, true)) {
                $rows[] = ['type' => 'tenant_or_null', 'column' => 'company_id'];
            }

            if ($permission !== null && ($permissions[$permission][0] ?? null) === PermissionScope::Site && $rows !== []) {
                if (in_array('site_id', $cols, true)) {
                    $rows[] = ['type' => 'scopes', 'column' => 'site_id', 'permission' => $permission];
                } elseif ($table === 'sites') {
                    $rows[] = ['type' => 'scopes', 'column' => 'id', 'permission' => $permission];
                } else {
                    // No site to filter on: only for users whose permission covers every site of the company.
                    $rows[] = ['type' => 'tenant_wide', 'column' => 'company_id', 'permission' => $permission];
                }
            }
            if (in_array('deleted_at', $cols, true)) {
                // Soft-deleted rows are gone for the screens; they are gone for custom views too.
                $rows[] = ['type' => 'is_null', 'column' => 'deleted_at'];
            }

            $columnRequires = [];
            foreach ($definition['column_permissions'] ?? [] as $column => $needed) {
                $columnRequires[$column] = (array) $needed;
            }
            $finance = $config['finance'] ?? null;
            if ($finance !== null && in_array($table, $finance['tables'], true)) {
                foreach ($cols as $column) {
                    if (preg_match($finance['pattern'], $column)) {
                        $columnRequires[$column] = [$finance['permission']];
                    }
                }
            }

            $entities[$table] = array_filter([
                'access' => 'allow',
                'requires' => $permission !== null ? [$permission] : [],
                'rows' => $rows,
                'label' => $definition['label'] ?? [],
                'hidden_columns' => array_values(array_unique(array_intersect(array_merge($definition['hidden'] ?? [], $config['always_hidden'] ?? []), $cols))),
                'column_requires' => $columnRequires,
                'column_labels' => ($definition['columns'] ?? []) + array_intersect_key($config['common_columns'] ?? [], array_flip($cols)),
                'display_column' => $definition['display'] ?? null,
                'route' => $definition['route'] ?? null,
            ], fn ($value) => $value !== null && $value !== []) + ['access' => 'allow'];
        }
        ksort($entities);

        $slots = [];
        foreach ($config['slots'] ?? [] as $name => $slot) {
            $slots[$name] = ['label' => $slot['label'], 'context' => $slot['context'] ?? []];
        }

        return [
            'schema_version' => 1,
            'id' => 'divawaste',
            'name' => 'DivaWaste',
            'version' => self::version($entities, $slots),
            'default_locale' => 'fr',
            'connector' => ['type' => 'mysql', 'dsn_env' => 'DIVABI_DIVAWASTE_DSN', 'flavor' => 'mariadb', 'timeout_s' => 20, 'max_rows' => 5000],
            'identity' => [
                'type' => 'jwt-hs256',
                'secret_env' => 'DIVABI_DIVAWASTE_SECRET',
                'issuer' => $config['issuer'] ?? 'divawaste',
                'audience' => $config['audience'] ?? 'divabi',
                'tenant_lookup' => ['table' => 'companies', 'by' => 'ulid', 'id' => 'id'],
                'scope_lookup' => ['table' => 'sites', 'by' => 'ulid', 'id' => 'id', 'tenant_column' => 'company_id'],
                'build_permission' => 'custom_views.manage',
                'view_permission' => 'custom_views.view',
            ],
            'model' => [
                'default_access' => 'deny',
                'tenant_columns' => ['company_id'],
                'hide_internal_ids' => true,
                'sensitive_columns' => ['ip_address', 'user_agent', 'tax_id', 'iban', 'rib'],
                'localized_columns' => $config['localized_columns'] ?? [],
                'discover_relationships' => true,
            ],
            'entities' => $entities,
            'relationships' => [],
            'slots' => $slots,
        ];
    }

    /** @param  array<string, mixed>  ...$parts */
    private static function version(array ...$parts): string
    {
        return substr(hash('sha256', json_encode($parts)), 0, 12);
    }
}
