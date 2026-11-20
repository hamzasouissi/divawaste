<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Scrap reasons (motifs de rebut): a list the company keeps, so that lots can be counted, weighed and valued per reason.
 *
 *  - code, label: what the plant writes (UE24) and says ("Trous dans le tissu");
 *  - category: the family of causes (the 6 M of a cause-and-effect diagram: matière, machine, méthode, main-d'œuvre,
 *    mesure, milieu), or none;
 *  - color: how the reason shows in lists and charts;
 *  - a reason is never deleted once lots point to it: it is deactivated.
 *
 * A lot names its reason (scrap_reason_id) and may add a note of its own (scrap_note). The free code and reason that
 * lots had until now become reasons of their company: one per code, named after the text most lots gave it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scrap_reasons', function (Blueprint $table) {
            $table->id();
            $table->publicUlid();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_scrap_reasons_company')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('label', 150);
            $table->string('category', 20)->nullable();
            $table->char('color', 7)->nullable();
            $table->string('description', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(1000);
            $table->boolean('is_active')->default(true);
            $table->datetimes();

            $table->unique(['company_id', 'code'], 'uq_scrap_reasons_code');
            $table->unique(['company_id', 'id'], 'uq_scrap_reasons_company_id');
            $table->index(['company_id', 'is_active', 'sort_order'], 'ix_scrap_reasons_active');
        });

        Schema::table('waste_lots', function (Blueprint $table) {
            $table->unsignedBigInteger('scrap_reason_id')->nullable()->after('cost_basis');
            $table->index(['company_id', 'scrap_reason_id'], 'ix_waste_lots_scrap_reason');
            // Reasons are deactivated, never deleted, while a lot names them.
            $table->foreign(['company_id', 'scrap_reason_id'], 'fk_waste_lots_scrap_reason')->references(['company_id', 'id'])->on('scrap_reasons')->restrictOnDelete();
        });

        // What lots said until now: a code becomes a reason, the text most lots gave it its label; a text that differs
        // from that label stays on the lot as its note.
        $now = now();
        $given = DB::table('waste_lots')->whereNotNull('scrap_code')->where('scrap_code', '!=', '')
            ->select('company_id', 'scrap_code', 'scrap_reason', DB::raw('count(*) as lots'))
            ->groupBy('company_id', 'scrap_code', 'scrap_reason')->get();
        foreach ($given->groupBy(fn ($row) => $row->company_id.'|'.Str::upper(trim($row->scrap_code))) as $rows) {
            $first = $rows->first();
            $code = Str::upper(trim($first->scrap_code));
            $label = $rows->filter(fn ($row) => trim((string) $row->scrap_reason) !== '')->sortByDesc('lots')->first()?->scrap_reason;
            $label = Str::limit(trim((string) ($label ?? $code)), 147);
            $id = DB::table('scrap_reasons')->insertGetId(['ulid' => (string) Str::ulid(), 'company_id' => $first->company_id, 'code' => Str::limit($code, 30, ''),
                'label' => $label, 'sort_order' => 1000, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            foreach ($rows as $row) {
                DB::table('waste_lots')->where('company_id', $row->company_id)->where('scrap_code', $row->scrap_code)->update(['scrap_reason_id' => $id]);
            }
            DB::table('waste_lots')->where('scrap_reason_id', $id)->where('scrap_reason', $label)->update(['scrap_reason' => null]);
        }

        Schema::table('waste_lots', function (Blueprint $table) {
            $table->dropColumn('scrap_code');
        });
        Schema::table('waste_lots', function (Blueprint $table) {
            $table->renameColumn('scrap_reason', 'scrap_note');
        });
    }

    public function down(): void
    {
        Schema::table('waste_lots', function (Blueprint $table) {
            $table->renameColumn('scrap_note', 'scrap_reason');
        });
        Schema::table('waste_lots', function (Blueprint $table) {
            $table->string('scrap_code', 30)->nullable()->after('cost_basis');
        });
        foreach (DB::table('scrap_reasons')->get(['id', 'code', 'label']) as $reason) {
            DB::table('waste_lots')->where('scrap_reason_id', $reason->id)->update(['scrap_code' => $reason->code]);
            DB::table('waste_lots')->where('scrap_reason_id', $reason->id)->whereNull('scrap_reason')->update(['scrap_reason' => $reason->label]);
        }
        Schema::table('waste_lots', function (Blueprint $table) {
            $table->dropForeign('fk_waste_lots_scrap_reason');
            $table->dropIndex('ix_waste_lots_scrap_reason');
            $table->dropColumn('scrap_reason_id');
        });
        Schema::dropIfExists('scrap_reasons');
    }
};
