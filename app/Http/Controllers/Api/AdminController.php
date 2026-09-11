<?php
// app/Http/Controllers/Api/AdminController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\FraudAlert;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    protected $notifService;

    public function __construct(NotificationService $notifService)
    {
        $this->notifService = $notifService;
    }

    // ── DASHBOARD ─────────────────────────────────────────────────────────────
    public function dashboard()
    {
        return response()->json([
            'success' => true,
            'stats'   => [
                'total_members'        => Member::where('status', 'active')->count(),
                'pending_loans'        => Loan::where('status', 'pending')->count(),
                'active_loans'         => Loan::where('status', 'active')->count(),
                'total_disbursed'      => Loan::where('status', '!=', 'denied')->sum('amount_approved'),
                'total_savings'        => Account::sum('savings_balance'),
                'open_fraud_alerts'    => FraudAlert::where('status', 'open')->count(),
                'transactions_today'   => Transaction::whereDate('created_at', today())->count(),
            ],
        ]);
    }

    // ── MEMBERS ───────────────────────────────────────────────────────────────
    public function members(Request $request)
    {
        $query = Member::with('account')->latest();

        if ($request->search) {
            $query->where(function($q) use ($request) {
                $q->where('full_name', 'like', "%{$request->search}%")
                  ->orWhere('staff_id', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%");
            });
        }
        if ($request->status) {
            $query->where('status', $request->status);
        }

        $members = $query->paginate(20);

        return response()->json([
            'success' => true,
            'members' => $members->map(fn($m) => [
                'id'             => $m->member_id,
                'name'           => $m->full_name,
                'staff_id'       => $m->staff_id,
                'email'          => $m->email,
                'phone'          => $m->phone_number,
                'account_number' => $m->account_number,
                'savings'        => $m->account->savings_balance ?? 0,
                'balance'        => $m->account->balance ?? 0,
                'status'         => $m->status,
                'join_date'      => $m->created_at->format('M Y'),
            ]),
            'total' => $members->total(),
        ]);
    }

    public function memberDetail($id)
    {
        $member = Member::where('member_id', $id)->with(['account', 'loans'])->firstOrFail();
        return response()->json(['success' => true, 'member' => $member]);
    }

    public function updateMemberStatus(Request $request, $id)
    {
        $request->validate(['status' => 'required|in:active,suspended,inactive']);
        Member::where('member_id', $id)->update(['status' => $request->status]);
        return response()->json(['success' => true, 'message' => 'Member status updated']);
    }

    public function sendMessage(Request $request, $id)
    {
        $request->validate(['message' => 'required|string']);
        $member = Member::where('member_id', $id)->firstOrFail();

        \App\Models\Message::create([
            'member_id'   => $member->id,
            'sender_type' => 'admin',
            'admin_id'    => $request->user()->id,
            'subject'     => 'Message from Admin',
            'body'        => $request->message,
            'is_read'     => false,
        ]);

        $this->notifService->sendPush($member->fcm_token, 'Message from Admin', $request->message);
        $this->notifService->sendSms($member->phone_number, $request->message);
        return response()->json(['success' => true, 'message' => 'Message sent']);
    }

    // ── HOUSEHOLD REQUESTS ───────────────────────────────────────────────────
    public function householdRequests(Request $request)
    {
        $query = \App\Models\HouseholdRequest::with('member')->latest();
        if ($request->status) $query->where('status', $request->status);
        return response()->json(['success' => true, 'requests' => $query->get()]);
    }

    public function updateHouseholdRequest(Request $request, $id)
    {
        $request->validate(['status' => 'required|in:approved,rejected', 'admin_notes' => 'nullable|string']);
        $hr = \App\Models\HouseholdRequest::findOrFail($id);
        $hr->update(['status' => $request->status, 'admin_notes' => $request->admin_notes]);
        return response()->json(['success' => true, 'message' => 'Household request updated']);
    }

    // ── PAYMENT TYPES ─────────────────────────────────────────────────────────
    public function paymentTypes()
    {
        return response()->json(['success' => true, 'types' => \App\Models\PaymentType::latest()->get()]);
    }

    public function createPaymentType(Request $request)
    {
        $request->validate(['name' => 'required|string|max:100', 'default_amount' => 'nullable|numeric|min:0']);
        $type = \App\Models\PaymentType::create([
            'name'            => $request->name,
            'default_amount'  => $request->default_amount,
            'active'          => true,
        ]);
        return response()->json(['success' => true, 'type' => $type]);
    }

    public function togglePaymentType(Request $request, $id)
    {
        $type = \App\Models\PaymentType::findOrFail($id);
        $type->update(['active' => !$type->active]);
        return response()->json(['success' => true, 'type' => $type]);
    }

    // ── LOANS ─────────────────────────────────────────────────────────────────
    public function loans(Request $request)
    {
        $query = Loan::with(['member', 'guarantors.member'])->latest();

        if ($request->status) {
            $query->where('status', $request->status);
        }

        $loans = $query->paginate(20);

        return response()->json([
            'success' => true,
            'loans'   => $loans->map(fn($l) => $this->formatAdminLoan($l)),
            'total'   => $loans->total(),
        ]);
    }

    public function pendingLoans()
    {
        $loans = Loan::where('status', 'pending')
                     ->with(['member', 'guarantors.member'])
                     ->latest()
                     ->get();

        return response()->json([
            'success' => true,
            'loans'   => $loans->map(fn($l) => $this->formatAdminLoan($l)),
        ]);
    }

    public function loanDetail($id)
    {
        $loan = Loan::where('loan_id', $id)->with(['member', 'guarantors.member', 'repayments'])->firstOrFail();
        return response()->json(['success' => true, 'loan' => $this->formatAdminLoan($loan, true)]);
    }

    public function approveLoan(Request $request, $id)
    {
        $request->validate(['amount_approved' => 'required|numeric|min:1']);

        $loan = Loan::where('loan_id', $id)->firstOrFail();

        if ($loan->status !== 'pending' && $loan->status !== 'under_review') {
            return response()->json(['success' => false, 'message' => 'Loan is not in a reviewable state'], 400);
        }

        DB::beginTransaction();
        try {
            $loan->update([
                'status'            => 'approved',
                'amount_approved'   => $request->amount_approved,
                'approved_by'       => $request->user()->id,
                'approval_date'     => now(),
            ]);

            // Disburse to member account
            $account = Account::where('member_id', $loan->member_id)->first();
            $account->increment('balance', $request->amount_approved);
            $account->increment('loan_balance', $request->amount_approved);

            // Update loan to active
            $loan->update([
                'status'            => 'active',
                'disbursement_date' => now(),
                'next_payment_date' => now()->addMonth(),
            ]);

            // Create disbursement transaction
            Transaction::create([
                'account_id'       => $account->id,
                'member_id'        => $loan->member_id,
                'transaction_type' => 'credit',
                'amount'           => $request->amount_approved,
                'reference_number' => 'DISB-' . $loan->loan_id,
                'description'      => 'Loan Disbursement - ' . $loan->loan_id,
                'risk_score'       => 0,
                'fraud_flag'       => false,
                'status'           => 'completed',
            ]);

            DB::commit();

            // Notify member
            $this->notifService->sendPush(
                $loan->member->fcm_token,
                'Loan Approved! 🎉',
                "Your loan of ₦" . number_format($request->amount_approved, 2) . " has been approved and credited to your account."
            );
            $this->notifService->sendSms(
                $loan->member->phone_number,
                "ASUP CICS: Your loan of ₦" . number_format($request->amount_approved, 2) . " (Ref: {$loan->loan_id}) has been approved and disbursed."
            );

            return response()->json(['success' => true, 'message' => 'Loan approved and disbursed successfully']);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Approval failed'], 500);
        }
    }

    public function denyLoan(Request $request, $id)
    {
        $request->validate(['reason' => 'nullable|string']);
        $loan = Loan::where('loan_id', $id)->firstOrFail();
        $loan->update(['status' => 'denied', 'denial_reason' => $request->reason, 'denied_by' => $request->user()->id]);

        $this->notifService->sendPush(
            $loan->member->fcm_token,
            'Loan Application Update',
            "Your loan application {$loan->loan_id} was not approved." . ($request->reason ? " Reason: {$request->reason}" : '')
        );

        return response()->json(['success' => true, 'message' => 'Loan denied']);
    }

    // ── TRANSACTIONS ──────────────────────────────────────────────────────────
    public function transactions(Request $request)
    {
        $query = Transaction::with('member')->latest();
        if ($request->search) {
            $query->where('reference_number', 'like', "%{$request->search}%")
                  ->orWhere('description', 'like', "%{$request->search}%");
        }
        $transactions = $query->paginate(30);
        return response()->json(['success' => true, 'transactions' => $transactions]);
    }

    public function transactionDetail($id)
    {
        $txn = Transaction::with('member')->findOrFail($id);
        return response()->json(['success' => true, 'transaction' => $txn]);
    }

    public function reverseTransaction(Request $request, $id)
    {
        $txn = Transaction::findOrFail($id);

        if ($txn->status === 'reversed') {
            return response()->json(['success' => false, 'message' => 'This transaction has already been reversed'], 400);
        }

        // A transfer writes two rows (debit + credit) sharing the same
        // reference_number. Reverse both legs together so the books stay
        // balanced; a single-leg transaction (savings, payment, deposit)
        // just reverses itself.
        $legs = Transaction::where('reference_number', $txn->reference_number)->get();

        // If reversing would leave any affected account negative, refuse —
        // this can happen if the credited party already spent the money.
        foreach ($legs as $leg) {
            if ($leg->transaction_type === 'credit') {
                $acct = Account::find($leg->account_id);
                if ($acct && $acct->balance < $leg->amount) {
                    return response()->json([
                        'success' => false,
                        'message' => "Cannot reverse: {$acct->member->full_name} no longer has sufficient balance to be debited back.",
                    ], 422);
                }
            }
        }

        DB::beginTransaction();
        try {
            foreach ($legs as $leg) {
                $account = Account::find($leg->account_id);

                if ($leg->transaction_type === 'debit') {
                    // Original debit removed money — give it back.
                    $account->increment('balance', $leg->amount);
                    if (str_contains($leg->description ?? '', 'Savings')) {
                        $account->increment('savings_balance', $leg->amount);
                    }
                } else {
                    // Original credit added money — take it back.
                    $account->decrement('balance', $leg->amount);
                }

                Transaction::create([
                    'account_id'       => $leg->account_id,
                    'member_id'        => $leg->member_id,
                    'transaction_type' => $leg->transaction_type === 'debit' ? 'credit' : 'debit',
                    'amount'           => $leg->amount,
                    'reference_number' => 'REV-' . $leg->reference_number,
                    'description'      => 'Reversal of ' . $leg->reference_number . ' — ' . $leg->description,
                    'risk_score'       => 0,
                    'fraud_flag'       => false,
                    'status'           => 'completed',
                ]);

                $leg->update(['status' => 'reversed']);
            }

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Transaction reversed and balances corrected.']);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Transaction reversal failed', ['error' => $e->getMessage(), 'txn_id' => $id]);
            return response()->json(['success' => false, 'message' => 'Reversal failed. No changes were made.'], 500);
        }
    }

    // ── REPORTS ───────────────────────────────────────────────────────────────
    public function financialReport(Request $request)
    {
        $from = $request->from ?? now()->startOfMonth();
        $to   = $request->to   ?? now()->endOfMonth();

        return response()->json([
            'success' => true,
            'report'  => [
                'period'          => ['from' => $from, 'to' => $to],
                'total_deposits'  => Transaction::where('transaction_type', 'credit')->whereBetween('created_at', [$from, $to])->sum('amount'),
                'total_withdrawn' => Transaction::where('transaction_type', 'debit')->whereBetween('created_at', [$from, $to])->sum('amount'),
                'loans_disbursed' => Loan::where('status', 'active')->whereBetween('disbursement_date', [$from, $to])->sum('amount_approved'),
                'loans_repaid'    => Transaction::where('description', 'like', 'Loan Repayment%')->whereBetween('created_at', [$from, $to])->sum('amount'),
                'total_savings'   => Account::sum('savings_balance'),
                'total_members'   => Member::where('status', 'active')->count(),
            ],
        ]);
    }

    public function memberReport()
    {
        return response()->json(['success' => true, 'data' => Member::with('account')->get()]);
    }

    public function loanReport()
    {
        return response()->json(['success' => true, 'data' => Loan::with('member')->get()]);
    }

    public function dividendReport()
    {
        $totalSavings  = Account::sum('savings_balance');
        $dividendRate  = \App\Models\Setting::getFloat('dividend_rate', 7) / 100;
        $totalDividend = $totalSavings * $dividendRate;

        return response()->json([
            'success'        => true,
            'total_savings'  => $totalSavings,
            'dividend_rate'  => $dividendRate * 100 . '%',
            'total_dividend' => $totalDividend,
            'members'        => Member::with('account')->get()->map(fn($m) => [
                'name'      => $m->full_name,
                'savings'   => $m->account->savings_balance ?? 0,
                'dividend'  => ($m->account->savings_balance ?? 0) * $dividendRate,
            ]),
        ]);
    }

    public function settings()
    {
        $rows = \App\Models\Setting::all(['key', 'value', 'label', 'type']);
        return response()->json(['success' => true, 'settings' => $rows]);
    }

    public function updateSettings(Request $request)
    {
        $request->validate(['settings' => 'required|array']);
        foreach ($request->settings as $key => $value) {
            // Only allow updating keys that already exist — prevents
            // arbitrary key injection from the request payload.
            if (\App\Models\Setting::where('key', $key)->exists()) {
                \App\Models\Setting::set($key, $value);
            }
        }
        return response()->json(['success' => true, 'message' => 'Settings updated', 'settings' => \App\Models\Setting::all_settings()]);
    }

    private function formatAdminLoan(Loan $loan, $detailed = false)
    {
        return [
            'id'               => $loan->loan_id,
            'memberId'         => $loan->member->member_id ?? '',
            'memberName'       => $loan->member->full_name ?? '',
            'staffId'          => $loan->member->staff_id ?? '',
            'phone'            => $loan->member->phone_number ?? '',
            'address'          => $loan->member->address ?? '',
            'monthlySaving'    => $loan->member->account->savings_balance ?? 0,
            'dateJoined'       => $loan->member->created_at ?? '',
            'amountRequested'  => $loan->amount_requested,
            'amountQualified'  => ($loan->member->account->savings_balance ?? 0) * \App\Models\Setting::getFloat('max_loan_multiplier', 2),
            'amountApproved'   => $loan->amount_approved,
            'purpose'          => $loan->purpose,
            'tenure'           => $loan->tenure_months,
            'deductionStart'   => $loan->commence_month . '-' . $loan->commence_year,
            'loanStatus'       => $loan->status,
            'dateRequested'    => $loan->application_date,
            'surety1'          => $loan->guarantors[0]->member->full_name ?? '',
            'surety2'          => $loan->guarantors[1]->member->full_name ?? '',
            'acceptedDate1'    => $loan->guarantors[0]->consent_date ?? '',
            'acceptedDate2'    => $loan->guarantors[1]->consent_date ?? '',
        ];
    }
}
