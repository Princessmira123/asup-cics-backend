<?php
// database/migrations/2024_01_02_000003_add_messages_household_payments.php
//
// Backs three previously-unimplemented screens on the Flutter side:
// Messages, Household Operation, and Update Other Payments. No demo rows
// are seeded — these tables start empty and only fill with real member
// activity.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── MESSAGES (member <-> admin) ─────────────────────────────────────
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            // The member this thread belongs to (either the sender, if a
            // member wrote it, or the recipient, if an admin wrote it).
            $table->foreignId('member_id')->constrained()->onDelete('cascade');
            $table->enum('sender_type', ['member', 'admin']);
            $table->foreignId('admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('subject')->nullable();
            $table->text('body');
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });

        // ── HOUSEHOLD REQUESTS ────────────────────────────────────────────────
        Schema::create('household_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->onDelete('cascade');
            $table->string('operation_type');
            $table->decimal('amount_requested', 15, 2);
            $table->text('description')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('admin_notes')->nullable();
            $table->timestamps();
        });

        // ── PAYMENT TYPES (admin-configured charges) ────────────────────────
        Schema::create('payment_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('default_amount', 15, 2)->nullable(); // null = member enters amount
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // ── PAYMENTS (member payment history) ────────────────────────────────
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->onDelete('cascade');
            $table->foreignId('payment_type_id')->nullable()->constrained('payment_types')->nullOnDelete();
            $table->string('payment_type_label'); // snapshot of the type name at time of payment
            $table->decimal('amount', 15, 2);
            $table->string('reference_number')->unique();
            $table->enum('status', ['completed', 'failed'])->default('completed');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('payment_types');
        Schema::dropIfExists('household_requests');
        Schema::dropIfExists('messages');
    }
};
