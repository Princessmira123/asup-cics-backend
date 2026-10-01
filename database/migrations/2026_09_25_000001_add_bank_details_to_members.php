<?php
// database/migrations/2026_09_25_000001_add_bank_details_to_members.php
//
// Supports real bank-account verification via Paystack's account-resolution
// API. account_number alone was previously just "any 10 digits typed in" —
// these columns let us record which bank it belongs to and the actual
// verified account holder name Paystack returned, so both the member and
// an admin can see it was genuinely checked against real bank records.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('bank_name')->nullable()->after('account_number');
            $table->string('bank_code', 10)->nullable()->after('bank_name');
            // The name Paystack's API returned for this account_number +
            // bank_code combination at the time it was verified — kept for
            // reference (e.g. to show alongside the member's own stated
            // name if they ever differ), not used for login/auth.
            $table->string('verified_account_name')->nullable()->after('bank_code');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['bank_name', 'bank_code', 'verified_account_name']);
        });
    }
};
