<?php
// app/Http/Controllers/Api/GuarantorController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoanGuarantor;
use App\Models\Member;
use App\Models\Loan;
use Illuminate\Http\Request;

class GuarantorController extends Controller
{
    public function eligibleMembers(Request $request)
    {
        $currentMember = $request->user();
        $members = Member::where('id', '!=', $currentMember->id)
            ->where('status', 'active')
            ->whereDoesntHave('loans', fn($q) => $q->where('status', 'active'))
            ->with('account')
            ->get()
            ->map(fn($m) => [
                'id'           => $m->member_id,
                'name'         => $m->full_name,
                'staffId'      => $m->staff_id,
                'account'      => $m->account_number,
                'phone'        => $m->phone_number,
                'dept'         => $m->department,
                'savings'      => $m->account->savings_balance ?? 0,
                'hasActiveLoan'=> false,
            ]);
        return response()->json(['success' => true, 'members' => $members]);
    }

    public function giveConsent(Request $request, string $token)
    {
        $guarantor = LoanGuarantor::where('consent_token', $token)
            ->where('member_id', $request->user()->id)
            ->where('status', 'pending')
            ->firstOrFail();

        $guarantor->update(['status' => 'accepted', 'consent_date' => now()]);

        // Check if all guarantors have accepted — if so, move loan to under_review
        $loan          = Loan::find($guarantor->loan_id);
        $pendingCount  = LoanGuarantor::where('loan_id', $loan->id)->where('status', 'pending')->count();
        if ($pendingCount === 0) {
            $loan->update(['status' => 'under_review']);
        }

        return response()->json(['success' => true, 'message' => 'Consent given. Loan forwarded to Credit Committee.']);
    }

    public function declineConsent(Request $request, string $token)
    {
        $guarantor = LoanGuarantor::where('consent_token', $token)
            ->where('member_id', $request->user()->id)
            ->firstOrFail();

        $guarantor->update(['status' => 'declined']);
        Loan::find($guarantor->loan_id)->update(['status' => 'denied', 'denial_reason' => 'Guarantor declined consent.']);

        return response()->json(['success' => true, 'message' => 'Consent declined.']);
    }

    public function pendingRequests(Request $request)
    {
        $requests = LoanGuarantor::where('member_id', $request->user()->id)
            ->where('status', 'pending')
            ->with('loan.member')
            ->get()
            ->map(fn($g) => [
                'token'         => $g->consent_token,
                'loan_id'       => $g->loan->loan_id,
                'borrower'      => $g->loan->member->full_name,
                'amount'        => $g->loan->amount_requested,
                'purpose'       => $g->loan->purpose,
                'requested_at'  => $g->created_at,
            ]);
        return response()->json(['success' => true, 'pending_requests' => $requests]);
    }

    // ── ACTIVE / HISTORY SURETIES ────────────────────────────────────────────
    // Backs the "Active" and "History" tabs on the member Surety screen.
    // Active  = accepted guarantorships on loans still being repaid.
    // History = accepted guarantorships on loans that are completed, plus
    //           any request this member declined.
    public function mySureties(Request $request)
    {
        $memberId = $request->user()->id;

        $active = LoanGuarantor::where('member_id', $memberId)
            ->where('status', 'accepted')
            ->whereHas('loan', fn($q) => $q->where('status', 'active'))
            ->with('loan.member')
            ->get()
            ->map(fn($g) => $this->formatSurety($g));

        $history = LoanGuarantor::where('member_id', $memberId)
            ->where(function ($q) {
                $q->where('status', 'declined')
                  ->orWhereHas('loan', fn($lq) => $lq->whereIn('status', ['completed', 'denied']));
            })
            ->with('loan.member')
            ->latest()
            ->get()
            ->map(fn($g) => $this->formatSurety($g));

        return response()->json(['success' => true, 'active' => $active, 'history' => $history]);
    }

    private function formatSurety(LoanGuarantor $g): array
    {
        return [
            'loan_id'      => $g->loan->loan_id,
            'borrower'     => $g->loan->member->full_name,
            'amount'       => $g->loan->amount_approved ?? $g->loan->amount_requested,
            'purpose'      => $g->loan->purpose,
            'loan_status'  => $g->loan->status,
            'my_status'    => $g->status,
            'signed_date'  => $g->consent_date,
        ];
    }
}
