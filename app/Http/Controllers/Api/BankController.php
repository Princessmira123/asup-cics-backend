<?php
// app/Http/Controllers/Api/BankController.php
//
// Real bank-account verification for the registration form and the
// Personal Data "Edit" sheet, using Paystack's free bank-resolution API.
// Paystack's TEST secret key is enough for this — account resolution works
// against real bank records even in test mode, at no cost. No payments are
// ever processed through this key; it's used purely to confirm an account
// number is real and to fetch the account holder's name for confirmation.
//
// Both endpoints are intentionally public (no auth:sanctum) since they're
// used during registration, before a member has a token yet.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class BankController extends Controller
{
    private function secretKey(): ?string
    {
        return config('services.paystack.secret_key', env('PAYSTACK_SECRET_KEY'));
    }

    // ── LIST BANKS ────────────────────────────────────────────────────────────
    // Powers the "Select your bank" dropdown. Cached for a day since
    // Paystack's bank list barely changes and this avoids hitting their API
    // on every single registration screen load.
    public function banks()
    {
        $key = $this->secretKey();
        if (!$key) {
            return response()->json(['success' => false, 'message' => 'Bank verification is not configured yet. Contact an admin.'], 503);
        }

        $banks = Cache::remember('paystack_banks_ng', now()->addDay(), function () use ($key) {
            $response = Http::withToken($key)->get('https://api.paystack.co/bank', ['country' => 'nigeria']);
            if (!$response->successful()) return null;
            return collect($response->json('data', []))
                ->map(fn($b) => ['name' => $b['name'], 'code' => $b['code']])
                ->values();
        });

        if ($banks === null) {
            return response()->json(['success' => false, 'message' => 'Could not load bank list. Try again shortly.'], 502);
        }

        return response()->json(['success' => true, 'banks' => $banks]);
    }

    // ── RESOLVE ACCOUNT ──────────────────────────────────────────────────────
    // Checks a specific account_number + bank_code pair against real bank
    // records and returns the actual account holder's name, so the app can
    // show "Confirm: this is <Name>'s account" before letting someone
    // register or save it — this is what makes account_number "verified"
    // rather than just "10 digits someone typed."
    public function resolveAccount(Request $request)
    {
        $request->validate([
            'account_number' => 'required|digits:10',
            'bank_code'      => 'required|string',
        ]);

        $key = $this->secretKey();
        if (!$key) {
            return response()->json(['success' => false, 'message' => 'Bank verification is not configured yet. Contact an admin.'], 503);
        }

        $response = Http::withToken($key)->get('https://api.paystack.co/bank/resolve', [
            'account_number' => $request->account_number,
            'bank_code'      => $request->bank_code,
        ]);

        if (!$response->successful()) {
            // Paystack returns a 422 with a clear message for a genuinely
            // invalid account number/bank combination — surface that
            // directly rather than a generic error where possible.
            $msg = $response->json('message') ?: 'Could not verify that account number. Double check the number and bank.';
            return response()->json(['success' => false, 'message' => $msg], 422);
        }

        return response()->json([
            'success' => true,
            'account_name'   => $response->json('data.account_name'),
            'account_number' => $response->json('data.account_number'),
        ]);
    }
}
