<?php
// database/migrations/2024_01_02_000002_add_biometric_columns_to_members.php
//
// Adds real device-bound biometric login support.
// Each member can enroll ONE device at a time per biometric slot below
// (we keep it simple: one active biometric device per member, matching
// how most banking apps behave — enrolling a new device replaces the old one).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            // Hash of the random secret generated on-device and stored in the
            // phone's secure hardware keystore/keychain. We NEVER store the
            // raw secret — only its hash, like a password.
            $table->string('biometric_secret_hash')->nullable()->after('biometric_token');

            // Identifies which device is enrolled (a random UUID generated once
            // by the app and persisted locally — not a hardware serial).
            $table->string('biometric_device_id')->nullable()->after('biometric_secret_hash');

            // Human-readable label shown to the member in Settings, e.g. "iPhone 14".
            $table->string('biometric_device_name')->nullable()->after('biometric_device_id');

            $table->timestamp('biometric_enrolled_at')->nullable()->after('biometric_device_name');
        });

        // The old free-text biometric_token column is no longer used by the
        // new flow but is left in place (nullable) to avoid a breaking
        // migration for any existing rows.
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn([
                'biometric_secret_hash',
                'biometric_device_id',
                'biometric_device_name',
                'biometric_enrolled_at',
            ]);
        });
    }
};
