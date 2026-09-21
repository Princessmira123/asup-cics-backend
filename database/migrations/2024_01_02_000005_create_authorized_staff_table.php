<?php
// database/migrations/2024_01_02_000005_create_authorized_staff_table.php
//
// This is a WHITELIST, not member data. A row here means "this person is a
// real Federal Polytechnic Ede staff member authorized to join the
// cooperative" — it does NOT mean they are a member yet. A row only ever
// gets is_registered = true once that exact person completes real
// registration through the app themselves. No fake/demo members are ever
// created from this table directly.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authorized_staff', function (Blueprint $table) {
            $table->id();
            $table->string('staff_id')->unique();
            $table->string('full_name');
            $table->boolean('is_registered')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authorized_staff');
    }
};
