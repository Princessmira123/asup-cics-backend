<?php
// routes/api.php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\LoanController;
use App\Http\Controllers\Api\GuarantorController;
use App\Http\Controllers\Api\SavingsController;
use App\Http\Controllers\Api\FraudController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\HouseholdController;
use App\Http\Controllers\Api\PaymentController;

// ── PUBLIC ROUTES ─────────────────────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('/register',        [AuthController::class, 'register']);
    Route::get('/available-staff',  [AuthController::class, 'availableStaff']);
    Route::post('/login',           [AuthController::class, 'login']);
    Route::post('/admin-login',     [AuthController::class, 'adminLogin']);
    Route::post('/verify-otp',      [AuthController::class, 'verifyOtp']);
    Route::post('/resend-otp',      [AuthController::class, 'resendOtp']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password',  [AuthController::class, 'resetPassword']);
    Route::post('/refresh-token',   [AuthController::class, 'refreshToken']);
    Route::post('/biometric-login', [AuthController::class, 'biometricLogin']);
});

// ── PROTECTED MEMBER ROUTES ───────────────────────────────────────────────────
Route::middleware(['auth:sanctum', 'member.active'])->group(function () {

    // Auth
    Route::post('/auth/logout',           [AuthController::class, 'logout']);
    Route::post('/auth/verify-pin',       [AuthController::class, 'verifyPin']);
    Route::post('/auth/change-password',  [AuthController::class, 'changePassword']);
    Route::post('/auth/change-pin',       [AuthController::class, 'changePin']);
    Route::post('/auth/set-pin',          [AuthController::class, 'setPin']);
    Route::post('/auth/biometric-enroll',  [AuthController::class, 'enrollBiometric']);
    Route::get('/auth/biometric-status',   [AuthController::class, 'biometricStatus']);
    Route::post('/auth/biometric-disable', [AuthController::class, 'disableBiometric']);

    // Member / Profile
    Route::get('/member/profile',         [MemberController::class, 'profile']);
    Route::put('/member/profile',         [MemberController::class, 'updateProfile']);
    Route::get('/member/dashboard',       [MemberController::class, 'dashboard']);
    Route::get('/member/search',          [MemberController::class, 'search']);

    // Account
    Route::get('/account',                [AccountController::class, 'index']);
    Route::get('/account/balance',        [AccountController::class, 'balance']);
    Route::get('/account/statement',      [AccountController::class, 'statement']);
    Route::get('/account/statement/pdf',  [AccountController::class, 'statementPdf']);

    // Transactions
    Route::get('/transactions',           [TransactionController::class, 'index']);
    Route::get('/transactions/{id}',      [TransactionController::class, 'show']);
    // NOTE: member-to-member transfer intentionally removed — feature disabled
    // per request. TransactionController::transfer() is left in place but is
    // no longer reachable from the API.
    //
    // NOTE: TransactionController::deposit() also disabled — it duplicated
    // SavingsController::contribute() (same effect: credits balance +
    // savings_balance) but had NO PIN check and no route-level protection.
    // Nothing in the app ever called it, but it was still live: any
    // authenticated member could have called it directly to credit their
    // own account for free, with no PIN and no limit. contribute() is the
    // one properly-secured path for this now — this one is redundant.

    // Savings
    Route::get('/savings',                [SavingsController::class, 'index']);
    Route::post('/savings/contribute',    [SavingsController::class, 'contribute']);
    Route::get('/savings/history',        [SavingsController::class, 'history']);
    Route::get('/savings/statement',      [SavingsController::class, 'statement']);

    // Loans
    Route::get('/loans',                  [LoanController::class, 'index']);
    Route::post('/loans/apply',           [LoanController::class, 'apply']);
    Route::get('/loans/eligibility',      [LoanController::class, 'eligibility']);
    Route::get('/loans/{id}',             [LoanController::class, 'show']);
    Route::get('/loans/{id}/status',      [LoanController::class, 'status']);
    Route::get('/loans/{id}/repayments',  [LoanController::class, 'repayments']);
    Route::post('/loans/{id}/repay',      [LoanController::class, 'repay']);

    // Guarantors / Surety
    Route::get('/guarantors/eligible',          [GuarantorController::class, 'eligibleMembers']);
    Route::post('/guarantors/consent/{token}',  [GuarantorController::class, 'giveConsent']);
    Route::post('/guarantors/decline/{token}',  [GuarantorController::class, 'declineConsent']);
    Route::get('/guarantors/pending',           [GuarantorController::class, 'pendingRequests']);
    Route::get('/guarantors/mine',              [GuarantorController::class, 'mySureties']);

    // Messages (member <-> admin)
    Route::get('/messages/inbox',              [MessageController::class, 'inbox']);
    Route::get('/messages/sent',               [MessageController::class, 'sent']);
    Route::post('/messages',                   [MessageController::class, 'send']);
    Route::put('/messages/{id}/read',          [MessageController::class, 'markRead']);

    // Household operations
    Route::get('/household',                   [HouseholdController::class, 'index']);
    Route::post('/household/request',          [HouseholdController::class, 'request']);

    // Other payments
    Route::get('/payments/types',              [PaymentController::class, 'types']);
    Route::get('/payments/history',            [PaymentController::class, 'history']);
    Route::post('/payments',                   [PaymentController::class, 'pay']);

    // Notifications
    Route::get('/notifications',               [NotificationController::class, 'index']);
    Route::put('/notifications/{id}/read',     [NotificationController::class, 'markRead']);
    Route::put('/notifications/read-all',      [NotificationController::class, 'markAllRead']);
    Route::post('/notifications/fcm-token',    [NotificationController::class, 'updateFcmToken']);

    // Fraud alerts (member view)
    Route::get('/fraud/alerts',                [FraudController::class, 'memberAlerts']);
    Route::post('/fraud/alerts/{id}/confirm',  [FraudController::class, 'confirmTransaction']);
    Route::post('/fraud/alerts/{id}/dispute',  [FraudController::class, 'disputeTransaction']);
});

