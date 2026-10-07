<?php
// app/Http/Controllers/Api/SavingsController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\FraudDetectionService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SavingsController extends Controller
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
        $member  = $request->user();
        $account = Account::where('member_id', $member->id)->first();
        $rate    = \App\Models\Setting::getFloat('savings_interest_rate', 7);
        return response()->json([
            'success'           => true,
            'savings_balance'   => $account->savings_balance ?? 0,
            'interest_rate'     => $rate,
            'monthly_target'    => \App\Models\Setting::getFloat('monthly_deduction', 50000),
            'annual_interest'   => ($account->savings_balance ?? 0) * ($rate / 100),
        ]);
    }

    public function contribute(Request $request)
    {
        $request->validate(['amount' => 'required|numeric|min:100', 'description' => 'nullable|string', 'pin' => 'required|digits:4']);
        $member  = $request->user();

        if (!$member->transaction_pin) {
            return response()->json(['success' => false, 'message' => 'Please set a transaction PIN first, in Settings.'], 400);
        }
        if (!Hash::check($request->pin, $member->transaction_pin)) {
            return response()->json(['success' => false, 'message' => 'Incorrect transaction PIN'], 401);
        }

        $account = Account::where('member_id', $member->id)->first();

        // Fraud check — same convention as the (now-removed) transfer
        // feature used: >=90 blocks outright, 70-89 goes through but gets
        // flagged for admin review. Most of the 6 rules inside
        // assessTransaction() specifically look at debit history, so for a
        // credit like a savings contribution only amount-threshold,
        // velocity and unusual-hour meaningfully apply here — that's
        // intentional, not a gap.
        $riskScore = $this->fraudService->assessTransaction([
            'member_id'  => $member->id,
            'amount'     => $request->amount,
            'type'       => 'savings_contribution',
            'account_id' => $account->id,
        ]);

        if ($riskScore >= 90) {
            $this->fraudService->createAlert($member->id, null, 'High Risk Savings Contribution', $riskScore, $request->amount);
            $this->notifService->sendFraudAlert($member, $request->amount, $riskScore);
            $this->notifService->sendFraudAlertToAdmins($member->full_name, $member->member_id, $request->amount, $riskScore, 'High Risk Savings Contribution');
            return response()->json(['success' => false, 'message' => 'Transaction blocked due to high fraud risk. Admin has been notified.', 'risk_score' => $riskScore], 403);
        }

        $isFlagged = $riskScore >= 70; // <90 already blocked and returned above

        DB::beginTransaction();
        try {
            // Flagged transactions (70-89) are deliberately NOT applied to
            // the balance yet — the money stays held until an admin
            // reviews it. Only normal (<70) contributions credit
            // immediately. status stays 'pending' for a held one; an
            // admin's resolve() call is what later either applies it
            // (status -> 'completed') or leaves it pending/flagged
            // permanently if rejected.
            if (!$isFlagged) {
                $account->increment('balance', $request->amount);
                $account->increment('savings_balance', $request->amount);
            }

            $transaction = Transaction::create([
                'account_id'       => $account->id,
                'member_id'        => $member->id,
                'transaction_type' => 'credit',
                'amount'           => $request->amount,
                'reference_number' => 'SAV-' . strtoupper(Str::random(8)),
                'description'      => $request->description ?? 'Savings Contribution',
                'risk_score'       => $riskScore,
                'fraud_flag'       => $isFlagged,
                'status'           => $isFlagged ? 'pending' : 'completed',
            ]);

            DB::commit();

            if ($isFlagged) {
                $this->fraudService->createAlert($member->id, $transaction->id, 'Flagged Savings Contribution', $riskScore, $request->amount);
                $this->notifService->sendFraudAlertToAdmins($member->full_name, $member->member_id, $request->amount, $riskScore, 'Flagged Savings Contribution');
            }

            return response()->json([
                'success'         => true,
                'message'         => $isFlagged
                    ? 'Your contribution was flagged for review and has not been added to your balance yet. You\'ll be notified once an admin resolves it.'
                    : 'Contribution recorded',
                'flagged'         => $isFlagged,
                'new_savings'     => $account->fresh()->savings_balance,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Savings contribution failed', ['member_id' => $member->id ?? null, 'error' => $e->getMessage(), 'at' => basename($e->getFile()).':'.$e->getLine()]);
            return response()->json(['success' => false, 'message' => 'Contribution failed: ' . $e->getMessage(), 'exception' => get_class($e)], 500);
        }
    }

    public function history(Request $request)
    {
        $account = Account::where('member_id', $request->user()->id)->first();
        $history = Transaction::where('account_id', $account->id)
            ->where('description', 'like', '%Savings%')
            ->where('transaction_type', 'credit')
            ->latest()
            ->get();
        return response()->json(['success' => true, 'history' => $history]);
    }

    public function statement(Request $request)
    {
        $account = Account::where('member_id', $request->user()->id)->first();
        $monthly = Transaction::where('account_id', $account->id)
            ->where('transaction_type', 'credit')
            ->selectRaw('MONTH(created_at) as month, YEAR(created_at) as year, SUM(amount) as total')
            ->groupBy('month', 'year')
            ->orderBy('year')
            ->orderBy('month')
            ->get();
        return response()->json(['success' => true, 'monthly' => $monthly]);
    }
}
