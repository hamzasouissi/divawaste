<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A transporter of the platform that is not the receiver answers the request too: the receiver sets the date, the
 * transporter then confirms that it carries the waste on that date, or refuses. A refusal says whose it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pickups', function (Blueprint $table) {
            $table->dateTime('transporter_confirmed_at')->nullable()->after('confirmed_at');
            $table->foreignId('transporter_confirmed_by_user_id')->nullable()->after('confirmed_by_user_id')
                ->constrained('users', 'id', 'fk_pickups_transporter_confirmed_by')->restrictOnDelete();
            // 'receiver' or 'transporter'; empty on refusals recorded before the transporter answered (all the receiver's).
            $table->string('refused_by_party', 20)->nullable()->after('refused_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('pickups', function (Blueprint $table) {
            $table->dropForeign('fk_pickups_transporter_confirmed_by');
            $table->dropIndex('fk_pickups_transporter_confirmed_by');
            $table->dropColumn(['transporter_confirmed_at', 'transporter_confirmed_by_user_id', 'refused_by_party']);
        });
    }
};
