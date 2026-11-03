<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two things.
 *
 * What a lot is made of, for every company: entered by a person, copied from a raw material, from the defaults of the
 * waste type, or averaged from grouped lots — the lot says which. Each pickup line keeps the composition the lot had
 * when it was put on the pickup, and the tracking slip prints it.
 *
 * The bourse (M4-06): a company offers lots it produced to the eligible providers of the platform, who propose a price;
 * each buyer negotiates privately with the seller, proposal after proposal, until one side accepts or refuses. An
 * accepted deal becomes a pickup to that buyer, at the agreed price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waste_lots', function (Blueprint $table) {
            // null = no composition known.
            $table->enum('composition_source', ['entered', 'raw_material', 'waste_type', 'grouping', 'split'])->nullable()->after('composition_label');
        });
        // What existing lots carry came from somewhere: from a raw material when the lot names one, from grouped lots
        // for a grouping, else from the waste type. A person may have typed it at creation; that is not known any more.
        DB::table('waste_lots')->whereNotNull('composition_label')->update(['composition_source' => DB::raw(
            "CASE WHEN origin_type = 'grouping' THEN 'grouping' WHEN origin_type = 'split' THEN 'split' WHEN raw_material_id IS NOT NULL THEN 'raw_material' ELSE 'waste_type' END")]);

        Schema::table('pickup_lots', function (Blueprint $table) {
            // [{material, pct}] as the lot was when put on the pickup (updated while the lot has not left).
            $table->json('composition_snapshot')->nullable()->after('waste_type_label_snapshot');
        });
        // Lines whose slip is not issued yet get the composition of their lot; the content of an issued slip is not touched.
        foreach (DB::table('pickup_lots')->whereNull('hazardous_waste_manifest_id')->get(['id', 'waste_lot_id']) as $line) {
            $rows = DB::table('waste_lot_compositions as c')->join('materials as m', 'm.id', '=', 'c.material_id')
                ->where('c.waste_lot_id', $line->waste_lot_id)->orderByDesc('c.percentage')->orderBy('m.code')->get(['m.code', 'c.percentage']);
            if ($rows->isNotEmpty()) {
                DB::table('pickup_lots')->where('id', $line->id)->update(['composition_snapshot' => json_encode($rows->map(fn ($row) => ['material' => $row->code, 'pct' => (string) $row->percentage])->all())]);
            }
        }

        $user = fn (Blueprint $table, string $column, string $fk) => $table->foreignId($column)->nullable()->constrained('users', 'id', $fk)->restrictOnDelete();

        Schema::create('marketplace_listings', function (Blueprint $table) use ($user) {
            $table->id();
            $table->publicUlid();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('waste_type_id');
            $table->string('listing_number', 32);
            $table->string('title', 191);
            $table->text('description')->nullable();
            // sold = a deal was accepted; expired = closing date passed without one.
            $table->enum('status', ['draft', 'published', 'sold', 'expired', 'cancelled'])->default('draft');
            // The seller stays anonymous to buyers until a deal is accepted, unless it chooses to show its name.
            $table->boolean('show_seller')->default(false);
            // An asking price is optional: buyers may accept it as it is, or propose another.
            $table->enum('asking_pricing_mode', ['per_kg', 'per_tonne', 'flat'])->nullable();
            $table->decimal('asking_unit_price', 15, 3)->nullable();
            // revenue = the buyer pays the seller; cost = the seller pays the buyer to take the waste.
            $table->enum('asking_direction', ['revenue', 'cost'])->nullable();
            $table->char('currency_code', 3);
            $table->date('available_from')->nullable();
            $table->dateTime('closes_at')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->unsignedBigInteger('accepted_negotiation_id')->nullable();
            $table->unsignedBigInteger('pickup_id')->nullable();
            $user($table, 'created_by_user_id', 'fk_ml_created_by');
            $table->datetimes();

            $table->foreign('company_id', 'fk_ml_company')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['company_id', 'site_id'], 'fk_ml_site')->references(['company_id', 'id'])->on('sites')->restrictOnDelete();
            $table->foreign(['company_id', 'waste_type_id'], 'fk_ml_waste_type')->references(['company_id', 'id'])->on('waste_types')->restrictOnDelete();
            $table->foreign(['company_id', 'pickup_id'], 'fk_ml_pickup')->references(['company_id', 'id'])->on('pickups')->restrictOnDelete();
            $table->foreign('currency_code', 'fk_ml_currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->unique(['company_id', 'id'], 'uq_ml_company_id_id');
            $table->unique(['company_id', 'listing_number'], 'uq_ml_number');
            $table->index(['status', 'published_at'], 'ix_ml_status_published');
        });

        Schema::create('marketplace_listing_lots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('marketplace_listing_id');
            $table->unsignedBigInteger('waste_lot_id');
            // true while the listing is a draft or published: a lot is offered in one listing at a time.
            $table->boolean('is_active_flag')->nullable()->default(true);
            $table->datetimes();

            $table->foreign(['company_id', 'marketplace_listing_id'], 'fk_mll_listing')->references(['company_id', 'id'])->on('marketplace_listings')->restrictOnDelete();
            $table->foreign(['company_id', 'waste_lot_id'], 'fk_mll_lot')->references(['company_id', 'id'])->on('waste_lots')->restrictOnDelete();
            $table->unique(['company_id', 'marketplace_listing_id', 'waste_lot_id'], 'uq_mll_pair');
            $table->unique(['waste_lot_id', 'is_active_flag'], 'uq_mll_active_lot');
        });

        Schema::create('marketplace_negotiations', function (Blueprint $table) use ($user) {
            $table->id();
            $table->publicUlid();
            // The seller owns the negotiation; the buyer reads it through provider_company_id (party scope).
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('marketplace_listing_id');
            $table->foreignId('provider_company_id')->constrained('companies', 'id', 'fk_mn_provider')->restrictOnDelete();
            // awaiting_* = whose turn it is to answer the last proposal. closed = the listing was sold to another buyer,
            // cancelled or expired.
            $table->enum('status', ['awaiting_seller', 'awaiting_buyer', 'accepted', 'refused', 'withdrawn', 'closed'])->default('awaiting_seller');
            $table->enum('refused_by', ['seller', 'buyer'])->nullable();
            $table->string('closed_reason', 40)->nullable();
            $table->string('end_message', 1000)->nullable();
            $table->unsignedBigInteger('accepted_proposal_id')->nullable();
            // The platform's commission on the deal, as it was when the deal was accepted (0 today).
            $table->decimal('commission_pct', 5, 2)->nullable();
            $table->dateTime('accepted_at')->nullable();
            $user($table, 'accepted_by_user_id', 'fk_mn_accepted_by');
            $table->dateTime('ended_at')->nullable();
            $table->datetimes();

            $table->foreign(['company_id', 'marketplace_listing_id'], 'fk_mn_listing')->references(['company_id', 'id'])->on('marketplace_listings')->restrictOnDelete();
            $table->unique(['company_id', 'id'], 'uq_mn_company_id_id');
            $table->unique(['company_id', 'marketplace_listing_id', 'provider_company_id'], 'uq_mn_listing_provider');
            $table->index(['provider_company_id', 'status'], 'ix_mn_provider');
        });

        Schema::create('marketplace_proposals', function (Blueprint $table) use ($user) {
            $table->id();
            $table->publicUlid();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('marketplace_negotiation_id');
            $table->foreignId('provider_company_id')->constrained('companies', 'id', 'fk_mp_provider')->restrictOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->enum('party', ['seller', 'buyer']);
            $table->enum('pricing_mode', ['per_kg', 'per_tonne', 'flat']);
            $table->decimal('unit_price', 15, 3);
            $table->enum('direction', ['revenue', 'cost']);
            $table->char('currency_code', 3);
            $table->date('pickup_date')->nullable();
            $table->string('message', 1000)->nullable();
            $table->dateTime('expires_at')->nullable();
            // What the lots were when the proposal was made: a proposal on lots that changed since cannot be accepted.
            $table->char('lots_fingerprint', 64)->charset('ascii')->collation('ascii_bin');
            $user($table, 'created_by_user_id', 'fk_mp_created_by');
            $table->datetimes();

            $table->foreign(['company_id', 'marketplace_negotiation_id'], 'fk_mp_negotiation')->references(['company_id', 'id'])->on('marketplace_negotiations')->restrictOnDelete();
            $table->foreign('currency_code', 'fk_mp_currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->unique(['marketplace_negotiation_id', 'sequence'], 'uq_mp_sequence');
        });
        DB::statement('ALTER TABLE marketplace_proposals ADD CONSTRAINT chk_mp_price CHECK (unit_price >= 0)');

        Schema::table('pickups', function (Blueprint $table) {
            // The deal of the bourse this pickup carries out: its agreed price is applied at reception.
            $table->unsignedBigInteger('marketplace_negotiation_id')->nullable()->after('replaces_pickup_id');
            $table->foreign(['company_id', 'marketplace_negotiation_id'], 'fk_pickups_negotiation')->references(['company_id', 'id'])->on('marketplace_negotiations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pickups', function (Blueprint $table) {
            $table->dropForeign('fk_pickups_negotiation');
            $table->dropIndex('fk_pickups_negotiation');
            $table->dropColumn('marketplace_negotiation_id');
        });
        Schema::dropIfExists('marketplace_proposals');
        Schema::dropIfExists('marketplace_negotiations');
        Schema::dropIfExists('marketplace_listing_lots');
        Schema::dropIfExists('marketplace_listings');
        Schema::table('pickup_lots', fn (Blueprint $table) => $table->dropColumn('composition_snapshot'));
        Schema::table('waste_lots', fn (Blueprint $table) => $table->dropColumn('composition_source'));
    }
};
