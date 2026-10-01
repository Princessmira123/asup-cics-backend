<?php
// app/Console/Commands/ProcessLoanDeductions.php
//
// Runs on a schedule (see routes/console.php) and deducts that cycle's
// installment from SAVINGS (specifically, per request — not general
// balance, which is what the manual repay() endpoint uses) for every active
// loan whose next_payment_date has arrived. next_payment_date is first set
// to one month after disbursement in AdminController::approveLoan(), and
// this command pushes it forward another month each time it successfully
// collects a payment — so deductions land roughly monthly, starting a
// month after the loan was granted, exactly as requested.
//
// Kept consistent with the existing manual repay() / repayments() logic:
// outstanding balance is amount_approved minus total repaid, with no
// interest applied to the running balance — interest_rate exists on the
// loan record but isn't factored into repayment math anywhere else in the
// app either, so this doesn't introduce a second, conflicting calculation.

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProcessLoanDeductions extends Command
{
    protected $signature = 'loans:process-deductions';
    protected $description = "Deduct this cycle's installment from savings for every active loan whose next payment date has arrived";

    public function handle(): int
    {
        $loans = Loan::where('status', 'active')
            ->whereNotNull('next_payment_date')
            ->where('next_payment_date', '<=', now())
            ->get();

        $processed = 0;
        $skipped = 0;

        foreach ($loans as $loan) {
            DB::transaction(function () use ($loan, &$processed, &$skipped) {
                $totalRepaid = LoanRepayment::where('loan_id', $loan->id)->sum('amount_paid');
                $outstanding = max(0, $loan->amount_approved - $totalRepaid);

                if ($outstanding <= 0) {
                    $loan->update(['status' => 'completed', 'next_payment_date' => null]);
                    return;
                }

                $installment = round($loan->amount_approved / max(1, $loan->tenure_months), 2);
                $due = min($installment, $outstanding);

                $account = Account::where('member_id', $loan->member_id)->first();
                if (!$account) { $skipped++; return; }

                // Deduct only what savings can actually cover — never pushes
                // savings negative. If savings can't cover any of it this
                // cycle, retry in a week rather than silently skipping the
                // member until next month.
                $available = min($due, max(0, $account->savings_balance));
                if ($available <= 0) {
                    $loan->update(['next_payment_date' => now()->addDays(7)]);
                    $skipped++;
                    return;
                }

                $account->decrement('savings_balance', $available);
                $account->decrement('loan_balance', $available);

                $newTotalRepaid = $totalRepaid + $available;

                LoanRepayment::create([
                    'loan_id'           => $loan->id,
                    'amount_paid'       => $available,
                    'balance_remaining' => max(0, $loan->amount_approved - $newTotalRepaid),
                    'payment_date'      => now(),
                    'status'            => 'completed',
                ]);

                Transaction::create([
                    'account_id'       => $account->id,
                    'member_id'        => $loan->member_id,
                    'transaction_type' => 'debit',
                    'amount'           => $available,
                    'reference_number' => 'AUTOPAY-' . $loan->loan_id . '-' . now()->format('Ymd'),
                    'description'      => 'Monthly Loan Deduction - ' . $loan->loan_id,
                    'risk_score'       => 0,
                    'fraud_flag'       => false,
                    'status'           => 'completed',
                ]);

                if ($newTotalRepaid >= $loan->amount_approved - 0.01) {
                    $loan->update(['status' => 'completed', 'next_payment_date' => null]);
                } else {
                    $loan->update(['next_payment_date' => now()->addMonth()]);
                }

                $processed++;
            });
        }

        $this->info("Processed {$processed} loan deduction(s), skipped {$skipped} (insufficient savings).");
        return self::SUCCESS;
    }
}
