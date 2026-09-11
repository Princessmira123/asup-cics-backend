<?php
// app/Http/Controllers/Api/AccountController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;

class AccountController extends Controller
{
    public function index(Request $request)
    {
        $account = Account::where('member_id', $request->user()->id)->firstOrFail();
        return response()->json(['success' => true, 'account' => $account]);
    }

    public function balance(Request $request)
    {
        $account = Account::where('member_id', $request->user()->id)->first();
        return response()->json([
            'success'      => true,
            'balance'      => $account->balance ?? 0,
            'savings'      => $account->savings_balance ?? 0,
            'loan_balance' => $account->loan_balance ?? 0,
            'shares'       => $account->shares_balance ?? 0,
        ]);
    }

    public function statement(Request $request)
    {
        $request->validate(['from' => 'required|date', 'to' => 'required|date']);
        $account = Account::where('member_id', $request->user()->id)->first();
        $txns    = Transaction::where('account_id', $account->id)
            ->whereBetween('created_at', [$request->from, $request->to . ' 23:59:59'])
            ->orderBy('created_at')
            ->get();

        $credits = $txns->where('transaction_type', 'credit')->sum('amount');
        $debits  = $txns->where('transaction_type', 'debit')->sum('amount');

        return response()->json([
            'success'      => true,
            'from'         => $request->from,
            'to'           => $request->to,
            'member'       => $request->user()->full_name,
            'account_no'   => $request->user()->account_number,
            'credits'      => $credits,
            'debits'       => $debits,
            'net'          => $credits - $debits,
            'transactions' => $txns->map(fn($t) => [
                'date'   => $t->created_at->format('Y-m-d'),
                'id'     => 'TXN-' . $t->id,
                'desc'   => $t->description,
                'type'   => $t->transaction_type,
                'amount' => $t->amount,
            ]),
        ]);
    }

    public function statementPdf(Request $request)
    {
        // Generate PDF — requires barryvdh/laravel-dompdf package
        // $pdf = Pdf::loadView('statements.pdf', $data);
        // return $pdf->download('statement.pdf');
        return response()->json(['success' => false, 'message' => 'PDF generation requires dompdf package. Install with: composer require barryvdh/laravel-dompdf'], 501);
    }
}
