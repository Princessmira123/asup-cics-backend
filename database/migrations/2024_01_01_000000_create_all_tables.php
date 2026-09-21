<?php
// database/migrations/2024_01_01_000001_create_all_tables.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── MEMBERS ───────────────────────────────────────────────────────────
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->string('member_id', 20)->unique();
            $table->string('full_name', 100);
            $table->string('staff_id', 20)->unique();
            $table->string('email', 100)->unique();
            $table->string('phone_number', 15);
            $table->string('password_hash');
            $table->string('transaction_pin')->nullable();
            $table->text('biometric_token')->nullable();
            $table->string('account_number', 10)->unique();
            $table->string('nin', 11)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->text('address')->nullable();
            $table->string('department')->nullable();
            $table->string('fcm_token')->nullable();
            $table->string('status')->default('pending_verification');
            $table->timestamp('last_login')->nullable();
            $table->timestamps();
        });

        // ── ADMIN USERS ───────────────────────────────────────────────────────
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('admin_id', 20)->unique();
            $table->string('full_name', 100);
            $table->string('email', 100)->unique();
            $table->string('password_hash');
            $table->string('role')->default('staff');
            $table->timestamp('last_login')->nullable();
            $table->timestamps();
        });

        // ── ACCOUNTS ──────────────────────────────────────────────────────────
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->onDelete('cascade');
            $table->string('account_type')->default('savings');
            $table->decimal('balance', 15, 2)->default(0);
            $table->decimal('savings_balance', 15, 2)->default(0);
            $table->decimal('loan_balance', 15, 2)->default(0);
            $table->decimal('shares_balance', 15, 2)->default(0);
            $table->decimal('interest_rate', 5, 2)->default(7.00);
            $table->date('date_opened')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        // ── TRANSACTIONS ──────────────────────────────────────────────────────
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->onDelete('cascade');
            $table->foreignId('member_id')->constrained()->onDelete('cascade');
            $table->enum('transaction_type', ['credit', 'debit', 'transfer']);
            $table->decimal('amount', 15, 2);
            $table->string('reference_number', 30)->unique();
            $table->string('description', 255)->nullable();
            $table->tinyInteger('risk_score')->default(0);
            $table->boolean('fraud_flag')->default(false);
            $table->string('device_id')->nullable();
            $table->enum('status', ['pending', 'completed', 'blocked', 'reversed'])->default('pending');
            $table->timestamps();
        });

        // ── LOANS ─────────────────────────────────────────────────────────────
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->string('loan_id', 20)->unique();
            $table->foreignId('member_id')->constrained()->onDelete('cascade');
            $table->decimal('amount_requested', 15, 2);
            $table->decimal('amount_approved', 15, 2)->nullable();
            $table->decimal('interest_rate', 5, 2)->default(10.00);
            $table->integer('tenure_months');
            $table->string('purpose', 255);
            $table->text('description')->nullable();
            $table->string('commence_month')->nullable();
            $table->integer('commence_year')->nullable();
            $table->enum('status', ['pending', 'under_review', 'approved', 'active', 'completed', 'denied'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('admin_users');
            $table->foreignId('denied_by')->nullable()->constrained('admin_users');
            $table->text('denial_reason')->nullable();
            $table->timestamp('application_date')->nullable();
            $table->timestamp('approval_date')->nullable();
            $table->timestamp('disbursement_date')->nullable();
            $table->timestamp('next_payment_date')->nullable();
            $table->timestamps();
        });

        // ── LOAN GUARANTORS ───────────────────────────────────────────────────
        Schema::create('loan_guarantors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->onDelete('cascade');
            $table->foreignId('member_id')->constrained()->onDelete('cascade');
            $table->string('consent_token', 40)->unique();
            $table->enum('status', ['pending', 'accepted', 'declined'])->default('pending');
            $table->timestamp('consent_date')->nullable();
            $table->timestamps();
        });

        // ── LOAN REPAYMENTS ───────────────────────────────────────────────────
        Schema::create('loan_repayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->onDelete('cascade');
            $table->decimal('amount_paid', 15, 2);
            $table->decimal('balance_remaining', 15, 2);
            $table->timestamp('payment_date');
            $table->string('status')->default('completed');
            $table->timestamps();
        });

        // ── FRAUD ALERTS ──────────────────────────────────────────────────────
        Schema::create('fraud_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->onDelete('cascade');
            $table->foreignId('transaction_id')->nullable()->constrained()->onDelete('set null');
            $table->string('alert_type', 50);
            $table->tinyInteger('risk_score');
            $table->decimal('amount', 15, 2)->default(0);
            $table->text('description')->nullable();
            $table->enum('status', ['open', 'investigating', 'resolved', 'false_positive'])->default('open');
            $table->foreignId('resolved_by')->nullable()->constrained('admin_users');
            $table->text('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        // ── OTP CODES ─────────────────────────────────────────────────────────
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->onDelete('cascade');
            $table->string('code', 6);
            $table->string('type', 30);
            $table->boolean('used')->default(false);
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        // ── NOTIFICATIONS ─────────────────────────────────────────────────────
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->onDelete('cascade');
            $table->string('type', 50);
            $table->string('title', 100);
            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->string('delivery_channel')->default('push');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        // ── AUDIT LOGS ────────────────────────────────────────────────────────
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->unsignedBigInteger('member_id')->nullable();
            $table->string('action', 100);
            $table->string('resource', 100);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('otp_codes');
        Schema::dropIfExists('fraud_alerts');
        Schema::dropIfExists('loan_repayments');
        Schema::dropIfExists('loan_guarantors');
        Schema::dropIfExists('loans');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('admin_users');
        Schema::dropIfExists('members');
    }
};
