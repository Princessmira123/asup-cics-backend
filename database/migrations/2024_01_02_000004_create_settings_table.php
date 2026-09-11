<?php
// database/migrations/2024_01_02_000004_create_settings_table.php
//
// Backs real admin-configurable settings (interest rate, loan multiplier,
// monthly deduction, etc.) instead of values hardcoded in controllers.
// The rows seeded here are configuration DEFAULTS (the values the
// cooperative would set on day one) — not demo/sample transactional data.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('value');
            $table->string('label')->nullable();   // human-readable name for the admin UI
            $table->string('type')->default('number'); // number|string|boolean — for UI rendering
            $table->timestamps();
        });

        DB::table('settings')->insert([
            ['key' => 'interest_rate',        'value' => '10',     'label' => 'Loan Interest Rate (% p.a.)',      'type' => 'number', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'max_loan_multiplier',  'value' => '2',      'label' => 'Max Loan Multiplier (x savings)',  'type' => 'number', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'min_guarantors',       'value' => '1',      'label' => 'Minimum Guarantors Required',      'type' => 'number', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'high_value_threshold', 'value' => '300000', 'label' => 'High-Value Loan Threshold (₦)',    'type' => 'number', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'dividend_rate',        'value' => '7',      'label' => 'Annual Dividend Rate (%)',         'type' => 'number', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'monthly_deduction',    'value' => '25000',  'label' => 'Default Monthly Deduction (₦)',    'type' => 'number', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'savings_interest_rate','value' => '7',      'label' => 'Savings Interest Rate (%)',        'type' => 'number', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
