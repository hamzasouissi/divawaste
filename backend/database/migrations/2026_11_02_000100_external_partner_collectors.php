<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A partner without an account can be a collector, like a provider of the platform: it fetches the waste itself and
 * receives it (the owner, 2026-11-02).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_partners', fn (Blueprint $table) => $table->boolean('is_collector')->default(false)->after('contact_name'));
        DB::statement('ALTER TABLE external_partners DROP CONSTRAINT chk_external_partners_role');
        DB::statement('ALTER TABLE external_partners ADD CONSTRAINT chk_external_partners_role CHECK (is_collector + is_transporter + is_recycler + is_sorter >= 1)');
    }

    public function down(): void
    {
        DB::table('external_partners')->where('is_collector', true)->where('is_transporter', false)->where('is_recycler', false)->where('is_sorter', false)
            ->update(['is_transporter' => true, 'is_recycler' => true]);
        DB::statement('ALTER TABLE external_partners DROP CONSTRAINT chk_external_partners_role');
        Schema::table('external_partners', fn (Blueprint $table) => $table->dropColumn('is_collector'));
        DB::statement('ALTER TABLE external_partners ADD CONSTRAINT chk_external_partners_role CHECK (is_transporter + is_recycler + is_sorter >= 1)');
    }
};
