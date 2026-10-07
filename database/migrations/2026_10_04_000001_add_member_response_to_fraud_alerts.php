<?php
// database/migrations/2026_10_04_000001_add_member_response_to_fraud_alerts.php
//
// Tracks whether the member confirmed ("this was me") or disputed ("this
// wasn't me") a flagged transaction — separate from the alert's own
// open/investigating/resolved lifecycle, which is admin-driven. This lets
// admin see what the member said before deciding whether to release the
// held funds or keep the transaction flagged permanently.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fraud_alerts', function (Blueprint $table) {
            $table->enum('member_response', ['confirmed', 'disputed'])->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('fraud_alerts', function (Blueprint $table) {
            $table->dropColumn('member_response');
        });
    }
};
