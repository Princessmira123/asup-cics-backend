<?php
// app/Http/Controllers/Api/PaymentController.php
//
// Backs the "Update Other Payments" screen. Payment types are configured
// by admin (empty until admin adds some via /admin/payment-types); payment
// history is empty until a member actually pays something. Making a
// payment really debits the member's account balance and records a
// matching Transaction, exactly like a transfer or savings contribution.
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Payment;
use App\Models\PaymentType;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    public function types()
    {
        $types = PaymentType::where('active', true)->get();
        return response()->json(['success' => true, 'types' => $types]);
    }

    public function history(Request $request)
    {
        $payments = Payment::where('member_id', $request->user()->id)->latest()->get();
        return response()->json(['success' => true, 'payments' => $payments]);
    }

    public function pay(Request $request)
    {
        $request->validate([
            'payment_type_id' => 'nullable|exists:payment_types,id',
            'label'            => 'required_without:payment_type_id|string|max:100',
            'amount'           => 'required|numeric|min:100',
        ]);

        $member  = $request->user();
        $account = Account::where('member_id', $member->id)->first();

        if (($account->balance ?? 0) < $request->amount) {
            return response()->json(['success' => false, 'message' => 'Insufficient balance'], 422);
        }

        $type  = $request->payment_type_id ? PaymentType::find($request->payment_type_id) : null;
        $label = $type->name ?? $request->label;

        DB::beginTransaction();
        try {
            $account->decrement('balance', $request->amount);

            $reference = 'PAY-' . strtoupper(Str::random(8));

            Transaction::create([
                'account_id'       => $account->id,
                'member_id'        => $member->id,
                'transaction_type' => 'debit',
                'amount'           => $request->amount,
                'reference_number' => $reference,
                'description'      => $label,
                'risk_score'       => 0,
                'fraud_flag'       => false,
                'status'           => 'completed',
            ]);

            $payment = Payment::create([
                'member_id'          => $member->id,
                'payment_type_id'    => $type->id ?? null,
                'payment_type_label' => $label,
                'amount'             => $request->amount,
                'reference_number'   => $reference,
                'status'             => 'completed',
            ]);

            DB::commit();
            return response()->json([
                'success'     => true,
                'message'     => 'Payment completed',
                'payment'     => $payment,
                'new_balance' => $account->fresh()->balance,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Payment failed'], 500);
        }
    }
}
