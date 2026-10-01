<?php
// app/Http/Controllers/Api/MemberController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\Loan;
use App\Models\FraudAlert;
use Illuminate\Http\Request;

class MemberController extends Controller
{
    public function profile(Request $request)
    {
        $member  = $request->user();
        $account = Account::where('member_id', $member->id)->first();
        return response()->json([
            'success' => true,
            'member'  => [
                'id'              => $member->member_id,
                'name'            => $member->full_name,
                'staff_id'        => $member->staff_id,
                'email'           => $member->email,
                'phone'           => $member->phone_number,
                'account_number'  => $member->account_number,
                'bank_name'       => $member->bank_name,
                'bank_code'       => $member->bank_code,
                'verified_account_name' => $member->verified_account_name,
                'address'         => $member->address,
                'department'      => $member->department,
                'join_date'       => $member->created_at->format('F Y'),
                'status'          => $member->status,
                'has_pin'         => (bool) $member->transaction_pin,
                'balance'         => $account->balance ?? 0,
                'savings'         => $account->savings_balance ?? 0,
                'shares'          => $account->shares_balance ?? 0,
                'loan_balance'    => $account->loan_balance ?? 0,
                'monthly_deduction' => \App\Models\Setting::getFloat('monthly_deduction', 25000),
                'avatar'          => strtoupper(substr($member->full_name, 0, 1) .
                                     substr(strrchr($member->full_name, ' ') ?? ' ', 1, 1)),
            ],
        ]);
    }

    public function dashboard(Request $request)
    {
        $member       = $request->user();
        $account      = Account::where('member_id', $member->id)->first();
        $recentTxns   = Transaction::where('member_id', $member->id)->latest()->take(5)->get();
        $activeLoan   = Loan::where('member_id', $member->id)->where('status', 'active')->with('guarantors.member')->first();
        $fraudAlerts  = FraudAlert::where('member_id', $member->id)->where('status', '!=', 'resolved')->count();
        $householdBalance = \App\Models\HouseholdRequest::where('member_id', $member->id)->where('status', 'approved')->sum('amount_requested');

        return response()->json([
            'success'      => true,
            'balance'      => $account->balance ?? 0,
            'savings'      => $account->savings_balance ?? 0,
            'shares'       => $account->shares_balance ?? 0,
            'household_balance' => $householdBalance,
            'loan_balance' => $account->loan_balance ?? 0,
            'monthly_deduction' => \App\Models\Setting::getFloat('monthly_deduction', 25000),
            'recent_transactions' => $recentTxns->map(fn($t) => [
                'id'     => 'TXN-' . $t->id,
                'type'   => $t->transaction_type,
                'amount' => $t->amount,
                'desc'   => $t->description,
                'date'   => $t->created_at->format('Y-m-d'),
                'risk'   => $t->risk_score,
                'status' => $t->fraud_flag ? 'flagged' : $t->status,
            ]),
            'active_loan'   => $activeLoan ? [
                'id'         => $activeLoan->loan_id,
                'purpose'    => $activeLoan->purpose,
                'approved'   => $activeLoan->amount_approved,
                'repaid'     => $activeLoan->repayments()->sum('amount_paid'),
                'next'       => $activeLoan->next_payment_date,
                'guarantors' => $activeLoan->guarantors->map(fn($g) => [
                    'name'   => $g->member->full_name,
                    'status' => $g->status,
                ]),
            ] : null,
            'fraud_alert_count' => $fraudAlerts,
        ]);
    }

    public function updateProfile(Request $request)
    {
        $request->validate([
            'address' => 'nullable|string',
            'phone_number' => 'nullable|string|max:15',
            'department' => 'nullable|string|in:' . implode(',', AuthController::DEPARTMENTS),
            'account_number' => 'nullable|digits:10|unique:members,account_number,' . $request->user()->id,
            'bank_code' => 'required_with:account_number|string',
            'fcm_token' => 'nullable|string',
        ]);

        $updates = $request->only(['address', 'phone_number', 'department', 'fcm_token']);

        // Only re-verify with Paystack (and touch the bank columns at all)
        // when the member is actually changing their account number —
        // updateProfile is also used to save just a phone/address/department
        // edit, which shouldn't require a fresh bank lookup every time.
        if ($request->filled('account_number')) {
            $paystackKey = config('services.paystack.secret_key', env('PAYSTACK_SECRET_KEY'));
            if (!$paystackKey) {
                return response()->json(['success' => false, 'message' => 'Bank verification is not configured yet. Contact an admin.'], 503);
            }
            $resolve = \Illuminate\Support\Facades\Http::withToken($paystackKey)->get('https://api.paystack.co/bank/resolve', [
                'account_number' => $request->account_number,
                'bank_code'      => $request->bank_code,
            ]);
            if (!$resolve->successful()) {
                return response()->json(['success' => false, 'message' => 'That account number could not be verified against the selected bank.'], 422);
            }
            $bankName = collect(\Illuminate\Support\Facades\Cache::get('paystack_banks_ng', []))
                ->firstWhere('code', $request->bank_code)['name'] ?? null;

            $updates['account_number']         = $request->account_number;
            $updates['bank_code']              = $request->bank_code;
            $updates['bank_name']              = $bankName;
            $updates['verified_account_name']  = $resolve->json('data.account_name');
        }

        $request->user()->update($updates);
        return response()->json(['success' => true, 'message' => 'Profile updated']);
    }

    public function search(Request $request)
    {
        $request->validate(['q' => 'required|string|min:2']);
        $members = \App\Models\Member::where('full_name', 'like', "%{$request->q}%")
            ->orWhere('account_number', 'like', "%{$request->q}%")
            ->where('status', 'active')
            ->take(10)
            ->get(['member_id', 'full_name', 'account_number', 'staff_id']);
        return response()->json(['success' => true, 'members' => $members]);
    }
}
