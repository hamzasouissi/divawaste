<?php

namespace Tests\Feature\Foundation;

use App\Modules\Common\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseConventionsTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('convention_probes');
        parent::tearDown();
    }

    public function test_tests_run_on_mariadb_in_utc_with_utf8mb4(): void
    {
        $this->assertSame('mariadb', DB::connection()->getDriverName());
        $this->assertSame('+00:00', DB::scalar('select @@session.time_zone'));
        $this->assertSame('utf8mb4', DB::scalar('select @@character_set_database'));
    }

    public function test_public_ulid_macro_creates_unique_ascii_binary_char26(): void
    {
        Schema::create('convention_probes', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();
        });

        $column = DB::selectOne(
            "select column_type, character_set_name, collation_name, is_nullable from information_schema.columns
             where table_schema = database() and table_name = 'convention_probes' and column_name = 'ulid'"
        );

        $this->assertSame('char(26)', $column->column_type);
        $this->assertSame('ascii', $column->character_set_name);
        $this->assertSame('ascii_bin', $column->collation_name);
        $this->assertSame('NO', $column->is_nullable);
        $this->assertTrue(Schema::hasIndex('convention_probes', 'convention_probes_ulid_unique', 'unique'));
    }

    public function test_has_public_ulid_generates_uppercase_ulid_and_keeps_client_value(): void
    {
        Schema::create('convention_probes', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();
        });

        $model = new class extends Model
        {
            use HasPublicUlid;

            protected $table = 'convention_probes';

            public $timestamps = false;

            protected $fillable = ['ulid'];
        };

        $generated = $model->newQuery()->create();
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $generated->ulid);
        $this->assertIsInt($generated->getKey());
        $this->assertSame('ulid', $generated->getRouteKeyName());

        $clientUlid = '01JC2Z8M4V7H3K9P2RQW5X6Y8Z';
        $this->assertSame($clientUlid, $model->newQuery()->create(['ulid' => $clientUlid])->ulid);
    }
}
