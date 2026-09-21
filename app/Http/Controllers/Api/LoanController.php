<?php
// app/Http/Controllers/Api/LoanController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\LoanGuarantor;
use App\Models\Account;
use App\Models\Member;
use App\Models\Transaction;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LoanController extends Controller
{
    protected $notifService;

    public function __construct(NotificationService $notifService)
    {
        $this->notifService = $notifService;
    }

    // Check eligibility
    public function eligibility(Request $request)
    {
        $member  = $request->user();
        $account = Account::where('member_id', $member->id)->first();

        $activeLoan     = Loan::where('member_id', $member->id)->where('status', 'active')->exists();
        $multiplier     = \App\Models\Setting::getFloat('max_loan_multiplier', 2);
        $maxLoanAmount  = $account->savings_balance * $multiplier;
        $requiredGuarantors = \App\Models\Setting::getInt('min_guarantors', 1);

        return response()->json([
            'success'              => true,
            'is_eligible'          => !$activeLoan && $account->savings_balance > 0,
            'max_loan_amount'      => $maxLoanAmount,
            'savings_balance'      => $account->savings_balance,
            'has_active_loan'      => $activeLoan,
            'required_guarantors'  => $requiredGuarantors,
            'interest_rate'        => \App\Models\Setting::getFloat('interest_rate', 10),
        ]);
    }

    // Get all loans for member
    public function index(Request $request)
    {
        $member = $request->user();
        $loans  = Loan::where('member_id', $member->id)
                      ->with('guarantors.member')
                      ->latest()
                      ->get();

        return response()->json([
            'success' => true,
            'loans'   => $loans->map(fn($l) => $this->formatLoan($l)),
        ]);
    }

    // Get single loan
    public function show(Request $request, $id)
    {
        $member = $request->user();
        $loan   = Loan::where('id', $id)
                      ->where('member_id', $member->id)
                      ->with(['guarantors.member', 'repayments'])
                      ->firstOrFail();

        return response()->json(['success' => true, 'loan' => $this->formatLoan($loan, true)]);
    }

    // Apply for loan
    public function apply(Request $request)
    {
        $request->validate([
            'amount'           => 'required|numeric|min:1000',
            'purpose'          => 'required|string',
            'tenure_months'    => 'required|integer|in:3,6,12,18,24,36',
            'commence_month'   => 'required|string',
            'commence_year'    => 'required|integer',
            'description'      => 'nullable|string',
            'guarantors'       => 'required|array|min:1',
            'guarantors.*.id'  => 'required|string|exists:members,member_id',
        ]);

        $member  = $request->user();
        $account = Account::where('member_id', $member->id)->first();

        // Eligibility checks
        if (Loan::where('member_id', $member->id)->where('status', 'active')->exists()) {
            return response()->json(['success' => false, 'message' => 'You already have an active loan'], 400);
        }

        $maxAmount = $account->savings_balance * \App\Models\Setting::getFloat('max_loan_multiplier', 2);
        if ($request->amount > $maxAmount) {
            return response()->json(['success' => false, 'message' => "Amount exceeds maximum eligible amount of ₦" . number_format($maxAmount, 2)], 400);
        }

        $highValueThreshold = \App\Models\Setting::getFloat('high_value_threshold', 300000);
        $requiredGuarantors = $request->amount > $highValueThreshold ? 2 : \App\Models\Setting::getInt('min_guarantors', 1);
        if (count($request->guarantors) < $requiredGuarantors) {
            return response()->json(['success' => false, 'message' => "This loan amount requires {$requiredGuarantors} guarantor(s)"], 400);
        }

        DB::beginTransaction();
        try {
            $loan = Loan::create([
                'loan_id'           => 'LN-' . date('Y') . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT),
                'member_id'         => $member->id,
                'amount_requested'  => $request->amount,
                'interest_rate'     => \App\Models\Setting::getFloat('interest_rate', 10),
                'tenure_months'     => $request->tenure_months,
                'purpose'           => $request->purpose,
                'description'       => $request->description,
                'commence_month'    => $request->commence_month,
                'commence_year'     => $request->commence_year,
                'status'            => 'pending',
                'application_date'  => now(),
            ]);

            // Add guarantors
            foreach ($request->guarantors as $g) {
                $guarantorMember = Member::where('member_id', $g['id'])->first();
                $consentToken    = Str::random(40);

                LoanGuarantor::create([
                    'loan_id'       => $loan->id,
                    'member_id'     => $guarantorMember->id,
                    'consent_token' => $consentToken,
                    'status'        => 'pending',
                ]);

                // Notify guarantor
                $this->notifService->sendSms(
                    $guarantorMember->phone_number,
                    "{$member->full_name} has listed you as a guarantor for a loan of ₦" . number_format($request->amount, 2) . ". Use token {$consentToken} to accept or decline via the ASUP CICS app."
                );
                $this->notifService->sendEmail(
                    $guarantorMember->email,
                    'Guarantor / Surety Request',
                    "You have been listed as a guarantor. Consent token: {$consentToken}"
                );
            }

            DB::commit();

            // Notify member
            $this->notifService->sendPush($member->fcm_token, 'Loan Application Submitted', "Your loan application of ₦" . number_format($request->amount, 2) . " has been submitted. Reference: {$loan->loan_id}");

            return response()->json([
                'success'    => true,
                'message'    => 'Loan application submitted successfully.',
                'loan_id'    => $loan->loan_id,
                'loan_status'=> 'pending',
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Application failed. Please try again.'], 500);
        }
    }

    // Loan status
    public function status(Request $request, $id)
    {
        $member = $request->user();
        $loan   = Loan::where('loan_id', $id)->where('member_id', $member->id)->firstOrFail();

        return response()->json([
            'success'    => true,
            'loan_id'    => $loan->loan_id,
            'status'     => $loan->status,
            'amount'     => $loan->amount_requested,
            'approved'   => $loan->amount_approved,
            'message'    => $this->getStatusMessage($loan->status),
        ]);
    }

    // Repayments
    public function repayments(Request $request, $id)
    {
        $member     = $request->user();
        $loan       = Loan::where('loan_id', $id)->where('member_id', $member->id)->firstOrFail();
        $repayments = LoanRepayment::where('loan_id', $loan->id)->latest()->get();

        return response()->json([
            'success'    => true,
            'repayments' => $repayments,
            'total_paid' => $repayments->sum('amount_paid'),
            'balance'    => $loan->amount_approved - $repayments->sum('amount_paid'),
        ]);
    }

    // Make repayment
    public function repay(Request $request, $id)
    {
        $request->validate(['amount' => 'required|numeric|min:1']);
        $member  = $request->user();
        $loan    = Loan::where('loan_id', $id)->where('member_id', $member->id)->where('status', 'active')->firstOrFail();
        $account = Account::where('member_id', $member->id)->first();

        if ($account->balance < $request->amount) {
            return response()->json(['success' => false, 'message' => 'Insufficient balance'], 400);
        }

        DB::beginTransaction();
        try {
            $totalPaid  = LoanRepayment::where('loan_id', $loan->id)->sum('amount_paid');
            $newPaid    = $totalPaid + $request->amount;
            $balance    = $loan->amount_approved - $newPaid;

            LoanRepayment::create([
                'loan_id'          => $loan->id,
                'amount_paid'      => $request->amount,
                'balance_remaining'=> max(0, $balance),
                'payment_date'     => now(),
                'status'           => 'completed',
            ]);

            $account->decrement('balance', $request->amount);

            Transaction::create([
                'account_id'       => $account->id,
                'member_id'        => $member->id,
                'transaction_type' => 'debit',
                'amount'           => $request->amount,
                'reference_number' => 'REP-' . strtoupper(Str::random(8)),
                'description'      => 'Loan Repayment - ' . $loan->loan_id,
                'risk_score'       => 0,
                'fraud_flag'       => false,
                'status'           => 'completed',
            ]);

            if ($balance <= 0) {
                $loan->update(['status' => 'completed']);
                $this->notifService->sendPush($member->fcm_token, 'Loan Fully Repaid 🎉', "Congratulations! Your loan {$loan->loan_id} has been fully repaid.");
            }

            DB::commit();

            return response()->json([
                'success'     => true,
                'message'     => 'Repayment successful',
                'amount_paid' => $request->amount,
                'balance'     => max(0, $balance),
                'loan_status' => $balance <= 0 ? 'completed' : 'active',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Repayment failed'], 500);
        }
    }

    private function formatLoan(Loan $loan, $detailed = false)
    {
        $totalPaid = $loan->repayments ? $loan->repayments->sum('amount_paid') : 0;
        $data = [
            'id'          => $loan->loan_id,
            'amount'      => $loan->amount_requested,
            'approved'    => $loan->amount_approved,
            'purpose'     => $loan->purpose,
            'tenure'      => $loan->tenure_months,
            'rate'        => $loan->interest_rate,
            'status'      => $loan->status,
            'disbursed'   => $loan->disbursement_date,
            'repaid'      => $totalPaid,
            'next'        => $loan->next_payment_date,
            'deductionStart' => $loan->commence_month . '-' . $loan->commence_year,
            'guarantors'  => $loan->guarantors ? $loan->guarantors->map(fn($g) => [
                'name'        => $g->member->full_name ?? '',
                'staffId'     => $g->member->staff_id ?? '',
                'phone'       => $g->member->phone_number ?? '',
                'dept'        => $g->member->department ?? '',
                'status'      => $g->status,
                'signedDate'  => $g->consent_date,
            ]) : [],
        ];
        return $data;
    }

    private function getStatusMessage($status)
    {
        return match($status) {
            'pending'    => 'Your application is awaiting guarantor consent.',
            'under_review' => 'Your application is under review by the Credit Committee.',
            'approved'   => 'Your loan has been approved. Disbursement in progress.',
            'active'     => 'Your loan is active and repayment is ongoing.',
            'completed'  => 'This loan has been fully repaid.',
            'denied'     => 'Your loan application was not approved.',
            default      => 'Status unknown.',
        };
    }
}
