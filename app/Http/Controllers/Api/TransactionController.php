<?php
// app/Http/Controllers/Api/TransactionController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\Account;
use App\Models\Member;
use App\Services\FraudDetectionService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TransactionController extends Controller
{
    protected $fraudService;
    protected $notifService;

    public function __construct(FraudDetectionService $fraudService, NotificationService $notifService)
    {
        $this->fraudService = $fraudService;
        $this->notifService = $notifService;
    }

    public function index(Request $request)
    {
        $member   = $request->user();
        $account  = Account::where('member_id', $member->id)->first();
        if (!$account) {
            return response()->json(['success' => false, 'message' => 'No account found for this member. Please contact support.'], 404);
        }
        $query    = Transaction::where('account_id', $account->id)->latest();

        if ($request->type && in_array($request->type, ['credit','debit'])) {
            $query->where('transaction_type', $request->type);
        }
        if ($request->search) {
            $query->where(function($q) use ($request) {
                $q->where('description', 'like', "%{$request->search}%")
                  ->orWhere('reference_number', 'like', "%{$request->search}%");
            });
        }
        if ($request->status === 'flagged') {
            $query->where('fraud_flag', true);
        }

        $transactions = $query->paginate(20);

        return response()->json([
            'success'      => true,
            'transactions' => $transactions->map(fn($t) => $this->formatTransaction($t)),
            'pagination'   => [
                'total'        => $transactions->total(),
                'current_page' => $transactions->currentPage(),
                'last_page'    => $transactions->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, $id)
    {
        $member  = $request->user();
        $account = Account::where('member_id', $member->id)->first();
        $txn     = Transaction::where('id', $id)->where('account_id', $account->id)->firstOrFail();

        return response()->json(['success' => true, 'transaction' => $this->formatTransaction($txn)]);
    }

    public function transfer(Request $request)
    {
        $request->validate([
            'recipient_account' => 'required|string',
            'amount'            => 'required|numeric|min:1',
            'description'       => 'nullable|string|max:255',
            'pin'               => 'required|digits:4',
        ]);

        $sender  = $request->user();
        $account = Account::where('member_id', $sender->id)->first();

        // Verify PIN
        if (!Hash::check($request->pin, $sender->transaction_pin)) {
            return response()->json(['success' => false, 'message' => 'Incorrect transaction PIN'], 401);
        }

        // Check balance
        if ($account->balance < $request->amount) {
            return response()->json(['success' => false, 'message' => 'Insufficient funds'], 400);
        }

        // Find recipient
        $recipientMember = Member::where('account_number', $request->recipient_account)->first();
        if (!$recipientMember) {
            return response()->json(['success' => false, 'message' => 'Recipient account not found'], 404);
        }

        $recipientAccount = Account::where('member_id', $recipientMember->id)->first();

        // Fraud detection
        $riskScore = $this->fraudService->assessTransaction([
            'member_id'   => $sender->id,
            'amount'      => $request->amount,
            'type'        => 'transfer',
            'recipient'   => $recipientMember->id,
            'account_id'  => $account->id,
        ]);

        // Block high risk
        if ($riskScore >= 90) {
            $this->fraudService->createAlert($sender->id, null, 'High Risk Transfer', $riskScore, $request->amount);
            $this->notifService->sendFraudAlert($sender, $request->amount, $riskScore);
            return response()->json([
                'success'    => false,
                'message'    => 'Transaction blocked due to high fraud risk. Admin has been notified.',
                'risk_score' => $riskScore,
            ], 403);
        }

        // Process transaction
        DB::beginTransaction();
        try {
            $reference = 'TXN-' . strtoupper(Str::random(8));

            // Debit sender
            $account->decrement('balance', $request->amount);
            $debitTxn = Transaction::create([
                'account_id'       => $account->id,
                'member_id'        => $sender->id,
                'transaction_type' => 'debit',
                'amount'           => $request->amount,
                'reference_number' => $reference,
                'description'      => 'Transfer to ' . $recipientMember->full_name . ($request->description ? ' - ' . $request->description : ''),
                'risk_score'       => $riskScore,
                'fraud_flag'       => $riskScore >= 70,
                'status'           => 'completed',
            ]);

            // Credit recipient
            $recipientAccount->increment('balance', $request->amount);
            Transaction::create([
                'account_id'       => $recipientAccount->id,
                'member_id'        => $recipientMember->id,
                'transaction_type' => 'credit',
                'amount'           => $request->amount,
                'reference_number' => $reference,
                'description'      => 'Transfer from ' . $sender->full_name,
                'risk_score'       => 0,
                'fraud_flag'       => false,
                'status'           => 'completed',
            ]);

            DB::commit();

            // Create fraud alert if medium-high risk
            if ($riskScore >= 70) {
                $this->fraudService->createAlert($sender->id, $debitTxn->id, 'High Value Transfer', $riskScore, $request->amount);
            }

            // Notifications
            $this->notifService->sendPush($sender->fcm_token, 'Transfer Successful', "₦" . number_format($request->amount, 2) . " sent to " . $recipientMember->full_name);
            $this->notifService->sendPush($recipientMember->fcm_token, 'Money Received', "₦" . number_format($request->amount, 2) . " received from " . $sender->full_name);

            return response()->json([
                'success'          => true,
                'message'          => 'Transfer successful',
                'reference'        => $reference,
                'risk_score'       => $riskScore,
                'amount'           => $request->amount,
                'recipient_name'   => $recipientMember->full_name,
                'new_balance'      => $account->fresh()->balance,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Transfer failed. Please try again.'], 500);
        }
    }

    public function deposit(Request $request)
    {
        $request->validate(['amount' => 'required|numeric|min:100']);
        $member  = $request->user();
        $account = Account::where('member_id', $member->id)->first();

        DB::beginTransaction();
        try {
            $reference = 'DEP-' . strtoupper(Str::random(8));
            $account->increment('balance', $request->amount);
            $account->increment('savings_balance', $request->amount);

            Transaction::create([
                'account_id'       => $account->id,
                'member_id'        => $member->id,
                'transaction_type' => 'credit',
                'amount'           => $request->amount,
                'reference_number' => $reference,
                'description'      => $request->description ?? 'Savings Contribution',
                'risk_score'       => 0,
                'fraud_flag'       => false,
                'status'           => 'completed',
            ]);

            DB::commit();

            $this->notifService->sendPush($member->fcm_token, 'Deposit Successful', "₦" . number_format($request->amount, 2) . " deposited to your account.");

            return response()->json([
                'success'     => true,
                'message'     => 'Deposit successful',
                'reference'   => $reference,
                'new_balance' => $account->fresh()->balance,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Deposit failed.'], 500);
        }
    }

    private function formatTransaction(Transaction $t)
    {
        return [
            'id'         => 'TXN-' . $t->id,
            'type'       => $t->transaction_type,
            'amount'     => $t->amount,
            'desc'       => $t->description,
            'date'       => $t->created_at->format('Y-m-d'),
            'risk'       => $t->risk_score,
            'status'     => $t->fraud_flag ? 'flagged' : $t->status,
            'reference'  => $t->reference_number,
        ];
    }
}
