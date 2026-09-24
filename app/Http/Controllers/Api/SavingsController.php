<?php
// app/Http/Controllers/Api/SavingsController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SavingsController extends Controller
{
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

        DB::beginTransaction();
        try {
            $account->increment('balance', $request->amount);
            $account->increment('savings_balance', $request->amount);

            Transaction::create([
                'account_id'       => $account->id,
                'member_id'        => $member->id,
                'transaction_type' => 'credit',
                'amount'           => $request->amount,
                'reference_number' => 'SAV-' . strtoupper(Str::random(8)),
                'description'      => $request->description ?? 'Savings Contribution',
                'risk_score'       => 0,
                'fraud_flag'       => false,
                'status'           => 'completed',
            ]);

            DB::commit();
            return response()->json([
                'success'         => true,
                'message'         => 'Contribution recorded',
                'new_savings'     => $account->fresh()->savings_balance,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Contribution failed'], 500);
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