// ── ADMIN PROTECTED ROUTES ────────────────────────────────────────────────────
Route::middleware(['auth:sanctum', 'admin.auth'])->prefix('admin')->group(function () {

    // Dashboard
    Route::get('/dashboard',              [AdminController::class, 'dashboard']);

    // Members
    Route::get('/members',                [AdminController::class, 'members']);
    Route::get('/members/{id}',           [AdminController::class, 'memberDetail']);
    Route::put('/members/{id}/status',    [AdminController::class, 'updateMemberStatus']);
    Route::put('/members/{id}/verify',    [AdminController::class, 'verifyMember']);
    Route::post('/members/{id}/message',  [AdminController::class, 'sendMessage']);

    // Loans
    Route::get('/loans',                  [AdminController::class, 'loans']);
    Route::get('/loans/pending',          [AdminController::class, 'pendingLoans']);
    Route::get('/loans/{id}',             [AdminController::class, 'loanDetail']);
    Route::post('/loans/{id}/approve',    [AdminController::class, 'approveLoan']);
    Route::post('/loans/{id}/deny',       [AdminController::class, 'denyLoan']);

    // Transactions
    Route::get('/transactions',           [AdminController::class, 'transactions']);
    Route::get('/transactions/{id}',      [AdminController::class, 'transactionDetail']);
    Route::post('/transactions/{id}/reverse', [AdminController::class, 'reverseTransaction']);

    // Fraud
    Route::get('/fraud/alerts',           [FraudController::class, 'adminAlerts']);
    Route::put('/fraud/alerts/{id}/resolve',   [FraudController::class, 'resolve']);
    Route::put('/fraud/alerts/{id}/escalate',  [FraudController::class, 'escalate']);
    Route::get('/fraud/stats',            [FraudController::class, 'stats']);

    // Reports
    Route::get('/reports/financial',      [AdminController::class, 'financialReport']);
    Route::get('/reports/members',        [AdminController::class, 'memberReport']);
    Route::get('/reports/loans',          [AdminController::class, 'loanReport']);
    Route::get('/reports/dividends',      [AdminController::class, 'dividendReport']);

    // Settings
    Route::get('/settings',               [AdminController::class, 'settings']);
    Route::put('/settings',               [AdminController::class, 'updateSettings']);

    // Household requests (admin review)
    Route::get('/household-requests',              [AdminController::class, 'householdRequests']);
    Route::put('/household-requests/{id}/status',  [AdminController::class, 'updateHouseholdRequest']);

    // Payment types (admin-configured charges)
    Route::get('/payment-types',              [AdminController::class, 'paymentTypes']);
    Route::post('/payment-types',             [AdminController::class, 'createPaymentType']);
    Route::put('/payment-types/{id}/toggle',  [AdminController::class, 'togglePaymentType']);
});
