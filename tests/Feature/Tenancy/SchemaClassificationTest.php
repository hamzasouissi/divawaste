<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Tenancy\TableClassification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SchemaClassificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_table_is_classified_exactly_once(): void
    {
        $tables = collect(DB::select('select table_name as name from information_schema.tables where table_schema = database()'))
            ->pluck('name')->sort()->values()->all();
        $classified = TableClassification::all();

        $this->assertSame([], array_values(array_diff($tables, $classified)), 'Unclassified tables: add them to TableClassification.');
        $this->assertSame([], array_values(array_diff($classified, $tables)), 'Classified tables that do not exist.');
        $this->assertSame(count($classified), count(array_unique($classified)), 'A table is classified twice.');
    }

    public function test_tenant_tables_have_a_non_nullable_company_id_and_mixed_tables_a_nullable_one(): void
    {
        foreach (TableClassification::TENANT as $table) {
            $this->assertSame('NO', $this->companyIdNullability($table), "{$table}.company_id must be NOT NULL");
        }

        foreach (TableClassification::MIXED as $table) {
            $this->assertSame('YES', $this->companyIdNullability($table), "{$table}.company_id must be nullable");
        }

        foreach (TableClassification::GLOBAL as $table) {
            $this->assertNull($this->companyIdNullability($table), "{$table} is global and must not have company_id");
        }
    }

    private function companyIdNullability(string $table): ?string
    {
        $value = DB::scalar(
            "select is_nullable from information_schema.columns where table_schema = database() and table_name = ? and column_name = 'company_id'",
            [$table],
        );

        return is_string($value) ? $value : null;
    }
}
