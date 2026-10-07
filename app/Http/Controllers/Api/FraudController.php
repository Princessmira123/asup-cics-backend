<?php
// app/Http/Controllers/Api/FraudController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\FraudAlert;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\Payment;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class FraudController extends Controller
{
    protected $notifService;

    public function __construct(NotificationService $notifService)
    {
        $this->notifService = $notifService;
    }

    public function memberAlerts(Request $request)
    {
        $alerts = FraudAlert::where('member_id', $request->user()->id)->latest()->get();
        return response()->json(['success' => true, 'alerts' => $alerts]);
    }

    public function adminAlerts(Request $request)
    {
        $query  = FraudAlert::with('member', 'transaction')->latest();
        $status = $request->status;
        if ($status) $query->where('status', $status);
        $alerts = $query->paginate(20);
        return response()->json(['success' => true, 'alerts' => $alerts]);
    }

    // decision: 'approve' actually applies the held transaction to the
    // member's balance (crediting savings, deducting a repayment/payment —
    // whichever it was) and marks it completed. 'reject' leaves the
    // transaction exactly as it was — status stays 'pending', fraud_flag
    // stays true — so it keeps showing as "Flagged" to the member
    // permanently, and the money is never applied. Either way the ALERT
    // itself moves to 'resolved' since admin has finished reviewing it,
    // even though the underlying transaction's fate differs completely.
    public function resolve(Request $request, $id)
    {
        $request->validate(['decision' => 'required|in:approve,reject', 'notes' => 'nullable|string']);
        $alert = FraudAlert::with('transaction')->findOrFail($id);
        $transaction = $alert->transaction;

        DB::beginTransaction();
        try {
            if ($request->decision === 'approve' && $transaction && $transaction->status === 'pending') {
                $account = Account::find($transaction->account_id);

                if ($account) {
                    if (str_starts_with($transaction->reference_number, 'SAV-')) {
                        $account->increment('balance', $transaction->amount);
                        $account->increment('savings_balance', $transaction->amount);
                    } elseif (str_starts_with($transaction->reference_number, 'REP-')) {
                        $loanId = trim(str_replace('Loan Repayment - ', '', $transaction->description));
                        $loan = Loan::where('loan_id', $loanId)->first();
                        if ($loan) {
                            $totalPaid = LoanRepayment::where('loan_id', $loan->id)->sum('amount_paid');
                            $newBalance = $loan->amount_approved - ($totalPaid + $transaction->amount);
                            LoanRepayment::create([
                                'loan_id'           => $loan->id,
                                'amount_paid'       => $transaction->amount,
                                'balance_remaining' => max(0, $newBalance),
                                'payment_date'      => now(),
                                'status'            => 'completed',
                            ]);
                            $account->decrement('balance', $transaction->amount);
                            if ($newBalance <= 0) {
                                $loan->update(['status' => 'completed']);
                            }
                        }
                    } elseif (str_starts_with($transaction->reference_number, 'PAY-')) {
                        $account->decrement('balance', $transaction->amount);
                        Payment::create([
                            'member_id'          => $transaction->member_id,
                            'payment_type_label' => $transaction->description,
                            'amount'             => $transaction->amount,
                            'reference_number'   => $transaction->reference_number,
                            'status'             => 'completed',
                        ]);
                    }
                }

                $transaction->update(['status' => 'completed']);
            }

            $alert->update([
                'status'           => 'resolved',
                'resolved_by'      => $request->user()->id,
                'resolution_notes' => $request->notes,
                'resolved_at'      => now(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $request->decision === 'approve'
                    ? 'Transaction approved — applied to the member\'s balance'
                    : 'Transaction rejected — remains flagged, nothing was applied',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Fraud alert resolution failed', ['alert_id' => $id, 'error' => $e->getMessage(), 'at' => basename($e->getFile()).':'.$e->getLine()]);
            return response()->json(['success' => false, 'message' => 'Resolution failed: ' . $e->getMessage()], 500);
        }
    }

    public function escalate(Request $request, $id)
    {
        FraudAlert::findOrFail($id)->update(['status' => 'investigating']);
        return response()->json(['success' => true, 'message' => 'Alert escalated']);
    }

    public function stats()
    {
        return response()->json([
            'success' => true,
            'stats'   => [
                'open'          => FraudAlert::where('status', 'open')->count(),
                'investigating' => FraudAlert::where('status', 'investigating')->count(),
                'resolved'      => FraudAlert::where('status', 'resolved')->count(),
                'total'         => FraudAlert::count(),
                'detection_rate'=> '88%',
                'false_positive'=> '2%',
            ],
        ]);
    }

    // Member-facing — unlike resolve()/escalate() above (admin-only, no PIN
    // needed since an admin acting on a member's alert isn't the member
    // authorizing anything themselves), these let a member make a real
    // claim about their own account ("this was me" / "this wasn't me") —
    // that needs the same PIN confirmation as any other sensitive action,
    // plus a check that the alert actually belongs to them at all.
    public function confirmTransaction(Request $request, $id)
    {
        $request->validate(['pin' => 'required|digits:4']);
        $member = $request->user();
        $alert  = FraudAlert::findOrFail($id);

        if ($alert->member_id !== $member->id) {
            return response()->json(['success' => false, 'message' => 'This alert does not belong to your account'], 403);
        }
        if (!$member->transaction_pin) {
            return response()->json(['success' => false, 'message' => 'Please set a transaction PIN first, in Settings.'], 400);
        }
        if (!Hash::check($request->pin, $member->transaction_pin)) {
            return response()->json(['success' => false, 'message' => 'Incorrect transaction PIN'], 401);
        }

        // Doesn't auto-resolve or touch the held balance — this is the
        // member's claim, not a final decision. An admin still has to
        // actually release the funds via resolve(), same as if the member
        // had said nothing at all. This also means a compromised PIN
        // can't be used to self-approve a fraudulent transaction just by
        // tapping "this was me."
        $alert->update(['member_response' => 'confirmed']);
        $this->notifService->sendFraudAlertToAdmins($member->full_name, $member->member_id, $alert->amount, $alert->risk_score, $alert->alert_type . ' — member confirmed this was them');
        return response()->json(['success' => true, 'message' => 'Thanks — we\'ve let the admin know this was you. They\'ll release the hold shortly.']);
    }

    public function disputeTransaction(Request $request, $id)
    {
        $request->validate(['pin' => 'required|digits:4']);
        $member = $request->user();
        $alert  = FraudAlert::findOrFail($id);

        if ($alert->member_id !== $member->id) {
            return response()->json(['success' => false, 'message' => 'This alert does not belong to your account'], 403);
        }
        if (!$member->transaction_pin) {
            return response()->json(['success' => false, 'message' => 'Please set a transaction PIN first, in Settings.'], 400);
        }
        if (!Hash::check($request->pin, $member->transaction_pin)) {
            return response()->json(['success' => false, 'message' => 'Incorrect transaction PIN'], 401);
        }

        $alert->update(['status' => 'investigating', 'member_response' => 'disputed']);
        $this->notifService->sendFraudAlertToAdmins($member->full_name, $member->member_id, $alert->amount, $alert->risk_score, $alert->alert_type . ' — member says this was NOT them');
        return response()->json(['success' => true, 'message' => 'Disputed. Admin has been notified — this transaction stays on hold.']);
    }
}
