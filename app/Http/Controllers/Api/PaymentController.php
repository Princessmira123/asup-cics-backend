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
use App\Services\FraudDetectionService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    protected $fraudService;
    protected $notifService;

    public function __construct(FraudDetectionService $fraudService, NotificationService $notifService)
    {
        $this->fraudService = $fraudService;
        $this->notifService = $notifService;
    }

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
            'pin'              => 'required|digits:4',
        ]);

        $member  = $request->user();

        if (!$member->transaction_pin) {
            return response()->json(['success' => false, 'message' => 'Please set a transaction PIN first, in Settings.'], 400);
        }
        if (!Hash::check($request->pin, $member->transaction_pin)) {
            return response()->json(['success' => false, 'message' => 'Incorrect transaction PIN'], 401);
        }

        $account = Account::where('member_id', $member->id)->first();

        if (!$account) {
            return response()->json(['success' => false, 'message' => 'No account found for this member. Please contact support.'], 404);
        }

        if (($account->balance ?? 0) < $request->amount) {
            return response()->json(['success' => false, 'message' => 'Insufficient balance'], 422);
        }

        $riskScore = $this->fraudService->assessTransaction([
            'member_id'  => $member->id,
            'amount'     => $request->amount,
            'type'       => 'other_payment',
            'account_id' => $account->id,
        ]);

        if ($riskScore >= 90) {
            $this->fraudService->createAlert($member->id, null, 'High Risk Payment', $riskScore, $request->amount);
            $this->notifService->sendFraudAlert($member, $request->amount, $riskScore);
            $this->notifService->sendFraudAlertToAdmins($member->full_name, $member->member_id, $request->amount, $riskScore, 'High Risk Payment');
            return response()->json(['success' => false, 'message' => 'Payment blocked due to high fraud risk. Admin has been notified.', 'risk_score' => $riskScore], 403);
        }

        $type  = $request->payment_type_id ? PaymentType::find($request->payment_type_id) : null;
        $label = $type->name ?? $request->label;
        $isFlagged = $riskScore >= 70; // <90 already blocked and returned above

        DB::beginTransaction();
        try {
            $reference = 'PAY-' . strtoupper(Str::random(8));

            // Flagged payments are held — balance isn't touched and no
            // Payment record is created yet (payments.status only allows
            // completed/failed, no pending, so the Payment row itself gets
            // created later by resolve() once approved — the Transaction
            // row below is the only record of a held payment in the
            // meantime).
            if (!$isFlagged) {
                $account->decrement('balance', $request->amount);
            }

            $transaction = Transaction::create([
                'account_id'       => $account->id,
                'member_id'        => $member->id,
                'transaction_type' => 'debit',
                'amount'           => $request->amount,
                'reference_number' => $reference,
                'description'      => $label,
                'risk_score'       => $riskScore,
                'fraud_flag'       => $isFlagged,
                'status'           => $isFlagged ? 'pending' : 'completed',
            ]);

            $payment = null;
            if (!$isFlagged) {
                $payment = Payment::create([
                    'member_id'          => $member->id,
                    'payment_type_id'    => $type->id ?? null,
                    'payment_type_label' => $label,
                    'amount'             => $request->amount,
                    'reference_number'   => $reference,
                    'status'             => 'completed',
                ]);
            }

            DB::commit();

            if ($isFlagged) {
                $this->fraudService->createAlert($member->id, $transaction->id, 'Flagged Payment', $riskScore, $request->amount);
                $this->notifService->sendFraudAlertToAdmins($member->full_name, $member->member_id, $request->amount, $riskScore, 'Flagged Payment');
            }

            return response()->json([
                'success'     => true,
                'message'     => $isFlagged
                    ? 'Your payment was flagged for review and has not been deducted yet. You\'ll be notified once an admin resolves it.'
                    : 'Payment completed',
                'flagged'     => $isFlagged,
                'payment'     => $payment,
                'new_balance' => $account->fresh()->balance,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Payment failed', ['member_id' => $member->id ?? null, 'error' => $e->getMessage(), 'at' => basename($e->getFile()).':'.$e->getLine()]);
            return response()->json(['success' => false, 'message' => 'Payment failed: ' . $e->getMessage(), 'exception' => get_class($e)], 500);
        }
    }
}
